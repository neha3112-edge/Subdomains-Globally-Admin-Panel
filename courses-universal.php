<?php
/**
 * ====================================================================
 * Universal Courses & Programs Component
 * File: courses-universal.php
 * 
 * Renders dynamic university mapped courses (Online Mode / Distance Mode),
 * adaptive responsive slider (>3 items on desktop, >=2 items on mobile),
 * and comma-separated course text list shortcodes.
 * 
 * Shortcodes:
 *  1. [university_courses] (Aliases: [uni_courses], [sode_courses], [university_programs])
 *  2. [university_courses_list] (Aliases: [uni_courses_list], [courses_list], [sode_courses_list])
 * ====================================================================
 */

if (defined('SODE_COURSES_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_COURSES_UNIVERSAL_LOADED', true);

// Safe polyfills for WP helpers (standalone / central SSR safe)
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
if (!function_exists('esc_js')) {
    function esc_js($text)
    {
        return addslashes((string) $text);
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
 * Helper: Fetch mapped courses for a given university slug or ID
 */
if (!function_exists('sode_get_university_courses_data')) {
    function sode_get_university_courses_data($uni_slug = '')
    {
        // Auto detect university slug if empty
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

        // Auto-include DB config & Redis if available locally
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

        // ── Check Redis Cache ─────────────────────────────────────────
        $cache_key = "courses_data:" . strtolower($uni_slug ?: 'default');
        if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
            $cached = Sode_Redis::get($cache_key);
            if (!empty($cached) && is_array($cached)) {
                return $cached;
            }
        }

        $courses_data = [];
        $uni_info = null;

        // 1. Direct Local DB Check
        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    if (!empty($uni_slug)) {
                        $stmt = $db->prepare("SELECT * FROM universities WHERE (LOWER(slug) = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?) AND is_active = 1 LIMIT 1");
                        $stmt->execute([$uni_slug, $uni_slug, $uni_slug]);
                        $uni_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    if (!$uni_info) {
                        $uni_info = $db->query("SELECT * FROM universities WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    }

                    if ($uni_info) {
                        $uni_id = (int) $uni_info['id'];
                        $stmt = $db->prepare("
                            SELECT 
                                ucm.id AS mapping_id,
                                ucm.university_id,
                                ucm.course_id,
                                ucm.mode,
                                ucm.course_description,
                                ucm.course_link,
                                ucm.eligibility_text,
                                ucm.per_semester_fee,
                                ucm.total_program_fee,
                                c.full_name AS course_name,
                                c.short_name AS course_short,
                                c.slug AS course_slug,
                                c.level,
                                c.description AS default_description,
                                (SELECT COUNT(*) FROM course_specializations WHERE mapping_id = ucm.id) AS specializations_count
                            FROM university_course_mappings ucm
                            INNER JOIN courses c ON ucm.course_id = c.id
                            WHERE ucm.university_id = ?
                            ORDER BY 
                                CASE 
                                    WHEN ucm.mode = 'Online' THEN 1 
                                    ELSE 2 
                                END ASC,
                                CASE 
                                    WHEN c.level = 'PG' THEN 1 
                                    WHEN c.level = 'UG' THEN 2 
                                    ELSE 3 
                                END ASC,
                                ucm.id ASC
                        ");
                        $stmt->execute([$uni_id]);
                        $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($mappings as $m) {
                            $course_mode = (!empty($m['mode']) && strtolower($m['mode']) === 'distance') ? 'Distance' : 'Online';
                            $level_raw = strtoupper(trim($m['level'] ?? 'UG'));
                            $tab_category = ($level_raw === 'PG' || stripos($m['course_name'], 'Master') !== false) ? 'Master' : 'Bachelor';
                            $duration = ($tab_category === 'Master') ? '2 Year' : '3 Year';

                            $desc = !empty($m['course_description']) ? trim($m['course_description']) : (!empty($m['default_description']) ? trim($m['default_description']) : '');
                            $elig = !empty($m['eligibility_text']) ? trim($m['eligibility_text']) : '';
                            if (empty($elig)) {
                                if ($tab_category === 'Master' || $level_raw === 'PG') {
                                    $elig = "Bachelor's degree in any discipline from a recognized university. Minimum 50% aggregate marks; 45% for SC/ST/OBC categories.";
                                } else {
                                    $elig = "10+2 or equivalent qualification from a recognized board. Minimum 45% aggregate marks; 40% for SC/ST/OBC categories.";
                                }
                            }

                            $link = !empty($m['course_link']) ? trim($m['course_link']) : '#';

                            $courses_data[] = [
                                'id' => (int) $m['mapping_id'],
                                'course_id' => (int) $m['course_id'],
                                'short_name' => $m['course_short'],
                                'full_name' => $m['course_name'],
                                'slug' => $m['course_slug'],
                                'mode' => $course_mode,
                                'level' => $m['level'],
                                'tab' => $tab_category,
                                'duration' => $duration,
                                'description' => $desc,
                                'eligibility' => $elig,
                                'link' => $link,
                                'per_sem_fee' => $m['per_semester_fee'],
                                'total_fee' => $m['total_program_fee'],
                                'specs_count' => (int) $m['specializations_count']
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        // 2. Fallback: Fetch via REST API if DB is remote
        if (empty($courses_data)) {
            $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? SODE_CENTRAL_ADMIN_URL : 'https://admin.distanceeducationschool.com';
            $api_url = rtrim($admin_url, '/') . '/api/get_courses.php?t=' . time();
            if ($uni_slug) {
                $api_url .= '&uni=' . urlencode($uni_slug);
            }

            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($api_url, ['timeout' => 6, 'headers' => ['Cache-Control' => 'no-cache']]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $json = json_decode(wp_remote_retrieve_body($resp), true);
                    if (!empty($json['courses']) && is_array($json['courses'])) {
                        $courses_data = $json['courses'];
                    }
                }
            } elseif (function_exists('curl_init')) {
                $ch = curl_init($api_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 6,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $body = curl_exec($ch);
                curl_close($ch);
                if ($body) {
                    $json = json_decode($body, true);
                    if (!empty($json['courses']) && is_array($json['courses'])) {
                        $courses_data = $json['courses'];
                    }
                }
            }
        }

        // Fallback default courses if empty
        if (empty($courses_data)) {
            $courses_data = [
                [
                    'id' => 1,
                    'course_id' => 1,
                    'short_name' => 'MBA',
                    'full_name' => 'Master of Business Administration',
                    'mode' => 'Online',
                    'tab' => 'Master',
                    'level' => 'PG',
                    'duration' => '2 Year',
                    'description' => 'Learners have access to higher knowledge of business and management that aligns with modern learners\' demands.',
                    'eligibility' => "Bachelor's degree in any discipline from a recognized university. Minimum 50% aggregate marks; 45% for SC/ST/OBC categories.",
                    'link' => '#'
                ],
                [
                    'id' => 2,
                    'course_id' => 2,
                    'short_name' => 'MCA',
                    'full_name' => 'Master of Computer Applications',
                    'mode' => 'Online',
                    'tab' => 'Master',
                    'level' => 'PG',
                    'duration' => '2 Year',
                    'description' => 'The Master of Computer Applications program offers learners advanced technical and computing skills that align with current IT industry trends.',
                    'eligibility' => "Bachelor's degree from a recognized university. Minimum 50% aggregate marks; 45% for SC/ST/OBC categories.",
                    'link' => '#'
                ],
                [
                    'id' => 3,
                    'course_id' => 10,
                    'short_name' => 'BCA',
                    'full_name' => 'Bachelor of Computer Applications',
                    'mode' => 'Online',
                    'tab' => 'Bachelor',
                    'level' => 'UG',
                    'duration' => '3 Year',
                    'description' => 'This program offers a structured curriculum in computer applications and technology.',
                    'eligibility' => "10+2 or equivalent qualification from a recognized board. Minimum 45% aggregate marks; 40% for SC/ST/OBC categories.",
                    'link' => '#'
                ],
                [
                    'id' => 4,
                    'course_id' => 9,
                    'short_name' => 'BBA',
                    'full_name' => 'Bachelor of Business Administration',
                    'mode' => 'Online',
                    'tab' => 'Bachelor',
                    'level' => 'UG',
                    'duration' => '3 Year',
                    'description' => 'BBA offers foundational knowledge of business administration and includes learning areas such as digital marketing and business analytics.',
                    'eligibility' => "10+2 or equivalent qualification from a recognized board. Minimum 45% aggregate marks; 40% for SC/ST/OBC categories.",
                    'link' => '#'
                ],
                [
                    'id' => 5,
                    'course_id' => 11,
                    'short_name' => 'BCom',
                    'full_name' => 'Bachelor of Commerce',
                    'mode' => 'Online',
                    'tab' => 'Bachelor',
                    'level' => 'UG',
                    'duration' => '3 Year',
                    'description' => 'This program provides access to basic knowledge of finance, accounting and emerging Business technologies.',
                    'eligibility' => "10+2 or equivalent qualification from a recognized board. Minimum 45% aggregate marks; 40% for SC/ST/OBC categories.",
                    'link' => '#'
                ]
            ];
        }

        if (!empty($courses_data) && class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
            Sode_Redis::set($cache_key, $courses_data, 86400);
        }

        return $courses_data;
    }
}

/**
 * 1. UNIVERSAL COURSES SECTION
 * Modes: Online Mode / Distance Mode
 * Slider: >=4 on Desktop, >=2 on Mobile
 * Shortcodes: [university_courses], [uni_courses], [sode_courses]
 */
if (!function_exists('sode_courses_tabs_render')) {
    function sode_courses_tabs_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni' => '',
            'btn_text' => 'Know More',
            'btn_action' => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $all_courses = sode_get_university_courses_data($uni_slug);

        // Group courses by Mode
        $online_courses = [];
        $distance_courses = [];

        foreach ($all_courses as $c) {
            $m = strtolower($c['mode'] ?? 'online');
            if ($m === 'distance') {
                $distance_courses[] = $c;
            } else {
                $online_courses[] = $c;
            }
        }

        $has_online = !empty($online_courses);
        $has_distance = !empty($distance_courses);
        $show_tabs = ($has_online && $has_distance);

        $on_count = count($online_courses);
        $dist_count = count($distance_courses);

        // Active mode & Tab priority: The mode with higher course count is active and displayed first!
        if ($dist_count > $on_count) {
            $active_mode = 'distance';
            $first_tab_mode = 'distance';
            $first_tab_label = 'Distance Mode';
            $second_tab_mode = 'online';
            $second_tab_label = 'Online Mode';
        } else {
            $active_mode = $has_online ? 'online' : ($has_distance ? 'distance' : 'online');
            $first_tab_mode = 'online';
            $first_tab_label = 'Online Mode';
            $second_tab_mode = 'distance';
            $second_tab_label = 'Distance Mode';
        }

        $unique_id = 'sode_courses_' . substr(md5($uni_slug ?? 'dsu'), 0, 8);

        ob_start();
        ?>
        <!-- SODE Universal Courses Mode-Based Section -->
        <div class="sode-courses-wrapper" id="<?php echo esc_attr($unique_id); ?>">

            <?php if ($show_tabs): ?>
                <!-- Mode Tabs Navigation (Rendered ONLY when BOTH Online & Distance courses exist) -->
                <div class="sode-courses-mode-tabs">
                    <button type="button" class="sode-mode-tab-btn <?php echo ($active_mode === $first_tab_mode) ? 'active' : ''; ?>"
                        data-mode="<?php echo esc_attr($first_tab_mode); ?>">
                        <?php echo esc_html($first_tab_label); ?>
                    </button>
                    <button type="button" class="sode-mode-tab-btn <?php echo ($active_mode === $second_tab_mode) ? 'active' : ''; ?>"
                        data-mode="<?php echo esc_attr($second_tab_mode); ?>">
                        <?php echo esc_html($second_tab_label); ?>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Panes Container -->
            <div class="sode-courses-panes-wrapper">

                <!-- 1. ONLINE COURSES PANE -->
                <?php if ($has_online):
                    $on_count = count($online_courses);
                    $on_is_slider_desktop = ($on_count > 3);
                    $on_is_slider_mobile = ($on_count >= 2);
                    $pane_class = 'sode-courses-pane ' . ($active_mode === 'online' ? 'active' : '') . ($on_is_slider_desktop ? ' has-slider-desktop' : ' is-static-desktop') . ($on_is_slider_mobile ? ' has-slider-mobile' : ' is-static-mobile') . ' count-' . $on_count;
                    ?>
                    <div class="<?php echo esc_attr($pane_class); ?>" id="<?php echo esc_attr($unique_id); ?>_pane_online"
                        data-pane-mode="online">
                        <div class="sode-slider-shell">
                            <?php if ($on_is_slider_desktop || $on_is_slider_mobile): ?>
                                <button type="button" class="sode-slider-nav-btn sode-slider-prev" aria-label="Previous Courses">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                </button>
                            <?php endif; ?>

                            <div class="sode-slider-viewport">
                                <div class="sode-slider-track">
                                    <?php foreach ($online_courses as $c):
                                        $c_mode = !empty($c['mode']) ? (strtolower(trim($c['mode'])) === 'distance' ? 'Distance' : 'Online') : 'Online';
                                        $display_short_name = trim($c['short_name'] ?? '');
                                        if (stripos($display_short_name, $c_mode) !== 0) {
                                            $display_short_name = $c_mode . ' ' . $display_short_name;
                                        }
                                        $raw_link = trim((string) ($c['link'] ?? ''));
                                        $has_custom_link = (!empty($raw_link) && $raw_link !== '#' && $raw_link !== '#custom_lead_form' && $raw_link !== 'javascript:void(0);');
                                        ?>
                                        <div class="sode-course-card-slide">
                                            <div class="sode-course-card">
                                                <div class="sode-course-card-inner">
                                                    <div class="sode-course-head">
                                                        <h3 class="sode-course-short-name"><?php echo esc_html($display_short_name); ?>
                                                        </h3>
                                                        <h4 class="sode-course-full-name"><?php echo esc_html($c['full_name']); ?></h4>
                                                    </div>

                                                    <div class="sode-course-body">
                                                        <p class="sode-course-desc"><?php echo esc_html($c['description']); ?></p>
                                                    </div>

                                                    <div class="sode-course-footer">
                                                        <div class="sode-course-meta">
                                                            <svg class="sode-course-cal-icon" viewBox="0 0 24 24" fill="none"
                                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                                stroke-linejoin="round">
                                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                                            </svg>
                                                            <span
                                                                class="sode-course-duration-text"><?php echo esc_html($c['duration']); ?></span>
                                                        </div>

                                                        <div class="sode-course-action">
                                                            <?php if (!empty($atts['btn_action']) && $atts['btn_action'] === 'counseling'): ?>
                                                                <button type="button" class="sode-course-btn open-counseling-modal-btn applynow"
                                                                    data-course="<?php echo esc_attr($display_short_name); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif (!empty($atts['btn_action']) && $atts['btn_action'] === 'brochure'): ?>
                                                                <button type="button" class="sode-course-btn open-brochure-modal-btn applynow"
                                                                    data-course="<?php echo esc_attr($display_short_name); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif ($has_custom_link): ?>
                                                                <a href="<?php echo esc_url($raw_link); ?>" class="sode-course-btn">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="#custom_lead_form" class="sode-course-btn applynow"
                                                                    data-course="<?php echo esc_attr($display_short_name); ?>"
                                                                    onclick="if(typeof openApplyModal==='function'){openApplyModal('<?php echo esc_js($display_short_name); ?>');return false;}if(document.querySelector('.sode-hero-form, #custom_lead_form')){document.querySelector('.sode-hero-form, #custom_lead_form').scrollIntoView({behavior:'smooth'});}">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if ($on_is_slider_desktop || $on_is_slider_mobile): ?>
                                <button type="button" class="sode-slider-nav-btn sode-slider-next" aria-label="Next Courses">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($on_is_slider_desktop || $on_is_slider_mobile): ?>
                            <div class="sode-slider-dots"></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>


                <!-- 2. DISTANCE COURSES PANE -->
                <?php if ($has_distance):
                    $dist_count = count($distance_courses);
                    $dist_is_slider_desktop = ($dist_count > 3);
                    $dist_is_slider_mobile = ($dist_count >= 2);
                    $pane_class = 'sode-courses-pane ' . ($active_mode === 'distance' ? 'active' : '') . ($dist_is_slider_desktop ? ' has-slider-desktop' : ' is-static-desktop') . ($dist_is_slider_mobile ? ' has-slider-mobile' : ' is-static-mobile') . ' count-' . $dist_count;
                    ?>
                    <div class="<?php echo esc_attr($pane_class); ?>" id="<?php echo esc_attr($unique_id); ?>_pane_distance"
                        data-pane-mode="distance">
                        <div class="sode-slider-shell">
                            <?php if ($dist_is_slider_desktop || $dist_is_slider_mobile): ?>
                                <button type="button" class="sode-slider-nav-btn sode-slider-prev" aria-label="Previous Courses">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                </button>
                            <?php endif; ?>

                            <div class="sode-slider-viewport">
                                <div class="sode-slider-track">
                                    <?php foreach ($distance_courses as $c):
                                        $c_mode = !empty($c['mode']) ? (strtolower(trim($c['mode'])) === 'distance' ? 'Distance' : 'Online') : 'Distance';
                                        $display_short_name = trim($c['short_name'] ?? '');
                                        if (stripos($display_short_name, $c_mode) !== 0) {
                                            $display_short_name = $c_mode . ' ' . $display_short_name;
                                        }
                                        $raw_link = trim((string) ($c['link'] ?? ''));
                                        $has_custom_link = (!empty($raw_link) && $raw_link !== '#' && $raw_link !== '#custom_lead_form' && $raw_link !== 'javascript:void(0);');
                                        ?>
                                        <div class="sode-course-card-slide">
                                            <div class="sode-course-card">
                                                <div class="sode-course-card-inner">
                                                    <div class="sode-course-head">
                                                        <h3 class="sode-course-short-name"><?php echo esc_html($display_short_name); ?>
                                                        </h3>
                                                        <h4 class="sode-course-full-name"><?php echo esc_html($c['full_name']); ?></h4>
                                                    </div>

                                                    <div class="sode-course-body">
                                                        <p class="sode-course-desc"><?php echo esc_html($c['description']); ?></p>
                                                    </div>

                                                    <div class="sode-course-footer">
                                                        <div class="sode-course-meta">
                                                            <svg class="sode-course-cal-icon" viewBox="0 0 24 24" fill="none"
                                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                                stroke-linejoin="round">
                                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                                            </svg>
                                                            <span
                                                                class="sode-course-duration-text"><?php echo esc_html($c['duration']); ?></span>
                                                        </div>

                                                        <div class="sode-course-action">
                                                            <?php if (!empty($atts['btn_action']) && $atts['btn_action'] === 'counseling'): ?>
                                                                <button type="button" class="sode-course-btn open-counseling-modal-btn applynow"
                                                                    data-course="<?php echo esc_attr($display_short_name); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif (!empty($atts['btn_action']) && $atts['btn_action'] === 'brochure'): ?>
                                                                <button type="button" class="sode-course-btn open-brochure-modal-btn applynow"
                                                                    data-course="<?php echo esc_attr($display_short_name); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif ($has_custom_link): ?>
                                                                <a href="<?php echo esc_url($raw_link); ?>" class="sode-course-btn">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="#custom_lead_form" class="sode-course-btn applynow"
                                                                    data-course="<?php echo esc_attr($display_short_name); ?>"
                                                                    onclick="if(typeof openApplyModal==='function'){openApplyModal('<?php echo esc_js($display_short_name); ?>');return false;}if(document.querySelector('.sode-hero-form, #custom_lead_form')){document.querySelector('.sode-hero-form, #custom_lead_form').scrollIntoView({behavior:'smooth'});}">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <?php if ($dist_is_slider_desktop || $dist_is_slider_mobile): ?>
                                <button type="button" class="sode-slider-nav-btn sode-slider-next" aria-label="Next Courses">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($dist_is_slider_desktop || $dist_is_slider_mobile): ?>
                            <div class="sode-slider-dots"></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <style>
            #<?php echo esc_attr($unique_id); ?>.sode-courses-wrapper {
                width: 100%;
                max-width: 1240px;
                margin: 0 auto;
                box-sizing: border-box;
                padding: 10px 0;
                position: relative;
            }

            /* Mode Tabs Navigation */
            #<?php echo esc_attr($unique_id); ?> .sode-courses-mode-tabs {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 14px;
                margin-bottom: 30px;
                flex-wrap: wrap;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-mode-tab-btn {
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #e2e8f0;
                padding: 10px 28px;
                font-size: 15.5px;
                font-weight: 700;
                border-radius: 50px;
                cursor: pointer;
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                outline: none;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }

            #<?php echo esc_attr($unique_id); ?> .sode-mode-tab-btn:hover {
                background: #e2e8f0;
                color: #0f172a;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-mode-tab-btn.active {
                background: #0b3b82;
                color: #ffffff;
                border-color: #0b3b82;
                box-shadow: 0 4px 14px rgba(11, 59, 130, 0.25);
            }

            /* Panes Visibility */
            #<?php echo esc_attr($unique_id); ?> .sode-courses-pane {
                display: none;
                width: 100%;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.active {
                display: block;
                animation: sodeCoursePaneFadeIn 0.35s ease;
            }

            /* Slider Shell & Alignment */
            #<?php echo esc_attr($unique_id); ?> .sode-slider-shell {
                position: relative;
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                box-sizing: border-box;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-slider-viewport {
                overflow: hidden;
                width: 100%;
                box-sizing: border-box;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-slider-track {
                display: flex;
                align-items: stretch;
                transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
                will-change: transform;
                width: 100%;
            }

            /* Course Card Base */
            #<?php echo esc_attr($unique_id); ?> .sode-course-card {
                background: #ffffff;
                border-radius: 14px;
                box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.06), 0 2px 6px -1px rgba(0, 0, 0, 0.02);
                border: 1px solid #edf2f7;
                transition: transform 0.25s ease, box-shadow 0.25s ease;
                display: flex;
                flex-direction: column;
                box-sizing: border-box;
                height: 100%;
                width: 100%;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 14px 34px -4px rgba(0, 0, 0, 0.12), 0 4px 10px -2px rgba(0, 0, 0, 0.04);
                border-color: #cbd5e1;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-card-inner {
                padding: 26px 24px 22px 24px;
                display: flex;
                flex-direction: column;
                flex: 1 1 auto;
                height: 100%;
                box-sizing: border-box;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-head {
                margin-bottom: 12px;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-short-name {
                font-size: 28px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 6px 0;
                line-height: 1.15;
                letter-spacing: -0.5px;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-full-name {
                font-size: 15.5px;
                font-weight: 500;
                color: #1e293b;
                margin: 0;
                line-height: 1.4;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-body {
                flex-grow: 1;
                margin-bottom: 18px;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-desc {
                font-size: 13.5px;
                line-height: 1.6;
                color: #475569;
                margin: 0;
                text-align: left;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-footer {
                margin-top: auto;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-meta {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 14px;
                color: #0f172a;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-cal-icon {
                width: 15px;
                height: 15px;
                stroke: #0f172a;
                flex-shrink: 0;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-duration-text {
                font-size: 13px;
                font-weight: 600;
                color: #0f172a;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-btn {
                display: block;
                width: 100%;
                background: #ffcc00;
                color: #111827;
                font-size: 14.5px;
                font-weight: 600;
                text-align: center;
                padding: 10px 18px;
                border-radius: 6px;
                text-decoration: none;
                border: none;
                cursor: pointer;
                box-sizing: border-box;
                transition: all 0.2s ease;
                box-shadow: 0 2px 6px rgba(245, 158, 11, 0.25);
            }

            #<?php echo esc_attr($unique_id); ?> .sode-course-btn:hover {
                transform: scale(1.015);
                box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4);
                color: #000000;
            }

            /* Slider Navigation Buttons */
            #<?php echo esc_attr($unique_id); ?> .sode-slider-nav-btn {
                position: absolute;
                top: 50%;
                transform: translateY(-50%);
                width: 44px;
                height: 44px;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 50%;
                color: #0b3b82;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                z-index: 10;
                box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
                transition: all 0.2s ease;
                outline: none;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-slider-nav-btn svg {
                width: 20px;
                height: 20px;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-slider-nav-btn:hover {
                background: #0b3b82;
                color: #ffffff;
                border-color: #0b3b82;
                box-shadow: 0 6px 18px rgba(11, 59, 130, 0.25);
            }

            #<?php echo esc_attr($unique_id); ?> .sode-slider-prev {
                left: -22px;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-slider-next {
                right: -22px;
            }

            /* Dots Pagination */
            #<?php echo esc_attr($unique_id); ?> .sode-slider-dots {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin-top: 20px;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-dot-btn {
                width: 9px;
                height: 9px;
                border-radius: 50%;
                background: #cbd5e1;
                border: none;
                cursor: pointer;
                padding: 0;
                transition: all 0.25s ease;
            }

            #<?php echo esc_attr($unique_id); ?> .sode-dot-btn.active {
                background: #0b3b82;
                width: 24px;
                border-radius: 12px;
            }

            /* =========================================
                                                   DESKTOP (> 1024px)
                                                   ========================================= */
            @media (min-width: 1025px) {
                #<?php echo esc_attr($unique_id); ?> .sode-slider-viewport {
                    padding: 10px 4px 14px 4px;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-slider-track {
                    gap: 24px;
                }

                /* Slider mode (> 3 courses) */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.has-slider-desktop .sode-course-card-slide {
                    box-sizing: border-box;
                    display: flex;
                    flex-direction: column;
                    flex: 0 0 calc((100% - 48px) / 3);
                    max-width: calc((100% - 48px) / 3);
                }

                /* 1 Course - Centered */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-1 .sode-slider-track {
                    display: flex !important;
                    justify-content: center !important;
                    align-items: stretch !important;
                    transform: none !important;
                    width: 100% !important;
                }
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-1 .sode-course-card-slide {
                    flex: 0 0 380px !important;
                    max-width: 380px !important;
                    width: 100% !important;
                }

                /* 2 Courses - Centered with Gap */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-2 .sode-slider-track {
                    display: flex !important;
                    justify-content: center !important;
                    align-items: stretch !important;
                    gap: 24px !important;
                    transform: none !important;
                    width: 100% !important;
                }
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-2 .sode-course-card-slide {
                    flex: 0 0 380px !important;
                    max-width: 380px !important;
                    width: 100% !important;
                }

                /* 3 Courses - Standard 3 Columns Grid */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-3 .sode-slider-track,
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop:not(.count-1):not(.count-2) .sode-slider-track {
                    display: grid !important;
                    grid-template-columns: repeat(3, 1fr) !important;
                    gap: 24px !important;
                    transform: none !important;
                    justify-content: center !important;
                    width: 100% !important;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-3 .sode-course-card-slide,
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop:not(.count-1):not(.count-2) .sode-course-card-slide {
                    flex: 1 1 auto !important;
                    max-width: 100% !important;
                }
            }

            /* =========================================
               TABLET (769px - 1024px)
               ========================================= */
            @media (min-width: 769px) and (max-width: 1024px) {
                #<?php echo esc_attr($unique_id); ?> .sode-slider-viewport {
                    padding: 10px 4px 14px 4px;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-slider-track {
                    gap: 20px;
                }

                /* Slider mode */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.has-slider-desktop .sode-course-card-slide {
                    box-sizing: border-box;
                    display: flex;
                    flex-direction: column;
                    flex: 0 0 calc((100% - 20px) / 2);
                    max-width: calc((100% - 20px) / 2);
                }

                /* 1 Course - Centered */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-1 .sode-slider-track {
                    display: flex !important;
                    justify-content: center !important;
                    align-items: stretch !important;
                    transform: none !important;
                    width: 100% !important;
                }
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-1 .sode-course-card-slide {
                    flex: 0 0 380px !important;
                    max-width: 380px !important;
                    width: 100% !important;
                }

                /* 2 or 3 Courses - Centered */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-2 .sode-slider-track,
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop:not(.count-1) .sode-slider-track {
                    display: flex !important;
                    justify-content: center !important;
                    flex-wrap: wrap !important;
                    gap: 20px !important;
                    transform: none !important;
                    width: 100% !important;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop.count-2 .sode-course-card-slide,
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop:not(.count-1) .sode-course-card-slide {
                    flex: 0 0 calc((100% - 20px) / 2) !important;
                    max-width: 380px !important;
                }
            }

            /* =========================================
                                                   MOBILE (<= 768px)
                                                   ========================================= */
            @media (max-width: 768px) {
                #<?php echo esc_attr($unique_id); ?> .sode-slider-shell {
                    padding: 0 20px;
                    box-sizing: border-box;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-slider-viewport {
                    padding: 10px 0 14px 0;
                    width: 100%;
                    overflow: hidden;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-slider-prev {
                    left: -6px;
                    width: 34px;
                    height: 34px;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-slider-next {
                    right: -6px;
                    width: 34px;
                    height: 34px;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-slider-nav-btn svg {
                    width: 16px;
                    height: 16px;
                }

                /* Mobile Slider Mode (>= 2 courses) */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.has-slider-mobile .sode-slider-track {
                    display: flex !important;
                    grid-template-columns: none !important;
                    gap: 0px !important;
                    width: 100% !important;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.has-slider-mobile .sode-course-card-slide {
                    flex: 0 0 100% !important;
                    width: 100% !important;
                    max-width: 100% !important;
                    min-width: 100% !important;
                    padding: 0 6px;
                    box-sizing: border-box;
                }

                /* Mobile Single Item (1 course) */
                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-mobile .sode-slider-shell {
                    padding: 0 !important;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-mobile .sode-slider-track {
                    display: block !important;
                    transform: none !important;
                    grid-template-columns: none !important;
                    width: 100% !important;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-mobile .sode-course-card-slide {
                    flex: 1 1 100% !important;
                    width: 100% !important;
                    max-width: 100% !important;
                    padding: 0 !important;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-course-card-inner {
                    padding: 22px 18px 18px 18px;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-course-short-name {
                    font-size: 26px;
                }

                #<?php echo esc_attr($unique_id); ?> .sode-mode-tab-btn {
                    padding: 8px 22px;
                    font-size: 14px;
                }
            }

            @keyframes sodeCoursePaneFadeIn {
                from {
                    opacity: 0;
                    transform: translateY(6px);
                }

                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>

        <script>
            (function () {
                var root = document.getElementById('<?php echo esc_js($unique_id); ?>');
                if (!root) return;

                var tabBtns = root.querySelectorAll('.sode-mode-tab-btn');
                var panes = root.querySelectorAll('.sode-courses-pane');
                var sliderControllers = {};

                // Universal Responsive Slider Controller for Panes
                function initSliderForPane(pane) {
                    if (!pane) return null;
                    var viewport = pane.querySelector('.sode-slider-viewport');
                    var track = pane.querySelector('.sode-slider-track');
                    var slides = pane.querySelectorAll('.sode-course-card-slide');
                    var prevBtn = pane.querySelector('.sode-slider-prev');
                    var nextBtn = pane.querySelector('.sode-slider-next');
                    var dotsWrap = pane.querySelector('.sode-slider-dots');

                    if (!viewport || !track || slides.length === 0) return null;

                    var totalSlides = slides.length;
                    var currentIndex = 0;
                    var autoplayTimer = null;
                    var isHovered = false;

                    function getVisibleCount() {
                        var w = window.innerWidth;
                        if (w <= 768) return 1;
                        if (w <= 1024) return 2;
                        return 3;
                    }

                    function shouldEnableSlider() {
                        var w = window.innerWidth;
                        if (w <= 768) {
                            return totalSlides >= 2;
                        }
                        return totalSlides > 3;
                    }

                    function updateSlider() {
                        var enabled = shouldEnableSlider();
                        var visible = getVisibleCount();
                        var maxIndex = Math.max(0, totalSlides - visible);

                        if (!enabled) {
                            track.style.transform = 'none';
                            if (prevBtn) prevBtn.style.display = 'none';
                            if (nextBtn) nextBtn.style.display = 'none';
                            if (dotsWrap) dotsWrap.style.display = 'none';
                            stopAutoplay();
                            return;
                        }

                        if (prevBtn) prevBtn.style.display = 'flex';
                        if (nextBtn) nextBtn.style.display = 'flex';
                        if (dotsWrap) dotsWrap.style.display = 'flex';

                        if (currentIndex > maxIndex) currentIndex = 0;
                        if (currentIndex < 0) currentIndex = maxIndex;

                        // Calculate translation accurately
                        var isMobile = (window.innerWidth <= 768);
                        var moveAmount = 0;

                        if (isMobile) {
                            var vWidth = viewport.clientWidth || viewport.getBoundingClientRect().width;
                            moveAmount = currentIndex * vWidth;
                        } else {
                            var firstSlide = slides[0];
                            var slideWidth = firstSlide ? firstSlide.getBoundingClientRect().width : 0;
                            var gap = (window.innerWidth <= 1024) ? 20 : 24;
                            moveAmount = currentIndex * (slideWidth + gap);
                        }

                        track.style.transform = 'translateX(-' + moveAmount + 'px)';

                        // Rebuild dots
                        if (dotsWrap) {
                            dotsWrap.innerHTML = '';
                            var totalPages = maxIndex + 1;
                            for (var i = 0; i < totalPages; i++) {
                                (function (idx) {
                                    var dot = document.createElement('button');
                                    dot.type = 'button';
                                    dot.className = 'sode-dot-btn' + (idx === currentIndex ? ' active' : '');
                                    dot.setAttribute('aria-label', 'Go to slide ' + (idx + 1));
                                    dot.addEventListener('click', function (e) {
                                        e.preventDefault();
                                        currentIndex = idx;
                                        updateSlider();
                                    });
                                    dotsWrap.appendChild(dot);
                                })(i);
                            }
                        }
                    }

                    function goNext() {
                        var visible = getVisibleCount();
                        var maxIndex = Math.max(0, totalSlides - visible);
                        if (currentIndex >= maxIndex) {
                            currentIndex = 0; // Loop back to start
                        } else {
                            currentIndex++;
                        }
                        updateSlider();
                    }

                    function goPrev() {
                        var visible = getVisibleCount();
                        var maxIndex = Math.max(0, totalSlides - visible);
                        if (currentIndex <= 0) {
                            currentIndex = maxIndex; // Loop to end
                        } else {
                            currentIndex--;
                        }
                        updateSlider();
                    }

                    function startAutoplay() {
                        stopAutoplay();
                        if (isHovered) return;
                        if (!shouldEnableSlider()) return;
                        autoplayTimer = setInterval(function () {
                            if (!isHovered) {
                                goNext();
                            }
                        }, 2000);
                    }

                    function stopAutoplay() {
                        if (autoplayTimer) {
                            clearInterval(autoplayTimer);
                            autoplayTimer = null;
                        }
                    }

                    if (prevBtn) {
                        prevBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            goPrev();
                        });
                    }

                    if (nextBtn) {
                        nextBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            goNext();
                        });
                    }

                    // Robust Hover Pause & Resume on entire pane, viewport and cards
                    pane.addEventListener('mouseenter', function () {
                        isHovered = true;
                        stopAutoplay();
                    });
                    pane.addEventListener('mouseleave', function () {
                        isHovered = false;
                        startAutoplay();
                    });

                    var interactiveEls = pane.querySelectorAll('.sode-course-card, .sode-slider-nav-btn, .sode-dot-btn, .sode-slider-viewport');
                    interactiveEls.forEach(function (el) {
                        el.addEventListener('mouseenter', function () {
                            isHovered = true;
                            stopAutoplay();
                        });
                        el.addEventListener('mouseleave', function () {
                            isHovered = false;
                            startAutoplay();
                        });
                    });

                    // Touch Swipe Support with Pause & Resume
                    var startX = 0;
                    var isDragging = false;
                    viewport.addEventListener('touchstart', function (e) {
                        isHovered = true;
                        stopAutoplay();
                        startX = e.touches[0].clientX;
                        isDragging = true;
                    }, { passive: true });

                    viewport.addEventListener('touchend', function (e) {
                        if (!isDragging) return;
                        isDragging = false;
                        var endX = e.changedTouches[0].clientX;
                        var diffX = startX - endX;
                        if (Math.abs(diffX) > 35) {
                            if (diffX > 0) {
                                goNext();
                            } else {
                                goPrev();
                            }
                        }
                        setTimeout(function () {
                            isHovered = false;
                            startAutoplay();
                        }, 1500);
                    }, { passive: true });

                    updateSlider();
                    startAutoplay();

                    return {
                        stopAutoplay: stopAutoplay,
                        startAutoplay: startAutoplay,
                        updateSlider: updateSlider
                    };
                }

                // Initialize all panes
                panes.forEach(function (p) {
                    var mode = p.getAttribute('data-pane-mode');
                    if (mode) {
                        sliderControllers[mode] = initSliderForPane(p);
                    }
                });

                // Tab Switching Event
                tabBtns.forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var targetMode = this.getAttribute('data-mode');
                        tabBtns.forEach(function (b) { b.classList.remove('active'); });
                        this.classList.add('active');

                        // Stop all autoplays across all panes
                        Object.keys(sliderControllers).forEach(function (k) {
                            if (sliderControllers[k] && typeof sliderControllers[k].stopAutoplay === 'function') {
                                sliderControllers[k].stopAutoplay();
                            }
                        });

                        panes.forEach(function (p) {
                            var mode = p.getAttribute('data-pane-mode');
                            if (mode === targetMode) {
                                p.classList.add('active');
                                // Delay slightly for display:block rendering
                                setTimeout(function () {
                                    if (sliderControllers[mode]) {
                                        sliderControllers[mode].updateSlider();
                                        sliderControllers[mode].startAutoplay();
                                    } else {
                                        sliderControllers[mode] = initSliderForPane(p);
                                    }
                                }, 40);
                            } else {
                                p.classList.remove('active');
                            }
                        });
                    });
                });

                window.addEventListener('resize', function () {
                    Object.keys(sliderControllers).forEach(function (k) {
                        if (sliderControllers[k] && typeof sliderControllers[k].updateSlider === 'function') {
                            sliderControllers[k].updateSlider();
                        }
                    });
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}

/**
 * 2. COMMA-SEPARATED COURSES TEXT LIST SHORTCODE
 * Shortcode: [university_courses_list], [uni_courses_list], [courses_list]
 * Output Example: "MBA, MCA, BCA, BBA, BCom" or "MBA, MCA, BCA, BBA, and BCom"
 */
if (!function_exists('sode_courses_list_render')) {
    function sode_courses_list_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni' => '',
            'mode' => 'all',       // 'all', 'online', 'distance'
            'format' => 'short',     // 'short' (e.g. MBA) or 'full' (e.g. Master of Business Administration)
            'level' => 'all',       // 'all', 'pg', 'ug', 'master', 'bachelor'
            'and' => 'false',     // 'true' or 'false' (adds "and" before last item)
            'separator' => ', ',        // delimiter between items
            'bold' => 'false',     // 'true' wraps each item in <strong>
            'link' => 'false',     // 'true' wraps each item in <a> link
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $courses = sode_get_university_courses_data($uni_slug);

        $mode_filter = strtolower($atts['mode']);
        $level_filter = strtolower($atts['level']);
        $filtered = [];

        foreach ($courses as $c) {
            $course_mode = strtolower($c['mode'] ?? 'online');
            if ($mode_filter === 'online' && $course_mode !== 'online')
                continue;
            if ($mode_filter === 'distance' && $course_mode !== 'distance')
                continue;

            $is_master = ($c['tab'] === 'Master' || strtoupper($c['level'] ?? '') === 'PG');
            if ($level_filter === 'master' || $level_filter === 'pg') {
                if (!$is_master)
                    continue;
            } elseif ($level_filter === 'bachelor' || $level_filter === 'ug') {
                if ($is_master)
                    continue;
            }

            $label = ($atts['format'] === 'full') ? $c['full_name'] : $c['short_name'];
            $label = esc_html($label);

            if ($atts['bold'] === 'true' || $atts['bold'] === '1') {
                $label = '<strong>' . $label . '</strong>';
            }

            if (($atts['link'] === 'true' || $atts['link'] === '1') && !empty($c['link']) && $c['link'] !== '#') {
                $label = '<a href="' . esc_url($c['link']) . '" target="_blank">' . $label . '</a>';
            }

            $filtered[] = $label;
        }

        $count = count($filtered);
        if ($count === 0) {
            return '';
        }

        $use_and = ($atts['and'] === 'true' || $atts['and'] === '1' || $atts['and'] === 'yes');

        if ($use_and && $count > 1) {
            $last = array_pop($filtered);
            return implode($atts['separator'], $filtered) . ', and ' . $last;
        }

        return implode($atts['separator'], $filtered);
    }
}

/**
 * 3. UNIVERSAL COURSES ELIGIBILITY TABLE COMPONENT
 * Renders modern responsive table with Course and Eligibility columns
 * Shortcodes: [university_eligibility_table], [uni_eligibility_table], [courses_eligibility_table], [university_eligibility], [uni_eligibility], [eligibility_table]
 */
if (!function_exists('sode_courses_eligibility_table_render')) {
    function sode_courses_eligibility_table_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni' => '',
            'mode' => 'all',      // 'all', 'online', 'distance'
            'format' => 'short',    // 'short' (e.g. BBA), 'full', 'both'
            'course_col' => 'COURSE',
            'eligibility_col' => 'ELIGIBILITY',
            'limit' => 10,
            'class' => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $all_courses = sode_get_university_courses_data($uni_slug);

        $mode_filter = strtolower(trim($atts['mode']));
        $filtered = [];
        $seen_courses = [];

        foreach ($all_courses as $c) {
            $m = strtolower($c['mode'] ?? 'online');
            if ($mode_filter === 'online' && $m !== 'online')
                continue;
            if ($mode_filter === 'distance' && $m !== 'distance')
                continue;

            $key = ($c['short_name'] ?? '') . '_' . $m;
            if (isset($seen_courses[$key]))
                continue;
            $seen_courses[$key] = true;

            $filtered[] = $c;
        }

        if (empty($filtered)) {
            return '';
        }

        $limit = isset($atts['limit']) ? (int)$atts['limit'] : 10;
        $total_count = count($filtered);
        $has_more = ($limit > 0 && $total_count > $limit);
        $more_count = $total_count - $limit;

        $table_id = 'sode_elig_tbl_' . substr(md5($uni_slug ?? 'dsu'), 0, 8);

        ob_start();
        ?>
        <div class="sode-eligibility-table-wrapper <?php echo esc_attr($atts['class']); ?>"
            id="<?php echo esc_attr($table_id); ?>">
            <table class="sode-eligibility-table">
                <thead>
                    <tr>
                        <th class="sode-elig-col-course"><?php echo esc_html($atts['course_col']); ?></th>
                        <th class="sode-elig-col-desc"><?php echo esc_html($atts['eligibility_col']); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filtered as $idx => $item):
                        $c_mode = !empty($item['mode']) ? (strtolower(trim($item['mode'])) === 'distance' ? 'Distance' : 'Online') : 'Online';
                        $base_name = ($item['short_name'] === 'B.Com') ? 'BCom' : $item['short_name'];
                        if ($atts['format'] === 'full') {
                            $base_name = $item['full_name'];
                        } elseif ($atts['format'] === 'both') {
                            $base_name = $base_name . ' (' . $item['full_name'] . ')';
                        }

                        if (stripos($base_name, $c_mode) !== 0) {
                            $c_name = $c_mode . ' ' . $base_name;
                        } else {
                            $c_name = $base_name;
                        }

                        $elig_text = !empty($item['eligibility']) ? $item['eligibility'] : '10+2 or equivalent qualification from a recognized board.';
                        $is_extra = ($limit > 0 && $idx >= $limit);
                        ?>
                        <tr class="<?php echo $is_extra ? 'sode-elig-row-extra' : ''; ?>">
                            <td class="sode-elig-cell-course">
                                <strong><?php echo esc_html($c_name); ?></strong>
                            </td>
                            <td class="sode-elig-cell-desc">
                                <?php echo esc_html($elig_text); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($has_more): ?>
                <div class="sode-elig-view-more-wrap">
                    <button type="button" class="sode-elig-view-more-btn" data-more-count="<?php echo esc_attr($more_count); ?>" onclick="sodeToggleEligRows('<?php echo esc_attr($table_id); ?>')">
                        <span class="sode-btn-text">View More Courses (<?php echo $more_count; ?> More) &darr;</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <script>
            if (typeof window.sodeToggleEligRows === 'undefined') {
                window.sodeToggleEligRows = function(tableId) {
                    var wrapper = document.getElementById(tableId);
                    if (!wrapper) return;
                    var btn = wrapper.querySelector('.sode-elig-view-more-btn');
                    var btnText = btn ? btn.querySelector('.sode-btn-text') : null;
                    var extraRows = wrapper.querySelectorAll('.sode-elig-row-extra');
                    var isExpanded = wrapper.classList.contains('is-expanded');
                    
                    if (isExpanded) {
                        extraRows.forEach(function(row) {
                            row.style.display = 'none';
                        });
                        wrapper.classList.remove('is-expanded');
                        if (btnText && btn) {
                            var moreCount = btn.getAttribute('data-more-count') || '';
                            btnText.innerHTML = 'View More Courses (' + moreCount + ' More) &darr;';
                        }
                    } else {
                        extraRows.forEach(function(row) {
                            row.style.display = 'table-row';
                        });
                        wrapper.classList.add('is-expanded');
                        if (btnText) {
                            btnText.innerHTML = 'View Less Courses &uarr;';
                        }
                    }
                };
            }
        </script>

        <style>
            #<?php echo esc_attr($table_id); ?>.sode-eligibility-table-wrapper {
                width: 100%;
                max-width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                background: #ffffff;
                border-radius: 4px;
                box-sizing: border-box;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table {
                width: 100%;
                border-collapse: collapse;
                border-spacing: 0;
                text-align: left;
                background: #ffffff;
                border: none;
                margin: 0px;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table thead tr {
                background-color: #e0f2fe;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table th {
                padding: 14px 20px;
                font-size: 15px;
                font-weight: 700;
                color: #000000;
                letter-spacing: 0.3px;
                text-transform: uppercase;
                border: none;
                vertical-align: middle;
                line-height: 1.3;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-col-course {
                width: 25%;
                min-width: 140px;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-col-desc {
                width: 75%;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table tbody tr {
                border-bottom: 1px solid #e2e8f0;
                transition: background-color 0.15s ease;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table tbody tr:last-child {
                border-bottom: 1px solid #e2e8f0;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table tbody tr:hover {
                background-color: #f8fafc;
            }

            #<?php echo esc_attr($table_id); ?> .sode-eligibility-table tbody tr.sode-elig-row-extra {
                display: none;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-cell-course {
                padding: 14px 20px;
                font-size: 15.5px;
                font-weight: 600;
                color: #000000;
                vertical-align: middle;
                white-space: nowrap;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-cell-course strong {
                font-weight: 700;
                color: #000000;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-cell-desc {
                padding: 14px 20px;
                font-size: 14px;
                line-height: 1.6;
                color: #1e293b;
                font-weight: 400;
                vertical-align: middle;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-view-more-wrap {
                text-align: center;
                padding: 16px 12px 12px 12px;
                background: #ffffff;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-view-more-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                background: #f0f9ff;
                color: #0284c7;
                font-size: 14.5px;
                font-weight: 700;
                padding: 10px 28px;
                border: 1.5px solid #bae6fd;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s ease-in-out;
                box-shadow: 0 2px 6px rgba(2, 132, 199, 0.08);
                font-family: inherit;
            }

            #<?php echo esc_attr($table_id); ?> .sode-elig-view-more-btn:hover {
                background: #0284c7;
                color: #ffffff;
                border-color: #0284c7;
                box-shadow: 0 4px 14px rgba(2, 132, 199, 0.25);
                transform: translateY(-1px);
            }

            @media (max-width: 768px) {
                #<?php echo esc_attr($table_id); ?> .sode-eligibility-table th {
                    padding: 12px 14px;
                    font-size: 13.5px;
                }

                #<?php echo esc_attr($table_id); ?> .sode-elig-col-course {
                    width: 30%;
                    min-width: 110px;
                }

                #<?php echo esc_attr($table_id); ?> .sode-elig-cell-course {
                    padding: 12px 14px;
                    font-size: 14px;
                    white-space: normal;
                }

                #<?php echo esc_attr($table_id); ?> .sode-elig-cell-desc {
                    padding: 12px 14px;
                    font-size: 13px;
                    line-height: 1.5;
                }

                #<?php echo esc_attr($table_id); ?> .sode-elig-view-more-btn {
                    width: 100%;
                    padding: 10px 16px;
                    font-size: 13.5px;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

/**
 * ====================================================================
 * Universal University Courses Eligibility Text Component
 * Renders course eligibility requirements in clean text format
 * (bullet list, paragraphs, inline text, or single course text)
 * ====================================================================
 */
if (!function_exists('sode_courses_eligibility_text_render')) {
    function sode_courses_eligibility_text_render($atts = [])
    {
        $atts = shortcode_atts([
            'university'  => '',
            'uni'         => '',
            'course'      => '',                 // single course filter e.g. 'mba', 'mca', 'bba'
            'mode'        => 'all',              // 'all', 'online', 'distance'
            'layout'      => 'list',             // 'list', 'p', 'paragraph', 'inline', 'plain', 'raw'
            'format'      => 'short',            // 'short' (e.g. MBA), 'full' (Master of Business Administration)
            'show_course' => '',                // 'true', 'false' (defaults to false if course is set, true otherwise)
            'bold'        => 'true',             // bold course name prefix
            'bullet'      => '',                 // custom bullet symbol e.g. '• ', '✓ '
            'separator'   => '<br>',             // for inline layout
            'class'       => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $all_courses = sode_get_university_courses_data($uni_slug);

        $mode_filter = strtolower(trim($atts['mode']));
        $course_filter = strtolower(trim($atts['course']));
        $filtered = [];
        $seen_courses = [];

        foreach ($all_courses as $c) {
            $m = strtolower($c['mode'] ?? 'online');
            if ($mode_filter === 'online' && $m !== 'online')
                continue;
            if ($mode_filter === 'distance' && $m !== 'distance')
                continue;

            // Single course filter
            if (!empty($course_filter)) {
                $s_clean = strtolower(preg_replace('/[^a-z0-9]/', '', $c['short_name'] ?? ''));
                $slug_clean = strtolower(preg_replace('/[^a-z0-9]/', '', $c['slug'] ?? ''));
                $target_clean = strtolower(preg_replace('/[^a-z0-9]/', '', $course_filter));
                if ($s_clean !== $target_clean && $slug_clean !== $target_clean) {
                    continue;
                }
            }

            $key = ($c['short_name'] ?? '') . '_' . $m;
            if (isset($seen_courses[$key]))
                continue;
            $seen_courses[$key] = true;

            $filtered[] = $c;
        }

        if (empty($filtered)) {
            return '';
        }

        // Determine if show_course should be on or off
        $show_course = $atts['show_course'];
        if ($show_course === '') {
            $show_course = empty($course_filter) ? 'true' : 'false';
        }
        $is_show_course = ($show_course === 'true' || $show_course === '1' || $show_course === 'yes');
        $is_bold = ($atts['bold'] === 'true' || $atts['bold'] === '1' || $atts['bold'] === 'yes');

        // Single course raw/plain request (e.g. [course_eligibility course="mba"] or [mba_eligibility])
        if (!empty($course_filter) && count($filtered) === 1 && !$is_show_course && ($atts['layout'] === 'raw' || $atts['layout'] === 'plain' || empty($atts['layout']) || $atts['layout'] === 'list')) {
            $first = reset($filtered);
            $elig = !empty($first['eligibility']) ? $first['eligibility'] : '10+2 or equivalent qualification from a recognized board.';
            return esc_html($elig);
        }

        $layout = strtolower(trim($atts['layout']));
        $output_items = [];

        foreach ($filtered as $item) {
            $c_mode = !empty($item['mode']) ? (strtolower(trim($item['mode'])) === 'distance' ? 'Distance' : 'Online') : 'Online';
            $base_name = ($atts['format'] === 'full') ? $item['full_name'] : $item['short_name'];
            if ($base_name === 'B.Com') $base_name = 'BCom';

            if (stripos($base_name, $c_mode) !== 0) {
                $c_name = $c_mode . ' ' . $base_name;
            } else {
                $c_name = $base_name;
            }

            $elig = !empty($item['eligibility']) ? $item['eligibility'] : '10+2 or equivalent qualification from a recognized board.';

            $prefix = '';
            if ($is_show_course) {
                $prefix = $is_bold ? '<strong>' . esc_html($c_name) . ':</strong> ' : esc_html($c_name) . ': ';
            }

            $bullet = !empty($atts['bullet']) ? esc_html($atts['bullet']) . ' ' : '';
            $text = $prefix . esc_html($elig);

            $output_items[] = [
                'name' => $c_name,
                'prefix' => $prefix,
                'eligibility' => esc_html($elig),
                'full' => $bullet . $text,
            ];
        }

        $wrap_class = esc_attr(trim('sode-eligibility-text ' . $atts['class']));

        // Layout: Paragraphs
        if ($layout === 'p' || $layout === 'paragraph' || $layout === 'paragraphs') {
            $html = '<div class="' . $wrap_class . ' sode-elig-layout-p">';
            foreach ($output_items as $oi) {
                $html .= '<p class="sode-elig-item" style="margin: 0 0 10px 0; font-size: 14px; line-height: 1.6; color: #374151;">' . $oi['full'] . '</p>';
            }
            $html .= '</div>';
            return $html;
        }

        // Layout: Inline (with <br> or separator)
        if ($layout === 'inline' || $layout === 'plain' || $layout === 'raw') {
            $lines = array_map(function($oi) { return $oi['full']; }, $output_items);
            $sep = !empty($atts['separator']) ? $atts['separator'] : '<br>';
            return '<div class="' . $wrap_class . ' sode-elig-layout-inline" style="font-size: 14px; line-height: 1.6; color: #374151;">' . implode($sep, $lines) . '</div>';
        }

        // Default Layout: Clean Bullet List (<ul><li>)
        $html = '<ul class="' . $wrap_class . ' sode-elig-layout-list" style="margin: 12px 0; padding-left: 20px; line-height: 1.7; font-size: 14px; color: #374151; list-style-type: disc;">';
        foreach ($output_items as $oi) {
            $html .= '<li class="sode-elig-item" style="margin-bottom: 8px;">' . $oi['full'] . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
}

/**
 * ====================================================================
 * Universal University Programs & Fee Table Component
 * Renders the 4-column dynamic program table:
 * PROGRAM | DURATION | FEE / 1ST SEMESTER | MORE INFORMATION
 * ====================================================================
 */
if (!function_exists('sode_university_programs_table_render')) {
    function sode_university_programs_table_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni' => '',
            'mode' => 'all',            // 'all', 'online', 'distance'
            'program_col' => 'PROGRAM',
            'duration_col' => 'DURATION',
            'fee_col' => 'FEE / 1ST SEMESTER',
            'more_info_col' => 'MORE INFORMATION',
            'btn_text' => 'View Details',
            'currency' => 'INR',
            'class' => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $all_courses = sode_get_university_courses_data($uni_slug);

        $mode_filter = strtolower(trim($atts['mode']));
        $filtered = [];
        $seen_courses = [];

        foreach ($all_courses as $c) {
            $m = strtolower($c['mode'] ?? 'online');
            if ($mode_filter === 'online' && $m !== 'online')
                continue;
            if ($mode_filter === 'distance' && $m !== 'distance')
                continue;

            $key = ($c['short_name'] ?? '') . '_' . $m;
            if (isset($seen_courses[$key]))
                continue;
            $seen_courses[$key] = true;

            $filtered[] = $c;
        }

        if (empty($filtered)) {
            return '';
        }

        $table_id = 'sode_prog_tbl_' . substr(md5($uni_slug ?? 'dsu'), 0, 8);

        ob_start();
        ?>
        <div class="sode-programs-table-wrapper <?php echo esc_attr($atts['class']); ?>"
            id="<?php echo esc_attr($table_id); ?>">
            <div class="sode-programs-table-scroll">
                <table class="sode-programs-table">
                    <thead>
                        <tr>
                            <th class="sode-prog-col-prog"><?php echo esc_html($atts['program_col']); ?></th>
                            <th class="sode-prog-col-dur"><?php echo esc_html($atts['duration_col']); ?></th>
                            <th class="sode-prog-col-fee"><?php echo esc_html($atts['fee_col']); ?></th>
                            <th class="sode-prog-col-info"><?php echo esc_html($atts['more_info_col']); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filtered as $item):
                            $c_mode = !empty($item['mode']) ? (strtolower(trim($item['mode'])) === 'distance' ? 'Distance' : 'Online') : 'Online';
                            // Program Name
                            $short_clean = $item['short_name'];
                            if ($short_clean === 'B.Com') {
                                $short_clean = 'BCom';
                            } elseif ($short_clean === 'M.Com') {
                                $short_clean = 'MCom';
                            } elseif ($short_clean === 'B.Sc') {
                                $short_clean = 'BSc';
                            } elseif ($short_clean === 'M.Sc') {
                                $short_clean = 'MSc';
                            }

                            if (stripos($short_clean, $c_mode) !== 0) {
                                $short_clean = $c_mode . ' ' . $short_clean;
                            }

                            // Duration (2 Years for PG / Master, 3 Years for UG / Bachelor)
                            $duration = !empty($item['duration']) ? $item['duration'] : '2 Years';
                            if (stripos($duration, 'year') !== false && stripos($duration, 'years') === false) {
                                $duration = str_ireplace('year', 'Years', $duration);
                            }

                            // Fee / 1st Semester
                            $raw_fee = !empty($item['per_sem_fee']) ? $item['per_sem_fee'] : (!empty($item['total_fee']) ? $item['total_fee'] : '');
                            $fee_disp = '-';
                            if ($raw_fee !== '') {
                                $fee_num = preg_replace('/^(INR|RS\.?|₹|\$)\s*/i', '', trim($raw_fee));
                                $fee_disp = trim($atts['currency']) . ' ' . $fee_num;
                            }

                            // Course Link
                            $course_url = !empty($item['link']) && $item['link'] !== '#' ? $item['link'] : '/' . ltrim($item['slug'], '/') . '/';
                            ?>
                            <tr>
                                <td class="sode-prog-cell-prog">
                                    <strong><?php echo esc_html($short_clean); ?></strong>
                                </td>
                                <td class="sode-prog-cell-dur">
                                    <?php echo esc_html($duration); ?>
                                </td>
                                <td class="sode-prog-cell-fee">
                                    <?php echo esc_html($fee_disp); ?>
                                </td>
                                <td class="sode-prog-cell-info">
                                    <a href="<?php echo esc_url($course_url); ?>" class="sode-prog-details-link">
                                        <?php echo esc_html($atts['btn_text']); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <style>
            #<?php echo esc_attr($table_id); ?>.sode-programs-table-wrapper {
                width: 100%;
                margin: 0px;
                box-sizing: border-box;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table-scroll {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #d8e5ee;
                border-radius: 4px;
                background: #ffffff;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table {
                width: 100%;
                border-collapse: collapse;
                border-spacing: 0;
                table-layout: auto;
                margin: 0;
                background: #ffffff;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table thead th {
                background-color: #eaf4fc;
                color: #0b1a2d;
                font-weight: 700;
                font-size: 13.5px;
                text-align: left;
                padding: 13px 18px;
                border-bottom: 1px solid #d8e5ee;
                border-right: 1px solid #d8e5ee;
                letter-spacing: 0.4px;
                white-space: nowrap;
                vertical-align: middle;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table thead th:last-child {
                border-right: none;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table tbody tr {
                background: #ffffff;
                transition: background 0.15s ease-in-out;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table tbody tr:hover {
                background: #f8fbff;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table tbody td {
                padding: 13px 18px;
                border-bottom: 1px solid #e8eef3;
                border-right: 1px solid #e8eef3;
                font-size: 14px;
                color: #1f2937;
                vertical-align: middle;
                line-height: 1.4;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table tbody tr:last-child td {
                border-bottom: none;
            }

            #<?php echo esc_attr($table_id); ?> .sode-programs-table tbody td:last-child {
                border-right: none;
            }

            #<?php echo esc_attr($table_id); ?> .sode-prog-cell-prog strong {
                font-weight: 700;
                color: #000000;
                font-size: 14px;
            }

            #<?php echo esc_attr($table_id); ?> .sode-prog-cell-dur {
                color: #374151;
                font-weight: 400;
            }

            #<?php echo esc_attr($table_id); ?> .sode-prog-cell-fee {
                color: #374151;
                font-weight: 400;
                white-space: nowrap;
            }

            #<?php echo esc_attr($table_id); ?> .sode-prog-details-link {
                color: #0284c7;
                font-size: 14px;
                font-weight: 400;
                text-decoration: none;
                transition: color 0.15s ease, text-decoration 0.15s ease;
                display: inline-block;
            }

            #<?php echo esc_attr($table_id); ?> .sode-prog-details-link:hover {
                text-decoration: underline !important;
            }

            @media (max-width: 768px) {
                #<?php echo esc_attr($table_id); ?> .sode-programs-table thead th {
                    padding: 11px 14px;
                    font-size: 12.5px;
                }

                #<?php echo esc_attr($table_id); ?> .sode-programs-table tbody td {
                    padding: 11px 14px;
                    font-size: 13.5px;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Register Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('university_courses', 'sode_courses_tabs_render');
    add_shortcode('uni_courses', 'sode_courses_tabs_render');
    add_shortcode('sode_courses', 'sode_courses_tabs_render');
    add_shortcode('university_programs', 'sode_courses_tabs_render');

    add_shortcode('university_courses_list', 'sode_courses_list_render');
    add_shortcode('uni_courses_list', 'sode_courses_list_render');
    add_shortcode('courses_list', 'sode_courses_list_render');
    add_shortcode('sode_courses_list', 'sode_courses_list_render');
    add_shortcode('uni_courses_text', 'sode_courses_list_render');

    add_shortcode('university_eligibility_table', 'sode_courses_eligibility_table_render');
    add_shortcode('uni_eligibility_table', 'sode_courses_eligibility_table_render');
    add_shortcode('courses_eligibility_table', 'sode_courses_eligibility_table_render');
    add_shortcode('university_eligibility', 'sode_courses_eligibility_table_render');
    add_shortcode('uni_eligibility', 'sode_courses_eligibility_table_render');
    add_shortcode('eligibility_table', 'sode_courses_eligibility_table_render');

    // Eligibility Text Shortcodes (Text Format)
    add_shortcode('university_eligibility_text', 'sode_courses_eligibility_text_render');
    add_shortcode('uni_eligibility_text', 'sode_courses_eligibility_text_render');
    add_shortcode('courses_eligibility_text', 'sode_courses_eligibility_text_render');
    add_shortcode('eligibility_text', 'sode_courses_eligibility_text_render');
    add_shortcode('course_eligibility', 'sode_courses_eligibility_text_render');
    add_shortcode('course_eligibility_text', 'sode_courses_eligibility_text_render');

    // Single course shortcuts
    add_shortcode('mba_eligibility', function ($atts) { return sode_courses_eligibility_text_render(array_merge((array)$atts, ['course' => 'mba'])); });
    add_shortcode('mca_eligibility', function ($atts) { return sode_courses_eligibility_text_render(array_merge((array)$atts, ['course' => 'mca'])); });
    add_shortcode('bba_eligibility', function ($atts) { return sode_courses_eligibility_text_render(array_merge((array)$atts, ['course' => 'bba'])); });
    add_shortcode('bca_eligibility', function ($atts) { return sode_courses_eligibility_text_render(array_merge((array)$atts, ['course' => 'bca'])); });
    add_shortcode('bcom_eligibility', function ($atts) { return sode_courses_eligibility_text_render(array_merge((array)$atts, ['course' => 'bcom'])); });

    // Programs / Fee Table Shortcodes
    add_shortcode('university_programs_table', 'sode_university_programs_table_render');
    add_shortcode('uni_programs_table', 'sode_university_programs_table_render');
    add_shortcode('programs_table', 'sode_university_programs_table_render');
    add_shortcode('university_fees_table', 'sode_university_programs_table_render');
    add_shortcode('uni_fees_table', 'sode_university_programs_table_render');
    add_shortcode('programs_fee_table', 'sode_university_programs_table_render');
    add_shortcode('university_courses_table', 'sode_university_programs_table_render');
    add_shortcode('uni_courses_table', 'sode_university_programs_table_render');
}

