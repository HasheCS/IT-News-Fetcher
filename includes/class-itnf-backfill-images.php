<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ITNF_Backfill_Images {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'wp_ajax_itnf_backfill_apply_one', [ __CLASS__, 'ajax_apply_one' ] );
        add_action( 'wp_ajax_itnf_backfill_rescan_one', [ __CLASS__, 'ajax_rescan_one' ] );
        add_action( 'wp_ajax_itnf_backfill_apply_bulk', [ __CLASS__, 'ajax_apply_bulk' ] );
    }

    public static function register_menu() {
        add_submenu_page(
            'edit.php?post_type=tech_news',
            __( 'Backfill Thumbnails', 'itnf' ),
            __( 'Backfill Thumbnails', 'itnf' ),
            'manage_options',
            'itnf-backfill-images',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        global $wpdb;

        $posts = $wpdb->get_results("
            SELECT ID, post_title, post_content
            FROM {$wpdb->posts}
            WHERE post_type = 'tech_news'
            AND post_status = 'publish'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta}
                WHERE post_id = ID AND meta_key = '_thumbnail_id'
            )
            ORDER BY ID DESC
            LIMIT 100
        ");

        echo '<div class="wrap"><h1>Backfill Thumbnails</h1>';

        // Logger
        echo '<div id="itnf-log" style="background:#000;border:1px solid #ccc;padding:10px;margin-bottom:15px;color:#31ff00;overflow:auto;font-family:monospace;font-size:12px;"></div>';

        // Top controls
        echo '<div style="margin:10px 0;padding:8px 0;">';
        echo '<button class="button button-secondary" id="itnf-rescan-all">Rescan All</button> ';
        echo '<button class="button button-primary" id="itnf-apply-selected">Apply Selected</button>';
        echo '</div>';

        echo '<form id="itnf-backfill-form"><table class="widefat striped">';
        echo '<thead><tr>
                <th><input type="checkbox" id="check-all"></th>
                <th>ID</th>
                <th>Word Count</th>
                <th>Title</th>
                <th>Source URL</th>
                <th>OG Image</th>
                <th>Actions</th>
              </tr></thead><tbody>';

        foreach ( $posts as $p ) {
            $source_url = self::get_source_url( $p->ID, $p->post_content );
            $image_url  = $source_url ? self::fetch_og_image( $source_url ) : '';

            $word_count = str_word_count( wp_strip_all_tags( $p->post_content ) );

            echo '<tr id="row-' . $p->ID . '">';
            echo '<td><input type="checkbox" class="row-check" value="' . $p->ID . '" data-img="' . esc_url( $image_url ) . '"></td>';
            echo '<td>' . $p->ID . '</td>';
            echo '<td>' . intval( $word_count ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '" style="font-weight:600;">' . esc_html( $p->post_title ) . '</a>';
            echo '<div style="font-size:11px;color:#666;">';
            echo '<a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">Edit</a> | ';
            echo '<a href="' . esc_url( get_permalink( $p->ID ) ) . '" target="_blank">View</a>';
            echo '</div>';
            echo '</td>';
            echo '<td>';
            if ( $source_url ) {
                echo '<a href="' . esc_url( $source_url ) . '" target="_blank">' . esc_html( $source_url ) . '</a>';
            } else {
                echo '<em>Not found</em>';
            }
            echo '</td>';
            echo '<td class="og-img">';
            echo $image_url ? '<img src="' . esc_url( $image_url ) . '" style="max-width:120px">' : '<em>Not found</em>';
            echo '</td>';
            echo '<td>';
            if ( $image_url ) {
                echo '<button type="button" class="button apply-thumb" data-id="' . $p->ID . '" data-img="' . esc_url( $image_url ) . '">Apply</button> ';
            }
            echo '<button type="button" class="button rescan-thumb" data-id="' . $p->ID . '">Rescan</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></form></div>';

        // Inline JS
        ?>
        <script>
        jQuery(function($){
            function log(msg){
                var now = new Date().toLocaleTimeString();
                $('#itnf-log').append('['+now+'] '+msg+'<br>').scrollTop(999999);
            }

            $('#check-all').on('change', function(){
                $('.row-check').prop('checked', $(this).prop('checked'));
            });

            $('.apply-thumb').on('click', function(){
                var $btn = $(this);
                var postId = $btn.data('id');
                var imgUrl = $btn.data('img');
                log('Applying thumbnail for post '+postId);
                $btn.prop('disabled', true).text('Applying...');
                $.post(ajaxurl, {
                    action: 'itnf_backfill_apply_one',
                    post_id: postId,
                    img_url: imgUrl
                }, function(resp){
                    if(resp.success){
                        log('Applied successfully for post '+postId);
                        $('#row-'+postId).fadeOut();
                    } else {
                        log('Failed for post '+postId+': '+resp.data);
                        $btn.prop('disabled', false).text('Apply');
                    }
                });
            });

            $('#itnf-apply-selected').on('click', function(e){
                e.preventDefault();
                var ids = [];
                $('.row-check:checked').each(function(){
                    ids.push({id: $(this).val(), img: $(this).data('img')});
                });
                if(!ids.length){ alert('No posts selected'); return; }
                log('Applying thumbnails for '+ids.length+' posts');
                $.post(ajaxurl, {
                    action: 'itnf_backfill_apply_bulk',
                    items: ids
                }, function(resp){
                    if(resp.success){
                        log('Bulk applied: '+resp.data);
                        ids.forEach(function(it){ $('#row-'+it.id).fadeOut(); });
                    } else {
                        log('Bulk apply failed: '+resp.data);
                    }
                });
            });

            $('.rescan-thumb').on('click', function(){
                var $btn = $(this);
                var postId = $btn.data('id');
                log('Rescanning post '+postId);
                $btn.prop('disabled', true).text('Rescanning...');
                $.post(ajaxurl, {
                    action: 'itnf_backfill_rescan_one',
                    post_id: postId
                }, function(resp){
                    $btn.prop('disabled', false).text('Rescan');
                    if(resp.success){
                        var img = resp.data;
                        log('Found new image for post '+postId);
                        $('#row-'+postId+' .og-img').html('<img src="'+img+'" style="max-width:120px">');
                        $('#row-'+postId+' .apply-thumb').data('img', img).show();
                    } else {
                        log('Rescan failed for post '+postId+': '+resp.data);
                    }
                });
            });

            $('#itnf-rescan-all').on('click', function(){
                var $rows = $('tr[id^="row-"]');
                if(!$rows.length){ log('No rows to rescan'); return; }
                log('Starting rescan for '+$rows.length+' posts…');

                $rows.each(function(i,row){
                    var postId = $(row).attr('id').replace('row-','');
                    $.post(ajaxurl, {
                        action: 'itnf_backfill_rescan_one',
                        post_id: postId
                    }, function(resp){
                        if(resp.success){
                            var img = resp.data;
                            log('Rescan success for post '+postId);
                            $('#row-'+postId+' .og-img').html('<img src="'+img+'" style="max-width:120px">');
                            $('#row-'+postId+' .apply-thumb').data('img', img).show();
                        } else {
                            log('Rescan failed for post '+postId+': '+resp.data);
                        }
                    });
                });
            });
        });
        </script>
        <?php
    }

private static function get_source_url( $post_id, $content ) {
    // 1. Meta
    $url = get_post_meta( $post_id, '_itnf_source_url', true );
    if ( $url ) return $url;

    // 2. <p class="source"><a href=...>
    if ( preg_match('/<p class="source">.*?<a[^>]+href=["\']([^"\']+)["\']/i', $content, $m ) ) {
        return $m[1];
    }

    // 3. Any <strong>Source:</strong> followed by plain URL
    if ( preg_match('/<strong>Source:\s*<\/strong>\s*(https?:\/\/[^\s<]+)/i', $content, $m ) ) {
        return $m[1];
    }

    // 4. Markdown-style link: [text](https://…)
    if ( preg_match('/\[[^\]]+\]\((https?:\/\/[^)]+)\)/i', $content, $m ) ) {
        return $m[1];
    }

    // 5. Plain URL inside <p> (no link)
    if ( preg_match('/<p>\s*(https?:\/\/[^\s<]+)\s*<\/p>/i', $content, $m ) ) {
        return $m[1];
    }

    // 6. Fallback: first external <a target="_blank">
    if ( preg_match('/<a[^>]+href=["\']([^"\']+)["\'][^>]*target=["\']_blank["\']/i', $content, $m ) ) {
        $candidate = $m[1];
        $host = parse_url( home_url(), PHP_URL_HOST );
        $chost = parse_url( $candidate, PHP_URL_HOST );
        if ( $chost && $chost !== $host ) {
            return $candidate;
        }
    }

    return '';
}





    private static function fetch_og_image( $url ) {
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) return '';
        $html = wp_remote_retrieve_body( $response );
        if ( ! $html ) return '';

        if ( preg_match('/<meta[^>]+property=["\']og:image(:secure_url|:url)?["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
            return $m[2];
        }
        if ( preg_match('/<meta[^>]+name=["\']twitter:image(:src)?["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
            return $m[2];
        }
        return '';
    }

    public static function ajax_rescan_one() {
        $id = intval($_POST['post_id'] ?? 0);
        if ( ! $id ) wp_send_json_error('Invalid ID');

        $post = get_post($id);
        if ( ! $post ) wp_send_json_error('Post not found');

        $source_url = self::get_source_url( $id, $post->post_content );
        if ( ! $source_url ) wp_send_json_error('No source URL found');

        $image_url = self::fetch_og_image( $source_url );
        if ( ! $image_url ) wp_send_json_error('No image found');

        wp_send_json_success( esc_url( $image_url ) );
    }

    public static function ajax_apply_one() {
        $id  = intval($_POST['post_id'] ?? 0);
        $url = esc_url_raw($_POST['img_url'] ?? '');
        if ( ! $id || ! $url ) wp_send_json_error('Invalid data');

        $res = self::sideload_image($id, $url);
        if ( is_wp_error($res) ) wp_send_json_error($res->get_error_message());

        wp_send_json_success('Thumbnail applied');
    }

    public static function ajax_apply_bulk() {
        $items = $_POST['items'] ?? [];
        if ( ! is_array($items) || empty($items) ) wp_send_json_error('No items');

        $done = 0;
        foreach ( $items as $it ) {
            $id = intval($it['id'] ?? 0);
            $url = esc_url_raw($it['img'] ?? '');
            if ( $id && $url ) {
                $res = self::sideload_image($id, $url);
                if ( ! is_wp_error($res) ) $done++;
            }
        }
        wp_send_json_success("Applied {$done} thumbnails");
    }

    private static function sideload_image( $post_id, $url ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) return $tmp;

        $file = [
            'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp,
        ];
        $id_img = media_handle_sideload( $file, $post_id );
        if ( is_wp_error( $id_img ) ) return $id_img;

        set_post_thumbnail( $post_id, $id_img );
        return true;
    }
}

ITNF_Backfill_Images::init();
