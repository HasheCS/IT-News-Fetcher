<?php
if (!defined('ABSPATH')) { exit; }
/**
 * Replacement for includes/class-itnf-fetcher.php
 * Provides ITNF_Fetcher with process_single_feed(...) used by the AJAX runner.
 * This file is self-contained and plays nicely with your existing helpers:
 *  - itnf_log_if()
 *  - it_news_fetcher_get_feed_variants()
 *  - itnf_item_hash(), itnf_pick_image_from_item(), itnf_attach_featured_image(), itnf_generate_tags()
 *  - itnf_openai_expand_content()  (legacy) or ITNF_OpenAI::expand_content() (new)
 *  - ITNF_RankMath_Optimizer::optimize_post() (if present)
 */

class ITNF_Fetcher {

    /**
     * Fetch and post items for a single feed.
     *
     * @param string  $feed_url
     * @param int     $per                 Max items per feed
     * @param string  $fallback_thumb      URL to fallback image
     * @param int     $expand_threshold    If content length < threshold, expand with OpenAI
     * @param bool    $auto_rankmath       Generate RM metas during create
     * @param string  $run_id              Logger run id (if your logger uses it)
     */
    public static function process_single_feed($feed_url, $per, $fallback_thumb, $expand_threshold, $auto_rankmath, $run_id){
        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        // Normalize inputs
        $feed_url = trim((string)$feed_url);
        $per = max(1, min(20, intval($per)));
        $expand_threshold = max(200, intval($expand_threshold));
        $auto_rankmath = !empty($auto_rankmath);

        // Determine feed variants
        $variants = array($feed_url);
        if (function_exists('it_news_fetcher_get_feed_variants')) {
            $variants = it_news_fetcher_get_feed_variants($feed_url);
        }

        self::log('Starting single feed: ' . $feed_url);
        foreach ($variants as $variant) {
            // Optional cancel support if your logger exposes should_stop()
            if (class_exists('ITNF_Logger') && method_exists('ITNF_Logger','should_stop') && ITNF_Logger::should_stop($run_id)) {
                self::log('Cancellation requested. Exiting feed: ' . $feed_url);
                return;
            }

            self::log('Trying variant... ' . $variant);
            $rss = fetch_feed($variant);
            if (is_wp_error($rss)) {
                self::log('Variant failed... ' . $rss->get_error_message());
                continue;
            }

            $max_items = $rss->get_item_quantity($per);
            $items     = $rss->get_items(0, $max_items);
            self::log('Items found... ' . intval($max_items) . ' (capped to ' . $per . ')');
            if (empty($items)) { self::log('No items...'); continue; }

            // Author selection from settings; fallback to current user -> 1
            $opts = get_option('it_news_fetcher_options');
            $author = isset($opts['default_author']) ? absint($opts['default_author']) : 0;
            if (!$author) $author = ( get_current_user_id() ?: 1 );

            foreach ($items as $item) {
                // Optional cancel
                if (class_exists('ITNF_Logger') && method_exists('ITNF_Logger','should_stop') && ITNF_Logger::should_stop($run_id)) {
                    self::log('Cancellation mid-feed.');
                    return;
                }

                $title   = $item->get_title();
                $link    = $item->get_permalink();
                $guid    = $item->get_id();
                $content = $item->get_content();
                if (!$content) $content = $item->get_description();

                self::log('Processing... ' . $title);

                // Dupe check
                $hash = function_exists('itnf_item_hash') ? itnf_item_hash($guid, $link) : md5(trim((string)$guid) . '|' . (string)$link);
                $dupe = get_posts(array(
                    'post_type' => 'tech_news',
                    'meta_key'  => '_itnf_hash',
                    'meta_value'=> $hash,
                    'fields'    => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                ));
                if ($dupe) { self::log('Duplicate... skipped'); continue; }

                // Insert post
                $new_post = array(
                    'post_type'    => 'tech_news',
                    'post_status'  => 'publish',
                    'post_author'  => $author,
                    'post_title'   => wp_strip_all_tags($title),
                    'post_content' => $content ?: '',
                );
                $dt = $item->get_date('Y-m-d H:i:s');
                if ($dt) $new_post['post_date'] = $dt;

                $post_id = wp_insert_post($new_post);
                if (is_wp_error($post_id)) { self::log('Error inserting: ' . $title . ' → ' . $post_id->get_error_message()); continue; }

                add_post_meta($post_id, '_itnf_hash', $hash, true);
                if ($link) add_post_meta($post_id, '_source_url', esc_url_raw($link), true);
                if ($guid) add_post_meta($post_id, '_source_guid', sanitize_text_field($guid), true);

                // Expand with OpenAI if thin
                $final = $content ?: '';
                $plain_len = strlen(wp_strip_all_tags($final));
                if ($plain_len < $expand_threshold) {
                    self::log('Expanding with OpenAI...');
                    $expanded = '';
                    if (class_exists('ITNF_OpenAI')) {
                        $expanded = ITNF_OpenAI::expand_content($title, $link, $final);
                    } elseif (function_exists('itnf_openai_expand_content')) {
                        $expanded = itnf_openai_expand_content($title, $link, $final);
                    }
                    if ($expanded) {
                        $final = $expanded;
                        wp_update_post(array('ID'=>$post_id,'post_content'=>$final));
                        add_post_meta($post_id, '_itnf_llm_used', 1, true);
                        self::log('Expansion complete');
                    } else {
                        self::log('Expansion skipped... no output');
                    }
                }

                // Auto tags
                if (function_exists('itnf_generate_tags')) {
                    $tags = itnf_generate_tags($final);
                    if (!empty($tags)) { wp_set_post_tags($post_id, $tags, true); self::log('Tags applied'); }
                }

                // Featured image
                $img = function_exists('itnf_pick_image_from_item') ? itnf_pick_image_from_item($item, $content, $link) : '';
                if ($img && function_exists('itnf_attach_featured_image')) { itnf_attach_featured_image($img, $post_id); self::log('Featured image set'); }
                elseif (!empty($fallback_thumb) && function_exists('itnf_attach_featured_image')) { itnf_attach_featured_image($fallback_thumb, $post_id); self::log('Fallback image set'); }

                // Rank Math generation + optimizer
                if ($auto_rankmath && class_exists('ITNF_OpenAI')) {
                    $seo = ITNF_OpenAI::generate_seo($title, $final, $link);
                    if (!empty($seo['focus'])) update_post_meta($post_id, 'rank_math_focus_keyword', $seo['focus']);
                    if (!empty($seo['title'])) update_post_meta($post_id, 'rank_math_title', $seo['title']);
                    if (!empty($seo['desc']))  update_post_meta($post_id, 'rank_math_description', $seo['desc']);
                    self::log('Rank Math SEO generated');
                }
                if (class_exists('ITNF_RankMath_Optimizer')) {
                    ITNF_RankMath_Optimizer::optimize_post($post_id, $link);
                }

                self::log('Posted... ID ' . $post_id);
            }

            self::log('Feed done.');
            return; // success
        }

        self::log('All variants failed for ' . $feed_url);
    }

    private static function log($msg){
        if (function_exists('itnf_log_if')) { itnf_log_if($msg); }
    }
}

// Keep your previous shim for direct calls if any legacy code uses it
if (!class_exists('ITNF_Fetcher_Shim')){
    class ITNF_Fetcher_Shim {
        public static function after_insert($post_id, $source_url=''){
            $o = get_option('it_news_fetcher_options');
            if (!empty($o['auto_rankmath']) && class_exists('ITNF_OpenAI')){
                $p = get_post($post_id);
                $seo = ITNF_OpenAI::generate_seo($p->post_title, $p->post_content, $source_url);
                if (!empty($seo['focus'])) update_post_meta($post_id, 'rank_math_focus_keyword', $seo['focus']);
                if (!empty($seo['title'])) update_post_meta($post_id, 'rank_math_title', $seo['title']);
                if (!empty($seo['desc']))  update_post_meta($post_id, 'rank_math_description', $seo['desc']);
            }
            if (class_exists('ITNF_RankMath_Optimizer')){
                ITNF_RankMath_Optimizer::optimize_post($post_id, $source_url);
            }
        }
    }
}
