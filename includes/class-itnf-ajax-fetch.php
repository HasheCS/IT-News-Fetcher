<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_Ajax_Fetch {
    public static function register(){
        add_action('wp_ajax_itnf_list_feeds',       [__CLASS__, 'list_feeds']);
        add_action('wp_ajax_itnf_check_feed',       [__CLASS__, 'check_feed']);
        add_action('wp_ajax_itnf_fetch_one',        [__CLASS__, 'fetch_one']);
        add_action('wp_ajax_itnf_fetch_selected',   [__CLASS__, 'fetch_selected']);
        add_action('wp_ajax_itnf_poll_log',         [__CLASS__, 'poll_log']);
        add_action('wp_ajax_itnf_stop_run',         [__CLASS__, 'stop_run']);
        add_action('wp_ajax_itnf_list_tech_news',   [__CLASS__, 'list_tech_news']);
        add_action('wp_ajax_itnf_bulk_rewrite',     [__CLASS__, 'bulk_rewrite']);
    }

    public static function list_feeds(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        wp_send_json_success(['feeds' => ITNF_Helpers::parse_feeds_option()]);
    }

    public static function check_feed(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $url = esc_url_raw($_POST['url'] ?? '');
        if (!$url) wp_send_json_error('no_url');

        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }
        $o   = get_option('it_news_fetcher_options');
        $per = isset($o['items_per_feed']) ? max(1, min(20, (int)$o['items_per_feed'])) : 3;

        // Try primary + known variants (helper decides)
        $variants = function_exists('it_news_fetcher_get_feed_variants')
            ? it_news_fetcher_get_feed_variants($url)
            : [$url];

        $new  = 0;
        $err  = '';

        foreach ($variants as $variant){
            $rss = fetch_feed($variant);
            if (is_wp_error($rss)) { $err = $rss->get_error_message(); continue; }

            $max   = (int) $rss->get_item_quantity($per);
            $items = $rss->get_items(0, $max);

            if (empty($items)) { $err = 'No items'; continue; }

            foreach ($items as $item){
                $hash = ITNF_Helpers::item_hash($item->get_id(), $item->get_permalink());
                $dupe = get_posts([
                    'post_type'      => 'tech_news',
                    'meta_key'       => '_itnf_hash',
                    'meta_value'     => $hash,
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                ]);
                if (!$dupe) $new++;
            }
            // success on first working variant
            $err = '';
            break;
        }

        $out = ['new_count' => (int)$new];
        if ($err) $out['error'] = $err;
        wp_send_json_success($out);
    }

    public static function fetch_one(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $url = esc_url_raw($_POST['url'] ?? '');
        if (!$url) wp_send_json_error('no_url');

        $run = uniqid('run_', true);
        ITNF_Logger::init($run);

        $o        = get_option('it_news_fetcher_options');
        $per      = $o['items_per_feed'] ?? 3;
        $fallback = $o['default_thumb'] ?? '';
        $thr      = isset($o['openai_threshold']) ? max(200, (int)$o['openai_threshold']) : 800;
        $rm       = !empty($o['auto_rankmath']);

        // schedule single worker via WP-Cron
        wp_schedule_single_event(time() + 1, 'itnf_worker_single', [$run, $url, $per, $fallback, $thr, $rm]);

        wp_send_json_success(['run_id' => $run]);
    }

    // Called by WP-Cron (hooked in your main file)
    public static function worker_single($run, $url, $per, $fallback, $thr, $rm){
        // Bridge the fetcher logs to the live log
        if (function_exists('itnf_set_logger')) {
            itnf_set_logger(function($msg) use ($run){ ITNF_Logger::append($run, $msg); });
        }
        ITNF_Logger::append($run, 'Starting single feed: '.$url);

        try {
            if (class_exists('ITNF_Fetcher')) {
                ITNF_Fetcher::process_single_feed($url, $per, $fallback, $thr, $rm, $run);
            } else {
                ITNF_Logger::append($run, 'Fatal error: ITNF_Fetcher class not found');
            }
        } catch (\Throwable $e){
            ITNF_Logger::append($run, 'Fatal error: '.$e->getMessage());
        }

        ITNF_Logger::finish($run);
    }

    public static function fetch_selected(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $urls_raw = $_POST['urls'] ?? '[]';
        $urls     = json_decode(wp_unslash($urls_raw), true);
        if (!is_array($urls) || !count($urls)) wp_send_json_error('no_urls');

        $run = uniqid('run_', true);
        ITNF_Logger::init($run);

        $o        = get_option('it_news_fetcher_options');
        $per      = $o['items_per_feed'] ?? 3;
        $fallback = $o['default_thumb'] ?? '';
        $thr      = isset($o['openai_threshold']) ? max(200, (int)$o['openai_threshold']) : 800;
        $rm       = !empty($o['auto_rankmath']);

        wp_schedule_single_event(time() + 1, 'itnf_worker_multi', [$run, $urls, $per, $fallback, $thr, $rm]);

        wp_send_json_success(['run_id' => $run]);
    }

    // Called by WP-Cron (hooked in your main file)
    public static function worker_multi($run, $urls, $per, $fallback, $thr, $rm){
        if (function_exists('itnf_set_logger')) {
            itnf_set_logger(function($msg) use ($run){ ITNF_Logger::append($run, $msg); });
        }

        foreach ($urls as $u){
            if (ITNF_Logger::should_stop($run)) {
                ITNF_Logger::append($run, 'Cancellation received. Stopping batch.');
                break;
            }
            ITNF_Logger::append($run, '--- FEED: '.$u.' ---');
            try {
                if (class_exists('ITNF_Fetcher')) {
                    ITNF_Fetcher::process_single_feed($u, $per, $fallback, $thr, $rm, $run);
                } else {
                    ITNF_Logger::append($run, 'Fatal error: ITNF_Fetcher class not found');
                    break;
                }
            } catch (\Throwable $e){
                ITNF_Logger::append($run, 'Fatal error: '.$e->getMessage());
            }
        }

        ITNF_Logger::append($run, 'Batch complete');
        ITNF_Logger::finish($run);
    }

    public static function poll_log(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $run_id = sanitize_text_field($_POST['run_id'] ?? '');
        $cursor = (int)($_POST['cursor'] ?? 0);

        // Convert logger's tuple into the structure your JS expects
        list($slice, $new_cursor, $done) = ITNF_Logger::read_slice($run_id, $cursor);
        wp_send_json_success(['lines' => $slice, 'cursor' => $new_cursor, 'done' => $done]);
    }

    public static function stop_run(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $run_id = sanitize_text_field($_POST['run_id'] ?? '');
        if ($run_id) ITNF_Logger::request_stop($run_id);
        wp_send_json_success(['stopping' => true]);
    }

    public static function list_tech_news() {
        check_ajax_referer('itnf_admin');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('forbidden');
        }
    
        $ids = get_posts([
            'post_type'      => 'tech_news',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
    
        $rows = [];
        foreach ($ids as $pid) {
            $title   = get_the_title($pid);
            $content = (string) get_post_field('post_content', $pid);
    
            // match Rank Math tab... plain text word count
            $plain = wp_strip_all_tags( strip_shortcodes( $content ) );
            $words = $plain !== '' ? str_word_count( $plain ) : 0;
    
            $rows[] = [
                'ID'        => (int) $pid,
                'title'     => $title, // escape in JS with _.escape(title)
                'date'      => get_the_date( get_option('date_format'), $pid ),
                'edit'      => get_edit_post_link($pid, ''),
                'permalink' => get_permalink($pid),
                'words'     => (int) $words,
            ];
        }
    
        wp_send_json_success(['rows' => $rows]);
    }


    public static function bulk_rewrite(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $ids_raw = $_POST['ids'] ?? '[]';
        $ids     = json_decode(wp_unslash($ids_raw), true);
        if (!is_array($ids)) $ids = [];

        $messages = [];
        foreach ($ids as $id){
            $pid   = (int)$id;
            $post  = get_post($pid);
            if (!$post || $post->post_type !== 'tech_news') { $messages[] = 'Skipped... invalid post ID '.$pid; continue; }

            $source   = get_post_meta($pid, '_source_url', true);
            $expanded = ITNF_OpenAI::expand_content($post->post_title, $source, $post->post_content);
            if ($expanded) {
                wp_update_post(['ID' => $pid, 'post_content' => $expanded]);
                add_post_meta($pid, '_itnf_llm_used', 1, false);
                $messages[] = 'Rewrote [ID '.$pid.'] '.$post->post_title;

                // Optional: run optimizer after rewrite if class exists
                if (class_exists('ITNF_RankMath_Optimizer')){
                    ITNF_RankMath_Optimizer::optimize_post($pid, $source);
                }
            } else {
                $messages[] = 'No output... [ID '.$pid.'] '.$post->post_title;
            }
        }

        wp_send_json_success(['messages' => $messages]);
    }
}
