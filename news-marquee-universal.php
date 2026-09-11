<?php
/**
 * Universal Latest News & Marquee Component
 * File: news-marquee-universal.php
 * 
 * Renders the vertical scrolling marquee news card matching SODE design.
 * Shortcodes: [latest_news], [universal_news], [sode_news]
 */

if (!function_exists('sode_news_marquee_render')) {
    function sode_news_marquee_render($atts = []) {
        $atts = shortcode_atts([
            'university' => '',
            'uni'        => '',
            'heading'    => 'Latest News',
            'speed'      => '',
            'height'     => '100%',
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
            height: 100%;
            max-width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-card {
            background-color: #dbeafe;
            background: linear-gradient(180deg, #e4efff 0%, #d8e8fe 100%);
            border-radius: 20px;
            padding: 24px 22px 20px 22px;
            box-shadow: 0 10px 30px -5px rgba(37, 99, 235, 0.12), 0 4px 6px -2px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(191, 219, 254, 0.85);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e3a8a;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
            height: 100%;
            min-height: 380px;
            display: flex;
            flex-direction: column;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-header {
            text-align: center;
            margin-bottom: 16px;
            flex-shrink: 0;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-title {
            margin: 0 0 14px 0;
            font-size: 22px;
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
            flex: 1;
            min-height: 280px;
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
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-item:last-child {
            margin-bottom: 20px;
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
            font-size: 14.5px;
            line-height: 1.55;
            font-weight: 500;
            color: #243c7c;
            display: inline;
        }
        #<?php echo esc_attr($unique_id); ?> .sode-news-badge {
            display: inline-block;
            background: #f3b23e;
            background: linear-gradient(135deg, #f59e0b 0%, #eab308 100%);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            margin-left: 6px;
            vertical-align: 1px;
            box-shadow: 0 2px 5px rgba(245, 158, 11, 0.35);
            letter-spacing: 0.2px;
            animation: sodeBadgePulse 2.4s ease-in-out infinite;
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
