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
 * Main Renderer for Important Dates Table
 */
if (!function_exists('sode_university_dates_table_render')) {
    function sode_university_dates_table_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni'        => '',
            'heading'    => '',
            'title'      => '',
            'subtitle'   => '',
            'show_btn'   => 'true',
            'btn_text'   => '',
            'btn_link'   => '#lead-form',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $uni = sode_get_university_dates_data($uni_slug);

        $y = date('Y');
        $session = $y . '-' . substr((string)((int)$y + 1), -2);
        $uni_name = !empty($uni['full_name']) ? $uni['full_name'] : 'University';
        $uni_short = !empty($uni['short_name']) ? $uni['short_name'] : 'University';

        // Title and Subtitle resolution
        $title = !empty($atts['title']) ? $atts['title'] : (!empty($atts['heading']) ? $atts['heading'] : "{$uni_short} Important Dates & Deadlines {$y}");
        $subtitle = !empty($atts['subtitle']) ? $atts['subtitle'] : "Official admission schedule and academic examination calendar for {$session} session.";
        
        $btn_text = !empty($atts['btn_text']) ? $atts['btn_text'] : "Apply for {$y} Admission";
        $btn_link = !empty($atts['btn_link']) ? $atts['btn_link'] : "#lead-form";
        $show_btn = filter_var($atts['show_btn'], FILTER_VALIDATE_BOOLEAN);

        // Prepare Milestone Dates List
        $events = [];

        // 1. Admission Start Date
        if (!empty($uni['admission_start_date'])) {
            $events[] = [
                'name'        => 'Admission Start Date',
                'description' => 'Online application process opens for new session',
                'date'        => $uni['admission_start_date'],
                'badge'       => '',
                'badge_color' => '',
                'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
            ];
        }

        // 2. Admission Last Date (Highlighted with badge)
        if (!empty($uni['admission_last_date'])) {
            $events[] = [
                'name'        => 'Admission Last Date',
                'description' => 'Final deadline for submission of registration form & fees',
                'date'        => $uni['admission_last_date'],
                'badge'       => 'Last Date',
                'badge_color' => '#f59e0b',
                'highlight'   => true,
                'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>'
            ];
        }

        // 3. Assignment Submission Date
        if (!empty($uni['assignment_date'])) {
            $events[] = [
                'name'        => 'Assignment Submission Date',
                'description' => 'Last date to submit internal assignments & project reports',
                'date'        => $uni['assignment_date'],
                'badge'       => '',
                'badge_color' => '',
                'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>'
            ];
        }

        // 4. Term-End Examination Date
        if (!empty($uni['exam_date'])) {
            $events[] = [
                'name'        => 'Term-End Exam Date',
                'description' => 'Semester / Annual examination schedule commencement',
                'date'        => $uni['exam_date'],
                'badge'       => '',
                'badge_color' => '',
                'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>'
            ];
        }

        // 5. Extended Exam Date
        if (!empty($uni['extended_exam_date'])) {
            $events[] = [
                'name'        => 'Extended Exam Date',
                'description' => 'Supplementary / Extended examination window',
                'date'        => $uni['extended_exam_date'],
                'badge'       => 'Extended',
                'badge_color' => '#3b82f6',
                'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline><path d="M16 16l4 4"></path></svg>'
            ];
        }

        // Fallback if no dates were entered in admin panel yet
        if (empty($events)) {
            $events = [
                [
                    'name'        => 'Admission Start Date',
                    'description' => 'Online application process opens for new session',
                    'date'        => '7th September ' . $y,
                    'badge'       => '',
                    'badge_color' => '',
                    'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>'
                ],
                [
                    'name'        => 'Admission Last Date',
                    'description' => 'Final deadline for submission of registration form & fees',
                    'date'        => '30th September ' . $y,
                    'badge'       => 'Last Date',
                    'badge_color' => '#f59e0b',
                    'highlight'   => true,
                    'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 16 14"></polyline></svg>'
                ],
                [
                    'name'        => 'Assignment Submission Date',
                    'description' => 'Last date to submit internal assignments & project reports',
                    'date'        => '15 November ' . $y,
                    'badge'       => '',
                    'badge_color' => '',
                    'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>'
                ],
                [
                    'name'        => 'Term-End Exam Date',
                    'description' => 'Semester / Annual examination schedule commencement',
                    'date'        => 'December ' . $y,
                    'badge'       => '',
                    'badge_color' => '',
                    'icon'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>'
                ]
            ];
        }

        $unique_id = 'sode-dates-' . wp_rand(1000, 9999);
        ob_start();
        ?>
        <style>
            .sode-dates-wrapper {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 2px 6px -1px rgba(0, 0, 0, 0.03);
                padding: 30px 34px;
                margin: 25px 0;
                font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                box-sizing: border-box;
                color: #1e293b;
            }
            .sode-dates-header {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 22px;
                padding-bottom: 18px;
                border-bottom: 1px solid #f1f5f9;
            }
            .sode-dates-icon-box {
                width: 46px;
                height: 46px;
                border-radius: 12px;
                background: linear-gradient(135deg, #e0e7ff 0%, #dbeafe 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                color: #2563eb;
            }
            .sode-dates-title {
                font-size: 22px;
                font-weight: 800;
                color: #0f172a;
                margin: 0 0 4px 0;
                letter-spacing: -0.3px;
                line-height: 1.3;
            }
            .sode-dates-subtitle {
                font-size: 13.5px;
                color: #64748b;
                margin: 0;
                line-height: 1.4;
            }
            .sode-dates-table-container {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
            }
            .sode-dates-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
                background: #ffffff;
            }
            .sode-dates-table thead th {
                background: #f8fafc;
                color: #475569;
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 14px 20px;
                border-bottom: 2px solid #e2e8f0;
            }
            .sode-dates-table tbody tr {
                border-bottom: 1px solid #f1f5f9;
                transition: background-color 0.15s ease;
            }
            .sode-dates-table tbody tr:hover {
                background-color: #f8fafc;
            }
            .sode-dates-table tbody tr:last-child {
                border-bottom: none;
            }
            .sode-dates-table tbody td {
                padding: 16px 20px;
                vertical-align: middle;
            }
            .sode-date-event-cell {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .sode-date-event-icon {
                width: 32px;
                height: 32px;
                border-radius: 8px;
                background: #f1f5f9;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .sode-date-event-name {
                font-size: 14.5px;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 2px;
            }
            .sode-date-event-desc {
                font-size: 12px;
                color: #64748b;
                line-height: 1.35;
            }
            .sode-date-value-box {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .sode-date-value-text {
                font-size: 14.5px;
                font-weight: 700;
                color: #0f172a;
            }
            .sode-date-badge {
                display: inline-block;
                background: #fef3c7;
                color: #b45309;
                border: 1px solid #fde68a;
                font-size: 11px;
                font-weight: 700;
                padding: 2px 8px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .sode-dates-footer {
                margin-top: 18px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 14px;
                padding-top: 14px;
                border-top: 1px solid #f1f5f9;
            }
            .sode-dates-disclaimer {
                font-size: 12px;
                color: #64748b;
                line-height: 1.4;
                max-width: 65%;
            }
            .sode-dates-apply-btn {
                background: #2563eb;
                color: #ffffff !important;
                font-size: 13.5px;
                font-weight: 700;
                padding: 10px 22px;
                border-radius: 8px;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                box-shadow: 0 3px 10px rgba(37, 99, 235, 0.28);
                transition: all 0.2s ease;
            }
            .sode-dates-apply-btn:hover {
                background: #1d4ed8;
                transform: translateY(-1px);
                box-shadow: 0 5px 14px rgba(37, 99, 235, 0.38);
                color: #ffffff !important;
            }
            @media (max-width: 640px) {
                .sode-dates-wrapper {
                    padding: 20px 16px;
                    border-radius: 12px;
                }
                .sode-dates-title {
                    font-size: 18px;
                }
                .sode-dates-footer {
                    flex-direction: column;
                    align-items: stretch;
                }
                .sode-dates-disclaimer {
                    max-width: 100%;
                    text-align: center;
                }
                .sode-dates-apply-btn {
                    text-align: center;
                    justify-content: center;
                    width: 100%;
                }
            }
        </style>

        <div class="sode-dates-wrapper" id="<?php echo esc_attr($unique_id); ?>">
            <div class="sode-dates-header">
                <div class="sode-dates-icon-box">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                </div>
                <div>
                    <h3 class="sode-dates-title"><?php echo esc_html($title); ?></h3>
                    <p class="sode-dates-subtitle"><?php echo esc_html($subtitle); ?></p>
                </div>
            </div>

            <div class="sode-dates-table-container">
                <table class="sode-dates-table">
                    <thead>
                        <tr>
                            <th style="width: 58%;">Event / Milestone</th>
                            <th style="width: 42%;">Important Date & Deadline</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $e): ?>
                            <tr>
                                <td>
                                    <div class="sode-date-event-cell">
                                        <span class="sode-date-event-icon">
                                            <?php echo $e['icon']; ?>
                                        </span>
                                        <div>
                                            <div class="sode-date-event-name">
                                                <?php echo esc_html($e['name']); ?>
                                            </div>
                                            <div class="sode-date-event-desc">
                                                <?php echo esc_html($e['description']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="sode-date-value-box">
                                        <span class="sode-date-value-text" style="<?php echo !empty($e['highlight']) ? 'color:#b45309;' : ''; ?>">
                                            <?php echo esc_html($e['date']); ?>
                                        </span>
                                        <?php if (!empty($e['badge'])): ?>
                                            <span class="sode-date-badge" style="<?php echo !empty($e['badge_color']) ? 'background:rgba(245,158,11,0.15); color:#b45309; border-color:rgba(245,158,11,0.3);' : ''; ?>">
                                                <?php echo esc_html($e['badge']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="sode-dates-footer">
                <div class="sode-dates-disclaimer">
                    ℹ️ <em>Note: Dates and examination schedules are subject to university and regulatory notifications. Students are advised to submit applications before the admission deadline.</em>
                </div>
                <?php if ($show_btn): ?>
                    <a href="<?php echo esc_url($btn_link); ?>" class="sode-dates-apply-btn">
                        <span><?php echo esc_html($btn_text); ?></span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                    </a>
                <?php endif; ?>
            </div>
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
