<?php
require_once __DIR__.'/render.php';

add_shortcode('glowbc_calendar', function($atts){
    $atts = shortcode_atts([
        'id' => 1,
    ], $atts, 'glowbc_calendar');

    $calendar_id = intval($atts['id']);

    ob_start();
    ?>
    <div class="glowbc-request">
        <div class="glowbc-frontend-calendar" data-calendar-id="<?php echo esc_attr($calendar_id); ?>"></div>

        <form id="glowbc-request-form" class="glowbc-request-form">
            <input type="hidden" name="action" value="glowbc_create_request" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('glowbc-nonce')); ?>" />
            <input type="hidden" name="calendar_id" value="<?php echo esc_attr($calendar_id); ?>" />
            <input type="hidden" name="start_date" id="glowbc-start-date" />
            <input type="hidden" name="end_date" id="glowbc-end-date" />

            <label class="full">Vorname*<br><input type="text" name="first_name" required></label>
            <label class="full">Nachname*<br><input type="text" name="last_name" required></label>
            <label class="full">E-Mail*<br><input type="email" name="email" required></label>
            <label class="full">Straße<br><input type="text" name="street"></label>
            <label class="full">PLZ / Ort<br><input type="text" name="city"></label>

            <label class="full">Anzahl der Personen*<br>
                <select name="persons" required>
                    <?php for($i=1;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </label>

            <label class="full">Anzahl der Kinder (bis inkl. 6 Jahre)<br>
                <select name="kids_0_6">
                    <?php for($i=0;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </label>

            <label class="full">Anzahl der Kinder (7–16 Jahre)<br>
                <select name="kids_7_16">
                    <?php for($i=0;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </label>

            <label class="full" style="grid-column:1 / -1;">Ihre Nachricht<br>
                <textarea name="message" rows="4" style="width:100%"></textarea>
            </label>

            <div style="grid-column:1 / -1; display:flex; gap:12px; align-items:center;">
                <button type="submit" class="button">Anfragen</button>
                <span class="glowbc-request-status"></span>
                <span class="glowbc-selected-range-label"></span>
            </div>
        </form>
    </div>
    <script>
    (function($){
        // Range selection on frontend calendar
        function ymd(d){
            let yyyy = d.getFullYear();
            let mm = String(d.getMonth()+1).padStart(2,'0');
            let dd = String(d.getDate()).padStart(2,'0');
            return `${yyyy}-${mm}-${dd}`;
        }
        function parseYmd(s){
            let [y, m, d] = s.split('-').map(Number);
            return new Date(y, m-1, d);
        }
        function markSelection($calendar){
            var startDate = $calendar.data('startDate');
            var endDate = $calendar.data('endDate');
            $calendar.find('td').removeClass('glowbc-selected glowbc-inrange');
            if(!startDate) return;
            var s = ymd(startDate);
            var e = endDate ? ymd(endDate) : s;
            var cur = new Date(startDate.getTime());
            $calendar.find('td[data-date="'+s+'"]').addClass('glowbc-selected');
            while(ymd(cur) < e){
                cur.setDate(cur.getDate()+1);
                $calendar.find('td[data-date="'+ymd(cur)+'"]').addClass('glowbc-inrange');
            }
            $('#glowbc-start-date').val(s);
            $('#glowbc-end-date').val(e);
            var sDisp = startDate.toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'numeric'});
            var eDateObj = endDate ? endDate : startDate;
            var eDisp = eDateObj.toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'numeric'});
            $('.glowbc-selected-range-label').text('Ausgewählt: '+sDisp+' – '+eDisp);
        }
        $(document).on('click', '.glowbc-frontend-calendar td.status-frei, .glowbc-frontend-calendar td.status-changeover1, .glowbc-frontend-calendar td.status-changeover2', function(){
            var $calendar = $(this).closest('.glowbc-frontend-calendar');
            var d = $(this).data('date');
            if(!d) return;
            var dt = parseYmd(d);
            var startDate = $calendar.data('startDate') || null;
            var endDate   = $calendar.data('endDate') || null;
            if(!startDate){ startDate = dt; endDate = null; }
            else if(!endDate){ if(dt < startDate){ var tmp=startDate; startDate=dt; endDate=tmp; } else { endDate = dt; } }
            else { startDate = dt; endDate = null; }
            $calendar.data('startDate', startDate);
            $calendar.data('endDate', endDate);
            markSelection($calendar);
        });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
});

// ===== Frontend Booking Request Shortcode =====
add_shortcode('glowbc_request_form', function($atts){
    $atts = shortcode_atts([
        'id' => 1,
    ], $atts, 'glowbc_request_form');

    $calendar_id = intval($atts['id']);

    ob_start();
    ?>
    <div class="glowbc-request">
        <div class="glowbc-frontend-calendar" data-calendar-id="<?php echo esc_attr($calendar_id); ?>"></div>

        <form id="glowbc-request-form" class="glowbc-request-form">
            <input type="hidden" name="action" value="glowbc_create_request" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('glowbc-nonce')); ?>" />
            <input type="hidden" name="calendar_id" value="<?php echo esc_attr($calendar_id); ?>" />
            <input type="hidden" name="start_date" id="glowbc-start-date" />
            <input type="hidden" name="end_date" id="glowbc-end-date" />

            <label class="full">Vorname*<br><input type="text" name="first_name" required></label>
            <label class="full">Nachname*<br><input type="text" name="last_name" required></label>
            <label class="full">E-Mail*<br><input type="email" name="email" required></label>
            <label class="full">Straße<br><input type="text" name="street"></label>
            <label class="full">PLZ / Ort<br><input type="text" name="city"></label>

            <label class="full">Anzahl der Personen*<br>
                <select name="persons" required>
                    <?php for($i=1;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </label>

            <label class="full">Anzahl der Kinder (bis inkl. 6 Jahre)<br>
                <select name="kids_0_6">
                    <?php for($i=0;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </label>

            <label class="full">Anzahl der Kinder (7–16 Jahre)<br>
                <select name="kids_7_16">
                    <?php for($i=0;$i<=10;$i++): ?><option value="<?php echo $i; ?>"><?php echo $i; ?></option><?php endfor; ?>
                </select>
            </label>

            <label class="full" style="grid-column:1 / -1;">Ihre Nachricht<br>
                <textarea name="message" rows="4" style="width:100%"></textarea>
            </label>

            <div style="grid-column:1 / -1; display:flex; gap:12px; align-items:center;">
                <button type="submit" class="button">Anfragen</button>
                <span class="glowbc-request-status"></span>
                <span class="glowbc-selected-range-label"></span>
            </div>
        </form>
    </div>
    <script>
    (function($){
        // Range selection on frontend calendar
        function ymd(d){
            let yyyy = d.getFullYear();
            let mm = String(d.getMonth()+1).padStart(2,'0');
            let dd = String(d.getDate()).padStart(2,'0');
            return `${yyyy}-${mm}-${dd}`;
        }
        function parseYmd(s){
            let [y, m, d] = s.split('-').map(Number);
            return new Date(y, m-1, d);
        }
        function markSelection($calendar){
            var startDate = $calendar.data('startDate');
            var endDate = $calendar.data('endDate');
            $calendar.find('td').removeClass('glowbc-selected glowbc-inrange');
            if(!startDate) return;
            var s = ymd(startDate);
            var e = endDate ? ymd(endDate) : s;
            var cur = new Date(startDate.getTime());
            $calendar.find('td[data-date="'+s+'"]').addClass('glowbc-selected');
            while(ymd(cur) < e){
                cur.setDate(cur.getDate()+1);
                $calendar.find('td[data-date="'+ymd(cur)+'"]').addClass('glowbc-inrange');
            }
            $('#glowbc-start-date').val(s);
            $('#glowbc-end-date').val(e);
            var sDisp = startDate.toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'numeric'});
            var eDateObj = endDate ? endDate : startDate;
            var eDisp = eDateObj.toLocaleDateString('de-DE', {day:'2-digit', month:'2-digit', year:'numeric'});
            $('.glowbc-selected-range-label').text('Ausgewählt: '+sDisp+' – '+eDisp);
        }
        $(document).on('click', '.glowbc-frontend-calendar td.status-frei, .glowbc-frontend-calendar td.status-changeover1, .glowbc-frontend-calendar td.status-changeover2', function(){
            var $calendar = $(this).closest('.glowbc-frontend-calendar');
            var d = $(this).data('date');
            if(!d) return;
            var dt = parseYmd(d);
            var startDate = $calendar.data('startDate') || null;
            var endDate   = $calendar.data('endDate') || null;
            if(!startDate){ startDate = dt; endDate = null; }
            else if(!endDate){ if(dt < startDate){ var tmp=startDate; startDate=dt; endDate=tmp; } else { endDate = dt; } }
            else { startDate = dt; endDate = null; }
            $calendar.data('startDate', startDate);
            $calendar.data('endDate', endDate);
            markSelection($calendar);
        });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
});

?>