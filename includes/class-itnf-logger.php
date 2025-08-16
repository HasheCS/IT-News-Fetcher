<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ITNF_Logger
 * Stores live run logs in wp_options (DB) so they’re visible across PHP workers.
 * Also supports a "stop" signal you can trigger from the UI.
 */
class ITNF_Logger {

    public static function key($run_id){ return 'itnf_log_' . sanitize_key($run_id); }
    public static function stop_key($run_id){ return 'itnf_stop_' . sanitize_key($run_id); }

    public static function init($run_id){
        $data = array(
            'lines'   => array('['. current_time('H:i:s') .'] Run started…'),
            'done'    => false,
            'started' => time(),
        );
        update_option(self::key($run_id), $data, false);
    }

    public static function append($run_id, $line){
        $key  = self::key($run_id);
        $data = get_option($key, array('lines'=>array(), 'done'=>false, 'started'=>time()));
        $data['lines'][] = '['. current_time('H:i:s') .'] ' . wp_strip_all_tags($line);
        update_option($key, $data, false);
    }

    public static function finish($run_id){
        $key  = self::key($run_id);
        $data = get_option($key, array('lines'=>array(), 'done'=>false, 'started'=>time()));
        $data['done'] = true;
        update_option($key, $data, false);
    }

    public static function read_slice($run_id, $cursor){
        $key   = self::key($run_id);
        $data  = get_option($key, null);
        if (!$data) return array(array(), (int)$cursor, true);
        $lines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : array();
        $slice = array_slice($lines, (int)$cursor);
        $newc  = count($lines);
        $done  = !empty($data['done']);
        return array($slice, $newc, $done);
    }

    public static function request_stop($run_id){
        update_option(self::stop_key($run_id), 1, false);
    }
    public static function clear_stop($run_id){
        delete_option(self::stop_key($run_id));
    }
    public static function should_stop($run_id){
        return (bool) get_option(self::stop_key($run_id), 0);
    }
}

/** Back-compat global helpers (existing code calls these) */
$GLOBALS['itnf_logger'] = null;

function itnf_set_logger($callable){ $GLOBALS['itnf_logger'] = $callable; }
function itnf_log_if($msg){
    if (is_callable($GLOBALS['itnf_logger'])) {
        try { call_user_func($GLOBALS['itnf_logger'], $msg); } catch (\Throwable $e) {}
    }
}
