jQuery(document).ready(function ($) {

    let runId = ITNF_Backfill.run_id;
    let logCursor = 0;
    let logBox = $('#itnf-log-box');

    console.log("ITNF Backfill initialized. Nonce:", ITNF_Backfill.nonce, "Run ID:", runId);

    function pollLogs() {
        $.post(ITNF_Backfill.ajaxurl, {
            action: 'itnf_poll_log',
            run_id: runId,
            cursor: logCursor,
            nonce: ITNF_Backfill.nonce
        }, function (resp) {
            if (resp && resp.lines) {
                resp.lines.forEach(function (line) {
                    logBox.append($('<div>').text(line.line));
                });
                logBox.scrollTop(logBox[0].scrollHeight);
                logCursor = resp.cursor;
            }
            if (!resp.done) {
                setTimeout(pollLogs, 2000);
            }
        });
    }
    pollLogs();

    // Apply one
    $(document).on('click', '.itnf-apply-one', function () {
        let postId = $(this).data('post-id');
        let $row = $(this).closest('tr');

        if (!postId) {
            alert('Post ID not found');
            return;
        }

        console.log("Apply one → Post ID:", postId, "Nonce:", ITNF_Backfill.nonce);

        $(this).prop('disabled', true).text('Applying...');

        $.post(ITNF_Backfill.ajaxurl, {
            action: 'itnf_backfill_apply_one',
            nonce: ITNF_Backfill.nonce,
            run_id: runId,
            post_id: postId
        }, function (resp) {
            if (resp.success) {
                $row.fadeOut(300, function () { $(this).remove(); });
            } else {
                console.error("Apply one failed:", resp);
                alert(resp.data && resp.data.msg ? resp.data.msg : 'Failed to apply');
                $row.find('.itnf-apply-one').prop('disabled', false).text('Apply');
            }
        });
    });

    // Bulk apply
    $('#itnf-bulk-apply').on('click', function () {
        let postIds = [];

        $('.itnf-select:checked').each(function () {
            let pid = $(this).data('post-id');
            if (pid) {
                postIds.push(pid);
            }
        });

        if (postIds.length === 0) {
            alert('No posts selected');
            return;
        }

        console.log("Bulk apply → Post IDs:", postIds, "Nonce:", ITNF_Backfill.nonce);

        $(this).prop('disabled', true).text('Applying...');

        $.post(ITNF_Backfill.ajaxurl, {
            action: 'itnf_backfill_apply_bulk',
            nonce: ITNF_Backfill.nonce,
            run_id: runId,
            post_ids: postIds
        }, function (resp) {
            if (resp.success && resp.data.results) {
                for (let pid in resp.data.results) {
                    if (resp.data.results[pid]) {
                        $('tr[data-post-id="' + pid + '"]').fadeOut(300, function () { $(this).remove(); });
                    }
                }
            } else {
                console.error("Bulk apply failed:", resp);
                alert('Bulk apply failed');
            }
            $('#itnf-bulk-apply').prop('disabled', false).text('Bulk Apply');
        });
    });

    // Rescan
    $('#itnf-rescan').on('click', function () {
        let $btn = $(this);
        console.log("Rescan triggered. Nonce:", ITNF_Backfill.nonce);

        $btn.prop('disabled', true).text('Rescanning...');

        $.post(ITNF_Backfill.ajaxurl, {
            action: 'itnf_backfill_rescan',
            nonce: ITNF_Backfill.nonce
        }, function (resp) {
            if (resp.success && resp.data.rows) {
                $('#itnf-backfill-rows').html(resp.data.rows);
            } else {
                console.error("Rescan failed:", resp);
                alert('Rescan failed');
            }
            $btn.prop('disabled', false).text('Rescan');
        });
    });

    // Select all toggle
    $(document).on('change', '#itnf-select-all', function () {
        $('.itnf-select').prop('checked', $(this).is(':checked'));
    });

});
