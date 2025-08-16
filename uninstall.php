<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

delete_option('it_news_fetcher_options');

// Best-effort cleanup of transients (pattern-based)
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_itnf_%' OR option_name LIKE '_transient_timeout_itnf_%'" );
