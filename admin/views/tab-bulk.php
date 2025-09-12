<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="wrap">
    <div class="tablenav top">
        <div class="alignleft actions bulkactions">
            <label style="margin-right:12px;"><input type="checkbox" id="itnf-bulk-regen-seo" /> <?php esc_html_e('Regenerate SEO','it-news-fetcher'); ?></label>
            <label style="margin-right:12px;"><input type="checkbox" id="itnf-bulk-apply-slug" /> <?php esc_html_e('Also update slug (creates redirect)','it-news-fetcher'); ?></label>

            <button id="itnf-reload-list" class="button"><?php esc_html_e('Reload List','it-news-fetcher'); ?></button>
            <button id="itnf-rewrite-selected" class="button button-primary"><?php esc_html_e('Rewrite Selected','it-news-fetcher'); ?></button>
            <span id="itnf-bulk-status" class="itnf-inline-status"></span>
        </div>
    </div>

    <div id="itnf-bulk-log" class="itnf-log" aria-live="polite"></div>

    <div class="itnf-table-wrap">
        <table class="wp-list-table widefat fixed striped table-view-list itnf-bulk-table">
            <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column">
                        <input type="checkbox" id="itnf-select-all" />
                    </td>
                    <th scope="col" class="manage-column column-word_count"><?php esc_html_e('Word Count','it-news-fetcher'); ?></th>
                    <th scope="col" class="manage-column column-title"><?php esc_html_e('Title','it-news-fetcher'); ?></th>
                    <th scope="col" class="manage-column column-post_id"><?php esc_html_e('Post ID','it-news-fetcher'); ?></th>
                    <th scope="col" class="manage-column column-date"><?php esc_html_e('Date','it-news-fetcher'); ?></th>
                </tr>
            </thead>
            <tbody id="itnf-bulk-rows">
                <tr class="no-items"><td class="colspanchange" colspan="5"><?php esc_html_e('Loading...','it-news-fetcher'); ?></td></tr>
            </tbody>
            <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="itnf-select-all-bottom" />
                    </td>
                    <th class="manage-column column-word_count"><?php esc_html_e('Word Count','it-news-fetcher'); ?></th>
                    <th class="manage-column column-title"><?php esc_html_e('Title','it-news-fetcher'); ?></th>
                    <th class="manage-column column-post_id"><?php esc_html_e('Post ID','it-news-fetcher'); ?></th>
                    <th class="manage-column column-date"><?php esc_html_e('Date','it-news-fetcher'); ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>



