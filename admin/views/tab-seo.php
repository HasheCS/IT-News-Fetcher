<?php if (!defined('ABSPATH')) { exit; } ?>
<div id="itnf-seo-root" data-ajax-url="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce('itnf_admin') ); ?>">
    <p style="margin-bottom:8px;">
    <label style="margin-right:12px;">
        <input type="checkbox" id="itnf-seo-apply-slug" />
        <?php esc_html_e('Also update slug (creates redirect)','it-news-fetcher'); ?>
    </label>

    <label style="margin-left:12px; margin-right:12px;">
        <input type="checkbox" id="itnf-force-overwrite" />
        <?php esc_html_e('Force overwrite manual SEO','it-news-fetcher'); ?>
    </label>

    <label style="margin-left:12px;">
        <?php esc_html_e('Per page','it-news-fetcher'); ?>
        <select id="itnf-seo-per-page">
            <option value="25">25</option>
            <option value="50" selected>50</option>
            <option value="100">100</option>
            <option value="200">200</option>
        </select>
    </label>

    <button id="itnf-seo-reload" class="button"><?php esc_html_e('Reload','it-news-fetcher'); ?></button>
    <button id="itnf-seo-generate" class="button"><?php esc_html_e('Generate (AI) for Selected','it-news-fetcher'); ?></button>
    <button id="itnf-seo-apply" class="button button-primary"><?php esc_html_e('Apply to Selected','it-news-fetcher'); ?></button>

    <span id="itnf-seo-status" style="margin-left:10px;"></span>
</p>

    <div id="itnf-seo-log" class="itnf-log" style="margin-top:10px;"></div>
    <div class="itnf-table-wrap">
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:28px"><input type="checkbox" id="itnf-seo-select-all" /></th>
                    <th style="width:90px"><?php esc_html_e('Words','it-news-fetcher'); ?></th>
                    <th><?php esc_html_e('Title','it-news-fetcher'); ?></th>
                    <th style="width:220px"><?php esc_html_e('Focus Keyword','it-news-fetcher'); ?></th>
                    <th style="width:360px"><?php esc_html_e('Meta Description','it-news-fetcher'); ?></th>
                    <th style="width:280px"><?php esc_html_e('Slug','it-news-fetcher'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Post ID','it-news-fetcher'); ?></th>
                </tr>
            </thead>
            <tbody id="itnf-seo-rows">
                <tr><td colspan="7"><?php esc_html_e('Loading...','it-news-fetcher'); ?></td></tr>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" style="display:flex; align-items:center; gap:8px; justify-content:flex-end;">
                        <button id="itnf-seo-prev" class="button">&lsaquo; <?php esc_html_e('Prev','it-news-fetcher'); ?></button>
                        <span id="itnf-seo-page-info"></span>
                        <button id="itnf-seo-next" class="button"><?php esc_html_e('Next','it-news-fetcher'); ?> &rsaquo;</button>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    
</div>
