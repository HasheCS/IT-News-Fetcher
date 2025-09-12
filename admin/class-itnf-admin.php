<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_Admin {
    public static function register_menu(){
        add_menu_page(
            __('IT News Fetcher','it-news-fetcher'),
            __('IT News Fetcher','it-news-fetcher'),
            'manage_options',
            'it-news-fetcher',
            [__CLASS__,'render_page'],
            'dashicons-rss',
            30
        );
    }

    public static function enqueue_assets($hook){
        if (strpos($hook, 'it-news-fetcher') === false) return;
        wp_enqueue_style('itnf-admin', ITNF_PLUGIN_URL.'assets/css/itnf-admin.css', [], ITNF_VERSION);
        wp_enqueue_script('itnf-admin-fetch', ITNF_PLUGIN_URL.'assets/js/itnf-admin-fetch.js', ['jquery'], ITNF_VERSION, true);
        wp_enqueue_script('itnf-admin-bulk',plugins_url('assets/js/itnf-admin-bulk.js', ITNF_PLUGIN_FILE),['jquery','underscore'],ITNF_VERSION,true);
        wp_enqueue_script('itnf-admin-seo',   ITNF_PLUGIN_URL.'assets/js/itnf-admin-seo.js',   ['jquery'], ITNF_VERSION, true);
        wp_localize_script('itnf-admin-fetch','itnf_ajax', [
            'ajax_url'=> admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('itnf_admin')
        ]);
        wp_localize_script('itnf-admin-bulk','itnf_ajax', [
            'ajax_url'=> admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('itnf_admin')
        ]);
        wp_localize_script('itnf-admin-seo','itnf_ajax', [
            'ajax_url'=> admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('itnf_admin')
        ]);
 
        add_action('add_meta_boxes', function(){
            add_meta_box('itnf-slug-box','ITNF Slug Control', function($post){
                if ($post->post_type !== 'tech_news') return;
                $val = get_post_meta($post->ID, '_itnf_slug_never_touch', true) ? 1 : 0;
                echo '<label><input type="checkbox" name="itnf_slug_never_touch" value="1" '.checked($val,1,false).'> Never touch this slug</label>';
                wp_nonce_field('itnf_slug_box','itnf_slug_box_nonce');
            }, 'tech_news', 'side', 'default');
        });
        
        add_action('save_post_tech_news', function($post_id){
            if (!isset($_POST['itnf_slug_box_nonce']) || !wp_verify_nonce($_POST['itnf_slug_box_nonce'],'itnf_slug_box')) return;
            if (!current_user_can('edit_post',$post_id)) return;
            $v = isset($_POST['itnf_slug_never_touch']) ? 1 : 0;
            if ($v) update_post_meta($post_id,'_itnf_slug_never_touch',1);
            else delete_post_meta($post_id,'_itnf_slug_never_touch');
        });
 
 
 
    }

    public static function render_page(){
        $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'fetch';
        $tabs = [
            'fetch'    => __('Run Fetch','it-news-fetcher'),
            'bulk'     => __('Bulk Rewrite','it-news-fetcher'),
            'seo'      => __('SEO (Rank Math)','it-news-fetcher'),
            'settings' => __('Settings','it-news-fetcher'),
        ];
        echo '<div class="wrap"><h1>'.esc_html__('IT News Fetcher','it-news-fetcher').'</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $slug=>$label){
            $cls = ($slug===$active)?' nav-tab-active':'';
            echo '<a class="nav-tab'.$cls.'" href="'.esc_url(admin_url('admin.php?page=it-news-fetcher&tab='.$slug)).'">'.esc_html($label).'</a>';
        }
        echo '</h2>';
        echo '<div class="itnf-tab-wrap">';
        switch ($active){
            case 'fetch':    include ITNF_PLUGIN_DIR.'admin/views/tab-fetch.php'; break;
            case 'bulk':     include ITNF_PLUGIN_DIR.'admin/views/tab-bulk.php'; break;
            case 'seo':      include ITNF_PLUGIN_DIR.'admin/views/tab-seo.php'; break;
            default:         include ITNF_PLUGIN_DIR.'admin/views/tab-settings.php'; break;
        }
        echo '</div></div>';
    }
}
