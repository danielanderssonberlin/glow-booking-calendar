<?php
/**
 * Shared frontend calendar renderer to avoid duplication between shortcode and AJAX.
 */

if (!function_exists('glowbc_get_entries_for_month_frontend')) {
    function glowbc_get_entries_for_month_frontend($calendar_id, $month) {
        global $wpdb; $table = $wpdb->prefix.'glow_bookings';
        $start_date = date('Y-m-01', strtotime($month));
        $end_date   = date('Y-m-t', strtotime($month));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT start_date, end_date, fields FROM {$table} WHERE calendar_id = %d AND start_date <= %s AND end_date >= %s",
            $calendar_id, $end_date.' 23:59:59', $start_date.' 00:00:00'
        ), ARRAY_A);

        $booked = [];
        $changeover = [];

        foreach($results as $r) {
            $fields = json_decode($r['fields'] ?? '[]', true) ?: [];
            $availability = $fields['availability'] ?? '';

            $current = strtotime($r['start_date']);
            $end     = strtotime($r['end_date']);

            while($current <= $end) {
                $day = date('Y-m-d', $current);
                if ($availability === 'gebucht') {
                    $booked[] = $day;
                } elseif ($availability === 'changeover1') {
                    $changeover['changeover1'][] = $day;
                } elseif ($availability === 'changeover2') {
                    $changeover['changeover2'][] = $day;
                }
                $current = strtotime('+1 day', $current);
            }
        }

        return [
            'booked' => $booked,
            'changeover' => $changeover,
        ];
    }
}

if (!function_exists('glowbc_render_month_table_frontend')) {
    function glowbc_render_month_table_frontend($calendar_id, $month) {
        $entries = glowbc_get_entries_for_month_frontend($calendar_id, $month);
        setlocale(LC_TIME, 'de_DE.UTF-8');

        $start = new DateTime($month . '-01');
        $days_in_month = $start->format('t');
        $first_day_week = (int)$start->format('N'); // Mon=1
        $isoMonth = $start->format('Y-m');

        $html = '<table class="glowbc-calendar" data-month="'.$isoMonth.'">';
        // thead with nav
        $html .= '<thead>';
        $html .= '<tr class="glowbc-calendar-nav">';
        $html .= '<th><button class="glowbc-prev-month" data-calendar-id="' . (int)$calendar_id . '" data-month="'.$isoMonth.'">‹</button></th>';
        $html .= '<th colspan="5">' . strftime('%B %Y', $start->getTimestamp()) . '</th>';
        $html .= '<th><button class="glowbc-next-month" data-calendar-id="' . (int)$calendar_id . '" data-month="'.$isoMonth.'">›</button></th>';
        $html .= '</tr>';
        // weekdays
        $html .= '<tr><th>Mo</th><th>Di</th><th>Mi</th><th>Do</th><th>Fr</th><th>Sa</th><th>So</th></tr>';
        $html .= '</thead>';

        $html .= '<tbody><tr>';
        // empty cells at month start
        for ($i=1; $i<$first_day_week; $i++) $html .= '<td></td>';

        for ($day=1; $day<=$days_in_month; $day++) {
            $date = $start->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
            if (in_array($date, $entries['booked'])) {
                $status = 'status-gebucht';
            } elseif (in_array($date, $entries['changeover']['changeover1'] ?? [])) {
                $status = 'status-changeover1';
            } elseif (in_array($date, $entries['changeover']['changeover2'] ?? [])) {
                $status = 'status-changeover2';
            } else {
                $status = 'status-frei';
            }
            $html .= '<td class="' . $status . '" data-date="' . esc_attr($date) . '">' . $day . '</td>';
            if (($day + $first_day_week - 1) % 7 == 0) $html .= '</tr><tr>';
        }

        // trailing empty cells
        $last_day_week = ($first_day_week + $days_in_month - 1) % 7;
        if ($last_day_week != 0) {
            for ($i=$last_day_week; $i<7; $i++) $html .= '<td></td>';
        }
        $html .= '</tr></tbody></table>';

        return $html;
    }
}

if (!function_exists('glowbc_render_calendar_html')) {
    function glowbc_render_calendar_html($calendar_id, $month) {
        // render two consecutive months
        $start = DateTime::createFromFormat('Y-m', $month);
        if(!$start) $start = new DateTime('first day of this month');
        $month1 = $start->format('Y-m');
        $month2 = (clone $start)->modify('+1 month')->format('Y-m');

        $html = '<div class="glowbc-calendar-wrapper">';
        $html .= glowbc_render_month_table_frontend($calendar_id, $month1);
        $html .= glowbc_render_month_table_frontend($calendar_id, $month2);
        $html .= '</div>';

        // Legend
        $html .= '<div class="glowbc-legend-frontend">';
        $html .= '<span><i class="legend-box status-gebucht"></i> gebucht</span>';
        $html .= '<span><i class="legend-box status-frei"></i> frei</span>';
        $html .= '<span style="font-size:11px;color:#666;">Hinweis: changeover-Tage sind anklickbar (buchbar).</span>';
        $html .= '</div>';

        return $html;
    }
}