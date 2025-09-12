(function ($) {
  'use strict';

  // ---- Guard: required globals ----
  if (typeof window.itnf_ajax !== 'object' || !itnf_ajax.ajax_url || !itnf_ajax.nonce) {
    console.error('[ITNF] itnf_ajax not localized correctly. Expected { ajax_url, nonce }.');
  }

  // ---- Elements ----
  var $log            = $('#itnf-log');              // scrolling log pane
  var $status         = $('#itnf-status');           // short status label
  var $btnStop        = $('#itnf-stop-run');         // Stop button
  var $btnFetchSel    = $('#itnf-fetch-selected');   // Fetch Selected button
  var $chkSelectAll   = $('#itnf-feed-select-all');  // Select all checkbox
  var $rows           = $('#itnf-feed-rows');        // <tbody id="itnf-feed-rows">

  // ---- Run state (client-sequenced) ----
  var queue      = [];    // list of URLs to process
  var index      = 0;     // current index in queue
  var running    = false; // global running flag
  var currentRun = null;  // { runId, cursor, url }
  var pollTimer  = null;  // setTimeout handle

  // ---- UI helpers ----
  function setRunning(on){
    running = !!on;
    $btnFetchSel.prop('disabled', on);
    $btnStop.prop('disabled', !on);
    $rows.find('button, input[type=checkbox]').prop('disabled', on);
    $chkSelectAll.prop('disabled', on);
  }
  function setStatus(t){ $status.text(t || ''); }
  function clearLog(){ $log.empty(); }
  function appendLine(line){
    var $d = $('<div/>').text(line || '');
    $log.append($d);
    $log.scrollTop($log[0].scrollHeight);
  }
  function appendLines(arr){
    for (var i=0;i<arr.length;i++){
      var it = arr[i];
      if (typeof it === 'string') appendLine(it);
      else if (it && typeof it.line === 'string') appendLine(it.line);
    }
  }

  // ---- Polling for the CURRENT feed only ----
  function pollCurrent(){
    if (!running || !currentRun || !currentRun.runId) return;
    $.post(itnf_ajax.ajax_url, {
      action: 'itnf_poll_log',
      run_id: currentRun.runId,
      cursor: currentRun.cursor || 0,
      _wpnonce: itnf_ajax.nonce
    }).done(function(res){
      if (!(res && res.success)) return;
      var d = res.data || {};
      if (Array.isArray(d.lines) && d.lines.length){
        appendLines(d.lines);
        currentRun.cursor = d.cursor || (currentRun.cursor || 0);
      } else if (typeof d.cursor === 'number') {
        currentRun.cursor = d.cursor;
      }
      if (d.status) setStatus(d.status);
      if (d.done){
        stopPolling();
        // proceed to next feed in the queue
        nextInQueue();
      }
    }).always(function(){
      if (running && currentRun && currentRun.runId && !pollTimer){
        pollTimer = setTimeout(function(){ pollTimer=null; pollCurrent(); }, 1200);
      }
    });
  }
  function stopPolling(){
    if (pollTimer){ clearTimeout(pollTimer); pollTimer = null; }
  }

  // ---- Feed list UI ----
  function setEmpty(msg){
    $rows.html('<tr><td colspan="4">'+String(msg||'No feeds.')+'</td></tr>');
  }

  function renderRows(urls){
    if (!urls.length){ setEmpty('No feeds configured. Add them in Settings.'); return; }
    var html = '';
    urls.forEach(function(u){
      var esc = $('<div/>').text(u).html();
      html += '' +
        '<tr data-url="' + esc + '">' +
          '<td style="width:28px;"><input type="checkbox" class="itnf-feed-cb"></td>' +
          '<td class="feed-url break">' + esc + '</td>' +
          '<td class="actions" style="white-space:nowrap;">' +
            '<button class="button itnf-check" type="button">Check</button> ' +
            '<button class="button button-primary itnf-run-one" type="button">Fetch</button>' +
          '</td>' +
          '<td class="status">—</td>' +
        '</tr>';
    });
    $rows.html(html);
  }

  function loadFeeds(){
    setEmpty('Loading…');
    $.post(itnf_ajax.ajax_url, {
      action: 'itnf_list_feeds',
      _wpnonce: itnf_ajax.nonce
    }).done(function(res){
      if(!(res && res.success)){ setEmpty('Failed to load feeds.'); return; }
      var feeds = (res.data && res.data.feeds) || [];
      renderRows(feeds);
    }).fail(function(){
      setEmpty('Failed to load feeds.');
    });
  }

  // ---- Check one feed (updates Status column) ----
  $rows.on('click', '.itnf-check', function(e){
    e.preventDefault();
    if (running) return;
    var $tr = $(this).closest('tr');
    var url = $tr.data('url');
    var $st = $tr.find('.status');
    if (!url){ $st.text('Invalid URL'); return; }
    $st.text('Checking…');

    $.post(itnf_ajax.ajax_url, {
      action: 'itnf_check_feed',
      url: url,
      _wpnonce: itnf_ajax.nonce
    }).done(function(res){
      if (!(res && res.success)){
        var msg = (res && res.data && (res.data.error || res.data.message)) ? (res.data.error || res.data.message) : 'Failed';
        $st.text(msg); return;
      }
      var c = res.data && res.data.new_count ? parseInt(res.data.new_count,10) : 0;
      $st.text(c + ' new');
    }).fail(function(){
      $st.text('Failed');
    });
  });

  // ---- Sequencer ----
  function startQueue(urls){
    // Prepare queue
    queue = urls.slice(0);
    index = 0;
    currentRun = null;

    clearLog();
    setStatus('Starting…');
    setRunning(true);

    // Kick off first feed
    nextInQueue();
  }

  function nextInQueue(){
    stopPolling(); // stop any previous poll loop

    if (!running){ setRunning(false); setStatus('Stopped.'); return; }
    if (index >= queue.length){
      setRunning(false);
      setStatus('Done.');
      appendLine('—— All selected feeds processed ——');
      return;
    }

    var url = queue[index++];
    appendLine('—— FEED '+index+'/'+queue.length+' — '+url+' ——');

    // Start single-feed run
    $.post(itnf_ajax.ajax_url, {
      action: 'itnf_fetch_one',
      url: url,
      _wpnonce: itnf_ajax.nonce
    }).done(function(res){
      if (!(res && res.success && res.data && res.data.run_id)){
        var msg = (res && res.data && (res.data.error || res.data.message)) ? (res.data.error || res.data.message) : 'Failed to start feed.';
        appendLine('[ERROR] '+msg);
        // Move on to next feed to avoid stalling entire batch
        nextInQueue();
        return;
      }
      // Begin polling this feed’s run_id until it is done
      currentRun = { runId: res.data.run_id, cursor: 0, url: url };
      pollCurrent();
    }).fail(function(){
      appendLine('[ERROR] Failed to start feed run.');
      nextInQueue();
    });
  }

  // ---- Stop button (stops current feed; prevents queuing next ones) ----
  $btnStop.on('click', function(e){
    e.preventDefault();
    if (!running) return;

    // Prevent starting any new feeds after the current one finishes
    running = false;

    // If a current run is active, request server stop
    if (currentRun && currentRun.runId){
      $.post(itnf_ajax.ajax_url, {
        action: 'itnf_stop_run',
        run_id: currentRun.runId,
        _wpnonce: itnf_ajax.nonce
      }).always(function(){
        setStatus('Stopping…');
        stopPolling();
      });
    } else {
      setStatus('Stopped.');
      stopPolling();
      setRunning(false);
    }
  });

  // ---- Single-row Fetch ----
  $rows.on('click', '.itnf-run-one', function(e){
    e.preventDefault();
    if (running) return;
    var url = $(this).closest('tr').data('url');
    if (!url) return;
    startQueue([url]);
  });

  // ---- Select all ----
  $chkSelectAll.on('change', function(){
    var on = $(this).prop('checked');
    $rows.find('.itnf-feed-cb').prop('checked', on);
  });

  // ---- Fetch selected ----
  $btnFetchSel.on('click', function(e){
    e.preventDefault();
    if (running) return;
    var urls = [];
    $rows.find('.itnf-feed-cb:checked').each(function(){
      var u = $(this).closest('tr').data('url');
      if (u) urls.push(u);
    });
    if (!urls.length){ setStatus('Select at least one feed.'); return; }
    startQueue(urls);
  });

  // ---- Init ----
  loadFeeds();

})(jQuery);
