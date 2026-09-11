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

        // Auto-include DB config if available locally
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
                            $link = !empty($m['course_link']) ? trim($m['course_link']) : '#';

                            $courses_data[] = [
                                'id'          => (int) $m['mapping_id'],
                                'course_id'   => (int) $m['course_id'],
                                'short_name'  => $m['course_short'],
                                'full_name'   => $m['course_name'],
                                'slug'        => $m['course_slug'],
                                'mode'        => $course_mode,
                                'level'       => $m['level'],
                                'tab'         => $tab_category,
                                'duration'    => $duration,
                                'description' => $desc,
                                'link'        => $link,
                                'per_sem_fee' => $m['per_semester_fee'],
                                'total_fee'   => $m['total_program_fee'],
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
                    CURLOPT_TIMEOUT        => 6,
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
                    'id' => 1, 'course_id' => 1, 'short_name' => 'MBA', 'full_name' => 'Master of Business Administration',
                    'mode' => 'Online', 'tab' => 'Master', 'level' => 'PG', 'duration' => '2 Year',
                    'description' => 'Learners have access to higher knowledge of business and management that aligns with modern learners\' demands.',
                    'link' => '#'
                ],
                [
                    'id' => 2, 'course_id' => 2, 'short_name' => 'MCA', 'full_name' => 'Master of Computer Applications',
                    'mode' => 'Online', 'tab' => 'Master', 'level' => 'PG', 'duration' => '2 Year',
                    'description' => 'The Master of Computer Applications program offers learners advanced technical and computing skills that align with current IT industry trends.',
                    'link' => '#'
                ],
                [
                    'id' => 3, 'course_id' => 10, 'short_name' => 'BCA', 'full_name' => 'Bachelor of Computer Applications',
                    'mode' => 'Online', 'tab' => 'Bachelor', 'level' => 'UG', 'duration' => '3 Year',
                    'description' => 'This program offers a structured curriculum in computer applications and technology.',
                    'link' => '#'
                ],
                [
                    'id' => 4, 'course_id' => 9, 'short_name' => 'BBA', 'full_name' => 'Bachelor of Business Administration',
                    'mode' => 'Online', 'tab' => 'Bachelor', 'level' => 'UG', 'duration' => '3 Year',
                    'description' => 'BBA offers foundational knowledge of business administration and includes learning areas such as digital marketing and business analytics.',
                    'link' => '#'
                ],
                [
                    'id' => 5, 'course_id' => 11, 'short_name' => 'BCom', 'full_name' => 'Bachelor of Commerce',
                    'mode' => 'Online', 'tab' => 'Bachelor', 'level' => 'UG', 'duration' => '3 Year',
                    'description' => 'This program provides access to basic knowledge of finance, accounting and emerging Business technologies.',
                    'link' => '#'
                ]
            ];
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
            'uni'        => '',
            'btn_text'   => 'Know More',
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

        $has_online   = !empty($online_courses);
        $has_distance = !empty($distance_courses);
        $show_tabs    = ($has_online && $has_distance);

        // Active mode: default Online if available, else Distance
        $active_mode = $has_online ? 'online' : ($has_distance ? 'distance' : 'online');
        $unique_id = 'sode_courses_' . substr(md5(uniqid(rand(), true)), 0, 8);

        ob_start();
        ?>
        <!-- SODE Universal Courses Mode-Based Section -->
        <div class="sode-courses-wrapper" id="<?php echo esc_attr($unique_id); ?>">

            <?php if ($show_tabs): ?>
                <!-- Mode Tabs Navigation (Rendered ONLY when BOTH Online & Distance courses exist) -->
                <div class="sode-courses-mode-tabs">
                    <button type="button" class="sode-mode-tab-btn <?php echo ($active_mode === 'online') ? 'active' : ''; ?>" data-mode="online">
                        Online Mode
                    </button>
                    <button type="button" class="sode-mode-tab-btn <?php echo ($active_mode === 'distance') ? 'active' : ''; ?>" data-mode="distance">
                        Distance Mode
                    </button>
                </div>
            <?php endif; ?>

            <!-- Panes Container -->
            <div class="sode-courses-panes-wrapper">

                <!-- 1. ONLINE COURSES PANE -->
                <?php if ($has_online): 
                    $on_count = count($online_courses);
                    $on_is_slider_desktop = ($on_count > 3);
                    $on_is_slider_mobile  = ($on_count >= 2);
                    $pane_class = 'sode-courses-pane ' . ($active_mode === 'online' ? 'active' : '') . ($on_is_slider_desktop ? ' has-slider-desktop' : ' is-static-desktop') . ($on_is_slider_mobile ? ' has-slider-mobile' : ' is-static-mobile');
                ?>
                    <div class="<?php echo esc_attr($pane_class); ?>" id="<?php echo esc_attr($unique_id); ?>_pane_online" data-pane-mode="online">
                        <div class="sode-slider-shell">
                            <?php if ($on_is_slider_desktop || $on_is_slider_mobile): ?>
                                <button type="button" class="sode-slider-nav-btn sode-slider-prev" aria-label="Previous Courses">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                </button>
                            <?php endif; ?>

                            <div class="sode-slider-viewport">
                                <div class="sode-slider-track">
                                    <?php foreach ($online_courses as $c): 
                                        $btn_link = !empty($c['link']) && $c['link'] !== '#' ? $c['link'] : '#';
                                    ?>
                                        <div class="sode-course-card-slide">
                                            <div class="sode-course-card">
                                                <div class="sode-course-card-inner">
                                                    <div class="sode-course-head">
                                                        <h3 class="sode-course-short-name"><?php echo esc_html($c['short_name']); ?></h3>
                                                        <h4 class="sode-course-full-name"><?php echo esc_html($c['full_name']); ?></h4>
                                                    </div>

                                                    <div class="sode-course-body">
                                                        <p class="sode-course-desc"><?php echo esc_html($c['description']); ?></p>
                                                    </div>

                                                    <div class="sode-course-footer">
                                                        <div class="sode-course-meta">
                                                            <svg class="sode-course-cal-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                                            </svg>
                                                            <span class="sode-course-duration-text"><?php echo esc_html($c['duration']); ?></span>
                                                        </div>

                                                        <div class="sode-course-action">
                                                            <?php if (!empty($atts['btn_action']) && $atts['btn_action'] === 'counseling'): ?>
                                                                <button type="button" class="sode-course-btn open-counseling-modal-btn" data-course="<?php echo esc_attr($c['short_name']); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif (!empty($atts['btn_action']) && $atts['btn_action'] === 'brochure'): ?>
                                                                <button type="button" class="sode-course-btn open-brochure-modal-btn" data-course="<?php echo esc_attr($c['short_name']); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif ($btn_link !== '#'): ?>
                                                                <a href="<?php echo esc_url($btn_link); ?>" class="sode-course-btn">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="#custom_lead_form" class="sode-course-btn" onclick="if(document.querySelector('.sode-hero-form, #custom_lead_form')){document.querySelector('.sode-hero-form, #custom_lead_form').scrollIntoView({behavior:'smooth'});}">
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
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
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
                    $dist_is_slider_mobile  = ($dist_count >= 2);
                    $pane_class = 'sode-courses-pane ' . ($active_mode === 'distance' ? 'active' : '') . ($dist_is_slider_desktop ? ' has-slider-desktop' : ' is-static-desktop') . ($dist_is_slider_mobile ? ' has-slider-mobile' : ' is-static-mobile');
                ?>
                    <div class="<?php echo esc_attr($pane_class); ?>" id="<?php echo esc_attr($unique_id); ?>_pane_distance" data-pane-mode="distance">
                        <div class="sode-slider-shell">
                            <?php if ($dist_is_slider_desktop || $dist_is_slider_mobile): ?>
                                <button type="button" class="sode-slider-nav-btn sode-slider-prev" aria-label="Previous Courses">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                </button>
                            <?php endif; ?>

                            <div class="sode-slider-viewport">
                                <div class="sode-slider-track">
                                    <?php foreach ($distance_courses as $c): 
                                        $btn_link = !empty($c['link']) && $c['link'] !== '#' ? $c['link'] : '#';
                                    ?>
                                        <div class="sode-course-card-slide">
                                            <div class="sode-course-card">
                                                <div class="sode-course-card-inner">
                                                    <div class="sode-course-head">
                                                        <h3 class="sode-course-short-name"><?php echo esc_html($c['short_name']); ?></h3>
                                                        <h4 class="sode-course-full-name"><?php echo esc_html($c['full_name']); ?></h4>
                                                    </div>

                                                    <div class="sode-course-body">
                                                        <p class="sode-course-desc"><?php echo esc_html($c['description']); ?></p>
                                                    </div>

                                                    <div class="sode-course-footer">
                                                        <div class="sode-course-meta">
                                                            <svg class="sode-course-cal-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                                            </svg>
                                                            <span class="sode-course-duration-text"><?php echo esc_html($c['duration']); ?></span>
                                                        </div>

                                                        <div class="sode-course-action">
                                                            <?php if (!empty($atts['btn_action']) && $atts['btn_action'] === 'counseling'): ?>
                                                                <button type="button" class="sode-course-btn open-counseling-modal-btn" data-course="<?php echo esc_attr($c['short_name']); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif (!empty($atts['btn_action']) && $atts['btn_action'] === 'brochure'): ?>
                                                                <button type="button" class="sode-course-btn open-brochure-modal-btn" data-course="<?php echo esc_attr($c['short_name']); ?>">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </button>
                                                            <?php elseif ($btn_link !== '#'): ?>
                                                                <a href="<?php echo esc_url($btn_link); ?>" class="sode-course-btn">
                                                                    <?php echo esc_html($atts['btn_text']); ?>
                                                                </a>
                                                            <?php else: ?>
                                                                <a href="#custom_lead_form" class="sode-course-btn" onclick="if(document.querySelector('.sode-hero-form, #custom_lead_form')){document.querySelector('.sode-hero-form, #custom_lead_form').scrollIntoView({behavior:'smooth'});}">
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
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
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
            padding: 10px 4px 14px 4px;
            box-sizing: border-box;
        }

        #<?php echo esc_attr($unique_id); ?> .sode-slider-track {
            display: flex;
            align-items: stretch;
            transition: transform 0.4s cubic-bezier(0.25, 1, 0.5, 1);
            will-change: transform;
            gap: 24px;
        }

        /* Static Grid Mode (when <= 3 on Desktop) */
        #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop .sode-slider-track {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            transform: none !important;
            justify-content: center;
            width: 100%;
        }

        /* Slide Card Wrapper */
        #<?php echo esc_attr($unique_id); ?> .sode-course-card-slide {
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            flex: 0 0 calc((100% - 48px) / 3);
            max-width: calc((100% - 48px) / 3);
        }

        #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop .sode-course-card-slide {
            flex: 1 1 auto;
            max-width: 100%;
        }

        /* Course Card */
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

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            #<?php echo esc_attr($unique_id); ?> .sode-course-card-slide {
                flex: 0 0 calc((100% - 24px) / 2);
                max-width: calc((100% - 24px) / 2);
            }
            #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-desktop .sode-slider-track {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            #<?php echo esc_attr($unique_id); ?> .sode-slider-shell {
                padding: 0 20px;
                box-sizing: border-box;
            }
            #<?php echo esc_attr($unique_id); ?> .sode-slider-viewport {
                padding: 10px 0 14px 0;
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
                gap: 0px !important;
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
                padding: 0;
            }
            #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-mobile .sode-slider-track {
                display: block !important;
                transform: none !important;
            }
            #<?php echo esc_attr($unique_id); ?> .sode-courses-pane.is-static-mobile .sode-course-card-slide {
                flex: 1 1 100% !important;
                max-width: 100% !important;
                padding: 0;
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
        (function() {
            var root = document.getElementById('<?php echo esc_js($unique_id); ?>');
            if (!root) return;

            // Mode Tabs Switcher
            var tabBtns = root.querySelectorAll('.sode-mode-tab-btn');
            var panes   = root.querySelectorAll('.sode-courses-pane');
            var activeSliderController = null;

            tabBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var targetMode = this.getAttribute('data-mode');
                    tabBtns.forEach(function(b) { b.classList.remove('active'); });
                    this.classList.add('active');

                    if (activeSliderController && typeof activeSliderController.stopAutoplay === 'function') {
                        activeSliderController.stopAutoplay();
                    }

                    panes.forEach(function(p) {
                        if (p.getAttribute('data-pane-mode') === targetMode) {
                            p.classList.add('active');
                            activeSliderController = initSliderForPane(p);
                        } else {
                            p.classList.remove('active');
                        }
                    });
                });
            });

            // Universal Responsive Slider Controller for Panes
            function initSliderForPane(pane) {
                if (!pane) return null;
                var viewport = pane.querySelector('.sode-slider-viewport');
                var track    = pane.querySelector('.sode-slider-track');
                var slides   = pane.querySelectorAll('.sode-course-card-slide');
                var prevBtn  = pane.querySelector('.sode-slider-prev');
                var nextBtn  = pane.querySelector('.sode-slider-next');
                var dotsWrap = pane.querySelector('.sode-slider-dots');

                if (!viewport || !track || slides.length === 0) return null;

                var totalSlides = slides.length;
                var currentIndex = 0;
                var autoplayTimer = null;

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

                    // Calculate translation
                    var isMobile = (window.innerWidth <= 768);
                    var moveAmount = 0;

                    if (isMobile) {
                        var vWidth = viewport.clientWidth;
                        moveAmount = currentIndex * vWidth;
                    } else {
                        var slideWidth = slides[0].getBoundingClientRect().width;
                        var gap = 24;
                        moveAmount = currentIndex * (slideWidth + gap);
                    }

                    track.style.transform = 'translateX(-' + moveAmount + 'px)';

                    // Rebuild dots
                    if (dotsWrap) {
                        dotsWrap.innerHTML = '';
                        var totalPages = maxIndex + 1;
                        for (var i = 0; i < totalPages; i++) {
                            (function(idx) {
                                var dot = document.createElement('button');
                                dot.type = 'button';
                                dot.className = 'sode-dot-btn' + (idx === currentIndex ? ' active' : '');
                                dot.setAttribute('aria-label', 'Go to slide ' + (idx + 1));
                                dot.addEventListener('click', function() {
                                    currentIndex = idx;
                                    updateSlider();
                                    restartAutoplay();
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
                    if (!shouldEnableSlider()) return;
                    autoplayTimer = setInterval(function() {
                        goNext();
                    }, 2000);
                }

                function stopAutoplay() {
                    if (autoplayTimer) {
                        clearInterval(autoplayTimer);
                        autoplayTimer = null;
                    }
                }

                function restartAutoplay() {
                    stopAutoplay();
                    startAutoplay();
                }

                if (prevBtn) {
                    prevBtn.onclick = function() {
                        goPrev();
                        restartAutoplay();
                    };
                }

                if (nextBtn) {
                    nextBtn.onclick = function() {
                        goNext();
                        restartAutoplay();
                    };
                }

                // Hover Pause & Resume
                pane.addEventListener('mouseenter', stopAutoplay);
                pane.addEventListener('mouseleave', startAutoplay);

                // Touch Swipe Support with Pause & Resume
                var startX = 0;
                var isDragging = false;
                viewport.addEventListener('touchstart', function(e) {
                    stopAutoplay();
                    startX = e.touches[0].clientX;
                    isDragging = true;
                }, { passive: true });

                viewport.addEventListener('touchend', function(e) {
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
                    setTimeout(startAutoplay, 1500);
                }, { passive: true });

                updateSlider();
                startAutoplay();

                window.addEventListener('resize', function() {
                    updateSlider();
                });

                return {
                    stopAutoplay: stopAutoplay,
                    startAutoplay: startAutoplay,
                    updateSlider: updateSlider
                };
            }

            // Init for active pane on load
            var activePane = root.querySelector('.sode-courses-pane.active') || panes[0];
            if (activePane) {
                activeSliderController = initSliderForPane(activePane);
            }
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
            'uni'        => '',
            'mode'       => 'all',       // 'all', 'online', 'distance'
            'format'     => 'short',     // 'short' (e.g. MBA) or 'full' (e.g. Master of Business Administration)
            'level'      => 'all',       // 'all', 'pg', 'ug', 'master', 'bachelor'
            'and'        => 'false',     // 'true' or 'false' (adds "and" before last item)
            'separator'  => ', ',        // delimiter between items
            'bold'       => 'false',     // 'true' wraps each item in <strong>
            'link'       => 'false',     // 'true' wraps each item in <a> link
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $courses = sode_get_university_courses_data($uni_slug);

        $mode_filter  = strtolower($atts['mode']);
        $level_filter = strtolower($atts['level']);
        $filtered = [];

        foreach ($courses as $c) {
            $course_mode = strtolower($c['mode'] ?? 'online');
            if ($mode_filter === 'online' && $course_mode !== 'online') continue;
            if ($mode_filter === 'distance' && $course_mode !== 'distance') continue;

            $is_master = ($c['tab'] === 'Master' || strtoupper($c['level'] ?? '') === 'PG');
            if ($level_filter === 'master' || $level_filter === 'pg') {
                if (!$is_master) continue;
            } elseif ($level_filter === 'bachelor' || $level_filter === 'ug') {
                if ($is_master) continue;
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
}
