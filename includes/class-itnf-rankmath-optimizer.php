<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Programmatic optimizer to hit Rank Math's Basic + Additional tests
 * enough to push posts into the green zone (80+).
 *
 * What it attempts:
 * - Focus keyword in: SEO title (at beginning), meta description, URL (optional), first paragraph, content, subheading.
 * - At least one internal link (from settings pool) and one external link (source).
 * - Ensure 1+ image alt includes focus (featured + content <img>).
 * - Keep paragraphs reasonably short (split very long ones).
 */
class ITNF_RankMath_Optimizer {

    /** Return primary focus keyword saved on the post, or fallback to suggestion */
    public static function get_focus_keyword($post_id){
        $fk = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        if ($fk) return $fk;
        $fk = get_post_meta($post_id, '_itnf_seo_suggest_focus', true);
        return $fk ?: '';
    }

    /** Normalize to ASCII, 6-7 words, safe for slug and checks */
    public static function normalize_focus($text){
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $repl = array(
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x80\x93" => '-',
            "\xE2\x80\x94" => '-',
        );
        $text = strtr($text, $repl);
        
        $text = strtolower($text);
        $text = str_replace(array('&amp;', '&'), ' and ', $text);

        // collapse whitespace and punctuation to single spaces
        $text = preg_replace('~[^\p{L}\p{Nd}\s-]+~u', ' ', $text);
        $text = preg_replace('~\s+~u', ' ', $text);
        $text = trim($text);

        // limit to ~7 words
        $parts = preg_split('~\s+~u', $text);
        if (count($parts) > 7) {
            $parts = array_slice($parts, 0, 7);
        }
        return trim(implode(' ', $parts));
    }

    /** Insert keyword into string if missing, optionally at beginning */
    private static function ensure_keyword($haystack, $focus, $at_beginning = true){
        $haystack = (string)$haystack;
        $focus = trim((string)$focus);
        if (!$focus) return $haystack;

        if (stripos($haystack, $focus) !== false) return $haystack;

        if ($at_beginning) {
            $sep = (strlen($haystack) && $haystack[0] !== ' ') ? ' ' : '';
            return $focus.$sep.$haystack;
        } else {
            $sep = (strlen($haystack) && substr($haystack, -1) !== ' ') ? ' ' : '';
            return $haystack.$sep.$focus;
        }
    }

    /** Add internal link from settings pool if none exists */
    private static function ensure_internal_link($html){
        if (!$html) return $html;
        if (preg_match('~<a[^>]+href=["\']\/[^"\']+["\']~i', $html)) return $html; // already has internal

        $opts = get_option('it_news_fetcher_options');
        $pool = isset($opts['rm_internal_links']) && is_array($opts['rm_internal_links']) ? $opts['rm_internal_links'] : array();
        $pool = array_values(array_filter(array_map('trim', $pool)));
        if (!$pool) return $html;

        $href = $pool[array_rand($pool)];
        $anchor = esc_html(wp_parse_url($href, PHP_URL_PATH) ?: 'read more');
        $a = '<p><a href="'.esc_url($href).'">'.$anchor.'</a></p>';

        // insert after first paragraph if exists, else prepend
        if (preg_match('/<\/p>/i', $html)){
            return preg_replace('/<\/p>/i', '</p>'.$a, $html, 1);
        }
        return $a.$html;
    }

    /** Ensure at least one subheading contains the focus */
    private static function ensure_focus_subheading($html, $focus){
        if (!$html || !$focus) return $html;
        if (preg_match('~<(h2|h3|h4)[^>]*>.*?'.preg_quote($focus,'~').'.*?<\/\1>~iu', $html)) {
            return $html;
        }
        // add an h2 after the lead
        $insertion = '<h2>'.esc_html($focus).'</h2>';
        if (preg_match('/<\/p>/i', $html)){
            return preg_replace('/<\/p>/i', '</p>'.$insertion, $html, 1);
        }
        return $insertion.$html;
    }

    /** Ensure images have alt with focus where possible */
    private static function ensure_image_alts($html, $focus){
        if (!$html || !$focus) return $html;
        // add alt to imgs missing it
        $html = preg_replace_callback('~<img([^>]*?)>~i', function($m) use ($focus){
            $tag = $m[0];
            $attrs = $m[1];
            if (!preg_match('~\salt=~i', $attrs)){
                // insert alt attribute
                $tag = preg_replace('~<img~i', '<img alt="'.esc_attr($focus).'"', $tag, 1);
            }
            return $tag;
        }, $html);
        return $html;
    }

    /** Split paragraphs that are too long */
    private static function split_long_paragraphs($html){
        if (!$html) return $html;
        $maxChars = 900; // approx ~150-180 words
        return preg_replace_callback('~<p[^>]*>.*?<\/p>~is', function($m) use ($maxChars){
            $p = $m[0];
            $plain = wp_strip_all_tags($p);
            if (function_exists('mb_strlen') ? mb_strlen($plain) : strlen($plain) < $maxChars){
                return $p;
            }
            // naive split by sentences
            $parts = preg_split('~(?<=[\.\!\?])\s+~u', $plain);
            $chunks = array();
            $cur = '';
            foreach($parts as $s){
                $next = ($cur ? $cur.' ' : '').$s;
                if ((function_exists('mb_strlen') ? mb_strlen($next) : strlen($next)) > $maxChars){
                    if ($cur) { $chunks[] = $cur; $cur = $s; }
                    else { $chunks[] = $s; $cur = ''; }
                } else {
                    $cur = $next;
                }
            }
            if ($cur) $chunks[] = $cur;
            $out = '';
            foreach ($chunks as $c){
                $out .= '<p>'.esc_html($c).'</p>';
            }
            return $out ?: $p;
        }, $html);
    }

    /** Does haystack contain focus (case-insensitive) */
    private static function contains_focus($haystack, $focus){
        $haystack = (string)$haystack;
        $focus = trim((string)$focus);
        if (!$focus) return false;
        return (stripos($haystack, $focus) !== false);
    }

    /** Ensure first paragraph contains focus within first 10% of content */
    private static function ensure_focus_in_intro($html, $focus){
        if (!$focus) return $html;
        $focus = trim($focus);
        if (!$focus) return $html;

        // Find first <p>...</p>
        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $m, PREG_OFFSET_CAPTURE)){
            $p = $m[1][0];
            $full = $m[0][0];
            if (!self::contains_focus($p, $focus)){
                $p2 = self::ensure_keyword($p, $focus, true);
                $html = substr($html, 0, $m[0][1]).str_replace($p, $p2, $full).substr($html, $m[0][1] + strlen($full));
            }
        } else {
            // no <p>, prepend a lead paragraph
            $html = '<p>'.esc_html($focus).'</p>'.$html;
        }
        return $html;
    }

    /** Optimize slug once (respects _itnf_slug_locked and _itnf_ai_slug rules) */
    private static function maybe_optimize_slug($post_id, $focus){
        $locked = get_post_meta($post_id, '_itnf_slug_locked', true);
        if ($locked) return;

        $ai_slug = get_post_meta($post_id, '_itnf_ai_slug', true);
        if ($ai_slug) {
            // AI slug already chosen at initial insert; lock it now
            add_post_meta($post_id, '_itnf_slug_locked', 1, true);
            return;
        }

        $slug = get_post_field('post_name', $post_id);
        $title = get_the_title($post_id);
        $base = $focus ? $focus : $title;
        $base = sanitize_title_with_dashes($base, '', 'save');
        if (!$base) $base = sanitize_title_with_dashes($title, '', 'save');

        if ($base && $base !== $slug){
            $unique = wp_unique_post_slug($base, $post_id, get_post_status($post_id), get_post_type($post_id), 0);
            wp_update_post(array('ID'=>$post_id, 'post_name'=>$unique));
        }

        // lock once we have set a slug, to avoid thrashing
        add_post_meta($post_id, '_itnf_slug_locked', 1, true);
}


    /** One-shot optimizer for a post */
    public static function optimize_post($post_id, $source_url=''){
        $opts = get_option('it_news_fetcher_options');
        if (empty($opts['rm_force_green'])) return;

        $focus = self::get_focus_keyword($post_id);
        $focus = self::normalize_focus($focus);

        // Title & Meta (ensure keyword presence at beginning)
        $seo_title = get_post_meta($post_id, 'rank_math_title', true);
        if (!$seo_title){
            $seo_title = get_the_title($post_id);
            if (!$seo_title) $seo_title = 'Tech News';
        }
        if ($focus){
            // Ensure keyword at the beginning
            if (stripos($seo_title, $focus) !== 0){
                $seo_title = self::ensure_keyword($seo_title, $focus, true);
            }
        }
        // clamp to 60 chars
        $seo_title = function_exists('mb_substr') ? mb_substr($seo_title, 0, 60) : substr($seo_title, 0, 60);
        update_post_meta($post_id, 'rank_math_title', $seo_title);

        $desc = get_post_meta($post_id, 'rank_math_description', true);
        if (!$desc){
            $excerpt = get_the_excerpt($post_id);
            $desc = $excerpt ? $excerpt : wp_trim_words( wp_strip_all_tags(get_post($post_id)->post_content), 28, '' );
        }
        if ($focus && stripos($desc, $focus) === false){
            $desc = $focus.': '.$desc;
        }
        if (function_exists('mb_substr')) $desc = mb_substr($desc, 0, 158);
        else $desc = substr($desc, 0, 158);
        update_post_meta($post_id, 'rank_math_description', $desc);

        // Content modifications
        $p = get_post($post_id);
        $html = $p ? $p->post_content : '';

        $html = self::ensure_focus_in_intro($html, $focus);
        $html = self::ensure_focus_subheading($html, $focus);
        $html = self::ensure_internal_link($html);
        $html = self::ensure_image_alts($html, $focus);
        $html = self::split_long_paragraphs($html);

        // Persist content if changed
        if ($p && $html && $html !== $p->post_content){
            wp_update_post(array('ID'=>$post_id, 'post_content'=>$html));
        }

        // Add alt to featured image
        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id && !empty($focus)){
            if (!get_post_meta($thumb_id, '_wp_attachment_image_alt', true)){
                update_post_meta($thumb_id, '_wp_attachment_image_alt', $focus);
            }
        }

        // Slug
        self::maybe_optimize_slug($post_id, $focus);
    }

    /**
     * Touch optimizer: thin wrapper to keep backward compatibility with callers expecting touch_post().
     * Does not change optimization rules; simply delegates to optimize_post().
     */
    public static function touch_post($post_id, $source_url=''){
        // Maintain existing behavior flags in options; do not force if rm_force_green is off.
        return self::optimize_post($post_id, $source_url);
    }

}
