<?php
/**
 * Universal Recent Announcements & Inner Page News Component
 * File: announcements-list-universal.php
 * 
 * Renders the clean announcement card list matching SODE inner page design.
 * Shortcodes: [recent_announcements], [university_announcements], [uni_announcements], [announcements_list], [inner_page_news]
 */

if (defined('SODE_ANNOUNCEMENTS_LIST_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_ANNOUNCEMENTS_LIST_UNIVERSAL_LOADED', true);

// Safe polyfills for WP helpers
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

if (!function_exists('sode_announcements_list_render')) {
    function sode_announcements_list_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni' => '',
            'heading' => 'Recent Announcements',
            'btn_text' => 'Read More',
            'limit' => 20,
        ], $atts);

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

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];

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

        $news_items = [];
        $uni_info = null;
        $limit = max(1, min(100, (int) $atts['limit']));

        // 1. Try direct DB query first
        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    if (!empty($uni_slug)) {
                        $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) LIMIT 1");
                        $stmt->execute([$uni_slug, $uni_slug]);
                        $uni_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    $uni_id = $uni_info ? (int) $uni_info['id'] : 0;
                    if ($uni_id > 0) {
                        $stmt = $db->prepare("
                            SELECT * FROM news_items 
                            WHERE is_active = 1 AND (is_global = 1 OR university_id = ?)
                            ORDER BY is_global DESC, sort_order ASC, id DESC
                            LIMIT {$limit}
                        ");
                        $stmt->execute([$uni_id]);
                    } else {
                        $stmt = $db->query("
                            SELECT * FROM news_items 
                            WHERE is_active = 1 AND is_global = 1
                            ORDER BY is_global DESC, sort_order ASC, id DESC
                            LIMIT {$limit}
                        ");
                    }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $news_items[] = [
                            'title' => $r['news_text'],
                            'description' => $r['description'] ?? '',
                            'published_date' => !empty($r['published_date']) ? $r['published_date'] : date('F j, Y', strtotime($r['created_at'])),
                            'link' => $r['news_link'] ?? '#',
                            'has_badge' => !empty($r['has_badge']),
                            'badge_text' => !empty($r['badge_text']) ? $r['badge_text'] : 'New'
                        ];
                    }
                }
            } catch (Exception $e) {
            }
        }

        // 2. Fallback: Fetch via REST API if DB is remote
        if (empty($news_items)) {
            $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? SODE_CENTRAL_ADMIN_URL : 'https://admin.distanceeducationschool.com';
            $api_url = rtrim($admin_url, '/') . '/api/get_latest_news.php?t=' . time();
            if ($uni_slug) {
                $api_url .= '&uni=' . urlencode($uni_slug);
            }

            $body = false;
            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($api_url, [
                    'timeout' => 5, 
                    'headers' => [
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache'
                    ]
                ]);
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
                if (!empty($json['news']) && is_array($json['news'])) {
                    foreach ($json['news'] as $r) {
                        $news_items[] = [
                            'title' => $r['title'] ?? ($r['text'] ?? ''),
                            'description' => $r['description'] ?? '',
                            'published_date' => $r['published_date'] ?? '',
                            'link' => $r['link'] ?? '#',
                            'has_badge' => !empty($r['has_badge']),
                            'badge_text' => $r['badge_text'] ?? 'New'
                        ];
                    }
                }
            }
        }

        // Fallback default sample announcements if database has no items yet
        if (empty($news_items)) {
            $news_items = [
                [
                    'title' => 'Admission Deadline Extended to 15th October ' . date('Y') . ' for the latest academic session.',
                    'description' => '{UNIVERSITY_NAME} Online has extended the admission deadline for the ' . date('Y') . '-' . substr((string) ((int) date('Y') + 1), -2) . ' academic session. Students can submit the application form before 15th October ' . date('Y') . '.',
                    'published_date' => date('F j, Y'),
                    'link' => '#',
                ],
                [
                    'title' => 'New Specialization Launched in Online MBA — Business Analytics',
                    'description' => '{UNIVERSITY_NAME} offers a new online MBA specialization for flexible learners. Online MBA now offers Business Analytics as one of the specializations that deals with business data and its organization.',
                    'published_date' => date('F j, Y'),
                    'link' => '#',
                ],
                [
                    'title' => '{UNIVERSITY_SHORT_NAME} Online Convocation ' . date('Y') . ' — Details Announced',
                    'description' => 'Learners seeking their online degree in the Convocation for the academic year of ' . date('Y') . ' can apply with a form. Mention accurate details and more to get your degree.',
                    'published_date' => date('F j, Y'),
                    'link' => '#',
                ],
                [
                    'title' => 'Semester Exam Schedule Released for ' . date('Y'),
                    'description' => '{UNIVERSITY_SHORT_NAME} has published several examination-related circulars and timetables for ' . date('F Y') . '. Recent updates include Summer Term Examinations, supplementary examinations, and other program-specific schedules.',
                    'published_date' => date('F j, Y'),
                    'link' => '#',
                ],
            ];
        }

        // Apply Placeholders Replacement
        $uni_name = $uni_info['full_name'] ?? ($uni_slug ? ucwords(str_replace('-', ' ', $uni_slug)) : 'University');
        $uni_short = $uni_info['short_name'] ?? strtoupper($uni_slug ?: 'UNI');
        $y = date('Y');

        $replacements = [
            '{UNIVERSITY_NAME}' => $uni_name,
            '{university_name}' => $uni_name,
            '{UNIVERSITY_SHORT_NAME}' => $uni_short,
            '{university_short_name}' => $uni_short,
            '{MODE}' => $uni_info['mode'] ?? 'Online & Distance',
            '{mode}' => $uni_info['mode'] ?? 'Online & Distance',
            '$YEAR$' => $y,
            '$nextyear$' => (string) ((int) $y + 1),
            '$session$' => $y . '-' . substr((string) ((int) $y + 1), -2),
        ];

        ob_start();
        $unique_id = 'sode-announcements-' . wp_rand(1000, 9999);
        ?>
        <style>
            .sode-announcements-box {
                background: #e2f2ff;
                border: 1px solid #d2e5fc;
                border-radius: 18px;
                padding: 32px 36px;
                box-sizing: border-box;
                margin: 0px;
            }

            .sode-announcements-title {
                font-size: 24px;
                font-weight: 700;
                color: #062c50;
                margin: 0 0 30px 0;
                letter-spacing: -0.3px;
                line-height: 1.3;
            }

            .sode-announcement-row {
                padding: 30px 0;
                border-bottom: 1px solid #dcdee1;
            }

            .sode-announcement-row:first-of-type {
                padding-top: 2px;
            }

            .sode-announcement-row:last-of-type {
                border-bottom: none;
                padding-bottom: 2px;
            }

            .sode-announcement-heading {
                font-size: 18px;
                font-weight: 600;
                color: #0a192f;
                line-height: 1.45;
                margin: 0 0 9px 0;
            }

            .sode-announcement-date-text {
                color: #0a192f;
            }

            .sode-announcement-date-sep {
                color: #0a192f;
                margin: 0 5px;
            }

            .sode-announcement-desc-text {
                font-size: 13.5px;
                color: #334155;
                line-height: 1.6;
                margin: 0 0 14px 0;
            }

            .sode-announcement-action-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #2563eb;
                color: #ffffff !important;
                font-size: 13px;
                font-weight: 600;
                padding: 7px 18px;
                border-radius: 6px;
                text-decoration: none;
                border: none;
                cursor: pointer;
                box-shadow: 0 2px 6px rgba(37, 99, 235, 0.25);
                transition: all 0.2s ease;
            }

            .sode-announcement-action-btn:hover {
                background: #1d4ed8;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
                color: #ffffff !important;
            }

            @media (max-width: 768px) {
                .sode-announcements-box {
                    padding: 22px 18px;
                    border-radius: 14px;
                }

                .sode-announcements-title {
                    font-size: 19px;
                    margin-bottom: 18px;
                }

                .sode-announcement-heading {
                    font-size: 15px;
                }

                .sode-announcement-desc-text {
                    font-size: 13px;
                }
            }
        </style>

        <div class="sode-announcements-box" id="<?php echo esc_attr($unique_id); ?>">
            <?php if (!empty($atts['heading'])): ?>
                <h3 class="sode-announcements-title"><?php echo esc_html($atts['heading']); ?></h3>
            <?php endif; ?>

            <div class="sode-announcements-list">
                <?php foreach ($news_items as $item):
                    $title = str_ireplace(array_keys($replacements), array_values($replacements), $item['title']);
                    $desc = str_ireplace(array_keys($replacements), array_values($replacements), $item['description']);
                    $date = str_ireplace(array_keys($replacements), array_values($replacements), $item['published_date']);
                    $link = !empty($item['link']) ? str_ireplace(array_keys($replacements), array_values($replacements), $item['link']) : '#';
                    ?>
                    <div class="sode-announcement-row">
                        <div class="sode-announcement-heading">
                            <?php if (!empty($date)): ?>
                                <span class="sode-announcement-date-text"><?php echo esc_html($date); ?></span>
                                <span class="sode-announcement-date-sep">|</span>
                            <?php endif; ?>
                            <span class="sode-announcement-title-text"><?php echo esc_html($title); ?></span>
                        </div>

                        <?php if (!empty($desc)): ?>
                            <p class="sode-announcement-desc-text"><?php echo nl2br(esc_html($desc)); ?></p>
                        <?php endif; ?>

                        <a href="<?php echo esc_url($link); ?>" class="sode-announcement-action-btn" <?php echo ($link !== '#' ? 'target="_blank" rel="noopener noreferrer"' : ''); ?>>
                            <?php echo esc_html($atts['btn_text']); ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Register Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('recent_announcements', 'sode_announcements_list_render');
    add_shortcode('university_announcements', 'sode_announcements_list_render');
    add_shortcode('uni_announcements', 'sode_announcements_list_render');
    add_shortcode('announcements_list', 'sode_announcements_list_render');
    add_shortcode('inner_page_news', 'sode_announcements_list_render');
}
