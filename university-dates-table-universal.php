<?php
/**
 * Universal University Important Dates & Deadlines Table Component
 * File: university-dates-table-universal.php
 * 
 * Renders an elegant, modern, responsive table of important dates and deadlines
 * dynamically per university from the admin panel (Section 3: Important Dates & Deadlines).
 * 
 * Shortcodes:
 * [university_dates]
 * [important_dates]
 * [uni_important_dates]
 * [admission_dates]
 * [dates_table]
 * [university_dates_table]
 * 
 * Individual Text Shortcodes:
 * [admission_last_date]
 * [admission_start_date]
 * [exam_date]
 * [extended_exam_date]
 * [assignment_date]
 */

if (defined('SODE_UNIVERSITY_DATES_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_UNIVERSITY_DATES_UNIVERSAL_LOADED', true);

// Safe Polyfills for WordPress functions
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $title), '-'));
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return filter_var((string) $url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 0)
    {
        return ($max > $min) ? mt_rand($min, $max) : mt_rand();
    }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($pairs, $atts, $shortcode = '')
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($pairs as $name => $default) {
            if (array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            } else {
                $out[$name] = $default;
            }
        }
        return $out;
    }
}

if (!function_exists('sode_format_admin_date_for_db')) {
    function sode_format_admin_date_for_db($raw)
    {
        $raw = trim($raw ?? '');
        if (empty($raw)) return '';
        $ts = strtotime($raw);
        if ($ts !== false && $ts > 0) {
            return date('d M Y', $ts); // e.g. "15 Nov 2026"
        }
        return $raw;
    }
}

if (!function_exists('sode_get_default_dates')) {
    function sode_get_default_dates($db = null)
    {
        $current_year = date('Y');
        $defaults = [
            'admission_last_date'  => '30 Sep ' . $current_year,
            'exam_date'            => '15 Dec ' . $current_year,
            'extended_exam_date'   => '15 Jan ' . ((int)$current_year + 1),
            'admission_start_date' => '01 Jun ' . $current_year,
            'assignment_date'      => '15 Nov ' . $current_year,
        ];

        if (!$db && function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
            } catch (Exception $e) {}
        }

        if ($db) {
            try {
                $stmt = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'dates'");
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!empty($rows['default_admission_last_date'])) {
                    $defaults['admission_last_date'] = sode_format_admin_date_for_db($rows['default_admission_last_date']);
                }
                if (!empty($rows['default_exam_date'])) {
                    $defaults['exam_date'] = sode_format_admin_date_for_db($rows['default_exam_date']);
                }
                if (!empty($rows['default_extended_exam_date'])) {
                    $defaults['extended_exam_date'] = sode_format_admin_date_for_db($rows['default_extended_exam_date']);
                }
                if (!empty($rows['default_admission_start_date'])) {
                    $defaults['admission_start_date'] = sode_format_admin_date_for_db($rows['default_admission_start_date']);
                }
                if (!empty($rows['default_assignment_date'])) {
                    $defaults['assignment_date'] = sode_format_admin_date_for_db($rows['default_assignment_date']);
                }
            } catch (Exception $e) {}
        }

        return $defaults;
    }
}

/**
 * Fetch University Dates & Profile
 */
if (!function_exists('sode_get_university_dates_data')) {
    function sode_get_university_dates_data($uni_slug = '')
    {
        // Auto-detect university slug from hostname or constant
        if (empty($uni_slug)) {
            if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
                $uni_slug = sanitize_title(SODE_UNIVERSITY_SLUG);
            } else {
                $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
                $parts = explode('.', $host);
                if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
                    $uni_slug = sanitize_title($parts[0]);
                }
            }
        }
        $uni_slug = strtolower(trim((string) $uni_slug));

        $uni_data = null;

        // 1. Direct Local DB query
        if (!function_exists('get_db_connection')) {
            $possible_configs = [
                __DIR__ . '/admin/config/config.php',
                dirname(__DIR__) . '/admin/config/config.php',
                dirname(__DIR__, 2) . '/admin/config/config.php',
            ];
            foreach ($possible_configs as $cfg) {
                if (file_exists($cfg)) {
                    require_once $cfg;
                    break;
                }
            }
        }

        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    if (!empty($uni_slug)) {
                        $stmt = $db->prepare("
                            SELECT id, full_name, short_name, slug, mode, 
                                   admission_start_date, admission_last_date, 
                                   exam_date, extended_exam_date, assignment_date
                            FROM universities 
                            WHERE LOWER(slug) = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$uni_slug, $uni_slug, $uni_slug]);
                        $uni_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$uni_data) {
                        $uni_data = $db->query("
                            SELECT id, full_name, short_name, slug, mode, 
                                   admission_start_date, admission_last_date, 
                                   exam_date, extended_exam_date, assignment_date
                            FROM universities 
                            WHERE is_active = 1 
                            ORDER BY id ASC 
                            LIMIT 1
                        ")->fetch(PDO::FETCH_ASSOC);
                    }
                }
            } catch (Exception $e) {
            }
        }

        // 2. Central API fallback (if running remotely - Live & Real-Time)
        if (!$uni_data) {
            $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? SODE_CENTRAL_ADMIN_URL : 'https://admin.distanceeducationschool.com';
            $api_url = rtrim($admin_url, '/') . '/api/get_university_banner.php?uni=' . urlencode($uni_slug ?: 'dsu') . '&_t=' . time();

            $body = false;
            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($api_url, [
                    'timeout' => 5,
                    'headers' => [
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache'
                    ]
                ]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $body = wp_remote_retrieve_body($resp);
                }
            } elseif (function_exists('curl_init')) {
                $ch = curl_init($api_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [
                        'Cache-Control: no-cache, no-store, must-revalidate',
                        'Pragma: no-cache'
                    ]
                ]);
                $body = curl_exec($ch);
                curl_close($ch);
            }

            if ($body) {
                $json = json_decode($body, true);
                if (!empty($json['data'])) {
                    $uni_data = $json['data'];
                }
            }
        }

        // 3. Fallback defaults if not found
        if (!$uni_data) {
            $def_dates = sode_get_default_dates();
            $uni_data = [
                'full_name' => !empty($uni_slug) ? ucwords(str_replace(['-', '_'], ' ', $uni_slug)) . ' Online' : 'Dayananda Sagar University Online',
                'short_name' => !empty($uni_slug) ? strtoupper($uni_slug) : 'DSU',
                'slug' => $uni_slug ?: 'dsu',
                'mode' => 'Online & Distance',
                'admission_start_date' => $def_dates['admission_start_date'],
                'admission_last_date' => $def_dates['admission_last_date'],
                'assignment_date' => $def_dates['assignment_date'],
                'exam_date' => $def_dates['exam_date'],
                'extended_exam_date' => $def_dates['extended_exam_date'],
            ];
        }

        return $uni_data;
    }
}

/**
 * Main Renderer for Important Dates Table (Matches exact user design: EVENT & DATE table)
 */
if (!function_exists('sode_university_dates_table_render')) {
    function sode_university_dates_table_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni' => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $uni = sode_get_university_dates_data($uni_slug);

        $def_dates = sode_get_default_dates();

        $adm_last = !empty($uni['admission_last_date']) ? sode_format_admin_date_for_db($uni['admission_last_date']) : $def_dates['admission_last_date'];
        $adm_start = !empty($uni['admission_start_date']) ? sode_format_admin_date_for_db($uni['admission_start_date']) : $def_dates['admission_start_date'];
        $exam_d = !empty($uni['exam_date']) ? sode_format_admin_date_for_db($uni['exam_date']) : $def_dates['exam_date'];
        $ext_exam = !empty($uni['extended_exam_date']) ? sode_format_admin_date_for_db($uni['extended_exam_date']) : $def_dates['extended_exam_date'];
        $asg_d = !empty($uni['assignment_date']) ? sode_format_admin_date_for_db($uni['assignment_date']) : $def_dates['assignment_date'];

        // Events mapping according to user screenshot
        $rows = [];

        if (!empty($adm_last)) {
            $rows[] = [
                'event' => 'Application Deadline',
                'date' => $adm_last
            ];
        }

        if (!empty($adm_start)) {
            $rows[] = [
                'event' => 'Semester Commencement',
                'date' => $adm_start
            ];
        }

        if (!empty($exam_d)) {
            $rows[] = [
                'event' => 'Examination Commencement',
                'date' => $exam_d
            ];
        }

        if (!empty($ext_exam)) {
            $rows[] = [
                'event' => 'Extended Examination',
                'date' => $ext_exam
            ];
        }

        if (!empty($asg_d)) {
            $rows[] = [
                'event' => 'Assignment Submission',
                'date' => $asg_d
            ];
        }

        ob_start();
        ?>
        <style>
            .sode-uni-dates-table-container {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0px;
            }

            .sode-uni-dates-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                background: #ffffff;
                font-family: inherit;
                border: 1px solid #d5e0ea;
                margin: 0px;
            }

            .sode-uni-dates-table th {
                background: #e8f3fe;
                color: #000000;
                font-size: 14.5px;
                font-weight: 700;
                letter-spacing: 0.2px;
                text-transform: uppercase;
                padding: 14px 20px;
                border: 1px solid #d5e0ea;
            }

            .sode-uni-dates-table td {
                padding: 14px 20px;
                border: 1px solid #d5e0ea;
                vertical-align: middle;
            }

            .sode-uni-dates-table td.sode-date-event-title {
                font-size: 15px;
                font-weight: 600;
                color: #000000;
                width: 60%;
            }

            .sode-uni-dates-table td.sode-date-value {
                font-size: 14.5px;
                font-weight: 400;
                color: #111827;
                width: 40%;
            }

            @media (max-width: 600px) {

                .sode-uni-dates-table th,
                .sode-uni-dates-table td {
                    padding: 10px 14px;
                    font-size: 13.5px;
                }

                .sode-uni-dates-table td.sode-date-event-title {
                    font-size: 14px;
                }
            }
        </style>

        <div class="sode-uni-dates-table-container">
            <table class="sode-uni-dates-table">
                <thead>
                    <tr>
                        <th style="width: 60%;">EVENT</th>
                        <th style="width: 40%;">DATE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="sode-date-event-title"><?php echo esc_html($r['event']); ?></td>
                            <td class="sode-date-value"><?php echo esc_html($r['date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Register Shortcodes in WordPress environment
if (function_exists('add_shortcode')) {
    add_shortcode('university_dates', 'sode_university_dates_table_render');
    add_shortcode('important_dates', 'sode_university_dates_table_render');
    add_shortcode('uni_important_dates', 'sode_university_dates_table_render');
    add_shortcode('admission_dates', 'sode_university_dates_table_render');
    add_shortcode('dates_table', 'sode_university_dates_table_render');
    add_shortcode('university_dates_table', 'sode_university_dates_table_render');

    // Individual Date Shortcodes (Auto-formatted as 15 Nov 2026 with default fallback)
    add_shortcode('admission_last_date', function ($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        $def = sode_get_default_dates();
        $val = !empty($uni['admission_last_date']) ? $uni['admission_last_date'] : $def['admission_last_date'];
        return esc_html(sode_format_admin_date_for_db($val));
    });

    add_shortcode('admission_start_date', function ($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        $def = sode_get_default_dates();
        $val = !empty($uni['admission_start_date']) ? $uni['admission_start_date'] : $def['admission_start_date'];
        return esc_html(sode_format_admin_date_for_db($val));
    });

    add_shortcode('exam_date', function ($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        $def = sode_get_default_dates();
        $val = !empty($uni['exam_date']) ? $uni['exam_date'] : $def['exam_date'];
        return esc_html(sode_format_admin_date_for_db($val));
    });

    add_shortcode('extended_exam_date', function ($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        $def = sode_get_default_dates();
        $val = !empty($uni['extended_exam_date']) ? $uni['extended_exam_date'] : $def['extended_exam_date'];
        return esc_html(sode_format_admin_date_for_db($val));
    });

    add_shortcode('assignment_date', function ($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        $def = sode_get_default_dates();
        $val = !empty($uni['assignment_date']) ? $uni['assignment_date'] : $def['assignment_date'];
        return esc_html(sode_format_admin_date_for_db($val));
    });
}
