<?php
if (!defined('ABSPATH')) { exit; }

class ITNF_Settings {
    public static function register(){
        register_setting('it_news_fetcher_options', 'it_news_fetcher_options', ['sanitize_callback'=>[__CLASS__,'sanitize']]);

        add_settings_section('itnf_main','Main Settings', function(){
            echo '<p>'.esc_html__('Enter feed URLs (comma- or newline-separated), frequency, items per feed, and optional defaults.','it-news-fetcher').'</p>';
        }, 'it-news-fetcher');

        add_settings_field('feeds','Feed URLs', [__CLASS__,'field_feeds'], 'it-news-fetcher','itnf_main');
        add_settings_field('frequency','Fetch Frequency', [__CLASS__,'field_frequency'], 'it-news-fetcher','itnf_main');
        add_settings_field('per','Items per feed (max)', [__CLASS__,'field_per'], 'it-news-fetcher','itnf_main');
        add_settings_field('thumb','Default Fallback Image URL', [__CLASS__,'field_thumb'], 'it-news-fetcher','itnf_main');
        add_settings_field('author','Default Post Author', [__CLASS__,'field_author'], 'it-news-fetcher','itnf_main');

        add_settings_section('itnf_ai','AI / Expansion', function(){
            echo '<p>'.esc_html__('Configure OpenAI-powered expansion and SEO generation.','it-news-fetcher').'</p>';
        }, 'it-news-fetcher');
        add_settings_field('openai_enable','Expand with OpenAI', [__CLASS__,'field_openai_enable'], 'it-news-fetcher','itnf_ai');
        add_settings_field('openai_key','OpenAI API Key', [__CLASS__,'field_openai_key'], 'it-news-fetcher','itnf_ai');
        add_settings_field('openai_threshold','Expansion Threshold (chars)', [__CLASS__,'field_openai_threshold'], 'it-news-fetcher','itnf_ai');
        add_settings_field('openai_target','Target Article Length (min/max words)', [__CLASS__,'field_openai_target'], 'it-news-fetcher','itnf_ai');
        add_settings_field('openai_tokens','Max Output Tokens', [__CLASS__,'field_openai_tokens'], 'it-news-fetcher','itnf_ai');
        add_settings_field('openai_temp','Temperature', [__CLASS__,'field_openai_temp'], 'it-news-fetcher','itnf_ai');

        add_settings_field('auto_tags','Auto Tags', [__CLASS__,'field_auto_tags'], 'it-news-fetcher','itnf_ai');
        add_settings_field('auto_rankmath','Auto Rank Math SEO on create', [__CLASS__,'field_auto_rankmath'], 'it-news-fetcher','itnf_ai');

        add_settings_section('itnf_rm','Rank Math 80+ Optimizer', function(){
            echo '<p>'.esc_html__('Automatically adjust content, slug, and metas to meet Rank Math tests that typically push posts into the green (80+).','it-news-fetcher').'</p>';
        }, 'it-news-fetcher');
        add_settings_field('rm_force_green','Enable Optimizer', [__CLASS__,'field_rm_force_green'], 'it-news-fetcher','itnf_rm');
        add_settings_field('rm_optimize_slug','Include Focus Keyword in URL (slug)', [__CLASS__,'field_rm_optimize_slug'], 'it-news-fetcher','itnf_rm');
        add_settings_field('rm_internal_links','Internal Links Pool (comma/newline URLs or paths)', [__CLASS__,'field_rm_internal_links'], 'it-news-fetcher','itnf_rm');
    }

    public static function sanitize($input){
        $out = [];
        $out['feed_urls'] = isset($input['feed_urls']) ? wp_kses_post($input['feed_urls']) : '';
        $out['frequency'] = in_array($input['frequency'] ?? 'daily', ['hourly','twicedaily','daily'], true) ? $input['frequency'] : 'daily';
        $out['items_per_feed'] = max(1, min(20, (int)($input['items_per_feed'] ?? 3)));
        $out['default_thumb'] = esc_url_raw($input['default_thumb'] ?? '');
        $author = isset($input['default_author']) ? absint($input['default_author']) : 0;
        $out['default_author'] = get_user_by('id', $author) ? $author : 0;

        $out['openai_enable'] = !empty($input['openai_enable']) ? 1 : 0;
        $out['openai_key'] = sanitize_text_field($input['openai_key'] ?? '');
        $out['openai_threshold'] = max(200, (int)($input['openai_threshold'] ?? 800));
        $out['openai_target_words_min'] = max(300, (int)($input['openai_target_words_min'] ?? 1200));
        $out['openai_target_words_max'] = max($out['openai_target_words_min']+50, (int)($input['openai_target_words_max'] ?? 1500));
        $out['openai_max_tokens'] = max(300, (int)($input['openai_max_tokens'] ?? 2400));
        $out['openai_temperature'] = is_numeric($input['openai_temperature'] ?? null) ? floatval($input['openai_temperature']) : 0.3;

        $out['auto_tags'] = !empty($input['auto_tags']) ? 1 : 0;
        $out['auto_rankmath'] = !empty($input['auto_rankmath']) ? 1 : 0;

        $out['rm_force_green'] = !empty($input['rm_force_green']) ? 1 : 0;
        $out['rm_optimize_slug'] = !empty($input['rm_optimize_slug']) ? 1 : 0;
        $out['rm_internal_links'] = wp_kses_post($input['rm_internal_links'] ?? '');
        return $out;
    }

    // ---- fields
    public static function field_feeds(){
        $o = get_option('it_news_fetcher_options'); $val = $o['feed_urls'] ?? '';
        echo "<textarea name='it_news_fetcher_options[feed_urls]' rows='6' cols='100' placeholder='https://example.com/feed/&#10;https://another.com/rss'>".esc_textarea($val)."</textarea>";
    }
    public static function field_frequency(){
        $o = get_option('it_news_fetcher_options'); $f = $o['frequency'] ?? 'daily';
        echo "<select name='it_news_fetcher_options[frequency]'>".
            "<option value='hourly' ".selected($f,'hourly',false).">Hourly</option>".
            "<option value='twicedaily' ".selected($f,'twicedaily',false).">Twice Daily</option>".
            "<option value='daily' ".selected($f,'daily',false).">Daily</option>".
            "</select>";
    }
    public static function field_per(){
        $o = get_option('it_news_fetcher_options'); $n = isset($o['items_per_feed']) ? (int)$o['items_per_feed'] : 3;
        echo "<input type='number' min='1' max='20' name='it_news_fetcher_options[items_per_feed]' value='".esc_attr($n)."'>";
    }
    public static function field_thumb(){
        $o = get_option('it_news_fetcher_options'); $u = $o['default_thumb'] ?? '';
        echo "<input type='url' size='80' name='it_news_fetcher_options[default_thumb]' value='".esc_attr($u)."'>";
    }
    public static function field_author(){
        $o = get_option('it_news_fetcher_options'); $cur = isset($o['default_author']) ? (int)$o['default_author'] : 0;
        $users = get_users(['who'=>'authors','orderby'=>'display_name','order'=>'ASC']);
        echo '<select name="it_news_fetcher_options[default_author]">';
        echo '<option value="0">— ' . esc_html__('Use current user (fallback to admin ID 1)','it-news-fetcher') . ' —</option>';
        foreach ($users as $u){
            printf('<option value="%d"%s>%s</option>', $u->ID, selected($cur, $u->ID, false), esc_html($u->display_name.' ('.$u->user_login.')'));
        }
        echo '</select>';
    }
    public static function field_openai_enable(){
        $o = get_option('it_news_fetcher_options'); $v = !empty($o['openai_enable']);
        echo "<label><input type='checkbox' name='it_news_fetcher_options[openai_enable]' value='1' ".checked($v,true,false)."> ".
            esc_html__('Enable content expansion','it-news-fetcher')."</label>";
    }
    public static function field_openai_key(){
        $o = get_option('it_news_fetcher_options'); $k = $o['openai_key'] ?? '';
        echo "<input type='password' size='50' name='it_news_fetcher_options[openai_key]' value='".esc_attr($k)."' placeholder='sk-...'>";
    }
    public static function field_openai_threshold(){
        $o = get_option('it_news_fetcher_options'); $t = $o['openai_threshold'] ?? 800;
        echo "<input type='number' min='200' max='4000' name='it_news_fetcher_options[openai_threshold]' value='".esc_attr((int)$t)."'>";
    }
    public static function field_openai_target(){
        $o = get_option('it_news_fetcher_options');
        $minw = $o['openai_target_words_min'] ?? 1200;
        $maxw = $o['openai_target_words_max'] ?? 1500;
        echo "<input type='number' min='300' name='it_news_fetcher_options[openai_target_words_min]' value='".esc_attr((int)$minw)."' style='width:90px'> – ";
        echo "<input type='number' min='350' name='it_news_fetcher_options[openai_target_words_max]' value='".esc_attr((int)$maxw)."' style='width:90px'>";
    }
    public static function field_openai_tokens(){
        $o = get_option('it_news_fetcher_options'); $v = $o['openai_max_tokens'] ?? 2400;
        echo "<input type='number' min='300' max='8000' name='it_news_fetcher_options[openai_max_tokens]' value='".esc_attr((int)$v)."'>";
    }
    public static function field_openai_temp(){
        $o = get_option('it_news_fetcher_options'); $v = isset($o['openai_temperature']) ? (float)$o['openai_temperature'] : 0.3;
        echo "<input type='number' step='0.1' min='0' max='2' name='it_news_fetcher_options[openai_temperature]' value='".esc_attr($v)."'>";
    }
    public static function field_auto_tags(){
        $o = get_option('it_news_fetcher_options'); $v = !empty($o['auto_tags']);
        echo "<label><input type='checkbox' name='it_news_fetcher_options[auto_tags]' value='1' ".checked($v,true,false)."> ".
            esc_html__('Generate tags from content','it-news-fetcher')."</label>";
    }
    public static function field_auto_rankmath(){
        $o = get_option('it_news_fetcher_options'); $v = !empty($o['auto_rankmath']);
        echo "<label><input type='checkbox' name='it_news_fetcher_options[auto_rankmath]' value='1' ".checked($v,true,false)."> ".
            esc_html__('Generate Focus Keyword, SEO Title, Meta Description','it-news-fetcher')."</label>";
    }
    public static function field_rm_force_green(){
        $o = get_option('it_news_fetcher_options'); $v = !empty($o['rm_force_green']);
        echo "<label><input type='checkbox' name='it_news_fetcher_options[rm_force_green]' value='1' ".checked($v,true,false)."> ".
            esc_html__('Try to auto-pass Rank Math basic & additional checks (target 80+)','it-news-fetcher')."</label>";
    }
    public static function field_rm_optimize_slug(){
        $o = get_option('it_news_fetcher_options'); $v = !empty($o['rm_optimize_slug']);
        echo "<label><input type='checkbox' name='it_news_fetcher_options[rm_optimize_slug]' value='1' ".checked($v,true,false)."> ".
            esc_html__('Prepend focus keyword to the post slug/URL','it-news-fetcher')."</label>";
    }
    public static function field_rm_internal_links(){
        $o = get_option('it_news_fetcher_options'); $val = $o['rm_internal_links'] ?? '';
        echo "<textarea name='it_news_fetcher_options[rm_internal_links]' rows='3' cols='100' placeholder='/tech-news/&#10;/blog/&#10;https://www.hashe.com/services/'>".esc_textarea($val)."</textarea>";
    }
}
