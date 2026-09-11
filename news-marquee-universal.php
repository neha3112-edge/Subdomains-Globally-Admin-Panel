<?php
/**
 * Universal Latest News & Marquee Component
 * File: news-marquee-universal.php
 * 
 * Renders the vertical scrolling marquee news card matching SODE design.
 * Shortcodes: [latest_news], [universal_news], [sode_news]
 */

if (defined('SODE_NEWS_MARQUEE_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_NEWS_MARQUEE_UNIVERSAL_LOADED', true);

// Safe polyfills for WP helpers (standalone / central SSR safe)
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string)$title), '-'));
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var((string)$url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('esc_js')) {
    function esc_js($text) {
        return addslashes((string)$text);
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

if (!function_exists('sode_news_marquee_render')) {
    function sode_news_marquee_render($atts = []) {
        $atts = shortcode_atts([
            'university' => '',
            'uni'        => '',
            'heading'    => 'Latest News',
            'speed'      => '',
            'height'     => '',
            'limit'      => 20,
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

        // If not specified, auto-detect from hostname or constant
        if (empty($uni_slug)) {
            if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
                $uni_slug = sanitize_title(SODE_UNIVERSITY_SLUG);
            } else {
                $host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
                $parts = explode('.', $host);
                if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
                    $uni_slug = sanitize_title($parts[0]);
                }
            }
        }

        // Fetch News Items from DB directly (if available) or remote API
        $news_items = [];
        $uni_info = null;

        // Try direct DB first if config is loaded
        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    if (!empty($uni_slug)) {
                        $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) LIMIT 1");
                        $stmt->execute([$uni_slug, $uni_slug]);
                        $uni_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    }

                    $uni_id = $uni_info ? (int)$uni_info['id'] : 0;
                    if ($uni_id > 0) {
                        $stmt = $db->prepare("
                            SELECT * FROM news_items 
                            WHERE is_active = 1 AND (is_global = 1 OR university_id = ?)
                            ORDER BY is_global DESC, sort_order ASC, id ASC
                        ");
                        $stmt->execute([$uni_id]);
                    } else {
                        $stmt = $db->query("
                            SELECT * FROM news_items 
                            WHERE is_active = 1 AND is_global = 1
                            ORDER BY is_global DESC, sort_order ASC, id ASC
                        ");
                    }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $news_items[] = [
                            'text'       => $r['news_text'],
                            'link'       => $r['news_link'],
                            'has_badge'  => !empty($r['has_badge']),
                            'badge_text' => !empty($r['badge_text']) ? $r['badge_text'] : 'New'
                        ];
                    }
                }
            } catch (Exception $e) {}
        }

        // Fallback: Fetch via REST API if DB is not local
        if (empty($news_items)) {
            $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? SODE_CENTRAL_ADMIN_URL : 'https://admin.distanceeducationschool.com';
            $api_url = rtrim($admin_url, '/') . '/api/get_latest_news.php?t=' . time();
            if ($uni_slug) {
                $api_url .= '&uni=' . urlencode($uni_slug);
            }

            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($api_url, ['timeout' => 5, 'headers' => ['Cache-Control' => 'no-cache']]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $json = json_decode(wp_remote_retrieve_body($resp), true);
                    if (!empty($json['news']) && is_array($json['news'])) {
                        $news_items = $json['news'];
                    }
                }
            } elseif (function_exists('curl_init')) {
                $ch = curl_init($api_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $body = curl_exec($ch);
                curl_close($ch);
                if ($body) {
                    $json = json_decode($body, true);
                    if (!empty($json['news']) && is_array($json['news'])) {
                        $news_items = $json['news'];
                    }
                }
            }
        }

        // Fallback default items if DB and API returned empty (ensures it never displays blank)
        if (empty($news_items)) {
            $news_items = [
                ['text' => 'Admissions Open for Academic Session $session$ - Apply Now!', 'link' => '#', 'has_badge' => 1, 'badge_text' => 'New'],
                ['text' => '{UNIVERSITY_NAME} Online Degree Programs are UGC-DEB Entitled & NAAC Accredited', 'link' => '#', 'has_badge' => 1, 'badge_text' => 'New'],
                ['text' => 'Avail Up to 30% Exclusive Academic Scholarship on {UNIVERSITY_SHORT_NAME} Programs', 'link' => '#', 'has_badge' => 1, 'badge_text' => 'New'],
                ['text' => '100% Placement Assistance & Dedicated Career Support for Online Students', 'link' => '#', 'has_badge' => 0, 'badge_text' => ''],
                ['text' => 'Flexible Weekend Live Lectures & 24/7 E-Library LMS Access', 'link' => '#', 'has_badge' => 0, 'badge_text' => ''],
            ];
        }

        // Apply Placeholders Replacement on client side
        $uni_name  = $uni_info['full_name'] ?? ($uni_slug ? ucwords(str_replace('-', ' ', $uni_slug)) : 'University');
        $uni_short = $uni_info['short_name'] ?? strtoupper($uni_slug ?: 'UNI');
        $y = date('Y');

        $processed_news = [];
        foreach ($news_items as $item) {
            $t = $item['text'] ?? ($item['news_text'] ?? '');
            $l = $item['link'] ?? ($item['news_link'] ?? '');
            $b = !empty($item['has_badge']);
            $bt = !empty($item['badge_text']) ? $item['badge_text'] : 'New';

            $replacements = [
                '{UNIVERSITY_NAME}'        => $uni_name,
                '{university_name}'        => $uni_name,
                '{UNIVERSITY_SHORT_NAME}'  => $uni_short,
                '{university_short_name}'  => $uni_short,
                '$YEAR$'                   => $y,
                '$session$'                => $y . '-' . substr((string)((int)$y + 1), -2),
                '$nextyear$'               => (string)((int)$y + 1),
            ];

            foreach ($replacements as $find => $repl) {
                $t = str_ireplace($find, $repl, $t);
                if ($l) $l = str_ireplace($find, $repl, $l);
            }

            $processed_news[] = [
                'text'       => $t,
                'link'       => $l,
                'has_badge'  => $b,
                'badge_text' => $bt
            ];
        }

        // Calculate fast and smooth dynamic animation speed
        $count = count($processed_news);
        if (!empty($atts['speed'])) {
            $anim_duration = $atts['speed'];
        } else {
            $anim_duration = max(6, (int)($count * 2.2)) . 's';
        }
        $unique_id = 'sode_news_' . substr(md5(uniqid(rand(), true)), 0, 8);

        ob_start();
        ?>
        <!-- SODE Universal Latest News Marquee Component -->
        <div class="sode-news-card-wrapper" id="<?php echo esc_attr($unique_id); ?>">
            <div class="sode-news-card">
                <div class="sode-news-header">
                    <h3 class="sode-news-title"><?php echo esc_html($atts['heading']); ?></h3>
                    <div class="sode-news-divider"></div>
                </div>

                <div class="sode-news-viewport">
                    <div class="sode-news-track" style="animation-duration: <?php echo esc_attr($anim_duration); ?>;">
                        <?php 
                        // Duplicate items twice to achieve seamless infinite loop
                        for ($loop = 0; $loop < 2; $loop++): 
                            foreach ($processed_news as $idx => $news):
                        ?>
                            <div class="sode-news-item">
                                <?php if (!empty($news['link']) && $news['link'] !== '#'): ?>
                                    <a href="<?php echo esc_url($news['link']); ?>" target="_blank" rel="noopener noreferrer" class="sode-news-link">
                                        <span class="sode-news-text"><?php echo esc_html($news['text']); ?></span>
                                        <?php if ($news['has_badge']): ?>
                                            <span class="sode-news-badge"><?php echo esc_html($news['badge_text']); ?></span>
                                        <?php endif; ?>
                                    </a>
                                <?php else: ?>
                                    <div class="sode-news-content">
                                        <span class="sode-news-text"><?php echo esc_html($news['text']); ?></span>
                                        <?php if ($news['has_badge']): ?>
                                            <span class="sode-news-badge"><?php echo esc_html($news['badge_text']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endforeach; 
                        endfor; 
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <style>
        #<?php echo esc_attr($unique_id); ?>.sode-news-card-wrapper {
            width: 100%;
            max-width: 440px;
            margin: 0 auto;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-card {
            background-color: #dbeafe;
            background: linear-gradient(180deg, #e4efff 0%, #d8e8fe 100%);
            border-radius: 20px;
            padding: 22px 20px 18px 20px;
            box-shadow: 0 10px 30px -5px rgba(37, 99, 235, 0.12), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(191, 219, 254, 0.85);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e3a8a;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-header {
            text-align: center;
            margin-bottom: 14px;
            flex-shrink: 0;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-title {
            margin: 0 0 12px 0;
            font-size: 21px;
            font-weight: 700;
            color: #0f3b82;
            letter-spacing: -0.3px;
            line-height: 1.2;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-divider {
            height: 1px;
            width: 100%;
            background: rgba(15, 59, 130, 0.16);
            margin: 0 auto;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-viewport {
            overflow: hidden;
            position: relative;
            height: 250px;
            min-height: 200px;
            max-height: 340px;
            mask-image: linear-gradient(to bottom, transparent, black 5%, black 95%, transparent);
            -webkit-mask-image: linear-gradient(to bottom, transparent, black 5%, black 95%, transparent);
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-track {
            display: flex;
            flex-direction: column;
            animation-name: sodeNewsMarqueeAnim;
            animation-timing-function: linear;
            animation-iteration-count: infinite;
            will-change: transform;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-track:hover,
        #<?php echo esc_attr($unique_id); ?> .sode-news-track:active {
            animation-play-state: paused !important;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-item {
            margin-bottom: 18px;
            box-sizing: border-box;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-item:last-child {
            margin-bottom: 18px;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-link {
            text-decoration: none;
            color: #243c7c;
            display: block;
            transition: color 0.2s ease, transform 0.2s ease;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-link:hover {
            color: #0f3b82;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-link:hover .sode-news-text {
            text-decoration: underline;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-content {
            color: #243c7c;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-text {
            font-size: 14px;
            line-height: 1.5;
            font-weight: 500;
            color: #243c7c;
            display: inline;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-badge {
            display: inline-block;
            background: #f3b23e;
            background: linear-gradient(135deg, #f59e0b 0%, #eab308 100%);
            color: #ffffff;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: 1px;
            box-shadow: 0 2px 5px rgba(245, 158, 11, 0.35);
            letter-spacing: 0.2px;
            animation: sodeBadgePulse 2.4s ease-in-out infinite;
        }

        /* Mobile specific height and styling */
        @media (max-width: 768px) {
            #<?php echo esc_attr($unique_id); ?>.sode-news-card-wrapper {
                max-width: 100%;
                margin-top: 20px;
            }
            #<?php echo esc_attr($unique_id); ?> .sode-news-card {
                padding: 20px 16px 16px 16px;
                border-radius: 16px;
            }
            #<?php echo esc_attr($unique_id); ?> .sode-news-viewport {
                height: 220px !important;
                min-height: 180px;
                max-height: 240px;
            }
        }

        @keyframes sodeNewsMarqueeAnim {
            0% {
                transform: translateY(0);
            }
            100% {
                transform: translateY(-50%);
            }
        }
        @keyframes sodeBadgePulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 2px 5px rgba(245, 158, 11, 0.35);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 3px 8px rgba(245, 158, 11, 0.55);
            }
        }
        </style>

        <script>
        (function() {
            function adjustNewsHeight() {
                var el = document.getElementById('<?php echo esc_js($unique_id); ?>');
                if (!el || window.innerWidth <= 768) return;
                
                // Find adjacent left content column in Elementor
                var container = el.closest('.elementor-widget-wrap') || el.closest('.elementor-column') || el.parentElement;
                if (!container || !container.parentElement) return;

                var sibling = container.parentElement.querySelector('.elementor-column:first-child') || container.previousElementSibling;
                if (sibling) {
                    var sibHeight = sibling.offsetHeight;
                    if (sibHeight > 220 && sibHeight < 650) {
                        var headerEl = el.querySelector('.sode-news-header');
                        var headerH = headerEl ? headerEl.offsetHeight : 55;
                        var vp = el.querySelector('.sode-news-viewport');
                        if (vp) {
                            var targetH = Math.max(190, sibHeight - headerH - 45);
                            vp.style.height = targetH + 'px';
                        }
                    }
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', adjustNewsHeight);
            } else {
                adjustNewsHeight();
            }
            window.addEventListener('resize', adjustNewsHeight);
            setTimeout(adjustNewsHeight, 500);
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}

// Register Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('latest_news', 'sode_news_marquee_render');
    add_shortcode('universal_news', 'sode_news_marquee_render');
    add_shortcode('sode_news', 'sode_news_marquee_render');
}
