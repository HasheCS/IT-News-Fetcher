<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ITNF_Ajax_SEO
 * Admin-side AJAX for SEO listing, AI generation and safe apply.
 * Cross-referenced with:
 *  - ITNF_OpenAI::generate_seo() / ::expand_content()
 *  - ITNF_RankMath_Optimizer::optimize_post()
 *  - Admin view: admin/views/tab-seo.php
 *  - Admin JS: assets/js/itnf-admin-seo.js
 *
 * Behavior:
 *  - Generates SEO fields (focus/title/desc/slug) from the CURRENT post content
 *    which may already be AI-expanded. Respects all Settings (temperature, tokens,
 *    rm_optimize_slug) via ITNF_OpenAI.
 *  - Apply respects manual edits and only updates the slug when requested.
 *  - Slug normalization dedupes tokens, caps at 75 chars, and prepends focus
 *    only if the Rank Math setting is enabled.
 *
 * Enhanced:
 *  - Detailed, de-duplicated log lines returned with each response:
 *      lines[] = { key: string, line: "[hh:mm:ss] PHASE · message" }
 *    The UI can suppress identical {key,line} repeats to avoid "running... running..."
 */
if (!class_exists('ITNF_Ajax_SEO')):

class ITNF_Ajax_SEO {

    public static function register(){
        add_action('wp_ajax_itnf_seo_list',     array(__CLASS__,'seo_list'));
        add_action('wp_ajax_itnf_seo_generate', array(__CLASS__,'seo_generate'));
        add_action('wp_ajax_itnf_seo_apply',    array(__CLASS__,'seo_apply'));
    }

    /* ================= Utilities ================= */

    /** Resolve CPT used by the plugin. Defaults to tech_news but filterable. */
    private static function cpt(){
        $slug = apply_filters('itnf_cpt', 'tech_news');
        if (!post_type_exists($slug)) { $slug = 'post'; }
        return $slug;
    }

    /** Basic word count from HTML */
    private static function word_count($html){
        $plain = wp_strip_all_tags((string)$html);
        $plain = preg_replace('~\s+~u', ' ', $plain);
        $plain = trim($plain);
        if ($plain === '') return 0;
        return str_word_count($plain);
    }

    /** Ensure one <h2>/<h3> contains focus keyword */
    private static function ensure_focus_in_heading($html, $focus){
        if (empty($focus)) return $html;
        if (preg_match('~<h[23][^>]*>[^<]*'.preg_quote($focus, '~').'~i', $html)) { return $html; }
        if (preg_match('~</p>~i', $html)) {
            return preg_replace('~</p>~i', '</p><h2>'.esc_html($focus).'</h2>', $html, 1);
        }
        return '<h2>'.esc_html($focus).'</h2>'.$html;
    }

    /** Ensure focus appears early in content */
    private static function ensure_focus_in_intro($html, $focus){
        if (empty($focus)) return $html;
    
        // Clean focus (trim and drop trailing punctuation/dashes)
        $clean = rtrim((string)$focus, " \t\n\r\0\x0B:—–-");
    
        // If focus already appears anywhere (case-insensitive, word-ish match), do nothing
        $plain = mb_strtolower(wp_strip_all_tags((string)$html));
        $fpat  = '~\b' . preg_quote(mb_strtolower($clean), '~') . '\b~u';
        if ($clean === '' || preg_match($fpat, $plain)) {
            return $html;
        }
    
        // If we've already injected an intro with this exact <em>focus</em>: prefix, skip
        $already = '~<p\b[^>]*>\s*<em\b[^>]*>\s*' . preg_quote($clean, '~') . '\s*</em>\s*:\s*~iu';
        if (preg_match($already, (string)$html)) {
            return $html;
        }
    
        $sentence = '<p><em>'.esc_html($clean).'</em>: key context and updates inside.</p>';
    
        // If there is an H2/H3, insert AFTER the first one; else, prepend to content
        if (preg_match('~(<h[23]\b[^>]*>.*?</h[23]>)~is', (string)$html, $m)) {
            return preg_replace('~' . preg_quote($m[1], '~') . '~', $m[1] . $sentence, (string)$html, 1);
        }
    
        return $sentence . (string)$html;
    }

    /** Naive density check */
    private static function keyword_density($html, $focus){
        $wc = self::word_count($html);
        if ($wc < 1 || empty($focus)) return 0.0;
        $plain = mb_strtolower(wp_strip_all_tags($html));
        $f = mb_strtolower($focus);
        $count = preg_match_all('~\b'.preg_quote($f, '~').'\b~u', $plain);
        return $wc ? ($count / $wc) : 0.0;
    }

    /** Ensure density roughly >= 1% by appending one sentence if needed */
    private static function ensure_density($html, $focus){
        $density = self::keyword_density($html, $focus);
        if ($density >= 0.01) return $html;
        $extra = '<p>This article explains <strong>'.esc_html($focus).'</strong> with background, implications, and practical takeaways.</p>';
        return $html.$extra;
    }

    /** Ensure at least one image has alt text including focus */
    private static function ensure_image_alt($html, $focus){
        if (empty($focus)) return $html;
        $html = preg_replace_callback('~<img\s+([^>]*?)>~i', function($m) use ($focus){
            $attrs = $m[1];
            if (!preg_match('~\balt\s*=~i', $attrs) || preg_match('~\balt\s*=\s*([\'\"])\s*\1~i', $attrs)) {
                $attrs = preg_replace('~\balt\s*=\s*([\'\"]).*?\1~i', '', $attrs);
                $attrs = trim($attrs);
                if ($attrs !== '' && substr($attrs, -1) !== ' ') $attrs .= ' ';
                $attrs .= 'alt="'.esc_attr($focus).'"';
                return '<img '.$attrs.'>';
            }
            return $m[0];
        }, $html, 1);
        return $html;
    }

    /** Ensure at least one internal link (from pool); external link is the Source footer already */
    private static function ensure_links($html, $source_url){
        $has_external = preg_match('~<a\s[^>]*href\s*=\s*["\']https?://~i', $html);
        $has_internal = preg_match('~<a\s[^>]*href\s*=\s*["\']/~i', $html) ||
                        preg_match('~<a\s[^>]*href\s*=\s*["\']'.preg_quote(home_url('/'), '~').'~i', $html);

        if (!$has_external && !empty($source_url)) {
            // Keep this dofollow per your SEO preference
            $html .= '<p class="source">Source: <a href="'.esc_url($source_url).'">Original report</a></p>';
        }

        if (!$has_internal) {
            $opts = get_option('it_news_fetcher_options');
            $pool = isset($opts['rm_internal_links']) ? trim((string)$opts['rm_internal_links']) : '';
            if ($pool) {
                $lines = preg_split('/\r\n|\n|\r/', $pool);
                $lines = array_map('trim', $lines);
                $lines = array_filter($lines, function($u){ return $u !== ''; });
                if ($lines) {
                    $link = reset($lines);
                    $html .= '<p>Related: <a href="'.esc_url($link).'">More technology coverage</a></p>';
                }
            } else {
                $html .= '<p>Related: <a href="'.esc_url(home_url('/')).'">More technology coverage</a></p>';
            }
        }
        return $html;
    }

    /** Slug normalization (settings-aware focus prepend + token de-dup + 75-char cap) */
    private static function normalize_slug($slug, $focus = '') {
        // Base sanitize
        $slug = sanitize_title($slug);
        $f    = $focus ? sanitize_title($focus) : '';

        // Only prepend focus if enabled in settings
        $opts    = get_option('it_news_fetcher_options');
        $prepend = is_array($opts) ? !empty($opts['rm_optimize_slug']) : false;

        if ($prepend && $f) {
            if ($slug === '' || strpos($slug, $f . '-') !== 0) {
                if ($slug !== '' && strpos($slug, $f) === false) {
                    $slug = $f . '-' . $slug;
                } elseif ($slug === '') {
                    $slug = $f;
                }
            }
        }

        // De-duplicate hyphen tokens while preserving order
        $parts = array_filter(explode('-', $slug), 'strlen');
        $seen  = array();
        $uniq  = array();
        foreach ($parts as $p) {
            if (!isset($seen[$p])) {
                $seen[$p] = true;
                $uniq[] = $p;
            }
        }

        // Rebuild and enforce 75-char cap by whole tokens
        $out = '';
        foreach ($uniq as $p) {
            $candidate = $out === '' ? $p : $out . '-' . $p;
            if (strlen($candidate) > 75) break;
            $out = $candidate;
        }

        return rtrim($out, '-_');
    }

    /** Detect if user manually overrode slug after last AI set */
    private static function user_overrode_slug($post_id){
        $ai = get_post_meta($post_id, '_itnf_ai_slug', true);
        $cur = get_post_field('post_name', $post_id);
        return ($ai && $cur && $ai !== $cur);
    }

    /** Attempt to create a 301 redirect using Rank Math or Redirection plugin, if available. */
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
        $list = get_option('itnf_pending_redirects', array());
        $list[] = array('from'=>$from_url, 'to'=>$to_url, 'ts'=>time());
        update_option('itnf_pending_redirects', $list, false);
    }

    /** Timestamp */
    private static function ts(){ return '['.current_time('H:i:s').']'; }

    /** Push a log line with stable key */
    private static function push_line(&$lines, $key, $phase, $msg){
        $lines[] = array(
            'key'  => (string)$key,
            'line' => self::ts().' '.$phase.' · '.$msg
        );
    }

    /* ================= AJAX: List (paginated) ================= */

    /** Return a paginated list for the SEO tab.  POST: page (1-based), per_page */
    public static function seo_list(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $page     = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['per_page']) ? max(1, min(200, intval($_POST['per_page']))) : 50;

        $q = new WP_Query(array(
            'post_type'      => self::cpt(),
            'post_status'    => 'any',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
        ));

        $rows = array();
        foreach ($q->posts as $pid){
            $p = get_post($pid);
            if (!$p) continue;

            $rows[] = array(
                'ID'        => $pid,
                'title'     => get_the_title($pid),
                'words'     => self::word_count($p->post_content),
                'focus'     => get_post_meta($pid, 'rank_math_focus_keyword', true),
                'desc'      => get_post_meta($pid, 'rank_math_description', true),
                'slug'      => $p->post_name,
                'ai_slug'   => get_post_meta($pid, '_itnf_ai_slug', true),
                's_focus'   => get_post_meta($pid, '_itnf_seo_suggest_focus', true),
                's_title'   => get_post_meta($pid, '_itnf_seo_suggest_title', true),
                's_desc'    => get_post_meta($pid, '_itnf_seo_suggest_desc',  true),
                's_slug'    => get_post_meta($pid, '_itnf_seo_suggest_slug',  true),
                'edit_link' => get_edit_post_link($pid, ''),
                'view_link' => get_permalink($pid),
            );
        }

        wp_send_json_success(array(
            'rows'        => $rows,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => intval($q->found_posts),
            'total_pages' => max(1, intval($q->max_num_pages)),
        ));
    }

    /* ================= AJAX: Generate SEO suggestions ================= */

    /** Generate SEO suggestions and store as _itnf_seo_suggest_* metas. POST: ids[] */
    public static function seo_generate(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
        $ids = array_values(array_filter(array_map('intval', $ids)));

        $messages = array();
        $lines    = array();

        if (!$ids){
            $messages[] = 'No posts selected.';
            self::push_line($lines, 'gen:start', 'GEN', 'No posts selected.');
            wp_send_json_success(array('messages'=>$messages, 'lines'=>$lines, 'generated'=>0, 'failed'=>0, 'done'=>true));
        }

        self::push_line($lines, 'gen:start', 'GEN', 'Selected '.count($ids).' posts.');
        $generated = 0; $failed = 0; $i = 0; $n = count($ids);

        foreach ($ids as $pid){
            $i++;
            $p = get_post($pid);
            if (!$p){
                $msg = "Post $i/$n #$pid not found, skipped.";
                $messages[] = $msg; self::push_line($lines, "gen:$pid:skip", 'GEN', $msg);
                $failed++; continue;
            }

            $title = get_the_title($pid);
            $w     = self::word_count($p->post_content);
            self::push_line($lines, "gen:$pid:load", 'GEN', "Post $i/$n #$pid \"$title\"");
            self::push_line($lines, "gen:$pid:wc",   'GEN', "Content words $w");

            $source = get_post_meta($pid, '_source_url', true);
            $seo = array('focus'=>'', 'title'=>'', 'desc'=>'', 'slug'=>'');

            if (class_exists('ITNF_OpenAI')) {
                $seo = ITNF_OpenAI::generate_seo($title, $p->post_content, $source);
            }

            // Fallbacks consistent with your previous logic
            if (empty($seo['focus']) && method_exists('ITNF_OpenAI','normalize_focus')) {
                $seo['focus'] = ITNF_OpenAI::normalize_focus($title);
            }
            if (empty($seo['title'])) { $seo['title'] = ($seo['focus'] ? $seo['focus'].' - ' : '').$title; }
            if (empty($seo['desc']))  { $seo['desc']  = ($seo['focus'] ?: 'update').': '.$w.' words'; }
            if (empty($seo['slug']))  { $seo['slug']  = sanitize_title(($seo['focus'] ? $seo['focus'].' ' : '').$title); }

            $focus = sanitize_text_field($seo['focus']);
            $mtitle= sanitize_text_field($seo['title']);
            $mdesc = sanitize_text_field($seo['desc']);
            $mslug = sanitize_title($seo['slug']);

            if (!$focus || !$mtitle || !$mdesc || !$mslug){
                $msg = "Post #$pid incomplete SEO result, skipped.";
                $messages[] = $msg; self::push_line($lines, "gen:$pid:bad", 'GEN', $msg);
                $failed++; continue;
            }

            update_post_meta($pid, '_itnf_seo_suggest_focus', $focus);
            update_post_meta($pid, '_itnf_seo_suggest_title', $mtitle);
            update_post_meta($pid, '_itnf_seo_suggest_desc',  $mdesc);
            update_post_meta($pid, '_itnf_seo_suggest_slug',  $mslug);

            $messages[] = 'Generated suggestions for [ID '.$pid.']';
            self::push_line($lines, "gen:$pid:ok", 'GEN', "focus \"$focus\" | title \"$mtitle\" | slug \"$mslug\"");
            $generated++;
        }

        self::push_line($lines, "gen:done", 'GEN', "Done. generated $generated, failed $failed");
        wp_send_json_success(array(
            'messages'  => $messages,
            'lines'     => $lines,
            'generated' => $generated,
            'failed'    => $failed,
            'done'      => true,
        ));
    }

    /* ================= AJAX: Apply SEO suggestions ================= */

    /**
     * Apply suggestions and normalize for RankMath.
     * POST:
     *   ids[]           : post IDs
     *   apply_slug      : '1' to update slug (creates redirect)
     *   force_overwrite : 1 to overwrite existing focus/title/desc
     */
    public static function seo_apply(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $ids             = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
        $ids             = array_values(array_filter(array_map('intval', $ids)));
        $apply_slug      = !empty($_POST['apply_slug']) && $_POST['apply_slug'] === '1';
        $force_overwrite = !empty($_POST['force_overwrite']) ? 1 : 0;

        $messages = array();
        $lines    = array();

        if (!$ids){
            $messages[] = 'No posts selected.';
            self::push_line($lines, 'apply:start', 'APPLY', 'No posts selected.');
            wp_send_json_success(array('messages'=>$messages, 'lines'=>$lines, 'applied'=>0, 'failed'=>0, 'preserved'=>0, 'slugged'=>0, 'done'=>true));
        }

        self::push_line($lines, 'apply:start', 'APPLY', 'Selected '.count($ids).' posts. Force overwrite '.($force_overwrite?'yes':'no').', Apply slug '.($apply_slug?'yes':'no').'.');

        $applied = 0; $failed = 0; $preserve = 0; $slugged = 0; $i = 0; $n = count($ids);

        foreach ($ids as $pid){
            $i++;
            $p = get_post($pid);
            if (!$p){
                $msg = "Post $i/$n #$pid not found, skipped.";
                $messages[] = $msg; self::push_line($lines, "apply:$pid:skip", 'APPLY', $msg);
                $failed++; continue;
            }

            $title = get_the_title($pid);
            self::push_line($lines, "apply:$pid:load", 'APPLY', "Post $i/$n #$pid \"$title\"");

            $focus_cur = get_post_meta($pid, 'rank_math_focus_keyword', true);
            $title_cur = get_post_meta($pid, 'rank_math_title', true);
            $desc_cur  = get_post_meta($pid, 'rank_math_description', true);
            $slug_cur  = $p->post_name;

            $s_focus = get_post_meta($pid, '_itnf_seo_suggest_focus', true);
            $s_title = get_post_meta($pid, '_itnf_seo_suggest_title', true);
            $s_desc  = get_post_meta($pid, '_itnf_seo_suggest_desc',  true);
            $s_slug  = get_post_meta($pid, '_itnf_seo_suggest_slug',  true);

            // Decide final values honoring "force_overwrite"
            $final_focus = ($force_overwrite ? $s_focus : ($s_focus ?: $focus_cur));
            $final_title = ($force_overwrite ? $s_title : ($s_title ?: $title_cur));
            $final_desc  = ($force_overwrite ? $s_desc  : ($s_desc  ?: $desc_cur));
            $final_slug  = $s_slug; // only if apply_slug is true will we use it

            // If no suggestions exist, generate on the fly to keep consistent
            if ((!$s_focus && !$s_title && !$s_desc && !$s_slug) && class_exists('ITNF_OpenAI')){
                $source = get_post_meta($pid, '_source_url', true);
                $seo = ITNF_OpenAI::generate_seo($p->post_title, $p->post_content, $source);
                $final_focus = $seo['focus'] ?: $final_focus;
                $final_title = $seo['title'] ?: $final_title;
                $final_desc  = $seo['desc']  ?: $final_desc;
                $final_slug  = $seo['slug']  ?: $final_slug;
                self::push_line($lines, "apply:$pid:gensync", 'APPLY', 'Suggestions missing; generated on the fly.');
            }

            // Apply Rank Math fields (focus, title, description)
            if ($final_focus) {
                update_post_meta($pid, 'rank_math_focus_keyword', sanitize_text_field($final_focus));
            }
            if ($final_title) {
                if (mb_strlen($final_title) > 60) { $final_title = rtrim(mb_substr($final_title, 0, 60)); }
                update_post_meta($pid, 'rank_math_title', sanitize_text_field($final_title));
            }
            if (!$final_desc) $final_desc = $p->post_excerpt ? $p->post_excerpt : wp_strip_all_tags($p->post_content);
            $final_desc = trim(preg_replace('~\s+~u', ' ', (string)$final_desc));
            if ($final_focus && stripos($final_desc, $final_focus) === false) {
                $final_desc = $final_focus.': '.$final_desc;
            }
            if (mb_strlen($final_desc) < 120) $final_desc = str_pad($final_desc, 120, '.');
            if (mb_strlen($final_desc) > 158) { $final_desc = rtrim(mb_substr($final_desc, 0, 158), " ,;:-"); }
            update_post_meta($pid, 'rank_math_description', sanitize_text_field($final_desc));

            self::push_line($lines, "apply:$pid:rm", 'APPLY', 'Rank Math fields applied (focus/title/desc).');

            // Slug handling (respect manual overrides)
            $did_slug = false;
            if ($apply_slug && $final_slug) {
                $new_slug = self::normalize_slug($final_slug, $final_focus);
                $cur_slug = $slug_cur;
                $ai_slug  = get_post_meta($pid, '_itnf_ai_slug', true);

                // Respect manual edits: if current slug != last AI slug, preserve unless it's the same as new generated
                if ($cur_slug && $cur_slug !== $new_slug && $cur_slug !== $ai_slug) {
                    $preserve++;
                    self::push_line($lines, "apply:$pid:slug-preserve", 'APPLY', 'Manual slug override detected, preserving.');
                } else {
                    $from_url = get_permalink($pid);
                    wp_update_post(array('ID'=>$pid, 'post_name'=>$new_slug));
                    update_post_meta($pid, '_itnf_ai_slug', $new_slug);
                    $to_url = get_permalink($pid);
                    self::maybe_create_redirect($from_url, $to_url);
                    $slugged++; $did_slug = true;
                    self::push_line($lines, "apply:$pid:slug", 'APPLY', 'Slug updated to "'.$new_slug.'".');
                }
            }

            // Optional light content optimizations for Rank Math checks
            $content = $p->post_content;
            $content = self::ensure_focus_in_heading($content, $final_focus);
            $content = self::ensure_focus_in_intro($content, $final_focus);
            $content = self::ensure_density($content, $final_focus);
            $content = self::ensure_image_alt($content, $final_focus);
            $content = self::ensure_links($content, get_post_meta($pid, '_source_url', true));
            wp_update_post(array('ID'=>$pid, 'post_content'=>$content));

            $applied++;
            $tail = $did_slug ? '; slug updated' : '';
            self::push_line($lines, "apply:$pid:ok", 'APPLY', 'Completed updates'.$tail.'.');
        }

        self::push_line($lines, 'apply:done', 'APPLY', "Done. applied $applied, preserved slug $preserve, slugged $slugged, failed $failed");
        wp_send_json_success(array(
            'messages'  => $messages,
            'lines'     => $lines,
            'applied'   => $applied,
            'preserved' => $preserve,
            'slugged'   => $slugged,
            'failed'    => $failed,
            'done'      => true,
        ));
    }

}

endif; // class_exists 
