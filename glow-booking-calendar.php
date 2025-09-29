<?php
/**
 * Plugin Name: Glow Booking Calendar
 * Description: Einfacher Buchungskalender (Backend) mit Tagesstatus und Beschreibung, Multi-Kalender-fähig.
 * Version: 0.3.0
 * Author: Glow
 */
error_log("Glow Booking Calendar loaded");
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
        add_action('admin_init', [$this, 'maybe_handle_booking_export_import']);
        add_action('wp_ajax_glowbc_bulk_save', [$this, 'ajax_bulk_save']);
        add_action('wp_ajax_glowbc_delete_calendar', [$this, 'ajax_delete_calendar']);

        


    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_calendars = $wpdb->prefix . 'glow_calendars';
        $sql1 = "CREATE TABLE $table_calendars (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            notification_email VARCHAR(100) NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $table_entries = $wpdb->prefix . 'glow_bookings';
        $sql2 = "CREATE TABLE $table_entries (
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
        dbDelta($sql1);
        dbDelta($sql2);
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

        error_log("BACKEND INITIAL: Found " . count($results) . " results for month $month");
        
        foreach($results as $r) {
            $fields = json_decode($r['fields'], true) ?: [];
            $availability = $fields['availability'] ?? '';
            
            error_log("BACKEND INITIAL: Processing booking - start: {$r['start_date']}, end: {$r['end_date']}, availability: $availability");

            // Skip if not a booking
            if ($availability !== 'gebucht') {
                continue;
            }

            // Normalize dates to Y-m-d format for comparison (like AJAX function)
            $current = strtotime($r['start_date']);
            $end = strtotime($r['end_date']);
            $startDate = date('Y-m-d', $current);
            $endDate = date('Y-m-d', $end);
            
            error_log("BACKEND INITIAL: Normalized dates - start: $startDate, end: $endDate");

            while($current <= $end) {
                $day = date('Y-m-d', $current);
                
                // Apply changeover logic exactly like AJAX function
                if ($day === $startDate && $day === $endDate) {
                    // Single day booking
                    $booked[] = $day;
                    error_log("BACKEND INITIAL: Single day booking: $day -> gebucht");
                } elseif ($day === $startDate) {
                    // First day of multi-day booking
                    $changeover['changeover1'][] = $day;
                    error_log("BACKEND INITIAL: First day: $day -> changeover1");
                } elseif ($day === $endDate) {
                    // Last day of multi-day booking
                    $changeover['changeover2'][] = $day;
                    error_log("BACKEND INITIAL: Last day: $day -> changeover2");
                } else {
                    // Middle days of multi-day booking
                    $booked[] = $day;
                    error_log("BACKEND INITIAL: Middle day: $day -> gebucht");
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
        // Use the same logic as AJAX function for consistency
        global $wpdb;
        
        $days_in_month = (int)(new DateTimeImmutable("$year-$month-01"))->format('t');
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);
        
        error_log("BACKEND INITIAL STATUS MAP: Getting bookings for $first to $last");
        
        // Get bookings that overlap with this month (same logic as AJAX)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT start_date, end_date, fields FROM {$this->table} 
             WHERE calendar_id = %d 
             AND start_date <= %s 
             AND end_date >= %s",
            $calendar_id, $last, $first
        ), ARRAY_A);
        
        $statusMap = [];
        
        foreach ($rows as $r) {
            $fields = json_decode($r['fields'], true) ?: [];
            $availability = $fields['availability'] ?? '';
            
            error_log("BACKEND INITIAL STATUS MAP: Processing booking - start: {$r['start_date']}, end: {$r['end_date']}, availability: $availability");
            
            if ($availability === 'gebucht') {
                // Apply changeover logic (same as AJAX)
                $current = strtotime($r['start_date']);
                $end = strtotime($r['end_date']);
                $startDate = date('Y-m-d', $current);
                $endDate = date('Y-m-d', $end);
                
                while ($current <= $end) {
                    $dateKey = date('Y-m-d', $current);
                    
                    // Only process days within current month
                    if ($dateKey >= $first && $dateKey <= $last) {
                        if ($dateKey === $startDate && $dateKey === $endDate) {
                            // Single day booking
                            $statusMap[$dateKey] = 'gebucht';
                            error_log("BACKEND INITIAL STATUS MAP: Single day: $dateKey -> gebucht");
                        } elseif ($dateKey === $startDate) {
                            // First day of multi-day booking
                            $statusMap[$dateKey] = 'changeover1';
                            error_log("BACKEND INITIAL STATUS MAP: First day: $dateKey -> changeover1");
                        } elseif ($dateKey === $endDate) {
                            // Last day of multi-day booking
                            $statusMap[$dateKey] = 'changeover2';
                            error_log("BACKEND INITIAL STATUS MAP: Last day: $dateKey -> changeover2");
                        } else {
                            // Middle days
                            $statusMap[$dateKey] = 'gebucht';
                            error_log("BACKEND INITIAL STATUS MAP: Middle day: $dateKey -> gebucht");
                        }
                    }
                    $current = strtotime('+1 day', $current);
                }
            } elseif ($availability) {
                // For other availabilities (non-booking statuses)
                $current = strtotime($r['start_date']);
                $end = strtotime($r['end_date']);
                
                while ($current <= $end) {
                    $dateKey = date('Y-m-d', $current);
                    if ($dateKey >= $first && $dateKey <= $last) {
                        $statusMap[$dateKey] = $availability;
                    }
                    $current = strtotime('+1 day', $current);
                }
            }
        }
        
        error_log("BACKEND INITIAL STATUS MAP: Final statusMap: " . print_r($statusMap, true));
        return $statusMap;
    }

    // ===== Helper für bestätigte Bookings =====
    private function get_confirmed_bookings($calendar_id) {
        global $wpdb;
        
        // Hole alle bestätigten Anfragen (ursprüngliche Requests mit Status 'accepted')
        // Diese enthalten die vollständigen Kundendaten
        // Zeige nur aktuelle und zukünftige Buchungen (nicht vergangene)
        $today = current_time('Y-m-d');
        $confirmed_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE calendar_id = %d 
             AND status = 'accepted' 
             AND fields LIKE %s 
             AND end_date >= %s
             ORDER BY start_date ASC",
            $calendar_id,
            '%"type":"request"%',
            $today . ' 00:00:00'
        ), ARRAY_A);

        return $confirmed_requests;
    }

    // ===== UI =====
    public function render_admin_page() {

        global $wpdb;
        $table_calendars = $wpdb->prefix . 'glow_calendars';
        $calendar_id = isset($_GET['cal']) ? intval($_GET['cal']) : 0;
        if ($calendar_id <= 0) {
            $calendars = $wpdb->get_results("SELECT * FROM $table_calendars ORDER BY id ASC", ARRAY_A);
            echo '<div class="wrap"><h1>Buchungskalender Übersicht</h1>';
            // Button zum neuen Kalender anlegen
            echo '<form method="post" style="margin-bottom:20px;">';
            echo '<input type="text" name="glowbc_new_calendar_name" placeholder="Name des Kalenders" required />';
            echo '<input type="email" name="glowbc_new_calendar_email" placeholder="Benachrichtigungs-E-Mail (optional)" />';
            echo '<button type="submit" class="button button-primary">Neuen Kalender anlegen</button>';
            echo '</form>';

            if (($_POST['glowbc_new_calendar_name'])) {

                $name = sanitize_text_field($_POST['glowbc_new_calendar_name']);
                $email = sanitize_email($_POST['glowbc_new_calendar_email'] ?? '');
                $slug = sanitize_title($name);
                $wpdb->insert($table_calendars, ['name'=>$name,'slug'=>$slug,'notification_email'=>$email]);
                echo '<div class="notice notice-success"><p>Kalender "'.esc_html($name).'" wurde angelegt.</p></div>';
                echo '<script>location.href=location.href;</script>';
                exit;
            }

            if ($calendars) {
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>Name</th><th>E-Mail für Benachrichtigungen</th><th>Shortcode</th><th>Aktionen</th></tr></thead>';
                echo '<tbody>';
                foreach ($calendars as $cal) {
                    $link = admin_url('admin.php?page=glow-booking-calendar&cal=' . $cal['id']);
                    echo '<tr>';
                    echo '<td><a href="'.esc_url($link).'">'.esc_html($cal['name']).'</a></td>';
                    echo '<td>'.esc_html($cal['notification_email'] ?? '').'</td>';
                    echo '<td><code>[glowbc_calendar id="' . esc_attr($cal['id']) . '"]</code></td>';
                    echo '<td>';
                    echo '<a href="'.esc_url($link).'&edit=1" class="button button-small">Bearbeiten</a> ';
                    echo '<button type="button" class="button button-small button-link-delete glowbc-delete-calendar" data-id="'.esc_attr($cal['id']).'" data-name="'.esc_attr($cal['name']).'">Löschen</button>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>Es existiert noch kein Kalender.</p>';
            }

            echo '</div>';
            return;
        }

        // ====================
        // Detailseite eines Kalenders
        // ====================
        $calendar = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_calendars WHERE id=%d", $calendar_id), ARRAY_A);
        if (!$calendar) {
            echo '<div class="notice notice-error"><p>Kalender nicht gefunden.</p></div>';
            return;
        }

        // Bearbeitungsmodus für Kalender
        if (isset($_GET['edit'])) {
            echo '<div class="wrap"><h1>Kalender bearbeiten</h1>';
            $overview_link = admin_url('admin.php?page=glow-booking-calendar');
            echo '<p><a href="'.esc_url($overview_link).'" class="button">&larr; Zurück zur Übersicht</a></p>';

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['glowbc_edit_calendar'])) {
                $name = sanitize_text_field($_POST['glowbc_edit_name']);
                $email = sanitize_email($_POST['glowbc_edit_email'] ?? '');
                $wpdb->update($table_calendars, ['name'=>$name, 'notification_email'=>$email], ['id'=>$calendar_id]);
                echo '<div class="notice notice-success"><p>Kalender aktualisiert.</p></div>';
                // Redirect zurück zur Detailansicht
                wp_redirect(admin_url('admin.php?page=glow-booking-calendar&cal=' . $calendar_id));
                exit;
            }

            echo '<form method="post">';
            echo '<table class="form-table">';
            echo '<tr><th><label for="glowbc_edit_name">Name</label></th><td><input type="text" id="glowbc_edit_name" name="glowbc_edit_name" value="'.esc_attr($calendar['name']).'" required /></td></tr>';
            echo '<tr><th><label for="glowbc_edit_email">Benachrichtigungs-E-Mail</label></th><td><input type="email" id="glowbc_edit_email" name="glowbc_edit_email" value="'.esc_attr($calendar['notification_email'] ?? '').'" /></td></tr>';
            echo '</table>';
            echo '<input type="hidden" name="glowbc_edit_calendar" value="1" />';
            echo '<button type="submit" class="button button-primary">Speichern</button>';
            echo '</form></div>';
            return;
        }

        
        // Prüfen, ob Kalender existieren

        echo '<div class="wrap"><h1>Buchungskalender</h1>';
        


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

        echo '<div class="wrap"><h2>'. $calendar['name'] .'</h2>';

        $overview_link = admin_url('admin.php?page=glow-booking-calendar');
        echo '<p><a href="'.esc_url($overview_link).'" class="button">&larr; Zurück zur Übersicht</a></p>';

        // Offene Anfragen anzeigen
        $pending_requests = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE calendar_id = %d AND status = 'pending' ORDER BY _date_created DESC",
            $calendar_id
        ), ARRAY_A);

        if ($pending_requests) {
            echo '<div class="notice notice-warning" style="margin-top:20px;"><h3>Offene Anfragen ('.count($pending_requests).')</h3>';
            echo '<table class="widefat striped" style="margin-top:10px;margin-bottom:10px;">';
            echo '<thead><tr><th>Name</th><th>E-Mail</th><th>Zeitraum</th><th>Personen</th><th>Nachricht</th><th>Aktionen</th></tr></thead><tbody>';
            foreach ($pending_requests as $req) {
                $fields = json_decode($req['fields'], true) ?: [];
                $name = esc_html(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? ''));
                $email = esc_html($fields['email'] ?? '');
                $start = date_i18n('d.m.Y', strtotime($req['start_date']));
                $end = date_i18n('d.m.Y', strtotime($req['end_date']));
                $persons = intval($fields['persons'] ?? 0);
                $message = esc_html($fields['message'] ?? '');
                echo '<tr>';
                echo '<td>'.$name.'</td>';
                echo '<td><a href="mailto:'.$email.'">'.$email.'</a></td>';
                echo '<td>'.$start.' – '.$end.'</td>';
                echo '<td>'.$persons.'</td>';
                echo '<td>'.wp_trim_words($message, 10).'</td>';
                echo '<td>';
                echo '<button class="button button-small button-primary glowbc-accept-request" data-id="'.esc_attr($req['id']).'">Annehmen</button> ';
                echo '<button class="button button-small button-secondary glowbc-delete-request" data-id="'.esc_attr($req['id']).'">Ablehnen</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        // Bestätigte Bookings anzeigen
        $confirmed_bookings = $this->get_confirmed_bookings($calendar_id);

        // Buchungs-Export/Import Buttons (immer anzeigen)
        $booking_export_url = add_query_arg([
            'page' => 'glow-booking-calendar',
            'cal' => $calendar_id,
            'glowbc_booking_export' => 1,
        ], admin_url('admin.php'));
        $booking_export_url = wp_nonce_url($booking_export_url, 'glowbc_booking_export', 'glowbc_booking_export_nonce');

        if ($confirmed_bookings) {
            echo '<div class="notice notice-success" style="margin-top:20px;"><h3>Bestätigte Buchungen ('.count($confirmed_bookings).')</h3>';
            echo '<table class="widefat striped" style="margin-top:10px;margin-bottom:10px;">';
            echo '<thead><tr><th>Name</th><th>E-Mail</th><th>Zeitraum</th><th>Personen</th><th>Nachricht</th><th>Buchungsdatum</th></tr></thead><tbody>';
            foreach ($confirmed_bookings as $booking) {
                $fields = json_decode($booking['fields'], true) ?: [];
                $name = esc_html(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? ''));
                $email = esc_html($fields['email'] ?? '');
                $start = date_i18n('d.m.Y', strtotime($booking['start_date']));
                $end = date_i18n('d.m.Y', strtotime($booking['end_date']));
                $persons = intval($fields['persons'] ?? 0);
                $message = esc_html($fields['message'] ?? '');
                $booking_date = date_i18n('d.m.Y H:i', strtotime($booking['date_modified']));
                echo '<tr>';
                echo '<td>'.$name.'</td>';
                echo '<td><a href="mailto:'.$email.'">'.$email.'</a></td>';
                echo '<td>'.$start.' – '.$end.'</td>';
                echo '<td>'.$persons.'</td>';
                echo '<td>'.wp_trim_words($message, 10).'</td>';
                echo '<td>'.$booking_date.'</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-info" style="margin-top:20px;"><h3>Bestätigte Buchungen (0)</h3>';
            echo '<p>Keine bestätigten Buchungen vorhanden.</p></div>';
        }
        
        // Export/Import Buttons für Buchungen (immer anzeigen)
        echo '<div style="margin:10px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">';
        echo '<a href="'.esc_url($booking_export_url).'" class="button button-secondary">Buchungen als CSV exportieren</a>';
        
        // Import-Formular für Buchungen
        echo '<form method="post" enctype="multipart/form-data" style="display:inline-flex; gap:8px; align-items:center;">';
        echo wp_nonce_field('glowbc_booking_import', 'glowbc_booking_import_nonce', true, false);
        echo '<input type="hidden" name="page" value="glow-booking-calendar" />';
        echo '<input type="hidden" name="cal" value="'.esc_attr($calendar_id).'" />';
        echo '<input type="file" name="glowbc_booking_csv" accept=".csv,text/csv" />';
        echo '<button class="button">Buchungen CSV importieren</button>';
        echo '<input type="hidden" name="glowbc_booking_import" value="1" />';
        echo '</form>';
        echo '</div>';
        
        // Admin Notice nach Buchungs-Import
        if (!empty($_GET['glowbc_booking_imported'])) {
            $count = intval($_GET['glowbc_booking_imported']);
            $skipped = intval($_GET['glowbc_booking_skipped'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>Buchungs-Import abgeschlossen: '
                . esc_html($count) . ' Buchungen importiert, ' . esc_html($skipped) . ' übersprungen.</p></div>';
        }

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
            'glowbc_export' => 1,
        ], admin_url('admin.php'));
        $export_url = wp_nonce_url($export_url, 'glowbc_export', 'glowbc_export_nonce');

        echo '<div style="margin:8px 0; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">'
            . '<a href="'.esc_url($export_url).'" class="button button-secondary">Jahr als CSV exportieren</a>'
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
        echo '<div class="glowbc-calendar" data-calendar-id="'.esc_attr($calendar_id).'">';
        echo '<div class="glowbc-cal-header">';
        echo '<button class="glowbc-nav prev" data-year="'.$prevYear.'" data-month="'.$prevMonth.'" aria-label="Voriger Monat">&#9664;</button>';
        echo '<select class="glowbc-month-select">';
        // Generate options for next 5 months
        $current = new DateTimeImmutable("$year-$month-01");
        for ($i = 0; $i < 5; $i++) {
            $ym = $current->format('Y-m');
            $label = $current->format('F Y');
            $selected = ($i == 0) ? ' selected' : '';
            echo '<option value="'.$ym.'"'.$selected.'>'.esc_html($label).'</option>';
            $current = $current->modify('+1 month');
        }
        echo '</select>';
        echo '<button class="glowbc-nav next" data-year="'.$nextYear.'" data-month="'.$nextMonth.'" aria-label="Nächster Monat">&#9654;</button>';
        echo '</div>';

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

        // Shortcodes anzeigen
        echo '<div style="margin-top:20px; padding:10px; background:#f9f9f9; border:1px solid #ddd;">';
        echo '<h3>Shortcodes für diesen Kalender</h3>';
        echo '<p>Kalender anzeigen: <code>[glowbc_calendar id="' . esc_attr($calendar_id) . '"]</code></p>';
        echo '<p>Anfrageformular: <code>[glowbc_request_form id="' . esc_attr($calendar_id) . '"]</code></p>';
        echo '</div>';

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

    public function ajax_delete_calendar() {
        check_ajax_referer('glowbc-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $calendar_id = intval($_POST['calendar_id'] ?? 0);
        if (!$calendar_id) {
            wp_send_json_error(['message' => 'Ungültige Kalender-ID']);
        }

        global $wpdb;
        $table_calendars = $wpdb->prefix . 'glow_calendars';
        $table_bookings = $this->table;

        // Prüfen, ob Kalender existiert
        $calendar = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_calendars WHERE id = %d", $calendar_id), ARRAY_A);
        if (!$calendar) {
            wp_send_json_error(['message' => 'Kalender nicht gefunden']);
        }

        // Zuerst alle Buchungen löschen
        $wpdb->delete($table_bookings, ['calendar_id' => $calendar_id], ['%d']);

        // Dann den Kalender löschen
        $deleted = $wpdb->delete($table_calendars, ['id' => $calendar_id], ['%d']);

        if ($deleted) {
            wp_send_json_success(['message' => 'Kalender "' . esc_html($calendar['name']) . '" wurde gelöscht.']);
        } else {
            wp_send_json_error(['message' => 'Fehler beim Löschen des Kalenders']);
        }
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
            $this->do_export_csv($calendar_id, $year);
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

    private function do_export_csv($calendar_id, $year) {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $map = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthMap = $this->get_month_rows_latest_per_day($calendar_id, $year, $month);
            $map = array_merge($map, $monthMap);
        }
        ksort($map); // Sortiere nach Datum

        $filename = sprintf('glowbc-calendar-%d-%04d.csv', $calendar_id, $year);
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

    // ===== Buchungs CSV Import/Export Handling =====
    public function maybe_handle_booking_export_import() {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $on_page = isset($_REQUEST['page']) && $_REQUEST['page'] === 'glow-booking-calendar';
        if (!$on_page) return;

        // BOOKING EXPORT (GET)
        if (isset($_GET['glowbc_booking_export'])) {
            check_admin_referer('glowbc_booking_export', 'glowbc_booking_export_nonce');
            $calendar_id = isset($_GET['cal']) ? max(1, intval($_GET['cal'])) : intval(apply_filters('glowbc_calendar_id_default', 1));
            $this->do_booking_export_csv($calendar_id);
            exit;
        }

        // BOOKING IMPORT (POST)
        if (!empty($_POST['glowbc_booking_import'])) {
            check_admin_referer('glowbc_booking_import', 'glowbc_booking_import_nonce');
            $calendar_id = isset($_POST['cal']) ? max(1, intval($_POST['cal'])) : intval(apply_filters('glowbc_calendar_id_default', 1));
            $year  = isset($_POST['y']) ? max(1970, intval($_POST['y'])) : intval(current_time('Y'));
            $month = isset($_POST['m']) ? min(12, max(1, intval($_POST['m']))) : intval(current_time('m'));

            $imported = 0; $skipped = 0;
            if (!empty($_FILES['glowbc_booking_csv']) && is_uploaded_file($_FILES['glowbc_booking_csv']['tmp_name'])) {
                $res = $this->do_booking_import_csv($_FILES['glowbc_booking_csv']['tmp_name'], $calendar_id);
                $imported = $res['imported'] ?? 0;
                $skipped = $res['skipped'] ?? 0;
            }

            $redirect = add_query_arg([
                'page' => 'glow-booking-calendar',
                'cal' => $calendar_id,
                'y' => $year,
                'm' => $month,
                'glowbc_booking_imported' => $imported,
                'glowbc_booking_skipped' => $skipped,
            ], admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }
    }

    private function do_booking_export_csv($calendar_id) {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        global $wpdb;
        $table_calendars = $wpdb->prefix . 'glow_calendars';
        
        // Hole Kalender-Name
        $calendar = $wpdb->get_row($wpdb->prepare("SELECT name FROM $table_calendars WHERE id = %d", $calendar_id), ARRAY_A);
        $calendar_name = $calendar ? $calendar['name'] : 'Unbekannter Kalender';

        // Hole alle bestätigten Buchungen für diesen Kalender
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} 
             WHERE calendar_id = %d 
             AND status = 'accepted' 
             AND fields LIKE %s 
             ORDER BY start_date ASC",
            $calendar_id,
            '%"type":"request"%'
        ), ARRAY_A);

        $filename = sprintf('buchungen-kalender-%d-%s.csv', $calendar_id, date('Y-m-d'));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');
        
        // Header entsprechend Ihrem gewünschten Format
        fputcsv($out, [
            'Booking ID',
            'Booking Status', 
            'Calendar ID',
            'Calendar Name',
            'Start Date',
            'End Date',
            'Stay Length - Days',
            'Stay Length - Nights',
            'Date Created',
            'first name (ID:1)',
            'last name (ID:2)',
            'E-Mail (ID:4)',
            'Straße (ID:8)',
            'PLZ / Ort (ID:9)',
            'number of guests (ID:3)',
            'number of children (up to incl 6 years) (ID:6)',
            'number of children (7-16 years) (ID:7)',
            'your message (ID:5)'
        ]);

        foreach ($bookings as $booking) {
            $fields = json_decode($booking['fields'], true) ?: [];
            
            $start_date = new DateTime($booking['start_date']);
            $end_date = new DateTime($booking['end_date']);
            $stay_days = $start_date->diff($end_date)->days + 1;
            $stay_nights = $stay_days - 1;
            
            fputcsv($out, [
                $booking['id'],
                $booking['status'],
                $calendar_id,
                $calendar_name,
                $start_date->format('Y-m-d'),
                $end_date->format('Y-m-d'),
                $stay_days,
                $stay_nights,
                (new DateTime($booking['_date_created']))->format('Y-m-d'),
                $fields['first_name'] ?? '',
                $fields['last_name'] ?? '',
                $fields['email'] ?? '',
                $fields['street'] ?? '',
                $fields['city'] ?? '', // PLZ / Ort - wird als neues Feld hinzugefügt
                $fields['persons'] ?? '',
                $fields['kids_0_6'] ?? '',
                $fields['kids_7_16'] ?? '',
                $fields['message'] ?? ''
            ]);
        }
        fclose($out);
    }

    private function do_booking_import_csv($tmpPath, $calendar_id) {
        if (!current_user_can('manage_options')) return ['imported'=>0,'skipped'=>0];
        
        // Datei komplett einlesen für robustes CSV-Parsing
        $content = file_get_contents($tmpPath);
        if (!$content) return ['imported'=>0,'skipped'=>0];

        global $wpdb;
        $imported = 0; $skipped = 0;
        
        // CSV-Zeilen mit robustem Parser parsen (unterstützt mehrzeilige Felder)
        $rows = $this->parse_csv_content($content);
        
        if (empty($rows)) return ['imported'=>0,'skipped'=>0];
        
        // Header-Zeile entfernen
        array_shift($rows);
        
        // Standard-Spalten-Indizes basierend auf Ihrem Format
        $indices = [
            'booking_id' => 0,
            'booking_status' => 1,
            'calendar_id' => 2,
            'calendar_name' => 3,
            'start_date' => 4,
            'end_date' => 5,
            'stay_days' => 6,
            'stay_nights' => 7,
            'date_created' => 8,
            'first_name' => 9,
            'last_name' => 10,
            'email' => 11,
            'street' => 12,
            'city' => 13,
            'persons' => 14,
            'kids_0_6' => 15,
            'kids_7_16' => 16,
            'message' => 17
        ];

        foreach ($rows as $row) {
            // Daten extrahieren
            $booking_id = isset($row[$indices['booking_id']]) ? intval($row[$indices['booking_id']]) : 0;
            $start_date = isset($row[$indices['start_date']]) ? trim($row[$indices['start_date']]) : '';
            $end_date = isset($row[$indices['end_date']]) ? trim($row[$indices['end_date']]) : '';
            $first_name = isset($row[$indices['first_name']]) ? trim($row[$indices['first_name']]) : '';
            $last_name = isset($row[$indices['last_name']]) ? trim($row[$indices['last_name']]) : '';
            $email = isset($row[$indices['email']]) ? trim($row[$indices['email']]) : '';

            // Validierung
            if (!$start_date || !$end_date || !$first_name || !$last_name || !$email) {
                $skipped++;
                continue;
            }

            // Datum validieren
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                $skipped++;
                continue;
            }

            // Prüfen ob Buchung bereits existiert (basierend auf ID oder eindeutigen Daten)
            if ($booking_id > 0) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE id = %d",
                    $booking_id
                ));
                if ($existing) {
                    $skipped++;
                    continue;
                }
            }

            // Felder bereinigen (- durch leeren String ersetzen)
            $street = isset($row[$indices['street']]) ? trim($row[$indices['street']]) : '';
            if ($street === '-') $street = '';
            
            $city = isset($row[$indices['city']]) ? trim($row[$indices['city']]) : '';
            if ($city === '-') $city = '';
            
            $message = isset($row[$indices['message']]) ? trim($row[$indices['message']]) : '';
            if ($message === '-') $message = '';

            // Fields-Array erstellen
            $fields = [
                'type' => 'request',
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'street' => $street,
                'city' => $city,
                'persons' => isset($row[$indices['persons']]) ? intval($row[$indices['persons']]) : 1,
                'kids_0_6' => isset($row[$indices['kids_0_6']]) ? intval($row[$indices['kids_0_6']]) : 0,
                'kids_7_16' => isset($row[$indices['kids_7_16']]) ? intval($row[$indices['kids_7_16']]) : 0,
                'message' => $message,
                'availability' => 'gebucht',
                'description' => '',
            ];

            // Buchung in Datenbank einfügen
            $data = [
                'calendar_id' => $calendar_id,
                'form_id'     => null,
                'start_date'  => $start_date . ' 00:00:00',
                'end_date'    => $end_date . ' 23:59:59',
                'fields'      => wp_json_encode($fields),
                'status'      => 'accepted',
                'is_read'     => 1,
            ];

            if ($booking_id > 0) {
                $data['id'] = $booking_id;
            }

            $result = $wpdb->insert($this->table, $data);
            if ($result !== false) {
                $imported++;
            } else {
                $skipped++;
            }
        }
        
        return ['imported'=>$imported,'skipped'=>$skipped];
    }

    /**
     * Robuster CSV-Parser der mehrzeilige Felder korrekt behandelt
     */
    private function parse_csv_content($content) {
        $rows = [];
        $current_row = [];
        $current_field = '';
        $in_quotes = false;
        $i = 0;
        $len = strlen($content);
        
        while ($i < $len) {
            $char = $content[$i];
            
            if ($char === '"') {
                if ($in_quotes && $i + 1 < $len && $content[$i + 1] === '"') {
                    // Escaped quote
                    $current_field .= '"';
                    $i += 2;
                    continue;
                } else {
                    // Toggle quote state
                    $in_quotes = !$in_quotes;
                }
            } elseif ($char === ',' && !$in_quotes) {
                // Field separator
                $current_row[] = $current_field;
                $current_field = '';
            } elseif (($char === "\n" || $char === "\r") && !$in_quotes) {
                // Row separator
                if ($current_field !== '' || !empty($current_row)) {
                    $current_row[] = $current_field;
                    if (!empty($current_row)) {
                        $rows[] = $current_row;
                    }
                    $current_row = [];
                    $current_field = '';
                }
                // Skip \r\n combinations
                if ($char === "\r" && $i + 1 < $len && $content[$i + 1] === "\n") {
                    $i++;
                }
            } else {
                $current_field .= $char;
            }
            
            $i++;
        }
        
        // Add last field and row if not empty
        if ($current_field !== '' || !empty($current_row)) {
            $current_row[] = $current_field;
            if (!empty($current_row)) {
                $rows[] = $current_row;
            }
        }
        
        return $rows;
    }
}

new GlowBookingCalendar();





// ===== Admin Submenu: Requests =====
function glowbc_render_requests_page(){
    if(!current_user_can('manage_options')){ wp_die('Unauthorized'); }
    global $wpdb; $table = $wpdb->prefix.'glow_bookings'; $cal_table = $wpdb->prefix.'glow_calendars';
    $rows = $wpdb->get_results("SELECT b.*, c.name as calendar_name FROM {$table} b LEFT JOIN {$cal_table} c ON b.calendar_id = c.id WHERE b.status='pending' ORDER BY b.start_date ASC", ARRAY_A);

    echo '<div class="wrap"><h1>Anfragen</h1>';
    echo '<table class="widefat fixed striped"><thead><tr>'
       . '<th>Kalender</th><th>Zeitraum</th><th>Name</th><th>E-Mail</th><th>Personen</th><th>Kinder 0-6</th><th>Kinder 7-16</th><th>Nachricht</th><th>Aktion</th>'
       . '</tr></thead><tbody>';

    foreach($rows as $r){
        $f = json_decode($r['fields'] ?? '{}', true) ?: [];
        $id = intval($r['id']);
        $calendar_name = esc_html($r['calendar_name'] ?? 'Unbekannt');
        $period = esc_html(date_i18n('d.m.Y', strtotime($r['start_date'])) . ' – ' . date_i18n('d.m.Y', strtotime($r['end_date'])));
        $name = esc_html(($f['first_name'] ?? '').' '.($f['last_name'] ?? ''));
        $email = esc_html(($f['email'] ?? ''));
        $persons = intval($f['persons'] ?? 1);
        $k06 = intval($f['kids_0_6'] ?? 0);
        $k716 = intval($f['kids_7_16'] ?? 0);
        $msg = esc_html($f['message'] ?? '');
        echo '<tr data-id="'.$id.'">'
           . '<td>'.$calendar_name.'</td>'
           . '<td>'.$period.'</td>'
           . '<td>'.$name.'</td>'
           . '<td>'.$email.'</td>'
           . '<td>'.$persons.'</td>'
           . '<td>'.$k06.'</td>'
           . '<td>'.$k716.'</td>'
           . '<td style="max-width:280px">'.$msg.'</td>'
           . '<td>
                <button class="button glowbc-req-accept" data-id="'.$id.'">
                    <i class="fas fa-check"></i>
                </button>
                <button type="button" class="button button-secondary glowbc-req-delete" data-id="'.$id.'">
                    <i class="fas fa-times"></i>
                </button>
            </td>

           '
           . '</tr>';
    }
    if(empty($rows)){
        echo '<tr><td colspan="10">Keine Anfragen</td></tr>';
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
            const id = $tr.data('id');
            console.log('ID:', id);

            var $btn = $(this); $btn.prop('disabled', true).text('Verarbeite …');
            $.post(ajaxurl, {action:'glowbc_delete_request', nonce:'<?php echo esc_js(wp_create_nonce('glowbc-nonce')); ?>', id:id}, function(res){
                if(res && res.success){
                    $btn.closest('tr').fadeOut(200, function(){ $(this).remove(); });
                } else {
                    alert((res && res.data && res.data.message) ? res.data.message : 'Fehler');
                    $btn.prop('disabled', false).text('Delete');
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

