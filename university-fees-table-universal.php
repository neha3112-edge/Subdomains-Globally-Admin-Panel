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
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 999999)
    {
        return mt_rand($min, $max);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $title), '-'));
    }
}
if (!function_exists('did_action')) {
    function did_action($tag)
    {
        global $sode_did_actions;
        return !empty($sode_did_actions[$tag]);
    }
}
if (!function_exists('do_action')) {
    function do_action($tag)
    {
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
if (!function_exists('sode_find_matching_university')) {
    function sode_find_matching_university($unis, $search_term)
    {
        if (empty($search_term) || empty($unis))
            return null;
        $term = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', (string) $search_term)));
        if ($term === '')
            return null;

        // 1. Exact match on slug, short_name, or full_name
        foreach ($unis as $u) {
            $clean_slug = strtolower(str_replace(['-', '_', ' '], '', $u['slug'] ?? ''));
            $clean_short = strtolower(str_replace(['-', '_', ' ', '.'], '', $u['short_name'] ?? ''));
            $clean_full = strtolower(str_replace(['-', '_', ' ', '.', ','], '', $u['full_name'] ?? ''));
            if ($term === $clean_slug || $term === $clean_short || $term === $clean_full) {
                return $u;
            }
        }

        // 2. Dynamic Acronym / Initials match (e.g. cu, dsu, lpu, smu, vgu, muj)
        foreach ($unis as $u) {
            $slug_parts = explode('-', strtolower($u['slug'] ?? ''));
            $slug_ac = '';
            foreach ($slug_parts as $sp) {
                if ($sp !== '')
                    $slug_ac .= $sp[0];
            }
            if ($term === $slug_ac) {
                return $u;
            }

            $full_words = preg_split('/[\s,\-\.]+/', strtolower($u['full_name'] ?? ''));
            $full_ac = '';
            foreach ($full_words as $fw) {
                if ($fw !== '' && !in_array($fw, ['and', 'of', 'for', 'the', 'in'])) {
                    $full_ac .= $fw[0];
                }
            }
            if ($term === $full_ac) {
                return $u;
            }
        }

        // 3. Substring / Prefix match on slug or name
        foreach ($unis as $u) {
            $clean_slug = strtolower(str_replace(['-', '_', ' '], '', $u['slug'] ?? ''));
            $clean_full = strtolower(str_replace(['-', '_', ' ', '.', ','], '', $u['full_name'] ?? ''));
            if (strpos($clean_slug, $term) !== false || strpos($clean_full, $term) !== false) {
                return $u;
            }
        }

        return null;
    }
}

/**
 * Helper: Detect current university slug
 */
if (!function_exists('sode_detect_matrix_uni_slug')) {
    function sode_detect_matrix_uni_slug($explicit = '')
    {
        $explicit = trim((string) $explicit);
        if (!empty($explicit)) {
            return sanitize_title($explicit);
        }
        if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
            return sanitize_title(SODE_UNIVERSITY_SLUG);
        }
        if (function_exists('sode_client_detect_uni')) {
            $u = sode_client_detect_uni();
            if ($u)
                return sanitize_title($u);
        }
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
        if ($host) {
            $parts = explode('.', $host);
            if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'webmail', 'admin', 'cpanel'])) {
                return sanitize_title($parts[0]);
            }
        }
        return 'dsu';
    }
}

/**
 * Helper: Format fee with Indian Rupee symbol
 */
if (!function_exists('sode_matrix_format_fee')) {
    function sode_matrix_format_fee($fee)
    {
        $fee = trim((string) $fee);
        if ($fee === '' || $fee === '0' || strtolower($fee) === 'n/a') {
            return 'N/A';
        }
        if (strpos($fee, '₹') !== false) {
            return $fee;
        }
        if (is_numeric(str_replace([',', ' '], '', $fee))) {
            return '₹ ' . number_format((float) str_replace([',', ' '], '', $fee));
        }
        return '₹ ' . $fee;
    }
}

/**
 * Fetch University Fees Matrix Table Data
 * Uses direct local database query if available, with Central API fallback.
 */
if (!function_exists('get_university_fees_table_data')) {
    function get_university_fees_table_data($uni_slug = '')
    {
        $uni_slug = sode_detect_matrix_uni_slug($uni_slug);

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
                    // Fetch all active universities & dynamically match
                    $all_active_stmt = $db->query("SELECT id, full_name, short_name, slug, mode, location, official_url FROM universities WHERE is_active = 1 ORDER BY id ASC");
                    $all_active_unis = $all_active_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $current_uni = sode_find_matching_university($all_active_unis, $uni_slug);
                    if (!$current_uni && !empty($all_active_unis)) {
                        $current_uni = $all_active_unis[0];
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
                            'mba' => 1,
                            'mca' => 2,
                            'mcom' => 3,
                            'm.com' => 3,
                            'ma' => 4,
                            'msc' => 5,
                            'm.sc' => 5,
                            'bba' => 10,
                            'bcom' => 11,
                            'b.com' => 11,
                            'bca' => 12,
                            'ba' => 13,
                            'bsc' => 14,
                            'b.sc' => 14
                        ];
                        usort($current_courses, function ($a, $b) use ($course_ranks) {
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
                                'course_id' => (int) $c['course_id'],
                                'short_name' => trim($c['short_name']),
                                'clean_name' => $clean_name,
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
                            if ((int) $alt['id'] === (int) $current_uni['id']) {
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
                            $u_id = (int) $m['university_id'];
                            $c_id = (int) $m['course_id'];
                            $clean_c = strtolower(str_replace(['.', ' '], '', $m['short_name']));
                            $uni_fees_map[$u_id]['by_id'][$c_id] = $m['per_semester_fee'];
                            $uni_fees_map[$u_id]['by_name'][$clean_c] = $m['per_semester_fee'];
                        }

                        $rows = [];
                        foreach ($all_unis as $u) {
                            $u_id = (int) $u['id'];
                            $is_curr = !empty($u['is_current']);

                            // Display name formatting: use full university name consistently
                            $disp_name = !empty($u['full_name']) ? $u['full_name'] : $u['short_name'];

                            $fees_row = [];
                            foreach ($columns as $col) {
                                $col_id = $col['course_id'];
                                $col_clean = strtolower($col['clean_name']);
                                $fee_val = $uni_fees_map[$u_id]['by_id'][$col_id] ?? ($uni_fees_map[$u_id]['by_name'][$col_clean] ?? null);
                                $fees_row[$col['clean_name']] = sode_matrix_format_fee($fee_val);
                            }

                            $rows[] = [
                                'id' => $u_id,
                                'name' => $disp_name,
                                'short_name' => $u['short_name'] ?? '',
                                'full_name' => $u['full_name'] ?? '',
                                'slug' => $u['slug'] ?? '',
                                'link' => '',
                                'is_current' => $is_curr,
                                'fees' => $fees_row,
                            ];
                        }

                        $data = [
                            'current_university' => [
                                'id' => (int) $current_uni['id'],
                                'full_name' => $current_uni['full_name'],
                                'short_name' => $current_uni['short_name'],
                                'slug' => $current_uni['slug'],
                                'display_name' => !empty($current_uni['full_name']) ? $current_uni['full_name'] : $current_uni['short_name'],
                            ],
                            'columns' => $columns,
                            'universities' => $rows,
                        ];

                        return $data;
                    }
                }
            } catch (Exception $e) {
                // Fallback to Central API
            }
        }

        // 2. Central API Fallback (for remote client subdomains - Live & Real-Time)
        $api_url = UNI_FEES_TABLE_API_URL . '?uni=' . urlencode($uni_slug) . '&_t=' . time();
        $json_content = null;

        if (function_exists('wp_remote_get')) {
            $resp = wp_remote_get($api_url, [
                'timeout' => 8, 
                'headers' => [
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache'
                ]
            ]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $json_content = wp_remote_retrieve_body($resp);
            }
        }
        if (!$json_content && function_exists('file_get_contents')) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'header' => "Cache-Control: no-cache, no-store, must-revalidate\r\nPragma: no-cache\r\n"
                ]
            ]);
            $json_content = @file_get_contents($api_url, false, $ctx);
        }

        if ($json_content) {
            $res = json_decode($json_content, true);
            if (!empty($res['success']) && !empty($res['universities'])) {
                return $res;
            }
        }

        return false;
    }
}

/**
 * Helper: Render Row Cells for University Fees Table
 */
if (!function_exists('sode_render_uni_fees_row_cells')) {
    function sode_render_uni_fees_row_cells($uni, $columns, $primary_course)
    {
        $name = !empty($uni['full_name']) ? $uni['full_name'] : (!empty($uni['name']) ? $uni['name'] : $uni['short_name']);
        $slug = !empty($uni['slug']) ? $uni['slug'] : sanitize_title($name);
        $is_curr = !empty($uni['is_current']);
        ?>
        <!-- Mobile Compare Column (First) -->
        <td class="sode-uni-td-compare sode-col-mobile-only">
            <button type="button" class="uni-compare-toggle-btn<?php echo $is_curr ? ' is-active' : ''; ?>" data-uni-name="<?php echo esc_attr($name); ?>"
                data-uni-slug="<?php echo esc_attr($slug); ?>" data-course="<?php echo esc_attr($primary_course); ?>"
                <?php if ($is_curr): ?>data-is-current="1"<?php endif; ?>
                aria-label="Compare <?php echo esc_attr($name); ?>">
                <span class="compare-icon"><?php echo $is_curr ? '✓' : '+'; ?></span>
                <span class="compare-text"><?php echo $is_curr ? 'Selected' : 'Compare'; ?></span>
            </button>
        </td>

        <!-- University Name Column -->
        <td class="sode-uni-td-name">
            <span class="sode-uni-name-text"><?php echo esc_html($name); ?></span>
        </td>

        <!-- Course Fee Columns -->
        <?php foreach ($columns as $col):
            $val = $uni['fees'][$col['clean_name']] ?? 'N/A';
            $is_na = ($val === 'N/A');
            ?>
            <td class="sode-uni-td-fee <?php echo $is_na ? 'is-na' : ''; ?>">
                <?php echo esc_html($val); ?>
            </td>
        <?php endforeach; ?>

        <!-- Desktop Compare Column (Last) -->
        <td class="sode-uni-td-compare sode-col-desktop-only">
            <button type="button" class="uni-compare-toggle-btn<?php echo $is_curr ? ' is-active' : ''; ?>" data-uni-name="<?php echo esc_attr($name); ?>"
                data-uni-slug="<?php echo esc_attr($slug); ?>" data-course="<?php echo esc_attr($primary_course); ?>"
                <?php if ($is_curr): ?>data-is-current="1"<?php endif; ?>
                aria-label="Compare <?php echo esc_attr($name); ?>">
                <span class="compare-icon"><?php echo $is_curr ? '✓' : '+'; ?></span>
                <span class="compare-text"><?php echo $is_curr ? 'Selected' : 'Add to Compare'; ?></span>
            </button>
        </td>
        <?php
    }
}

/**
 * Render University Fees Table
 */
if (!function_exists('sode_render_university_fees_table')) {
    function sode_render_university_fees_table($atts = [])
    {
        $raw_atts = (array) ($atts ?: []);
        $explicit_uni = !empty($raw_atts['uni']) ? $raw_atts['uni'] : (!empty($raw_atts['university']) ? $raw_atts['university'] : '');
        if (empty($explicit_uni)) {
            foreach ($raw_atts as $k => $v) {
                if (is_numeric($k) && is_string($v) && !empty($v)) {
                    if (strpos($v, '=') !== false) {
                        list($pk, $pv) = explode('=', $v, 2);
                        if (in_array(strtolower(trim($pk)), ['uni', 'university'])) {
                            $explicit_uni = trim($pv, " '\"\t\n\r\0\x0B");
                            break;
                        }
                    } else {
                        $explicit_uni = trim($v, " '\"\t\n\r\0\x0B");
                        break;
                    }
                }
            }
        }

        $atts = shortcode_atts([
            'uni' => $explicit_uni,
            'university' => $explicit_uni,
            'visible_rows' => UNI_FEES_TABLE_VISIBLE_ROWS,
            'limit' => '',
            'heading' => '',
            'description' => '',
            'class' => '',
        ], $raw_atts);

        $uni_slug = !empty($atts['uni']) ? $atts['uni'] : (!empty($atts['university']) ? $atts['university'] : $explicit_uni);
        $data = get_university_fees_table_data($uni_slug);

        if (empty($data) || empty($data['universities']) || empty($data['columns'])) {
            return '<p><em>University fee comparison data is currently updating. Please refresh shortly.</em></p>';
        }

        $columns = $data['columns'];
        $universities = $data['universities'];
        $total_rows = count($universities);

        $visible_rows = !empty($atts['limit']) ? (int) $atts['limit'] : (int) $atts['visible_rows'];
        if ($visible_rows <= 0)
            $visible_rows = 6;
        $has_more = $total_rows > $visible_rows;

        static $inst_count = 0;
        $inst_count++;
        $table_id = 'sode-uni-fees-table-' . $inst_count . '-' . substr(md5($current_uni_slug ?? 'dsu'), 0, 4);

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
                            <th class="sode-uni-th-compare sode-col-mobile-only">COMPARE</th>
                            <th class="sode-uni-th-name">UNIVERSITY NAME</th>
                            <?php foreach ($columns as $col): ?>
                                <th class="sode-uni-th-fee"><?php echo esc_html(strtoupper($col['header_text'])); ?></th>
                            <?php endforeach; ?>
                            <th class="sode-uni-th-compare sode-col-desktop-only">COMPARE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($universities as $idx => $uni):
                            if ($idx >= $visible_rows)
                                break;
                            $is_current = !empty($uni['is_current']);
                            ?>
                            <tr class="<?php echo $is_current ? 'sode-row-current-uni' : ''; ?>">
                                <?php sode_render_uni_fees_row_cells($uni, $columns, $primary_course); ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($has_more): ?>
                        <tbody class="sode-uni-fees-extra-rows" style="display:none;">
                            <?php foreach ($universities as $idx => $uni):
                                if ($idx < $visible_rows)
                                    continue;
                                $is_current = !empty($uni['is_current']);
                                ?>
                                <tr class="<?php echo $is_current ? 'sode-row-current-uni' : ''; ?>">
                                    <?php sode_render_uni_fees_row_cells($uni, $columns, $primary_course); ?>
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
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                stroke-linecap="round" stroke-linejoin="round">
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
                    margin: 0px;
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
                    overflow-x: visible;
                    overflow-y: visible;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    background: #ffffff;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
                }

                .sode-uni-fees-table {
                    width: 100%;
                    min-width: 0;
                    border-collapse: collapse !important;
                    font-size: 13px;
                    margin: 0;
                    table-layout: auto;
                }

                .sode-uni-fees-table thead th {
                    background-color: #e0f2fe !important;
                    color: #0c2340 !important;
                    text-align: left;
                    padding: 10px 10px;
                    font-weight: 700;
                    font-size: 11.5px;
                    text-transform: uppercase;
                    letter-spacing: 0.2px;
                    white-space: normal;
                    line-height: 1.25;
                    border-bottom: 1px solid #cbd5e1 !important;
                    border-right: 1px solid #cbd5e1 !important;
                }

                .sode-uni-fees-table thead th:last-child {
                    border-right: none !important;
                }

                .sode-uni-th-name {
                    width: auto;
                    max-width: 210px;
                }

                .sode-uni-th-fee {
                    text-align: left;
                }

                .sode-uni-th-compare {
                    width: 125px;
                    text-align: center !important;
                    white-space: nowrap;
                }

                .sode-uni-fees-table tbody td {
                    padding: 10px 10px;
                    border-bottom: 1px solid #e2e8f0 !important;
                    border-right: 1px solid #e2e8f0 !important;
                    vertical-align: middle;
                    color: #1f2937;
                    font-size: 12.5px;
                }

                .sode-uni-fees-table tbody td:last-child {
                    border-right: none !important;
                }

                .sode-col-mobile-only {
                    display: none !important;
                }

                .sode-col-desktop-only {
                    display: table-cell !important;
                }

                .sode-uni-fees-table tbody tr:hover {
                    background-color: #f8fafc;
                }

                /* First Row: Current Domain University */
                .sode-row-current-uni td {
                    background-color: #ffffff;
                }

                .sode-row-current-uni .sode-uni-td-name .sode-uni-name-text {
                    font-size: 13px;
                }

                .sode-uni-name-text {
                    font-weight: 700;
                    color: #0c2340;
                    display: inline-block;
                    line-height: 1.35;
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
                    padding: 6px 12px;
                    font-size: 11.5px;
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
                    from {
                        transform: translateX(-50%) translateY(120px);
                        opacity: 0;
                    }

                    to {
                        transform: translateX(-50%) translateY(0);
                        opacity: 1;
                    }
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

                    /* Mobile: Horizontal scroll active */
                    .sode-uni-fees-scroll {
                        overflow-x: auto !important;
                        overflow-y: hidden !important;
                        -webkit-overflow-scrolling: touch !important;
                    }

                    .sode-uni-fees-table {
                        min-width: 500px !important;
                        width: max-content !important;
                    }

                    /* Mobile: Compare column 1st, Hide desktop compare column */
                    .sode-col-mobile-only {
                        display: table-cell !important;
                    }

                    .sode-col-desktop-only {
                        display: none !important;
                    }

                    /* Slimmer column widths & paddings for mobile */
                    .sode-uni-fees-table thead th {
                        padding: 7px 6px !important;
                        font-size: 10px !important;
                        line-height: 1.25 !important;
                        letter-spacing: 0 !important;
                    }

                    .sode-uni-fees-table tbody td {
                        padding: 7px 6px !important;
                        font-size: 11px !important;
                    }

                    .sode-uni-th-compare,
                    .sode-uni-td-compare {
                        width: 78px !important;
                        min-width: 74px !important;
                        max-width: 82px !important;
                        padding: 5px 3px !important;
                    }

                    .sode-uni-th-name,
                    .sode-uni-td-name {
                        min-width: 110px !important;
                        max-width: 135px !important;
                        font-size: 11px !important;
                        line-height: 1.25 !important;
                    }

                    .sode-uni-th-fee,
                    .sode-uni-td-fee {
                        min-width: 68px !important;
                        font-size: 11px !important;
                    }

                    .uni-compare-toggle-btn {
                        padding: 4px 6px !important;
                        font-size: 10px !important;
                        gap: 3px !important;
                        border-radius: 12px !important;
                        white-space: nowrap !important;
                    }

                    .sode-row-current-uni .sode-uni-td-name .sode-uni-name-text {
                        font-size: 11.5px !important;
                    }

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

                    /* HIDE WHATSAPP & FLOATING WIDGETS ON MOBILE WHEN COMPARE DOCK IS OPEN */
                    body.has-uni-compare-dock-open #gb-waw-iframe,
                    body.has-uni-compare-dock-open [id*="gb-waw"],
                    body.has-uni-compare-dock-open [class*="gb-waw"],
                    body.has-uni-compare-dock-open [class*="whatsapp"],
                    body.has-uni-compare-dock-open [id*="whatsapp"],
                    body.has-uni-compare-dock-open [class*="joinchat"],
                    body.has-uni-compare-dock-open [id*="joinchat"],
                    body.has-uni-compare-dock-open [class*="ht-ctc"],
                    body.has-uni-compare-dock-open [id*="ht-ctc"],
                    body.has-uni-compare-dock-open [class*="chaty"],
                    body.has-uni-compare-dock-open [id*="chaty"],
                    body.has-uni-compare-dock-open [class*="qlwapp"],
                    body.has-uni-compare-dock-open [id*="qlwapp"],
                    body.has-uni-compare-dock-open [class*="get-help"],
                    body.has-uni-compare-dock-open [id*="get-help"] {
                        display: none !important;
                        visibility: hidden !important;
                        opacity: 0 !important;
                        pointer-events: none !important;
                    }
                }
            </style>

            <script>
                (function () {
                    var selectedUnis = []; // { name, slug, course }
                    var maxSelections = 3;
                    var toastTimer = null;
                    var isInitialSilent = true; // True only on page load until user interacts

                    // Auto-select current subdomain university on load
                    function initCurrentUniversity() {
                        var currentBtn = document.querySelector('.uni-compare-toggle-btn[data-is-current="1"]') || document.querySelector('.sode-row-current-uni .uni-compare-toggle-btn');
                        if (currentBtn) {
                            var slug = currentBtn.getAttribute('data-uni-slug');
                            var name = currentBtn.getAttribute('data-uni-name');
                            var course = currentBtn.getAttribute('data-course');
                            if (slug && !selectedUnis.some(function (item) { return item.slug === slug; })) {
                                selectedUnis.push({ name: name, slug: slug, course: course });
                            }
                        }
                    }

                    function showToast(message) {
                        var toast = document.getElementById('uni-compare-toast');
                        if (!toast) return;
                        toast.textContent = message;
                        toast.style.display = 'block';
                        if (toastTimer) clearTimeout(toastTimer);
                        toastTimer = setTimeout(function () {
                            toast.style.display = 'none';
                        }, 2800);
                    }

                    function setExternalWidgetVisibility(visible) {
                        var waEl = document.getElementById('gb-waw-iframe');
                        if (waEl) {
                            if (window.innerWidth <= 768) {
                                waEl.style.setProperty('display', visible ? '' : 'none', 'important');
                                waEl.style.setProperty('visibility', visible ? '' : 'hidden', 'important');
                            } else {
                                waEl.style.removeProperty('display');
                                waEl.style.removeProperty('visibility');
                            }
                        }
                    }

                    function updateUI() {
                        var dock = document.getElementById('uni-compare-dock');
                        var countBadge = document.getElementById('uni-compare-count-badge');
                        var chipsList = document.getElementById('uni-compare-chips-list');

                        // Update all compare buttons across the page
                        var allBtns = document.querySelectorAll('.uni-compare-toggle-btn');
                        allBtns.forEach(function (btn) {
                            var slug = btn.getAttribute('data-uni-slug');
                            var isSelected = selectedUnis.some(function (item) { return item.slug === slug; });
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
                                if (text) {
                                    text.textContent = btn.closest('.sode-col-mobile-only') ? 'Compare' : 'Add to Compare';
                                }
                            }
                        });

                        if (!dock || !countBadge || !chipsList) return;

                        // Dock stays hidden on initial load when only the auto-selected current university is present.
                        // As soon as user adds another university OR unselects current and selects any university, dock opens!
                        var shouldHideDock = (selectedUnis.length === 0) || (isInitialSilent && selectedUnis.length === 1);

                        if (shouldHideDock) {
                            document.body.classList.remove('has-uni-compare-dock-open');
                            setExternalWidgetVisibility(true);
                            dock.style.display = 'none';
                            return;
                        }

                        document.body.classList.add('has-uni-compare-dock-open');
                        setExternalWidgetVisibility(false);
                        dock.style.display = 'block';
                        countBadge.textContent = selectedUnis.length + '/' + maxSelections;

                        var html = '';
                        selectedUnis.forEach(function (item) {
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

                    window.addEventListener('resize', function () {
                        var shouldHideDock = (selectedUnis.length === 0) || (isInitialSilent && selectedUnis.length === 1);
                        if (!shouldHideDock) {
                            setExternalWidgetVisibility(false);
                        } else {
                            setExternalWidgetVisibility(true);
                        }
                    });

                    document.addEventListener('click', function (e) {
                        // 1. Toggle Button
                        var toggleBtn = e.target.closest('.uni-compare-toggle-btn');
                        if (toggleBtn) {
                            isInitialSilent = false; // User manually interacted
                            var slug = toggleBtn.getAttribute('data-uni-slug');
                            var name = toggleBtn.getAttribute('data-uni-name');
                            var course = toggleBtn.getAttribute('data-course');

                            var idx = selectedUnis.findIndex(function (item) { return item.slug === slug; });
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
                            isInitialSilent = false;
                            var removeSlug = removeBtn.getAttribute('data-slug');
                            selectedUnis = selectedUnis.filter(function (item) { return item.slug !== removeSlug; });
                            updateUI();
                            return;
                        }

                        // 3. Clear All Button
                        if (e.target.closest('#uni-compare-clear-btn')) {
                            isInitialSilent = false;
                            selectedUnis = [];
                            updateUI();
                            return;
                        }

                        // 4. Submit Button
                        if (e.target.closest('#uni-compare-submit-btn')) {
                            if (selectedUnis.length < 2) {
                                showToast('Please select at least 2 universities to compare.');
                                return;
                            }
                            var slugs = selectedUnis.map(function (item) { return item.slug; }).join(',');
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

                    // Initialize auto-selection for current subdomain university
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function () {
                            initCurrentUniversity();
                            updateUI();
                        });
                    } else {
                        initCurrentUniversity();
                        updateUI();
                    }
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
