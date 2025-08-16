<?php if (!defined('ABSPATH')) { exit; } ?>
<p>
    <label><input type="checkbox" id="itnf-seo-overwrite"> <?php esc_html_e('Overwrite existing SEO fields','it-news-fetcher'); ?></label>
    <button id="itnf-seo-reload" class="button"><?php esc_html_e('Reload List','it-news-fetcher'); ?></button>
    <button id="itnf-seo-generate" class="button"><?php esc_html_e('Generate SEO for Selected','it-news-fetcher'); ?></button>
    <button id="itnf-seo-apply" class="button button-primary"><?php esc_html_e('Apply SEO to Selected','it-news-fetcher'); ?></button>
    <span id="itnf-seo-status" style="margin-left:10px;"></span>
</p>
<div class="itnf-table-wrap">
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:28px"><input type="checkbox" id="itnf-seo-select-all" /></th>
                <th style="width:90px"><?php esc_html_e('Word Count','it-news-fetcher'); ?></th>
                <th><?php esc_html_e('Title','it-news-fetcher'); ?></th>
                <th style="width:220px"><?php esc_html_e('Focus Keyword','it-news-fetcher'); ?></th>
                <th style="width:360px"><?php esc_html_e('Meta Description','it-news-fetcher'); ?></th>
                <th style="width:140px"><?php esc_html_e('Post ID','it-news-fetcher'); ?></th>
            </tr>
        </thead>
        <tbody id="itnf-seo-rows"><tr><td colspan="6"><?php esc_html_e('Loading…','it-news-fetcher'); ?></td></tr></tbody>
    </table>
</div>
<div id="itnf-seo-log" class="itnf-log"></div>
