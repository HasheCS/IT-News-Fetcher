<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Glue wrappers so ITNF_Fetcher’s global calls hit the helper methods.
 * These must be global functions... not inside a class or namespace.
 */

if (!function_exists('itnf_pick_image_from_item')) {
    function itnf_pick_image_from_item($item, $content_html, $link_url) {
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','pick_image_from_item')) {
            return ITNF_Helpers::pick_image_from_item($item, $content_html, $link_url);
        }
        return '';
    }
}

if (!function_exists('itnf_attach_featured_image')) {
    function itnf_attach_featured_image($url, $post_id) {
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
