<?php
/**
 * Plugin Name: IT News Fetcher
 * Plugin URI:  https://www.hashe.com/
 * Description: Fetch and republish tech news into a "Tech News" CPT with per-feed checklist (check/fetch), batch fetching, live logs with Stop, OpenAI expansion (1200–1500 words), Bulk Rewrite, and Rank Math SEO generation/apply. Modular, secure, and WordPress.org-ready.
 * Version:     4.1.2
 * Author:      Mamoon Rashid
 * Author URI:  https://www.hashe.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: it-news-fetcher
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

define('ITNF_VERSION', '4.0.0');
define('ITNF_PLUGIN_FILE', __FILE__);
define('ITNF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ITNF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Internationalization
add_action('plugins_loaded', function(){
    load_plugin_textdomain('it-news-fetcher', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Includes (order matters for dependencies)
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-logger.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-helpers.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-openai.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-fetcher.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-settings.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-cron.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-ajax-fetch.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-ajax-seo.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-wrappers.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-archives.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-ajax-bulk.php';
require_once ITNF_PLUGIN_DIR.'includes/class-itnf-backfill-images.php';
require_once ITNF_PLUGIN_DIR.'admin/class-itnf-admin.php';

if ( is_admin() && class_exists('ITNF_Ajax_Bulk') ) {
    ITNF_Ajax_Bulk::register();
}

// Activation/Deactivation (schedule, rewrites)
register_activation_hook(__FILE__, ['ITNF_Cron', 'activate']);
register_deactivation_hook(__FILE__, ['ITNF_Cron', 'deactivate']);

// Register CPT
add_action('init', ['ITNF_Helpers', 'register_cpt'], 0);

// Admin UI
add_action('admin_menu', ['ITNF_Admin', 'register_menu']);
add_action('admin_enqueue_scripts', ['ITNF_Admin', 'enqueue_assets']);

// Settings
add_action('admin_init', ['ITNF_Settings', 'register']);

// Cron runner
add_action('itnf_cron_event', ['ITNF_Cron', 'run']);
add_action('itnf_worker_single', ['ITNF_Ajax_Fetch', 'worker_single'], 10, 6);
add_action('itnf_worker_multi', ['ITNF_Ajax_Fetch', 'worker_multi'], 10, 6);

// AJAX endpoints
ITNF_Ajax_Fetch::register();
ITNF_Ajax_SEO::register();

// Uninstall cleanup handled by uninstall.php


// === ITNF: wiring block (non-destructive) ===
if (!defined('ITNF_DIR')) define('ITNF_DIR', plugin_dir_path(__FILE__));
if (!defined('ITNF_URL')) define('ITNF_URL', plugin_dir_url(__FILE__));

// Load new modules if present
if (file_exists(ITNF_DIR.'includes/class-itnf-openai.php')) require_once ITNF_DIR.'includes/class-itnf-openai.php';
if (file_exists(ITNF_DIR.'includes/class-itnf-settings.php')) require_once ITNF_DIR.'includes/class-itnf-settings.php';
if (file_exists(ITNF_DIR.'includes/class-itnf-ajax-seo.php')) require_once ITNF_DIR.'includes/class-itnf-ajax-seo.php';
if (file_exists(ITNF_DIR.'includes/class-itnf-rankmath-optimizer.php')) require_once ITNF_DIR.'includes/class-itnf-rankmath-optimizer.php';

// Register settings & AJAX endpoints (keeps your existing admin UI/CPT)
add_action('admin_init', function(){
    if (class_exists('ITNF_Settings')) ITNF_Settings::register();
    if (class_exists('ITNF_Ajax_SEO')) ITNF_Ajax_SEO::register();
});

// Run optimizer on Tech News save/create (safe & non-recursive)
add_action('save_post_tech_news', function($post_id, $post, $update){
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (get_post_meta($post_id, '_itnf_optimizing', true)) return;
    update_post_meta($post_id, '_itnf_optimizing', 1);

    $opts   = get_option('it_news_fetcher_options');
    $source = get_post_meta($post_id, '_source_url', true);

    // Generate base Rank Math fields if enabled
    if (!empty($opts['auto_rankmath']) && class_exists('ITNF_OpenAI')){
        $seo = ITNF_OpenAI::generate_seo($post->post_title, $post->post_content, $source);
        if (!empty($seo['focus'])) update_post_meta($post_id, 'rank_math_focus_keyword', $seo['focus']);
        if (!empty($seo['title'])) update_post_meta($post_id, 'rank_math_title', $seo['title']);
        if (!empty($seo['desc']))  update_post_meta($post_id, 'rank_math_description', $seo['desc']);
    }

    // Optimizer pass (FK in title/desc/URL/intro/H2, internal/external links, image alt)
    if (class_exists('ITNF_RankMath_Optimizer')){
        ITNF_RankMath_Optimizer::optimize_post($post_id, $source);
    }

    delete_post_meta($post_id, '_itnf_optimizing');
}, 10, 3);

// Flag posts when Rank Math SEO is changed manually in the editor
add_action('post_updated', function($post_id, $post_after, $post_before){
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (get_post_type($post_id) !== 'post') return;

    // Compare Rank Math metas before vs after
    $keys = ['rank_math_focus_keyword','rank_math_title','rank_math_description'];
    $changed = false;
    foreach ($keys as $k) {
        $old = get_metadata_raw($post_id, $k, true); // raw value from DB
        $new = get_post_meta($post_id, $k, true);
        if ($old !== $new && $new !== '') { $changed = true; break; }
    }
    if ($changed) {
        update_post_meta($post_id, '_itnf_seo_manual', 1);
    }
}, 10, 3);
 
// Helper: raw meta without filters
if (!function_exists('get_metadata_raw')) {
    function get_metadata_raw($post_id, $key, $single = true){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",
            $post_id, $key
        ));
    }// Show a clear notice on IT News Fetcher screens if the OpenAI key is missing
add_action('admin_notices', function () {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (!function_exists('get_current_screen')) return;
    $screen = get_current_screen();
    if (!$screen) return;

    // Limit the notice to IT News Fetcher screens only
    $id = isset($screen->id) ? $screen->id : '';
    if ($id && stripos($id, 'it-news-fetcher') === false && stripos($id, 'itnf') === false) return;

    $key = trim((string) get_option('itnf_openai_key'));
    if ($key === '') {
        echo '<div class="notice notice-warning"><p><strong>IT News Fetcher:</strong> OpenAI API key is empty. AI expansion and SEO suggestions will be skipped until you set it in Settings.</p></div>';
    }
});

}

// === /ITNF wiring block ===

// Show a clear notice on IT News Fetcher screens if the OpenAI key is missing/disabled
add_action('admin_notices', function () {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (!function_exists('get_current_screen')) return;
    $screen = get_current_screen();
    if (!$screen) return;

    // Limit the notice to IT News Fetcher screens only
    $id = isset($screen->id) ? $screen->id : '';
    if ($id && stripos($id, 'it-news-fetcher') === false && stripos($id, 'itnf') === false) return;

    // READ THE CORRECT OPTION ARRAY
    $opts = get_option('it_news_fetcher_options');
    $key  = is_array($opts) ? trim((string) ($opts['openai_key'] ?? '')) : '';
    $ena  = is_array($opts) ? !empty($opts['openai_enable']) : false;

    if ($key === '') {
        echo '<div class="notice notice-warning"><p><strong>IT News Fetcher:</strong> OpenAI API key is empty. AI expansion and SEO suggestions will be skipped until you set it in <em>Settings → OpenAI API Key</em>.</p></div>';
        return;
    }

    if (!$ena) {
        echo '<div class="notice notice-info"><p><strong>IT News Fetcher:</strong> OpenAI is currently <em>disabled</em>. Turn on “Expand with OpenAI” in <em>Settings</em> if you want AI expansion/SEO suggestions.</p></div>';
        return;
    }
});

// Mark a post as "manual SEO" when Rank Math fields are changed in the editor
add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;

    // Limit to the plugin CPT, falling back to 'post'
    $cpt = apply_filters('itnf_cpt', 'tech_news');
    if (!post_type_exists($cpt)) { $cpt = 'post'; }
    if (get_post_type($post_id) !== $cpt) return;

    // Compare Rank Math metas before vs after
    $keys = array('rank_math_focus_keyword', 'rank_math_title', 'rank_math_description');
    foreach ($keys as $k) {
        $old = get_metadata_raw($post_id, $k, true); // raw DB value
        $new = get_post_meta($post_id, $k, true);
        if ($old !== $new && $new !== '') {
            update_post_meta($post_id, '_itnf_seo_manual', 1);
            break;
        }
    }
}, 10, 3);

// Helper to read raw meta without filters
if (!function_exists('get_metadata_raw')) {
    function get_metadata_raw($post_id, $key, $single = true) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",
            $post_id, $key
        ));
    }
}
