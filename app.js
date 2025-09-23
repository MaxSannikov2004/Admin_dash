/* local_admindash plain JS (no AMD) */
(function($){
  'use strict';

  var CURRENT = { courseid:0, groupid:0 };

  function t(k){
    try {
      if (window.M && M.util && typeof M.util.get_string === 'function') {
        var comp = (k === 'student') ? 'core' : 'local_admindash';
        var str  = M.util.get_string(k, comp);
        if (typeof str === 'string' && str.indexOf('[[') === -1) { return str; }
      }
    } catch(e){}
    var fallbacks = { student:'Студент', element:'Элемент', no_data:'Нет данных', loading:'Загрузка...', apply:'Применить', note_editor_title:'Заметка куратора', save:'Сохранить', cancel:'Отмена' };
    return fallbacks[k] || k;
  }

  function api(action, params){
    params = params || {}; params.action = action;
    if (window.M && M.cfg && M.cfg.sesskey) { params.sesskey = M.cfg.sesskey; }
    var base = (window.M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
    // use POST for save_note to avoid long URLs / encoding issues, GET for others
    var method = (action === 'save_note') ? 'POST' : 'GET';
    return $.ajax({ url: base + '/local/admindash/ajax.php', method: method, dataType: 'json', data: params });
  }

  function dateToTimestamp(s){ if(!s) return 0; try{ return Math.floor(new Date(s+'T00:00:00Z').getTime()/1000); }catch(_){ return 0; } }
  function fmtDateShort(ts){ try{ if(!ts) return ''; var d=new Date(Number(ts)*1000); return String(d.getDate()).padStart(2,'0')+'.'+String(d.getMonth()+1).padStart(2,'0'); }catch(_){ return ''; } }
  function setLoading(on){ var $b=$('#cd-apply'); var lbl=$b.data('label-apply')||t('apply'); $b.prop('disabled',!!on).text(on?(t('loading')||'Loading...'):lbl); }
  function cellGet(obj, key){ if(!obj) return undefined; if(Object.prototype.hasOwnProperty.call(obj,key)) return obj[key]; var sk=String(key); if(Object.prototype.hasOwnProperty.call(obj,sk)) return obj[sk]; var nk=Number(key); if(Object.prototype.hasOwnProperty.call(obj,nk)) return obj[nk]; return undefined; }

  function openNoteEditor(opts){
    var $pop = $('#cd-note-pop'); if ($pop.length) $pop.remove();
    $pop = $('<div id="cd-note-pop">');
    $pop.append($('<h4>').text(t('note_editor_title')));
    var $ta = $('<textarea>').val(opts.current || '');
    $pop.append($ta);
    // priority selector
    var $prioRow = $('<div>').css({'margin-top':'8px','display':'flex','gap':'8px','align-items':'center'});
    $prioRow.append($('<label>').text('Приоритет:').css({'font-size':'13px','margin-right':'6px'}));
    var $sel = $('<select id="cd-note-priority">');
    $sel.append($('<option>').attr('value',0).text('Нормальный'));
    $sel.append($('<option>').attr('value',1).text('Важный'));
    $sel.append($('<option>').attr('value',2).text('Срочно'));
    $sel.val(opts.priority||0);
    $prioRow.append($sel);
    $pop.append($prioRow);
    $pop.append($('<div>').addClass('small').text('Сохраняется только в этом дашборде — в журналы не уходит.'));
    var $actions = $('<div class="actions">');
    var $btnCancel = $('<button>').text(t('cancel'));
    var $btnSave   = $('<button class="primary">').text(t('save'));
    $actions.append($btnCancel, $btnSave); $pop.append($actions); $('body').append($pop);
    var vpW=$(window).width(), vpH=$(window).height();
    $pop.css({ left:(vpW-$pop.outerWidth())/2+'px', top:Math.max(12,(vpH-$pop.outerHeight())/3)+'px' });
    $btnCancel.on('click', function(){ $pop.remove(); });
    $btnSave.on('click', function(){
      var pr = parseInt(String($sel.val()||'0'),10)||0;
      api('save_note', {
        courseid: CURRENT.courseid, groupid: CURRENT.groupid,
        userid: opts.userid, itemtype: (opts.type==='grade'?'grade':'att'), itemid: opts.itemid,
        note: $ta.val(), priority: pr
      }).done(function(){
        $pop.remove();
        if (opts.$cell && opts.$cell.length) {
          var text = ($ta.val() || '').trim();
          if (text) {
            opts.$cell.addClass('cd-has-cdnote').attr('data-cdnote', text).attr('data-cdpriority', pr);
          } else {
            opts.$cell.removeClass('cd-has-cdnote').removeAttr('data-cdnote');
          }
          var parts = (opts.$cell.attr('title') || '').split(/\n/).filter(Boolean).filter(function(s){ return !/^Куратор: /.test(s); });
          if (text) parts.push('Куратор: ' + text);
          // remove native title usage and set custom tooltip content attribute
          opts.$cell.removeAttr('title').attr('data-tooltip', parts.join('\n'));
          // add priority class
          opts.$cell.removeClass('cd-prio-0 cd-prio-1 cd-prio-2').addClass('cd-prio-'+pr);
        }
      }).fail(function(xhr){ console.error('save_note failed', xhr && xhr.responseText); $pop.remove(); });
    });
  }

  function renderGradesMatrix(payload){
    try{
      payload=payload||{};
      var students=Array.isArray(payload.students)?payload.students:[];
      var items=Array.isArray(payload.items)?payload.items:[];
      var cells=(payload&&payload.cells)||{};

      // If there are no students or no grade items, show friendly message
      if (!students.length || !items.length) {
        $('#cd-grades').empty().text(t('no_data'));
        $('#kpi-grade-avg').text('0%');
        $('#kpi-students').text(students.length || 0);
        $('#cd-grades').data('cancomment', !!(payload && payload.cancomment));
        return;
      }

      var $table=$('<table>').addClass('cd-matrix');
      var $thead=$('<thead>'); var $tbody=$('<tbody>'); var $hr=$('<tr>');
      $hr.append($('<th>').text(t('student')));
      items.forEach(function(it){ $hr.append($('<th>').text((it&&it.name)||((t('element')||'')+' '+String((it&&it.id)||'')))); });
      $thead.append($hr);

      var sum=0,cnt=0;
      students.forEach(function(st){
        var uid=(st&&st.id!=null)?st.id:''; var $tr=$('<tr>');
        $tr.append($('<td>').addClass('cd-student').text(st&&st.name?st.name:('ID '+uid)));
        items.forEach(function(it){
          var out='—', iid=(it&&it.id!=null)?it.id:'';
          var row=cellGet(cells, uid); var mark=row?cellGet(row, iid):undefined;
          if(mark!==undefined&&mark!==null&&mark!==''){
            var v=null, max=parseFloat(it&&it.max);
            if (typeof mark === 'object' && mark !== null) {
              if ('val' in mark) v=parseFloat(mark.val);
            } else v=parseFloat(mark);
            if(!isNaN(v)){ out=String(v); if(!isNaN(max)&&max>0){ sum+=(v/max); cnt++; } }
          }
          var $td=$('<td>').text(out).attr({'data-type':'grade','data-userid':uid,'data-itemid':iid});
          if (mark && typeof mark === 'object' && mark.cdnote) {
            var pr = mark.cdpriority || 0;
            $td.addClass('cd-has-cdnote').attr('data-cdnote', String(mark.cdnote)).attr('data-cdpriority', pr).attr('data-tooltip', 'Куратор: '+String(mark.cdnote));
            $td.addClass('cd-prio-'+pr);
            // insert inline SVG marker for resilience against theme CSS
            var svgCorner = '<svg class="cd-svg-corner" width="16" height="16" viewBox="0 0 14 14" aria-hidden="true"><path d="M14 0v7c0 2.761-2.239 5-5 5H0V0h14z" fill="#FFD952"/></svg>';
            var svgPen = '<svg class="cd-svg-pen" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1.004 1.004 0 0 0 0-1.42l-2.34-2.34a1.004 1.004 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 1.84-1.82z" fill="#5b4300"/></svg>';
            // absolute wrapper (top-right) and inline fallback (emoji) for themes that clip/override
            var wrapper = '<div class="cd-marker-abs">'+svgCorner+svgPen+'</div>';
            var inlineFallback = '<span class="cd-inline-marker" aria-hidden="true">✎</span>';
            $td.append(wrapper).append(inlineFallback);
            // also append a guaranteed inline (non-absolute) marker so themes like Academi can't hide it
            $td.append('<span class="cd-inline-fallback-visible" aria-hidden="true">✎</span>');
          }
          $tr.append($td);
        });
        $tbody.append($tr);
      });

      $table.append($thead).append($tbody);
      // payload.cancomment indicates whether current user can add/edit notes
      $('#cd-grades').empty().append($table).data('cancomment', !!(payload && payload.cancomment));
      var kpi=cnt?(100.0*(sum/cnt)):0; $('#kpi-grade-avg').text(kpi.toFixed(1)+'%'); $('#kpi-students').text(students.length);
    }catch(e){ console.error('renderGradesMatrix fail', e); $('#cd-grades').text(t('no_data')); }
  }

  function renderAttendanceMatrix(payload){
    try{
      payload=payload||{};
      // If backend indicates attendance module/tables are not present, show informative message
      if (payload && payload.attendance_available === false) {
        $('#cd-attendance').empty().text('Модуль посещаемости не настроен на сервере.');
        $('#kpi-att').text('0.0%');
        $('#cd-attendance').data('cancomment', false);
        return;
      }
      var students=Array.isArray(payload.students)?payload.students:[];
      var sessions=Array.isArray(payload.sessions)?payload.sessions:[];
      var cells=(payload&&payload.cells)||{}; var kpi=(payload&&typeof payload.kpi==='number')?payload.kpi:0;

      // If no students or no sessions, show friendly message
      if (!students.length || !sessions.length) {
        $('#cd-attendance').empty().text(t('no_data'));
        $('#kpi-att').text('0.0%');
        $('#cd-attendance').data('cancomment', !!(payload && payload.cancomment));
        return;
      }

      var $table=$('<table>').addClass('cd-matrix att');
      var n=sessions.length;
      if (n>24){ $table.addClass('compact-3'); } else if (n>18){ $table.addClass('compact-2'); } else if (n>12){ $table.addClass('compact-1'); }

      var $thead=$('<thead>'); var $tbody=$('<tbody>'); var $hr=$('<tr>');
      $hr.append($('<th>').text(t('student')));
      sessions.forEach(function(s){ $hr.append($('<th>').text(s&&s.date?fmtDateShort(s.date):String(s&&s.id||''))); });
      $thead.append($hr);

      students.forEach(function(st){
        var uid=(st&&st.id!=null)?st.id:''; var $tr=$('<tr>');
        $tr.append($('<td>').addClass('cd-student').text(st&&st.name?st.name:('ID '+uid)));
        sessions.forEach(function(s){
          var out='—', sid=(s&&s.id!=null)?s.id:'';
          var row=cellGet(cells, uid); var mark=row?cellGet(row, sid):undefined;
          if (mark && (mark.status || mark.statusid)) { out = mark.status || String(mark.statusid); }
          var $td=$('<td>').text(out).attr({'data-type':'att','data-userid':uid,'data-itemid':sid});
          var tooltipParts = [];
          if (mark && mark.comment) { tooltipParts.push('Преподаватель: '+String(mark.comment)); $td.addClass('cd-has-comment'); }
          if (mark && mark.cdnote)   { var pr = mark.cdpriority || 0; tooltipParts.push('Куратор: '+String(mark.cdnote)); $td.addClass('cd-has-cdnote').attr('data-cdnote', String(mark.cdnote)).attr('data-cdpriority', pr).addClass('cd-prio-'+pr); }
          if (tooltipParts.length) { $td.attr('data-tooltip', tooltipParts.join('\n')); }
          // If there is a teacher comment, insert an inline chat SVG for robustness
          if (mark && mark.comment) {
            var svgChat = '<svg class="cd-svg-chat" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 6h-18v12h4v4l4-4h10z" fill="#6b7280"/></svg>';
            var chatWrapper = '<div class="cd-marker-abs cd-marker-chat">'+svgChat+'</div>';
            var chatInline = '<span class="cd-inline-marker chat" aria-hidden="true">💬</span>';
            $td.append(chatWrapper).append(chatInline);
            // guaranteed inline chat marker
            $td.append('<span class="cd-inline-fallback-visible chat" aria-hidden="true">💬</span>');
          }
          $tr.append($td);
        });
        $tbody.append($tr);
      });

      $table.append($thead).append($tbody);
  // payload.cancomment indicates whether current user can add/edit notes
  $('#cd-attendance').empty().append($table).data('cancomment', !!(payload && payload.cancomment));
      $('#kpi-att').text((typeof kpi==='number'?kpi.toFixed(1):'0.0')+'%');
    }catch(e){ console.error('renderAttendanceMatrix fail', e); $('#cd-attendance').text(t('no_data')); }
  }

  function fillCourseSelect(list){
    var $crs=$('#cd-course'); if(!$crs.length) return;
    $crs.empty().append($('<option>').val('').text('—'));
    (list||[]).forEach(function(o){ var id=String(o&&o.id!=null?o.id:''); var label=(o&&(o.fullname||o.name))?(o.fullname||o.name):('ID '+id); $crs.append($('<option>').attr('value',id).text(label)); });
    $('#cd-apply').prop('disabled', $crs.children('option').length<=1);
  }

  function fillInitLists(data){
    try{
      var cats=(data&&Array.isArray(data.catsList))?data.catsList:[]; var $cat=$('#cd-category'), $btn=$('#cd-apply');
      if ($btn.length && !$btn.data('label-apply')) { $btn.data('label-apply', $btn.text() || t('apply')); }
      if ($cat.length){ $cat.empty().append($('<option>').val('').text('—')); cats.forEach(function(c){ $cat.append($('<option>').attr('value',String(c.id)).text(String(c.name||''))); }); }
      fillCourseSelect([]);
    }catch(e){ console.error('init parse fail', e, data); }
  }

  function bindUi(){
    $(document).on('click', '.cd-tab', function(e){ e.preventDefault(); var tab=$(this).data('tab'); if(!tab) return; $('.cd-tab').removeClass('active'); $(this).addClass('active'); $('.cd-tabpanel').removeClass('active'); $('#tab-'+tab).addClass('active'); });
    $(document).on('change', 'input[type=\"date\"]', function(){ var $el=$(this); $el.data('ts', dateToTimestamp($el.val())); });

    $(document).on('change', '#cd-category', function(){
      var catid=parseInt(String($(this).val()||'0'),10)||0;
      if(!catid){ fillCourseSelect([]); return; }
      $('#cd-course').empty().append($('<option>').text(t('loading')));
      api('get_courses',{categoryid:catid}).done(function(d){ fillCourseSelect((d&&d.coursesList)||[]); }).fail(function(){ fillCourseSelect([]); });
    });

    $(document).on('change', '#cd-course', function(){
      var cid=parseInt(String($(this).val()||'0'),10)||0; var $grp=$('#cd-group');
      CURRENT.courseid = cid; $('#cd-apply').prop('disabled', !cid);
      if(!$grp.length) return;
      if(!cid){ $grp.empty().append($('<option>').val('').text('—')); return; }
      api('get_groups',{courseid:cid}).done(function(d){
        var list=(d&&Array.isArray(d.groups))?d.groups:[];
        $grp.empty().append($('<option>').val('').text('—'));
        list.forEach(function(g){ var id=String(g&&g.id!=null?g.id:''); var nm=(g&&g.name)?g.name:('ID '+id); $grp.append($('<option>').attr('value',id).text(nm)); });
      }).fail(function(){ $grp.empty().append($('<option>').val('').text('—')); });
    });

    $(document).on('change', '#cd-group', function(){ CURRENT.groupid = parseInt(String($(this).val()||'0'),10)||0; });

    $(document).on('click', '#cd-apply', function(){
      var cid=parseInt(String($('#cd-course').val()||'0'),10)||0;
      var gid=parseInt(String($('#cd-group').val()||'0'),10)||0;
      CURRENT.courseid=cid; CURRENT.groupid=gid;
      var from=Number($('#cd-from').data('ts')||0)||0;
      var to  =Number($('#cd-to').data('ts')||2147483647)||2147483647;
      if(!cid) return;
      setLoading(true);
      var p1=api('grades_by_group',{courseid:cid, groupid:gid, from:from, to:to});
      var p2=api('attendance_by_group',{courseid:cid, groupid:gid, from:from, to:to});
      $.when(p1,p2).done(function(gr,att){
        try{ renderGradesMatrix(gr[0]||{});}catch(e){ console.error(e); $('#cd-grades').text(t('no_data')); }
        try{ renderAttendanceMatrix(att[0]||{});}catch(e){ console.error(e); $('#cd-attendance').text(t('no_data')); }
      }).fail(function(){
        $('#cd-grades').text(t('no_data'));
        $('#cd-attendance').text(t('no_data'));
      }).always(function(){ setLoading(false); });
    });

    $(document).on('dblclick', '#cd-attendance td, #cd-grades td', function(){
      var $cell=$(this), type=$cell.attr('data-type'); if(!type) return;
      var $table = $cell.closest('#cd-attendance, #cd-grades');
      var cancomment = !!($table.data('cancomment'));
      if (!cancomment) return; // user cannot add/edit notes
      var uid=parseInt(String($cell.attr('data-userid')||'0'),10)||0;
      var iid=parseInt(String($cell.attr('data-itemid')||'0'),10)||0;
      var existing=$cell.attr('data-cdnote')||'';
      openNoteEditor({type:type, userid:uid, itemid:iid, current:existing, $cell:$cell});
    });

    // custom tooltip using data-tooltip
    var $tt;
    $(document).on('mouseenter', '#cd-attendance td[data-tooltip], #cd-grades td[data-tooltip]', function(e){
      var $cell = $(this); var txt = $cell.attr('data-tooltip') || '';
      if (!txt) return;
      if ($tt && $tt.length) $tt.remove();
      $tt = $('<div class="cd-tooltip">').text(txt);
      $('body').append($tt);
      var pos = $cell.offset(); var cw = $cell.outerWidth(); var ch = $cell.outerHeight();
      var top = pos.top - $tt.outerHeight() - 8; if (top < 8) top = pos.top + ch + 8;
      var left = pos.left + cw - $tt.outerWidth(); if (left < 8) left = pos.left;
      $tt.css({ top: top+'px', left: left+'px' });
    }).on('mouseleave', '#cd-attendance td[data-tooltip], #cd-grades td[data-tooltip]', function(){ if ($tt && $tt.length) { $tt.remove(); $tt=null; } });
  }

  $(function(){
    $('input[type=\"date\"]').each(function(){ var $el=$(this); if($el.val()){ $el.data('ts', dateToTimestamp($el.val())); } });
    api('init', {}).done(fillInitLists).fail(function(xhr){ console.error('init fail', xhr && xhr.status); });
    bindUi();
  });

})(jQuery);
