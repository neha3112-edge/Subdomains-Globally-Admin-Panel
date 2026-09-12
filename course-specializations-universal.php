<?php
/**
 * ====================================================================
 * Universal Course Specializations Component
 * File: course-specializations-universal.php
 * 
 * Renders a clean 3-column table of specializations with optional URLs,
 * semester fees, and durations for any university course (e.g. Online MBA).
 * 
 * Shortcodes:
 *  1. [course_specializations course="mba" mode="online"]
 *  2. [course_specialization_table course="mba" mode="online"]
 *  3. [course_specialization course="mba" mode="online"]
 *  4. [specializations_table course="mba" mode="online"]
 *  5. [university_course_specializations course="mba" mode="online"]
 *  6. [uni_course_specializations course="mba" mode="online"]
 * ====================================================================
 */

if (defined('SODE_COURSE_SPECIALIZATIONS_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_COURSE_SPECIALIZATIONS_UNIVERSAL_LOADED', true);

// Polyfills
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $title), '-'));
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
 * Format fee amount with currency prefix
 */
if (!function_exists('sode_format_spec_fee_display')) {
    function sode_format_spec_fee_display($amount, $currency = 'INR')
    {
        $val = trim((string) $amount);
        if ($val === '') {
            return '';
        }

        // Clean out existing currency symbols or text if already present
        $cleaned = preg_replace('/^(INR|RS\.?|₹|\$)\s*/i', '', $val);
        $cleaned = trim($cleaned);

        if ($cleaned === '') {
            return '';
        }

        // Return formatted as "INR 32,875"
        return trim($currency) . ' ' . $cleaned;
    }
}

/**
 * Helper: Fetch course specializations data from DB or Central API
 */
if (!function_exists('sode_get_course_specializations_data')) {
    function sode_get_course_specializations_data($uni_slug = '', $course_slug = 'mba', $mode = 'Online')
    {
        static $cache = [];

        // 1. Auto-detect uni_slug if empty
        if (empty($uni_slug)) {
            if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
                $uni_slug = sanitize_title(SODE_UNIVERSITY_SLUG);
            } elseif (function_exists('sode_client_detect_uni')) {
                $uni_slug = sode_client_detect_uni();
            } else {
                $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
                $parts = explode('.', $host);
                if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
                    $uni_slug = sanitize_title($parts[0]);
                }
            }
        }
        if (empty($uni_slug)) {
            $uni_slug = 'dsu';
        }

        $course_slug = !empty($course_slug) ? trim(strtolower($course_slug)) : 'mba';
        $mode_clean = (stripos($mode, 'dist') !== false) ? 'Distance' : 'Online';

        $cache_key = $uni_slug . '_' . $course_slug . '_' . strtolower($mode_clean);
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $specializations = [];
        $uni_name = strtoupper($uni_slug);
        $course_name = strtoupper($course_slug);

        // 2. Try Local DB if available
        if (!function_exists('get_db_connection')) {
            $possible_configs = [
                __DIR__ . '/admin/config/config.php',
                dirname(__DIR__) . '/admin/config/config.php',
                dirname(__DIR__, 2) . '/admin/config/config.php',
            ];
            foreach ($possible_configs as $cfg_file) {
                if (file_exists($cfg_file)) {
                    require_once $cfg_file;
                    break;
                }
            }
        }

        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    // Find Uni
                    $u_stmt = $db->prepare("SELECT id, full_name, short_name FROM universities WHERE (LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) OR LOWER(full_name) = LOWER(?)) AND is_active = 1 LIMIT 1");
                    $u_stmt->execute([$uni_slug, $uni_slug, $uni_slug]);
                    $found_uni = $u_stmt->fetch(PDO::FETCH_ASSOC);

                    // Find Course
                    $c_stmt = $db->prepare("SELECT id, full_name, short_name FROM courses WHERE (LOWER(short_name) = LOWER(?) OR LOWER(slug) = LOWER(?) OR LOWER(full_name) = LOWER(?)) LIMIT 1");
                    $c_stmt->execute([$course_slug, $course_slug, $course_slug]);
                    $found_course = $c_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($found_uni && $found_course) {
                        $uni_name = $found_uni['short_name'] ?: $found_uni['full_name'];
                        $course_name = $found_course['short_name'] ?: $found_course['full_name'];

                        $m_stmt = $db->prepare("
                            SELECT id, mode 
                            FROM university_course_mappings 
                            WHERE university_id = ? AND course_id = ? AND LOWER(mode) = LOWER(?) 
                            LIMIT 1
                        ");
                        $m_stmt->execute([$found_uni['id'], $found_course['id'], $mode_clean]);
                        $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);

                        // Fallback to any mode if exact mode not found
                        if (!$mapping) {
                            $m_stmt = $db->prepare("
                                SELECT id, mode 
                                FROM university_course_mappings 
                                WHERE university_id = ? AND course_id = ? 
                                LIMIT 1
                            ");
                            $m_stmt->execute([$found_uni['id'], $found_course['id']]);
                            $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);
                        }

                        if ($mapping) {
                            $s_stmt = $db->prepare("
                                SELECT specialization_name, specialization_link, fees_per_sem, duration 
                                FROM course_specializations 
                                WHERE mapping_id = ? 
                                ORDER BY id ASC
                            ");
                            $s_stmt->execute([$mapping['id']]);
                            $specializations = $s_stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("sode_get_course_specializations_data local error: " . $e->getMessage());
            }
        }

        // 3. Central Admin API Remote Call if empty
        if (empty($specializations)) {
            $api_base = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $params = http_build_query([
                'uni'    => $uni_slug,
                'course' => $course_slug,
                'mode'   => $mode_clean
            ]);
            $endpoints = [
                $api_base . '/admin/api/get_course_specializations.php?' . $params,
                $api_base . '/api/get_course_specializations.php?' . $params,
            ];

            foreach ($endpoints as $url) {
                $raw = '';
                if (function_exists('wp_remote_get')) {
                    $resp = wp_remote_get($url, ['timeout' => 8, 'sslverify' => false]);
                    if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                        $raw = wp_remote_retrieve_body($resp);
                    }
                } elseif (function_exists('file_get_contents')) {
                    $ctx = stream_context_create(['http' => ['timeout' => 8], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
                    $raw = @file_get_contents($url, false, $ctx);
                }

                if (!empty($raw)) {
                    $json = json_decode($raw, true);
                    if (!empty($json['success']) && !empty($json['specializations']) && is_array($json['specializations'])) {
                        $specializations = $json['specializations'];
                        if (!empty($json['university_name']))
                            $uni_name = $json['university_name'];
                        if (!empty($json['course_name']))
                            $course_name = $json['course_name'];
                        break;
                    }
                }
            }
        }

        $result = [
            'university'      => $uni_name,
            'course'          => $course_name,
            'mode'            => $mode_clean,
            'specializations' => $specializations,
        ];

        $cache[$cache_key] = $result;
        return $result;
    }
}

/**
 * Main Render Function for Course Specializations Table
 */
if (!function_exists('sode_course_specializations_render')) {
    function sode_course_specializations_render($atts = [])
    {
        $atts = shortcode_atts([
            'uni'        => '',
            'university' => '',
            'course'     => 'mba',
            'mode'       => 'Online',
            'currency'   => 'INR',
            'class'      => '',
        ], $atts, 'course_specializations');

        $uni = !empty($atts['uni']) ? $atts['uni'] : $atts['university'];
        $course = $atts['course'];
        $mode = $atts['mode'];
        $currency = !empty($atts['currency']) ? trim($atts['currency']) : 'INR';
        $custom_class = trim($atts['class'] ?? '');

        // Fetch data
        $data = sode_get_course_specializations_data($uni, $course, $mode);
        $specs = $data['specializations'] ?? [];

        // Fallback default sample if empty to prevent broken UI
        if (empty($specs)) {
            $specs = [
                ['specialization_name' => 'Financial Management', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
                ['specialization_name' => 'Human Resource Management (HRM)', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
                ['specialization_name' => 'Marketing Management', 'specialization_link' => '', 'fees_per_sem' => '3,750', 'duration' => '2 Years'],
                ['specialization_name' => 'IT & Systems Management', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
                ['specialization_name' => 'Supply Chain Management', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
                ['specialization_name' => 'Entrepreneurship', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
                ['specialization_name' => 'Business Analytics', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
                ['specialization_name' => 'Artificial Intelligence', 'specialization_link' => '', 'fees_per_sem' => '32,875', 'duration' => '2 Years'],
            ];
        }

        $uid = 'sode_spec_' . substr(md5(uniqid(rand(), true)), 0, 8);

        ob_start();
        ?>
        <div class="sode-course-specs-wrapper <?php echo htmlspecialchars($custom_class); ?>" id="<?php echo $uid; ?>">
            <div class="sode-specs-scroll">
                <table class="sode-specs-table">
                    <thead>
                        <tr>
                            <th class="sode-specs-th-name">SPECIALIZATION</th>
                            <th class="sode-specs-th-fee">FEES (FIRST SEMESTER)</th>
                            <th class="sode-specs-th-duration">DURATION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($specs as $s): ?>
                            <tr>
                                <td class="sode-specs-td-name">
                                    <?php if (!empty($s['specialization_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($s['specialization_link']); ?>" target="_blank" rel="noopener" class="sode-spec-link">
                                            <?php echo htmlspecialchars($s['specialization_name']); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($s['specialization_name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="sode-specs-td-fee">
                                    <?php echo htmlspecialchars(sode_format_spec_fee_display($s['fees_per_sem'], $currency)); ?>
                                </td>
                                <td class="sode-specs-td-duration">
                                    <?php echo htmlspecialchars($s['duration'] ?? '2 Years'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
            #<?php echo $uid; ?>.sode-course-specs-wrapper {
                width: 100%;
                margin: 0px;
                box-sizing: border-box;
            }

            #<?php echo $uid; ?> .sode-specs-scroll {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            }

            #<?php echo $uid; ?> .sode-specs-table {
                width: 100%;
                border-collapse: collapse;
                border-spacing: 0;
                text-align: left;
                margin: 0px;
            }

            #<?php echo $uid; ?> .sode-specs-table thead tr {
                background: #EBF3FC;
                border-bottom: 1px solid #CBD5E1;
            }

            #<?php echo $uid; ?> .sode-specs-table th {
                padding: 13px 20px;
                font-size: 13.5px;
                font-weight: 800 !important;
                color: #0F172A;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border: none;
                line-height: 1.4;
            }

            #<?php echo $uid; ?> .sode-specs-table th.sode-specs-th-name {
                width: 48%;
                border-right: 1px solid #CBD5E1;
            }

            #<?php echo $uid; ?> .sode-specs-table th.sode-specs-th-fee {
                width: 28%;
                border-right: 1px solid #CBD5E1;
            }

            #<?php echo $uid; ?> .sode-specs-table th.sode-specs-th-duration {
                width: 24%;
            }

            #<?php echo $uid; ?> .sode-specs-table tbody tr {
                border-bottom: 1px solid #E2E8F0;
                transition: background-color 0.15s ease;
                background: #ffffff;
            }

            #<?php echo $uid; ?> .sode-specs-table tbody tr:last-child {
                border-bottom: none;
            }

            #<?php echo $uid; ?> .sode-specs-table tbody tr:hover {
                background-color: #F8FAFC;
            }

            #<?php echo $uid; ?> .sode-specs-table td {
                padding: 13px 20px;
                border: none;
                line-height: 1.5;
            }

            #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-name {
                font-size: 13.5px;
                font-weight: 600 !important;
                color: #0F172A;
                width: 48%;
                border-right: 1px solid #E2E8F0;
            }

            #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-name a.sode-spec-link {
                color: #0F172A;
                text-decoration: none;
                font-weight: 600 !important;
                transition: color 0.15s ease;
            }

            #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-name a.sode-spec-link:hover {
                color: #2563EB;
                text-decoration: underline;
            }

            #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-fee {
                font-size: 13.5px;
                font-weight: 500;
                color: #334155;
                width: 28%;
                white-space: nowrap;
                border-right: 1px solid #E2E8F0;
            }

            #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-duration {
                font-size: 13.5px;
                font-weight: 500;
                color: #334155;
                width: 24%;
                white-space: nowrap;
            }

            @media (max-width: 640px) {
                #<?php echo $uid; ?> .sode-specs-table th {
                    padding: 11px 14px;
                    font-size: 12px;
                }

                #<?php echo $uid; ?> .sode-specs-table td {
                    padding: 10px 14px;
                    font-size: 12.5px;
                }

                #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-name {
                    font-size: 12.5px;
                    width: 45%;
                }

                #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-fee {
                    font-size: 12.5px;
                    width: 30%;
                }

                #<?php echo $uid; ?> .sode-specs-table td.sode-specs-td-duration {
                    font-size: 12.5px;
                    width: 25%;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Register shortcodes directly if WordPress add_shortcode exists
if (function_exists('add_shortcode')) {
    add_shortcode('course_specializations', 'sode_course_specializations_render');
    add_shortcode('course_specialization_table', 'sode_course_specializations_render');
    add_shortcode('course_specialization', 'sode_course_specializations_render');
    add_shortcode('specializations_table', 'sode_course_specializations_render');
    add_shortcode('university_course_specializations', 'sode_course_specializations_render');
    add_shortcode('uni_course_specializations', 'sode_course_specializations_render');
}
