<?php
/**
 * ====================================================================
 * Universal Course Syllabus Component
 * File: course-syllabus-universal.php
 * 
 * Renders semester-wise course syllabus comparison tables.
 * Admin manages semester boxes, subjects, and links per university course mapping.
 * 
 * Shortcodes:
 *  1. [course_syllabus course="mba" mode="online"]
 *  2. [syllabus course="mba" mode="online"]
 *  3. [university_course_syllabus course="mba" mode="online"]
 *  4. [uni_course_syllabus course="mba" mode="online"]
 * ====================================================================
 */

if (defined('SODE_COURSE_SYLLABUS_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_COURSE_SYLLABUS_UNIVERSAL_LOADED', true);

// Polyfills
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string)$title), '-'));
    }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($pairs, $atts, $shortcode = '') {
        $atts = (array)$atts;
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
 * Helper: Fetch course syllabus data from DB or Central API
 */
if (!function_exists('sode_get_course_syllabus_data')) {
    function sode_get_course_syllabus_data($uni_slug = '', $course_slug = 'mba', $mode = 'Online') {
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

        $semesters = [];
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

                        $m_stmt = $db->prepare("SELECT syllabus_json, mode FROM university_course_mappings WHERE university_id = ? AND course_id = ? AND LOWER(mode) = LOWER(?) LIMIT 1");
                        $m_stmt->execute([$found_uni['id'], $found_course['id'], $mode_clean]);
                        $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);

                        // Fallback to any mode if not found
                        if (!$mapping) {
                            $m_stmt = $db->prepare("SELECT syllabus_json, mode FROM university_course_mappings WHERE university_id = ? AND course_id = ? LIMIT 1");
                            $m_stmt->execute([$found_uni['id'], $found_course['id']]);
                            $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);
                        }

                        if (!empty($mapping['syllabus_json'])) {
                            $dec = json_decode($mapping['syllabus_json'], true);
                            if (is_array($dec)) {
                                $semesters = $dec;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("sode_get_course_syllabus_data local error: " . $e->getMessage());
            }
        }

        // 3. Central Admin API Remote Call if empty
        if (empty($semesters)) {
            $api_base = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $params = http_build_query([
                'uni'    => $uni_slug,
                'course' => $course_slug,
                'mode'   => $mode_clean
            ]);
            $endpoints = [
                $api_base . '/admin/api/get_course_syllabus.php?' . $params,
                $api_base . '/api/get_course_syllabus.php?' . $params,
            ];

            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            foreach ($endpoints as $ep) {
                $raw = @file_get_contents($ep, false, $ctx);
                if ($raw) {
                    $json = json_decode($raw, true);
                    if (!empty($json['success']) && !empty($json['semesters']) && is_array($json['semesters'])) {
                        $semesters = $json['semesters'];
                        if (!empty($json['university_name'])) $uni_name = $json['university_name'];
                        if (!empty($json['course_name'])) $course_name = $json['course_name'];
                        break;
                    }
                }
            }
        }

        $result = [
            'university' => $uni_name,
            'course'     => $course_name,
            'mode'       => $mode_clean,
            'semesters'  => $semesters,
        ];

        $cache[$cache_key] = $result;
        return $result;
    }
}

/**
 * Main Render Function for Course Syllabus Table
 */
if (!function_exists('sode_course_syllabus_render')) {
    function sode_course_syllabus_render($atts = []) {
        $atts = shortcode_atts([
            'uni'        => '',
            'university' => '',
            'course'     => 'mba',
            'mode'       => 'Online',
            'class'      => '',
        ], $atts, 'course_syllabus');

        $uni = !empty($atts['uni']) ? $atts['uni'] : $atts['university'];
        $course = !empty($atts['course']) ? $atts['course'] : 'mba';
        $mode = !empty($atts['mode']) ? $atts['mode'] : 'Online';

        $data = sode_get_course_syllabus_data($uni, $course, $mode);
        $semesters = $data['semesters'] ?? [];

        if (empty($semesters)) {
            return '<!-- SODE Course Syllabus: No syllabus data found for ' . htmlspecialchars($course) . ' (' . htmlspecialchars($mode) . ') -->';
        }

        // Calculate max rows across all semesters
        $max_rows = 0;
        foreach ($semesters as $sem) {
            $count = count($sem['subjects'] ?? []);
            if ($count > $max_rows) {
                $max_rows = $count;
            }
        }

        $uid = 'sode_syl_' . substr(md5(uniqid()), 0, 6);
        $col_count = count($semesters);
        $col_percent = $col_count > 0 ? (100 / $col_count) : 25;

        ob_start();
        ?>
        <div id="<?php echo $uid; ?>" class="sode-syllabus-wrapper <?php echo htmlspecialchars($atts['class']); ?>">
            <div class="sode-syllabus-scroll">
                <table class="sode-syllabus-table">
                    <thead>
                        <tr>
                            <?php foreach ($semesters as $sIdx => $sem): ?>
                                <th style="width:<?php echo $col_percent; ?>%;">
                                    <?php echo htmlspecialchars($sem['semester_title'] ?? ('SEMESTER ' . ($sIdx + 1))); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($r = 0; $r < $max_rows; $r++): ?>
                            <tr>
                                <?php foreach ($semesters as $sem): 
                                    $sub = $sem['subjects'][$r] ?? null;
                                    ?>
                                    <td>
                                        <?php if ($sub && !empty($sub['name'])): ?>
                                            <?php if (!empty($sub['link'])): ?>
                                                <a href="<?php echo htmlspecialchars($sub['link']); ?>" target="_blank" rel="noopener" class="sode-syllabus-link">
                                                    <?php echo htmlspecialchars($sub['name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="sode-syllabus-text"><?php echo htmlspecialchars($sub['name']); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="sode-syllabus-empty">&nbsp;</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
            #<?php echo $uid; ?>.sode-syllabus-wrapper {
                width: 100%;
                margin: 20px 0;
                box-sizing: border-box;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
            }

            #<?php echo $uid; ?> .sode-syllabus-scroll {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #E2E8F0;
                border-radius: 8px;
                background: #fff;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            }

            #<?php echo $uid; ?> .sode-syllabus-table {
                width: 100%;
                border-collapse: collapse;
                border-spacing: 0;
                text-align: left;
                min-width: <?php echo max(600, $col_count * 200); ?>px;
            }

            #<?php echo $uid; ?> .sode-syllabus-table thead tr {
                background: #EBF3FC;
                border-bottom: 1px solid #CBD5E1;
            }

            #<?php echo $uid; ?> .sode-syllabus-table th {
                padding: 14px 18px;
                font-size: 13.5px;
                font-weight: 800;
                color: #0F172A;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-right: 1px solid #CBD5E1;
                line-height: 1.4;
            }

            #<?php echo $uid; ?> .sode-syllabus-table th:last-child {
                border-right: none;
            }

            #<?php echo $uid; ?> .sode-syllabus-table tbody tr {
                border-bottom: 1px solid #E2E8F0;
                transition: background 0.15s ease;
            }

            #<?php echo $uid; ?> .sode-syllabus-table tbody tr:last-child {
                border-bottom: none;
            }

            #<?php echo $uid; ?> .sode-syllabus-table tbody tr:hover {
                background: #F8FAFC;
            }

            #<?php echo $uid; ?> .sode-syllabus-table td {
                padding: 12px 18px;
                font-size: 13px;
                color: #1E293B;
                border-right: 1px solid #E2E8F0;
                line-height: 1.5;
                vertical-align: middle;
            }

            #<?php echo $uid; ?> .sode-syllabus-table td:last-child {
                border-right: none;
            }

            #<?php echo $uid; ?> .sode-syllabus-text {
                display: inline-block;
                color: #1E293B;
                font-weight: 600;
            }

            #<?php echo $uid; ?> .sode-syllabus-link {
                display: inline-block;
                color: #0284C7;
                font-weight: 600;
                text-decoration: none;
                transition: color 0.15s ease, text-decoration 0.15s ease;
            }

            #<?php echo $uid; ?> .sode-syllabus-link:hover {
                color: #0369A1;
                text-decoration: underline;
            }

            #<?php echo $uid; ?> .sode-syllabus-empty {
                display: inline-block;
                min-height: 18px;
            }

            @media (max-width: 768px) {
                #<?php echo $uid; ?> .sode-syllabus-table th {
                    padding: 11px 14px;
                    font-size: 12px;
                }
                #<?php echo $uid; ?> .sode-syllabus-table td {
                    padding: 10px 14px;
                    font-size: 12px;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Register shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('course_syllabus', 'sode_course_syllabus_render');
    add_shortcode('syllabus', 'sode_course_syllabus_render');
    add_shortcode('university_course_syllabus', 'sode_course_syllabus_render');
    add_shortcode('uni_course_syllabus', 'sode_course_syllabus_render');
}
