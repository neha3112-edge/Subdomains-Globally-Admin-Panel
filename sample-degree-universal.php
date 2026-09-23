<?php
/**
 * Universal University Sample Degree Component
 * File: sample-degree-universal.php
 *
 * Renders the University Sample Degree image or interactive card/modal
 * dynamically fetched from the Central Admin Panel (Universities > Edit > Sample Degree Image).
 *
 * Shortcodes:
 *   [sample_degree]
 *   [university_sample_degree]
 *   [uni_sample_degree]
 *   [sample_degree_image]
 *   [sample_degree_card]
 *   [sample_degree_button]
 *   [sample_degree_url]
 *
 * Supported Attributes:
 *   uni          = "dsu" (default: auto-detected subdomain)
 *   layout       = "card" | "image" | "button" | "url" (default: "card")
 *   title        = "Sample Degree Certificate" (custom heading)
 *   subtitle     = "Government Recognized & UGC-DEB Approved Degree Format"
 *   button_text  = "View Sample Degree" (for layout="button")
 *   max_width    = "720px" (custom max-width, e.g. "100%", "500px")
 *   zoom         = "yes" | "no" (default: "yes" - opens full-res lightbox modal on click)
 *   class        = "custom-class"
 *   alt          = "Custom alt text"
 */

if (defined('SODE_SAMPLE_DEGREE_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_SAMPLE_DEGREE_UNIVERSAL_LOADED', true);

// Safe Polyfills for WordPress functions
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

/**
 * Fetch University Profile & Sample Degree Data
 */
if (!function_exists('sode_get_university_sample_degree_data')) {
    function sode_get_university_sample_degree_data($uni_slug = '')
    {
        // Auto-detect university slug
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
        $uni_slug = strtolower(trim((string) $uni_slug));

        $uni_data = null;

        // 1. Direct Local DB query if available
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
                    if (!empty($uni_slug)) {
                        $stmt = $db->prepare("
                            SELECT id, full_name, short_name, slug, mode, logo_url, sample_degree_img
                            FROM universities 
                            WHERE LOWER(slug) = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?
                            LIMIT 1
                        ");
                        $stmt->execute([$uni_slug, $uni_slug, $uni_slug]);
                        $uni_data = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$uni_data) {
                        $uni_data = $db->query("
                            SELECT id, full_name, short_name, slug, mode, logo_url, sample_degree_img
                            FROM universities 
                            WHERE is_active = 1 
                            ORDER BY id ASC 
                            LIMIT 1
                        ")->fetch(PDO::FETCH_ASSOC);
                    }
                }
            } catch (Exception $e) {
                // Ignore DB error
            }
        }

        // 2. Fallback via Central API (Remote Client)
        if (!$uni_data) {
            $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $api_url = $admin_url . '/api/get_global_keys.php?t=' . time();
            if (!empty($uni_slug)) {
                $api_url .= '&uni=' . urlencode($uni_slug);
            }

            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($api_url, ['timeout' => 4, 'headers' => ['Cache-Control' => 'no-cache']]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $json = json_decode(wp_remote_retrieve_body($resp), true);
                    if (!empty($json['keys'])) {
                        $k = $json['keys'];
                        $uni_data = [
                            'full_name'         => $k['{UNIVERSITY_NAME}'] ?? 'University Online',
                            'short_name'        => $k['{UNIVERSITY_SHORT_NAME_UPPER}'] ?? ($k['{UNIVERSITY_SHORT_NAME}'] ?? 'University'),
                            'slug'              => $k['{UNIVERSITY_SLUG}'] ?? $uni_slug,
                            'mode'              => $k['{MODE}'] ?? 'Online',
                            'logo_url'          => $k['{UNIVERSITY_LOGO}'] ?? '',
                            'sample_degree_img' => $k['{SAMPLE_DEGREE_IMG}'] ?? ($k['{SAMPLE_DEGREE_URL}'] ?? ''),
                        ];
                    }
                }
            }
        }

        if ($uni_data) {
            $raw_img = trim((string)($uni_data['sample_degree_img'] ?? ''));
            if (!empty($raw_img)) {
                if (function_exists('get_asset_url')) {
                    $uni_data['sample_degree_url'] = get_asset_url($raw_img);
                } else {
                    if (strpos($raw_img, 'http://') === 0 || strpos($raw_img, 'https://') === 0) {
                        $uni_data['sample_degree_url'] = $raw_img;
                    } else {
                        $admin_url = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
                        $clean = ltrim($raw_img, '/');
                        if (strpos($clean, 'uploads/') === 0) {
                            $clean = 'admin/' . $clean;
                        }
                        $uni_data['sample_degree_url'] = $admin_url . '/' . $clean;
                    }
                }
            } else {
                $uni_data['sample_degree_url'] = '';
            }
        }

        return $uni_data;
    }
}

/**
 * Universal Sample Degree Shortcode Renderer
 */
if (!function_exists('sode_sample_degree_render')) {
    function sode_sample_degree_render($atts = [])
    {
        $pairs = [
            'uni'         => '',
            'layout'      => 'card', // 'card', 'image', 'button', 'url'
            'title'       => '',
            'subtitle'    => '',
            'button_text' => 'View Sample Degree',
            'max_width'   => '',
            'zoom'        => 'yes',
            'class'       => '',
            'alt'         => '',
        ];
        $atts = shortcode_atts($pairs, $atts, 'sample_degree');

        $uni_data = sode_get_university_sample_degree_data($atts['uni']);
        if (!$uni_data || empty($uni_data['sample_degree_url'])) {
            // Graceful fallback if no degree uploaded
            if ($atts['layout'] === 'url') {
                return '';
            }
            return '<!-- SODE: No sample degree image uploaded for university -->';
        }

        $degree_url = esc_url($uni_data['sample_degree_url']);
        $full_name  = esc_html($uni_data['full_name'] ?? 'University');
        $short_name = esc_html($uni_data['short_name'] ?? 'University');
        $mode       = esc_html($uni_data['mode'] ?? 'Online');

        // Layout: URL only
        if ($atts['layout'] === 'url') {
            return $degree_url;
        }

        $uid = 'sode_deg_' . substr(md5(wp_rand() . microtime()), 0, 8);
        $custom_class = esc_attr(trim($atts['class']));
        $zoom_enabled = strtolower(trim((string)$atts['zoom'])) !== 'no';
        $alt_text = !empty($atts['alt']) ? esc_attr($atts['alt']) : esc_attr("{$full_name} Sample Degree Certificate");

        $heading = !empty($atts['title']) ? esc_html($atts['title']) : "{$full_name} Sample Degree";
        $subtext = !empty($atts['subtitle']) ? esc_html($atts['subtitle']) : "Sample format of Government Recognized & UGC-DEB Approved Degree awarded upon completion.";

        $max_w = !empty($atts['max_width']) ? esc_attr($atts['max_width']) : ($atts['layout'] === 'image' ? '100%' : '760px');

        ob_start();
        ?>
        <style>
            .sode-sample-degree-root {
                box-sizing: border-box;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                margin: 20px auto;
                width: 100%;
            }
            .sode-sample-degree-root * { box-sizing: border-box; }

            /* Card Layout */
            .sode-deg-card {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.03);
                overflow: hidden;
                transition: transform 0.25s ease, box-shadow 0.25s ease;
                padding: 24px;
            }
            .sode-deg-card:hover {
                box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.1);
            }
            .sode-deg-card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 18px;
                flex-wrap: wrap;
            }
            .sode-deg-title-group h3 {
                margin: 0 0 6px 0;
                font-size: 19px;
                font-weight: 700;
                color: #0f172a;
                line-height: 1.3;
            }
            .sode-deg-title-group p {
                margin: 0;
                font-size: 13.5px;
                color: #64748b;
                line-height: 1.4;
            }
            .sode-deg-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: rgba(37, 99, 235, 0.08);
                color: #2563eb;
                border: 1px solid rgba(37, 99, 235, 0.2);
                border-radius: 9999px;
                padding: 6px 14px;
                font-size: 12px;
                font-weight: 600;
                white-space: nowrap;
            }
            .sode-deg-img-wrap {
                position: relative;
                width: 100%;
                background: #f8fafc;
                border-radius: 12px;
                border: 1px solid #edf2f7;
                overflow: hidden;
                text-align: center;
                cursor: <?php echo $zoom_enabled ? 'zoom-in' : 'default'; ?>;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
            }
            .sode-deg-img {
                display: block;
                max-width: 100%;
                height: auto;
                margin: 0 auto;
                border-radius: 8px;
                transition: transform 0.3s ease;
            }
            <?php if ($zoom_enabled): ?>
            .sode-deg-img-wrap:hover .sode-deg-img {
                transform: scale(1.015);
            }
            .sode-deg-overlay-hint {
                position: absolute;
                bottom: 12px;
                right: 12px;
                background: rgba(15, 23, 42, 0.82);
                backdrop-filter: blur(4px);
                color: #ffffff;
                padding: 6px 14px;
                border-radius: 9999px;
                font-size: 12px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                box-shadow: 0 4px 10px rgba(0,0,0,0.2);
                pointer-events: none;
                transition: opacity 0.2s ease;
            }
            <?php endif; ?>

            /* Clean Image Layout */
            .sode-deg-image-only {
                display: block;
                max-width: 100%;
                height: auto;
                border-radius: 12px;
                box-shadow: 0 8px 20px rgba(0,0,0,0.08);
                border: 1px solid #e2e8f0;
                cursor: <?php echo $zoom_enabled ? 'zoom-in' : 'default'; ?>;
            }

            /* Button Layout */
            .sode-deg-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: linear-gradient(135deg, #2563eb, #1d4ed8);
                color: #ffffff !important;
                padding: 12px 24px;
                border-radius: 10px;
                font-size: 14.5px;
                font-weight: 600;
                text-decoration: none !important;
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
                transition: all 0.2s ease;
                border: none;
                cursor: pointer;
            }
            .sode-deg-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
                background: linear-gradient(135deg, #1d4ed8, #1e40af);
            }

            /* Lightbox Modal */
            .sode-deg-lightbox {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(15, 23, 42, 0.88);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                z-index: 999999;
                align-items: center;
                justify-content: center;
                padding: 24px;
                opacity: 0;
                transition: opacity 0.25s ease;
            }
            .sode-deg-lightbox.is-open {
                display: flex;
                opacity: 1;
            }
            .sode-deg-modal-container {
                position: relative;
                max-width: 92vw;
                max-height: 92vh;
                background: #ffffff;
                border-radius: 16px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                animation: sodeDegModalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes sodeDegModalPop {
                from { transform: scale(0.95) translateY(10px); opacity: 0; }
                to { transform: scale(1) translateY(0); opacity: 1; }
            }
            .sode-deg-modal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 20px;
                background: #f8fafc;
                border-bottom: 1px solid #e2e8f0;
            }
            .sode-deg-modal-header h4 {
                margin: 0;
                font-size: 16px;
                font-weight: 700;
                color: #0f172a;
            }
            .sode-deg-modal-close {
                background: transparent;
                border: none;
                font-size: 24px;
                line-height: 1;
                color: #64748b;
                cursor: pointer;
                padding: 4px 8px;
                border-radius: 6px;
                transition: all 0.15s ease;
            }
            .sode-deg-modal-close:hover {
                color: #0f172a;
                background: #e2e8f0;
            }
            .sode-deg-modal-body {
                padding: 12px;
                overflow: auto;
                max-height: calc(92vh - 65px);
                text-align: center;
                background: #f1f5f9;
            }
            .sode-deg-modal-body img {
                display: block;
                max-width: 100%;
                max-height: calc(92vh - 90px);
                height: auto;
                margin: 0 auto;
                border-radius: 8px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            }
        </style>

        <div id="<?php echo $uid; ?>" class="sode-sample-degree-root <?php echo $custom_class; ?>" style="max-width:<?php echo $max_w; ?>;">
            <?php if ($atts['layout'] === 'button'): ?>
                <!-- Layout: Button Trigger -->
                <button type="button" class="sode-deg-btn sode-deg-trigger" data-target="<?php echo $uid; ?>_modal">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <span><?php echo esc_html($atts['button_text']); ?></span>
                </button>

            <?php elseif ($atts['layout'] === 'image'): ?>
                <!-- Layout: Clean Image Only -->
                <img src="<?php echo $degree_url; ?>" alt="<?php echo $alt_text; ?>" class="sode-deg-image-only <?php echo $zoom_enabled ? 'sode-deg-trigger' : ''; ?>" data-target="<?php echo $uid; ?>_modal" loading="lazy">

            <?php else: ?>
                <!-- Layout: Modern Card (Default) -->
                <div class="sode-deg-card">
                    <div class="sode-deg-card-header">
                        <div class="sode-deg-title-group">
                            <h3><?php echo $heading; ?></h3>
                            <p><?php echo $subtext; ?></p>
                        </div>
                        <span class="sode-deg-badge">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            Verified Sample Format
                        </span>
                    </div>

                    <div class="sode-deg-img-wrap <?php echo $zoom_enabled ? 'sode-deg-trigger' : ''; ?>" data-target="<?php echo $uid; ?>_modal">
                        <img src="<?php echo $degree_url; ?>" alt="<?php echo $alt_text; ?>" class="sode-deg-img" loading="lazy">
                        <?php if ($zoom_enabled): ?>
                            <div class="sode-deg-overlay-hint">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                <span>Click to Enlarge</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($zoom_enabled || $atts['layout'] === 'button'): ?>
                <!-- Fullscreen Lightbox Modal -->
                <div id="<?php echo $uid; ?>_modal" class="sode-deg-lightbox">
                    <div class="sode-deg-modal-container">
                        <div class="sode-deg-modal-header">
                            <h4><?php echo $heading; ?></h4>
                            <button type="button" class="sode-deg-modal-close" aria-label="Close">&times;</button>
                        </div>
                        <div class="sode-deg-modal-body">
                            <img src="<?php echo $degree_url; ?>" alt="<?php echo $alt_text; ?>">
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
            (function() {
                var root = document.getElementById('<?php echo $uid; ?>');
                if (!root) return;

                var triggers = root.querySelectorAll('.sode-deg-trigger');
                var modal = document.getElementById('<?php echo $uid; ?>_modal');
                if (!modal) return;

                var closeBtn = modal.querySelector('.sode-deg-modal-close');

                function openModal(e) {
                    if (e) e.preventDefault();
                    modal.classList.add('is-open');
                    document.body.style.overflow = 'hidden';
                }

                function closeModal(e) {
                    if (e) e.preventDefault();
                    modal.classList.remove('is-open');
                    document.body.style.overflow = '';
                }

                triggers.forEach(function(t) {
                    t.addEventListener('click', openModal);
                });

                if (closeBtn) {
                    closeBtn.addEventListener('click', closeModal);
                }

                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal(e);
                    }
                });

                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                        closeModal(e);
                    }
                });
            })();
        </script>
        <?php
        return ob_get_clean();
    }
}

// Register shortcodes in WordPress
if (function_exists('add_shortcode')) {
    add_shortcode('sample_degree',             'sode_sample_degree_render');
    add_shortcode('university_sample_degree',  'sode_sample_degree_render');
    add_shortcode('uni_sample_degree',         'sode_sample_degree_render');
    add_shortcode('sample_degree_card',        'sode_sample_degree_render');
    add_shortcode('sample_degree_image',       function($atts) {
        return sode_sample_degree_render(array_merge((array)$atts, ['layout' => 'image']));
    });
    add_shortcode('sample_degree_button',      function($atts) {
        return sode_sample_degree_render(array_merge((array)$atts, ['layout' => 'button']));
    });
    add_shortcode('sample_degree_url',         function($atts) {
        return sode_sample_degree_render(array_merge((array)$atts, ['layout' => 'url']));
    });
}
