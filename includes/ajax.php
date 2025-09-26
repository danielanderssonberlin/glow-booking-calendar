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

    wp_send_json_success(['message' => 'Anfrage gespeichert']);
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
        $availability = $isStart ? 'changeover1' : ($isEnd ? 'changeover2' : 'gebucht');
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
?>