<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ITNF_OpenAI
 * - Single-pass expansion: produce a complete HTML article within the Settings word range.
 * - Generate SEO (focus/title/desc/slug) from the expanded content (never the raw feed).
 * - Respect Settings: openai_enable, openai_key, openai_target_words_min/max (words), openai_max_tokens, openai_temperature.
 * - Respect Rank Math slug option (rm_optimize_slug) and dedupe slug tokens, cap 75 chars.
 * - Structural rules: never output <h1>; use <h2>/<h3>, paragraphs/lists; one dofollow Source footer to the article URL.
 */
class ITNF_OpenAI {

    /* ----------------- Helpers ----------------- */

    /** 6–7 words, ascii, lower, safe for focus keyword */
    public static function normalize_focus($text){
        $text = wp_strip_all_tags((string)$text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $map  = array(
            "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xE2\x80\x93" => '-', "\xE2\x80\x94" => '-',
        );
        $text = strtr($text, $map);
        $text = mb_strtolower($text);
        $text = str_replace(array('&amp;','&'), ' and ', $text);
        $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $parts = explode(' ', $text);
        $parts = array_slice($parts, 0, 7);
        return trim(implode(' ', $parts));
    }

    private static function wc($html){
        $plain = trim(preg_replace('~\s+~u', ' ', wp_strip_all_tags((string)$html)));
        return $plain ? str_word_count($plain) : 0;
    }

    private static function smart_truncate($text, $limit){
        $t = trim(preg_replace('~\s+~u', ' ', (string)$text));
        if (mb_strlen($t) <= $limit) return $t;
        $cut = mb_substr($t, 0, $limit);
        $sp  = mb_strrpos($cut, ' ');
        return ($sp !== false) ? mb_substr($cut, 0, $sp) : $cut;
    }

    /** Convert markdown-ish headings to HTML only if there are no headings already */
    private static function markdown_headings_to_html($html_or_text){
        $s = (string)$html_or_text;
        if (preg_match('/<h[1-6][^>]*>/i', $s)) return $s;
        $lines = preg_split("/\r\n|\r|\n/", $s);
        foreach ($lines as &$line){
            if (preg_match('/^\s*##\s+(.+)/', $line, $m)){
                $line = '<h2>'.esc_html($m[1]).'</h2>';
            } elseif (preg_match('/^\s*###\s+(.+)/', $line, $m)){
                $line = '<h3>'.esc_html($m[1]).'</h3>';
            } else {
                $line = $line === '' ? '' : '<p>'.esc_html($line).'</p>';
            }
        }
        unset($line);
        $joined = implode("\n", array_filter($lines, function($x){ return $x !== ''; }));
        return $joined;
    }

    /** Strip any H1 and ensure we have a single-sentence lead paragraph at the top */
    private static function strip_h1_and_enforce_lead($html, $headline=''){
        $s = (string)$html;
        $s = preg_replace('~<h1\b[^>]*>.*?</h1>~is', '', $s);
        if (!preg_match('~<p\b[^>]*>.*?</p>~is', $s)) {
            $plain = wp_strip_all_tags($headline ?: $s);
            if ($plain) {
                $leadParts = preg_split('/(?<=[\.\!\?])\s+/', $plain, 2);
                $first = esc_html(trim($leadParts[0]));
                if ($first) $s = '<p>'.$first.'</p>'."\n".$s;
            }
        }
        return $s;
    }

    /** Ensure exactly one Source footer, dofollow (per your request) */
    private static function enforce_source_footer($html, $source_url){
        if (!$source_url) return $html;
        $footer = '<p class="source">Source: <a href="'.esc_url($source_url).'">Original report</a></p>';
        $s = preg_replace('~<p[^>]*class=("|\')source\1[^>]*>.*?</p>~is', '', (string)$html);
        return rtrim($s)."\n".$footer;
    }

    /** Deduplicate slug tokens, cap to 75 chars; respect rm_optimize_slug for focus-prepend */
    private static function normalize_slug($slug, $focus){
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
        foreach ($parts as $p) { if (!isset($seen[$p])) { $seen[$p]=true; $uniq[]=$p; } }

        $out=''; foreach ($uniq as $p){
            $candidate = ($out==='' ? $p : $out.'-'.$p);
            if (strlen($candidate) > 75) break;
            $out = $candidate;
        }
        return rtrim($out, '-_');
    }

    /** Strip code fences and lone “html”/html marker lines from model output */
    private static function strip_code_fences($s){
        $s = (string)$s;
        // ```lang … ``` (start & end)
        $s = preg_replace('~^\s*```[a-zA-Z0-9_-]*\s*$~m', '', $s);
        $s = preg_replace('~^\s*```+\s*$~m', '', $s);
        // lone html / “html” on its own line
        $s = preg_replace('~^[\s“”"\'`]*html[\s“”"\'`]*$~mi', '', $s);
        // leading stray backticks
        $s = preg_replace('~^\s*`{1,3}\s*~m', '', $s);
        return trim($s);
    }

    /* ----------------- OpenAI Calls ----------------- */

    /**
     * Single-pass expansion to settings range (words).
     * - Uses Settings: min/max words, max tokens, temperature.
     * - Produces clean HTML: no <h1>, uses <h2>/<h3>, paragraphs/lists; adds a single dofollow Source footer.
     */
    public static function expand_content($headline, $source_url, $raw_html_or_text){
        $o = get_option('it_news_fetcher_options');
        if (empty($o['openai_enable']) || empty($o['openai_key'])) return '';

        $minw        = max(300, (int)($o['openai_target_words_min'] ?? 1200));
        $maxw        = max($minw + 50, (int)($o['openai_target_words_max'] ?? 1500));
        $tokens_set  = max(300, (int)($o['openai_max_tokens'] ?? 2400));
        $temperature = isset($o['openai_temperature']) ? floatval($o['openai_temperature']) : 0.3;

        // Token safety floor so the model can actually reach the target word count.
        // Roughly ~2 tokens per word upper-bound to be safe.
        $tokens_floor = (int) ceil($maxw * 2.0);
        $max_tokens   = max($tokens_set, $tokens_floor);

        $plain = wp_strip_all_tags((string)$raw_html_or_text);
        $plain = mb_substr($plain, 0, 18000);

        $sys =
"You are a senior technology news editor. Rewrite the provided material into a polished {$minw}-{$maxw} word HTML article for a WordPress site.

LENGTH REQUIREMENTS
- The article MUST be at least {$minw} words and at most {$maxw} words.
- Do NOT stop before reaching {$minw} words. If you approach the lower bound, expand by adding concise background, implications, stakeholder reactions, and relevant context from the provided material.
- Do not add fluff or generic filler; keep it factual and useful.

OUTPUT FORMAT
- Output PURE HTML only. Do NOT use markdown, code fences, language labels, or wrap in <html>/<body>.
- Do NOT output <h1>.
- Begin with exactly one <p> at the top: a single-sentence lead capturing the key development (not a rephrased headline).
- Use <h2> for main sections and <h3> for logical subsections.
- Use <p> for paragraphs. Convert lists to <ul>/<ol> appropriately.
- Neutral, precise tone.

CONTENT RULES
- Preserve facts, dates, quotes, figures, attributions.
- Add brief context and implications when helpful.
- Do NOT invent facts. If unclear, say it’s unclear or attribute it.

LINKS & IMAGES
- Do NOT output <img>.
- Do not add new external links within the body.

FOOTER
- End with exactly one footer: <p class=\"source\">Source: <a href=\"%s\">Original report</a></p>";

        $sys = sprintf($sys, esc_url_raw($source_url));
        $usr  = "HEADLINE (do not repeat in the first sentence):\n".trim((string)$headline)."\n\n";
        $usr .= "SOURCE URL:\n".trim((string)$source_url)."\n\n";
        $usr .= "RAW MATERIAL TO REWRITE:\n".$plain;

        $payload = array(
            'model' => 'gpt-4o-mini',
            'input' => array(
                array('role'=>'system','content'=>$sys),
                array('role'=>'user','content'=>$usr),
            ),
            'max_output_tokens' => $max_tokens,
            'temperature'       => $temperature,
        );

        $r = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => 120,
            'headers' => array(
                'Authorization' => 'Bearer '.$o['openai_key'],
                'Content-Type'  => 'application/json'
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($r) || 200 !== wp_remote_retrieve_response_code($r)) return '';

        $json = json_decode(wp_remote_retrieve_body($r), true);
        $out  = '';
        if (!empty($json['output_text'])) {
            $out = $json['output_text'];
        } elseif (!empty($json['output'][0]['content'][0]['text'])) {
            $out = $json['output'][0]['content'][0]['text'];
        }

        // Strip stray fences/labels
        $out = self::strip_code_fences($out);

        // Normalize possible markdown-ish output, enforce no H1, and append the single Source footer
        $out = self::markdown_headings_to_html($out);
        $out = self::strip_h1_and_enforce_lead($out, $headline);
        $out = wp_kses_post($out);
        $out = self::enforce_source_footer($out, $source_url);

        // Log under-run (for visibility in the live log)
        
// After getting $out from API, enforce minimum words
        $wc = self::wc($out);
        if ($wc < $minw) {
            // Retry once with expansion instruction
            $extra = "The previous draft was too short ({$wc} words). Expand further to at least {$minw} words, keeping structure.";
            $payload['input'][] = array('role'=>'user','content'=>$extra);
        
            $r2 = wp_remote_post('https://api.openai.com/v1/responses', array(
                'timeout' => 150,
                'headers' => array(
                    'Authorization' => 'Bearer '.$o['openai_key'],
                    'Content-Type'  => 'application/json'
                ),
                'body' => wp_json_encode($payload),
            ));
            if (!is_wp_error($r2) && 200 === wp_remote_retrieve_response_code($r2)) {
                $json2 = json_decode(wp_remote_retrieve_body($r2), true);
                $out2  = '';
                if (!empty($json2['output_text'])) {
                    $out2 = $json2['output_text'];
                } elseif (!empty($json2['output'][0]['content'][0]['text'])) {
                    $out2 = $json2['output'][0]['content'][0]['text'];
                }
                if ($out2) $out = $out2;
            }
        }

        return $out;
    }

    /**
     * Generate SEO fields from the expanded article.
     * Returns ['focus','title','desc','slug'].
     * Title ≤ 60 chars (starts with focus), desc 120–158 with focus exactly once, slug ≤ 75 chars.
     */
    public static function generate_seo($headline, $expanded_html, $source_url){
        $o = get_option('it_news_fetcher_options');

        $focus=''; $seo_title=''; $desc=''; $slug='';
        $plain = trim(preg_replace('~\s+~u', ' ', wp_strip_all_tags((string)$expanded_html)));
        $plain = mb_substr($plain, 0, 6000);

        if (!empty($o['openai_enable']) && !empty($o['openai_key'])) {
            $sys = "You are an SEO assistant for a WordPress tech news site. Return strict JSON only, no commentary.";

            $usr = "Expanded Article (HTML stripped to text):\n{$plain}\n\n"
                 . "Original Headline (H1 on site):\n{$headline}\n\n"
                 . "Rules:\n"
                 . "- focus: 1-4 simple words, lowercase ascii, no punctuation, no brand fluff.\n"
                 . "- title: <=60 chars, MUST start with focus, keep brand/product names exact, include a number only if natural.\n"
                 . "- desc: 120-158 chars, natural sentence, include focus exactly once. Avoid em dashes; use commas/colons.\n"
                 . "- slug: ascii lowercase hyphenated, 3-7 words, include focus at least once, <=75 chars.\n"
                 . "Return JSON with keys: focus, title, desc, slug.";

            $payload = array(
                'model' => 'gpt-4o-mini',
                'input' => array(
                    array('role'=>'system','content'=>$sys),
                    array('role'=>'user','content'=>$usr),
                ),
                'max_output_tokens' => max(300, (int)($o['openai_max_tokens'] ?? 2400)),
                'temperature'       => isset($o['openai_temperature']) ? floatval($o['openai_temperature']) : 0.3
            );

            $r = wp_remote_post('https://api.openai.com/v1/responses', array(
                'timeout' => 90,
                'headers' => array(
                    'Authorization' => 'Bearer '.$o['openai_key'],
                    'Content-Type'  => 'application/json'
                ),
                'body' => wp_json_encode($payload),
            ));
            if (!is_wp_error($r) && 200 === wp_remote_retrieve_response_code($r)) {
                $json = json_decode(wp_remote_retrieve_body($r), true);
                $txt  = '';
                if (!empty($json['output_text'])) {
                    $txt = $json['output_text'];
                } elseif (!empty($json['output'][0]['content'][0]['text'])) {
                    $txt = $json['output'][0]['content'][0]['text'];
                }
                $data = json_decode(trim($txt), true);
                if (is_array($data)) {
                    $focus     = sanitize_text_field($data['focus'] ?? '');
                    $seo_title = sanitize_text_field($data['title'] ?? '');
                    $desc      = sanitize_text_field($data['desc']  ?? '');
                    $slug      = sanitize_title($data['slug'] ?? '');
                }
            }
        }

        // Fallbacks & enforcement
        $focus = self::normalize_focus($focus ?: $headline);

        // Title: ensure starts with focus and ≤ 60
        if (!$seo_title) {
            $base = $headline;
            if ($focus && stripos($base, $focus) !== 0) $base = $focus.' - '.$base;
            $seo_title = mb_substr($base, 0, 60);
        } else {
            if ($focus && stripos($seo_title, $focus) !== 0) $seo_title = $focus.' - '.$seo_title;
            $seo_title = mb_substr($seo_title, 0, 60);
        }

        // Meta description: natural 120–158 incl. focus exactly once (no em dash)
        if (!$desc) {
            $desc = self::smart_truncate($plain, 156);
            if ($focus && stripos($desc, $focus) === false) $desc = self::smart_truncate($focus.': '.$desc, 158);
        } else {
            if ($focus && stripos($desc, $focus) === false) $desc = self::smart_truncate($focus.': '.$desc, 158);
            $desc = self::smart_truncate($desc, 158);
        }

        // Slug: dedupe tokens, cap 75; respect rm_optimize_slug
        if (!$slug) $slug = sanitize_title($focus.' '.$headline);
        $slug = self::normalize_slug($slug, $focus);

        return array('focus'=>$focus, 'title'=>$seo_title, 'desc'=>$desc, 'slug'=>$slug);
    }
}
