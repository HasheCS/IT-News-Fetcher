(function($){
    var logEl = $('#itnf-log'), statusEl = $('#itnf-status'), stopBtn = $('#itnf-stop-run');
    var runId = null, pollTimer = null, cursor = 0;
    function appendLines(lines){ if(!lines||!lines.length) return; lines.forEach(function(l){ logEl.append($('<div/>').text(l)); }); logEl.scrollTop(logEl[0].scrollHeight); }
    function poll(){ if(!runId) return; $.post(itnf_ajax.ajax_url, {action:'itnf_poll_log', _wpnonce:itnf_ajax.nonce, run_id:runId, cursor:cursor}, function(res){ if(res && res.success){ cursor = res.data.cursor || cursor; appendLines(res.data.lines||[]); if(res.data.done){ statusEl.text('Done'); clearInterval(pollTimer); pollTimer=null; runId=null; stopBtn.prop('disabled', true); } } }); }

    var feedRows = $('#itnf-feed-rows'), selectAll = $('#itnf-feed-select-all'), fetchSelected = $('#itnf-fetch-selected');
    function loadFeeds(){
        $.post(itnf_ajax.ajax_url, {action:'itnf_list_feeds', _wpnonce:itnf_ajax.nonce}, function(res){
            if(!(res && res.success)){ feedRows.html('<tr><td colspan="4">Failed</td></tr>'); return; }
            var feeds = res.data.feeds || [];
            if(!feeds.length){ feedRows.html('<tr><td colspan="4">No feeds configured. Add them in Settings.</td></tr>'); return; }
            var html = '';
            feeds.forEach(function(u){
                html += '<tr data-url="'+u+'">'+
                    '<td><input type="checkbox" class="itnf-feed-cb"></td>'+
                    '<td class="itnf-url">'+u+'</td>'+
                    '<td>'+
                        '<button class="button button-small itnf-btn-check">Check</button> '+
                        '<button class="button button-small button-primary itnf-btn-fetch">Fetch</button>'+
                    '</td>'+
                    '<td class="itnf-row-status">—</td>'+
                '</tr>';
            });
            feedRows.html(html);
        }).then(bindRowEvents);
    }
    function bindRowEvents(){
        feedRows.on('click','.itnf-btn-check', function(){
            var row=$(this).closest('tr'), url=row.data('url'); row.find('.itnf-row-status').text('Checking…');
            $.post(itnf_ajax.ajax_url, {action:'itnf_check_feed', _wpnonce:itnf_ajax.nonce, url:url}, function(res){
                if(res && res.success){ row.find('.itnf-row-status').text((res.data.new_count||0)+' new'); } else { row.find('.itnf-row-status').text('Error'); }
            });
        });
        feedRows.on('click','.itnf-btn-fetch', function(){
            var row=$(this).closest('tr'), url=row.data('url'); logEl.empty(); statusEl.text('Running…'); cursor=0;
            $.post(itnf_ajax.ajax_url, {action:'itnf_fetch_one', _wpnonce:itnf_ajax.nonce, url:url}, function(res){
                if(res && res.success){ runId=res.data.run_id; stopBtn.prop('disabled',false); if(pollTimer) clearInterval(pollTimer); pollTimer=setInterval(poll, 1000); row.find('.itnf-row-status').text('Fetching…'); } else { row.find('.itnf-row-status').text('Failed'); }
            });
        });
        feedRows.on('change','.itnf-feed-cb', updateFetchSelected);
    }
    function updateFetchSelected(){ fetchSelected.prop('disabled', feedRows.find('.itnf-feed-cb:checked').length===0); }
    selectAll.on('change', function(){ feedRows.find('.itnf-feed-cb').prop('checked', this.checked); updateFetchSelected(); });

    fetchSelected.on('click', function(){
        var urls=[]; feedRows.find('.itnf-feed-cb:checked').each(function(){ urls.push($(this).closest('tr').data('url')); });
        if(!urls.length) return;
        logEl.empty(); statusEl.text('Running…'); cursor=0;
        $.post(itnf_ajax.ajax_url, {action:'itnf_fetch_selected', _wpnonce:itnf_ajax.nonce, urls:JSON.stringify(urls)}, function(res){
            if(res && res.success){ runId=res.data.run_id; stopBtn.prop('disabled',false); if(pollTimer) clearInterval(pollTimer); pollTimer=setInterval(poll, 1000); } else { statusEl.text('Failed'); }
        });
    });

    stopBtn.on('click', function(){
        if(!runId) return; stopBtn.prop('disabled',true);
        $.post(itnf_ajax.ajax_url, {action:'itnf_stop_run', _wpnonce:itnf_ajax.nonce, run_id:runId}, function(){ statusEl.text('Stopping…'); });
    });

    loadFeeds();
})(jQuery);
