(function ($) {
  'use strict';

  var $rows      = $('#itnf-seo-rows');
  var $reload    = $('#itnf-seo-reload');
  var $gen       = $('#itnf-seo-generate');
  var $apply     = $('#itnf-seo-apply');
  var $selAll    = $('#itnf-seo-select-all');

  // Support either ID if your HTML used older names
  var $applySlug       = $('#itnf-apply-slug, #itnf-seo-apply-slug');
  var $forceOverwrite  = $('#itnf-force-overwrite, #itnf-seo-force-overwrite');

  var $status    = $('#itnf-seo-status');
  var $log       = $('#itnf-seo-log');

  var $perPage   = $('#itnf-seo-per-page');
  var $pageInfo  = $('#itnf-seo-page-info');
  var $prevPage  = $('#itnf-seo-prev');
  var $nextPage  = $('#itnf-seo-next');

  var state = { page: 1, per_page: parseInt($perPage.val() || '50', 10), total_pages: 1 };
  var running = false;

  // --- small LRU for log de-dup ---
  var seen = Object.create(null);
  var seenQ = [];
  var SEEN_MAX = 400;
  function seenAdd(key) {
    if (seen[key]) return true;
    seen[key] = 1;
    seenQ.push(key);
    if (seenQ.length > SEEN_MAX) {
      var old = seenQ.shift();
      delete seen[old];
    }
    return false;
  }

  function ajaxInfo() {
    var $root = $('#itnf-seo-root');
    var url = (window.itnf_ajax && window.itnf_ajax.ajax_url) ? window.itnf_ajax.ajax_url : ($root.data('ajaxUrl') || window.ajaxurl || '');
    var nonce = (window.itnf_ajax && window.itnf_ajax.nonce) ? window.itnf_ajax.nonce : ($root.data('nonce') || '');
    return { url: url, nonce: nonce };
  }

  function setStatus(text) { $status.text(text || ''); }

  function setRunning(on){
    running = !!on;
    $gen.prop('disabled', running);
    $apply.prop('disabled', running);
    $reload.prop('disabled', running);
    $selAll.prop('disabled', running);
    $rows.find('.itnf-seo-cb').prop('disabled', running);
  }

  function logMsg(msg) {
    if (!msg) return;
    var txt = String(msg);
    var key = 's:' + txt;
    if (seenAdd(key)) return; // suppress exact repeats
    $('<div/>').text(txt).appendTo($log);
    $log.scrollTop($log[0].scrollHeight);
  }

  function renderRows(rows) {
    if (!rows || !rows.length) {
      $rows.html('<tr class="no-items"><td class="colspanchange" colspan="7">No posts found.</td></tr>');
      return;
    }
    var html = '';
    rows.forEach(function (r) {
      var title = $('<div/>').text(r.title).html();
      var edit = r.edit_link ? '<a href="'+r.edit_link+'">Edit</a>' : '';
      var view = r.view_link ? '<a href="'+r.view_link+'" target="_blank" rel="noopener">View</a>' : '';
      var actions = '';
      if (edit || view) actions = '<div class="row-actions">'+edit+(edit&&view?' | ':'')+view+'</div>';

      var slug = r.slug ? r.slug : '';
      var aiSlug = r.ai_slug ? r.ai_slug : '';
      var manualBadge = (aiSlug && slug && slug !== aiSlug) ? ' <span class="tag warning">Manual slug</span>' : '';
      var focus = r.focus ? r.focus : '';
      var desc = r.desc ? r.desc : '';

      var sFocus = r.s_focus ? ' <span class="tag">→ '+$('<span/>').text(r.s_focus).html()+'</span>' : '';
      var sTitle = r.s_title ? ' <div class="description">→ '+$('<span/>').text(r.s_title).html()+'</div>' : '';
      var sDesc  = r.s_desc  ? ' <div class="description">→ '+$('<span/>').text(r.s_desc).html()+'</div>' : '';
      var sSlug  = r.s_slug  ? ' <div class="description">→ '+$('<span/>').text(r.s_slug).html()+'</div>' : '';

      html += '<tr>';
      html +=   '<td style="width:28px;"><input type="checkbox" class="itnf-seo-cb" value="'+r.ID+'"></td>';
      html +=   '<td style="width:90px;">'+(r.words||0)+'</td>';
      html +=   '<td><strong><a href="'+(r.edit_link||'#')+'">'+title+'</a></strong>'+actions+sTitle+'</td>';
      html +=   '<td style="width:220px;"><div>'+ (focus ? $('<span/>').text(focus).html() : '') + sFocus +'</div></td>';
      html +=   '<td style="width:360px;"><div>'+ (desc ? $('<span/>').text(desc).html() : '') + sDesc +'</div></td>';
      html +=   '<td style="width:280px;"><code>'+ $('<span/>').text(slug).html() +'</code>'+ manualBadge + sSlug +'</td>';
      html +=   '<td style="width:120px;">'+r.ID+'</td>';
      html += '</tr>';
    });
    $rows.html(html);
  }

  function refreshPager(page, total_pages) {
    $pageInfo.text('Page '+page+' of '+total_pages);
    $prevPage.prop('disabled', page <= 1);
    $nextPage.prop('disabled', page >= total_pages);
  }

  function loadList() {
    setStatus('Loading...');
    var ai = ajaxInfo();
    $.post(ai.url, {
      action: 'itnf_seo_list',
      _wpnonce: ai.nonce,
      page: state.page,
      per_page: state.per_page
    }, function (res) {
      if (!(res && res.success)) {
        $rows.html('<tr class="no-items"><td class="colspanchange" colspan="7">Failed to load.</td></tr>');
        setStatus('Failed');
        return;
      }
      renderRows(res.data.rows || []);
      state.total_pages = res.data.total_pages || 1;
      refreshPager(state.page, state.total_pages);
      setStatus('');
    });
  }

  function selectedIds() {
    var out = [];
    $rows.find('.itnf-seo-cb:checked').each(function () { out.push(parseInt(this.value, 10)); });
    return out;
  }

  function chunk(arr, size) {
    var out = [];
    for (var i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
    return out;
  }

  function labelForAction(action){
    if (action === 'itnf_seo_generate') return 'Generating';
    if (action === 'itnf_seo_apply')    return 'Applying';
    return action;
  }

  function doBatched(action, ids, extra, done) {
    var ai = ajaxInfo();
    var groups = chunk(ids, 20);
    var idx = 0;

    setRunning(true);

    function next() {
      if (idx >= groups.length) {
        setRunning(false);
        done(true);
        return;
      }
      var part = groups[idx++];
      var start = (idx-1)*20 + 1;
      var end   = (idx-1)*20 + part.length;
      setStatus(labelForAction(action)+': '+start+'–'+end+' / '+ids.length);

      var payload = { action: action, _wpnonce: ai.nonce, ids: part };
      if (extra) for (var k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) payload[k] = extra[k];

      $.post(ai.url, payload, function (res) {
        if (res && res.success && res.data && res.data.messages) {
          (res.data.messages || []).forEach(function (m) { logMsg(m); });
          next();
        } else {
          logMsg('Failed during '+action+' at batch '+idx);
          setStatus('Failed');
          setRunning(false);
          done(false);
        }
      }).fail(function(){
        logMsg('Network error during '+action+' at batch '+idx);
        setStatus('Failed');
        setRunning(false);
        done(false);
      });
    }
    next();
  }

  $reload.on('click', function(){ if (running) return; state.page = 1; loadList(); });
  $perPage.on('change', function(){ if (running) return; state.per_page = parseInt($perPage.val() || '50', 10); state.page = 1; loadList(); });
  $prevPage.on('click', function(){ if (running) return; if (state.page > 1) { state.page--; loadList(); } });
  $nextPage.on('click', function(){ if (running) return; if (state.page < state.total_pages) { state.page++; loadList(); } });

  $selAll.on('change', function () {
    if (running) { this.checked = !this.checked; return; }
    var ck = this.checked;
    $rows.find('.itnf-seo-cb').prop('checked', ck);
  });

  $gen.on('click', function () {
    if (running) return;
    var ids = selectedIds();
    if (!ids.length) { alert('Select at least one post.'); return; }
    setStatus('Generating…');
    doBatched('itnf_seo_generate', ids, null, function (ok) {
      setStatus(ok ? 'Done' : 'Failed');
      loadList();
    });
  });

  $apply.on('click', function () {
    if (running) return;
    var ids = selectedIds();
    if (!ids.length) { alert('Select at least one post.'); return; }

    // EXACTLY what seo_apply() expects now
    var extra = {
      apply_slug: $applySlug.is(':checked') ? '1' : '0',
      force_overwrite: $forceOverwrite.is(':checked') ? 1 : 0
    };

    setStatus('Applying…');
    doBatched('itnf_seo_apply', ids, extra, function (ok) {
      setStatus(ok ? 'Done' : 'Failed');
      loadList();
    });
  });

  // initial
  loadList();

})(jQuery);
