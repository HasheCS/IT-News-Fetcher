<?php
/**
 * Plugin Name: IT News Fetcher
 * Plugin URI:  https://www.hashe.com/
 * Description: Fetch and republish tech news into a "Tech News" CPT with per-feed checklist (check/fetch), batch fetching, live logs with Stop, OpenAI expansion (1200–1500 words), Bulk Rewrite, and Rank Math SEO generation/apply. Modular, secure, and WordPress.org-ready.
 * Version:     4.0.0
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


require_once ITNF_PLUGIN_DIR.'admin/class-itnf-admin.php';

// Activation/Deactivation (schedule, rewrites)
register_activation_hook(__FILE__, ['ITNF_Cron', 'activate']);
register_deactivation_hook(__FILE__, ['ITNF_Cron', 'deactivate']);

// Register CPT
add_action('init', ['ITNF_Helpers', 'register_cpt']);

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
// === /ITNF wiring block ===