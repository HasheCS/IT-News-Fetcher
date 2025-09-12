<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ITNF_Ajax_Fetch
 *
 * Admin-ajax controller and async runner for the Fetch tab:
 *  - itnf_list_feeds      : returns configured feed URLs
 *  - itnf_check_feed      : quick "new items" probe for a feed (mirrors fetcher dedupe)
 *  - itnf_fetch_one       : schedules/starts processing for a single feed and returns run_id immediately
 *  - itnf_fetch_selected  : kicks off one worker per feed (concurrent) and returns run_ids
 *  - itnf_poll_log        : poll run log lines since cursor
 *  - itnf_stop_run        : request stop (honored by runner between items)
 *
 * Notes:
 *  - All endpoints require manage_options + valid nonce ('itnf_admin', field '_wpnonce')
 *  - All endpoints return wp_send_json_{success,error}()
 */

if (!class_exists('ITNF_Ajax_Fetch')):

class ITNF_Ajax_Fetch {

    /** Transient TTL for an active run (seconds) */
    const RUN_TTL  = 2 * HOUR_IN_SECONDS;

    /** Transient TTL for a one-shot start token */
    const KICK_TTL = 5 * MINUTE_IN_SECONDS;

    /* -------------------- Boot -------------------- */

    public static function register(){
        add_action('wp_ajax_itnf_list_feeds',      array(__CLASS__, 'list_feeds'));
        add_action('wp_ajax_itnf_check_feed',      array(__CLASS__, 'check_feed'));
        add_action('wp_ajax_itnf_fetch_one',       array(__CLASS__, 'fetch_one'));
        add_action('wp_ajax_itnf_fetch_selected',  array(__CLASS__, 'fetch_selected'));
        add_action('wp_ajax_itnf_poll_log',        array(__CLASS__, 'poll_log'));
        add_action('wp_ajax_itnf_stop_run',        array(__CLASS__, 'stop_run'));

        // Fallback runner signature kept for compatibility with scheduled single events.
        add_action('itnf_process_feed_async',      array(__CLASS__, 'process_async'), 10, 6);

        // Self-kick runner (works even if cron is delayed)
        add_action('init',                         array(__CLASS__, 'maybe_kickoff'));
    }

    /* -------------------- Options / Helpers -------------------- */

    private static function opts(){
        $o = get_option('it_news_fetcher_options');
        return is_array($o) ? $o : array();
    }

    private static function get($key, $default = ''){
        $o = self::opts();
        return isset($o[$key]) ? $o[$key] : $default;
    }

    private static function bool($v){ return !empty($v) && $v !== '0' && $v !== 0 && $v !== false; }

    /** Return CPT slug (falls back to 'post' if CPT missing) */
    private static function cpt(){
        $slug = apply_filters('itnf_cpt', 'tech_news');
        if (!post_type_exists($slug)) $slug = 'post';
        return $slug;
    }

    /** Atomic run lock (one worker per run_id) */
    private static function lock_key($run_id){ return 'itnf_lock_'.$run_id; }

    private static function acquire_lock($run_id){
        // add_option is atomic at DB level; returns false if option already exists
        return add_option(self::lock_key($run_id), time(), '', false);
    }

    private static function release_lock($run_id){
        delete_option(self::lock_key($run_id));
    }

    /** Returns unique list of feed URLs from settings (or helper if present) */
    private static function feeds_from_settings(){
        if (class_exists('ITNF_Helpers') && method_exists('ITNF_Helpers', 'parse_feeds_option')) {
            $feeds = ITNF_Helpers::parse_feeds_option();
            if (is_array($feeds) && $feeds) {
                $out = array();
                foreach ($feeds as $f){
                    if (is_string($f) && $f !== '') $out[] = trim($f);
                    elseif (is_array($f) && !empty($f['url'])) $out[] = trim((string)$f['url']);
                }
                return array_values(array_unique(array_filter($out)));
            }
        }
        $o   = self::opts();
        $txt = isset($o['feeds']) ? (string)$o['feeds'] : '';
        $lines = preg_split('~\r\n|\n|\r~', $txt);
        $lines = array_values(array_filter(array_map('trim', $lines)));
        return array_values(array_unique($lines));
    }

    /**
     * Canonicalize URL for hashing.
     * Mirrors ITNF_Fetcher::canon_url (keep-only selected query keys).
     */
    private static function canon_url($url){
        $u = trim((string)$url);
        if ($u === '') return '';
        $p = @wp_parse_url($u);
        if (!$p || empty($p['host'])) return $u;
        $scheme = 'https';
        $host   = strtolower($p['host']);
        $path   = isset($p['path']) ? $p['path'] : '/';
        if ($path !== '/') $path = rtrim($path, '/');

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

    /** GUID */
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

    /** Duplicate hash (GUID + canon link) */
    private static function item_hash($item){
        $guid = self::item_guid($item);
        $link = is_object($item) && method_exists($item,'get_link') ? (string)$item->get_link() : (string)($item['link'] ?? '');
        return md5(trim((string)$guid).'|'.self::canon_url($link));
    }

    private static function tkey($run_id){ return 'itnf_run_'.$run_id; }

    private static function new_run_state(){
        return array(
            'status' => 'Queued…',
            'cursor' => 0,
            'lines'  => array(),
            'stop'   => false,
        );
    }

    private static function create_run(){
        $run_id = uniqid('itnf_', true);
        set_transient(self::tkey($run_id), self::new_run_state(), self::RUN_TTL);
        return $run_id;
    }

    private static function get_run($run_id){
        $s = get_transient(self::tkey($run_id));
        return is_array($s) ? $s : self::new_run_state();
    }

    private static function save_run($run_id, $state){
        set_transient(self::tkey($run_id), $state, self::RUN_TTL);
    }

    /** PUBLIC so fetcher can stream live lines during runs */
    public static function append($run_id, $line){
        $state = self::get_run($run_id);
        $idx   = (int)$state['cursor'];
        $state['lines'][] = array(
            'key'  => $idx,
            'line' => '['.current_time('H:i:s').'] '.(string)$line
        );
        $state['cursor']  = $idx + 1;
        self::save_run($run_id, $state);
    }

    private static function set_status($run_id, $status){
        $state = self::get_run($run_id);
        $state['status'] = (string)$status;
        self::save_run($run_id, $state);
    }

    private static function finish($run_id, $status = 'Done.'){
        $state = self::get_run($run_id);
        $state['status'] = (string)$status;
        self::save_run($run_id, $state);
    }

    /* -------------------- AJAX: Feeds List -------------------- */

    public static function list_feeds(){
        check_ajax_referer('itnf_admin', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
        $feeds = self::feeds_from_settings();
        wp_send_json_success(array('feeds' => $feeds));
    }

    /* -------------------- AJAX: Quick Check -------------------- */

    public static function check_feed(){
        check_ajax_referer('itnf_admin', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $url = esc_url_raw($_POST['url'] ?? '');
        if (!$url) wp_send_json_error('no_url');

        if (!function_exists('fetch_feed')) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        $o   = self::opts();
        $per = isset($o['items_per_feed']) ? max(1, min(20, (int)$o['items_per_feed'])) : 3;

        $variants = function_exists('it_news_fetcher_get_feed_variants')
            ? (array)it_news_fetcher_get_feed_variants($url)
            : array($url);

        $new_count = 0;

        foreach ($variants as $v){
            $rss = fetch_feed($v);
            if (is_wp_error($rss)) { continue; }

            $max   = min($rss->get_item_quantity(), $per);
            $items = $rss->get_items(0, $max);

            foreach ($items as $it){
                $hash = self::item_hash($it);
                if (!$hash) { continue; }

                $dupe = get_posts(array(
                    'post_type'      => self::cpt(),
                    'post_status'    => 'any',
                    'meta_key'       => '_itnf_hash',
                    'meta_value'     => $hash,
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                ));

                if (empty($dupe)) { $new_count++; }
            }

            if ($new_count > 0) break;
        }

        wp_send_json_success(array('new_count' => $new_count));
    }

    /* -------------------- AJAX: Single Feed (Immediate self-kick) -------------------- */

    public static function fetch_one(){
        check_ajax_referer('itnf_admin', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $url = isset($_POST['url']) ? trim((string)$_POST['url']) : '';
        if ($url === '') wp_send_json_error('no_url');

        $run_id = self::create_run();
        self::append($run_id, 'Run started…');
        self::set_status($run_id, 'Queued…');

        // Gather options for the runner
        $o         = self::opts();
        $per       = max(1, (int)($o['items_per_feed'] ?? 5));
        $fallback  = isset($o['default_thumb']) ? trim((string)$o['default_thumb']) : '';
        $threshold = 0; // signature compatibility
        $auto_rm   = !empty($o['auto_rankmath']);

        // Create a one-shot token & stash params in a transient
        $token = wp_generate_password(20, false, false);
        set_transient(
            'itnf_kick_'.$run_id,
            array('url'=>$url, 'per'=>$per, 'fallback'=>$fallback, 'threshold'=>$threshold, 'auto_rm'=>$auto_rm, 'token'=>$token),
            self::KICK_TTL
        );

        // Loopback GET to start immediately (tiny timeout, non-blocking)
        $kick_url = add_query_arg(array(
            'itnf_run_now' => 1,
            'rid'          => rawurlencode($run_id),
            't'            => rawurlencode($token),
        ), home_url('/'));
        @wp_remote_get($kick_url, array('timeout'=>0.01, 'blocking'=>false, 'sslverify'=>false));

        // Also schedule cron as a redundant fallback (harmless if it runs later)
        if ( ! wp_next_scheduled('itnf_process_feed_async', array($url, $per, $fallback, $threshold, $auto_rm, $run_id)) ) {
            wp_schedule_single_event( time() + 60, 'itnf_process_feed_async', array($url, $per, $fallback, $threshold, $auto_rm, $run_id) );
        }

        wp_send_json_success(array('run_id' => $run_id));
    }

    /**
     * init-hooked entrypoint to start a run immediately from our non-blocking GET.
     * Validates a transient-backed token, then calls process_async().
     * Guarded by an atomic run lock: only one worker per run_id.
     */
    public static function maybe_kickoff(){
        if (empty($_GET['itnf_run_now']) || empty($_GET['rid']) || empty($_GET['t'])) return;

        $run_id = isset($_GET['rid']) ? trim((string)$_GET['rid']) : '';
        $token  = isset($_GET['t'])   ? trim((string)$_GET['t'])   : '';

        $stash = get_transient('itnf_kick_'.$run_id);
        if (!is_array($stash) || empty($stash['token']) || !hash_equals($stash['token'], $token)) {
            return;
        }
        // Acquire lock; if another worker already holds it, exit silently.
        if (!self::acquire_lock($run_id)) {
            return;
        }
        delete_transient('itnf_kick_'.$run_id); // consume

        $url       = (string)$stash['url'];
        $per       = (int)$stash['per'];
        $fallback  = (string)$stash['fallback'];
        $threshold = (int)$stash['threshold'];
        $auto_rm   = !empty($stash['auto_rm']);

        self::set_status($run_id, 'Processing…');
        self::append($run_id, 'Loopback kickoff accepted.');
        try {
            self::process_async($url, $per, $fallback, $threshold, $auto_rm, $run_id);
        } finally {
            self::release_lock($run_id);
        }
        // silent end
    }

    /**
     * Cron/loopback runner — single feed processing with step-by-step logs.
     * Signature matches the wp_schedule_single_event() we set as fallback.
     * Guarded by the same atomic run lock.
     */
    public static function process_async($url, $per, $fallback, $threshold, $auto_rm, $run_id){
        if (!self::acquire_lock($run_id)) {
            // Someone else is already running this run_id
            self::append($run_id, 'Another worker already active; exiting.');
            return;
        }
        try {
            // If run state was GC'd, re-create a placeholder to avoid UI confusion
            $state = self::get_run($run_id);
            if (empty($state['lines'])) {
                $state = self::new_run_state();
                self::save_run($run_id, $state);
                self::append($run_id, 'Cron fallback picked up run.');
            }

            self::set_status($run_id, 'Processing…');
            self::append($run_id, 'Starting single feed: '.$url);

            try {
                if (class_exists('ITNF_Fetcher')) {
                    ITNF_Fetcher::process_single_feed($url, (int)$per, (string)$fallback, (int)$threshold, !empty($auto_rm), $run_id);
                } else {
                    self::append($run_id, 'Fatal: ITNF_Fetcher class not found.');
                }
            } catch (\Throwable $e){
                self::append($run_id, 'Fatal error: '.$e->getMessage());
            }

            self::finish($run_id, 'Done.');
        } finally {
            self::release_lock($run_id);
        }
    }

    /* -------------------- AJAX: Selected Feeds (one worker per feed) -------------------- */

    public static function fetch_selected(){
        check_ajax_referer('itnf_admin', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $urls = isset($_POST['urls']) && is_array($_POST['urls'])
              ? array_values(array_filter(array_map('esc_url_raw', array_map('wp_unslash', $_POST['urls']))))
              : array();
        if (!$urls) wp_send_json_error('no_urls');

        // Read global options once; each child run persists its own snapshot
        $o         = self::opts();
        $per       = max(1, (int)($o['items_per_feed'] ?? 5));
        $fallback  = isset($o['default_thumb']) ? trim((string)$o['default_thumb']) : '';
        $threshold = 0;
        $auto_rm   = !empty($o['auto_rankmath']);

        $run_ids = array();

        foreach ($urls as $u){
            $run_id = self::create_run();
            self::append($run_id, 'Run started…');
            self::set_status($run_id, 'Queued…');

            $token = wp_generate_password(20, false, false);
            set_transient(
                'itnf_kick_'.$run_id,
                array('url'=>$u, 'per'=>$per, 'fallback'=>$fallback, 'threshold'=>$threshold, 'auto_rm'=>$auto_rm, 'token'=>$token),
                self::KICK_TTL
            );

            // Loopback (fast)
            $kick_url = add_query_arg(array(
                'itnf_run_now' => 1,
                'rid'          => rawurlencode($run_id),
                't'            => rawurlencode($token),
            ), home_url('/'));
            @wp_remote_get($kick_url, array('timeout'=>0.01, 'blocking'=>false, 'sslverify'=>false));

            // Cron fallback (later)
            if ( ! wp_next_scheduled('itnf_process_feed_async', array($u, $per, $fallback, $threshold, $auto_rm, $run_id)) ) {
                wp_schedule_single_event( time() + 60, 'itnf_process_feed_async', array($u, $per, $fallback, $threshold, $auto_rm, $run_id) );
            }

            $run_ids[] = $run_id;
        }

        // Back-compat: also provide a scalar 'run_id' = first child, so existing UIs continue to poll at least one.
        wp_send_json_success(array('run_ids' => $run_ids, 'run_id' => (string)($run_ids[0] ?? '')));
    }

    /* -------------------- AJAX: Poll / Stop -------------------- */

    public static function poll_log(){
        check_ajax_referer('itnf_admin', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        // Support both GET and POST to be flexible
        $run_id = isset($_REQUEST['run_id']) ? trim((string)$_REQUEST['run_id']) : '';
        $cursor = isset($_REQUEST['cursor']) ? (int)$_REQUEST['cursor'] : 0;
        if ($run_id === '') wp_send_json_error('no_run');

        $state = self::get_run($run_id);
        $lines = array();
        $c     = (int)$state['cursor'];

        for ($i = $cursor; $i < $c; $i++){
            $row = isset($state['lines'][$i]) ? $state['lines'][$i] : null;
            if ($row) $lines[] = $row;
        }

        wp_send_json_success(array(
            'lines'  => $lines,
            'cursor' => $c,
            'done'   => !empty($state['done']),
            'status' => (string)$state['status'],
        ));
    }

    public static function stop_run(){
        check_ajax_referer('itnf_admin', '_wpnonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

        $run_id = isset($_POST['run_id']) ? trim((string)$_POST['run_id']) : '';
        if ($run_id === '') wp_send_json_error('no_run');

        $state = self::get_run($run_id);
        $state['stop']   = true;
        $state['status'] = 'Stopping…';
        self::save_run($run_id, $state);
        self::append($run_id, 'Stop requested by user.');

        wp_send_json_success(array('ok' => 1));
    }
}

ITNF_Ajax_Fetch::register();

endif;
