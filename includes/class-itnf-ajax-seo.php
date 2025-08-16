<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_Ajax_SEO {
    public static function register(){
        add_action('wp_ajax_itnf_seo_list', [__CLASS__,'seo_list']);
        add_action('wp_ajax_itnf_seo_generate', [__CLASS__,'seo_generate']);
        add_action('wp_ajax_itnf_seo_apply', [__CLASS__,'seo_apply']);
    }

    public static function seo_list(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $posts = get_posts([
            'post_type'=>'tech_news','posts_per_page'=>200,'post_status'=>'any',
            'orderby'=>'date','order'=>'DESC','no_found_rows'=>true,'fields'=>'ids'
        ]);
        $rows = [];
        foreach ($posts as $pid){
            $p = get_post($pid);
            $rows[] = [
                'ID'    => $pid,
                'words' => str_word_count(wp_strip_all_tags($p->post_content)),
                'title' => esc_html(get_the_title($pid)),
                'focus' => get_post_meta($pid,'rank_math_focus_keyword',true),
                'desc'  => get_post_meta($pid,'rank_math_description',true),
            ];
        }
        wp_send_json_success(['rows'=>$rows]);
    }

    public static function seo_generate(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $ids_raw = $_POST['ids'] ?? '[]';
        $ids = json_decode(wp_unslash($ids_raw), true);
        if (!is_array($ids)) $ids = [];
        $overwrite = !empty($_POST['overwrite']);
        $messages = [];
        foreach ($ids as $id){
            $pid = (int)$id; $p = get_post($pid);
            if (!$p || $p->post_type !== 'tech_news'){ $messages[]='Skipped... invalid post ID '.$pid; continue; }

            $hasExisting = ( get_post_meta($pid,'rank_math_focus_keyword',true) ||
                             get_post_meta($pid,'rank_math_title',true) ||
                             get_post_meta($pid,'rank_math_description',true) );
            if ($hasExisting && !$overwrite){ $messages[]='Skipped (existing SEO kept) [ID '.$pid.']'; continue; }

            $source = get_post_meta($pid,'_source_url',true);
            $seo = ITNF_OpenAI::generate_seo($p->post_title, $p->post_content, $source);

            if ($seo['focus']) update_post_meta($pid, '_itnf_seo_suggest_focus', $seo['focus']);
            if ($seo['title']) update_post_meta($pid, '_itnf_seo_suggest_title', $seo['title']);
            if ($seo['desc'])  update_post_meta($pid, '_itnf_seo_suggest_desc',  $seo['desc']);

            $messages[] = 'Generated SEO [ID '.$pid.'] focus: '.$seo['focus'].' | title: '.$seo['title'].' | desc: '. ( $seo['desc'] ? 'yes' : 'no');
        }
        wp_send_json_success(['messages'=>$messages]);
    }

    public static function seo_apply(){
        check_ajax_referer('itnf_admin');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $ids_raw = $_POST['ids'] ?? '[]';
        $ids = json_decode(wp_unslash($ids_raw), true);
        if (!is_array($ids)) $ids = [];
        $messages = [];
        foreach ($ids as $id){
            $pid = (int)$id; $p = get_post($pid);
            if (!$p || $p->post_type !== 'tech_news'){ $messages[]='Skipped... invalid post ID '.$pid; continue; }

            $focus = get_post_meta($pid, '_itnf_seo_suggest_focus', true);
            $title = get_post_meta($pid, '_itnf_seo_suggest_title', true);
            $desc  = get_post_meta($pid, '_itnf_seo_suggest_desc',  true);

            if ($focus) update_post_meta($pid, 'rank_math_focus_keyword', $focus);
            if ($title) update_post_meta($pid, 'rank_math_title', $title);
            if ($desc)  update_post_meta($pid, 'rank_math_description', $desc);

            delete_post_meta($pid, '_itnf_seo_suggest_focus');
            delete_post_meta($pid, '_itnf_seo_suggest_title');
            delete_post_meta($pid, '_itnf_seo_suggest_desc');

            // Run programmatic optimizer (URL/title/first para/H2/img alt/internal link)
            ITNF_RankMath_Optimizer::optimize_post($pid, get_post_meta($pid,'_source_url',true));

            $messages[]='Applied & Optimized [ID '.$pid.']';
        }
        wp_send_json_success(['messages'=>$messages]);
    }
}
