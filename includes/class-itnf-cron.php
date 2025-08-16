<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_Cron {
    public static function activate(){
        self::schedule();
        flush_rewrite_rules();
    }
    public static function deactivate(){
        wp_clear_scheduled_hook('itnf_cron_event');
        flush_rewrite_rules();
    }
    public static function schedule(){
        $o = get_option('it_news_fetcher_options');
        $freq = $o['frequency'] ?? 'daily';
        if (!wp_next_scheduled('itnf_cron_event')) {
            wp_schedule_event(time(), $freq, 'itnf_cron_event');
        }
    }
    public static function run(){
        // Cron processes all feeds sequentially
        $feeds = ITNF_Helpers::parse_feeds_option();
        if (!$feeds) return;
        $o = get_option('it_news_fetcher_options');
        $per = $o['items_per_feed'] ?? 3;
        $fallback = $o['default_thumb'] ?? '';
        $thr = isset($o['openai_threshold']) ? max(200, (int)$o['openai_threshold']) : 800;
        $rm  = !empty($o['auto_rankmath']);

        $run = 'cron_'.time();
        ITNF_Logger::init($run);
        foreach ($feeds as $url){
            ITNF_Logger::append($run, '--- FEED (cron): '.$url.' ---');
            ITNF_Fetcher::process_single_feed($url, $per, $fallback, $thr, $rm, $run);
        }
        ITNF_Logger::append($run, 'Cron run complete');
        ITNF_Logger::finish($run);
    }
}
