<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_OpenAI {

    public static function normalize_focus($text){
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $repl = array("\xE2\x80\x98"=>"'","\xE2\x80\x99"=>"'", "\xE2\x80\x9C"=>'"',"\xE2\x80\x9D"=>'"', "\xE2\x80\x93"=>'-',"\xE2\x80\x94"=>'-');
        $text = strtr($text, $repl);
        $text = mb_strtolower($text);
        $text = str_replace(array('&amp;','&'), ' and ', $text);
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $parts = explode(' ', $text);
        $parts = array_slice($parts, 0, 7);
        return trim(implode(' ', $parts));
    }

    private static function smart_truncate($text, $limit){
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        if (mb_strlen($text) <= $limit) return $text;
        $cut = mb_substr($text, 0, $limit);
        $sp = mb_strrpos($cut, ' ');
        return ($sp !== false) ? mb_substr($cut, 0, $sp) : $cut;
    }

    private static function build_instructions($source_url){
        $o = get_option('it_news_fetcher_options');
        $minw = max(300, (int)($o['openai_target_words_min'] ?? 1200));
        $maxw = max($minw+50, (int)($o['openai_target_words_max'] ?? 1500));
        $tmpl = "You are a senior tech news editor. Rewrite the provided material into a clear %d-%d word HTML article for a WordPress site.\n\nReturn ONLY valid HTML... no markdown... no backticks... no surrounding explanations.\n\nStructure requirements...\n- The first line must be a single-sentence summary at the very top inside a <p> element... do NOT prefix it with any label.\n- Use <h2> for the main section heading and <h3> for logical subheads.\n- Use <p>, <ul>, and <ol> where appropriate... convert any lists into proper HTML lists.\n- Use <a href=\"\"> hyperlinks for any products... companies... or sources referenced if the URL is given.\n- End with: <p class=\"source\">Source: <a href=\"%s\" rel=\"nofollow noopener\">Original reporting</a></p>\n- Preserve key specs... dates... product names... prices... and quotations if present.\n- Do not invent facts... keep a neutral... factual tone.";
        return sprintf($tmpl, $minw, $maxw, esc_url_raw($source_url));
    }

    public static function expand_content($title, $source_url, $raw_html_or_text){
    $o = get_option('it_news_fetcher_options');
    if (empty($o['openai_enable']) || empty($o['openai_key'])) return '';

    // Settings from UI
    $minw = max(300, (int)($o['openai_target_words_min'] ?? 1200));
    $maxw = max($minw+50, (int)($o['openai_target_words_max'] ?? 1500));
    $max_tokens = max(300, (int)($o['openai_max_tokens'] ?? 2400));
    $temperature = isset($o['openai_temperature']) ? floatval($o['openai_temperature']) : 0.3;
    if ($temperature < 0) $temperature = 0.0;
    if ($temperature > 2) $temperature = 2.0;

    // Source text
    $text = wp_strip_all_tags($raw_html_or_text);
    $text = mb_substr($text, 0, 18000);

    // Build base instructions with your min/max
    $instructions = self::build_instructions($source_url); // uses minw/maxw internally

    // Single call helper
    $do_call = function($sys) use ($o,$title,$source_url,$text,$max_tokens,$temperature){
        $payload = array(
            'model' => 'gpt-4o-mini',
            'input' => array(
                array('role'=>'system','content'=>$sys),
                array('role'=>'user','content'=>"Title: {$title}\nSource: {$source_url}\n\nSource text:\n{$text}")
            ),
            'max_output_tokens' => $max_tokens,
            'temperature' => $temperature
        );
        $r = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer '.$o['openai_key'],
                'Content-Type'  => 'application/json'
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($r)) { error_log('ITNF OpenAI error: '.$r->get_error_message()); return ''; }
        if (200 !== wp_remote_retrieve_response_code($r)) { error_log('ITNF OpenAI HTTP '.wp_remote_retrieve_response_code($r).' body: '.wp_remote_retrieve_body($r)); return ''; }
        $json = json_decode(wp_remote_retrieve_body($r), true);
        $out = '';
        if (!empty($json['output_text'])) $out = trim($json['output_text']);
        elseif (!empty($json['output'][0]['content'][0]['text'])) $out = trim($json['output'][0]['content'][0]['text']);
        return $out;
    };

    // First attempt
    $out = $do_call($instructions);

    // Word count
    $wc = 0;
    if ($out) {
        $plain = wp_strip_all_tags($out);
        $plain = preg_replace('/\s+/', ' ', $plain);
        $wc = str_word_count($plain);
    }

    // Retry once if shorter than the min
    if ($wc > 0 && $wc < $minw) {
        $stronger = $instructions . "\n\nLength enforcement... expand to at least {$minw} words and preferably near {$maxw} words... add concise context... background... timelines... stakeholder impact... and implications while staying factual and tied to the source.";
        $out2 = $do_call($stronger);

        if ($out2) {
            $plain2 = preg_replace('/\s+/', ' ', wp_strip_all_tags($out2));
            $wc2 = str_word_count($plain2);
            if ($wc2 >= $wc) {
                $out = $out2;
                $wc = $wc2;
            }
        }
    }

    // Ensure attribution exists even if no link tags were produced
    if ($out && strpos($out, '<a ') === false && !empty($source_url)) {
        $out .= "\n<p class=\"source\">Source: <a href=\"".esc_url($source_url)."\" rel=\"nofollow noopener\">Original reporting</a></p>";
    }

    return $out;
}


    public static function generate_seo($title, $content, $source_url){
        $o = get_option('it_news_fetcher_options');
        $focus=''; $seo_title=''; $desc='';

        $plain = wp_strip_all_tags($content);
        $plain = mb_substr($plain, 0, 4000);

        if (!empty($o['openai_key'])) {
            $sys = "You are an SEO assistant for a WordPress news site. Produce JSON with keys: focus (1–3 simple phrases, comma-separated, ASCII-only), title (~55–60 chars, include the first focus at the beginning if natural), desc (~150–160 chars, include the first focus). Avoid quotes and special characters in focus. DO NOT include emojis.";
            $usr = "Post Title: {$title}\nSource: {$source_url}\nContent (trimmed):\n{$plain}\n\nReturn JSON only.";
            $payload = array(
                'model' => 'gpt-4o-mini',
                'input' => array(
                    array('role'=>'system','content'=>$sys),
                    array('role'=>'user','content'=>$usr),
                ),
                'max_output_tokens' => 320,
                'temperature' => 0.2,
                'response_format' => array('type'=>'json_object')
            );
            $r = wp_remote_post('https://api.openai.com/v1/responses', array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer '.$o['openai_key'],
                    'Content-Type'  => 'application/json'
                ),
                'body' => wp_json_encode($payload),
            ));
            if (!is_wp_error($r) && 200 === wp_remote_retrieve_response_code($r)) {
                $jr = json_decode(wp_remote_retrieve_body($r), true);
                $raw = '';
                if (!empty($jr['output_text'])) { $raw = $jr['output_text']; }
                elseif (!empty($jr['output'][0]['content'][0]['text'])) { $raw = $jr['output'][0]['content'][0]['text']; }
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $focus = sanitize_text_field($data['focus'] ?? '');
                    $seo_title = sanitize_text_field($data['title'] ?? '');
                    $desc = sanitize_text_field($data['desc'] ?? '');
                }
            }
        }

        if (!$focus) $focus = self::normalize_focus($title);
        $focus_norm = self::normalize_focus(explode(',', $focus)[0]);
        if (!$focus_norm) $focus_norm = 'technology news';

        if (!$seo_title) { $seo_title = $focus_norm.' – '.$title; }
        if (stripos($seo_title, $focus_norm) === false) { $seo_title = $focus_norm.' – '.$seo_title; }
        $seo_title = self::smart_truncate($seo_title, 60);

        if (!$desc) { $desc = $focus_norm.': '.self::smart_truncate($plain ?: $title, 150); }
        if (stripos($desc, $focus_norm) === false) { $desc = $focus_norm.': '.$desc; }
        $desc = self::smart_truncate($desc, 158);

        return array('focus'=>$focus_norm,'title'=>$seo_title,'desc'=>$desc);
    }
}
