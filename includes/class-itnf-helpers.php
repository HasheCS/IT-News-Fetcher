<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_Helpers {

    public static function register_cpt(){
        $labels = [
            'name' => __('Tech News','it-news-fetcher'),
            'singular_name' => __('Tech News','it-news-fetcher'),
            'menu_name' => __('Tech News','it-news-fetcher'),
            'add_new' => __('Add New','it-news-fetcher'),
            'add_new_item' => __('Add New Tech News','it-news-fetcher'),
            'edit_item' => __('Edit Tech News','it-news-fetcher'),
            'new_item' => __('New Tech News','it-news-fetcher'),
            'view_item' => __('View Tech News','it-news-fetcher'),
            'search_items' => __('Search Tech News','it-news-fetcher'),
            'not_found' => __('No tech news found.','it-news-fetcher'),
            'not_found_in_trash' => __('No tech news found in Trash.','it-news-fetcher'),
        ];
        register_post_type('tech_news', [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'has_archive'         => 'tech-news',
            'rewrite'             => ['slug' => 'tech-news', 'with_front' => false],
            'show_in_rest'        => true,
            'supports'            => [
                'title',
                'editor',
                'author',
                'thumbnail',
                'excerpt',
                'comments',
                'custom-fields',
                'revisions',
            ],
        ]);

    }

    public static function parse_feeds_option(){
        $o = get_option('it_news_fetcher_options');
        $raw = isset($o['feed_urls']) ? $o['feed_urls'] : '';
        $raw = str_replace(["\r\n","\r"], "\n", $raw);
        $parts = array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)));
        return array_values(array_unique($parts));
    }

    public static function canon_url($url){
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) return $url;
        $q = [];
        if (!empty($parts['query'])) parse_str($parts['query'], $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_|fbclid|gclid|_hs)/i', $k)) unset($q[$k]);
        }
        $rebuilt = $parts['scheme'].'://'.$parts['host'].(isset($parts['path'])?$parts['path']:'');
        if (!empty($q)) $rebuilt .= '?'.http_build_query($q);
        return rtrim($rebuilt, '&?');
    }

    public static function item_hash($guid, $link){ return md5(trim((string)$guid).'|'.self::canon_url((string)$link)); }

    public static function feed_variants($url){
        $variants = [$url];
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return array_unique($variants);
        switch (str_ireplace('www.','',$host)) {
            case 'howtogeek.com':       $variants[]='https://www.howtogeek.com/feed/rss'; break;
            case 'makeuseof.com':       $variants[]='https://feeds.feedburner.com/makeuseof'; break;
            case 'xda-developers.com':  $variants[]='https://www.xda-developers.com/feed/rss'; break;
            case 'androidpolice.com':   $variants[]='https://feeds.feedburner.com/androidpolice'; break;
            case 'bleepingcomputer.com':$variants[]='https://feeds.feedburner.com/BleepingComputer'; break;
        }
        return array_unique($variants);
    }

    public static function pick_image_from_item($item, $content_html, $link_url){
        if (method_exists($item, 'get_enclosure')) {
            $enc = $item->get_enclosure();
            if ($enc && $enc->get_link()) return $enc->get_link();
        }
        if (method_exists($item, 'get_item_tags')) {
            $m = $item->get_item_tags(SIMPLEPIE_NAMESPACE_MEDIA, 'content');
            if (!empty($m[0]['attribs']['']['url'])) return $m[0]['attribs']['']['url'];
        }
        if ($content_html && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content_html, $m2)) {
            return $m2[1];
        }
        if ($link_url) {
            $r = wp_remote_get($link_url, ['timeout'=>10]);
            if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
                $body = wp_remote_retrieve_body($r);
                if ($body && preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $mm)) {
                    return $mm[1];
                }
            }
        }
        return '';
    }

    public static function attach_featured_image($url, $post_id){
        $url = esc_url_raw($url);
        if (!$url) return false;
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $tmp = download_url($url, 20);
        if (is_wp_error($tmp)) return false;
        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg');
        $file_array = ['name'=>$name,'tmp_name'=>$tmp];
        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) { @unlink($tmp); return false; }
        set_post_thumbnail($post_id, $id);
        return $id;
    }

    public static function generate_tags($text, $max=10){
        $o = get_option('it_news_fetcher_options');
        if (empty($o['auto_tags'])) return [];
        $text = strtolower(wp_strip_all_tags($text));
        $text = preg_replace('/[^a-z0-9\s\-\+\.\#]/',' ', $text);
        $words = str_word_count($text, 1);
        $stop = ['the','and','for','that','with','this','from','have','has','had','are','was','were','will','your','you','but','not','they','their','them','its','our','out','can','into','over','than','then','also','about','more','only','such','when','what','which','while','where','there','here','been','being','after','before','because','around','between','among','using','based','into','onto','upon','via','per','each','other','some','most','many','much','very','make','made','like'];
        $freq = [];
        foreach ($words as $w) {
            if (strlen($w) < 4) continue;
            if (in_array($w, $stop, true)) continue;
            $freq[$w] = isset($freq[$w]) ? $freq[$w]+1 : 1;
        }
        arsort($freq);
        return array_slice(array_keys($freq), 0, $max);
    }
}
