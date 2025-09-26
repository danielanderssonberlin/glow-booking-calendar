jQuery(function($){
  $('.glowbc-frontend-calendar').each(function(){
    var $wrap = $(this);
    var id = $wrap.data('calendar-id');
    var now = new Date();
    var month = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0');

    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_get_calendar',
      nonce: GlowBC.nonce,
      calendar_id: id,
      month: month
    }, function(res){
      if(res.success){
        $wrap.html(res.data.html);
      } else {
        $wrap.html('<p>Kalender konnte nicht geladen werden.</p>');
      }
    });
  });

  // Event-Delegation für die Pfeile
  $(document).on('click', '.glowbc-prev-month, .glowbc-next-month', function(){
    var $btn = $(this);
    var calendarId = $btn.data('calendar-id');
    // Nutze data-month statt Text-Parsen
    var cur = $btn.attr('data-month'); // YYYY-MM
    if(!cur){ cur = $btn.closest('table').attr('data-month'); }
    if(!cur){ return; }
    var parts = cur.split('-');
    var y = parseInt(parts[0],10), m = parseInt(parts[1],10) - 1;
    var date = new Date(y, m, 1);

    if($btn.hasClass('glowbc-prev-month')){ date.setMonth(date.getMonth() - 1); }
    else { date.setMonth(date.getMonth() + 1); }

    var monthStr = date.toISOString().slice(0,7);
    var $container = $('.glowbc-frontend-calendar[data-calendar-id="'+calendarId+'"]');
    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_get_calendar',
      nonce: GlowBC.nonce,
      calendar_id: calendarId,
      month: monthStr
    }, function(res){
      if(res.success){
        $container.html(res.data.html);
      } else {
        $container.html('<p>Kalender konnte nicht geladen werden.</p>');
      }
    });
  });

  // AJAX submit für das Anfrageformular
  $(document).on('submit', '#glowbc-request-form', function(e){
    e.preventDefault();
    var $form = $(this);
    var $status = $form.find('.glowbc-request-status');
    var $btn = $form.find('button[type="submit"]');
    $status.text('Sende Anfrage...');
    $btn.prop('disabled', true);

    // Validierung: Zeitraum gewählt?
    var start = $form.find('input[name="start_date"]').val();
    var end   = $form.find('input[name="end_date"]').val();
    if(!start || !end){
      $status.text('Bitte Zeitraum im Kalender wählen.');
      $btn.prop('disabled', false);
      return;
    }

    $.post(GlowBC.ajaxUrl, $form.serialize(), function(res){
      if(res && res.success){
        $status.text(res.data && res.data.message ? res.data.message : 'Anfrage gespeichert.');
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message : 'Fehler beim Senden der Anfrage.';
        $status.text(msg);
      }
    }).fail(function(){
      $status.text('Netzwerkfehler. Bitte später erneut versuchen.');
    }).always(function(){
      $btn.prop('disabled', false);
    });
  });
});
