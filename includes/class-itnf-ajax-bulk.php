<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ITNF_Ajax_Bulk
 * - Provides list + bulk rewrite endpoints used by assets/js/itnf-admin-bulk.js
 */
if (!class_exists('ITNF_Ajax_Bulk')):

class ITNF_Ajax_Bulk {

    public static function register(){
        add_action('wp_ajax_itnf_list_tech_news', array(__CLASS__, 'list_tech_news'));
        add_action('wp_ajax_itnf_bulk_rewrite',    array(__CLASS__, 'bulk_rewrite'));
    }

    /** Resolve CPT used by the plugin. Defaults to tech_news but filterable. */
    private static function cpt(){
        $slug = apply_filters('itnf_cpt', 'tech_news');
        if (!post_type_exists($slug)) { $slug = 'post'; }
        return $slug;
    }

    /** Basic word count from HTML */
    private static function wc($html){
        $plain = wp_strip_all_tags((string)$html);
        $plain = preg_replace('~\s+~u', ' ', $plain);
        $plain = trim($plain);
        if ($plain === '') return 0;
        return str_word_count($plain);
    }

    /** Normalize slug (dedupe tokens; ≤75 chars; prepend focus only if setting enabled) */
    private static function normalize_slug($slug, $focus = '') {
        $slug = sanitize_title($slug);
        $f    = $focus ? sanitize_title($focus) : '';

        $opts    = get_option('it_news_fetcher_options');
        $prepend = is_array($opts) ? !empty($opts['rm_optimize_slug']) : false;

        if ($prepend && $f) {
            if ($slug === '' || strpos($slug, $f.'-') !== 0) {
                if ($slug !== '' && strpos($slug, $f) === false) $slug = $f.'-'.$slug;
                elseif ($slug === '') $slug = $f;
            }
        }
        $parts = array_filter(explode('-', $slug), 'strlen');
        $seen  = array(); $uniq = array();
        foreach ($parts as $p) { if (!isset($seen[$p])) { $seen[$p]=1; $uniq[]=$p; } }
        $out=''; foreach ($uniq as $p){
            $candidate = ($out==='' ? $p : $out.'-'.$p);
            if (strlen($candidate) > 75) break;
            $out = $candidate;
        }
        return rtrim($out, '-_');
    }

    /** Simple redirect helper (Rank Math or Redirection) */
    private static function maybe_create_redirect($from_url, $to_url){
        if (empty($from_url) || empty($to_url) || $from_url === $to_url) return;
        try {
            if (function_exists('rank_math_create_redirection')) {
                @rank_math_create_redirection(array($from_url), $to_url, 301, 'active');
                return;
            }
        } catch (\Throwable $e) {}
        try {
            if (class_exists('\Redirection\Api')) {
                \Redirection\Api::create($from_url, $to_url, 301);
                return;
            }
        } catch (\Throwable $e) {}
    }

    /** LIST: rows for Bulk Rewrite table */
    public static function list_tech_news(){
    check_ajax_referer('itnf_admin');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    $q = new WP_Query(array(
        'post_type'      => self::cpt(),
        'post_status'    => 'any',
        'orderby'        => 'date', // query order doesn’t matter since we’ll sort after
        'order'          => 'DESC',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    $rows = array();
    foreach ($q->posts as $pid){
        $p = get_post($pid);
        if (!$p) continue;

        $rows[] = array(
            'ID'        => $pid,
            'words'     => self::wc($p->post_content),
            'title'     => get_the_title($pid),
            'edit'      => get_edit_post_link($pid, ''),
            'permalink' => get_permalink($pid),
            'date'      => get_the_time(get_option('date_format').' '.get_option('time_format'), $pid),
        );
    }

    // 🔥 Sort by word count ascending (fewest words first)
    usort($rows, function($a, $b) {
        return $a['words'] <=> $b['words'];
    });

    wp_send_json_success(array('rows'=>$rows));
}


    /**
     * BULK REWRITE
     * POST:
     *   ids: JSON array of post IDs
     *   regen_seo: '1' to regenerate SEO from expanded content
     *   apply_slug: '1' to apply suggested slug (creates 301 if slug changes)
     */
    public static function bulk_rewrite(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $ids_raw = isset($_POST['ids']) ? (string) $_POST['ids'] : '[]';
        $ids     = json_decode(wp_unslash($ids_raw), true);
        if (!is_array($ids) || !$ids) wp_send_json_error('no_ids');

        $regen_seo = !empty($_POST['regen_seo']) && $_POST['regen_seo']==='1';
        $apply_slug = !empty($_POST['apply_slug']) && $_POST['apply_slug']==='1';

        $messages = array();

        foreach ($ids as $pid){
            $pid = intval($pid);
            $p   = get_post($pid);
            if (!$p) { $messages[] = 'Skipped ID '.$pid.' (not found)'; continue; }

            $messages[] = 'ID '.$pid.': starting rewrite for “'.get_the_title($pid).'”…';

            // Expand content (one-pass) using current title, saved source URL, and existing content
            $source_url = get_post_meta($pid, '_source_url', true);
            $expanded   = '';

            if (class_exists('ITNF_OpenAI')) {
                $expanded = ITNF_OpenAI::expand_content($p->post_title, $source_url, $p->post_content);
            }
            if (!$expanded) {
                $messages[] = 'ID '.$pid.': expansion returned empty; kept original content.';
                continue;
            }

            // Update content
            wp_update_post(array('ID'=>$pid, 'post_content'=>$expanded));
            $messages[] = 'ID '.$pid.': content updated ('.self::wc($expanded).' words).';

            // SEO (optional)
            if ($regen_seo && class_exists('ITNF_OpenAI')) {
                $seo = ITNF_OpenAI::generate_seo($p->post_title, $expanded, $source_url);

                if (!empty($seo['focus'])) update_post_meta($pid, 'rank_math_focus_keyword', sanitize_text_field($seo['focus']));
                if (!empty($seo['title'])) update_post_meta($pid, 'rank_math_title', sanitize_text_field(mb_substr($seo['title'],0,60)));

                $desc = isset($seo['desc']) ? (string)$seo['desc'] : '';
                if ($desc !== '') {
                    if (mb_strlen($desc) > 158) $desc = rtrim(mb_substr($desc, 0, 158), " ,;:-");
                    update_post_meta($pid, 'rank_math_description', sanitize_text_field($desc));
                }

                // Slug application (optional)
                if ($apply_slug && !empty($seo['slug'])) {
                    $cur_slug = get_post_field('post_name', $pid);
                    $new_slug = self::normalize_slug($seo['slug'], $seo['focus'] ?? '');

                    if ($new_slug && $new_slug !== $cur_slug) {
                        $from = get_permalink($pid);
                        wp_update_post(array('ID'=>$pid, 'post_name'=>$new_slug));
                        $to   = get_permalink($pid);
                        self::maybe_create_redirect($from, $to);
                        update_post_meta($pid, '_itnf_ai_slug', $new_slug);
                        $messages[] = 'ID '.$pid.': slug updated to '.$new_slug;
                    }
                }

                $messages[] = 'ID '.$pid.': SEO regenerated.';
            }
        }

        wp_send_json_success(array('messages'=>$messages));
    }
}

ITNF_Ajax_Bulk::register();

endif;
