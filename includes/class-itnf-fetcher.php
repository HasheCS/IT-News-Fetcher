<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ITNF_Fetcher
 *
 * - Inserts into CPT 'tech_news' (guarded).
 * - Expands source content via ITNF_OpenAI and sets Rank Math fields.
 * - Sets a featured image best-effort: enclosure(s) → og/twitter/link[image_src] → first <img> → fallback.
 * - Logs each step via the existing logger/runner.
 * - Dedupe: meta_key _itnf_hash = md5(GUID|'|'|canon_url(link)).
 */
class ITNF_Fetcher {

    /* ---------------- Utilities ---------------- */

    public static function get_og_image( $url ) {
        return self::og_image_from_page( $url );
    }


    /** Route logs to current run if available, else global logger */
    private static function log($msg){
        $msg = (string)$msg;
        if (!empty($GLOBALS['itnf_current_run_id']) && class_exists('ITNF_Ajax_Fetch') && method_exists('ITNF_Ajax_Fetch','append')) {
            ITNF_Ajax_Fetch::append($GLOBALS['itnf_current_run_id'], $msg);
            return;
        }
        if (function_exists('itnf_logger')) { itnf_logger($msg); return; }
        if (class_exists('ITNF_Logger') && method_exists('ITNF_Logger','append')) { ITNF_Logger::append(null, $msg); }
    }

    /** Options helper */
    private static function opts(){
        $o = get_option('it_news_fetcher_options');
        return is_array($o) ? $o : array();
    }

    /** CPT slug with guard registration for AJAX/CLI paths */
    private static function cpt(){
        $slug = apply_filters('itnf_cpt', 'tech_news');
        if (!post_type_exists($slug)) {
            if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','register_cpt')) {
                ITNF_Helpers::register_cpt(); // attempt once, then re-check
            }
        }
        if (!post_type_exists($slug)) return 'post'; // last resort
        return $slug;
    }

    /** Clamp integer between min and max */
    private static function clamp($v, $min, $max){
        $v = (int)$v; $min = (int)$min; $max = (int)$max;
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }

    /** Canonicalize URL (https scheme, lowercase host, stable query keys only) */
    private static function canon_url($url){
        $u = trim((string)$url);
        if ($u === '') return '';
        $p = @wp_parse_url($u);
        if (!$p || empty($p['host'])) return $u;

        $scheme = 'https';
        $host   = strtolower($p['host']);
        $path   = isset($p['path']) ? $p['path'] : '/';
        if ($path !== '/') $path = rtrim($path, '/');

        // Keep only stable keys so minor tracking changes don't create dupes
        $keep = array('id','p','story','article','utm_id');
        $query = '';
        if (!empty($p['query'])) {
            parse_str($p['query'], $q);
            $q = array_intersect_key($q, array_flip($keep));
            if (!empty($q)) {
                ksort($q);
                $query = http_build_query($q);
            }
        }
        $rebuilt = $scheme.'://'.$host.$path;
        if ($query) $rebuilt .= '?'.$query;
        return $rebuilt;
    }

    /** Item GUID */
    private static function item_guid($item){
        if (is_object($item) && method_exists($item,'get_id')) {
            $id = $item->get_id(true);
            if ($id) return $id;
            if (method_exists($item,'get_link')) {
                $link = $item->get_link();
                return $link ?: '';
            }
        } elseif (is_array($item)) {
            if (!empty($item['guid'])) return (string)$item['guid'];
            if (!empty($item['link'])) return (string)$item['link'];
        }
        return '';
    }

    /** Duplicate hash = md5(GUID + '|' + canon_url(link)) */
    private static function item_hash($item){
        $guid = self::item_guid($item);
        $link = is_object($item) && method_exists($item,'get_link') ? (string)$item->get_link() : (string)($item['link'] ?? '');
        return md5(trim((string)$guid).'|'.self::canon_url($link));
    }

    /** Generate candidate feed variants (delegate to helpers if present) */
    private static function get_variants($url){
        if (function_exists('it_news_fetcher_get_feed_variants')) {
            $v = (array)it_news_fetcher_get_feed_variants($url);
            $v = array_values(array_unique(array_filter(array_map('trim',$v))));
            if ($v) return $v;
        }
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers','feed_variants')) {
            $v = ITNF_Helpers::feed_variants($url);
            if (is_array($v) && $v) return array_values(array_unique(array_filter(array_map('trim',$v))));
        }
        return array($url);
    }

    /** Word count from HTML */
    private static function wc($html){
        $plain = trim(preg_replace('~\s+~u', ' ', wp_strip_all_tags((string)$html)));
        return $plain ? str_word_count($plain) : 0;
    }

    /** Normalize slug to <=75 ASCII chars, de-duplicated hyphens */
    private static function normalize_slug($slug){
        $s = sanitize_title_with_dashes((string)$slug, '', 'save');
        if (strlen($s) > 75) $s = substr($s, 0, 75);
        $s = preg_replace('~-{2,}~', '-', $s);
        return trim($s, '-');
    }

    /* ---------------- Image helpers (improved) ---------------- */

    /** Does URL look like an image (allow querystrings) */
    private static function looks_like_image_url($u){
        return (bool)preg_match('~\.(jpe?g|png|webp)(\?.*)?$~i', (string)$u);
    }

    /** Extract ALL enclosure image URLs from a SimplePie item (by MIME or relaxed extension) */
    private static function extract_enclosure_urls($item){
        $out = array();

        // Prefer full list if available
        if (is_object($item) && method_exists($item,'get_enclosures')) {
            $encs = (array)$item->get_enclosures();
            foreach ($encs as $enc){
                if (!$enc) continue;
                $type = is_callable(array($enc,'get_type')) ? (string)$enc->get_type() : '';
                $link = is_callable(array($enc,'get_link')) ? (string)$enc->get_link() : '';
                if (!$link) continue;

                if ($type && stripos($type, 'image/') === 0) {
                    $out[] = $link;
                } else if (self::looks_like_image_url($link)) {
                    $out[] = $link;
                }
            }
        }

        // Fallback to single enclosure
        if (!$out && is_object($item) && method_exists($item,'get_enclosure')) {
            $enc = $item->get_enclosure();
            if ($enc && is_callable(array($enc,'get_link'))) {
                $u = (string)$enc->get_link();
                $type = is_callable(array($enc,'get_type')) ? (string)$enc->get_type() : '';
                if ($u && ($type && stripos($type,'image/') === 0 || self::looks_like_image_url($u))) {
                    $out[] = $u;
                }
            }
        }

        // Some feeds put image in media:thumbnail as a <link> without extension
        if (!$out && is_object($item) && method_exists($item,'get_item_tags')) {
            $tags = $item->get_item_tags(SIMPLEPIE_NAMESPACE_MEDIARSS, 'thumbnail');
            if (is_array($tags)) {
                foreach ($tags as $t){
                    if (!empty($t['attribs']['']['url'])) {
                        $u = (string)$t['attribs']['']['url'];
                        if ($u) { $out[] = $u; }
                    }
                }
            }
        }

        // Dedup
        $out = array_values(array_unique(array_filter(array_map('trim', $out))));
        return $out;
    }

    private static function first_img_from_html($html){
        if (!$html) return '';
        if (preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) {
            return esc_url_raw($m[1]);
        }
        return '';
    }

    /** Resolve possibly relative URL against base */
    private static function resolve_url($base, $maybe){
        $m = trim((string)$maybe);
        if ($m === '') return '';
        if (parse_url($m, PHP_URL_SCHEME)) return $m;
        $p = wp_parse_url($base);
        if (!$p || empty($p['host'])) return $m;
        $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
        if ($m[0] === '/') {
            return $scheme.'://'.$p['host'].$m;
        }
        return rtrim($base, '/').'/'.ltrim($m, '/');
    }

    /** Parse og/twitter/link images from HTML (expanded set of tags) */
    private static function parse_social_image_from_html($html, $base){
        $cands = array();

        // og:image, og:image:url, og:image:secure_url
        if (preg_match_all('~<meta\s+property=["\']og:image(?::(?:url|secure_url))?["\']\s+content=["\']([^"\']+)["\']~i', $html, $mm)) {
            foreach ($mm[1] as $u) $cands[] = self::resolve_url($base, $u);
        }
        // twitter:image / twitter:image:src
        if (preg_match_all('~<meta\s+name=["\']twitter:image(?::src)?["\']\s+content=["\']([^"\']+)["\']~i', $html, $mm2)) {
            foreach ($mm2[1] as $u) $cands[] = self::resolve_url($base, $u);
        }
        // link rel="image_src"
        if (preg_match_all('~<link\s+rel=["\']image_src["\']\s+href=["\']([^"\']+)["\']~i', $html, $mm3)) {
            foreach ($mm3[1] as $u) $cands[] = self::resolve_url($base, $u);
        }

        // Keep only likely images; allow querystrings
        $cands = array_values(array_unique(array_filter($cands, function($u){
            return self::looks_like_image_url($u) || parse_url($u, PHP_URL_SCHEME); // allow CDN w/o extension too
        })));
        return $cands ? $cands[0] : '';
    }

    /** Fetch a page and try to extract an OG/Twitter image */
    private static function og_image_from_page($url){
        $u = trim((string)$url);
        if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) { return ''; }

        $resp = wp_remote_get($u, array(
            'timeout'     => 20,
            'redirection' => 7,
            'httpversion' => '1.1',
            'headers'     => array(
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari ITNF',
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer'         => $u,
            ),
        ));
        if (is_wp_error($resp)) { self::log('OG page fetch error: '.$resp->get_error_message()); return ''; }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 400) { self::log('OG page HTTP '.$code.' for '.$u); return ''; }

        $body = wp_remote_retrieve_body($resp);
        if (!$body) { self::log('OG page empty body'); return ''; }

        $cand = self::parse_social_image_from_html($body, $u);
        if ($cand) self::log('IMG: candidate(og/twitter/link) '.$cand);
        return $cand ?: '';
    }

    private static function sideload_as_thumbnail($post_id, $img_url){
        if (!function_exists('media_sideload_image')) require_once ABSPATH.'wp-admin/includes/media.php';
        if (!function_exists('download_url'))        require_once ABSPATH.'wp-admin/includes/file.php';
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH.'wp-admin/includes/image.php';

        $att_id = media_sideload_image($img_url, $post_id, null, 'id');
        if (is_wp_error($att_id)) {
            self::log('IMG: sideload error: '.$att_id->get_error_message());
            return 0;
        }
        set_post_thumbnail($post_id, (int)$att_id);
        return (int)$att_id;
    }

    /** Try enclosure(s) → og/twitter/link → first <img> → fallback */
    private static function maybe_set_featured_image($post_id, $source_url, $expanded_html, $enclosure_url = '', $fallback = ''){
        // 0) Collect enclosure candidates (from all enclosures)
        $enc_urls = array();
        if ($enclosure_url) { $enc_urls[] = $enclosure_url; }
        $enc_urls = array_merge($enc_urls, self::extract_enclosure_urls(array('enclosure' => $enclosure_url) + array('item'=>$enclosure_url)));
        // If the above fallback array hack looks odd: extract_enclosure_urls will ignore if not a SimplePie item; we keep original $enclosure_url first anyway.

        // 1) Try each enclosure first
        if (is_array($enc_urls) && $enc_urls){
            foreach (array_values(array_unique($enc_urls)) as $u){
                if (!$u) continue;
                self::log('IMG: candidate(enclosure) '.$u);
                $att = self::sideload_as_thumbnail($post_id, $u);
                if ($att) return $att;
            }
        }

        // 2) og/twitter/link image from source page
        $og = $source_url ? self::og_image_from_page($source_url) : '';
        if ($og){
            $att = self::sideload_as_thumbnail($post_id, $og);
            if ($att) return $att;
        }

        // 3) first <img> from expanded content
        $first = self::first_img_from_html($expanded_html);
        if ($first){
            self::log('IMG: candidate(first <img>) '.$first);
            $att = self::sideload_as_thumbnail($post_id, $first);
            if ($att) return $att;
        }

        // 4) fallback (settings default)
        $fb = trim((string)$fallback);
        if ($fb) {
            self::log('IMG: candidate(fallback) '.$fb);
            $att = self::sideload_as_thumbnail($post_id, $fb);
            if ($att) return $att;
        }
        self::log('IMG: no featured image could be set');
        return 0;
    }

    /* ---------------- Expansion + Insert ---------------- */

    private static function expand_and_insert($title, $source_url, $raw_html_or_text, $enclosure_url, $fallback_img, $auto_rm){
        $opts = self::opts();
        $minw = isset($opts['openai_min_words']) ? max(400, (int)$opts['openai_min_words']) : 1200;
        $maxw = isset($opts['openai_max_words']) ? max($minw, (int)$opts['openai_max_words']) : 1500;

        self::log('AI expand start: "'.$title.'"');
        $expanded_html = ITNF_OpenAI::expand_content($title, $source_url, $raw_html_or_text);
        $w = self::wc($expanded_html);
        self::log('AI expand done: words='.$w.' (target '.$minw.'–'.$maxw.')');

        // SEO draft
        $plain = trim(preg_replace('~\s+~u', ' ', wp_strip_all_tags((string)$expanded_html)));
        $seo = ITNF_OpenAI::generate_seo($title, $plain, $source_url);

        $ai_slug = self::normalize_slug(isset($seo['slug']) ? $seo['slug'] : '');
        if ($ai_slug === '') $ai_slug = self::normalize_slug($title);
        self::log('SEO gen: focus="'.(isset($seo['focus'])?$seo['focus']:'').'", slug="'.$ai_slug.'"');

        // Insert post
        $postarr = array(
            'post_title'   => $title,
            'post_content' => $expanded_html,
            'post_status'  => 'publish',
            'post_type'    => self::cpt(),
        );
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) { throw new \Exception('Insert failed: '.$post_id->get_error_message()); }

        // Slug: store AI suggestion meta and apply unique slug
        update_post_meta($post_id, '_itnf_ai_slug', $ai_slug);
        $unique = wp_unique_post_slug($ai_slug, $post_id, get_post_status($post_id), get_post_type($post_id), 0);
        wp_update_post(array('ID'=>$post_id, 'post_name'=>$unique));
        self::log('Slug applied: '.$unique);

        // Rank Math metas
        if (!empty($seo['focus'])) { update_post_meta($post_id, 'rank_math_focus_keyword', $seo['focus']); self::log('RankMath: focus keyword set'); }
        if (!empty($seo['title'])) { update_post_meta($post_id, 'rank_math_title', $seo['title']); self::log('RankMath: title set'); }
        if (!empty($seo['desc']))  { update_post_meta($post_id, 'rank_math_description', $seo['desc']); self::log('RankMath: description set'); }

        // Featured image (improved)
        self::maybe_set_featured_image($post_id, $source_url, $expanded_html, $enclosure_url, $fallback_img);

        // Rank Math optimizer touch (if enabled)
        if ($auto_rm && class_exists('ITNF_RankMath_Optimizer')) {
            if (method_exists('ITNF_RankMath_Optimizer','touch_post')) {
                ITNF_RankMath_Optimizer::touch_post($post_id, $source_url);
                self::log('RankMath: touch_post() invoked');
            } elseif (method_exists('ITNF_RankMath_Optimizer','optimize_post')) {
                ITNF_RankMath_Optimizer::optimize_post($post_id, $source_url);
                self::log('RankMath: optimize_post() invoked');
            }
        }

        return $post_id;
    }

    /* ---------------- Public: process a single feed ---------------- */

    public static function process_single_feed($feed_url, $per = 3, $fallback = '', $threshold = 800, $auto_rm = false, $run_id = ''){
        $GLOBALS['itnf_current_run_id'] = $run_id;
        $url = trim((string)$feed_url);
        if ($url === '') { self::log('[fetch] empty URL'); return array('inserted'=>0, 'skipped'=>0); }

        self::log('Run started…');
        self::log('Starting single feed: '.$url);

        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        $rss = null; $last_err = '';
        $variants = self::get_variants($url);
        self::log('--- FEED: '.$url.' ---');

        // Try WordPress fetch_feed for each variant
        foreach ($variants as $v){
            $try = fetch_feed($v);
            if (!is_wp_error($try)) {
                self::log('[fetch] using variant via fetch_feed: '.$v);
                $rss = $try; break;
            }
            $last_err = $try->get_error_message();
            self::log('[fetch] fetch_feed failed for '.$v.' | '.$last_err);
        }
        if (!$rss) {
            self::log('[fetch] All variants failed. Aborting.');
            return array('inserted'=>0, 'skipped'=>0, 'error'=>$last_err);
        }

        // Items to process
        $o   = self::opts();
        $per_setting = isset($o['items_per_feed']) ? max(1, min(20, (int)$o['items_per_feed'])) : 3;
        $max   = self::clamp((int)$per ?: $per_setting, 1, 20);
        $total = $rss->get_item_quantity($max);
        $items = $rss->get_items(0, $total);
        self::log('Parsed items='.$total);

        $inserted = 0; $skipped = 0;

        foreach ($items as $idx => $item){
            // stop signal?
            $state_stop = defined('ITNF_STOP_NOW') && ITNF_STOP_NOW;
            if ($state_stop) { self::log('Stop signal received, exiting loop.'); break; }

            $title = (string)$item->get_title();
            $link  = (string)$item->get_link();
            $guid  = self::item_guid($item);
            $hash  = self::item_hash($item);

            // Prefer enclosure discovery via improved helper
            $enclosure_url = '';
            $encs = self::extract_enclosure_urls($item);
            if ($encs) { $enclosure_url = $encs[0]; }

            self::log('Item '.($idx+1).'/'.$total.' — "'.$title.'"');

            // Duplicate check (EXACT same key as AJAX check_feed)
            $existing = get_posts(array(
                'post_type'      => self::cpt(),
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_itnf_hash',
                'meta_value'     => $hash,
                'no_found_rows'  => true,
            ));
            if (!empty($existing)) {
                $skipped++; self::log('Duplicate (hash match), skipping.'); continue;
            }

            // Pull raw content (summary/content)
            $raw = '';
            if (method_exists($item,'get_content')) $raw = (string)$item->get_content();
            if (!$raw && method_exists($item,'get_description')) $raw = (string)$item->get_description();
            if (!$raw) $raw = $title.' — '.$link;

            try {
                // Insert, expand, SEO, image, rankmath
                $post_id = self::expand_and_insert($title, $link, $raw, $enclosure_url, $fallback, $auto_rm);

                // provenance + hash for future dedupe
                update_post_meta($post_id, '_itnf_guid', $guid);
                update_post_meta($post_id, '_itnf_hash', $hash);
                update_post_meta($post_id, '_itnf_source_url', $link);

                $inserted++;
            } catch (\Exception $e){
                self::log('Insert failed: '.$e->getMessage());
                $skipped++;
            }

            if ($inserted >= $max) break;
        }

        self::log('Run complete. Inserted='.$inserted.' Skipped='.$skipped);
        return array('inserted'=>$inserted,'skipped'=>$skipped);
    }
}
