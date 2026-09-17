<?php
/**
 * ====================================================================
 * UNIVERSITIES COURSE FEES MATRIX TABLE - UNIVERSAL COMPONENT
 * File: university-fees-table-universal.php
 * 
 * Renders a clean comparison table of university course fees:
 *  1. Row 1 is ALWAYS the current domain university (e.g. DSU Online)
 *  2. Followed by all active alternate universities (Amity, Manipal, etc.)
 *  3. Table columns strictly match the courses offered by the current domain university
 *  4. Displays 'N/A' if an alternate university does not offer a particular course
 *  5. Includes 'Add to Compare' (floating compare dock) & 'View More / View Less'
 * 
 * Shortcodes:
 *  - [compare_universities_table]
 *  - [universities_comparison_table]
 *  - [compare_universities_fees]
 *  - [universities_fee_comparison]
 *  - [alternate_universities_fees]
 *  - [alternate_universities_fees_table]
 *  - [subdomain_fees_table]
 * ====================================================================
 */

if (defined('SODE_UNI_FEES_TABLE_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_UNI_FEES_TABLE_UNIVERSAL_LOADED', true);

// Polyfills for standalone / SSR execution
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($pairs, $atts, $shortcode = '') {
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
    function esc_html($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 999999) {
        return mt_rand($min, $max);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $title), '-'));
    }
}
if (!function_exists('did_action')) {
    function did_action($tag) {
        global $sode_did_actions;
        return !empty($sode_did_actions[$tag]);
    }
}
if (!function_exists('do_action')) {
    function do_action($tag) {
        global $sode_did_actions;
        $sode_did_actions[$tag] = true;
    }
}

if (!defined('UNI_FEES_TABLE_VISIBLE_ROWS')) {
    define('UNI_FEES_TABLE_VISIBLE_ROWS', 6);
}
if (!defined('UNI_FEES_TABLE_API_URL')) {
    define('UNI_FEES_TABLE_API_URL', 'https://admin.distanceeducationschool.com/admin/api/get_university_fees_table.php');
}

/**
 * Helper: Detect current university slug
 */
if (!function_exists('sode_detect_matrix_uni_slug')) {
    function sode_detect_matrix_uni_slug($explicit = '') {
        if (!empty($explicit)) {
            return sanitize_title($explicit);
        }
        if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
            return sanitize_title(SODE_UNIVERSITY_SLUG);
        }
        if (function_exists('sode_client_detect_uni')) {
            $u = sode_client_detect_uni();
            if ($u) return sanitize_title($u);
        }
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
        if ($host) {
            $parts = explode('.', $host);
            if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'webmail', 'admin', 'cpanel'])) {
                return sanitize_title($parts[0]);
            }
        }
        return 'dayananda-sagar-university';
    }
}

/**
 * Helper: Format fee with Indian Rupee symbol
 */
if (!function_exists('sode_matrix_format_fee')) {
    function sode_matrix_format_fee($fee) {
        $fee = trim((string)$fee);
        if ($fee === '' || $fee === '0' || strtolower($fee) === 'n/a') {
            return 'N/A';
        }
        if (strpos($fee, '₹') !== false) {
            return $fee;
        }
        if (is_numeric(str_replace([',', ' '], '', $fee))) {
            return '₹ ' . number_format((float)str_replace([',', ' '], '', $fee));
        }
        return '₹ ' . $fee;
    }
}

/**
 * Fetch University Fees Matrix Table Data
 * Uses direct local database query if available, with Central API fallback.
 */
if (!function_exists('get_university_fees_table_data')) {
    function get_university_fees_table_data($uni_slug = '') {
        static $cache = [];
        $uni_slug = sode_detect_matrix_uni_slug($uni_slug);

        if (isset($cache[$uni_slug])) {
            return $cache[$uni_slug];
        }

        // 1. Try Direct Database Connection
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
                    // Find Current University
                    $u_stmt = $db->prepare("
                        SELECT id, full_name, short_name, slug, mode, location, official_url
                        FROM universities 
                        WHERE (LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) OR LOWER(full_name) = LOWER(?) OR LOWER(slug) LIKE LOWER(?)) 
                          AND is_active = 1 
                        LIMIT 1
                    ");
                    $search_like = '%' . strtolower($uni_slug) . '%';
                    $u_stmt->execute([$uni_slug, $uni_slug, $uni_slug, $search_like]);
                    $current_uni = $u_stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$current_uni) {
                        $fallback_stmt = $db->query("SELECT id, full_name, short_name, slug, mode, location, official_url FROM universities WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                        $current_uni = $fallback_stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if ($current_uni) {
                        // Fetch Mapped Courses for Current University
                        $c_stmt = $db->prepare("
                            SELECT c.id AS course_id, c.short_name, c.full_name, c.level,
                                   ucm.per_semester_fee, ucm.total_program_fee, ucm.mode
                            FROM university_course_mappings ucm
                            JOIN courses c ON ucm.course_id = c.id
                            WHERE ucm.university_id = ?
                            ORDER BY ucm.id ASC
                        ");
                        $c_stmt->execute([$current_uni['id']]);
                        $current_courses = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

                        // Sort order: Masters first, then Bachelors
                        $course_ranks = [
                            'mba' => 1, 'mca' => 2, 'mcom' => 3, 'm.com' => 3, 'ma' => 4, 'msc' => 5, 'm.sc' => 5,
                            'bba' => 10, 'bcom' => 11, 'b.com' => 11, 'bca' => 12, 'ba' => 13, 'bsc' => 14, 'b.sc' => 14
                        ];
                        usort($current_courses, function($a, $b) use ($course_ranks) {
                            $k_a = strtolower(str_replace(['.', ' '], '', $a['short_name']));
                            $k_b = strtolower(str_replace(['.', ' '], '', $b['short_name']));
                            $r_a = $course_ranks[$k_a] ?? 50;
                            $r_b = $course_ranks[$k_b] ?? 50;
                            return ($r_a === $r_b) ? (($a['course_id'] < $b['course_id']) ? -1 : 1) : (($r_a < $r_b) ? -1 : 1);
                        });

                        // Columns
                        $columns = [];
                        foreach ($current_courses as $c) {
                            $clean_name = strtoupper(str_replace(['.', ' '], '', trim($c['short_name'])));
                            $columns[] = [
                                'course_id'   => (int) $c['course_id'],
                                'short_name'  => trim($c['short_name']),
                                'clean_name'  => $clean_name,
                                'header_text' => $clean_name . ' SEMESTER FEE',
                            ];
                        }

                        // Alternate Universities
                        $alt_stmt = $db->query("
                            SELECT id, full_name, short_name, slug, mode, location, official_url
                            FROM universities
                            WHERE show_in_alternate = 1 AND is_active = 1
                            ORDER BY id ASC
                        ");
                        $alt_unis = $alt_stmt->fetchAll(PDO::FETCH_ASSOC);

                        $all_unis = [];
                        $all_unis[] = array_merge($current_uni, ['is_current' => true]);

                        foreach ($alt_unis as $alt) {
                            if ((int)$alt['id'] === (int)$current_uni['id']) {
                                continue;
                            }
                            $all_unis[] = array_merge($alt, ['is_current' => false]);
                        }

                        // Pre-fetch fees for all selected universities
                        $uni_ids = array_column($all_unis, 'id');
                        $placeholders = implode(',', array_fill(0, count($uni_ids), '?'));
                        $mappings_stmt = $db->prepare("
                            SELECT ucm.university_id, c.id AS course_id, c.short_name, ucm.per_semester_fee
                            FROM university_course_mappings ucm
                            JOIN courses c ON ucm.course_id = c.id
                            WHERE ucm.university_id IN ($placeholders)
                        ");
                        $mappings_stmt->execute($uni_ids);
                        $all_mappings = $mappings_stmt->fetchAll(PDO::FETCH_ASSOC);

                        $uni_fees_map = [];
                        foreach ($all_mappings as $m) {
                            $u_id = (int)$m['university_id'];
                            $c_id = (int)$m['course_id'];
                            $clean_c = strtolower(str_replace(['.', ' '], '', $m['short_name']));
                            $uni_fees_map[$u_id]['by_id'][$c_id] = $m['per_semester_fee'];
                            $uni_fees_map[$u_id]['by_name'][$clean_c] = $m['per_semester_fee'];
                        }

                        $rows = [];
                        foreach ($all_unis as $u) {
                            $u_id = (int)$u['id'];
                            $is_curr = !empty($u['is_current']);

                            if ($is_curr) {
                                $base_name = !empty($u['short_name']) ? $u['short_name'] : $u['full_name'];
                                $disp_name = (stripos($base_name, 'online') === false) ? $base_name . ' Online' : $base_name;
                            } else {
                                $disp_name = !empty($u['full_name']) ? $u['full_name'] : $u['short_name'];
                            }

                            $fees_row = [];
                            foreach ($columns as $col) {
                                $col_id = $col['course_id'];
                                $col_clean = strtolower($col['clean_name']);
                                $fee_val = $uni_fees_map[$u_id]['by_id'][$col_id] ?? ($uni_fees_map[$u_id]['by_name'][$col_clean] ?? null);
                                $fees_row[$col['clean_name']] = sode_matrix_format_fee($fee_val);
                            }

                            $rows[] = [
                                'id'         => $u_id,
                                'name'       => $disp_name,
                                'short_name' => $u['short_name'] ?? '',
                                'full_name'  => $u['full_name'] ?? '',
                                'slug'       => $u['slug'] ?? '',
                                'link'       => $u['official_url'] ?? '',
                                'is_current' => $is_curr,
                                'fees'       => $fees_row,
                            ];
                        }

                        $data = [
                            'current_university' => [
                                'id'           => (int)$current_uni['id'],
                                'full_name'    => $current_uni['full_name'],
                                'short_name'   => $current_uni['short_name'],
                                'slug'         => $current_uni['slug'],
                                'display_name' => (!empty($current_uni['short_name']) ? $current_uni['short_name'] : $current_uni['full_name']) . ' Online',
                            ],
                            'columns'      => $columns,
                            'universities' => $rows,
                        ];

                        $cache[$uni_slug] = $data;
                        return $data;
                    }
                }
            } catch (Exception $e) {
                // Fallback to Central API
            }
        }

        // 2. Central API Fallback (for remote client subdomains)
        $api_url = UNI_FEES_TABLE_API_URL . '?uni=' . urlencode($uni_slug);
        $json_content = null;

        if (function_exists('wp_remote_get')) {
            $resp = wp_remote_get($api_url, ['timeout' => 8, 'headers' => ['Cache-Control' => 'no-cache']]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $json_content = wp_remote_retrieve_body($resp);
            }
        }
        if (!$json_content && function_exists('file_get_contents')) {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $json_content = @file_get_contents($api_url, false, $ctx);
        }

        if ($json_content) {
            $res = json_decode($json_content, true);
            if (!empty($res['success']) && !empty($res['universities'])) {
                $cache[$uni_slug] = $res;
                return $res;
            }
        }

        return false;
    }
}

/**
 * Render University Fees Table
 */
if (!function_exists('sode_render_university_fees_table')) {
    function sode_render_university_fees_table($atts = []) {
        $atts = shortcode_atts([
            'uni'          => '',
            'university'   => '',
            'visible_rows' => UNI_FEES_TABLE_VISIBLE_ROWS,
            'limit'        => '',
            'heading'      => '',
            'description'  => '',
            'class'        => '',
        ], $atts);

        $uni_slug = !empty($atts['uni']) ? $atts['uni'] : $atts['university'];
        $data = get_university_fees_table_data($uni_slug);

        if (empty($data) || empty($data['universities']) || empty($data['columns'])) {
            return '<p><em>University fee comparison data is currently updating. Please refresh shortly.</em></p>';
        }

        $columns = $data['columns'];
        $universities = $data['universities'];
        $total_rows = count($universities);

        $visible_rows = !empty($atts['limit']) ? (int)$atts['limit'] : (int)$atts['visible_rows'];
        if ($visible_rows <= 0) $visible_rows = 6;
        $has_more = $total_rows > $visible_rows;

        static $inst_count = 0;
        $inst_count++;
        $table_id = 'sode-uni-fees-table-' . $inst_count . '-' . wp_rand(100, 999);

        // Primary course slug for compare button (first column)
        $primary_course = !empty($columns[0]['short_name']) ? strtolower(trim($columns[0]['short_name'])) : 'mba';

        ob_start();
        ?>
        <div class="sode-uni-fees-table-wrap <?php echo esc_attr($atts['class']); ?>">

            <?php if (!empty($atts['heading'])): ?>
                <h2 class="sode-uni-fees-heading"><?php echo esc_html($atts['heading']); ?></h2>
            <?php endif; ?>

            <?php if (!empty($atts['description'])): ?>
                <p class="sode-uni-fees-description"><?php echo esc_html($atts['description']); ?></p>
            <?php endif; ?>

            <div class="sode-uni-fees-scroll">
                <table class="sode-uni-fees-table" id="<?php echo esc_attr($table_id); ?>">
                    <thead>
                        <tr>
                            <th class="sode-uni-th-name">UNIVERSITY NAME</th>
                            <?php foreach ($columns as $col): ?>
                                <th class="sode-uni-th-fee"><?php echo esc_html(strtoupper($col['header_text'])); ?></th>
                            <?php endforeach; ?>
                            <th class="sode-uni-th-compare">COMPARE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($universities as $idx => $uni):
                            if ($idx >= $visible_rows) break;
                            $is_current = !empty($uni['is_current']);
                        ?>
                            <tr class="<?php echo $is_current ? 'sode-row-current-uni' : ''; ?>">
                                <td class="sode-uni-td-name">
                                    <?php if (!empty($uni['link'])): ?>
                                        <a href="<?php echo esc_url($uni['link']); ?>" target="_blank" rel="noopener noreferrer" class="sode-uni-name-link">
                                            <?php echo esc_html($uni['name']); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="sode-uni-name-text"><?php echo esc_html($uni['name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($columns as $col): 
                                    $val = $uni['fees'][$col['clean_name']] ?? 'N/A';
                                    $is_na = ($val === 'N/A');
                                ?>
                                    <td class="sode-uni-td-fee <?php echo $is_na ? 'is-na' : ''; ?>">
                                        <?php echo esc_html($val); ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="sode-uni-td-compare">
                                    <button type="button"
                                        class="uni-compare-toggle-btn"
                                        data-uni-name="<?php echo esc_attr($uni['name']); ?>"
                                        data-uni-slug="<?php echo esc_attr($uni['slug'] ?: sanitize_title($uni['name'])); ?>"
                                        data-course="<?php echo esc_attr($primary_course); ?>"
                                        aria-label="Compare <?php echo esc_attr($uni['name']); ?>">
                                        <span class="compare-icon">+</span>
                                        <span class="compare-text">Add to Compare</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($has_more): ?>
                        <tbody class="sode-uni-fees-extra-rows" style="display:none;">
                            <?php foreach ($universities as $idx => $uni):
                                if ($idx < $visible_rows) continue;
                                $is_current = !empty($uni['is_current']);
                            ?>
                                <tr class="<?php echo $is_current ? 'sode-row-current-uni' : ''; ?>">
                                    <td class="sode-uni-td-name">
                                        <?php if (!empty($uni['link'])): ?>
                                            <a href="<?php echo esc_url($uni['link']); ?>" target="_blank" rel="noopener noreferrer" class="sode-uni-name-link">
                                                <?php echo esc_html($uni['name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="sode-uni-name-text"><?php echo esc_html($uni['name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($columns as $col): 
                                        $val = $uni['fees'][$col['clean_name']] ?? 'N/A';
                                        $is_na = ($val === 'N/A');
                                    ?>
                                        <td class="sode-uni-td-fee <?php echo $is_na ? 'is-na' : ''; ?>">
                                            <?php echo esc_html($val); ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="sode-uni-td-compare">
                                        <button type="button"
                                            class="uni-compare-toggle-btn"
                                            data-uni-name="<?php echo esc_attr($uni['name']); ?>"
                                            data-uni-slug="<?php echo esc_attr($uni['slug'] ?: sanitize_title($uni['name'])); ?>"
                                            data-course="<?php echo esc_attr($primary_course); ?>"
                                            aria-label="Compare <?php echo esc_attr($uni['name']); ?>">
                                            <span class="compare-icon">+</span>
                                            <span class="compare-text">Add to Compare</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    <?php endif; ?>
                </table>
            </div>

            <?php if ($has_more): ?>
                <div class="sode-uni-fees-btn-wrap">
                    <button type="button" class="sode-uni-fees-toggle-btn" data-target="<?php echo esc_attr($table_id); ?>">
                        View More
                    </button>
                </div>
            <?php endif; ?>

        </div>
        <?php

        // Print Assets (CSS, JS, Compare Dock & Toast) only once per page
        if (!did_action('uni_compare_dock_assets_printed')) {
            do_action('uni_compare_dock_assets_printed');
            ?>
            <!-- FLOATING COMPARE DOCK -->
            <div id="uni-compare-dock" class="uni-compare-dock" style="display:none;" aria-live="polite">
                <div class="uni-compare-dock-container">
                    <div class="uni-compare-dock-info">
                        <div class="uni-compare-dock-title-wrap">
                            <span class="uni-compare-dock-title">Compare Universities</span>
                            <span class="uni-compare-dock-badge" id="uni-compare-count-badge">0/3</span>
                        </div>
                        <div class="uni-compare-chips-list" id="uni-compare-chips-list"></div>
                    </div>
                    <div class="uni-compare-dock-actions">
                        <button type="button" class="uni-compare-clear-btn" id="uni-compare-clear-btn">Clear</button>
                        <button type="button" class="uni-compare-submit-btn" id="uni-compare-submit-btn">
                            <span>Compare Now</span>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- TOAST NOTIFICATION -->
            <div id="uni-compare-toast" class="uni-compare-toast" style="display:none;"></div>

            <style>
            /* Universities Fees Matrix Table Styles */
            .sode-uni-fees-table-wrap {
                width: 100%;
                margin: 24px 0;
                box-sizing: border-box;
            }
            .sode-uni-fees-heading {
                font-size: 24px;
                font-weight: 700;
                color: #0c2340;
                margin: 0 0 8px 0;
            }
            .sode-uni-fees-description {
                font-size: 14px;
                color: #4b5563;
                margin: 0 0 16px 0;
                line-height: 1.5;
            }
            .sode-uni-fees-scroll {
                width: 100%;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            }
            .sode-uni-fees-table {
                width: 100%;
                min-width: 820px;
                border-collapse: collapse !important;
                font-size: 13px;
                margin: 0;
            }
            .sode-uni-fees-table thead th {
                background-color: #e0f2fe !important;
                color: #0c2340 !important;
                text-align: left;
                padding: 14px 16px;
                font-weight: 700;
                font-size: 12.5px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                white-space: nowrap;
                border-bottom: 1px solid #cbd5e1 !important;
                border-right: 1px solid #cbd5e1 !important;
            }
            .sode-uni-fees-table thead th:last-child {
                border-right: none !important;
            }
            .sode-uni-th-name {
                width: 220px;
                min-width: 190px;
            }
            .sode-uni-th-fee {
                text-align: left;
                white-space: nowrap;
            }
            .sode-uni-th-compare {
                width: 140px;
                min-width: 130px;
                text-align: center !important;
            }
            .sode-uni-fees-table tbody td {
                padding: 13px 16px;
                border-bottom: 1px solid #e2e8f0 !important;
                border-right: 1px solid #e2e8f0 !important;
                vertical-align: middle;
                color: #1f2937;
                font-size: 13px;
            }
            .sode-uni-fees-table tbody td:last-child {
                border-right: none !important;
            }
            .sode-uni-fees-table tbody tr:hover {
                background-color: #f8fafc;
            }
            /* First Row: Current Domain University */
            .sode-row-current-uni td {
                background-color: #ffffff;
            }
            .sode-row-current-uni .sode-uni-td-name .sode-uni-name-text,
            .sode-row-current-uni .sode-uni-td-name .sode-uni-name-link {
                font-weight: 800;
                color: #000000;
                font-size: 13.5px;
            }
            .sode-uni-name-text {
                font-weight: 700;
                color: #0c2340;
            }
            .sode-uni-name-link {
                color: #0c2340;
                font-weight: 700;
                text-decoration: none;
                transition: color 0.15s ease;
            }
            .sode-uni-name-link:hover {
                color: #2563eb;
                text-decoration: underline;
            }
            .sode-uni-td-fee {
                font-weight: 500;
                color: #111827;
                white-space: nowrap;
            }
            .sode-uni-td-fee.is-na {
                color: #94a3b8;
                font-weight: 600;
            }
            .sode-uni-td-compare {
                text-align: center;
                white-space: nowrap;
            }

            /* COMPARE BUTTON */
            .uni-compare-toggle-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
                padding: 7px 14px;
                font-size: 12px;
                font-weight: 600;
                color: #2563eb;
                background-color: #eff6ff;
                border: 1px solid #bfdbfe;
                border-radius: 20px;
                cursor: pointer;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                user-select: none;
            }
            .uni-compare-toggle-btn:hover {
                background-color: #dbeafe;
                border-color: #93c5fd;
                transform: translateY(-1px);
            }
            .uni-compare-toggle-btn.is-active {
                background-color: #2563eb;
                color: #ffffff;
                border-color: #2563eb;
                box-shadow: 0 3px 10px rgba(37, 99, 235, 0.35);
            }

            /* View More / View Less Toggle Button */
            .sode-uni-fees-btn-wrap {
                text-align: center;
                margin-top: 20px;
            }
            .sode-uni-fees-toggle-btn {
                padding: 10px 28px;
                background-color: #2563eb;
                color: #ffffff;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 700;
                font-size: 14px;
                box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
                transition: background-color 0.2s ease, transform 0.1s ease;
            }
            .sode-uni-fees-toggle-btn:hover {
                background-color: #1d4ed8;
                transform: translateY(-1px);
            }

            /* FLOATING COMPARE DOCK */
            .uni-compare-dock {
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%) translateY(0);
                z-index: 2147483647 !important;
                width: calc(100% - 32px);
                max-width: 900px;
                background: rgba(15, 23, 42, 0.94);
                backdrop-filter: blur(14px);
                -webkit-backdrop-filter: blur(14px);
                border: 1px solid rgba(255, 255, 255, 0.15);
                border-radius: 18px;
                box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.05);
                color: #ffffff;
                padding: 14px 20px;
                animation: sodeDockSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
                box-sizing: border-box;
            }
            @keyframes sodeDockSlideUp {
                from { transform: translateX(-50%) translateY(120px); opacity: 0; }
                to { transform: translateX(-50%) translateY(0); opacity: 1; }
            }
            .uni-compare-dock-container {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
            }
            .uni-compare-dock-info {
                display: flex;
                align-items: center;
                gap: 16px;
                flex: 1;
                min-width: 0;
            }
            .uni-compare-dock-title-wrap {
                display: flex;
                align-items: center;
                gap: 8px;
                white-space: nowrap;
            }
            .uni-compare-dock-title {
                font-weight: 700;
                font-size: 14px;
                color: #f8fafc;
                letter-spacing: 0.2px;
            }
            .uni-compare-dock-badge {
                background: #2563eb;
                color: #ffffff;
                font-size: 11px;
                font-weight: 700;
                padding: 2px 7px;
                border-radius: 10px;
            }
            .uni-compare-chips-list {
                display: flex;
                align-items: center;
                gap: 8px;
                overflow-x: auto;
                padding: 2px 0;
            }
            .uni-compare-chip {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: rgba(255, 255, 255, 0.12);
                border: 1px solid rgba(255, 255, 255, 0.2);
                padding: 5px 10px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 500;
                color: #ffffff;
                white-space: nowrap;
            }
            .uni-compare-chip-remove {
                background: none;
                border: none;
                color: #cbd5e1;
                font-size: 16px;
                line-height: 1;
                cursor: pointer;
                padding: 0;
                display: flex;
                align-items: center;
            }
            .uni-compare-chip-remove:hover {
                color: #f87171;
            }
            .uni-compare-chip-slot {
                border: 1px dashed rgba(255, 255, 255, 0.3);
                border-radius: 8px;
                padding: 5px 10px;
                font-size: 12px;
                color: #94a3b8;
                white-space: nowrap;
            }
            .uni-compare-dock-actions {
                display: flex;
                align-items: center;
                gap: 10px;
                white-space: nowrap;
            }
            .uni-compare-clear-btn {
                background: transparent;
                border: 1px solid rgba(255, 255, 255, 0.25);
                color: #cbd5e1;
                font-size: 12px;
                font-weight: 600;
                padding: 8px 14px;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .uni-compare-clear-btn:hover {
                background: rgba(255, 255, 255, 0.1);
                color: #ffffff;
            }
            .uni-compare-submit-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #2563eb;
                color: #ffffff;
                border: none;
                font-size: 13px;
                font-weight: 700;
                padding: 9px 18px;
                border-radius: 8px;
                cursor: pointer;
                transition: background-color 0.15s ease, transform 0.1s ease;
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            }
            .uni-compare-submit-btn:hover {
                background: #1d4ed8;
                transform: translateY(-1px);
            }
            .uni-compare-toast {
                position: fixed;
                bottom: 96px;
                left: 50%;
                transform: translateX(-50%);
                background: #ef4444;
                color: #ffffff;
                padding: 10px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
                z-index: 2147483647 !important;
            }

            @media (max-width: 768px) {
                .uni-compare-dock {
                    bottom: 12px;
                    padding: 12px 14px;
                    width: calc(100% - 20px);
                    border-radius: 14px;
                }
                .uni-compare-dock-container {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 10px;
                }
                .uni-compare-dock-info {
                    min-width: 100%;
                    justify-content: space-between;
                }
                .uni-compare-dock-actions {
                    justify-content: flex-end;
                }
                .uni-compare-submit-btn {
                    flex: 1;
                    justify-content: center;
                }
                .uni-compare-toast {
                    bottom: 135px;
                    width: calc(100% - 32px);
                    text-align: center;
                    box-sizing: border-box;
                }
            }
            </style>

            <script>
            (function() {
                var selectedUnis = []; // { name, slug, course }
                var maxSelections = 3;
                var toastTimer = null;

                function showToast(message) {
                    var toast = document.getElementById('uni-compare-toast');
                    if (!toast) return;
                    toast.textContent = message;
                    toast.style.display = 'block';
                    if (toastTimer) clearTimeout(toastTimer);
                    toastTimer = setTimeout(function() {
                        toast.style.display = 'none';
                    }, 2800);
                }

                function updateUI() {
                    var dock = document.getElementById('uni-compare-dock');
                    var countBadge = document.getElementById('uni-compare-count-badge');
                    var chipsList = document.getElementById('uni-compare-chips-list');

                    // Update all compare buttons across the page
                    var allBtns = document.querySelectorAll('.uni-compare-toggle-btn');
                    allBtns.forEach(function(btn) {
                        var slug = btn.getAttribute('data-uni-slug');
                        var isSelected = selectedUnis.some(function(item) { return item.slug === slug; });
                        if (isSelected) {
                            btn.classList.add('is-active');
                            var icon = btn.querySelector('.compare-icon');
                            if (icon) icon.textContent = '✓';
                            var text = btn.querySelector('.compare-text');
                            if (text) text.textContent = 'Selected';
                        } else {
                            btn.classList.remove('is-active');
                            var icon = btn.querySelector('.compare-icon');
                            if (icon) icon.textContent = '+';
                            var text = btn.querySelector('.compare-text');
                            if (text) text.textContent = 'Add to Compare';
                        }
                    });

                    if (!dock || !countBadge || !chipsList) return;

                    if (selectedUnis.length === 0) {
                        dock.style.display = 'none';
                        return;
                    }

                    dock.style.display = 'block';
                    countBadge.textContent = selectedUnis.length + '/' + maxSelections;

                    var html = '';
                    selectedUnis.forEach(function(item) {
                        var safeName = document.createElement('div');
                        safeName.textContent = item.name;
                        html += '<div class="uni-compare-chip">' +
                            '<span>' + safeName.innerHTML + '</span>' +
                            '<button type="button" class="uni-compare-chip-remove" data-slug="' + item.slug + '" aria-label="Remove ' + safeName.innerHTML + '">&times;</button>' +
                            '</div>';
                    });

                    var remaining = maxSelections - selectedUnis.length;
                    for (var i = 0; i < remaining; i++) {
                        html += '<div class="uni-compare-chip-slot">+ Add University</div>';
                    }
                    chipsList.innerHTML = html;
                }

                document.addEventListener('click', function(e) {
                    // 1. Toggle Button
                    var toggleBtn = e.target.closest('.uni-compare-toggle-btn');
                    if (toggleBtn) {
                        var slug = toggleBtn.getAttribute('data-uni-slug');
                        var name = toggleBtn.getAttribute('data-uni-name');
                        var course = toggleBtn.getAttribute('data-course');

                        var idx = selectedUnis.findIndex(function(item) { return item.slug === slug; });
                        if (idx > -1) {
                            selectedUnis.splice(idx, 1);
                        } else {
                            if (selectedUnis.length >= maxSelections) {
                                showToast('You can compare a maximum of 3 universities.');
                                return;
                            }
                            selectedUnis.push({ name: name, slug: slug, course: course });
                        }
                        updateUI();
                        return;
                    }

                    // 2. Chip Remove Button
                    var removeBtn = e.target.closest('.uni-compare-chip-remove');
                    if (removeBtn) {
                        var removeSlug = removeBtn.getAttribute('data-slug');
                        selectedUnis = selectedUnis.filter(function(item) { return item.slug !== removeSlug; });
                        updateUI();
                        return;
                    }

                    // 3. Clear All Button
                    if (e.target.closest('#uni-compare-clear-btn')) {
                        selectedUnis = [];
                        updateUI();
                        return;
                    }

                    // 4. Submit Button
                    if (e.target.closest('#uni-compare-submit-btn')) {
                        if (selectedUnis.length === 0) return;
                        var slugs = selectedUnis.map(function(item) { return item.slug; }).join(',');
                        var course = selectedUnis[0].course || 'mba';
                        var redirectUrl = 'https://distanceeducationschool.com/compare-university/?university=' + slugs + '&course=' + encodeURIComponent(course);
                        window.open(redirectUrl, '_blank');
                        return;
                    }

                    // 5. View More / View Less Toggle
                    var viewMoreBtn = e.target.closest('.sode-uni-fees-toggle-btn');
                    if (viewMoreBtn) {
                        var table = document.getElementById(viewMoreBtn.getAttribute('data-target'));
                        if (!table) return;
                        var extraTbody = table.querySelector('.sode-uni-fees-extra-rows');
                        if (!extraTbody) return;

                        var isHidden = (extraTbody.style.display === 'none' || extraTbody.style.display === '');
                        extraTbody.style.display = isHidden ? 'table-row-group' : 'none';
                        viewMoreBtn.textContent = isHidden ? 'View Less' : 'View More';
                        return;
                    }
                });
            })();
            </script>
            <?php
        }

        return ob_get_clean();
    }
}

// Register Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('compare_universities_table', 'sode_render_university_fees_table');
    add_shortcode('universities_comparison_table', 'sode_render_university_fees_table');
    add_shortcode('compare_universities_fees', 'sode_render_university_fees_table');
    add_shortcode('universities_fee_comparison', 'sode_render_university_fees_table');
    add_shortcode('alternate_universities_fees', 'sode_render_university_fees_table');
    add_shortcode('alternate_universities_fees_table', 'sode_render_university_fees_table');
    add_shortcode('subdomain_fees_table', 'sode_render_university_fees_table');
    add_shortcode('subdomain_course_fees_table', 'sode_render_university_fees_table');
}
