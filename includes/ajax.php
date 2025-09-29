<?php
require_once __DIR__.'/render.php';

// ===== AJAX: Create Request (Public) =====
function glowbc_ajax_create_request(){
    check_ajax_referer('glowbc-nonce', 'nonce');

    global $wpdb; $table = $wpdb->prefix.'glow_bookings';
    $calendar_id = intval($_POST['calendar_id'] ?? 1);

    $start_raw = sanitize_text_field($_POST['start_date'] ?? '');
    $end_raw   = sanitize_text_field($_POST['end_date'] ?? '');

    if (!$start_raw || !$end_raw || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_raw)) {
        wp_send_json_error(['message' => 'Bitte gültigen Zeitraum wählen.']);
    }

    $start = $start_raw.' 00:00:00';
    $end   = $end_raw.' 23:59:59';

    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name  = sanitize_text_field($_POST['last_name'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $street     = sanitize_text_field($_POST['street'] ?? '');
    $city       = sanitize_text_field($_POST['city'] ?? '');
    $persons    = intval($_POST['persons'] ?? 1);
    $kids_0_6   = intval($_POST['kids_0_6'] ?? 0);
    $kids_7_16  = intval($_POST['kids_7_16'] ?? 0);
    $message    = wp_kses_post($_POST['message'] ?? '');

    if(!$first_name || !$last_name || !$email || $persons < 1){
        wp_send_json_error(['message' => 'Bitte Pflichtfelder ausfüllen.']);
    }

    $fields = [
        'type' => 'request',
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'street' => $street,
        'city' => $city,
        'persons' => $persons,
        'kids_0_6' => $kids_0_6,
        'kids_7_16' => $kids_7_16,
        'message' => $message,
        'availability' => '',
        'description' => '',
    ];

    // Simple conflict check: only count accepted bookings with availability "gebucht"
    $conflict = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE calendar_id=%d AND status='accepted' AND fields LIKE %s AND NOT (end_date < %s OR start_date > %s)",
        $calendar_id, '%"availability":"gebucht"%', $start, $end
    ));
    if(intval($conflict) > 0){
        wp_send_json_error(['message' => 'Der gewÃ¤hlte Zeitraum ist bereits (teilweise) belegt.']);
    }

    $wpdb->insert($table, [
        'calendar_id' => $calendar_id,
        'form_id'     => null,
        'start_date'  => $start,
        'end_date'    => $end,
        'fields'      => wp_json_encode($fields),
        'status'      => 'pending',
        'is_read'     => 0,
    ]);

    // E-Mail-Benachrichtigung senden
    $cal_table = $wpdb->prefix . 'glow_calendars';
    $calendar = $wpdb->get_row($wpdb->prepare("SELECT name, notification_email FROM $cal_table WHERE id = %d", $calendar_id), ARRAY_A);
    if ($calendar && !empty($calendar['notification_email'])) {
        $subject = 'Neue Buchungsanfrage für ' . $calendar['name'];
        $message = "Neue Anfrage erhalten:\n\n";
        $message .= "Name: " . $fields['first_name'] . ' ' . $fields['last_name'] . "\n";
        $message .= "E-Mail: " . $fields['email'] . "\n";
        $message .= "Zeitraum: " . date('d.m.Y', strtotime($start)) . ' - ' . date('d.m.Y', strtotime($end)) . "\n";
        $message .= "Personen: " . $fields['persons'] . "\n";
        if ($fields['kids_0_6'] > 0) $message .= "Kinder 0-6: " . $fields['kids_0_6'] . "\n";
        if ($fields['kids_7_16'] > 0) $message .= "Kinder 7-16: " . $fields['kids_7_16'] . "\n";
        if (!empty($fields['message'])) $message .= "Nachricht: " . $fields['message'] . "\n";
        $message .= "\nBitte im Admin-Bereich überprüfen: " . admin_url('admin.php?page=glow-booking-calendar&cal=' . $calendar_id);

        wp_mail($calendar['notification_email'], $subject, $message);
    }

    wp_send_json_success(['message' => 'Anfrage gesendet']);
}
add_action('wp_ajax_nopriv_glowbc_create_request', 'glowbc_ajax_create_request');
add_action('wp_ajax_glowbc_create_request', 'glowbc_ajax_create_request');

// ===== AJAX: Get Calendar (Frontend) =====
function glowbc_ajax_get_calendar(){
    check_ajax_referer('glowbc-nonce', 'nonce');
    $calendar_id = intval($_POST['calendar_id'] ?? 1);
    $month = sanitize_text_field($_POST['month'] ?? date('Y-m'));
    $html = glowbc_render_calendar_html($calendar_id, $month);
    wp_send_json_success(['html'=>$html]);
}
add_action('wp_ajax_nopriv_glowbc_get_calendar', 'glowbc_ajax_get_calendar');
add_action('wp_ajax_glowbc_get_calendar', 'glowbc_ajax_get_calendar');

// ===== AJAX: Get Admin Calendar =====
function glowbc_ajax_get_admin_calendar(){
    check_ajax_referer('glowbc-nonce', 'nonce');
    if(!current_user_can('manage_options')){ wp_send_json_error(['message'=>'Unauthorized'], 403); }

    $calendar_id = intval($_POST['calendar_id'] ?? 1);
    $year = isset($_POST['y']) ? max(1970, intval($_POST['y'])) : intval(current_time('Y'));
    $month = isset($_POST['m']) ? min(12, max(1, intval($_POST['m']))) : intval(current_time('m'));

    // Render the calendar HTML similar to admin page
    ob_start();
    // Include the calendar rendering code from glow-booking-calendar.php
    // But since it's a separate file, better to create a helper function
    // For now, inline the rendering
    global $wpdb;
    $table = $wpdb->prefix.'glow_bookings';
    $days = []; // get_month_days logic
    $date = new DateTimeImmutable("$year-$month-01 00:00:00");
    $days_in_month = (int)$date->format('t');
    for ($d = 1; $d <= $days_in_month; $d++) {
        $days[] = $date->setDate((int)$date->format('Y'), (int)$date->format('m'), $d);
    }

    $statusMap = []; // get_month_status_map logic
    $first = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $last = sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $days_in_month);
    
    // Verwende die gleiche Logik wie das Frontend: Buchungen die den Monat überschneiden
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT start_date, end_date, fields FROM {$table} WHERE calendar_id = %d AND start_date <= %s AND end_date >= %s",
        $calendar_id, $last, $first
    ), ARRAY_A);
    
    // Iteriere durch alle Tage jeder Buchung (wie im Frontend)
    foreach ($rows as $r) {
        $fields = !empty($r['fields']) ? json_decode($r['fields'], true) : [];
        $availability = $fields['availability'] ?? '';
        
        if ($availability) {
            $current = strtotime($r['start_date']);
            $end = strtotime($r['end_date']);
            
            while ($current <= $end) {
                $dateKey = date('Y-m-d', $current);
                // Nur Tage im aktuellen Monat berücksichtigen
                if ($dateKey >= sprintf('%04d-%02d-01', $year, $month) && 
                    $dateKey <= sprintf('%04d-%02d-%02d', $year, $month, $days_in_month)) {
                    $statusMap[$dateKey] = $availability;
                }
                $current = strtotime('+1 day', $current);
            }
        }
    }

    // Navigation
    $prevMonth = $month - 1; $prevYear = $year; if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
    $nextMonth = $month + 1; $nextYear = $year; if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
    $monthLabel = date_i18n('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    echo '<div class="glowbc-calendar">';
    echo '<div class="glowbc-cal-header">';
    echo '<button class="glowbc-nav prev" data-year="'.$prevYear.'" data-month="'.$prevMonth.'" aria-label="Voriger Monat">&#9664;</button>';
    echo '<div class="glowbc-month-label">'.esc_html($monthLabel).'</div>';
    echo '<button class="glowbc-nav next" data-year="'.$nextYear.'" data-month="'.$nextMonth.'" aria-label="Nächster Monat">&#9654;</button>';
    echo '</div>';

    // Weekdays
    $weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    echo '<div class="glowbc-grid glowbc-weekdays">';
    foreach ($weekdays as $wd) { echo '<div class="glowbc-weekday">'.esc_html($wd).'</div>'; }
    echo '</div>';

    // Days
    $firstDow = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('N');
    $totalDays = $days_in_month;
    echo '<div class="glowbc-grid glowbc-days">';
    for ($i=1; $i<$firstDow; $i++) { echo '<div class="glowbc-day empty"></div>'; }
    for ($d=1; $d<=$totalDays; $d++) {
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $availability = $statusMap[$dateKey] ?? '';
        $cls = 'glowbc-day';
        if ($availability) { $cls .= ' status-' . esc_attr($availability); }
        echo '<div class="'.$cls.'" data-date="'.$dateKey.'"><span class="num">'.$d.'</span></div>';
        if (($d + $firstDow - 1) % 7 == 0) echo '</div><div class="glowbc-grid glowbc-days">';
    }
    echo '</div>';

    echo '<div class="glowbc-legend">';
    echo '<span><i class="legend-box status-gebucht"></i> gebucht</span>';
    echo '<span><i class="legend-box status-changeover1"></i> changeover 1</span>';
    echo '<span><i class="legend-box status-changeover2"></i> changeover 2</span>';
    echo '</div>';

    // Bulk edit
    echo '<div class="glowbc-bulk-edit">';
    echo '<h2>Bulk Edit Verfügbarkeit</h2>';
    echo '<p style="margin-top:6px;color:#50575e;">Tipp: Start- und Enddatum kannst du auch direkt im Kalender auswählen.</p>';
    echo '<form id="glowbc-bulk-form" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">';
    echo '<input type="hidden" name="calendar_id" value="'.esc_attr($calendar_id).'" />';
    echo '<label class="full">Startdatum<br><input type="date" name="start_date" required></label>';
    echo '<label class="full">Enddatum<br><input type="date" name="end_date" required></label>';
    echo '<label class="full">Verfügbarkeit<br><select name="availability" required>';
    echo '<option value="">— bitte wählen —</option>';
    echo '<option value="verfuegbar">verfügbar</option>';
    echo '<option value="gebucht">gebucht</option>';
    echo '</select></label>';
    echo '<label class="full">Beschreibung<br><input type="text" name="description" placeholder="Beschreibung..."></label>';
    echo '<button type="submit" class="button button-primary">Speichern</button>';
    echo '<div class="glowbc-bulk-status" style="color:green;"></div>';
    echo '</form></div>';

    echo '</div>'; // glowbc-calendar

    $html = ob_get_clean();
    wp_send_json_success(['html'=>$html]);
}
add_action('wp_ajax_glowbc_get_admin_calendar', 'glowbc_ajax_get_admin_calendar');

// ===== AJAX: Accept Request (Admin) =====
function glowbc_ajax_accept_request(){
    check_ajax_referer('glowbc-nonce', 'nonce');
    if(!current_user_can('manage_options')){ wp_send_json_error(['message'=>'Unauthorized'], 403); }

    global $wpdb; $table = $wpdb->prefix.'glow_bookings';
    $id = intval($_POST['id'] ?? 0);
    if(!$id){ wp_send_json_error(['message'=>'Ungültige Anfrage-ID']); }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if(!$row){ wp_send_json_error(['message'=>'Anfrage nicht gefunden']); }

    $calendar_id = intval($row['calendar_id'] ?? 1);
    $start = new DateTimeImmutable(substr($row['start_date'],0,10));
    $end   = new DateTimeImmutable(substr($row['end_date'],0,10));
    $fields = json_decode($row['fields'] ?? '{}', true) ?: [];
    $desc = trim(($fields['first_name'] ?? '').' '.($fields['last_name'] ?? '')).' ('.$row['start_date'].'–'.$row['end_date'].')';

    // Create bookings for each day
    for($d=$start; $d <= $end; $d = $d->modify('+1 day')){
        $ymd = $d->format('Y-m-d');
        $start_dt = $ymd.' 00:00:00';
        $end_dt   = $ymd.' 23:59:59';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE calendar_id=%d AND start_date >= %s AND end_date <= %s ORDER BY id DESC LIMIT 1",
            $calendar_id, $start_dt, $end_dt
        ));
        $isStart = ($ymd === $start->format('Y-m-d'));
        $isEnd   = ($ymd === $end->format('Y-m-d'));
        if ($isStart && $isEnd) {
            $availability = 'gebucht';
        } elseif ($isStart) {
            $availability = 'changeover1';
        } elseif ($isEnd) {
            $availability = 'changeover2';
        } else {
            $availability = 'gebucht';
        }
        $data = [
            'calendar_id' => $calendar_id,
            'form_id'     => null,
            'start_date'  => $start_dt,
            'end_date'    => $end_dt,
            'fields'      => wp_json_encode(['availability'=>$availability,'description'=>$desc]),
            'status'      => 'accepted',
            'is_read'     => 1,
        ];
        if($existing){ $wpdb->update($table, $data, ['id'=>intval($existing)]); }
        else { $wpdb->insert($table, $data); }
    }

    // Mark request as accepted
    $wpdb->update($table, ['status'=>'accepted','is_read'=>1], ['id'=>$id]);

    wp_send_json_success(['message'=>'Anfrage akzeptiert und im Kalender eingetragen.']);
}
add_action('wp_ajax_glowbc_accept_request', 'glowbc_ajax_accept_request');

function glowbc_ajax_delete_request(){
    error_log('Delete Request AJAX gestartet: '.print_r($_POST, true));

    check_ajax_referer('glowbc-nonce', 'nonce');
    if(!current_user_can('manage_options')){
        wp_send_json_error(['message'=>'Unauthorized'], 403);
    }

    global $wpdb;
    $table = $wpdb->prefix.'glow_bookings';
    $id = intval($_POST['id'] ?? 0);
    if(!$id){
        wp_send_json_error(['message'=>'Ungültige Anfrage-ID']);
    }

    // Prüfen, ob der Eintrag existiert
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    if(!$row){
        wp_send_json_error(['message'=>'Anfrage nicht gefunden']);
    }

    // Eintrag löschen
    $deleted = $wpdb->delete($table, ['id'=>$id], ['%d']);

    if($deleted !== false){
        wp_send_json_success(['message'=>'Anfrage erfolgreich gelöscht.']);
    } else {
        wp_send_json_error(['message'=>'Fehler beim Löschen der Anfrage.']);
    }
}
add_action('wp_ajax_glowbc_delete_request', 'glowbc_ajax_delete_request');


?>