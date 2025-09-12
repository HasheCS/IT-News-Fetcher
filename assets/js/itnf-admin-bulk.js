(function($){
  'use strict';

  var $rows = $('#itnf-bulk-rows');
  var $reload = $('#itnf-reload-list');
  var $rewrite = $('#itnf-rewrite-selected');
  var $status = $('#itnf-bulk-status');
  var $log = $('#itnf-bulk-log');
  var $selectAllTop = $('#itnf-select-all');
  var $selectAllBottom = $('#itnf-select-all-bottom');

  // toggles (already in your view)
  var $regenSEO  = $('#itnf-bulk-regen-seo');
  var $applySlug = $('#itnf-bulk-apply-slug');

  // run guard
  var running = false;

  // --- Log de-dup (small LRU) ---
  var seen = Object.create(null);
  var seenQueue = [];
  var SEEN_MAX = 10;

  function seenAdd(key){
    if (seen[key]) return true;
    seen[key] = 1;
    seenQueue.push(key);
    if (seenQueue.length > SEEN_MAX){
      var old = seenQueue.shift();
      delete seen[old];
    }
    return false;
  }

  function setEmpty(msg){
    $rows.html('<tr class="no-items"><td class="colspanchange" colspan="5">'+msg+'</td></tr>');
  }

  function setStatus(msg){
    $status.text(msg || '');
  }

  function setRunning(on){
    running = !!on;
    $rewrite.prop('disabled', running);
    $reload.prop('disabled', running);
    $selectAllTop.prop('disabled', running);
    $selectAllBottom.prop('disabled', running);
    $rows.find('.itnf-row').prop('disabled', running);
  }

  function clearLog(){
    $log.empty();
    seen = Object.create(null);
    seenQueue = [];
  }

  function appendLine(line, key){
    var txt = String(line || '');
    var k = key ? String(key) : 's:' + txt;
    if (seenAdd(k)) return; // suppress exact repeat
    $log.append('<div>'+_.escape(txt)+'</div>');
    $log.scrollTop($log[0].scrollHeight);
  }

  function appendLines(arr, keyPrefix){
    if (!arr || !arr.length) return;
    for (var i=0; i<arr.length; i++){
      var msg = String(arr[i]);
      appendLine(msg, (keyPrefix ? keyPrefix+':' : '') + msg);
    }
  }

  function loadList(){
    setEmpty('Loading...');
    $.post(itnf_ajax.ajax_url, { action: 'itnf_list_tech_news', _wpnonce: itnf_ajax.nonce }, function(res){
      if(!(res && res.success) || !res.data || !res.data.rows){ setEmpty('Failed'); return; }
      var rows = res.data.rows || [];
      if(!rows.length){ setEmpty('No items found'); return; }

      var html = rows.map(function(r){
        // use Rank Math style keys
        var id = Number.parseInt(r.ID, 10); id = Number.isFinite(id) ? id : 0;

        var wcNum = Number(r.words);
        var wcTxt = Number.isFinite(wcNum) ? wcNum.toLocaleString() : '—';

        var date  = r.date || '';
        var title = r.title || '';
        var edit  = r.edit  || '#';
        var url   = r.permalink || ''; // optional; may be empty

        return ''+
          '<tr>' +
            '<th scope="row" class="check-column"><input type="checkbox" class="itnf-row" value="'+id+'"/></th>' +
            '<td class="column-word_count num">'+ wcTxt +'</td>' +
            '<td class="column-title has-row-actions page-title">' +
              '<strong><a class="row-title" href="'+edit+'">'+ _.escape(title) +'</a></strong>' +
              '<div class="row-actions">' +
                (url ? '<span class="view"><a href="'+url+'" target="_blank" rel="noopener">View</a> | </span>' : '') +
                '<span class="edit"><a href="'+edit+'">Edit</a> | </span>' +
                '<span class="rewrite"><a href="#" class="itnf-rewrite-one" data-id="'+id+'">Rewrite</a></span>' +
              '</div>' +
            '</td>' +
            '<td class="column-post_id num">'+ id +'</td>' +
            '<td class="column-date">'+ _.escape(date) +'</td>' +
          '</tr>';
      }).join('');

      $rows.html(html);
    }).fail(function(){ setEmpty('Failed'); });
  }

  function selectedIds(){
    return $('.itnf-row:checked').map(function(){ return parseInt(this.value,10); }).get();
  }

  // select all... both header and footer stay in sync
  $(document).on('change', '#itnf-select-all, #itnf-select-all-bottom', function(){
    var checked = $(this).prop('checked');
    $('#itnf-select-all, #itnf-select-all-bottom').prop('checked', checked);
    $('.itnf-row').prop('checked', checked);
  });

  // reload
  $reload.on('click', function(e){ e.preventDefault(); if (running) return; loadList(); });

  // bulk rewrite
  $rewrite.on('click', function(e){
    e.preventDefault();
    if (running) return;

    var ids = selectedIds();
    if(!ids.length){ setStatus('Select at least one post'); return; }

    setRunning(true);
    setStatus('Rewriting '+ids.length+' post'+(ids.length>1?'s':'')+'…');
    clearLog();
    appendLine('Starting bulk rewrite…', 'start');

    var payload = {
      action: 'itnf_bulk_rewrite',
      _wpnonce: itnf_ajax.nonce,
      ids: JSON.stringify(ids),
      regen_seo: ($regenSEO.is(':checked') ? '1' : '0'),
      apply_slug: ($applySlug.is(':checked') ? '1' : '0')
    };

    $.post(itnf_ajax.ajax_url, payload, function(res){
      if(res && res.success){
        var msgs = (res.data && res.data.messages) ? res.data.messages : [];
        if (msgs.length){ appendLines(msgs, 'msg'); }
        else { appendLine('No messages returned.', 'nomsg'); }
        setStatus('Done');
      } else {
        appendLine('Failed to rewrite (server error).', 'fail');
        setStatus('Failed');
      }
    }).fail(function(){
      appendLine('Failed to rewrite (network error).', 'fail');
      setStatus('Failed');
    }).always(function(){
      setRunning(false);
      loadList();
    });
  });

  // single rewrite
  $(document).on('click', '.itnf-rewrite-one', function(e){
    e.preventDefault();
    if (running) return;

    var $a = $(this), id = parseInt($a.data('id'),10);
    if(!id) return;

    setRunning(true);
    setStatus('Rewriting post #'+id+'…');
    clearLog();
    appendLine('Starting single rewrite for ID '+id+'…', 'start:'+id);

    var payload = {
      action: 'itnf_bulk_rewrite',
      _wpnonce: itnf_ajax.nonce,
      ids: JSON.stringify([id]),
      regen_seo: ($regenSEO.is(':checked') ? '1' : '0'),
      apply_slug: ($applySlug.is(':checked') ? '1' : '0')
    };

    // Show inline feedback on the clicked link without breaking logging panel
    var oldTxt = $a.text();
    $a.text('Rewriting…').addClass('updating');

    $.post(itnf_ajax.ajax_url, payload, function(res){
      if(res && res.success){
        var msgs = (res.data && res.data.messages) ? res.data.messages : [];
        if (msgs.length){ appendLines(msgs, 'msg:'+id); }
        else { appendLine('No messages for ID '+id+'.', 'nomsg:'+id); }
        setStatus('Done');
        $a.text('Done');
      } else {
        appendLine('Failed to rewrite ID '+id+' (server error).', 'fail:'+id);
        setStatus('Failed');
        $a.text('Failed');
      }
    }).fail(function(){
      appendLine('Failed to rewrite ID '+id+' (network error).', 'fail:'+id);
      setStatus('Failed');
      $a.text('Failed');
    }).always(function(){
      setRunning(false);
      $a.removeClass('updating');
      // restore label if not final "Done"/"Failed"
      if ($a.text() === 'Rewriting…') $a.text(oldTxt);
      loadList();
    });
  });

  // Init 
  loadList();

})(jQuery);
