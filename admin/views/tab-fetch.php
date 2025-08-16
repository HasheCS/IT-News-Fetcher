<?php if (!defined('ABSPATH')) { exit; } ?>
<p><?php esc_html_e('Select feeds to Check (count potential new posts) or Fetch individually, or use “Fetch Selected”.','it-news-fetcher'); ?></p>
<p>
    <button id="itnf-fetch-selected" class="button button-primary" disabled><?php esc_html_e('Fetch Selected','it-news-fetcher'); ?></button>
    <button id="itnf-stop-run" class="button" disabled><?php esc_html_e('Stop','it-news-fetcher'); ?></button>
    <span id="itnf-status" style="margin-left:10px;"></span>
</p>
<h3 style="margin-top:16px;"><?php esc_html_e('Live Log','it-news-fetcher'); ?></h3>
<div id="itnf-log" class="itnf-log"></div>
<div class="itnf-table-wrap">
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:28px;"><input type="checkbox" id="itnf-feed-select-all"></th>
                <th><?php esc_html_e('Feed URL','it-news-fetcher'); ?></th>
                <th style="width:160px;"><?php esc_html_e('Actions','it-news-fetcher'); ?></th>
                <th style="width:160px;"><?php esc_html_e('Status','it-news-fetcher'); ?></th>
            </tr>
        </thead>
        <tbody id="itnf-feed-rows">
            <tr><td colspan="4"><?php esc_html_e('Loading feeds…','it-news-fetcher'); ?></td></tr>
        </tbody>
    </table>
</div>


