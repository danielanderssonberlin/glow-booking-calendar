(function($){
  const debounce = (fn, ms) => { let t; return function(...args){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,args), ms); }; };

  function apiSave(date, availability, description, done) {
    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_save_day', nonce: GlowBC.nonce, calendar_id: GlowBC.calendarId,
      date, availability, description
    }).done(res => done(null, res)).fail(() => done('Netzwerkfehler'));
  }

  function saveRow($tr) {
    const date = $tr.data('date');
    const availability = $tr.find('.glowbc-availability').val() || '';
    const description = $tr.find('.glowbc-description').val() || '';
    const $status = $tr.find('.glowbc-status');
    $status.text('Speichere …');

    apiSave(date, availability, description, (err, res) => {
      if (err) return $status.text(err);
      if (res && res.success) {
        $tr.attr('data-row-id', res.data.id);
        $status.text('Gespeichert');
        applyCalendarClasses(date, availability);
      } else {
        $status.text((res && res.data && res.data.message) ? res.data.message : 'Fehler');
      }
    });
  }

  jQuery(function($){
    console.log('GlowBC Admin JS loaded');
    console.log('GlowBC object:', typeof GlowBC !== 'undefined' ? GlowBC : 'undefined');
    
    $('#glowbc-bulk-form').on('submit', function(e){
      console.log('Bulk form submitted');
      e.preventDefault();
      
      // Check if GlowBC is available
      if (typeof GlowBC === 'undefined') {
        console.error('GlowBC object not found');
        alert('JavaScript-Fehler: GlowBC object nicht gefunden. Bitte Seite neu laden.');
        return;
      }
      
      var $form = $(this);
      var $status = $form.find('.glowbc-bulk-status');
      
      $status.text('Speichere...').css('color', '#666');
      
      var data = $form.serializeArray();
      data.push({name: 'action', value: 'glowbc_bulk_save'});
      data.push({name: 'nonce', value: GlowBC.nonce});
      
      console.log('Sending AJAX request with data:', data);

      $.post(GlowBC.ajaxUrl, data)
        .done(function(res){
          console.log('AJAX response:', res);
          if(res && res.success){
            $status.text(res.data.message).css('color','green');
            setTimeout(function(){
              location.reload();
            }, 800);
          } else {
            var message = (res && res.data && res.data.message) ? res.data.message : 'Unbekannter Fehler';
            $status.text(message).css('color','red');
          }
        })
        .fail(function(xhr, status, error){
          console.error('AJAX failed:', status, error);
          $status.text('AJAX-Fehler: ' + error).css('color','red');
        });
    });
  });


  function applyCalendarClasses(date, availability) {
    const $cell = $('.glowbc-calendar .glowbc-day[data-date="'+date+'"]').first();
    $cell.removeClass('status-verfuegbar status-gebucht status-changeover1 status-changeover2');
    if (availability) $cell.addClass('status-'+availability);
  }

  // Klick im Kalender:
  // 1) Bereichsauswahl für Bulk-Edit (Start/Ende setzen, visuell markieren)
  // 2) Tabellenzeile zum Datum fokussieren
  $(document).on('click', '.glowbc-calendar .glowbc-day', function(){
    const date = $(this).data('date');
    if (!date) return; // leere Zellen ignorieren

    const $cal = $('.glowbc-calendar').first();
    let selStart = $cal.data('selStart') || null;
    let selEnd   = $cal.data('selEnd') || null;

    // Auswahl-Logik: erster Klick = Start; zweiter Klick = Ende; dritter Klick = neue Auswahl
    if (!selStart || (selStart && selEnd)) {
      selStart = date; selEnd = null;
    } else {
      // nur Start gesetzt -> Ende setzen (automatisch sortieren)
      if (date < selStart) { selEnd = selStart; selStart = date; } else { selEnd = date; }
    }

    $cal.data('selStart', selStart);
    $cal.data('selEnd', selEnd);

    // Visuelle Markierung zurücksetzen
    const $days = $('.glowbc-calendar .glowbc-day');
    $days.removeClass('glowbc-selected glowbc-inrange');

    // Start markieren
    if (selStart) {
      $days.filter('[data-date="'+selStart+'"]').addClass('glowbc-selected');
    }
    // Ende markieren + In-Range hervorheben
    if (selStart && selEnd) {
      $days.filter('[data-date="'+selEnd+'"]').addClass('glowbc-selected');
      $days.each(function(){
        const d = $(this).data('date');
        if (d && d > selStart && d < selEnd) $(this).addClass('glowbc-inrange');
      });
    }

    // Bulk-Formular aktualisieren
    const $form = $('#glowbc-bulk-form');
    if ($form.length) {
      if (selStart) $form.find('input[name="start_date"]').val(selStart);
      if (selEnd)   $form.find('input[name="end_date"]').val(selEnd);
      if (!selEnd)  $form.find('input[name="end_date"]').val('');

      const fmt = (ymd) => { const [y,m,d] = ymd.split('-'); return `${d}.${m}.${y}`; };
      const $label = $('.glowbc-bulk-range');
      if (selStart && selEnd)      $label.text(fmt(selStart) + ' – ' + fmt(selEnd));
      else if (selStart && !selEnd) $label.text(fmt(selStart));
      else                          $label.text('');
    }

    // Tabellenzeile zum geklickten Datum fokussieren (wie zuvor)
    const $row = $('.glowbc-table tr[data-date="'+date+'"]').first();
    if ($row.length) {
      $row[0].scrollIntoView({behavior:'smooth', block:'center'});
      $row.addClass('glowbc-row-focus');
      setTimeout(()=> $row.removeClass('glowbc-row-focus'), 1200);
    }
  });

  // Save-Button
  $(document).on('click', '.glowbc-save', function(){ saveRow($(this).closest('tr')); });

  // Autosave
  $(document).on('change', '.glowbc-availability', function(){ saveRow($(this).closest('tr')); });
  $(document).on('input', '.glowbc-description', debounce(function(){ saveRow($(this).closest('tr')); }, 800));

  // Admin Calendar Navigation
  function loadAdminCalendar(year, month) {
    const $cal = $('.glowbc-calendar').first();
    const $table = $('.glowbc-table').first();
    let calendarId = $cal.data('calendar-id') || 1; // Default to 1 if not found
    
    console.log('Calendar ID found:', calendarId, 'Year:', year, 'Month:', month);
    
    // Load calendar first
    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_get_admin_calendar',
      nonce: GlowBC.nonce,
      calendar_id: calendarId,
      y: year,
      m: month
    }).done(function(res){
      if (res.success) {
        $cal.replaceWith(res.data.html);
        
        // After calendar is loaded, load table with the same calendar_id
        console.log('Loading table for calendar_id:', calendarId);
        $.post(GlowBC.ajaxUrl, {
          action: 'glowbc_get_admin_table',
          nonce: GlowBC.nonce,
          calendar_id: calendarId,
          y: year,
          m: month
        }).done(function(res){
          console.log('Table response:', res);
          if (res.success) {
            console.log('Replacing table with new HTML');
            $table.replaceWith(res.data.html);
            console.log('Table replaced successfully');
          } else {
            console.error('Fehler beim Laden der Tabelle:', res.data.message);
          }
        }).fail(function(){
          console.error('Netzwerkfehler beim Laden der Tabelle');
        });
        
      } else {
        alert(res.data.message);
      }
    }).fail(function(){
      alert('Netzwerkfehler beim Laden des Kalenders');
    });
  }

  $(document).on('click', '.glowbc-nav', function(){
    const year = $(this).data('year');
    const month = $(this).data('month');
    loadAdminCalendar(year, month);
  });

  $(document).on('change', '.glowbc-month-select', function(){
    const ym = $(this).val();
    const [year, month] = ym.split('-').map(Number);
    loadAdminCalendar(year, month);
  });

  // Kalender löschen
  $(document).on('click', '.glowbc-delete-calendar', function(e){
    e.preventDefault();
    const $btn = $(this);
    const calendarId = $btn.data('id');
    const calendarName = $btn.data('name');
    if (!confirm('Sind Sie sicher, dass Sie den Kalender "' + calendarName + '" und alle zugehörigen Buchungen löschen möchten? Diese Aktion kann nicht rückgängig gemacht werden.')) {
      return;
    }
    $btn.prop('disabled', true).text('Lösche...');
    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_delete_calendar',
      nonce: GlowBC.nonce,
      calendar_id: calendarId
    }).done(function(res){
      if (res.success) {
        alert(res.data.message);
        location.reload();
      } else {
        alert(res.data.message);
        $btn.prop('disabled', false).text('Löschen');
      }
    }).fail(function(){
      alert('Netzwerkfehler');
      $btn.prop('disabled', false).text('Löschen');
    });
  });

  // Accept Request
  $(document).on('click', '.glowbc-accept-request', function(e){
    e.preventDefault();
    const $btn = $(this);
    const id = $btn.data('id');
    $btn.prop('disabled', true).text('Annehmen...');
    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_accept_request',
      nonce: GlowBC.nonce,
      id: id
    }).done(function(res){
      if (res.success) {
        alert(res.data.message);
        location.reload();
      } else {
        alert(res.data.message);
        $btn.prop('disabled', false).text('Annehmen');
      }
    }).fail(function(){
      alert('Netzwerkfehler');
      $btn.prop('disabled', false).text('Annehmen');
    });
  });

  // Delete Request
  $(document).on('click', '.glowbc-delete-request', function(e){
    e.preventDefault();
    const $btn = $(this);
    const id = $btn.data('id');
    if (!confirm('Sind Sie sicher, dass Sie diese Anfrage ablehnen möchten?')) {
      return;
    }
    $btn.prop('disabled', true).text('Ablehnen...');
    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_delete_request',
      nonce: GlowBC.nonce,
      id: id
    }).done(function(res){
      if (res.success) {
        alert(res.data.message);
        location.reload();
      } else {
        alert(res.data.message);
        $btn.prop('disabled', false).text('Ablehnen');
      }
    }).fail(function(){
      alert('Netzwerkfehler');
      $btn.prop('disabled', false).text('Ablehnen');
    });
  });
})(jQuery);