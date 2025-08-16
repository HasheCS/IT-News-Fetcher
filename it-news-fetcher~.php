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
