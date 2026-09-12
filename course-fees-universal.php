<?php
/**
 * ====================================================================
 * Universal Course Fees Component
 * File: course-fees-universal.php
 * 
 * Renders a clean 2-column fee structure comparison table for any course
 * and mode (e.g. Online MBA) for universities.
 * 
 * Shortcodes:
 *  1. [course_fees course="mba" mode="online"]
 *  2. [course_fee_table course="mba" mode="online"]
 *  3. [course_fee course="mba" mode="online"]
 *  4. [fee_structure course="mba" mode="online"]
 *  5. [university_course_fees course="mba" mode="online"]
 *  6. [uni_course_fees course="mba" mode="online"]
 * ====================================================================
 */

if (defined('SODE_COURSE_FEES_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_COURSE_FEES_UNIVERSAL_LOADED', true);

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
if (!function_exists('sode_format_fee_display')) {
    function sode_format_fee_display($amount, $currency = 'INR')
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

        // Return formatted as "INR 61,000"
        return trim($currency) . ' ' . $cleaned;
    }
}

/**
 * Helper: Fetch course fees data from DB or Central API
 */
if (!function_exists('sode_get_course_fees_data')) {
    function sode_get_course_fees_data($uni_slug = '', $course_slug = 'mba', $mode = 'Online')
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

        $fees = [];
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
                            SELECT one_time_processing_fee, tuition_fee, examination_fee, per_semester_fee, total_program_fee, mode 
                            FROM university_course_mappings 
                            WHERE university_id = ? AND course_id = ? AND LOWER(mode) = LOWER(?) 
                            LIMIT 1
                        ");
                        $m_stmt->execute([$found_uni['id'], $found_course['id'], $mode_clean]);
                        $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);

                        // Fallback to any mode if exact mode not found
                        if (!$mapping) {
                            $m_stmt = $db->prepare("
                                SELECT one_time_processing_fee, tuition_fee, examination_fee, per_semester_fee, total_program_fee, mode 
                                FROM university_course_mappings 
                                WHERE university_id = ? AND course_id = ? 
                                LIMIT 1
                            ");
                            $m_stmt->execute([$found_uni['id'], $found_course['id']]);
                            $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);
                        }

                        if ($mapping) {
                            $fees = [
                                'one_time_processing_fee' => $mapping['one_time_processing_fee'] ?? '',
                                'tuition_fee'             => $mapping['tuition_fee'] ?? '',
                                'examination_fee'         => $mapping['examination_fee'] ?? '',
                                'per_semester_fee'        => $mapping['per_semester_fee'] ?? '',
                                'total_program_fee'       => $mapping['total_program_fee'] ?? '',
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("sode_get_course_fees_data local error: " . $e->getMessage());
            }
        }

        // 3. Central Admin API Remote Call if empty
        if (empty($fees) || (!array_filter($fees))) {
            $api_base = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $params = http_build_query([
                'uni'    => $uni_slug,
                'course' => $course_slug,
                'mode'   => $mode_clean
            ]);
            $endpoints = [
                $api_base . '/admin/api/get_course_fees.php?' . $params,
                $api_base . '/api/get_course_fees.php?' . $params,
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
                    if (!empty($json['success']) && !empty($json['fees']) && is_array($json['fees'])) {
                        $fees = $json['fees'];
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
            'university' => $uni_name,
            'course'     => $course_name,
            'mode'       => $mode_clean,
            'fees'       => $fees,
        ];

        $cache[$cache_key] = $result;
        return $result;
    }
}

/**
 * Main Render Function for Course Fees Table
 */
if (!function_exists('sode_course_fees_render')) {
    function sode_course_fees_render($atts = [])
    {
        $atts = shortcode_atts([
            'uni'               => '',
            'university'        => '',
            'course'            => 'mba',
            'mode'              => 'Online',
            'currency'          => 'INR',
            'show_per_semester' => '',
            'class'             => '',
        ], $atts, 'course_fees');

        $uni = !empty($atts['uni']) ? $atts['uni'] : $atts['university'];
        $course = $atts['course'];
        $mode = $atts['mode'];
        $currency = !empty($atts['currency']) ? trim($atts['currency']) : 'INR';
        $show_per_sem = in_array(strtolower(trim((string) $atts['show_per_semester'])), ['true', '1', 'yes', 'on']);
        $custom_class = trim($atts['class'] ?? '');

        // Fetch data
        $data = sode_get_course_fees_data($uni, $course, $mode);
        $fees = $data['fees'] ?? [];

        // Build list of fee rows to display
        $rows = [];

        // 1. One-time Processing Fee
        if (!empty($fees['one_time_processing_fee'])) {
            $rows[] = [
                'component' => 'One-time Processing Fee',
                'amount'    => sode_format_fee_display($fees['one_time_processing_fee'], $currency),
            ];
        }

        // 2. Tuition Fee
        if (!empty($fees['tuition_fee'])) {
            $rows[] = [
                'component' => 'Tuition Fee',
                'amount'    => sode_format_fee_display($fees['tuition_fee'], $currency),
            ];
        }

        // 3. Examination Fee
        if (!empty($fees['examination_fee'])) {
            $rows[] = [
                'component' => 'Examination Fee',
                'amount'    => sode_format_fee_display($fees['examination_fee'], $currency),
            ];
        }

        // 4. Per Semester Fee (optional if requested or if tuition fee is missing)
        if (!empty($fees['per_semester_fee']) && ($show_per_sem || empty($fees['tuition_fee']))) {
            $rows[] = [
                'component' => 'Per Semester Fee',
                'amount'    => sode_format_fee_display($fees['per_semester_fee'], $currency),
            ];
        }

        // 5. Total Program Fee
        if (!empty($fees['total_program_fee'])) {
            $rows[] = [
                'component' => 'Total Program Fee',
                'amount'    => sode_format_fee_display($fees['total_program_fee'], $currency),
            ];
        }

        // Fallback default sample if empty to avoid broken UI
        if (empty($rows)) {
            $rows = [
                ['component' => 'One-time Processing Fee', 'amount' => $currency . ' 500'],
                ['component' => 'Tuition Fee',             'amount' => $currency . ' 61,000'],
                ['component' => 'Examination Fee',         'amount' => $currency . ' 3,750'],
                ['component' => 'Total Program Fee',       'amount' => $currency . ' 1,30,000'],
            ];
        }

        $uid = 'sode_fees_' . substr(md5(uniqid(rand(), true)), 0, 8);

        ob_start();
        ?>
        <div class="sode-course-fees-wrapper <?php echo htmlspecialchars($custom_class); ?>" id="<?php echo $uid; ?>">
            <div class="sode-fees-scroll">
                <table class="sode-fees-table">
                    <thead>
                        <tr>
                            <th class="sode-fees-th-component">FEE COMPONENT</th>
                            <th class="sode-fees-th-amount">AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="sode-fees-td-component"><?php echo htmlspecialchars($row['component']); ?></td>
                                <td class="sode-fees-td-amount"><?php echo htmlspecialchars($row['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
            #<?php echo $uid; ?>.sode-course-fees-wrapper {
                width: 100%;
                margin: 0px;
                box-sizing: border-box;
            }

            #<?php echo $uid; ?> .sode-fees-scroll {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            }

            #<?php echo $uid; ?> .sode-fees-table {
                width: 100%;
                border-collapse: collapse;
                border-spacing: 0;
                text-align: left;
                margin: 0px;
            }

            #<?php echo $uid; ?> .sode-fees-table thead tr {
                background: #EBF3FC;
                border-bottom: 1px solid #CBD5E1;
            }

            #<?php echo $uid; ?> .sode-fees-table th {
                padding: 13px 20px;
                font-size: 13.5px;
                font-weight: 800;
                color: #0F172A;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border: none;
                line-height: 1.4;
            }

            #<?php echo $uid; ?> .sode-fees-table th.sode-fees-th-component {
                width: 68%;
            }

            #<?php echo $uid; ?> .sode-fees-table th.sode-fees-th-amount {
                width: 32%;
            }

            #<?php echo $uid; ?> .sode-fees-table tbody tr {
                border-bottom: 1px solid #E2E8F0;
                transition: background-color 0.15s ease;
                background: #ffffff;
            }

            #<?php echo $uid; ?> .sode-fees-table tbody tr:last-child {
                border-bottom: none;
            }

            #<?php echo $uid; ?> .sode-fees-table tbody tr:hover {
                background-color: #F8FAFC;
            }

            #<?php echo $uid; ?> .sode-fees-table td {
                padding: 13px 20px;
                border: none;
                line-height: 1.5;
            }

            #<?php echo $uid; ?> .sode-fees-table td.sode-fees-td-component {
                font-size: 13.5px;
                font-weight: 700;
                color: #0F172A;
                width: 68%;
            }

            #<?php echo $uid; ?> .sode-fees-table td.sode-fees-td-amount {
                font-size: 13.5px;
                font-weight: 500;
                color: #334155;
                width: 32%;
                white-space: nowrap;
            }

            @media (max-width: 640px) {
                #<?php echo $uid; ?> .sode-fees-table th {
                    padding: 11px 14px;
                    font-size: 12px;
                }

                #<?php echo $uid; ?> .sode-fees-table td {
                    padding: 10px 14px;
                    font-size: 12.5px;
                }

                #<?php echo $uid; ?> .sode-fees-table td.sode-fees-td-component {
                    font-size: 12.5px;
                    width: 60%;
                }

                #<?php echo $uid; ?> .sode-fees-table td.sode-fees-td-amount {
                    font-size: 12.5px;
                    width: 40%;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Register shortcodes directly if WordPress add_shortcode exists
if (function_exists('add_shortcode')) {
    add_shortcode('course_fees', 'sode_course_fees_render');
    add_shortcode('course_fee_table', 'sode_course_fees_render');
    add_shortcode('course_fee', 'sode_course_fees_render');
    add_shortcode('fee_structure', 'sode_course_fees_render');
    add_shortcode('university_course_fees', 'sode_course_fees_render');
    add_shortcode('uni_course_fees', 'sode_course_fees_render');
}
