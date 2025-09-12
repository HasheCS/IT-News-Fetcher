<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Glue wrappers so ITNF_Fetcher’s global calls hit the helper methods.
 * These must be global functions... not inside a class or namespace.
 *
 * This file intentionally defines only thin wrappers and preserves
 * any existing behavior. New wrappers added:
 *  - itnf_item_hash($guid, $link)              -> ITNF_Helpers::item_hash()
 *  - itnf_canon_url($url)                      -> ITNF_Helpers::canon_url()
 *  - it_news_fetcher_get_feed_variants($url)   -> ITNF_Helpers::feed_variants()
 */

if (!function_exists('itnf_pick_image_from_item')) {
    function itnf_pick_image_from_item($item, $content_html, $link_url) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','pick_image_from_item')) {
            return ITNF_Helpers::pick_image_from_item($item, $content_html, $link_url);
        }
        return '';
    }
}

if (!function_exists('itnf_sideload_featured_image')) {
    function itnf_sideload_featured_image($url, $post_id) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','attach_featured_image')) {
            return ITNF_Helpers::attach_featured_image($url, $post_id);
        }
        return false;
    }
}

if (!function_exists('itnf_generate_tags')) {
    function itnf_generate_tags($text, $max = 10) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','generate_tags')) {
            return ITNF_Helpers::generate_tags($text, $max);
        }
        return [];
    }
}

/** NEW: ensure Fetcher and AJAX use the same stable hash logic */
if (!function_exists('itnf_item_hash')) {
    function itnf_item_hash($guid, $link) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','item_hash')) {
            return ITNF_Helpers::item_hash($guid, $link);
        }
        // Safe fallback (less robust): normalize inputs and md5
        $g = trim((string)$guid);
        $l = trim((string)$link);
        return md5($g . '|' . $l);
    }
}

/** NEW: canonicalize URLs exactly like Helpers */
if (!function_exists('itnf_canon_url')) {
    function itnf_canon_url($url) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','canon_url')) {
            return ITNF_Helpers::canon_url($url);
        }
        return (string)$url;
    }
}

/** NEW: expose feed variant helper as global used by Fetcher */
if (!function_exists('it_news_fetcher_get_feed_variants')) {
    function it_news_fetcher_get_feed_variants($url) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','feed_variants')) {
            return ITNF_Helpers::feed_variants($url);
        }
        return [$url]; 
    }
}
