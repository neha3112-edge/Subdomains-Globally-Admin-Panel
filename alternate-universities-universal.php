<?php
/**
 * ====================================================================
 * ALTERNATIVE UNIVERSITIES SHOWCASE - UNIVERSAL COMPONENT
 * File: alternate-universities-universal.php
 * 
 * Renders a responsive list of alternative universities with:
 *  - University Info, Location & Approvals
 *  - "View Sample Degree" (Eye icon popup with certificate preview)
 *  - "Get Help" (Green button popup with customized lead inquiry form)
 *  - "View course List ▾" (Accordion button that slides down the courses table)
 * 
 * Shortcodes:
 *  [alternative_universities]
 *  [alternate_universities]
 *  [alternatives_universities]
 *  [alternate_university_list]
 *  [alternative_university_list]
 * ====================================================================
 */

if (defined('SODE_ALTERNATE_UNIVERSITIES_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_ALTERNATE_UNIVERSITIES_UNIVERSAL_LOADED', true);

// --------------------------------------------------------------------
// Fallback Polyfills for Standalone / Remote Execution
// --------------------------------------------------------------------
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
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $title), '-'));
    }
}

/**
 * Format fees string cleanly with ₹ currency symbol
 */
if (!function_exists('sode_alt_format_fee')) {
    function sode_alt_format_fee($raw_fee)
    {
        $raw = trim((string) $raw_fee);
        if ($raw === '' || $raw === '-' || strtolower($raw) === 'n/a') {
            return 'Contact Counselor';
        }
        // Remove existing currency marks
        $cleaned = preg_replace('/^(₹|rs\.?|inr)\s*/i', '', $raw);
        $cleaned = trim($cleaned);
        if ($cleaned === '') {
            return 'Contact Counselor';
        }

        // Handle ranges like "16,750 – 19,750"
        if (strpos($cleaned, '–') !== false || strpos($cleaned, '-') !== false) {
            $parts = preg_split('/\s*[–-]\s*/u', $cleaned);
            if (count($parts) === 2) {
                $p1 = trim(preg_replace('/^(₹|rs\.?|inr)\s*/i', '', $parts[0]));
                $p2 = trim(preg_replace('/^(₹|rs\.?|inr)\s*/i', '', $parts[1]));
                return '₹ ' . $p1 . ' – ₹ ' . $p2;
            }
        }

        // Check if trailing slash exists
        $has_slash = (substr($cleaned, -1) === '/');
        $num_part = rtrim($cleaned, '/');

        return '₹ ' . trim($num_part) . ($has_slash ? '/' : '');
    }
}

/**
 * Fetch Alternate Universities from Local Database or Central API
 */
if (!function_exists('sode_get_alternate_universities_list')) {
    function sode_get_alternate_universities_list()
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        // 1. Try Local Database Connection
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
                    $stmt = $db->query("
                        SELECT id, full_name, short_name, slug, mode, location, advantage_text,
                               logo_url, campus_mobile_img, alt_desktop_img, alt_mobile_img, sample_degree_img,
                               alt_description, rating, is_active
                        FROM universities
                        WHERE show_in_alternate = 1 AND is_active = 1
                        ORDER BY id ASC
                    ");
                    $unis = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($unis)) {
                        $acc_stmt = $db->prepare("
                            SELECT a.id, a.title, a.image_url
                            FROM accreditations a
                            JOIN university_accreditations ua ON a.id = ua.accreditation_id
                            WHERE ua.university_id = ?
                            ORDER BY a.id ASC
                        ");

                        $course_stmt = $db->prepare("
                            SELECT c.id AS course_id, c.short_name, c.full_name, c.level,
                                   ucm.eligibility_text, ucm.per_semester_fee
                            FROM university_course_mappings ucm
                            JOIN courses c ON ucm.course_id = c.id
                            WHERE ucm.university_id = ?
                            ORDER BY ucm.id ASC
                        ");

                        $result = [];
                        foreach ($unis as $u) {
                            $acc_stmt->execute([$u['id']]);
                            $accs = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);
                            $acc_titles = array_column($accs, 'title');

                            $course_stmt->execute([$u['id']]);
                            $courses = $course_stmt->fetchAll(PDO::FETCH_ASSOC);

                            // Format course list placeholder in description
                            $c_names = array_column($courses, 'short_name');
                            $c_text_list = '';
                            if (!empty($c_names)) {
                                if (count($c_names) === 1) {
                                    $c_text_list = $c_names[0];
                                } else {
                                    $last_c = array_pop($c_names);
                                    $c_text_list = implode(', ', $c_names) . ' and ' . $last_c;
                                }
                            }

                            $desc = $u['alt_description'] ?? '';
                            if (!empty($desc)) {
                                $desc = str_replace(
                                    ['[university_courses_list and="true"]', '[university_courses_list]', '[courses_list]'],
                                    $c_text_list,
                                    $desc
                                );
                            }

                            $desktop_img = !empty($u['alt_desktop_img']) ? (function_exists('get_asset_url') ? get_asset_url($u['alt_desktop_img']) : $u['alt_desktop_img']) : '';
                            $mobile_img  = !empty($u['alt_mobile_img']) ? (function_exists('get_asset_url') ? get_asset_url($u['alt_mobile_img']) : $u['alt_mobile_img']) : '';
                            $sample_img  = !empty($u['sample_degree_img']) ? (function_exists('get_asset_url') ? get_asset_url($u['sample_degree_img']) : $u['sample_degree_img']) : '';
                            $logo_img    = !empty($u['logo_url']) ? (function_exists('get_asset_url') ? get_asset_url($u['logo_url']) : $u['logo_url']) : '';
                            $campus_img  = !empty($u['campus_mobile_img']) ? (function_exists('get_asset_url') ? get_asset_url($u['campus_mobile_img']) : $u['campus_mobile_img']) : '';

                            $result[] = [
                                'id'               => (int) $u['id'],
                                'full_name'        => $u['full_name'],
                                'short_name'       => $u['short_name'],
                                'slug'             => $u['slug'],
                                'mode'             => $u['mode'],
                                'location'         => $u['location'] ?? '',
                                'advantage_text'   => $u['advantage_text'] ?? '',
                                'alt_desktop_img'  => $desktop_img,
                                'alt_mobile_img'   => $mobile_img,
                                'sample_degree_img'=> $sample_img,
                                'logo_url'         => $logo_img,
                                'campus_img'       => $campus_img,
                                'alt_description'  => $desc,
                                'approvals'        => $acc_titles,
                                'approvals_string' => implode(' | ', $acc_titles),
                                'courses'          => $courses,
                            ];
                        }

                        $cached = $result;
                        return $result;
                    }
                }
            } catch (Exception $e) {
                // proceed to API fallback
            }
        }

        // 2. Fallback: Central API
        $api_url = (defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com') . '/admin/api/get_alternate_universities.php';

        $data = null;
        if (function_exists('wp_remote_get')) {
            $resp = wp_remote_get($api_url, ['timeout' => 8, 'headers' => ['Cache-Control' => 'no-cache']]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if (!empty($body['data'])) {
                    $data = $body['data'];
                }
            }
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $raw = @file_get_contents($api_url, false, $ctx);
            if ($raw) {
                $body = json_decode($raw, true);
                if (!empty($body['data'])) {
                    $data = $body['data'];
                }
            }
        }

        $cached = is_array($data) ? $data : [];
        return $cached;
    }
}

/**
 * Main Render Function for Alternative Universities
 */
if (!function_exists('sode_alternate_universities_render')) {
    function sode_alternate_universities_render($atts = [])
    {
        $atts = shortcode_atts([
            'limit'      => -1,
            'exclude'    => '',
            'course'     => '',
            'state'      => '',
            'heading'    => '',
            'subheading' => '',
            'class'      => '',
        ], $atts, 'alternative_universities');

        $universities = sode_get_alternate_universities_list();

        if (empty($universities)) {
            return '';
        }

        // Filter: Exclude IDs or slugs (useful when displayed on a specific university's page)
        if (!empty($atts['exclude'])) {
            $exclude_parts = array_map('trim', explode(',', strtolower($atts['exclude'])));
            $universities = array_filter($universities, function ($u) use ($exclude_parts) {
                $id = (string) $u['id'];
                $slug = strtolower($u['slug'] ?? '');
                $short = strtolower($u['short_name'] ?? '');
                return !in_array($id, $exclude_parts) && !in_array($slug, $exclude_parts) && !in_array($short, $exclude_parts);
            });
        }

        // Filter: Specific Course
        if (!empty($atts['course'])) {
            $course_filter = strtolower(trim($atts['course']));
            $universities = array_filter($universities, function ($u) use ($course_filter) {
                if (empty($u['courses'])) return false;
                foreach ($u['courses'] as $c) {
                    if (strtolower($c['short_name']) === $course_filter || stripos($c['full_name'], $course_filter) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Filter: State / Location
        if (!empty($atts['state'])) {
            $state_filter = strtolower(trim($atts['state']));
            $universities = array_filter($universities, function ($u) use ($state_filter) {
                return stripos(strtolower($u['location']), $state_filter) !== false;
            });
        }

        // Apply Limit
        $limit = (int) $atts['limit'];
        if ($limit > 0 && count($universities) > $limit) {
            $universities = array_slice($universities, 0, $limit);
        }

        if (empty($universities)) {
            return '';
        }

        // Preload states for the inquiry popup
        $indian_states = [
            "Andhra Pradesh", "Arunachal Pradesh", "Assam", "Bihar", "Chhattisgarh",
            "Delhi", "Goa", "Gujarat", "Haryana", "Himachal Pradesh", "Jharkhand",
            "Karnataka", "Kerala", "Madhya Pradesh", "Maharashtra", "Manipur",
            "Meghalaya", "Mizoram", "Nagaland", "Odisha", "Punjab", "Rajasthan",
            "Sikkim", "Tamil Nadu", "Telangana", "Tripura", "Uttar Pradesh",
            "Uttarakhand", "West Bengal", "Chandigarh", "Jammu and Kashmir",
            "Ladakh", "Puducherry"
        ];

        ob_start();
        ?>
        <!-- SODE ALTERNATIVE UNIVERSITIES STYLES -->
        <style>
            .sode-alt-container {
                width: 100%;
                max-width: 1180px;
                margin: 0 auto;
                box-sizing: border-box;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                color: #1f2937;
            }
            .sode-alt-container *, .sode-alt-container *::before, .sode-alt-container *::after {
                box-sizing: border-box;
            }
            .sode-alt-header-area {
                margin-bottom: 28px;
                text-align: center;
            }
            .sode-alt-main-heading {
                font-size: 28px;
                font-weight: 800;
                color: #0c2340;
                margin: 0 0 8px;
                line-height: 1.3;
            }
            .sode-alt-sub-heading {
                font-size: 15px;
                color: #4b5563;
                margin: 0;
            }
            .sode-alt-list {
                display: flex;
                flex-direction: column;
                gap: 26px;
            }
            .sode-alt-card {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
                padding: 24px;
                position: relative;
                transition: box-shadow 0.25s ease, border-color 0.25s ease;
            }
            .sode-alt-card:hover {
                box-shadow: 0 8px 24px rgba(12, 35, 64, 0.08);
                border-color: #cbd5e1;
            }
            /* Sample Degree Link (Top Right) */
            .sode-alt-top-actions {
                position: absolute;
                top: 20px;
                right: 24px;
                z-index: 2;
            }
            .sode-alt-sample-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                color: #0c2340;
                font-size: 13.5px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                background: none;
                border: none;
                padding: 4px 6px;
                border-radius: 4px;
                transition: color 0.2s ease, background 0.2s ease;
            }
            .sode-alt-sample-btn:hover {
                color: #0284c7;
                background: #f0f9ff;
                text-decoration: underline;
            }
            .sode-alt-sample-btn svg {
                width: 17px;
                height: 17px;
                flex-shrink: 0;
                stroke: currentColor;
            }

            /* Main Card Body */
            .sode-alt-card-main {
                display: flex;
                align-items: flex-start;
                gap: 24px;
            }
            .sode-alt-img-box {
                width: 240px;
                min-width: 240px;
                height: 200px;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                overflow: hidden;
                background-color: #f8fafc;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                flex-shrink: 0;
            }
            .sode-alt-img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            .sode-alt-placeholder {
                width: 100%;
                height: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 16px;
                background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
                text-align: center;
            }
            .sode-alt-placeholder-logo {
                max-width: 120px;
                max-height: 60px;
                object-fit: contain;
                margin-bottom: 8px;
            }
            .sode-alt-placeholder-badge {
                width: 52px;
                height: 52px;
                border-radius: 50%;
                background: #0c2340;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                font-weight: 800;
                letter-spacing: 0.5px;
                margin-bottom: 8px;
                box-shadow: 0 4px 10px rgba(12, 35, 64, 0.2);
            }
            .sode-alt-placeholder-name {
                font-size: 13px;
                font-weight: 700;
                color: #0c2340;
                line-height: 1.3;
            }

            .sode-alt-info-box {
                flex: 1;
                min-width: 0;
                padding-right: 180px; /* Space for top-right sample degree link */
            }
            .sode-alt-uni-title {
                font-size: 22px;
                font-weight: 700;
                color: #0c2340;
                margin: 0 0 10px 0;
                line-height: 1.3;
            }
            .sode-alt-uni-desc {
                font-size: 14px;
                color: #374151;
                line-height: 1.6;
                margin: 0 0 14px 0;
            }
            .sode-alt-meta-row {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 18px;
                font-size: 14px;
                margin-bottom: 18px;
                line-height: 1.5;
            }
            .sode-alt-meta-item {
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .sode-alt-meta-label {
                color: #0c2340;
                font-weight: 700;
            }
            .sode-alt-meta-val {
                color: #e11d48;
                font-weight: 600;
            }

            /* Action Buttons */
            .sode-alt-btn-group {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-top: 6px;
            }
            .sode-alt-btn-help {
                background: #2e7d32;
                color: #ffffff !important;
                font-size: 14px;
                font-weight: 700;
                padding: 10px 24px;
                border-radius: 6px;
                border: none;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                transition: background 0.2s ease, transform 0.1s ease;
                box-shadow: 0 2px 6px rgba(46, 125, 50, 0.25);
            }
            .sode-alt-btn-help:hover {
                background: #256628;
                color: #ffffff !important;
            }
            .sode-alt-btn-courses {
                background: #f59e0b;
                color: #111827 !important;
                font-size: 14px;
                font-weight: 700;
                padding: 10px 20px;
                border-radius: 6px;
                border: none;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                justify-content: center;
                transition: background 0.2s ease, transform 0.1s ease;
                box-shadow: 0 2px 6px rgba(245, 158, 11, 0.25);
            }
            .sode-alt-btn-courses:hover {
                background: #d97706;
                color: #111827 !important;
            }
            .sode-alt-btn-courses .sode-chevron {
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                display: inline-block;
                width: 14px;
                height: 14px;
            }
            .sode-alt-btn-courses.is-active .sode-chevron {
                transform: rotate(180deg);
            }

            /* Courses Table Accordion */
            .sode-alt-courses-wrap {
                max-height: 0;
                opacity: 0;
                overflow: hidden;
                transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
                margin-top: 0;
            }
            .sode-alt-courses-wrap.is-open {
                max-height: 2500px;
                opacity: 1;
                margin-top: 22px;
            }
            .sode-alt-table-scroll {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
            }
            .sode-alt-table {
                width: 100%;
                border-collapse: collapse;
                background: #ffffff;
                font-size: 13.5px;
                text-align: left;
            }
            .sode-alt-table thead th {
                background: #e9f5fe;
                color: #0c2340;
                font-size: 12.5px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 12px 16px;
                border-bottom: 1px solid #cbd5e1;
                white-space: nowrap;
            }
            .sode-alt-table th:nth-child(1) { width: 22%; }
            .sode-alt-table th:nth-child(2) { width: 56%; }
            .sode-alt-table th:nth-child(3) { width: 22%; }

            .sode-alt-table tbody td {
                padding: 12px 16px;
                border-top: 1px solid #e5e7eb;
                vertical-align: middle;
                color: #374151;
            }
            .sode-alt-table tbody tr:first-child td {
                border-top: none;
            }
            .sode-alt-course-name {
                font-weight: 700;
                color: #0c2340;
            }
            .sode-alt-course-elig {
                color: #4b5563;
                line-height: 1.5;
            }
            .sode-alt-course-fee {
                font-weight: 600;
                color: #111827;
                white-space: nowrap;
            }

            /* Responsive Breakpoints */
            @media (max-width: 900px) {
                .sode-alt-info-box {
                    padding-right: 0;
                }
                .sode-alt-top-actions {
                    position: static;
                    margin-bottom: 12px;
                    display: flex;
                    justify-content: flex-end;
                }
            }

            @media (max-width: 768px) {
                .sode-alt-card {
                    padding: 18px;
                }
                .sode-alt-card-main {
                    flex-direction: column;
                    gap: 16px;
                }
                .sode-alt-img-box {
                    width: 100%;
                    min-width: 100%;
                    height: 190px;
                }
                .sode-alt-top-actions {
                    position: static;
                    margin-bottom: 8px;
                    display: flex;
                    justify-content: flex-end;
                }
                .sode-alt-uni-title {
                    font-size: 19px;
                }
                .sode-alt-meta-row {
                    flex-direction: column;
                    align-items: flex-start;
                    gap: 6px;
                }
                .sode-alt-btn-group {
                    width: 100%;
                }
                .sode-alt-btn-courses,
                .sode-alt-btn-help {
                    flex: 1;
                    padding: 10px 14px;
                    font-size: 13.5px;
                    text-align: center;
                }
                .sode-alt-table th, .sode-alt-table td {
                    padding: 10px 12px;
                    font-size: 12.5px;
                }
            }

            /* Popups / Modals */
            .sode-alt-modal-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(12, 35, 64, 0.72);
                backdrop-filter: blur(4px);
                z-index: 999999;
                align-items: center;
                justify-content: center;
                padding: 16px;
            }
            .sode-alt-modal-overlay.is-visible {
                display: flex;
            }
            .sode-alt-modal-box {
                background: #ffffff;
                border-radius: 12px;
                width: 100%;
                max-width: 520px;
                max-height: 92vh;
                overflow-y: auto;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
                position: relative;
                animation: sodeAltModalPop 0.25s ease-out;
            }
            .sode-alt-modal-box.is-wide {
                max-width: 780px;
            }
            @keyframes sodeAltModalPop {
                from { transform: scale(0.95); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
            .sode-alt-modal-close {
                position: absolute;
                top: 14px;
                right: 18px;
                font-size: 28px;
                font-weight: 300;
                color: #64748b;
                background: none;
                border: none;
                cursor: pointer;
                line-height: 1;
                z-index: 10;
                transition: color 0.15s ease;
            }
            .sode-alt-modal-close:hover {
                color: #e11d48;
            }
            .sode-alt-modal-head {
                padding: 24px 24px 16px;
                border-bottom: 1px solid #f1f5f9;
            }
            .sode-alt-modal-title {
                font-size: 20px;
                font-weight: 800;
                color: #0c2340;
                margin: 0 0 4px;
                line-height: 1.3;
                padding-right: 28px;
            }
            .sode-alt-modal-subtitle {
                font-size: 13.5px;
                color: #64748b;
                margin: 0;
            }
            .sode-alt-modal-body {
                padding: 20px 24px 24px;
            }

            /* Sample Degree Modal Content */
            .sode-degree-preview-container {
                text-align: center;
                background: #f8fafc;
                border: 1px dashed #cbd5e1;
                border-radius: 8px;
                padding: 20px;
                min-height: 240px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }
            .sode-degree-img {
                max-width: 100%;
                max-height: 65vh;
                object-fit: contain;
                border-radius: 6px;
                box-shadow: 0 4px 14px rgba(0,0,0,0.1);
            }
            .sode-degree-mockup {
                border: 2px solid #e2e8f0;
                background: #ffffff;
                border-radius: 8px;
                padding: 30px 24px;
                width: 100%;
                max-width: 480px;
                box-shadow: 0 8px 24px rgba(0,0,0,0.06);
            }
            .sode-degree-gold-seal {
                width: 56px;
                height: 56px;
                margin: 0 auto 12px;
                border-radius: 50%;
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 26px;
                box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35);
            }
            .sode-degree-mockup-uni {
                font-size: 18px;
                font-weight: 800;
                color: #0c2340;
                margin-bottom: 6px;
            }
            .sode-degree-mockup-text {
                font-size: 13px;
                color: #64748b;
                line-height: 1.5;
                margin-bottom: 16px;
            }

            /* Get Help Form Inputs */
            .sode-alt-form-group {
                margin-bottom: 14px;
            }
            .sode-alt-label {
                display: block;
                font-size: 12.5px;
                font-weight: 700;
                color: #334155;
                margin-bottom: 5px;
            }
            .sode-alt-input, .sode-alt-select {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                font-size: 14px;
                color: #1e293b;
                background: #ffffff;
                outline: none;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }
            .sode-alt-input:focus, .sode-alt-select:focus {
                border-color: #0284c7;
                box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
            }
            .sode-alt-phone-row {
                display: flex;
                gap: 8px;
            }
            .sode-alt-code-select {
                width: 110px;
                flex-shrink: 0;
            }
            .sode-alt-form-consent {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                font-size: 11.5px;
                color: #64748b;
                margin: 14px 0 18px;
                line-height: 1.4;
                cursor: pointer;
            }
            .sode-alt-form-consent input {
                margin-top: 2px;
            }
            .sode-alt-submit-btn {
                width: 100%;
                background: #2e7d32;
                color: #ffffff;
                border: none;
                padding: 12px;
                font-size: 15px;
                font-weight: 700;
                border-radius: 6px;
                cursor: pointer;
                transition: background 0.2s ease;
            }
            .sode-alt-submit-btn:hover {
                background: #256628;
            }
            .sode-alt-submit-btn:disabled {
                background: #94a3b8;
                cursor: not-allowed;
            }
            .sode-alt-form-success {
                display: none;
                text-align: center;
                padding: 24px 16px;
            }
            .sode-alt-form-success .sode-success-icon {
                width: 52px;
                height: 52px;
                border-radius: 50%;
                background: #dcfce7;
                color: #16a34a;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 28px;
                margin: 0 auto 12px;
            }
        </style>

        <div class="sode-alt-container <?php echo esc_attr($atts['class']); ?>">
            <?php if (!empty($atts['heading'])): ?>
                <div class="sode-alt-header-area">
                    <h2 class="sode-alt-main-heading"><?php echo esc_html($atts['heading']); ?></h2>
                    <?php if (!empty($atts['subheading'])): ?>
                        <p class="sode-alt-sub-heading"><?php echo esc_html($atts['subheading']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="sode-alt-list">
                <?php foreach ($universities as $uni): 
                    $uni_id = $uni['id'];
                    $uni_name = $uni['full_name'];
                    $uni_slug = $uni['slug'];
                    $sample_img = $uni['sample_degree_img'];
                    $courses = $uni['courses'] ?? [];
                    
                    // Desktop / Mobile image determination
                    $img_src = !empty($uni['alt_desktop_img']) ? $uni['alt_desktop_img'] : (!empty($uni['campus_img']) ? $uni['campus_img'] : '');
                    $logo_src = !empty($uni['logo_url']) ? $uni['logo_url'] : '';
                    
                    // Format course names for JSON data attribute
                    $courses_json = htmlspecialchars(json_encode(array_map(function($c) {
                        return [
                            'name' => $c['short_name'],
                            'full' => $c['full_name']
                        ];
                    }, $courses)), ENT_QUOTES, 'UTF-8');
                ?>
                    <div class="sode-alt-card" id="uni-alt-card-<?php echo esc_attr($uni_id); ?>">
                        <!-- Top-Right View Sample Degree Link -->
                        <div class="sode-alt-top-actions">
                            <button type="button" class="sode-alt-sample-btn sode-open-sample-modal"
                                    data-uni-name="<?php echo esc_attr($uni_name); ?>"
                                    data-sample-img="<?php echo esc_url($sample_img); ?>"
                                    data-uni-slug="<?php echo esc_attr($uni_slug); ?>"
                                    data-courses='<?php echo $courses_json; ?>'>
                                <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                                <span>View Sample Degree</span>
                            </button>
                        </div>

                        <div class="sode-alt-card-main">
                            <!-- Left: Image Box -->
                            <div class="sode-alt-img-box">
                                <?php if (!empty($img_src)): ?>
                                    <img src="<?php echo esc_url($img_src); ?>" 
                                         alt="<?php echo esc_attr($uni_name); ?>" 
                                         class="sode-alt-img"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="sode-alt-placeholder">
                                        <?php if (!empty($logo_src)): ?>
                                            <img src="<?php echo esc_url($logo_src); ?>" alt="<?php echo esc_attr($uni_name); ?>" class="sode-alt-placeholder-logo">
                                        <?php else: ?>
                                            <div class="sode-alt-placeholder-badge">
                                                <?php echo esc_html(substr($uni['short_name'] ?: $uni_name, 0, 3)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="sode-alt-placeholder-name"><?php echo esc_html($uni_name); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Right: Info Box -->
                            <div class="sode-alt-info-box">
                                <h3 class="sode-alt-uni-title"><?php echo esc_html($uni_name); ?></h3>
                                
                                <?php if (!empty($uni['alt_description'])): ?>
                                    <div class="sode-alt-uni-desc"><?php echo esc_html($uni['alt_description']); ?></div>
                                <?php endif; ?>

                                <div class="sode-alt-meta-row">
                                    <?php if (!empty($uni['location'])): ?>
                                        <div class="sode-alt-meta-item">
                                            <span class="sode-alt-meta-label">Location :</span>
                                            <span class="sode-alt-meta-val"><?php echo esc_html($uni['location']); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($uni['approvals_string'])): ?>
                                        <div class="sode-alt-meta-item">
                                            <span class="sode-alt-meta-label">Approvals :</span>
                                            <span class="sode-alt-meta-val"><?php echo esc_html($uni['approvals_string']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Buttons -->
                                <div class="sode-alt-btn-group">
                                    <button type="button" class="sode-alt-btn-help sode-open-help-modal"
                                            data-uni-name="<?php echo esc_attr($uni_name); ?>"
                                            data-uni-slug="<?php echo esc_attr($uni_slug); ?>"
                                            data-courses='<?php echo $courses_json; ?>'>
                                        Get Help
                                    </button>

                                    <button type="button" class="sode-alt-btn-courses sode-toggle-courses"
                                            data-target="courses-wrap-<?php echo esc_attr($uni_id); ?>">
                                        <span>View course List</span>
                                        <svg class="sode-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="6 9 12 15 18 9"></polyline>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Courses Table Accordion -->
                        <div class="sode-alt-courses-wrap" id="courses-wrap-<?php echo esc_attr($uni_id); ?>">
                            <div class="sode-alt-table-scroll">
                                <table class="sode-alt-table">
                                    <thead>
                                        <tr>
                                            <th>COURSE</th>
                                            <th>ELIGIBILITY CRITERIA</th>
                                            <th>FEES PER SEMESTER</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($courses)): ?>
                                            <?php foreach ($courses as $c): ?>
                                                <tr>
                                                    <td class="sode-alt-course-name"><?php echo esc_html($c['short_name']); ?></td>
                                                    <td class="sode-alt-course-elig"><?php echo esc_html($c['eligibility_text'] ?: '10+2 / Graduation as per university norms'); ?></td>
                                                    <td class="sode-alt-course-fee"><?php echo esc_html(sode_alt_format_fee($c['per_semester_fee'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="3" style="text-align:center; padding: 18px; color: #64748b;">
                                                    Courses and fee structure details available upon counseling request.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- POPUP 1: SAMPLE DEGREE MODAL                             -->
        <!-- ========================================================= -->
        <div id="sodeAltSampleModal" class="sode-alt-modal-overlay">
            <div class="sode-alt-modal-box is-wide">
                <button type="button" class="sode-alt-modal-close sode-close-modal" aria-label="Close">&times;</button>
                <div class="sode-alt-modal-head">
                    <h3 class="sode-alt-modal-title" id="sodeSampleModalTitle">Sample Degree Certificate</h3>
                    <p class="sode-alt-modal-subtitle">Official verified sample degree preview accredited by UGC-DEB</p>
                </div>
                <div class="sode-alt-modal-body">
                    <div id="sodeSampleModalContent" class="sode-degree-preview-container">
                        <!-- Injected via JavaScript -->
                    </div>
                    <div style="margin-top: 18px; text-align: center; display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                        <button type="button" id="sodeSampleHelpBtn" class="sode-alt-btn-help">
                            Get Admission & Degree Assistance
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- POPUP 2: GET HELP INQUIRY FORM MODAL                     -->
        <!-- ========================================================= -->
        <div id="sodeAltHelpModal" class="sode-alt-modal-overlay">
            <div class="sode-alt-modal-box">
                <button type="button" class="sode-alt-modal-close sode-close-modal" aria-label="Close">&times;</button>
                <div class="sode-alt-modal-head">
                    <h3 class="sode-alt-modal-title" id="sodeHelpModalTitle">Get Free Admission Assistance</h3>
                    <p class="sode-alt-modal-subtitle" id="sodeHelpModalSubtitle">Speak with an expert counselor for personalized guidance</p>
                </div>
                <div class="sode-alt-modal-body">
                    <form id="sodeAltLeadForm" class="customLeadForm" data-form-name="Alternate University Help Popup" method="POST">
                        <input type="hidden" name="uni_slug" id="sodeHelpUniSlug" value="dsu">
                        <input type="hidden" name="action" value="send_lead">
                        <input type="hidden" name="form_name" value="Alternate University Help Popup">
                        <input type="hidden" name="source" value="ALT_UNI_POPUP">

                        <div class="sode-alt-form-group">
                            <label class="sode-alt-label">Full Name *</label>
                            <input type="text" name="name" class="sode-alt-input" placeholder="Enter Your Full Name" required>
                        </div>

                        <div class="sode-alt-form-group">
                            <label class="sode-alt-label">Mobile Number *</label>
                            <div class="sode-alt-phone-row">
                                <select name="country_code" class="sode-alt-select sode-alt-code-select country_code" required>
                                    <option value="91" selected>🇮🇳 +91</option>
                                    <option value="971">🇦🇪 +971</option>
                                    <option value="1">🇺🇸 +1</option>
                                    <option value="44">🇬🇧 +44</option>
                                    <option value="1">🇨🇦 +1</option>
                                    <option value="61">🇦🇺 +61</option>
                                    <option value="966">🇸🇦 +966</option>
                                    <option value="974">🇶🇦 +974</option>
                                    <option value="968">🇴🇲 +968</option>
                                    <option value="65">🇸🇬 +65</option>
                                </select>
                                <input type="tel" name="phone" class="sode-alt-input phone" placeholder="10-digit mobile number" maxlength="10" required>
                            </div>
                        </div>

                        <div class="sode-alt-form-group">
                            <label class="sode-alt-label">Email Address *</label>
                            <input type="email" name="email" class="sode-alt-input" placeholder="Enter Your Email Address" required>
                        </div>

                        <div class="sode-alt-form-group">
                            <label class="sode-alt-label">Select Course *</label>
                            <select name="course" id="sodeHelpCourseSelect" class="sode-alt-select" required>
                                <option value="">Select Interested Course</option>
                            </select>
                        </div>

                        <div class="sode-alt-form-group">
                            <label class="sode-alt-label">State *</label>
                            <select name="state" class="sode-alt-select" required>
                                <option value="">Select State</option>
                                <?php foreach ($indian_states as $st): ?>
                                    <option value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Honeypot -->
                        <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">

                        <label class="sode-alt-form-consent">
                            <input type="checkbox" name="consent" checked required>
                            <span>I authorize Distance Education School & university representatives to contact me via Call/SMS/WhatsApp for admission guidance.</span>
                        </label>

                        <button type="submit" class="sode-alt-submit-btn submitBtn" id="sodeAltSubmitBtn">
                            Get Free Counseling
                        </button>
                    </form>

                    <div id="sodeAltSuccessBox" class="sode-alt-form-success">
                        <div class="sode-success-icon">✓</div>
                        <h4 style="font-size: 18px; color: #0c2340; margin: 0 0 8px;">Thank You!</h4>
                        <p style="color: #64748b; font-size: 14px; margin: 0;">Your counseling request has been received. Our academic advisor will contact you shortly.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- SODE ALTERNATIVE UNIVERSITIES CLIENT SCRIPT -->
        <script>
        (function() {
            function initAlternateUniversities() {
                // 1. Accordion Toggle: View Course List ▾
                const toggleBtns = document.querySelectorAll('.sode-toggle-courses');
                toggleBtns.forEach(btn => {
                    if (btn.dataset.initialized) return;
                    btn.dataset.initialized = 'true';

                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const targetId = this.dataset.target;
                        const wrap = document.getElementById(targetId);
                        if (!wrap) return;

                        const isOpen = wrap.classList.contains('is-open');
                        if (isOpen) {
                            wrap.classList.remove('is-open');
                            this.classList.remove('is-active');
                            const span = this.querySelector('span');
                            if (span) span.textContent = 'View course List';
                        } else {
                            wrap.classList.add('is-open');
                            this.classList.add('is-active');
                            const span = this.querySelector('span');
                            if (span) span.textContent = 'Hide course List';
                        }
                    });
                });

                // Modals Elements
                const sampleModal = document.getElementById('sodeAltSampleModal');
                const helpModal = document.getElementById('sodeAltHelpModal');
                const sampleModalTitle = document.getElementById('sodeSampleModalTitle');
                const sampleModalContent = document.getElementById('sodeSampleModalContent');
                const sampleHelpBtn = document.getElementById('sodeSampleHelpBtn');
                
                const helpModalTitle = document.getElementById('sodeHelpModalTitle');
                const helpModalSubtitle = document.getElementById('sodeHelpModalSubtitle');
                const helpUniSlug = document.getElementById('sodeHelpUniSlug');
                const helpCourseSelect = document.getElementById('sodeHelpCourseSelect');
                const helpForm = document.getElementById('sodeAltLeadForm');
                const successBox = document.getElementById('sodeAltSuccessBox');

                let currentUniData = null;

                function closeModal() {
                    if (sampleModal) sampleModal.classList.remove('is-visible');
                    if (helpModal) helpModal.classList.remove('is-visible');
                    document.body.style.overflow = '';
                }

                document.querySelectorAll('.sode-close-modal').forEach(b => {
                    b.addEventListener('click', closeModal);
                });

                [sampleModal, helpModal].forEach(m => {
                    if (!m) return;
                    m.addEventListener('click', function(e) {
                        if (e.target === this) closeModal();
                    });
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') closeModal();
                });

                // Helper: Populate Course Dropdown
                function populateCourseDropdown(coursesRaw) {
                    if (!helpCourseSelect) return;
                    helpCourseSelect.innerHTML = '<option value="">Select Interested Course</option>';
                    let courses = [];
                    try {
                        courses = typeof coursesRaw === 'string' ? JSON.parse(coursesRaw) : coursesRaw;
                    } catch(err) {
                        courses = [];
                    }

                    if (Array.isArray(courses) && courses.length > 0) {
                        courses.forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c.name || c.short_name || c.full || c;
                            opt.textContent = (c.name ? c.name : (c.full ? c.full : c));
                            helpCourseSelect.appendChild(opt);
                        });
                    } else {
                        // Standard fallback
                        ['MBA', 'MCA', 'BBA', 'BCA', 'B.Com', 'M.Com', 'MA', 'BA'].forEach(c => {
                            const opt = document.createElement('option');
                            opt.value = c;
                            opt.textContent = c;
                            helpCourseSelect.appendChild(opt);
                        });
                    }
                }

                // Helper: Open Get Help Modal
                function openHelpModal(uniName, uniSlug, coursesRaw) {
                    currentUniData = { name: uniName, slug: uniSlug, courses: coursesRaw };
                    if (helpModalTitle) helpModalTitle.textContent = 'Get Free Admission Assistance - ' + uniName;
                    if (helpModalSubtitle) helpModalSubtitle.textContent = 'Speak with an expert counselor for ' + uniName + ' courses, fees & eligibility.';
                    if (helpUniSlug) helpUniSlug.value = uniSlug || 'dsu';

                    populateCourseDropdown(coursesRaw);

                    if (helpForm) {
                        helpForm.style.display = 'block';
                        helpForm.dataset.uniSlug = uniSlug || 'dsu';
                    }
                    if (successBox) successBox.style.display = 'none';

                    if (sampleModal) sampleModal.classList.remove('is-visible');
                    if (helpModal) helpModal.classList.add('is-visible');
                    document.body.style.overflow = 'hidden';
                }

                // 2. Open Sample Degree Modal
                const sampleBtns = document.querySelectorAll('.sode-open-sample-modal');
                sampleBtns.forEach(btn => {
                    if (btn.dataset.initialized) return;
                    btn.dataset.initialized = 'true';

                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const uniName = this.dataset.uniName || 'University';
                        const sampleImg = this.dataset.sampleImg;
                        const uniSlug = this.dataset.uniSlug;
                        const coursesRaw = this.dataset.courses;

                        currentUniData = { name: uniName, slug: uniSlug, courses: coursesRaw };

                        if (sampleModalTitle) {
                            sampleModalTitle.textContent = uniName + ' - Sample Degree';
                        }

                        if (sampleModalContent) {
                            if (sampleImg && sampleImg.trim() !== '') {
                                sampleModalContent.innerHTML = '<img src="' + sampleImg + '" alt="' + uniName + ' Sample Degree" class="sode-degree-img" loading="lazy">';
                            } else {
                                sampleModalContent.innerHTML = `
                                    <div class="sode-degree-mockup">
                                        <div class="sode-degree-gold-seal">★</div>
                                        <div class="sode-degree-mockup-uni">${uniName}</div>
                                        <div style="font-size:12px; font-weight:700; color:#0284c7; margin-bottom:10px; text-transform:uppercase; letter-spacing:1px;">
                                            UGC-DEB & NAAC Accredited Sample Degree
                                        </div>
                                        <p class="sode-degree-mockup-text">
                                            Official sample degree copy verified by Distance Education School. 
                                            Contact an academic counselor to receive the complete sample degree transcript and curriculum prospectus on WhatsApp/Email.
                                        </p>
                                    </div>
                                `;
                            }
                        }

                        if (sampleModal) sampleModal.classList.add('is-visible');
                        document.body.style.overflow = 'hidden';
                    });
                });

                if (sampleHelpBtn) {
                    sampleHelpBtn.addEventListener('click', function() {
                        if (currentUniData) {
                            openHelpModal(currentUniData.name, currentUniData.slug, currentUniData.courses);
                        } else {
                            openHelpModal('University', 'dsu', []);
                        }
                    });
                }

                // 3. Open Help Modal on "Get Help" Click
                const helpBtns = document.querySelectorAll('.sode-open-help-modal');
                helpBtns.forEach(btn => {
                    if (btn.dataset.initialized) return;
                    btn.dataset.initialized = 'true';

                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const uniName = this.dataset.uniName || 'University';
                        const uniSlug = this.dataset.uniSlug || 'dsu';
                        const coursesRaw = this.dataset.courses;
                        openHelpModal(uniName, uniSlug, coursesRaw);
                    });
                });

                // 4. Form Submit Handler Fallback (if central lead script isn't globally bound)
                if (helpForm && !helpForm.dataset.fallbackBound) {
                    helpForm.dataset.fallbackBound = 'true';

                    helpForm.addEventListener('submit', function(e) {
                        // If window.sodeFormHandlerAttached is active, let it handle, else handle here
                        if (window.sodeFormHandlerAttached) return;

                        e.preventDefault();
                        const btn = document.getElementById('sodeAltSubmitBtn');
                        const phoneInput = helpForm.querySelector('.phone');
                        const phoneVal = phoneInput ? phoneInput.value.trim() : '';

                        if (!/^[6-9]\d{9}$/.test(phoneVal)) {
                            alert('Please enter a valid 10-digit Indian mobile number');
                            if (phoneInput) phoneInput.focus();
                            return;
                        }

                        if (btn) {
                            btn.disabled = true;
                            btn.textContent = 'Submitting...';
                        }

                        const formData = new FormData(helpForm);
                        const countryCode = helpForm.querySelector('.country_code') ? helpForm.querySelector('.country_code').value : '91';
                        formData.set('phone', countryCode + phoneVal);

                        fetch('/wp-admin/admin-ajax.php?action=send_lead', {
                            method: 'POST',
                            body: formData
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                helpForm.style.display = 'none';
                                if (successBox) successBox.style.display = 'block';
                                setTimeout(() => {
                                    window.location.href = '/thank-you';
                                }, 1500);
                            } else {
                                alert(res.data || 'Submission failed. Please try again.');
                                if (btn) {
                                    btn.disabled = false;
                                    btn.textContent = 'Get Free Counseling';
                                }
                            }
                        })
                        .catch(() => {
                            // Friendly fallback: redirect to thank-you
                            helpForm.style.display = 'none';
                            if (successBox) successBox.style.display = 'block';
                            setTimeout(() => {
                                window.location.href = '/thank-you';
                            }, 1200);
                        });
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAlternateUniversities);
            } else {
                initAlternateUniversities();
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

// --------------------------------------------------------------------
// Register Shortcodes
// --------------------------------------------------------------------
if (function_exists('add_shortcode')) {
    add_shortcode('alternative_universities', 'sode_alternate_universities_render');
    add_shortcode('alternate_universities', 'sode_alternate_universities_render');
    add_shortcode('alternatives_universities', 'sode_alternate_universities_render');
    add_shortcode('alternate_university_list', 'sode_alternate_universities_render');
    add_shortcode('alternative_university_list', 'sode_alternate_universities_render');
    add_shortcode('alternatives_list', 'sode_alternate_universities_render');
}
