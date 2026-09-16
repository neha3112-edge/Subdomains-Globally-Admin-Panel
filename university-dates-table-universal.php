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

/**
 * Fetch University Dates & Profile
 */
if (!function_exists('sode_get_university_dates_data')) {
    function sode_get_university_dates_data($uni_slug = '')
    {
        static $dates_cache = [];

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
        $uni_slug = strtolower(trim((string)$uni_slug));

        if (isset($dates_cache[$uni_slug])) {
            return $dates_cache[$uni_slug];
        }

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
            } catch (Exception $e) {}
        }

        // 2. Central API fallback (if running remotely)
        if (!$uni_data) {
            $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? SODE_CENTRAL_ADMIN_URL : 'https://admin.distanceeducationschool.com';
            $api_url = rtrim($admin_url, '/') . '/api/get_university_banner.php?uni=' . urlencode($uni_slug ?: 'dsu');

            $body = false;
            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($api_url, ['timeout' => 5]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $body = wp_remote_retrieve_body($resp);
                }
            } elseif (function_exists('curl_init')) {
                $ch = curl_init($api_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
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
            $current_year = date('Y');
            $uni_data = [
                'full_name'            => !empty($uni_slug) ? ucwords(str_replace(['-', '_'], ' ', $uni_slug)) . ' Online' : 'Dayananda Sagar University Online',
                'short_name'           => !empty($uni_slug) ? strtoupper($uni_slug) : 'DSU',
                'slug'                 => $uni_slug ?: 'dsu',
                'mode'                 => 'Online & Distance',
                'admission_start_date' => '7th September ' . $current_year,
                'admission_last_date'  => '30th September ' . $current_year,
                'assignment_date'      => '15 November ' . $current_year,
                'exam_date'            => 'December ' . $current_year,
                'extended_exam_date'   => '15 Jan ' . ((int)$current_year + 1),
            ];
        }

        $dates_cache[$uni_slug] = $uni_data;
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
            'uni'        => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $uni = sode_get_university_dates_data($uni_slug);

        $y = date('Y');

        // Events mapping according to user screenshot
        $rows = [];

        if (!empty($uni['admission_last_date'])) {
            $rows[] = [
                'event' => 'Application Deadline',
                'date'  => trim($uni['admission_last_date'])
            ];
        }

        if (!empty($uni['admission_start_date'])) {
            $rows[] = [
                'event' => 'Semester Commencement',
                'date'  => trim($uni['admission_start_date'])
            ];
        }

        if (!empty($uni['exam_date'])) {
            $rows[] = [
                'event' => 'Examination Commencement',
                'date'  => trim($uni['exam_date'])
            ];
        }

        if (!empty($uni['extended_exam_date'])) {
            $rows[] = [
                'event' => 'Extended Examination',
                'date'  => trim($uni['extended_exam_date'])
            ];
        }

        if (!empty($uni['assignment_date'])) {
            $rows[] = [
                'event' => 'Assignment Submission',
                'date'  => trim($uni['assignment_date'])
            ];
        }

        // Fallback default sample if empty
        if (empty($rows)) {
            $rows = [
                ['event' => 'Application Deadline', 'date' => '15th October ' . $y . ' *'],
                ['event' => 'Semester Commencement', 'date' => 'October ' . $y . '*'],
                ['event' => 'Examination Commencement', 'date' => 'December ' . $y . '*']
            ];
        }

        ob_start();
        ?>
        <style>
            .sode-uni-dates-table-container {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 15px 0;
            }
            .sode-uni-dates-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                background: #ffffff;
                font-family: inherit;
                border: 1px solid #d5e0ea;
            }
            .sode-uni-dates-table th {
                background: #e8f3fe;
                color: #000000;
                font-size: 14.5px;
                font-weight: 800;
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
                font-weight: 700;
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

    // Individual Date Shortcodes
    add_shortcode('admission_last_date', function($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        return esc_html($uni['admission_last_date'] ?? '');
    });

    add_shortcode('admission_start_date', function($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        return esc_html($uni['admission_start_date'] ?? '');
    });

    add_shortcode('exam_date', function($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        return esc_html($uni['exam_date'] ?? '');
    });

    add_shortcode('extended_exam_date', function($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        return esc_html($uni['extended_exam_date'] ?? '');
    });

    add_shortcode('assignment_date', function($atts) {
        $atts = shortcode_atts(['uni' => '', 'university' => ''], $atts);
        $uni = sode_get_university_dates_data(!empty($atts['university']) ? $atts['university'] : $atts['uni']);
        return esc_html($uni['assignment_date'] ?? '');
    });
}
