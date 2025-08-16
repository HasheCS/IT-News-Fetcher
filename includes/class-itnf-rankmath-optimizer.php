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
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $parts = explode(' ', $text);
        $parts = array_slice($parts, 0, 7);
        return trim(implode(' ', $parts));

    }

    /** Quickly check if string contains focus (case-insensitive) */
    private static function contains_focus($haystack, $focus){
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
            if (!self::contains_focus(strip_tags($p), $focus)){
                // Prepend a short sentence with the focus at the start.
                $lead = '<p><strong>'.esc_html($focus).'</strong> — ';
                // use first sentence of original p if available
                $sentence = preg_split('/(\.|\!|\?)\s/', wp_strip_all_tags($p), 2);
                $lead .= esc_html( trim($sentence[0]) ) . '.</p>';
                $html = str_replace($full, $lead.$full, $html);
            }
        } else {
            // No paragraph at all, just prepend one
            $html = '<p><strong>'.esc_html($focus).'</strong> — Latest update.</p>'.$html;
        }
        return $html;
    }

    /** Ensure at least one H2/H3 contains the focus */
    private static function ensure_focus_subheading($html, $focus){
        if (!$focus) return $html;
        if (preg_match('/<(h2|h3)[^>]*>.*?<\/\1>/is', $html)){
            if (!preg_match('/<(h2|h3)[^>]*>[^<]*'.preg_quote($focus,'/').'[^<]*<\/\1>/i', $html)){
                // insert an H2 after first paragraph
                $insertion = '<h2>'.esc_html(ucwords($focus)).'</h2>';
                $html = preg_replace('/<\/p>/i', '</p>'.$insertion, $html, 1);
            }
        } else {
            $html = '<h2>'.esc_html(ucwords($focus)).'</h2>'.$html;
        }
        return $html;
    }

    /** Ensure at least one internal link exists */
    private static function ensure_internal_link($html){
        $opts = get_option('it_news_fetcher_options');
        $pool = isset($opts['rm_internal_links']) ? $opts['rm_internal_links'] : '';
        $targets = array();
        foreach (preg_split('/[\r\n,]+/', $pool) as $u){
            $u = trim($u);
            if (!$u) continue;
            if (strpos($u, 'http') === 0 || substr($u,0,1) === '/') $targets[] = $u;
        }
        if (empty($targets)){
            // fallback to tech-news archive if it exists
            $targets[] = home_url('/tech-news/');
        }

        // check if there is any internal link already
        $home = home_url('/');
        if (preg_match('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m)){
            // if any internal present, keep
            if (strpos($m[1], $home) !== false || substr($m[1],0,1) === '/'){
                return $html;
            }
        }
        // inject a "Further reading" paragraph near the end
        $t = $targets[array_rand($targets)];
        $para = '<p><em>Further reading:</em> <a href="'.esc_url($t).'">related insights</a>.</p>';
        if (preg_match('/<\/p>(?!.*<\/p>)/is', $html)){
            $html = preg_replace('/<\/p>(?!.*<\/p>)/is', '</p>'.$para, $html, 1);
        } else {
            $html .= $para;
        }
        return $html;
    }

    /** Ensure at least one image alt contains focus; add alt to all images missing it */
    private static function ensure_image_alts($html, $focus){
        return preg_replace_callback('/<img([^>]+)>/i', function($m) use ($focus){
            $tag = $m[0]; $attrs = $m[1];
            if (stripos($attrs, 'alt=') !== false){
                // if alt exists but doesn't include focus, keep as-is (avoid over-optimization)
                return $tag;
            }
            $alt = esc_attr($focus ?: 'illustration');
            // insert alt attribute before closing bracket
            $new = '<img'.$attrs.' alt="'.$alt.'">';
            return $new;
        }, $html);
    }

    /** Split very long paragraphs (> 220 words) for readability */
    private static function split_long_paragraphs($html){
        return preg_replace_callback('/<p[^>]*>(.*?)<\/p>/is', function($m){
            $inside = trim($m[1]);
            $words = preg_split('/\s+/', wp_strip_all_tags($inside));
            if (count($words) <= 220) return $m[0];
            // split into two <p> by sentence boundary
            $parts = preg_split('/(\.|\!|\?)\s+/u', $inside, 2);
            if (count($parts) == 2){
                return '<p>'.$parts[0].'.</p><p>'.$parts[1].'</p>';
            }
            return $m[0];
        }, $html);
    }

    /** Optimize slug to include focus (optional) */
    private static function maybe_optimize_slug($post_id, $focus){
        $opts = get_option('it_news_fetcher_options');
        if (empty($opts['rm_optimize_slug'])) return;
        if (!$focus) return;
    
        // allow a per-post bypass
        if (get_post_meta($post_id, '_itnf_slug_never_touch', true)) return;
    
        // never change a slug more than once via optimizer
        if (get_post_meta($post_id, '_itnf_slug_locked', true)) return;
    
        $p = get_post($post_id);
        if (!$p) return;
    
        // respect manual editor activity
        if (get_post_meta($post_id, '_edit_last', true)) return;
    
        // only adjust within 10 minutes of creation to avoid late fights or redirects
        $created_gmt = $p->post_date_gmt ? strtotime($p->post_date_gmt) : strtotime($p->post_date);
        if ($created_gmt && (time() - $created_gmt) > 10 * 60) return;
    
        $slug = $p->post_name ?: sanitize_title($p->post_title);
        $focus_slug = sanitize_title($focus);
    
        // already contains focus... done
        if (false !== strpos($slug, $focus_slug)) return;
    
        // let WP handle uniqueness
        $desired = sanitize_title($focus_slug.' '.$slug);
    
        // update and lock
        wp_update_post(array('ID' => $post_id, 'post_name' => $desired));
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
        }
        if ($focus && stripos($seo_title, $focus) !== 0){
            $seo_title = $focus.' – '.$seo_title;
        }
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
}
