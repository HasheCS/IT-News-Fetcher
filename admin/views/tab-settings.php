<?php if (!defined('ABSPATH')) { exit; } ?>
<form action="options.php" method="post">
    <?php
        settings_fields('it_news_fetcher_options');
        do_settings_sections('it-news-fetcher');
        submit_button(__('Save Settings','it-news-fetcher'));
    ?>
</form>
