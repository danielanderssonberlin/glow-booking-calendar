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
    $('#glowbc-bulk-form').on('submit', function(e){
      e.preventDefault();
      var $form = $(this);
      var data = $form.serializeArray();
      data.push({name: 'action', value: 'glowbc_bulk_save'});
      data.push({name: 'nonce', value: GlowBC.nonce});

      $.post(GlowBC.ajaxUrl, data, function(res){
        if(res.success){
          $form.find('.glowbc-bulk-status').text(res.data.message).css('color','green');
          setTimeout(()=>location.reload(), 800);
        } else {
          $form.find('.glowbc-bulk-status').text(res.data.message).css('color','red');
        }
      });
    });
  });


  function applyCalendarClasses(date, availability) {
    const $cell = $('.glowbc-calendar .glowbc-day[data-date="'+date+'"]').first();
    $cell.removeClass('status-verfuegbar status-gebucht status-changeover1 status-changeover2');
    if (availability) $cell.addClass('status-'+availability);
  }

  // Klick im Kalender -> entsprechende Tabellenzeile finden & Fokus setzen
  $(document).on('click', '.glowbc-calendar .glowbc-day', function(){
    const date = $(this).data('date');
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
})(jQuery);