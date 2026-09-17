<?php
/**
 * ==========================================================
 * UNIVERSITY PROGRAMMES TABLE (UGC-DEB APPROVED) - UNIVERSAL COMPONENT
 * File: university-programmes-table-universal.php
 * ==========================================================
 * Dynamically displays the university's offered programmes, level,
 * UGC-DEB approval status, and mode in a clean, modern responsive table.
 * 
 * Shortcode:
 *   [subdomain_programmes_table]
 *   [subdomain_programmes_table uni="chandigarh-university"]
 *   [subdomain_programmes_table uni="cu"]
 *   [university_programmes_table]
 *   [uni_programmes_table]
 *   [ugc_deb_approved_courses_table]
 * ==========================================================
 */

if (defined('UNIVERSITY_PROGRAMMES_TABLE_UNIVERSAL_LOADED')) {
    return;
}
define('UNIVERSITY_PROGRAMMES_TABLE_UNIVERSAL_LOADED', true);

// Fallback Polyfills for standalone / SSR execution
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
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        $title = strtolower(trim((string) $title));
        $title = preg_replace('/[^a-z0-9-]+/', '-', $title);
        return trim($title, '-');
    }
}

if (!defined('UNI_PROGRAMMES_TABLE_API_URL')) {
    define('UNI_PROGRAMMES_TABLE_API_URL', 'https://admin.distanceeducationschool.com/admin/api/get_university_programmes_table.php');
}

/**
 * Dynamic University Matcher (100% Dynamic, Zero Hardcoded Aliases)
 */
if (!function_exists('sode_find_matching_university')) {
    function sode_find_matching_university($unis, $search_term)
    {
        if (empty($search_term) || empty($unis)) {
            return null;
        }
        $term = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', (string) $search_term)));
        if ($term === '') {
            return null;
        }

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
                if ($sp !== '') {
                    $slug_ac .= $sp[0];
                }
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
 * Helper: Detect university slug from parameter, constants, client helper, or host
 */
if (!function_exists('sode_detect_programmes_uni_slug')) {
    function sode_detect_programmes_uni_slug($explicit = '')
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
            if ($u) {
                return sanitize_title($u);
            }
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
 * Fetch University Programmes Data
 */
if (!function_exists('get_university_programmes_table_data')) {
    function get_university_programmes_table_data($uni_slug = '')
    {
        static $cache = [];
        $uni_slug = sode_detect_programmes_uni_slug($uni_slug);

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
                    $all_active_stmt = $db->query("SELECT id, full_name, short_name, slug, mode, location, official_url FROM universities WHERE is_active = 1 ORDER BY id ASC");
                    $all_active_unis = $all_active_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $current_uni = sode_find_matching_university($all_active_unis, $uni_slug);
                    if (!$current_uni && !empty($all_active_unis)) {
                        $current_uni = $all_active_unis[0];
                    }

                    if ($current_uni) {
                        $c_stmt = $db->prepare("
                            SELECT c.id AS course_id, c.short_name, c.full_name, c.level, ucm.mode
                            FROM university_course_mappings ucm
                            JOIN courses c ON ucm.course_id = c.id
                            WHERE ucm.university_id = ?
                            ORDER BY ucm.id ASC
                        ");
                        $c_stmt->execute([$current_uni['id']]);
                        $courses = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

                        // Ranking: Undergraduate first, then Postgraduate, then others
                        $level_ranks = [
                            'ug' => 1,
                            'undergraduate' => 1,
                            'pg' => 2,
                            'postgraduate' => 2,
                            'diploma' => 3,
                            'certificate' => 4,
                            'doctorate' => 5,
                        ];

                        $course_sub_ranks = [
                            'bba' => 1,
                            'bca' => 2,
                            'bcom' => 3,
                            'b.com' => 3,
                            'ba' => 4,
                            'bsc' => 5,
                            'b.sc' => 5,
                            'mba' => 10,
                            'mca' => 11,
                            'mcom' => 12,
                            'm.com' => 12,
                            'ma' => 13,
                            'msc' => 14,
                            'm.sc' => 14,
                        ];

                        usort($courses, function ($a, $b) use ($level_ranks, $course_sub_ranks) {
                            $lvl_a = strtolower(trim($a['level'] ?? 'ug'));
                            $lvl_b = strtolower(trim($b['level'] ?? 'ug'));
                            $r_lvl_a = $level_ranks[$lvl_a] ?? 10;
                            $r_lvl_b = $level_ranks[$lvl_b] ?? 10;

                            if ($r_lvl_a !== $r_lvl_b) {
                                return ($r_lvl_a < $r_lvl_b) ? -1 : 1;
                            }

                            $k_a = strtolower(str_replace(['.', ' '], '', $a['short_name']));
                            $k_b = strtolower(str_replace(['.', ' '], '', $b['short_name']));
                            $r_a = $course_sub_ranks[$k_a] ?? 50;
                            $r_b = $course_sub_ranks[$k_b] ?? 50;

                            if ($r_a === $r_b) {
                                return ($a['course_id'] < $b['course_id']) ? -1 : 1;
                            }
                            return ($r_a < $r_b) ? -1 : 1;
                        });

                        $programmes = [];
                        $default_mode = !empty($current_uni['mode']) ? trim($current_uni['mode']) : 'Online';

                        foreach ($courses as $c) {
                            $course_mode = !empty($c['mode']) ? trim($c['mode']) : $default_mode;
                            $is_online = (stripos($course_mode, 'Online') !== false);

                            $raw_short = trim($c['short_name']);
                            $clean_short = str_ireplace(['B.Com', 'M.Com', 'B.Sc', 'M.Sc'], ['BCom', 'MCom', 'BSc', 'MSc'], $raw_short);

                            if ($is_online && stripos($clean_short, 'Online') === false) {
                                $prog_name = 'Online ' . $clean_short;
                            } elseif (stripos($clean_short, 'Online') === false && stripos($clean_short, 'Distance') === false && !empty($course_mode)) {
                                $prog_name = $course_mode . ' ' . $clean_short;
                            } else {
                                $prog_name = $clean_short;
                            }

                            $raw_lvl = strtoupper(trim($c['level'] ?? ''));
                            if ($raw_lvl === 'UG' || stripos($raw_lvl, 'UNDER') !== false) {
                                $level_name = 'Undergraduate';
                            } elseif ($raw_lvl === 'PG' || stripos($raw_lvl, 'POST') !== false || stripos($raw_lvl, 'MASTER') !== false) {
                                $level_name = 'Postgraduate';
                            } elseif (stripos($raw_lvl, 'DIP') !== false) {
                                $level_name = 'Diploma';
                            } elseif (stripos($raw_lvl, 'CERT') !== false) {
                                $level_name = 'Certificate';
                            } elseif (stripos($raw_lvl, 'DOC') !== false || stripos($raw_lvl, 'PHD') !== false) {
                                $level_name = 'Doctorate';
                            } else {
                                $level_name = !empty($c['level']) ? ucwords(strtolower($c['level'])) : 'Undergraduate';
                            }

                            $programmes[] = [
                                'course_id' => (int) $c['course_id'],
                                'short_name' => $raw_short,
                                'programme' => $prog_name,
                                'level' => $level_name,
                                'approved' => 'Yes',
                                'mode' => $course_mode,
                            ];
                        }

                        $data = [
                            'success' => true,
                            'university' => [
                                'id' => (int) $current_uni['id'],
                                'full_name' => $current_uni['full_name'],
                                'short_name' => $current_uni['short_name'],
                                'slug' => $current_uni['slug'],
                                'mode' => $default_mode,
                            ],
                            'programmes' => $programmes,
                        ];

                        $cache[$uni_slug] = $data;
                        return $data;
                    }
                }
            } catch (Exception $e) {
                // Fallback to central API
            }
        }

        // 2. Central API Fallback (for remote client subdomains)
        $api_url = UNI_PROGRAMMES_TABLE_API_URL . '?uni=' . urlencode($uni_slug);
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
            if (!empty($res['success']) && !empty($res['programmes'])) {
                $cache[$uni_slug] = $res;
                return $res;
            }
        }

        return false;
    }
}

/**
 * Universal Renderer for University Programmes Table
 */
if (!function_exists('sode_render_university_programmes_table')) {
    function sode_render_university_programmes_table($atts = [])
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
            'heading' => '',
            'mode' => '',
            'ugc' => 'Yes',
            'class' => '',
        ], $raw_atts);

        $uni_slug = !empty($atts['uni']) ? $atts['uni'] : (!empty($atts['university']) ? $atts['university'] : $explicit_uni);
        $data = get_university_programmes_table_data($uni_slug);

        if (empty($data) || empty($data['programmes'])) {
            return '<!-- No programmes mapped for this university -->';
        }

        $programmes = $data['programmes'];
        $override_mode = !empty($atts['mode']) ? trim($atts['mode']) : '';
        $override_ugc = !empty($atts['ugc']) ? trim($atts['ugc']) : 'Yes';
        $extra_class = !empty($atts['class']) ? ' ' . esc_attr($atts['class']) : '';

        ob_start();
        ?>
        <div class="sode-uni-programmes-table-wrap<?php echo $extra_class; ?>">
            <style>
                .sode-uni-programmes-table-wrap {
                    width: 100%;
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                .sode-uni-programmes-scroll-box {
                    width: 100%;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    background: #ffffff;
                }

                .sode-uni-programmes-table {
                    width: 100%;
                    min-width: 580px;
                    border-collapse: collapse;
                    border: 1px solid #e2e8f0;
                    text-align: left;
                    background: #ffffff;
                }

                .sode-uni-programmes-table thead th {
                    background-color: #eaf4fe;
                    color: #0f172a;
                    font-size: 14px;
                    font-weight: 700;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                    padding: 16px 22px;
                    border: 1px solid #e2e8f0;
                    white-space: nowrap;
                    vertical-align: middle;
                }

                .sode-uni-programmes-table tbody tr {
                    background-color: #ffffff;
                    transition: background-color 0.15s ease-in-out;
                }

                .sode-uni-programmes-table tbody tr:hover {
                    background-color: #f8fafc;
                }

                .sode-uni-programmes-table tbody td {
                    padding: 15px 22px;
                    font-size: 15px;
                    color: #1e293b;
                    border: 1px solid #e2e8f0;
                    vertical-align: middle;
                    line-height: 1.5;
                }

                .sode-uni-programmes-table tbody td.col-programme {
                    font-weight: 700;
                    color: #0f172a;
                }

                .sode-uni-programmes-table tbody td.col-level {
                    font-weight: 400;
                    color: #1e293b;
                }

                .sode-uni-programmes-table tbody td.col-approved {
                    font-weight: 400;
                    color: #1e293b;
                }

                .sode-uni-programmes-table tbody td.col-mode {
                    font-weight: 400;
                    color: #1e293b;
                }

                @media (max-width: 768px) {
                    .sode-uni-programmes-table thead th {
                        padding: 13px 15px;
                        font-size: 13px;
                    }

                    .sode-uni-programmes-table tbody td {
                        padding: 13px 15px;
                        font-size: 14px;
                    }
                }
            </style>

            <div class="sode-uni-programmes-scroll-box">
                <table class="sode-uni-programmes-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 32%;">PROGRAMME</th>
                            <th scope="col" style="width: 26%;">LEVEL</th>
                            <th scope="col" style="width: 24%;">UGC-DEB APPROVED</th>
                            <th scope="col" style="width: 18%;">MODE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programmes as $item):
                            $mode_val = $override_mode ? $override_mode : $item['mode'];
                            $ugc_val = $override_ugc ? $override_ugc : $item['approved'];
                            ?>
                            <tr>
                                <td class="col-programme"><?php echo esc_html($item['programme']); ?></td>
                                <td class="col-level"><?php echo esc_html($item['level']); ?></td>
                                <td class="col-approved"><?php echo esc_html($ugc_val); ?></td>
                                <td class="col-mode"><?php echo esc_html($mode_val); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Register Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('subdomain_programmes_table', 'sode_render_university_programmes_table');
    add_shortcode('university_programmes_table', 'sode_render_university_programmes_table');
    add_shortcode('uni_programmes_table', 'sode_render_university_programmes_table');
    add_shortcode('ugc_deb_approved_courses_table', 'sode_render_university_programmes_table');
    add_shortcode('university_ugc_courses_table', 'sode_render_university_programmes_table');
}
