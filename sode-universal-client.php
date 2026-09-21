<?php
if (false) {
    /** @noinspection PhpIncludeInspection */
    require_once __DIR__ . '/wordpress-stubs.php';
}

/**
 * Plugin Name: SODE Universal Remote Client
 * Plugin URI: https://admin.distanceeducationschool.com
 * Description: Lightweight 1-time drop-in client connected to SODE Central Admin Engine. All UI, CSS, JS, banner designs, and lead forms render dynamically from the central server.
 * Version: 4.0.0
 * Author: Rachit Aggarwal (Senior Web Developer)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('SODE_UNIVERSAL_CLIENT_LOADED')) {
    return;
}
define('SODE_UNIVERSAL_CLIENT_LOADED', true);

// ====================================================
// ⚙️ CONFIGURATION: Central Admin API Root
// ====================================================
if (!defined('SODE_CENTRAL_ADMIN_URL')) {
    define('SODE_CENTRAL_ADMIN_URL', 'https://admin.distanceeducationschool.com');
}

// ====================================================
// ⚙️ GLOBAL KEYS ENGINE
// Auto-replaces $KEY$ / {KEY} / {{KEY}} in all WordPress
// content (Elementor, Widgets, Titles, Shortcodes, Yoast)
// without any code when new keys are added in Admin Panel.
// ====================================================

if (!function_exists('sode_client_get_global_keys')) {
    function sode_client_get_global_keys()
    {
        // Per-request memory only (no cross-request caching)
        // Fresh API call on every page load = always up-to-date values
        static $mem = null;
        if ($mem !== null)
            return $mem;

        // Detect university slug from subdomain or SODE_UNIVERSITY_SLUG constant
        $uni = '';
        if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
            $uni = sanitize_title(SODE_UNIVERSITY_SLUG);
        } else {
            $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
            $parts = explode('.', $host);
            if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
                $uni = sanitize_title($parts[0]);
            }
        }

        $api_url = rtrim(SODE_CENTRAL_ADMIN_URL, '/') . '/api/get_global_keys.php?t=' . time();
        if ($uni)
            $api_url .= '&uni=' . urlencode($uni);

        $resp = wp_remote_get($api_url, ['timeout' => 5, 'headers' => ['Cache-Control' => 'no-cache']]);

        $keys = [];
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $json = json_decode(wp_remote_retrieve_body($resp), true);
            if (!empty($json['keys']) && is_array($json['keys'])) {
                $keys = $json['keys'];
            }
        }

        // Fallback defaults if API unreachable
        if (empty($keys)) {
            $y = date('Y');
            $keys = [
                '$YEAR$' => $y,
                '$session$' => $y . '-' . substr((string) ((int) $y + 1), -2),
                '$nextyear$' => (string) ((int) $y + 1),
                '$BANNER_TOP_TEXT$' => 'Welcome to SODE™ (School of Online and Distance Education)',
            ];
        }

        $mem = $keys;
        return $mem;
    }
}



if (!function_exists('sode_client_replace_keys')) {
    function sode_client_replace_keys($text)
    {
        if (!is_string($text) || empty($text))
            return $text;

        // Quick bail — if none of the dollar signs or braces present
        if (strpos($text, '$') === false && strpos($text, '{') === false)
            return $text;

        $keys = sode_client_get_global_keys();
        if (empty($keys))
            return $text;

        // Protect <script> and <style> blocks if present in HTML to prevent corrupting JS code
        $placeholders = [];
        if (strpos($text, '<script') !== false || strpos($text, '<style') !== false) {
            $text = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\\1>/is', function ($matches) use (&$placeholders) {
                $token = '###SODE_PROTECTED_TAG_' . count($placeholders) . '###';
                $placeholders[$token] = $matches[0];
                return $token;
            }, $text);
        }

        foreach ($keys as $code => $val) {
            $val = (string) $val;
            $raw = trim($code, '$');

            // All pattern variants — double curly first to avoid partial replace
            $patterns = [
                '{{' . $raw . '}}',
                '{{' . strtoupper($raw) . '}}',
                '{{' . strtolower($raw) . '}}',
                $code,
                '$' . strtoupper($raw) . '$',
                '$' . strtolower($raw) . '$',
                '{' . $raw . '}',
                '{' . strtoupper($raw) . '}',
                '{' . strtolower($raw) . '}',
            ];

            foreach ($patterns as $p) {
                if (strpos($text, $p) !== false) {
                    $text = str_replace($p, $val, $text);
                }
            }
        }

        // Restore protected script/style tags
        if (!empty($placeholders)) {
            $text = strtr($text, $placeholders);
        }

        return $text;
    }
}

// Hook into every WordPress content filter so $YEAR$ etc.
// works in Elementor, Classic Editor, Widgets, Titles, Yoast SEO
add_filter('the_content', 'sode_client_replace_keys', 20);
add_filter('the_title', 'sode_client_replace_keys', 20);
add_filter('widget_text', 'sode_client_replace_keys', 20);
add_filter('widget_text_content', 'sode_client_replace_keys', 20);
add_filter('widget_block_content', 'sode_client_replace_keys', 20);
add_filter('get_the_excerpt', 'sode_client_replace_keys', 20);
add_filter('the_excerpt', 'sode_client_replace_keys', 20);

// Elementor dynamic content
add_filter('elementor/frontend/the_content', 'sode_client_replace_keys', 20);
add_filter('elementor/widget/render_content', 'sode_client_replace_keys', 20);

// Yoast SEO title & meta description
add_filter('wpseo_title', 'sode_client_replace_keys', 20);
add_filter('wpseo_metadesc', 'sode_client_replace_keys', 20);
add_filter('wpseo_opengraph_title', 'sode_client_replace_keys', 20);

// ACF & shortcode output
add_filter('acf/format_value', 'sode_client_replace_keys', 20);
add_filter('do_shortcode_tag', 'sode_client_replace_keys', 20);

// ====================================================
// 🌟 GLOBAL FAVICON OVERRIDE ENGINE
// Centralized Favicon from Admin Panel overrides any
// WordPress theme, customizer, or plugin favicon.
// ====================================================

if (!function_exists('sode_client_get_central_favicon_url')) {
    function sode_client_get_central_favicon_url()
    {
        $keys = sode_client_get_global_keys();
        $fav = $keys['$FAVICON_URL$'] ?? $keys['{FAVICON_URL}'] ?? $keys['$SITE_FAVICON$'] ?? $keys['{SITE_FAVICON}'] ?? $keys['$SITE_LOGO$'] ?? $keys['$LOGO_URL$'] ?? '';

        if (empty($fav)) {
            $fav = 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';
        }

        // Prepend Central Admin URL if relative path
        if (!empty($fav) && !preg_match('#^https?://#i', $fav)) {
            $fav = rtrim(SODE_CENTRAL_ADMIN_URL, '/') . '/' . ltrim($fav, '/');
        }

        return $fav;
    }
}

// 1. Override WordPress core site icon URL
add_filter('get_site_icon_url', function ($url, $size = 512, $blog_id = 0) {
    $fav = sode_client_get_central_favicon_url();
    return !empty($fav) ? $fav : $url;
}, 999999, 3);

// 2. Override WordPress site_icon_meta_tags (outputs icon tags in wp_head)
add_filter('site_icon_meta_tags', function ($meta_tags) {
    $fav = sode_client_get_central_favicon_url();
    if (!empty($fav)) {
        $clean_fav = esc_url($fav);
        return [
            sprintf('<link rel="shortcut icon" href="%s" />', $clean_fav),
            sprintf('<link rel="icon" type="image/x-icon" href="%s" />', $clean_fav),
            sprintf('<link rel="icon" href="%s" sizes="32x32" />', $clean_fav),
            sprintf('<link rel="icon" href="%s" sizes="192x192" />', $clean_fav),
            sprintf('<link rel="apple-touch-icon" href="%s" />', $clean_fav),
            sprintf('<meta name="msapplication-TileImage" content="%s" />', $clean_fav),
        ];
    }
    return $meta_tags;
}, 999999);

// 3. Render Central Favicon in <head> at top priority
if (!function_exists('sode_client_render_central_favicon_tags')) {
    function sode_client_render_central_favicon_tags()
    {
        $fav = sode_client_get_central_favicon_url();
        if (!empty($fav)) {
            $clean_fav = esc_url($fav);
            echo "\n<!-- SODE Central Admin Global Favicon -->\n";
            echo '<link rel="shortcut icon" href="' . $clean_fav . '" />' . "\n";
            echo '<link rel="icon" href="' . $clean_fav . '" sizes="32x32" />' . "\n";
            echo '<link rel="apple-touch-icon" href="' . $clean_fav . '" />' . "\n";
            echo "<!-- End SODE Favicon -->\n";
        }
    }
}
add_action('wp_head', 'sode_client_render_central_favicon_tags', 1);
add_action('login_head', 'sode_client_render_central_favicon_tags', 1);
add_action('admin_head', 'sode_client_render_central_favicon_tags', 1);

// ====================================================
// 🔥 FULL PAGE OUTPUT BUFFER (PHP-level)
// Runs when WP Rocket/Redis cache MISS (first visit).
// Strips existing theme favicons and enforces central keys.
// ====================================================
add_action('template_redirect', function () {
    ob_start(function ($html) {
        if (empty($html) || !is_string($html))
            return $html;

        // 1. Enforce Favicon Replacement in HTML output
        $fav = sode_client_get_central_favicon_url();
        if (!empty($fav)) {
            $clean_fav = esc_url($fav);
            // Strip any theme/plugin favicon tags to prevent duplicates
            $html = preg_replace('/<link\s+[^>]*rel=["\'](?:shortcut\s+icon|icon|apple-touch-icon)["\'][^>]*>\s*/i', '', $html);
            // Inject clean central favicon right after <head>
            $favicon_tags = "\n<link rel=\"shortcut icon\" href=\"{$clean_fav}\" />\n<link rel=\"icon\" href=\"{$clean_fav}\" sizes=\"32x32\" />\n<link rel=\"apple-touch-icon\" href=\"{$clean_fav}\" />\n";
            $html = preg_replace('/(<head\b[^>]*>)/i', '$1' . $favicon_tags, $html, 1);
        }

        // 2. Replace Dynamic Text Keys
        if (strpos($html, '$') !== false || strpos($html, '{') !== false) {
            $html = sode_client_replace_keys($html);
        }

        return $html;
    });
}, 0);

// ====================================================
// ⚡ JS GLOBAL KEYS & FAVICON ENGINE (Client-Side)
// Injected into <head> — runs in browser AFTER page loads.
// Bypasses WP Rocket HTML cache, Redis, ALL server caches.
// Automatically wipes old cached favicon and injects Central Favicon.
// ====================================================
add_action('wp_head', function () {
    // Detect university slug for university-specific keys
    $uni = '';
    if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
        $uni = sanitize_title(SODE_UNIVERSITY_SLUG);
    } else {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $parts = explode('.', $host);
        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
            $uni = sanitize_title($parts[0]);
        }
    }
    $api_base = esc_js(rtrim(SODE_CENTRAL_ADMIN_URL, '/') . '/api/get_global_keys.php');
    $admin_root_js = esc_js(rtrim(SODE_CENTRAL_ADMIN_URL, '/'));
    $uni_js = esc_js($uni);
    ?>
    <script id="sode-global-keys-engine">
        (function () {
            'use strict';
            var uniSlug = '<?php echo $uni_js; ?>';
            var adminRoot = '<?php echo $admin_root_js; ?>';
            var apiUrl = '<?php echo $api_base; ?>?t=' + Date.now() + (uniSlug ? '&uni=' + encodeURIComponent(uniSlug) : '');
            // Fetch fresh global keys — no browser cache
            fetch(apiUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache' }
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var keys = data.keys || {};
                    var centralFavicon = data.favicon_url || keys['$FAVICON_URL$'] || keys['{FAVICON_URL}'] || keys['$SITE_FAVICON$'] || keys['{SITE_FAVICON}'] || data.site_logo_url || keys['$SITE_LOGO$'] || keys['$LOGO_URL$'] || '';

                    // Normalize favicon URL
                    if (centralFavicon && !centralFavicon.match(/^https?:\/\//i)) {
                        centralFavicon = adminRoot + '/' + centralFavicon.replace(/^\/+/, '');
                    }

                    // 1. Force Central Favicon Overwrite in Browser DOM
                    if (centralFavicon) {
                        try {
                            var existingIcons = document.querySelectorAll("link[rel*='icon'], link[rel='apple-touch-icon']");
                            existingIcons.forEach(function(el) {
                                if (el.parentNode) el.parentNode.removeChild(el);
                            });

                            var linkIcon = document.createElement('link');
                            linkIcon.rel = 'icon';
                            linkIcon.href = centralFavicon;
                            document.head.appendChild(linkIcon);

                            var linkShortcut = document.createElement('link');
                            linkShortcut.rel = 'shortcut icon';
                            linkShortcut.href = centralFavicon;
                            document.head.appendChild(linkShortcut);

                            var linkApple = document.createElement('link');
                            linkApple.rel = 'apple-touch-icon';
                            linkApple.href = centralFavicon;
                            document.head.appendChild(linkApple);
                        } catch (e) {}
                    }

                    if (!Object.keys(keys).length) return;

                    // Build a flat map of ALL pattern variants → value
                    var replacements = {};
                    Object.entries(keys).forEach(function ([code, val]) {
                        var raw = code.replace(/^\$|\$$/g, '');
                        [
                            code,
                            '$' + raw.toUpperCase() + '$',
                            '$' + raw.toLowerCase() + '$',
                            '{{' + raw + '}}',
                            '{{' + raw.toUpperCase() + '}}',
                            '{{' + raw.toLowerCase() + '}}',
                            '{' + raw + '}',
                            '{' + raw.toUpperCase() + '}',
                            '{' + raw.toLowerCase() + '}',
                        ].forEach(function (p) { replacements[p] = String(val); });
                    });

                    var patterns = Object.keys(replacements);
                    if (!patterns.length) return;

                    // Walk all text nodes in the DOM and replace
                    function walk(node) {
                        if (!node) return;
                        if (node.nodeType === 3) { // TEXT_NODE
                            var t = node.textContent;
                            var changed = false;
                            patterns.forEach(function (p) {
                                if (t.indexOf(p) !== -1) {
                                    t = t.split(p).join(replacements[p]);
                                    changed = true;
                                }
                            });
                            if (changed) node.textContent = t;
                        } else if (node.nodeType === 1) {
                            var tag = node.tagName;
                            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') return;
                            // Replace placeholder in attribute values (href, title, alt, placeholder, etc.)
                            ['href', 'title', 'alt', 'placeholder', 'data-title', 'content'].forEach(function (attr) {
                                if (node.hasAttribute(attr)) {
                                    var a = node.getAttribute(attr);
                                    var ac = false;
                                    patterns.forEach(function (p) {
                                        if (a.indexOf(p) !== -1) { a = a.split(p).join(replacements[p]); ac = true; }
                                    });
                                    if (ac) node.setAttribute(attr, a);
                                }
                            });
                            node.childNodes.forEach(walk);
                        }
                    }

                    // Run immediately on current DOM
                    if (document.body) walk(document.body);

                    // Also observe future DOM changes (Elementor animations, AJAX loads)
                    if (window.MutationObserver) {
                        var observer = new MutationObserver(function (mutations) {
                            mutations.forEach(function (m) {
                                m.addedNodes.forEach(walk);
                            });
                        });
                        observer.observe(document.body, { childList: true, subtree: true });
                    }
                })
                .catch(function () { }); // Silent fail — PHP output buffer is the fallback
        })();
    </script>
    <?php
}, 999);

// ====================================================
// 🧹 COMPREHENSIVE CACHE FLUSH HANDLER
// Visit /?sode_flush=sode_flush_2026
// Clears: Redis object cache, WP Rocket page cache,
//         Elementor CSS cache, WordPress transients
// ====================================================
add_action('init', function () {
    $token = $_GET['sode_flush'] ?? '';
    if ($token !== 'sode_flush_2026')
        return;

    $cleared = [];

    // 1. WordPress Transients (DB + Redis)
    delete_transient('sode_global_keys_map');
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('sode_global_keys_map', 'transient');
        wp_cache_delete('_transient_sode_global_keys_map', 'options');
    }
    $cleared[] = 'transients';

    // 2. Redis Object Cache — flush entire object cache group
    if (function_exists('wp_cache_flush')) {
        // Only flush if it's a Redis/Memcached object cache (not persistent DB)
        if (defined('WP_REDIS_VERSION') || defined('WP_CACHE') && class_exists('Redis')) {
            wp_cache_flush();
            $cleared[] = 'redis';
        }
    }

    // 3. WP Rocket page cache
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
        $cleared[] = 'wp_rocket_domain';
    }
    if (function_exists('rocket_clean_post')) {
        /** @var \WP_Post|null $post */
        global $post;
        if ($post && isset($post->ID))
            rocket_clean_post($post->ID);
        $cleared[] = 'wp_rocket_post';
    }

    // 4. Elementor CSS & data cache
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance) && isset(\Elementor\Plugin::$instance->files_manager)) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        $cleared[] = 'elementor';
    }

    // 5. W3 Total Cache (if installed)
    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $cleared[] = 'w3tc';
    }

    // 6. WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $cleared[] = 'wp_super_cache';
    }

    wp_send_json_success([
        'message' => 'SODE Cache flushed',
        'cleared' => $cleared,
        'time' => time()
    ]);
    exit;
}, 1);

// ====================================================
// 🚀 WP ROCKET COMPATIBILITY & LAYOUT PROTECTION
// Prevents WP Rocket from breaking layouts or delaying critical scripts
// ====================================================
add_filter('rocket_delay_js_exclusions', function ($exclusions) {
    if (!is_array($exclusions)) $exclusions = [];
    $sode_exclusions = [
        'sode',
        'sode-global-keys-engine',
        'sf-ai-track',
        'sf-slider',
        'sf-cta',
        'lead-form',
        'edu_banner',
        'custom_lead_form',
        'disclaimer-main-popup',
        'privacy-main-popup',
        'term-main-popup'
    ];
    return array_unique(array_merge($exclusions, $sode_exclusions));
}, 99);

add_filter('rocket_exclude_js', function ($exclusions) {
    if (!is_array($exclusions)) $exclusions = [];
    $sode_exclusions = [
        'sode-global-keys-engine',
        'sode-universal-client'
    ];
    return array_unique(array_merge($exclusions, $sode_exclusions));
}, 99);

add_filter('rocket_rucss_safelist', function ($safelist) {
    if (!is_array($safelist)) $safelist = [];
    $sode_safelist = [
        'sode.*',
        'sf-.*',
        'edu-.*',
        'lead-.*',
        'uni-.*',
        'course-.*',
        'table-.*',
        '.*popup.*'
    ];
    return array_unique(array_merge($safelist, $sode_safelist));
}, 99);

add_filter('rocket_exclude_css', function ($exclusions) {
    if (!is_array($exclusions)) $exclusions = [];
    $exclusions[] = 'sode-universal-client';
    $exclusions[] = 'footer-universal';
    return array_unique($exclusions);
}, 99);


/**
 * Helper: Detect current university slug from subdomain or constant
 */
if (!function_exists('sode_client_detect_uni')) {
    function sode_client_detect_uni($explicit = '')
    {
        if (!empty($explicit)) {
            return sanitize_title($explicit);
        }
        if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
            return sanitize_title(SODE_UNIVERSITY_SLUG);
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
 * Helper: Fetch remote rendered component (100% Live & Real-Time, No Caching)
 */
if (!function_exists('sode_fetch_remote_component')) {
    function sode_fetch_remote_component($component, $args = [])
    {
        $explicit_uni = !empty($args['uni']) ? $args['uni'] : (!empty($args['university']) ? $args['university'] : '');
        $uni = sode_client_detect_uni($explicit_uni);
        $args['uni'] = $uni;
        $args['university'] = $uni;
        $args['component'] = $component;
        $args['_t'] = time(); // Live cache-buster

        $admin_url = rtrim(SODE_CENTRAL_ADMIN_URL, '/');

        // Primary endpoint: /admin/api/render_component.php (POST transmits long text/symbols cleanly)
        $primary_url = $admin_url . '/admin/api/render_component.php?_t=' . time();
        $resp = wp_remote_post($primary_url, [
            'body' => $args,
            'timeout' => 12,
            'sslverify' => false,
            'headers' => [
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache'
            ]
        ]);

        // Fallback endpoint: /api/render_component.php or GET fallback
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            $fallback_url = $admin_url . '/api/render_component.php?_t=' . time();
            $resp = wp_remote_post($fallback_url, [
                'body' => $args,
                'timeout' => 12,
                'sslverify' => false,
                'headers' => [
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache'
                ]
            ]);

            // Final fallback to GET if POST was blocked by security firewall
            if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
                $get_url = add_query_arg($args, $primary_url);
                $resp = wp_remote_get($get_url, [
                    'timeout' => 12,
                    'sslverify' => false,
                    'headers' => [
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache'
                    ]
                ]);
            }
        }

        if (is_wp_error($resp)) {
            /** @var \WP_Error $resp */
            $err_msg = method_exists($resp, 'get_error_message') ? $resp->get_error_message() : 'Connection Error';
            return '<!-- SODE Central SSR API Connection Error: ' . esc_html($err_msg) . ' -->';
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return '<!-- SODE Central SSR API HTTP Error: ' . esc_html($code) . ' -->';
        }

        $html = wp_remote_retrieve_body($resp);
        if (!empty($html)) {
            // Never display API fallback error message
            if (strpos($html, 'SODE Universal Component SSR API Online') !== false) {
                return '';
            }
        }
        return $html;
    }
}

// ====================================================
// 1. HERO BANNER SHORTCODE [edu_banner]
// ====================================================
add_shortcode('edu_banner', function ($atts) {
    $atts = shortcode_atts([
        'heading' => '',
        'subheading' => '',
        'description' => '',
        'top_heading' => '',
        'audio_url' => '',
        'video_url' => '',
        'university' => ''
    ], $atts, 'edu_banner');

    return sode_fetch_remote_component('banner', $atts);
});

// ====================================================
// 2. LEAD FORM SHORTCODES
// [custom_lead_form], [compare_universities_form], [brochure_download_form], [scholarship_coupon_form]
// ====================================================
add_shortcode('custom_lead_form', function ($atts) {
    $atts = shortcode_atts([
        'heading' => 'Book 100% Free Counseling',
        'subheading' => 'Get 1 to 1 Expert Guidance from SODE™',
        'form_name' => '',
        'button_text' => 'Submit',
        'university' => ''
    ], $atts, 'custom_lead_form');

    return sode_fetch_remote_component('lead_form', $atts);
});

add_shortcode('compare_universities_form', function ($atts) {
    $atts = shortcode_atts([
        'heading' => 'Compare Universities',
        'subheading' => 'Get expert help to compare universities',
        'form_name' => 'Compare Universities Form',
        'university' => ''
    ], $atts, 'compare_universities_form');

    return sode_fetch_remote_component('compare_form', $atts);
});

add_shortcode('brochure_download_form', function ($atts) {
    $atts = shortcode_atts([
        'heading' => 'Download Brochure',
        'subheading' => 'Fill the form to get your free brochure',
        'form_name' => 'Brochure Download Form',
        'university' => ''
    ], $atts, 'brochure_download_form');

    return sode_fetch_remote_component('brochure_form', $atts);
});

add_shortcode('scholarship_coupon_form', function ($atts) {
    $atts = shortcode_atts([
        'heading' => 'Get Scholarship Coupon Code',
        'subheading' => 'Claim your exclusive academic scholarship today!',
        'form_name' => 'Scholarship Coupon Form',
        'button_text' => 'Claim Scholarship Code',
        'university' => ''
    ], $atts, 'scholarship_coupon_form');

    return sode_fetch_remote_component('scholarship_form', $atts);
});

// ====================================================
// 2.4.2. COUNSELING LEAD FORM CARD & CTA BUTTON SHORTCODES
// [counseling_lead_form], [counseling_form], [lead_form_box], [sode_lead_form]
// [counseling_button], [counseling_btn], [apply_now_button], [free_counseling_button]
// ====================================================
$counseling_lead_form_handler = function ($atts) {
    if (function_exists('sode_counseling_lead_form_box_shortcode')) {
        return sode_counseling_lead_form_box_shortcode($atts ?: []);
    }
    return sode_fetch_remote_component('counseling_lead_form', $atts ?: []);
};
add_shortcode('counseling_lead_form', $counseling_lead_form_handler);
add_shortcode('counseling_form', $counseling_lead_form_handler);
add_shortcode('lead_form_box', $counseling_lead_form_handler);
add_shortcode('sode_lead_form', $counseling_lead_form_handler);

$counseling_btn_handler = function ($atts) {
    if (function_exists('sode_counseling_button_shortcode')) {
        return sode_counseling_button_shortcode($atts ?: []);
    }
    return sode_fetch_remote_component('counseling_button', $atts ?: []);
};
add_shortcode('counseling_button', $counseling_btn_handler);
add_shortcode('counseling_btn', $counseling_btn_handler);
add_shortcode('apply_now_button', $counseling_btn_handler);
add_shortcode('free_counseling_button', $counseling_btn_handler);
add_shortcode('applynow_button', $counseling_btn_handler);
add_shortcode('applynow_btn', $counseling_btn_handler);

// ====================================================
// 2.5. LATEST NEWS & MARQUEE SHORTCODES
// [latest_news], [universal_news], [sode_news]
// ====================================================
add_shortcode('latest_news', function ($atts) {
    if (function_exists('sode_news_marquee_render')) {
        return sode_news_marquee_render($atts);
    }
    $atts = shortcode_atts([
        'university' => '',
        'heading' => 'Latest News',
        'speed' => '16s',
        'height' => '240px',
        'limit' => 10
    ], $atts, 'latest_news');

    return sode_fetch_remote_component('latest_news', $atts);
});

add_shortcode('universal_news', function ($atts) {
    if (function_exists('sode_news_marquee_render')) {
        return sode_news_marquee_render($atts);
    }
    return sode_fetch_remote_component('latest_news', $atts);
});

add_shortcode('sode_news', function ($atts) {
    if (function_exists('sode_news_marquee_render')) {
        return sode_news_marquee_render($atts);
    }
    return sode_fetch_remote_component('latest_news', $atts);
});

// ====================================================
// 2.7. UNIVERSITY COURSES & MAPPINGS SHORTCODES
// [university_courses], [uni_courses], [sode_courses]
// [university_courses_list], [uni_courses_list], [courses_list]
// ====================================================
add_shortcode('university_courses', function ($atts) {
    if (function_exists('sode_courses_tabs_render')) {
        return sode_courses_tabs_render($atts);
    }
    return sode_fetch_remote_component('university_courses', $atts);
});

add_shortcode('uni_courses', function ($atts) {
    if (function_exists('sode_courses_tabs_render')) {
        return sode_courses_tabs_render($atts);
    }
    return sode_fetch_remote_component('university_courses', $atts);
});

add_shortcode('sode_courses', function ($atts) {
    if (function_exists('sode_courses_tabs_render')) {
        return sode_courses_tabs_render($atts);
    }
    return sode_fetch_remote_component('university_courses', $atts);
});

add_shortcode('university_courses_list', function ($atts) {
    if (function_exists('sode_courses_list_render')) {
        return sode_courses_list_render($atts);
    }
    return sode_fetch_remote_component('university_courses_list', $atts);
});

add_shortcode('uni_courses_list', function ($atts) {
    if (function_exists('sode_courses_list_render')) {
        return sode_courses_list_render($atts);
    }
    return sode_fetch_remote_component('university_courses_list', $atts);
});

add_shortcode('courses_list', function ($atts) {
    if (function_exists('sode_courses_list_render')) {
        return sode_courses_list_render($atts);
    }
    return sode_fetch_remote_component('university_courses_list', $atts);
});

// ====================================================
// 2.8. UNIVERSITY COURSES ELIGIBILITY TABLE SHORTCODES
// [university_eligibility_table], [uni_eligibility_table], [eligibility_table], [courses_eligibility_table], [university_eligibility]
// ====================================================
add_shortcode('university_eligibility_table', function ($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('uni_eligibility_table', function ($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('eligibility_table', function ($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('courses_eligibility_table', function ($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('university_eligibility', function ($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('uni_eligibility', function ($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

// ====================================================
// 2.8.1. UNIVERSITY PROGRAMS & FEE TABLE SHORTCODES
// [university_programs_table], [uni_programs_table], [programs_table], [university_fees_table], [uni_fees_table], [programs_fee_table], [university_courses_table], [uni_courses_table]
// ====================================================
$prog_table_handler = function ($atts) {
    if (function_exists('sode_university_programs_table_render')) {
        return sode_university_programs_table_render($atts);
    }
    return sode_fetch_remote_component('university_programs_table', $atts);
};
add_shortcode('university_programs_table', $prog_table_handler);
add_shortcode('uni_programs_table', $prog_table_handler);
add_shortcode('programs_table', $prog_table_handler);
add_shortcode('university_fees_table', $prog_table_handler);
add_shortcode('uni_fees_table', $prog_table_handler);
add_shortcode('programs_fee_table', $prog_table_handler);
add_shortcode('university_courses_table', $prog_table_handler);
add_shortcode('uni_courses_table', $prog_table_handler);

// ====================================================
// 2.8.2. UNIVERSITY COURSES ELIGIBILITY TEXT SHORTCODES
// [university_eligibility_text], [uni_eligibility_text], [courses_eligibility_text], [eligibility_text], [course_eligibility]
// ====================================================
$elig_text_handler = function ($atts) {
    if (function_exists('sode_courses_eligibility_text_render')) {
        return sode_courses_eligibility_text_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_text', $atts);
};
add_shortcode('university_eligibility_text', $elig_text_handler);
add_shortcode('uni_eligibility_text', $elig_text_handler);
add_shortcode('courses_eligibility_text', $elig_text_handler);
add_shortcode('eligibility_text', $elig_text_handler);
add_shortcode('course_eligibility', $elig_text_handler);
add_shortcode('course_eligibility_text', $elig_text_handler);

add_shortcode('mba_eligibility', function ($atts) use ($elig_text_handler) { return $elig_text_handler(array_merge((array)$atts, ['course' => 'mba'])); });
add_shortcode('mca_eligibility', function ($atts) use ($elig_text_handler) { return $elig_text_handler(array_merge((array)$atts, ['course' => 'mca'])); });
add_shortcode('bba_eligibility', function ($atts) use ($elig_text_handler) { return $elig_text_handler(array_merge((array)$atts, ['course' => 'bba'])); });
add_shortcode('bca_eligibility', function ($atts) use ($elig_text_handler) { return $elig_text_handler(array_merge((array)$atts, ['course' => 'bca'])); });
add_shortcode('bcom_eligibility', function ($atts) use ($elig_text_handler) { return $elig_text_handler(array_merge((array)$atts, ['course' => 'bcom'])); });

// ====================================================
// 2.8.3. RECENT ANNOUNCEMENTS / INNER PAGE NEWS SHORTCODES
// [recent_announcements], [university_announcements], [uni_announcements], [announcements_list], [inner_page_news]
// ====================================================
if (file_exists(__DIR__ . '/announcements-list-universal.php')) {
    include_once __DIR__ . '/announcements-list-universal.php';
}

$announcements_handler = function ($atts) {
    if (function_exists('sode_announcements_list_render')) {
        return sode_announcements_list_render($atts ?: []);
    }
    return sode_fetch_remote_component('recent_announcements', $atts ?: []);
};
add_shortcode('recent_announcements', $announcements_handler);
add_shortcode('university_announcements', $announcements_handler);
add_shortcode('uni_announcements', $announcements_handler);
add_shortcode('announcements_list', $announcements_handler);
add_shortcode('inner_page_news', $announcements_handler);

// ====================================================
// 2.8.4. UNIVERSITY IMPORTANT DATES & DEADLINES TABLE SHORTCODES
// [university_dates], [important_dates], [uni_important_dates], [admission_dates], [dates_table], [university_dates_table]
// ====================================================
if (file_exists(__DIR__ . '/university-dates-table-universal.php')) {
    include_once __DIR__ . '/university-dates-table-universal.php';
}

$dates_table_handler = function ($atts) {
    if (function_exists('sode_university_dates_table_render')) {
        return sode_university_dates_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('university_dates', $atts ?: []);
};
add_shortcode('university_dates', $dates_table_handler);
add_shortcode('important_dates', $dates_table_handler);
add_shortcode('uni_important_dates', $dates_table_handler);
add_shortcode('admission_dates', $dates_table_handler);
add_shortcode('dates_table', $dates_table_handler);
add_shortcode('university_dates_table', $dates_table_handler);

// Individual Date Shortcodes
add_shortcode('admission_last_date', function ($atts) {
    if (function_exists('sode_get_university_dates_data')) {
        $uni = sode_get_university_dates_data($atts['uni'] ?? ($atts['university'] ?? ''));
        return esc_html($uni['admission_last_date'] ?? '');
    }
    return '';
});
add_shortcode('admission_start_date', function ($atts) {
    if (function_exists('sode_get_university_dates_data')) {
        $uni = sode_get_university_dates_data($atts['uni'] ?? ($atts['university'] ?? ''));
        return esc_html($uni['admission_start_date'] ?? '');
    }
    return '';
});
add_shortcode('exam_date', function ($atts) {
    if (function_exists('sode_get_university_dates_data')) {
        $uni = sode_get_university_dates_data($atts['uni'] ?? ($atts['university'] ?? ''));
        return esc_html($uni['exam_date'] ?? '');
    }
    return '';
});
add_shortcode('extended_exam_date', function ($atts) {
    if (function_exists('sode_get_university_dates_data')) {
        $uni = sode_get_university_dates_data($atts['uni'] ?? ($atts['university'] ?? ''));
        return esc_html($uni['extended_exam_date'] ?? '');
    }
    return '';
});
add_shortcode('assignment_date', function ($atts) {
    if (function_exists('sode_get_university_dates_data')) {
        $uni = sode_get_university_dates_data($atts['uni'] ?? ($atts['university'] ?? ''));
        return esc_html($uni['assignment_date'] ?? '');
    }
    return '';
});


// ====================================================
// 2.9. UNIVERSITY ADMISSION & APPLICATION PROCESS SHORTCODES

// [university_application_process], [idol_application_process], [uni_application_process], [admission_process], [application_process]
// ====================================================
add_shortcode('university_application_process', function ($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('idol_application_process', function ($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('uni_application_process', function ($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('admission_process', function ($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('application_process', function ($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('sode_application_process', function ($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

// ====================================================
// 2.10. ALTERNATIVE UNIVERSITIES SHOWCASE SHORTCODES
// [alternative_universities], [alternate_universities], [alternatives_universities], [alternate_university_list], [alternative_university_list], [alternatives_list]
// ====================================================
if (file_exists(__DIR__ . '/alternate-universities-universal.php')) {
    include_once __DIR__ . '/alternate-universities-universal.php';
}

$alternate_unis_handler = function ($atts) {
    if (function_exists('sode_alternate_universities_render')) {
        return sode_alternate_universities_render($atts ?: []);
    }
    return sode_fetch_remote_component('alternate_universities', $atts ?: []);
};
add_shortcode('alternative_universities', $alternate_unis_handler);
add_shortcode('alternate_universities', $alternate_unis_handler);
add_shortcode('alternatives_universities', $alternate_unis_handler);
add_shortcode('alternate_university_list', $alternate_unis_handler);
add_shortcode('alternative_university_list', $alternate_unis_handler);
add_shortcode('alternatives_list', $alternate_unis_handler);

// ====================================================
// 2.10.1. UNIVERSITIES COURSE FEES MATRIX TABLE SHORTCODES
// [compare_universities_table], [universities_comparison_table], [compare_universities_fees], [universities_fee_comparison], [alternate_universities_fees], [alternate_universities_fees_table]
// ====================================================
if (file_exists(__DIR__ . '/university-fees-table-universal.php')) {
    include_once __DIR__ . '/university-fees-table-universal.php';
}

$uni_compare_fees_handler = function ($atts) {
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
    if (!empty($explicit_uni)) {
        $raw_atts['uni'] = $explicit_uni;
        $raw_atts['university'] = $explicit_uni;
    }

    if (function_exists('sode_render_university_fees_table')) {
        return sode_render_university_fees_table($raw_atts);
    }
    // Live Central Server handles 'subdomain_fees_table' and 'alternate_universities_fees_table'
    $res = sode_fetch_remote_component('subdomain_fees_table', $raw_atts);
    if (empty($res)) {
        $res = sode_fetch_remote_component('alternate_universities_fees_table', $raw_atts);
    }
    if (empty($res)) {
        $res = sode_fetch_remote_component('compare_universities_table', $raw_atts);
    }
    return $res;
};
add_shortcode('compare_universities_table', $uni_compare_fees_handler);
add_shortcode('universities_comparison_table', $uni_compare_fees_handler);
add_shortcode('compare_universities_fees', $uni_compare_fees_handler);
add_shortcode('universities_fee_comparison', $uni_compare_fees_handler);
add_shortcode('alternate_universities_fees', $uni_compare_fees_handler);
add_shortcode('alternate_universities_fees_table', $uni_compare_fees_handler);
add_shortcode('subdomain_fees_table', $uni_compare_fees_handler);
add_shortcode('subdomain_course_fees_table', $uni_compare_fees_handler);

// ====================================================
// 2.10.2. UNIVERSITIES PROGRAMMES TABLE (UGC-DEB APPROVED) SHORTCODES
// [subdomain_programmes_table], [university_programmes_table], [uni_programmes_table], [ugc_deb_approved_courses_table]
// ====================================================
if (file_exists(__DIR__ . '/university-programmes-table-universal.php')) {
    include_once __DIR__ . '/university-programmes-table-universal.php';
}

$uni_programmes_table_handler = function ($atts) {
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
    if (!empty($explicit_uni)) {
        $raw_atts['uni'] = $explicit_uni;
        $raw_atts['university'] = $explicit_uni;
    }

    if (function_exists('sode_render_university_programmes_table')) {
        return sode_render_university_programmes_table($raw_atts);
    }
    $res = sode_fetch_remote_component('subdomain_programmes_table', $raw_atts);
    if (empty($res)) {
        $res = sode_fetch_remote_component('university_programmes_table', $raw_atts);
    }
    return $res;
};
add_shortcode('subdomain_programmes_table', $uni_programmes_table_handler);
add_shortcode('university_programmes_table', $uni_programmes_table_handler);
add_shortcode('uni_programmes_table', $uni_programmes_table_handler);
add_shortcode('ugc_deb_approved_courses_table', $uni_programmes_table_handler);
add_shortcode('university_ugc_courses_table', $uni_programmes_table_handler);

// ====================================================
// 3. GLOBAL YEAR SHORTCODE [site_year]
// ====================================================

add_shortcode('site_year', function ($atts) {
    return date('Y');
});

// ====================================================
// 3.5. UNIVERSAL FOOTER SHORTCODES
// [footer_section], [universal_footer], [sode_footer]
// ====================================================
add_shortcode('footer_section', function ($atts) {
    if (function_exists('sode_footer_render')) {
        return sode_footer_render($atts ?: []);
    }
    return sode_fetch_remote_component('footer', $atts ?: []);
});

add_shortcode('universal_footer', function ($atts) {
    if (function_exists('sode_footer_render')) {
        return sode_footer_render($atts ?: []);
    }
    return sode_fetch_remote_component('footer', $atts ?: []);
});

add_shortcode('sode_footer', function ($atts) {
    if (function_exists('sode_footer_render')) {
        return sode_footer_render($atts ?: []);
    }
    return sode_fetch_remote_component('footer', $atts ?: []);
});

// ====================================================
// 2.12. COURSE SYLLABUS TABLE SHORTCODES
// [course_syllabus], [syllabus], [university_course_syllabus], [uni_course_syllabus]
// Usage: [course_syllabus course="mba" mode="online"]
// ====================================================
add_shortcode('course_syllabus', function ($atts) {
    if (function_exists('sode_course_syllabus_render')) {
        return sode_course_syllabus_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_syllabus', $atts ?: []);
});

add_shortcode('syllabus', function ($atts) {
    if (function_exists('sode_course_syllabus_render')) {
        return sode_course_syllabus_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_syllabus', $atts ?: []);
});

add_shortcode('university_course_syllabus', function ($atts) {
    if (function_exists('sode_course_syllabus_render')) {
        return sode_course_syllabus_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_syllabus', $atts ?: []);
});

add_shortcode('uni_course_syllabus', function ($atts) {
    if (function_exists('sode_course_syllabus_render')) {
        return sode_course_syllabus_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_syllabus', $atts ?: []);
});

// ====================================================
// 2.13. COURSE FEES TABLE SHORTCODES
// [course_fees], [course_fee_table], [course_fee], [fee_structure], [university_course_fees], [uni_course_fees]
// Usage: [course_fees course="mba" mode="online"]
// ====================================================
if (file_exists(__DIR__ . '/course-fees-universal.php')) {
    require_once __DIR__ . '/course-fees-universal.php';
}

add_shortcode('course_fees', function ($atts) {
    if (function_exists('sode_course_fees_render')) {
        return sode_course_fees_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_fees', $atts ?: []);
});

add_shortcode('course_fee_table', function ($atts) {
    if (function_exists('sode_course_fees_render')) {
        return sode_course_fees_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_fees', $atts ?: []);
});

add_shortcode('course_fee', function ($atts) {
    if (function_exists('sode_course_fees_render')) {
        return sode_course_fees_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_fees', $atts ?: []);
});

add_shortcode('fee_structure', function ($atts) {
    if (function_exists('sode_course_fees_render')) {
        return sode_course_fees_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_fees', $atts ?: []);
});

add_shortcode('university_course_fees', function ($atts) {
    if (function_exists('sode_course_fees_render')) {
        return sode_course_fees_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_fees', $atts ?: []);
});

add_shortcode('uni_course_fees', function ($atts) {
    if (function_exists('sode_course_fees_render')) {
        return sode_course_fees_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_fees', $atts ?: []);
});

// ====================================================
// 2.14. COURSE SPECIALIZATIONS TABLE SHORTCODES
// [course_specializations], [course_specialization_table], [course_specialization], [specializations_table], [university_course_specializations], [uni_course_specializations]
// Usage: [course_specializations course="mba" mode="online"]
// ====================================================
if (file_exists(__DIR__ . '/course-specializations-universal.php')) {
    require_once __DIR__ . '/course-specializations-universal.php';
}

add_shortcode('course_specializations', function ($atts) {
    if (function_exists('sode_course_specializations_render')) {
        return sode_course_specializations_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_specializations', $atts ?: []);
});

add_shortcode('course_specialization_table', function ($atts) {
    if (function_exists('sode_course_specializations_render')) {
        return sode_course_specializations_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_specializations', $atts ?: []);
});

add_shortcode('course_specialization', function ($atts) {
    if (function_exists('sode_course_specializations_render')) {
        return sode_course_specializations_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_specializations', $atts ?: []);
});

add_shortcode('specializations_table', function ($atts) {
    if (function_exists('sode_course_specializations_render')) {
        return sode_course_specializations_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_specializations', $atts ?: []);
});

add_shortcode('university_course_specializations', function ($atts) {
    if (function_exists('sode_course_specializations_render')) {
        return sode_course_specializations_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_specializations', $atts ?: []);
});

add_shortcode('uni_course_specializations', function ($atts) {
    if (function_exists('sode_course_specializations_render')) {
        return sode_course_specializations_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_specializations', $atts ?: []);
});

add_shortcode('job_roles_table', function ($atts) {
    if (function_exists('sode_job_roles_table_render')) {
        return sode_job_roles_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('job_roles_table', $atts ?: []);
});

add_shortcode('job_roles', function ($atts) {
    if (function_exists('sode_job_roles_table_render')) {
        return sode_job_roles_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('job_roles_table', $atts ?: []);
});

add_shortcode('course_job_roles', function ($atts) {
    if (function_exists('sode_job_roles_table_render')) {
        return sode_job_roles_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('job_roles_table', $atts ?: []);
});

add_shortcode('job_roles_salary', function ($atts) {
    if (function_exists('sode_job_roles_table_render')) {
        return sode_job_roles_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('job_roles_table', $atts ?: []);
});

add_shortcode('course_table', function ($atts) {
    if (function_exists('sode_course_universities_table_render')) {
        return sode_course_universities_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_table', $atts ?: []);
});

add_shortcode('universities_table', function ($atts) {
    if (function_exists('sode_course_universities_table_render')) {
        return sode_course_universities_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_table', $atts ?: []);
});

add_shortcode('course_universities', function ($atts) {
    if (function_exists('sode_course_universities_table_render')) {
        return sode_course_universities_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_table', $atts ?: []);
});

add_shortcode('top_universities', function ($atts) {
    if (function_exists('sode_course_universities_table_render')) {
        return sode_course_universities_table_render($atts ?: []);
    }
    return sode_fetch_remote_component('course_table', $atts ?: []);
});



// ====================================================
// 4. SECURITY TOKEN & AJAX LEAD HANDLER
// ====================================================
add_action('init', function () {
    if (isset($_POST['action']) && $_POST['action'] === 'send_lead') {
        $token = $_SERVER['HTTP_X_LEAD_TOKEN'] ?? '';
        if (!$token || !get_transient('lead_token_' . $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'data' => 'Forbidden - Invalid Token']);
            exit();
        }
    }
});

add_action('wp_ajax_get_lead_token', 'sode_client_generate_token');
add_action('wp_ajax_nopriv_get_lead_token', 'sode_client_generate_token');
function sode_client_generate_token()
{
    $token = wp_generate_uuid4();
    set_transient('lead_token_' . $token, true, DAY_IN_SECONDS);
    wp_send_json_success($token);
}

// Multi-Endpoint Lead Sender
add_action('wp_ajax_send_lead', 'sode_client_send_lead');
add_action('wp_ajax_nopriv_send_lead', 'sode_client_send_lead');
function sode_client_send_lead()
{
    $token = $_SERVER['HTTP_X_LEAD_TOKEN'] ?? '';
    delete_transient('lead_token_' . $token);

    if (!empty($_POST['website'])) {
        wp_send_json_error('Spam detected');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rate_key = 'sode_rate_' . md5($ip);
    if (get_transient($rate_key)) {
        wp_send_json_error('Too many requests. Please wait.');
    }
    set_transient($rate_key, true, 30);

    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    if (strlen($phone) < 10)
        wp_send_json_error('Invalid phone number');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        wp_send_json_error('Invalid email address');

    $uni_slug = sanitize_title($_POST['uni_slug'] ?? sode_client_detect_uni());

    // Fetch dynamic university & API config from Central Admin
    $api_url = add_query_arg(['uni' => $uni_slug], SODE_CENTRAL_ADMIN_URL . '/api/get_form_config.php');
    $cfg_resp = wp_remote_get($api_url, ['timeout' => 8]);
    $cfg = [];
    if (!is_wp_error($cfg_resp)) {
        $cfg = json_decode(wp_remote_retrieve_body($cfg_resp), true) ?: [];
    }

    $crm_url = $cfg['crm_api_url'] ?? 'https://api.crm.mysode.com/api/lead/apicreated';
    $crm_key = $cfg['crm_api_key'] ?? '';
    $crm_secret = $cfg['crm_secret'] ?? '';
    $brevo_url = $cfg['brevo_api_url'] ?? 'https://api.brevo.com/v3/contacts';
    $brevo_key = $cfg['brevo_api_key'] ?? '';
    $brevo_list_id = (int) ($cfg['brevo_list_id'] ?? 124);
    $brevo_source = $cfg['brevo_source'] ?? 'MISC';
    $gallabox_url = $cfg['gallabox_webhook_url'] ?? '';
    $gallabox_src = $cfg['gallabox_source'] ?? 'MISC';

    $name = sanitize_text_field($_POST['name'] ?? '');
    $course = sanitize_text_field($_POST['course'] ?? '');
    $state = sanitize_text_field($_POST['state'] ?? '');
    $form_name = sanitize_text_field($_POST['form_name'] ?? 'Lead Form');
    $source = sanitize_text_field($_POST['source'] ?? ($cfg['source'] ?? 'MISC'));
    $utm_source = sanitize_text_field($_POST['utm_source'] ?? ($cfg['default_utm_source'] ?? 'Organic'));
    $utm_medium = sanitize_text_field($_POST['utm_medium'] ?? ($cfg['default_utm_medium'] ?? 'Direct'));
    $utm_campaign = sanitize_text_field($_POST['utm_campaign'] ?? ($cfg['default_utm_campaign'] ?? 'Universal'));
    $utm_term = sanitize_text_field($_POST['utm_term'] ?? '');
    $utm_content = sanitize_text_field($_POST['utm_content'] ?? '');
    $page_url = esc_url_raw($_POST['page_url'] ?? '');

    // 1. CRM Lead Dispatch
    $crm_data = [
        "name" => $name,
        "email" => $email,
        "phone" => $phone,
        "course" => $course,
        "state" => $state,
        "form_name" => $form_name,
        "source" => $source,
        "utm_source" => $utm_source,
        "utm_medium" => $utm_medium,
        "utm_campaign" => $utm_campaign,
        "utm_term" => $utm_term,
        "utm_content" => $utm_content,
        "page_url" => $page_url
    ];
    if ($crm_key && $crm_secret) {
        wp_remote_post($crm_url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'x-api-key' => $crm_key, 'secret' => $crm_secret],
            'body' => json_encode($crm_data)
        ]);
    }

    // 2. Brevo Dispatch
    if ($brevo_key) {
        $brevo_data = [
            "email" => $email,
            "listIds" => [$brevo_list_id],
            "attributes" => [
                "FULLNAME" => $name,
                "MOBILE" => $phone,
                "COURSES" => $course,
                "STATES" => $state,
                "UTM_SOURCE" => $utm_source,
                "UTM_CAMPAIGN" => $utm_campaign,
                "UTM_MEDIUM" => $utm_medium,
                "UTM_TERM" => $utm_term,
                "SOURCE" => $brevo_source
            ],
            "updateEnabled" => true
        ];
        wp_remote_post($brevo_url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'api-key' => $brevo_key],
            'body' => json_encode($brevo_data)
        ]);
    }

    // 3. Gallabox Dispatch
    if ($gallabox_url) {
        $galla_data = [
            "name" => $name,
            "phone" => (str_starts_with($phone, '+') ? $phone : '+' . $phone),
            "email" => $email,
            "course" => $course,
            "state" => $state,
            "source" => $gallabox_src,
            "utm_source" => $utm_source,
            "utm_medium" => $utm_medium,
            "utm_campaign" => $utm_campaign,
            "utm_term" => $utm_term,
            "utm_content" => $utm_content
        ];
        wp_remote_post($gallabox_url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($galla_data)
        ]);
    }

    wp_send_json_success('Lead Sent Successfully');
}

// ====================================================
// 5. GLOBAL FOOTER: POPUP MODALS & JS HANDLER
// ====================================================
add_action('wp_footer', function () {
    // 1. Output Legal Popups (Disclaimer, Privacy Policy, Terms) from Central Admin
    if (function_exists('sode_legal_popups_render')) {
        echo sode_legal_popups_render();
    } else {
        echo sode_fetch_remote_component('legal_popups');
    }

    // 2. Output Modals from Central Admin
    echo sode_fetch_remote_component('popup_modals');

    // 3. Output Gallabox WhatsApp Floating Widget from Central Admin
    if (function_exists('sode_gallabox_widget_render')) {
        echo sode_gallabox_widget_render(['uni' => sode_client_detect_uni()]);
    } else {
        echo sode_fetch_remote_component('gallabox_widget', ['uni' => sode_client_detect_uni()]);
    }

    // 4. Output Unified Form & Popup Client JS
    ?>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // Load Counseling, Compare & Brochure Form contents on-demand
            const counselingContent = document.getElementById('sode-counseling-modal-content');
            const compareContent = document.getElementById('sode-compare-modal-content');
            const brochureContent = document.getElementById('sode-brochure-modal-content');
            const scholarshipContent = document.getElementById('sode-scholarship-modal-content');

            // Fetch sub-forms asynchronously
            function loadModalForms() {
                if (counselingContent && !counselingContent.innerHTML.trim()) {
                    fetch('<?php echo SODE_CENTRAL_ADMIN_URL; ?>/api/render_component.php?component=custom_lead_form&uni=<?php echo sode_client_detect_uni(); ?>&form_name=Counseling+Popup+Form')
                        .then(r => r.text()).then(html => { counselingContent.innerHTML = html; });
                }
                if (compareContent && !compareContent.innerHTML.trim()) {
                    fetch('<?php echo SODE_CENTRAL_ADMIN_URL; ?>/api/render_component.php?component=compare_form&uni=<?php echo sode_client_detect_uni(); ?>')
                        .then(r => r.text()).then(html => { compareContent.innerHTML = html; });
                }
                if (brochureContent && !brochureContent.innerHTML.trim()) {
                    fetch('<?php echo SODE_CENTRAL_ADMIN_URL; ?>/api/render_component.php?component=brochure_form&uni=<?php echo sode_client_detect_uni(); ?>')
                        .then(r => r.text()).then(html => { brochureContent.innerHTML = html; });
                }
                if (scholarshipContent && !scholarshipContent.innerHTML.trim()) {
                    fetch('<?php echo SODE_CENTRAL_ADMIN_URL; ?>/api/render_component.php?component=scholarship_form&uni=<?php echo sode_client_detect_uni(); ?>')
                        .then(r => r.text()).then(html => { scholarshipContent.innerHTML = html; });
                }
            }
            loadModalForms();

            // Modal Triggers
            const counselingOverlay = document.getElementById('counselingFormPopupOverlay');
            const compareOverlay = document.getElementById('compareFormPopupOverlay');
            const brochureOverlay = document.getElementById('brochureFormPopupOverlay');

            document.addEventListener('click', function (e) {
                // APPLY NOW / COUNSELING FORM POPUP
                const applyBtn = e.target.closest('.applynow, .apply-now, .open-counseling-modal-btn, .open-counseling-form, .open-counseling-popup, a[href="#applynow"], a[href="#apply-now"]');
                if (applyBtn) {
                    e.preventDefault();
                    if (counselingOverlay) {
                        if (counselingContent && !counselingContent.innerHTML.trim()) {
                            loadModalForms();
                        }
                        const courseName = applyBtn.getAttribute('data-course');
                        if (courseName) {
                            const select = counselingOverlay.querySelector('select[name="course"]');
                            if (select) {
                                for (let i = 0; i < select.options.length; i++) {
                                    if (select.options[i].value.toLowerCase() === courseName.toLowerCase() || select.options[i].text.toLowerCase() === courseName.toLowerCase()) {
                                        select.selectedIndex = i;
                                        break;
                                    }
                                }
                            }
                        }
                        counselingOverlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                }
                if (e.target.closest('.counseling-popup-close') && counselingOverlay) {
                    counselingOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }

                // COMPARE FORM POPUP
                if (e.target.closest('.open-compare-form')) {
                    e.preventDefault();
                    if (compareOverlay) { compareOverlay.classList.add('active'); document.body.style.overflow = 'hidden'; }
                }

                // BROCHURE FORM POPUP
                if (e.target.closest('.open-brochure-form')) {
                    e.preventDefault();
                    if (brochureOverlay) {
                        if (brochureContent) brochureContent.style.display = 'block';
                        if (scholarshipContent) scholarshipContent.style.display = 'none';
                        brochureOverlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                }

                // SCHOLARSHIP FORM POPUP
                if (e.target.closest('.get-scholarship')) {
                    e.preventDefault();
                    if (brochureOverlay) {
                        if (brochureContent) brochureContent.style.display = 'none';
                        if (scholarshipContent) scholarshipContent.style.display = 'block';
                        brochureOverlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                }

                if (e.target.closest('.compare-popup-close') && compareOverlay) {
                    compareOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
                if (e.target.closest('.brochure-popup-close') && brochureOverlay) {
                    brochureOverlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });

            // Click outside backdrop to close
            [counselingOverlay, compareOverlay, brochureOverlay].forEach(function (ov) {
                if (ov) {
                    ov.addEventListener('click', function (e) {
                        if (e.target === ov) {
                            ov.classList.remove('active');
                            document.body.style.overflow = '';
                        }
                    });
                }
            });

            // Escape key closes any active modal
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    [counselingOverlay, compareOverlay, brochureOverlay].forEach(function (ov) {
                        if (ov && ov.classList.contains('active')) {
                            ov.classList.remove('active');
                            document.body.style.overflow = '';
                        }
                    });
                }
            });

            // One-time Token Management
            let sodeToken = "";
            function fetchToken() {
                fetch("/wp-admin/admin-ajax.php?action=get_lead_token")
                    .then(r => r.json()).then(r => { if (r.success) sodeToken = r.data; });
            }
            fetchToken();

            // UTM Helper
            function getUTMParam(p, def) {
                const sp = new URLSearchParams(window.location.search);
                return sp.get(p) || localStorage.getItem(p) || def || "";
            }
            ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content"].forEach(p => {
                const val = (new URLSearchParams(window.location.search)).get(p);
                if (val) localStorage.setItem(p, val);
            });

            // Universal Form Submit Handler
            document.addEventListener("submit", function (e) {
                if (!e.target.classList.contains("customLeadForm")) return;
                e.preventDefault();

                const form = e.target;
                const btn = form.querySelector(".submitBtn");
                if (btn.disabled) return;

                const countryCode = form.querySelector(".country_code") ? form.querySelector(".country_code").value : "91";
                const phoneValue = form.querySelector(".phone").value.trim();
                if (!/^[6-9]\d{9}$/.test(phoneValue)) {
                    alert("Please enter a valid 10-digit mobile number");
                    return;
                }

                btn.disabled = true;
                btn.innerText = "Submitting...";

                const formData = new FormData(form);
                formData.set("phone", countryCode + phoneValue);
                formData.append("action", "send_lead");
                formData.append("uni_slug", form.dataset.uniSlug || "<?php echo sode_client_detect_uni(); ?>");
                formData.append("form_name", form.dataset.formName || "Lead Form");
                formData.append("source", form.dataset.defaultSource || "MISC");
                formData.append("utm_source", getUTMParam("utm_source", form.dataset.defaultUtmSource || "Organic"));
                formData.append("utm_medium", getUTMParam("utm_medium", form.dataset.defaultUtmMedium || "Direct"));
                formData.append("utm_campaign", getUTMParam("utm_campaign", form.dataset.defaultUtmCampaign || "Universal"));
                formData.append("utm_term", getUTMParam("utm_term"));
                formData.append("utm_content", getUTMParam("utm_content"));
                formData.append("page_url", window.location.href);

                fetch("/wp-admin/admin-ajax.php", {
                    method: "POST",
                    headers: { "X-Lead-Token": sodeToken },
                    body: formData
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            if (form.dataset.formName && form.dataset.formName.includes("Compare")) {
                                window.open('https://distanceeducationschool.com/compare-university/', '_blank');
                            }
                            if (form.dataset.formName && form.dataset.formName.includes("Brochure")) {
                                window.location.href = "/thank-you?download_brochure=1";
                                return;
                            }
                            window.location.href = "/thank-you";
                        } else {
                            alert(res.data || "Submission failed. Please try again.");
                            btn.disabled = false;
                            btn.innerText = "Submit";
                            fetchToken();
                        }
                    })
                    .catch(() => {
                        alert("Network error. Please try again.");
                        btn.disabled = false;
                        btn.innerText = "Submit";
                    });
            });

            // Auto Download Brochure on Thank You Page
            if (new URLSearchParams(window.location.search).get('download_brochure') === '1') {
                fetch('<?php echo SODE_CENTRAL_ADMIN_URL; ?>/api/get_form_config.php?uni=<?php echo sode_client_detect_uni(); ?>')
                    .then(r => r.json())
                    .then(cfg => {
                        if (cfg.brochure_pdf_url) {
                            setTimeout(() => {
                                const a = document.createElement('a');
                                a.href = cfg.brochure_pdf_url;
                                a.target = '_blank';
                                document.body.appendChild(a);
                                a.click();
                                document.body.removeChild(a);
                            }, 1000);
                        }
                    });
                const cleanUrl = new URL(window.location);
                cleanUrl.searchParams.delete('download_brochure');
                window.history.replaceState({}, '', cleanUrl);
            }
        });
    </script>
    <?php
});

// ====================================================
// 8. GLOBAL KEYS & SITE YEAR DYNAMIC INTEGRATION
// ====================================================
if (file_exists(__DIR__ . '/site-year-universal.php')) {
    require_once __DIR__ . '/site-year-universal.php';
}

if (file_exists(__DIR__ . '/news-marquee-universal.php')) {
    require_once __DIR__ . '/news-marquee-universal.php';
}

if (file_exists(__DIR__ . '/courses-universal.php')) {
    require_once __DIR__ . '/courses-universal.php';
}

if (file_exists(__DIR__ . '/admission-process-universal.php')) {
    require_once __DIR__ . '/admission-process-universal.php';
}

if (file_exists(__DIR__ . '/legal-pages-universal.php')) {
    require_once __DIR__ . '/legal-pages-universal.php';
}

if (file_exists(__DIR__ . '/footer-universal.php')) {
    require_once __DIR__ . '/footer-universal.php';
}

if (file_exists(__DIR__ . '/course-syllabus-universal.php')) {
    require_once __DIR__ . '/course-syllabus-universal.php';
}

if (file_exists(__DIR__ . '/course-fees-universal.php')) {
    require_once __DIR__ . '/course-fees-universal.php';
}

if (file_exists(__DIR__ . '/course-specializations-universal.php')) {
    require_once __DIR__ . '/course-specializations-universal.php';
}

if (file_exists(__DIR__ . '/job-roles-table-universal.php')) {
    require_once __DIR__ . '/job-roles-table-universal.php';
}

if (file_exists(__DIR__ . '/course-fees-table-universal.php')) {
    require_once __DIR__ . '/course-fees-table-universal.php';
}

if (file_exists(__DIR__ . '/gallabox-widget-universal.php')) {
    require_once __DIR__ . '/gallabox-widget-universal.php';
}

if (file_exists(__DIR__ . '/university-dates-table-universal.php')) {
    require_once __DIR__ . '/university-dates-table-universal.php';
}

if (file_exists(__DIR__ . '/alternate-universities-universal.php')) {
    require_once __DIR__ . '/alternate-universities-universal.php';
}

if (file_exists(__DIR__ . '/university-fees-table-universal.php')) {
    require_once __DIR__ . '/university-fees-table-universal.php';
}