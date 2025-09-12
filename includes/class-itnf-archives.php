<?php
if (!defined('ABSPATH')) { exit; }

/**
 * IT News Fetcher... tech_news archives utilities
 * - Pretty date archives for CPT under /tech-news/YYYY/MM/
 * - Query scoping so date archives only show tech_news when requested
 * - Shortcode that renders markup matching your widget classes and IDs
 * - Optional widget for Appearance...Widgets
 */
class ITNF_Archives {

    public static function init(){
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_action('pre_get_posts', [__CLASS__, 'scope_date_archives']);
        add_shortcode('tech_news_archives', [__CLASS__, 'archives_dropdown']);
        add_action('widgets_init', function(){ register_widget('ITNF_Tech_News_Archives_Widget'); });
    }

    /**
     * Add pretty permalinks for CPT date archives
     * Examples... /tech-news/2025/08/ and /tech-news/2025/
     */
    public static function add_rewrite_rules(){
        add_rewrite_rule(
            '^tech-news/([0-9]{4})/([0-9]{1,2})/?$',
            'index.php?post_type=tech_news&year=$1&monthnum=$2',
            'top'
        );
        add_rewrite_rule(
            '^tech-news/([0-9]{4})/?$',
            'index.php?post_type=tech_news&year=$1',
            'top'
        );
    }

    /**
     * Keep main query scoped to tech_news on CPT date archive URLs
     */
    public static function scope_date_archives($q){
        if (is_admin() || !$q->is_main_query()) return;

        // If explicitly asking for tech_news on a date archive... lock to tech_news
        if ($q->get('post_type') === 'tech_news' && ($q->is_date() || $q->get('m') || $q->get('year'))) {
            $q->set('post_type', 'tech_news');
            return;
        }

        // If post_type not set and URL path contains /tech-news/... assume CPT date archive
        if (($q->is_date() || $q->get('m') || $q->get('year')) && empty($q->get('post_type'))) {
            $req = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
            if ($req && strpos($req, '/tech-news/') !== false) {
                $q->set('post_type', 'tech_news');
            }
        }
    }

    /**
     * Shortcode that renders markup matching your styled widget
     * Usage... [tech_news_archives id="archives-2" title="Archives" name="archive-dropdown"]
     */
    public static function archives_dropdown($atts = []){
        global $wpdb;

        $atts = shortcode_atts([
            'id'    => 'archives-2',        // section id you CSSed
            'title' => 'Archives',          // heading
            'name'  => 'archive-dropdown',  // select name
        ], $atts, 'tech_news_archives');

        // Derive select id like archives-dropdown-2 from section id
        $select_id = 'archives-dropdown-2';
        if (preg_match('/-(\d+)$/', $atts['id'], $m)) {
            $select_id = 'archives-dropdown-' . $m[1];
        } else {
            $rand = wp_rand(100, 9999);
            $atts['id'] = 'archives-' . $rand;
            $select_id  = 'archives-dropdown-' . $rand;
        }

        // Fetch distinct months for tech_news only
        $rows = $wpdb->get_results("
            SELECT DISTINCT YEAR(post_date) AS y, MONTH(post_date) AS m
            FROM {$wpdb->posts}
            WHERE post_type='tech_news' AND post_status='publish'
            ORDER BY post_date DESC
        ");

        // Build options
        $options = '<option value="">Select Month</option>';
        if (!empty($rows)) {
            foreach ($rows as $r) {
                $year  = (int) $r->y;
                $month = (int) $r->m;
                $label = date_i18n('F Y', mktime(0,0,0,$month,1,$year));

                // Pretty CPT URL... fallback to query args if permalinks are plain
                $pretty   = home_url(trailingslashit('tech-news/' . $year . '/' . str_pad((string)$month, 2, '0', STR_PAD_LEFT)));
                $fallback = add_query_arg(['post_type'=>'tech_news','year'=>$year,'monthnum'=>$month], home_url('/'));
                $link     = get_option('permalink_structure') ? $pretty : $fallback;

                $options .= '<option value="'.esc_url($link).'"> '.esc_html($label).' </option>';
            }
        }

        // Final HTML... mirrors your widget structure and classes
        $html  = '<section id="'.esc_attr($atts['id']).'" class="widget widget_archive">';
        $html .= '<h2 class="widget-title">'.esc_html($atts['title']).'</h2>';
        $html .= '<label class="screen-reader-text" for="'.esc_attr($select_id).'">'.esc_html($atts['title']).'</label>';
        $html .= '<select id="'.esc_attr($select_id).'" name="'.esc_attr($atts['name']).'">';
        $html .= $options;
        $html .= '</select>';
        $html .= '<script type="text/javascript">(function(){var d=document.getElementById("'.esc_js($select_id).'");if(!d)return;d.onchange=function(){var v=this.options[this.selectedIndex].value;if(v!==""){window.location.href=v;}}})();</script>';
        $html .= '</section>';

        return $html;
    }
}

/**
 * Simple widget wrapper so you can add it from Appearance...Widgets
 */
class ITNF_Tech_News_Archives_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'itnf_tech_news_archives',
            'Tech News Archives (CPT)',
            ['description' => 'Monthly archives for tech_news only']
        );
    }

    public function widget($args, $instance) {
        echo isset($args['before_widget']) ? $args['before_widget'] : '';
        if (!empty($instance['title'])) {
            $before = isset($args['before_title']) ? $args['before_title'] : '<h3>';
            $after  = isset($args['after_title'])  ? $args['after_title']  : '</h3>';
            echo $before . apply_filters('widget_title', $instance['title']) . $after;
        }
        echo ITNF_Archives::archives_dropdown();
        echo isset($args['after_widget']) ? $args['after_widget'] : '';
    }

    public function form($instance) {
        $title = isset($instance['title']) ? $instance['title'] : 'Archives';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title</label>
            <input class="widefat"
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   type="text"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }
}

// Boot
ITNF_Archives::init();
