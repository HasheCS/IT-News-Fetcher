(function($){
  var $rows = $('#itnf-bulk-rows');
  var $reload = $('#itnf-reload-list');
  var $rewrite = $('#itnf-rewrite-selected');
  var $status = $('#itnf-bulk-status');
  var $log = $('#itnf-bulk-log');
  var $selectAllTop = $('#itnf-select-all');
  var $selectAllBottom = $('#itnf-select-all-bottom');

  function setEmpty(msg){
    $rows.html('<tr class="no-items"><td class="colspanchange" colspan="5">'+msg+'</td></tr>');
  }

  function loadList(){
    setEmpty('Loading...');
    $.post(itnf_ajax.ajax_url, { action: 'itnf_list_tech_news', _wpnonce: itnf_ajax.nonce }, function(res){
      if(!(res && res.success) || !res.data || !res.data.rows){ setEmpty('Failed'); return; }
      var rows = res.data.rows;
      if(!rows.length){ setEmpty('No items found'); return; }

      var html = (res.data.rows || []).map(function(r){
  // use Rank Math style keys
  var id = Number.parseInt(r.ID, 10);
  id = Number.isFinite(id) ? id : 0;

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
          (url ? '<span class="view"><a href="'+url+'" target="_blank">View</a> | </span>' : '') +
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
  $reload.on('click', function(e){ e.preventDefault(); loadList(); });

  // bulk rewrite
  $rewrite.on('click', function(e){
    e.preventDefault();
    var ids = selectedIds();
    if(!ids.length){ $status.text('Select at least one post'); return; }
    $status.text('Rewriting...'); $log.empty();
    $.post(itnf_ajax.ajax_url, { action: 'itnf_bulk_rewrite', _wpnonce: itnf_ajax.nonce, ids: JSON.stringify(ids) }, function(res){
      if(res && res.success){
        (res.data && res.data.messages || []).forEach(function(m){ $log.append('<div>'+_.escape(m)+'</div>'); });
        $status.text('Done');
        loadList();
      } else {
        $status.text('Failed');
      }
    }).fail(function(){ $status.text('Failed'); });
  });

  // single rewrite
  $(document).on('click', '.itnf-rewrite-one', function(e){
    e.preventDefault();
    var $a = $(this), id = parseInt($a.data('id'),10);
    if(!id) return;
    $a.text('Rewriting...');
    $.post(itnf_ajax.ajax_url, { action: 'itnf_bulk_rewrite', _wpnonce: itnf_ajax.nonce, ids: JSON.stringify([id]) }, function(res){
      $a.text((res && res.success) ? 'Done' : 'Failed');
      if(res && res.success) loadList();
    }).fail(function(){ $a.text('Failed'); });
  });

  loadList();
})(jQuery);
