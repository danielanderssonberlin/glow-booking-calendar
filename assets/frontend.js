jQuery(function($){
  $('.glowbc-frontend-calendar').each(function(){
    var $wrap = $(this);
    var id = $wrap.data('calendar-id');
    var month = new Date().toISOString().slice(0,7);

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
    var $wrapper = $('.glowbc-frontend-calendar[data-calendar-id="'+calendarId+'"] .glowbc-calendar-wrapper');
    var currentMonth = $wrapper.find('table:first thead th:eq(1)').text(); // mittlerer th = Monatsname
console.log(currentMonth);
    var date = new Date(currentMonth + ' 01');

    if($btn.hasClass('glowbc-prev-month')){
      date.setMonth(date.getMonth() - 1);
    } else {
      date.setMonth(date.getMonth() + 1);
    }

    var monthStr = date.toISOString().slice(0,7);

    $.post(GlowBC.ajaxUrl, {
      action: 'glowbc_get_calendar',
      nonce: GlowBC.nonce,
      calendar_id: calendarId,
      month: monthStr
    }, function(res){
      if(res.success){
        $wrapper.html(res.data.html);
      }
    });
  });
});
