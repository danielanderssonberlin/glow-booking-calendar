<?php
/**
 * Plugin Name: Glow Booking Calendar
 * Description: Einfacher Buchungskalender (Backend) mit Tagesstatus und Beschreibung, Multi-Kalender-fähig.
 * Version: 0.3.0
 * Author: Glow
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/ajax.php';


class GlowBookingCalendar {
    private $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'glow_bookings';

        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend']); 
        add_action('wp_ajax_glowbc_save_day', [$this, 'ajax_save_day']);
        // CSV Import/Export
        add_action('admin_init', [$this, 'maybe_handle_export_import']);
        add_action('wp_ajax_glowbc_bulk_save', [$this, 'ajax_bulk_save']);

        


    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Tabellenstruktur gemäß Vorgabe
        $sql = "CREATE TABLE {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id BIGINT UNSIGNED NOT NULL,
            form_id BIGINT UNSIGNED NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NOT NULL,
            fields LONGTEXT NULL,
            status VARCHAR(50) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            _date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_modified DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY calendar_id (calendar_id),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function admin_menu() {
        add_menu_page(
            'Buchungskalender',
            'Buchungskalender',
            'manage_options',
            'glow-booking-calendar',
            [$this, 'render_admin_page'],
            'dashicons-calendar',
            40
        );
    }

    public function get_entries_for_month($calendar_id, $month) {
        global $wpdb;

        $start_date = date('Y-m-01', strtotime($month));
        $end_date   = date('Y-m-t', strtotime($month));

        $table = $this->table; // korrekt, nicht glowbc_bookings

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT start_date, end_date, fields FROM $table WHERE calendar_id = %d AND start_date <= %s AND end_date >= %s",
            $calendar_id, $end_date, $start_date
        ), ARRAY_A);

        $booked = [];
        $changeover = [];

        foreach($results as $r) {
            $fields = json_decode($r['fields'], true) ?: [];
            $availability = $fields['availability'] ?? '';

            $current = strtotime($r['start_date']);
            $end     = strtotime($r['end_date']);

            while($current <= $end) {
                $day = date('Y-m-d', $current);
                if($availability === 'gebucht') {
                    $booked[] = $day;
                } elseif($availability === 'changeover1') {
                    $changeover['changeover1'][] = $day;
                } elseif($availability === 'changeover2') {
                    $changeover['changeover2'][] = $day;
                }
                $current = strtotime('+1 day', $current);
            }
        }


        return [
            'booked' => $booked,
            'changeover' => $changeover
        ];
    }






    public function render_month_table($calendar_id, $month, $readonly = false) {
        $entries = $this->get_entries_for_month($calendar_id, $month);
        setlocale(LC_TIME, 'de_DE.UTF-8'); // oder 'de_DE' je nach Server

        $start = new DateTime($month . '-01');
        $days_in_month = $start->format('t');
        $first_day_week = (int)$start->format('N'); // Mo=1

       $html = '<table class="glowbc-calendar">';

        // Navigation im thead
        $html .= '<thead>';
        $html .= '<tr class="glowbc-calendar-nav">';
        $html .= '<th><button class="glowbc-prev-month" data-calendar-id="' . $calendar_id . '">‹</button></th>';
        $html .= '<th colspan="5">' . strftime('%B %Y', $start->getTimestamp()) . '</th>';
        $html .= '<th><button class="glowbc-next-month" data-calendar-id="' . $calendar_id . '">›</button></th>';
        $html .= '</tr>';

        // Wochentage
        $html .= '<tr><th>Mo</th><th>Di</th><th>Mi</th><th>Do</th><th>Fr</th><th>Sa</th><th>So</th></tr>';
        $html .= '</thead>';

        // tbody vorbereiten (die Tage kommen hierhin)
        $html .= '<tbody><tr>';


        
        // Leerzellen am Monatsanfang
        for($i = 1; $i < $first_day_week; $i++) {
            $html .= '<td></td>';
        }

        for($day = 1; $day <= $days_in_month; $day++) {
            $date = $start->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);

            if(in_array($date, $entries['booked'])) {
                $status = 'status-gebucht';
            } elseif(in_array($date, $entries['changeover']['changeover1'] ?? [])) {
                $status = 'status-changeover1';
            } elseif(in_array($date, $entries['changeover']['changeover2'] ?? [])) {
                $status = 'status-changeover2';
            } else {
                $status = 'status-frei';
            }

            $html .= '<td class="' . $status . '" data-date="' . esc_attr($date) . '">' . $day . '</td>';

            if(($day + $first_day_week - 1) % 7 == 0) {
                $html .= '</tr><tr>';
            }
        }

        // Leerzellen am Monatsende
        $last_day_week = ($first_day_week + $days_in_month - 1) % 7;
        if($last_day_week != 0) {
            for($i = $last_day_week; $i < 7; $i++) {
                $html .= '<td></td>';
            }
        }

        $html .= '</tr></tbody></table>';

        return $html;
    }



    
    public function enqueue_frontend($hook){
        // Frontend
        if (!is_admin()) {
            wp_enqueue_style('glowbc-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.css', [], '0.3.0');
                wp_enqueue_script('glowbc-frontend', plugin_dir_url(__FILE__) . 'assets/frontend.js', ['jquery'], '0.3.0', true);
            wp_localize_script('glowbc-frontend', 'GlowBC', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('glowbc-nonce'),
            ]);
        }
    }


    public function enqueue_admin($hook) {
        if ($hook !== 'toplevel_page_glow-booking-calendar') return;
        wp_enqueue_style('glowbc-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '0.3.0');
        wp_enqueue_script('glowbc-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], '0.3.0', true);
        wp_localize_script('glowbc-admin', 'GlowBC', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('glowbc-nonce'),
            'calendarId' => intval(isset($_GET['cal']) ? max(1, intval($_GET['cal'])) : apply_filters('glowbc_calendar_id_default', 1)),
            'pageUrl'    => admin_url('admin.php?page=glow-booking-calendar'),
        ]);
    }

    // ===== Helpers =====
    private function get_month_days($year, $month) {
        $date = new DateTimeImmutable("$year-$month-01 00:00:00");
        $days = (int)$date->format('t');
        $out = [];
        for ($d = 1; $d <= $days; $d++) {
            $out[] = $date->setDate((int)$date->format('Y'), (int)$date->format('m'), $d);
        }
        return $out;
    }

    private function get_entry_for_day($calendar_id, DateTimeInterface $day) {
        global $wpdb;
        $start = $day->format('Y-m-d 00:00:00');
        $end   = $day->format('Y-m-d 23:59:59');
        $sql   = $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE calendar_id = %d
               AND start_date >= %s
               AND end_date <= %s
             ORDER BY id DESC
             LIMIT 1",
            $calendar_id, $start, $end
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($row && !empty($row['fields'])) {
            $row['fields'] = json_decode($row['fields'], true) ?: [];
        } else {
            $row['fields'] = [];
        }
        return $row;
    }

    public function ajax_bulk_save() {
        check_ajax_referer('glowbc-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $calendar_id = intval($_POST['calendar_id'] ?? 1);
        $start_raw   = sanitize_text_field($_POST['start_date'] ?? '');
        $end_raw     = sanitize_text_field($_POST['end_date'] ?? '');
        $availability= sanitize_text_field($_POST['availability'] ?? '');
        $description = wp_unslash($_POST['description'] ?? '');

        if (!$start_raw || !$end_raw) {
            wp_send_json_error(['message' => 'Bitte Start- und Enddatum angeben']);
        }

        // Falls ein ISO-Timestamp oder ähnliches kommt: nur das YYYY-MM-DD extrahieren
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $start_raw, $m)) { $start_candidate = $m[0]; } else { $start_candidate = $start_raw; }
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $end_raw, $m))   { $end_candidate   = $m[0]; } else { $end_candidate   = $end_raw; }

        // Versuche strikte Parsing mit Y-m-d
        $start_dt = DateTime::createFromFormat('Y-m-d', $start_candidate);
        $end_dt   = DateTime::createFromFormat('Y-m-d', $end_candidate);

        // Fallback auf strtotime falls createFromFormat fehlschlägt
        if (!$start_dt) {
            $ts = strtotime($start_candidate);
            if ($ts) $start_dt = (new DateTime())->setTimestamp($ts);
        }
        if (!$end_dt) {
            $ts = strtotime($end_candidate);
            if ($ts) $end_dt = (new DateTime())->setTimestamp($ts);
        }

        if (!$start_dt || !$end_dt) {
            wp_send_json_error(['message' => 'Ungültiges Datumsformat. Bitte YYYY-MM-DD verwenden.']);
        }

        // Normalisieren auf Mitternacht (Server-TZ)
        $start = DateTimeImmutable::createFromMutable((new DateTime())->setTimestamp($start_dt->getTimestamp()))->setTime(0,0,0);
        $end   = DateTimeImmutable::createFromMutable((new DateTime())->setTimestamp($end_dt->getTimestamp()))->setTime(0,0,0);

        // Vergleich: Enddatum muss gleich oder nach Startdatum sein
        if ($end < $start) {
            wp_send_json_error([
                'message' => 'Enddatum muss gleich oder nach dem Startdatum liegen',
                'debug' => [
                    'start_input' => $start_raw,
                    'end_input'   => $end_raw,
                    'start_parsed'=> $start->format('Y-m-d H:i:s'),
                    'end_parsed'  => $end->format('Y-m-d H:i:s'),
                    'start_ts'    => $start->getTimestamp(),
                    'end_ts'      => $end->getTimestamp(),
                ]
            ]);
        }

        // Schleife über Tage inkl. Enddatum
        $count = 0;
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $this->upsert_day($calendar_id, $d->format('Y-m-d'), $availability, $description);
            $count++;
        }

        wp_send_json_success(['message' => $count . ' Tage aktualisiert']);
    }



    private function get_month_rows_latest_per_day($calendar_id, $year, $month) {
        // Liefert Array dateKey => fields (neueste je Tag)
        global $wpdb;
        $first = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $lastDate = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $lastDate->format('Y-m-t 23:59:59');

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, start_date, fields FROM {$this->table}
                 WHERE calendar_id = %d
                   AND start_date >= %s AND end_date <= %s
                 ORDER BY id DESC",
                $calendar_id, $first, $last
            ),
            ARRAY_A
        );
        $map = [];
        foreach ($rows as $r) {
            $dateKey = substr($r['start_date'], 0, 10);
            if (!isset($map[$dateKey])) { // nimm den neuesten (id DESC)
                $fields = [];
                if (!empty($r['fields'])) {
                    $fields = json_decode($r['fields'], true) ?: [];
                }
                $map[$dateKey] = $fields; // enthalten: availability, description
            }
        }
        ksort($map);
        return $map;
    }

    private function get_month_status_map($calendar_id, $year, $month) {
        $map = $this->get_month_rows_latest_per_day($calendar_id, $year, $month);
        $out = [];
        foreach ($map as $dateKey => $fields) {
            $out[$dateKey] = $fields['availability'] ?? '';
        }
        return $out;
    }

    // ===== UI =====
    public function render_admin_page() {
        // Jahr/Monat via GET wählbar
        $year  = isset($_GET['y']) ? max(1970, intval($_GET['y'])) : intval(current_time('Y'));
        $month = isset($_GET['m']) ? min(12, max(1, intval($_GET['m']))) : intval(current_time('m'));

        // Kalender-ID wählbar (Multi-Kalender vorbereitend)
        $calendar_id = isset($_GET['cal']) ? max(1, intval($_GET['cal'])) : intval(apply_filters('glowbc_calendar_id_default', 1));

        $days = $this->get_month_days($year, $month);
        $statusMap = $this->get_month_status_map($calendar_id, $year, $month);

        // Navigation berechnen
        $prevMonth = $month - 1; $prevYear = $year; if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
        $nextMonth = $month + 1; $nextYear = $year; if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

        $baseUrl = admin_url('admin.php?page=glow-booking-calendar');
        $prevUrl = esc_url(add_query_arg(['y' => $prevYear, 'm' => $prevMonth, 'cal' => $calendar_id], $baseUrl));
        $nextUrl = esc_url(add_query_arg(['y' => $nextYear, 'm' => $nextMonth, 'cal' => $calendar_id], $baseUrl));
        $monthLabel = date_i18n('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));

        echo '<div class="wrap"><h1>Buchungskalender</h1>';

        // Admin Notice nach Import
        if (!empty($_GET['glowbc_imported'])) {
            $count = intval($_GET['glowbc_imported']);
            $skipped = intval($_GET['glowbc_skipped'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Import abgeschlossen: '
                . esc_html($count) . ' Zeilen gespeichert, ' . esc_html($skipped) . ' übersprungen.</p></div>';
        }

        echo '<div class="glowbc-controls">'
            . '<form method="get" class="glowbc-calid-form" style="gap:8px;align-items:flex-end;">'
            . '<input type="hidden" name="page" value="glow-booking-calendar" />'
            . '<label>Kalender-ID:'
            . '<input type="number" name="cal" value="'.esc_attr($calendar_id).'" min="1" />'
            . '</label>'
            . '<input type="hidden" name="y" value="'.esc_attr($year).'" />'
            . '<input type="hidden" name="m" value="'.esc_attr($month).'" />'
            . '<button class="button">Übernehmen</button>'
            . '</form>';

        // Export-Button (GET mit Nonce)
        $export_url = add_query_arg([
            'page' => 'glow-booking-calendar',
            'cal' => $calendar_id,
            'y' => $year,
            'm' => $month,
            'glowbc_export' => 1,
        ], admin_url('admin.php'));
        $export_url = wp_nonce_url($export_url, 'glowbc_export', 'glowbc_export_nonce');

        echo '<div style="margin:8px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">'
            . '<a href="'.esc_url($export_url).'" class="button button-secondary">Monat als CSV exportieren</a>'
            // Import-Formular
            . '<form method="post" enctype="multipart/form-data" style="display:inline-flex; gap:8px; align-items:center;">'
            . wp_nonce_field('glowbc_import', 'glowbc_import_nonce', true, false)
            . '<input type="hidden" name="page" value="glow-booking-calendar" />'
            . '<input type="hidden" name="cal" value="'.esc_attr($calendar_id).'" />'
            . '<input type="hidden" name="y" value="'.esc_attr($year).'" />'
            . '<input type="hidden" name="m" value="'.esc_attr($month).'" />'
            . '<input type="file" name="glowbc_csv" accept=".csv,text/csv" />'
            . '<button class="button">CSV importieren</button>'
            . '<input type="hidden" name="glowbc_import" value="1" />'
            . '</form>'
            . '</div>';

        echo '</div>';

        echo '<div class="glowbc-layout">';

        // Linke Spalte: Kalender
        echo '<div class="glowbc-calendar">';
        echo '<div class="glowbc-cal-header"'
            . '><a class="glowbc-nav prev" href="'.$prevUrl.'" aria-label="Voriger Monat">&#9664;</a>'
            . '<div class="glowbc-month-label">'.esc_html($monthLabel).'</div>'
            . '<a class="glowbc-nav next" href="'.$nextUrl.'" aria-label="Nächster Monat">&#9654;</a>'
            . '</div>';

        // Wochentage (Mo-So)
        $weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        echo '<div class="glowbc-grid glowbc-weekdays">';
        foreach ($weekdays as $wd) { echo '<div class="glowbc-weekday">'.esc_html($wd).'</div>'; }
        echo '</div>';

        // Tage
        $firstDow = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('N'); // 1=Mo..7=So
        $totalDays = (int) end($days)->format('d');

        echo '<div class="glowbc-grid glowbc-days">';
        for ($i=1; $i<$firstDow; $i++) { echo '<div class="glowbc-day empty"></div>'; }

        for ($d=1; $d<=$totalDays; $d++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $availability = $statusMap[$dateKey] ?? '';
            $cls = 'glowbc-day';
            if ($availability) { $cls .= ' status-' . esc_attr($availability); }
            echo '<div class="'.$cls.'" data-date="'.$dateKey.'">'
                . '<span class="num">'.$d.'</span>'
                . '</div>';
        }
        echo '</div>'; // glowbc-days

        echo '<div class="glowbc-legend"'
            . '><span><i class="legend-box status-gebucht"></i> gebucht</span>'
            . '<span><i class="legend-box status-changeover1"></i> changeover 1</span>'
            . '<span><i class="legend-box status-changeover2"></i> changeover 2</span>'
            . '</div>';

            // Bulk Edit

        echo '<div class="glowbc-bulk-edit">';
        echo '<h2>Bulk Edit Verfügbarkeit</h2>';
        echo '<p style="margin-top:6px;color:#50575e;">Tipp: Start- und Enddatum kannst du auch direkt im Kalender auswählen.</p>';
        echo '<form id="glowbc-bulk-form" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">';
        echo '<input type="hidden" name="calendar_id" value="'.esc_attr($calendar_id).'" />';

        echo '<label class="full">Startdatum<br><input type="date" name="start_date" required></label>';
        echo '<label class="full">Enddatum<br><input type="date" name="end_date" required></label>';

        echo '<label class="full">Verfügbarkeit<br>
                <select name="availability" required>
                    <option value="">— bitte wählen —</option>
                    <option value="verfuegbar">verfügbar</option>
                    <option value="gebucht">gebucht</option>
                </select></label>';

        echo '<label class="full">Beschreibung<br><input type="text" name="description" placeholder="Beschreibung..."></label>';

        echo '<button type="submit" class="button button-primary">Speichern</button>';
        echo '<span class="glowbc-bulk-status"></span>';
        echo '<span class="glowbc-bulk-range" style="margin-left:8px;color:#50575e;"></span>';

        echo '</form></div>';

        echo '</div>'; // glowbc-calendar

        
        // Rechte Spalte: Tabelle
        echo '<div class="glowbc-table-wrap">';
        echo '<table class="widefat fixed striped glowbc-table"><thead>
                <tr>
                    <th style="width:140px">Datum</th>
                    <th style="width:220px">Verfügbarkeit</th>
                    <th>Beschreibung</th>
                    <th style="width:120px">Aktion</th>
                </tr>
              </thead><tbody>';

        foreach ($days as $day) {
            $row = $this->get_entry_for_day($calendar_id, $day);
            $availability = $row['fields']['availability'] ?? '';
            $description  = $row['fields']['description'] ?? '';
            $row_id = $row['id'] ?? '';

            $dateLabel = esc_html($day->format('D, d.m.Y'));
            $dateKey   = esc_attr($day->format('Y-m-d'));

            echo '<tr data-date="'.$dateKey.'" data-row-id="'.esc_attr($row_id).'">
                    <td>'.$dateLabel.'</td>
                    <td>
                        <select class="glowbc-availability">
                            <option value="">— bitte wählen —</option>
                            <option value="verfuegbar" '.selected($availability, 'verfuegbar', false).'>verfügbar</option>
                            <option value="gebucht" '.selected($availability, 'gebucht', false).'>gebucht</option>
                            <option value="changeover1" '.selected($availability, 'changeover1', false).'>changeover 1</option>
                            <option value="changeover2" '.selected($availability, 'changeover2', false).'>changeover 2</option>
                        </select>
                    </td>
                    <td>
                        <input type="text" class="glowbc-description" value="'.esc_attr($description).'" placeholder="Beschreibung..." />
                    </td>
                    <td>
                        <button type="button" class="button button-primary glowbc-save">Speichern</button>
                        <span class="glowbc-status"></span>
                    </td>
                  </tr>';
        }

        echo '</tbody></table>';
        echo '</div>'; // table-wrap

        echo '</div>'; // layout
        echo '</div>'; // wrap
    }

    // ===== AJAX Save =====
    public function ajax_save_day() {
        check_ajax_referer('glowbc-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $calendar_id = intval($_POST['calendar_id'] ?? 1);
        $date        = sanitize_text_field($_POST['date'] ?? '');
        $availability= sanitize_text_field($_POST['availability'] ?? '');
        $description = wp_unslash($_POST['description'] ?? '');

        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['message' => 'Ungültiges Datum']);
        }

        $this->upsert_day($calendar_id, $date, $availability, $description);

        global $wpdb;
        $id = $wpdb->insert_id; // if update, not set; fetch latest id for safety
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE calendar_id=%d AND start_date >= %s AND end_date <= %s ORDER BY id DESC LIMIT 1",
            $calendar_id, $date.' 00:00:00', $date.' 23:59:59'
        ));

        wp_send_json_success(['id' => intval($existing), 'message' => 'Gespeichert']);
    }

    private function upsert_day($calendar_id, $dateYmd, $availability, $description) {
        global $wpdb;

        $start = $dateYmd . ' 00:00:00';
        $end   = $dateYmd . ' 23:59:59';

        $fields = [
            'availability' => $availability,
            'description'  => $description,
        ];

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$this->table}
                 WHERE calendar_id = %d AND start_date >= %s AND end_date <= %s
                 ORDER BY id DESC LIMIT 1",
                $calendar_id, $start, $end
            ),
            ARRAY_A
        );

        $data = [
            'calendar_id' => $calendar_id,
            'form_id'     => null,
            'start_date'  => $start,
            'end_date'    => $end,
            'fields'      => wp_json_encode($fields),
            'status'      => 'accepted',
            'is_read'     => 1,
        ];

        if ($existing) {
            $wpdb->update($this->table, $data, ['id' => intval($existing['id'])]);
            return intval($existing['id']);
        } else {
            $wpdb->insert($this->table, $data);
            return intval($wpdb->insert_id);
        }
    }

    // ===== CSV Import/Export Handling =====
    public function maybe_handle_export_import() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $on_page = isset($_REQUEST['page']) && $_REQUEST['page'] === 'glow-booking-calendar';
        if (!$on_page) return;

        // EXPORT (GET)
        if (isset($_GET['glowbc_export'])) {
            check_admin_referer('glowbc_export', 'glowbc_export_nonce');
            $calendar_id = isset($_GET['cal']) ? max(1, intval($_GET['cal'])) : intval(apply_filters('glowbc_calendar_id_default', 1));
            $year  = isset($_GET['y']) ? max(1970, intval($_GET['y'])) : intval(current_time('Y'));
            $month = isset($_GET['m']) ? min(12, max(1, intval($_GET['m']))) : intval(current_time('m'));
            $this->do_export_csv($calendar_id, $year, $month);
            exit;
        }

        // IMPORT (POST)
        if (!empty($_POST['glowbc_import'])) {
            check_admin_referer('glowbc_import', 'glowbc_import_nonce');
            $calendar_id = isset($_POST['cal']) ? max(1, intval($_POST['cal'])) : intval(apply_filters('glowbc_calendar_id_default', 1));
            $year  = isset($_POST['y']) ? max(1970, intval($_POST['y'])) : intval(current_time('Y'));
            $month = isset($_POST['m']) ? min(12, max(1, intval($_POST['m']))) : intval(current_time('m'));

            $imported = 0; $skipped = 0;
            if (!empty($_FILES['glowbc_csv']) && is_uploaded_file($_FILES['glowbc_csv']['tmp_name'])) {
                $res = $this->do_import_csv($_FILES['glowbc_csv']['tmp_name'], $calendar_id);
                $imported = $res['imported'] ?? 0;
                $skipped = $res['skipped'] ?? 0;
            }

            $redirect = add_query_arg([
                'page' => 'glow-booking-calendar',
                'cal' => $calendar_id,
                'y' => $year,
                'm' => $month,
                'glowbc_imported' => $imported,
                'glowbc_skipped' => $skipped,
            ], admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }
    }

    private function legend_for_export($availability) {
        switch ($availability) {
            case 'gebucht': return 'Booked';
            case 'changeover1': return 'Changeover 1';
            case 'changeover2': return 'Changeover 2';
            case 'verfuegbar': return 'Available';
        }
        return '';
    }

    private function availability_for_import($legend) {
        $legend = trim((string)$legend);
        $legend_l = strtolower($legend);
        // normalize
        $legend_l = str_replace(['"', '\\"'], '"', $legend_l);
        if ($legend_l === 'booked') return 'gebucht';
        if ($legend_l === 'changeover 1' || $legend_l === 'wechsel 1') return 'changeover1';
        if ($legend_l === 'changeover 2' || $legend_l === 'wechsel 2') return 'changeover2';
        if ($legend_l === 'available' || $legend_l === 'verfügbar' || $legend_l === 'verfuegbar') return 'verfuegbar';
        return '';
    }

    private function parse_import_date_to_ymd($dateStr) {
        $s = trim((string)$dateStr);
        if ($s === '') return '';

        // Already Y-m-d?
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

        // Try d.m.Y
        if (preg_match('/^(\d{1,2})[.](\d{1,2})[.](\d{4})$/', $s, $m)) {
            $d = (int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3];
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        // Try j. F Y with German month names
        $months = [
            'januar'=>1,'februar'=>2,'märz'=>3,'maerz'=>3,'april'=>4,'mai'=>5,'juni'=>6,
            'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12,
            // English fallback
            'january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,'july'=>7,
            'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
        ];
        if (preg_match('/^(\d{1,2})\.?\s+([\p{L}äÄöÖüÜß]+)\s+(\d{4})$/u', $s, $m)) {
            $d = (int)$m[1]; $name = strtolower($m[2]); $y=(int)$m[3];
            $name = strtr($name, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
            if (isset($months[$name])) {
                $mo = (int)$months[$name];
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // Last resort: strtotime
        $ts = strtotime($s);
        if ($ts) return date('Y-m-d', $ts);
        return '';
    }

    private function do_export_csv($calendar_id, $year, $month) {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $map = $this->get_month_rows_latest_per_day($calendar_id, $year, $month);

        $filename = sprintf('glowbc-calendar-%d-%04d-%02d.csv', $calendar_id, $year, $month);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');
        // Header
        fputcsv($out, ['Date','Legend','Description','Tooltip']);

        foreach ($map as $dateKey => $fields) {
            $availability = $fields['availability'] ?? '';
            if ($availability === '') continue; // nur belegte Tage exportieren
            $legend = $this->legend_for_export($availability);
            $description = (string)($fields['description'] ?? '');
            $dateHuman = date_i18n('j. F Y', strtotime($dateKey));
            fputcsv($out, [$dateHuman, $legend, $description, '']);
        }
        fclose($out);
    }

    private function do_import_csv($tmpPath, $calendar_id) {
        if (!current_user_can('manage_options')) return ['imported'=>0,'skipped'=>0];
        $fh = fopen($tmpPath, 'r');
        if (!$fh) return ['imported'=>0,'skipped'=>0];

        $imported = 0; $skipped = 0; $line = 0; $indices = [
            'date' => 0, 'legend' => 1, 'description' => 2, 'tooltip' => 3
        ];

        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            // Header detection
            if ($line === 1) {
                $header = array_map('trim', $row);
                // Map columns by name if provided
                $lookup = [];
                foreach ($header as $i => $name) { $lookup[strtolower($name)] = $i; }
                foreach (['date','legend','description','tooltip'] as $col) {
                    if (isset($lookup[$col])) $indices[$col] = $lookup[$col];
                }
                // if header is not recognized (no 'date'), assume first row is header anyway and continue
                continue;
            }

            $dateStr = $row[$indices['date']] ?? '';
            $legend  = $row[$indices['legend']] ?? '';
            $desc    = $row[$indices['description']] ?? '';

            $ymd = $this->parse_import_date_to_ymd($dateStr);
            $availability = $this->availability_for_import($legend);

            if (!$ymd || !$availability) { $skipped++; continue; }

            $this->upsert_day($calendar_id, $ymd, $availability, $desc);
            $imported++;
        }
        fclose($fh);
        return ['imported'=>$imported,'skipped'=>$skipped];
    }
}

new GlowBookingCalendar();





// ===== Admin Submenu: Requests =====
function glowbc_render_requests_page(){
    if(!current_user_can('manage_options')){ wp_die('Unauthorized'); }
    global $wpdb; $table = $wpdb->prefix.'glow_bookings';
    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE status='pending' ORDER BY start_date ASC", ARRAY_A);

    echo '<div class="wrap"><h1>Anfragen</h1>';
    echo '<table class="widefat fixed striped"><thead><tr>'
       . '<th>ID</th><th>Zeitraum</th><th>Name</th><th>E-Mail</th><th>Personen</th><th>Kinder 0-6</th><th>Kinder 7-16</th><th>Nachricht</th><th>Aktion</th>'
       . '</tr></thead><tbody>'; 

    foreach($rows as $r){
        $f = json_decode($r['fields'] ?? '{}', true) ?: [];
        $id = intval($r['id']);
        $period = esc_html(date_i18n('d.m.Y', strtotime($r['start_date'])) . ' – ' . date_i18n('d.m.Y', strtotime($r['end_date'])));
        $name = esc_html(($f['first_name'] ?? '').' '.($f['last_name'] ?? ''));
        $email = esc_html(($f['email'] ?? ''));
        $persons = intval($f['persons'] ?? 1);
        $k06 = intval($f['kids_0_6'] ?? 0);
        $k716 = intval($f['kids_7_16'] ?? 0);
        $msg = esc_html($f['message'] ?? '');
        echo '<tr data-id="'.$id.'">'
           . '<td>'.$id.'</td>'
           . '<td>'.$period.'</td>'
           . '<td>'.$name.'</td>'
           . '<td>'.$email.'</td>'
           . '<td>'.$persons.'</td>'
           . '<td>'.$k06.'</td>'
           . '<td>'.$k716.'</td>'
           . '<td style="max-width:280px">'.$msg.'</td>'
           . '<td>
                <button class="button glowbc-req-accept" data-id="'.$id.'">Accept</button>
                <button type="button" class="button button-secondary glowbc-req-delete">Delete</button>
             </td>
           '
           . '</tr>';
    }
    if(empty($rows)){
        echo '<tr><td colspan="9">Keine Anfragen</td></tr>';
    }
    echo '</tbody></table>';
    ?>
    <script>
    (function($){
        $(document).on('click','.glowbc-req-accept', function(){
            var id = $(this).data('id');
            var $btn = $(this); $btn.prop('disabled', true).text('Verarbeite …');
            $.post(ajaxurl, {action:'glowbc_accept_request', nonce:'<?php echo esc_js(wp_create_nonce('glowbc-nonce')); ?>', id:id}, function(res){
                if(res && res.success){
                    $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Fehler');
                    $btn.prop('disabled', false).text('Accept');
                }
            });
        });

        $(document).on('click', '.glowbc-req-delete', function () {
            if (!confirm('Eintrag wirklich löschen?')) return;

            const $tr = jQuery(this).closest('tr');
            const id = $tr.data('row-id');

            jQuery.post(ajaxUrl, {
                action: 'glowbc_req_delete',
                nonce:'<?php echo esc_js(wp_create_nonce('glowbc-nonce')); ?>',
                id: id
            }, function (resp) {
                if (resp.success) {
                    $tr.fadeOut(300, function () { jQuery(this).remove(); });
                } else {
                    alert(resp.data?.message || 'Fehler beim Löschen');
                }
            });
        });
        
    })(jQuery);
    </script>
    <?php
    echo '</div>';
}
add_action('admin_menu', function(){
    add_submenu_page(
        'glow-booking-calendar',
        'Anfragen',
        'Anfragen',
        'manage_options',
        'glow-booking-requests',
        'glowbc_render_requests_page'
    );
});

