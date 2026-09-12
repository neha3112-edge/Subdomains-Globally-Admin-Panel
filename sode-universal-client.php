<?php
/**
 * Plugin Name: SODE Universal Remote Client
 * Plugin URI: https://admin.distanceeducationschool.com
 * Description: Lightweight 1-time drop-in client connected to SODE Central Admin Engine. All UI, CSS, JS, banner designs, and lead forms render dynamically from the central server.
 * Version: 4.0.0
 * Author: SODE Tech Team
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
    function sode_client_get_global_keys() {
        // Per-request memory only (no cross-request caching)
        // Fresh API call on every page load = always up-to-date values
        static $mem = null;
        if ($mem !== null) return $mem;

        // Detect university slug from subdomain or SODE_UNIVERSITY_SLUG constant
        $uni = '';
        if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
            $uni = sanitize_title(SODE_UNIVERSITY_SLUG);
        } else {
            $host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
            $parts = explode('.', $host);
            if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
                $uni = sanitize_title($parts[0]);
            }
        }

        $api_url = rtrim(SODE_CENTRAL_ADMIN_URL, '/') . '/api/get_global_keys.php?t=' . time();
        if ($uni) $api_url .= '&uni=' . urlencode($uni);

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
                '$YEAR$'             => $y,
                '$session$'          => $y . '-' . substr((string)((int)$y + 1), -2),
                '$nextyear$'         => (string)((int)$y + 1),
                '$BANNER_TOP_TEXT$'  => 'Welcome to SODE™ (School of Online and Distance Education)',
            ];
        }

        $mem = $keys;
        return $mem;
    }
}



if (!function_exists('sode_client_replace_keys')) {
    function sode_client_replace_keys($text) {
        if (!is_string($text) || empty($text)) return $text;

        // Quick bail — if none of the dollar signs or braces present
        if (strpos($text, '$') === false && strpos($text, '{') === false) return $text;

        $keys = sode_client_get_global_keys();
        if (empty($keys)) return $text;

        foreach ($keys as $code => $val) {
            $val = (string)$val;
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
        return $text;
    }
}

// Hook into every WordPress content filter so $YEAR$ etc.
// works in Elementor, Classic Editor, Widgets, Titles, Yoast SEO
add_filter('the_content',            'sode_client_replace_keys', 20);
add_filter('the_title',              'sode_client_replace_keys', 20);
add_filter('widget_text',            'sode_client_replace_keys', 20);
add_filter('widget_text_content',    'sode_client_replace_keys', 20);
add_filter('widget_block_content',   'sode_client_replace_keys', 20);
add_filter('get_the_excerpt',        'sode_client_replace_keys', 20);
add_filter('the_excerpt',            'sode_client_replace_keys', 20);

// Elementor dynamic content
add_filter('elementor/frontend/the_content',       'sode_client_replace_keys', 20);
add_filter('elementor/widget/render_content',      'sode_client_replace_keys', 20);

// Yoast SEO title & meta description
add_filter('wpseo_title',            'sode_client_replace_keys', 20);
add_filter('wpseo_metadesc',         'sode_client_replace_keys', 20);
add_filter('wpseo_opengraph_title',  'sode_client_replace_keys', 20);

// ACF & shortcode output
add_filter('acf/format_value',       'sode_client_replace_keys', 20);
add_filter('do_shortcode_tag',       'sode_client_replace_keys', 20);

// ====================================================
// 🔥 FULL PAGE OUTPUT BUFFER (PHP-level)
// Runs when WP Rocket/Redis cache MISS (first visit).
// For cache HITs, the JS engine below handles replacement.
// ====================================================
add_action('template_redirect', function() {
    ob_start(function($html) {
        if (empty($html) || !is_string($html)) return $html;
        if (strpos($html, '$') === false && strpos($html, '{') === false) return $html;
        return sode_client_replace_keys($html);
    });
}, 0);

// ====================================================
// ⚡ JS GLOBAL KEYS ENGINE (Client-Side)
// Injected into <head> — runs in browser AFTER page loads.
// Bypasses WP Rocket HTML cache, Redis, ALL server caches.
// Fetches fresh keys from Admin API every page load.
// Works even on WP Rocket cached pages!
// ====================================================
add_action('wp_head', function() {
    // Detect university slug for university-specific keys
    $uni = '';
    if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
        $uni = sanitize_title(SODE_UNIVERSITY_SLUG);
    } else {
        $host  = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $parts = explode('.', $host);
        if (count($parts) >= 3 && !in_array($parts[0], ['www','mail','admin','cpanel','webmail'])) {
            $uni = sanitize_title($parts[0]);
        }
    }
    $api_base = esc_js(rtrim(SODE_CENTRAL_ADMIN_URL, '/') . '/api/get_global_keys.php');
    $uni_js   = esc_js($uni);
    ?>
<script id="sode-global-keys-engine">
(function() {
    'use strict';
    var uniSlug = '<?php echo $uni_js; ?>';
    var apiUrl  = '<?php echo $api_base; ?>?t=' + Date.now() + (uniSlug ? '&uni=' + encodeURIComponent(uniSlug) : '');
    // Fetch fresh global keys — no browser cache
    fetch(apiUrl, {
        method: 'GET',
        cache: 'no-store',
        headers: { 'Cache-Control': 'no-cache' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var keys = data.keys || {};
        if (!Object.keys(keys).length) return;

        // Build a flat map of ALL pattern variants → value
        var replacements = {};
        Object.entries(keys).forEach(function([code, val]) {
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
            ].forEach(function(p) { replacements[p] = String(val); });
        });

        var patterns = Object.keys(replacements);
        if (!patterns.length) return;

        // Walk all text nodes in the DOM and replace
        function walk(node) {
            if (!node) return;
            if (node.nodeType === 3) { // TEXT_NODE
                var t = node.textContent;
                var changed = false;
                patterns.forEach(function(p) {
                    if (t.indexOf(p) !== -1) {
                        t = t.split(p).join(replacements[p]);
                        changed = true;
                    }
                });
                if (changed) node.textContent = t;
            } else if (node.nodeType === 1) {
                var tag = node.tagName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') return;
                // Replace placeholder in attribute values (title, alt, placeholder, etc.)
                ['title','alt','placeholder','data-title','content'].forEach(function(attr) {
                    if (node.hasAttribute(attr)) {
                        var a = node.getAttribute(attr);
                        var ac = false;
                        patterns.forEach(function(p) {
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
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(walk);
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    })
    .catch(function() {}); // Silent fail — PHP output buffer is the fallback
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
add_action('init', function() {
    $token = $_GET['sode_flush'] ?? '';
    if ($token !== 'sode_flush_2026') return;

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
        global $post;
        if ($post) rocket_clean_post($post->ID);
        $cleared[] = 'wp_rocket_post';
    }

    // 4. Elementor CSS & data cache
    if (class_exists('\Elementor\Plugin')) {
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
        'time'    => time()
    ]);
    exit;
}, 1);


/**
 * Helper: Detect current university slug from subdomain or constant
 */
if (!function_exists('sode_client_detect_uni')) {
    function sode_client_detect_uni($explicit = '') {
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
 * Helper: Fetch remote rendered component with high-speed transient caching
 */
if (!function_exists('sode_fetch_remote_component')) {
    function sode_fetch_remote_component($component, $args = []) {
        $uni = sode_client_detect_uni($args['university'] ?? '');
        $args['uni'] = $uni;
        $args['component'] = $component;

        // Build unique cache key
        $cache_key = 'sode_ssr_' . md5($component . '_' . $uni . '_' . serialize($args));
        
        // Check if admin is previewing or cache bypass requested (including Elementor editor)
        $bypass_cache = isset($_GET['nocache']) || 
                        isset($_GET['preview']) || 
                        isset($_GET['elementor-preview']) || 
                        (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts'));

        if (!$bypass_cache) {
            $cached_html = get_transient($cache_key);
            if ($cached_html !== false && !empty($cached_html)) {
                return $cached_html;
            }
        }

        $admin_url = rtrim(SODE_CENTRAL_ADMIN_URL, '/');
        
        // Primary endpoint: /admin/api/render_component.php (POST transmits long text/symbols cleanly)
        $primary_url = $admin_url . '/admin/api/render_component.php';
        $resp = wp_remote_post($primary_url, [
            'body'      => $args,
            'timeout'   => 12,
            'sslverify' => false,
            'headers'   => ['Cache-Control' => 'no-cache']
        ]);

        // Fallback endpoint: /api/render_component.php or GET fallback
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            $fallback_url = $admin_url . '/api/render_component.php';
            $resp = wp_remote_post($fallback_url, [
                'body'      => $args,
                'timeout'   => 12,
                'sslverify' => false,
                'headers'   => ['Cache-Control' => 'no-cache']
            ]);

            // Final fallback to GET if POST was blocked by security firewall
            if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
                $get_url = add_query_arg($args, $primary_url);
                $resp = wp_remote_get($get_url, [
                    'timeout'   => 12,
                    'sslverify' => false,
                    'headers'   => ['Cache-Control' => 'no-cache']
                ]);
            }
        }

        if (is_wp_error($resp)) {
            return '<!-- SODE Central SSR API Connection Error: ' . esc_html($resp->get_error_message()) . ' -->';
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return '<!-- SODE Central SSR API HTTP Error: ' . esc_html($code) . ' -->';
        }

        $html = wp_remote_retrieve_body($resp);
        if (!empty($html)) {
            set_transient($cache_key, $html, 600); // 10 minutes cache
        }
        return $html;
    }
}

// ====================================================
// 1. HERO BANNER SHORTCODE [edu_banner]
// ====================================================
add_shortcode('edu_banner', function($atts) {
    $atts = shortcode_atts([
        'heading'     => '',
        'subheading'  => '',
        'description' => '',
        'top_heading' => '',
        'audio_url'   => '',
        'video_url'   => '',
        'university'  => ''
    ], $atts, 'edu_banner');

    return sode_fetch_remote_component('banner', $atts);
});

// ====================================================
// 2. LEAD FORM SHORTCODES
// [custom_lead_form], [compare_universities_form], [brochure_download_form], [scholarship_coupon_form]
// ====================================================
add_shortcode('custom_lead_form', function($atts) {
    $atts = shortcode_atts([
        'heading'     => 'Book 100% Free Counseling',
        'subheading'  => 'Get 1 to 1 Expert Guidance from SODE™',
        'form_name'   => '',
        'button_text' => 'Submit',
        'university'  => ''
    ], $atts, 'custom_lead_form');

    return sode_fetch_remote_component('lead_form', $atts);
});

add_shortcode('compare_universities_form', function($atts) {
    $atts = shortcode_atts([
        'heading'     => 'Compare Universities',
        'subheading'  => 'Get expert help to compare universities',
        'form_name'   => 'Compare Universities Form',
        'university'  => ''
    ], $atts, 'compare_universities_form');

    return sode_fetch_remote_component('compare_form', $atts);
});

add_shortcode('brochure_download_form', function($atts) {
    $atts = shortcode_atts([
        'heading'     => 'Download Brochure',
        'subheading'  => 'Fill the form to get your free brochure',
        'form_name'   => 'Brochure Download Form',
        'university'  => ''
    ], $atts, 'brochure_download_form');

    return sode_fetch_remote_component('brochure_form', $atts);
});

add_shortcode('scholarship_coupon_form', function($atts) {
    $atts = shortcode_atts([
        'heading'     => 'Get Scholarship Coupon Code',
        'subheading'  => 'Claim your exclusive academic scholarship today!',
        'form_name'   => 'Scholarship Coupon Form',
        'button_text' => 'Claim Scholarship Code',
        'university'  => ''
    ], $atts, 'scholarship_coupon_form');

    return sode_fetch_remote_component('scholarship_form', $atts);
});

// ====================================================
// 2.5. LATEST NEWS & MARQUEE SHORTCODES
// [latest_news], [universal_news], [sode_news]
// ====================================================
add_shortcode('latest_news', function($atts) {
    if (function_exists('sode_news_marquee_render')) {
        return sode_news_marquee_render($atts);
    }
    $atts = shortcode_atts([
        'university' => '',
        'heading'    => 'Latest News',
        'speed'      => '16s',
        'height'     => '240px',
        'limit'      => 10
    ], $atts, 'latest_news');

    return sode_fetch_remote_component('latest_news', $atts);
});

add_shortcode('universal_news', function($atts) {
    if (function_exists('sode_news_marquee_render')) {
        return sode_news_marquee_render($atts);
    }
    return sode_fetch_remote_component('latest_news', $atts);
});

add_shortcode('sode_news', function($atts) {
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
add_shortcode('university_courses', function($atts) {
    if (function_exists('sode_courses_tabs_render')) {
        return sode_courses_tabs_render($atts);
    }
    return sode_fetch_remote_component('university_courses', $atts);
});

add_shortcode('uni_courses', function($atts) {
    if (function_exists('sode_courses_tabs_render')) {
        return sode_courses_tabs_render($atts);
    }
    return sode_fetch_remote_component('university_courses', $atts);
});

add_shortcode('sode_courses', function($atts) {
    if (function_exists('sode_courses_tabs_render')) {
        return sode_courses_tabs_render($atts);
    }
    return sode_fetch_remote_component('university_courses', $atts);
});

add_shortcode('university_courses_list', function($atts) {
    if (function_exists('sode_courses_list_render')) {
        return sode_courses_list_render($atts);
    }
    return sode_fetch_remote_component('university_courses_list', $atts);
});

add_shortcode('uni_courses_list', function($atts) {
    if (function_exists('sode_courses_list_render')) {
        return sode_courses_list_render($atts);
    }
    return sode_fetch_remote_component('university_courses_list', $atts);
});

add_shortcode('courses_list', function($atts) {
    if (function_exists('sode_courses_list_render')) {
        return sode_courses_list_render($atts);
    }
    return sode_fetch_remote_component('university_courses_list', $atts);
});

// ====================================================
// 2.8. UNIVERSITY COURSES ELIGIBILITY TABLE SHORTCODES
// [university_eligibility_table], [uni_eligibility_table], [eligibility_table], [courses_eligibility_table], [university_eligibility]
// ====================================================
add_shortcode('university_eligibility_table', function($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('uni_eligibility_table', function($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('eligibility_table', function($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('courses_eligibility_table', function($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('university_eligibility', function($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

add_shortcode('uni_eligibility', function($atts) {
    if (function_exists('sode_courses_eligibility_table_render')) {
        return sode_courses_eligibility_table_render($atts);
    }
    return sode_fetch_remote_component('university_eligibility_table', $atts);
});

// ====================================================
// 2.9. UNIVERSITY ADMISSION & APPLICATION PROCESS SHORTCODES
// [university_application_process], [idol_application_process], [uni_application_process], [admission_process], [application_process]
// ====================================================
add_shortcode('university_application_process', function($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('idol_application_process', function($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('uni_application_process', function($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('admission_process', function($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('application_process', function($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

add_shortcode('sode_application_process', function($atts) {
    if (function_exists('sode_application_process_render')) {
        return sode_application_process_render($atts);
    }
    return sode_fetch_remote_component('application_process', $atts);
});

// ====================================================
// 3. GLOBAL YEAR SHORTCODE [site_year]
// ====================================================
add_shortcode('site_year', function($atts) {
    return date('Y');
});

// ====================================================
// 3.5. UNIVERSAL FOOTER SHORTCODES
// [footer_section], [universal_footer], [sode_footer]
// ====================================================
add_shortcode('footer_section', function($atts) {
    if (function_exists('sode_footer_render')) {
        return sode_footer_render($atts ?: []);
    }
    return sode_fetch_remote_component('footer', $atts ?: []);
});

add_shortcode('universal_footer', function($atts) {
    if (function_exists('sode_footer_render')) {
        return sode_footer_render($atts ?: []);
    }
    return sode_fetch_remote_component('footer', $atts ?: []);
});

add_shortcode('sode_footer', function($atts) {
    if (function_exists('sode_footer_render')) {
        return sode_footer_render($atts ?: []);
    }
    return sode_fetch_remote_component('footer', $atts ?: []);
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
function sode_client_generate_token() {
    $token = wp_generate_uuid4();
    set_transient('lead_token_' . $token, true, DAY_IN_SECONDS);
    wp_send_json_success($token);
}

// Multi-Endpoint Lead Sender
add_action('wp_ajax_send_lead', 'sode_client_send_lead');
add_action('wp_ajax_nopriv_send_lead', 'sode_client_send_lead');
function sode_client_send_lead() {
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
    if (strlen($phone) < 10) wp_send_json_error('Invalid phone number');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) wp_send_json_error('Invalid email address');

    $uni_slug = sanitize_title($_POST['uni_slug'] ?? sode_client_detect_uni());

    // Fetch dynamic university & API config from Central Admin
    $api_url = add_query_arg(['uni' => $uni_slug], SODE_CENTRAL_ADMIN_URL . '/api/get_form_config.php');
    $cfg_resp = wp_remote_get($api_url, ['timeout' => 8]);
    $cfg = [];
    if (!is_wp_error($cfg_resp)) {
        $cfg = json_decode(wp_remote_retrieve_body($cfg_resp), true) ?: [];
    }

    $crm_url       = $cfg['crm_api_url'] ?? 'https://api.crm.mysode.com/api/lead/apicreated';
    $crm_key       = $cfg['crm_api_key'] ?? '';
    $crm_secret    = $cfg['crm_secret'] ?? '';
    $brevo_url     = $cfg['brevo_api_url'] ?? 'https://api.brevo.com/v3/contacts';
    $brevo_key     = $cfg['brevo_api_key'] ?? '';
    $brevo_list_id = (int)($cfg['brevo_list_id'] ?? 124);
    $brevo_source  = $cfg['brevo_source'] ?? 'MISC';
    $gallabox_url  = $cfg['gallabox_webhook_url'] ?? '';
    $gallabox_src  = $cfg['gallabox_source'] ?? 'MISC';

    $name         = sanitize_text_field($_POST['name'] ?? '');
    $course       = sanitize_text_field($_POST['course'] ?? '');
    $state        = sanitize_text_field($_POST['state'] ?? '');
    $form_name    = sanitize_text_field($_POST['form_name'] ?? 'Lead Form');
    $source       = sanitize_text_field($_POST['source'] ?? ($cfg['source'] ?? 'MISC'));
    $utm_source   = sanitize_text_field($_POST['utm_source'] ?? ($cfg['default_utm_source'] ?? 'Organic'));
    $utm_medium   = sanitize_text_field($_POST['utm_medium'] ?? ($cfg['default_utm_medium'] ?? 'Direct'));
    $utm_campaign = sanitize_text_field($_POST['utm_campaign'] ?? ($cfg['default_utm_campaign'] ?? 'Universal'));
    $utm_term     = sanitize_text_field($_POST['utm_term'] ?? '');
    $utm_content  = sanitize_text_field($_POST['utm_content'] ?? '');
    $page_url     = esc_url_raw($_POST['page_url'] ?? '');

    // 1. CRM Lead Dispatch
    $crm_data = [
        "name" => $name, "email" => $email, "phone" => $phone, "course" => $course,
        "state" => $state, "form_name" => $form_name, "source" => $source,
        "utm_source" => $utm_source, "utm_medium" => $utm_medium, "utm_campaign" => $utm_campaign,
        "utm_term" => $utm_term, "utm_content" => $utm_content, "page_url" => $page_url
    ];
    if ($crm_key && $crm_secret) {
        wp_remote_post($crm_url, [
            'method' => 'POST', 'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'x-api-key' => $crm_key, 'secret' => $crm_secret],
            'body' => json_encode($crm_data)
        ]);
    }

    // 2. Brevo Dispatch
    if ($brevo_key) {
        $brevo_data = [
            "email" => $email, "listIds" => [$brevo_list_id],
            "attributes" => [
                "FULLNAME" => $name, "MOBILE" => $phone, "COURSES" => $course, "STATES" => $state,
                "UTM_SOURCE" => $utm_source, "UTM_CAMPAIGN" => $utm_campaign, "UTM_MEDIUM" => $utm_medium,
                "UTM_TERM" => $utm_term, "SOURCE" => $brevo_source
            ],
            "updateEnabled" => true
        ];
        wp_remote_post($brevo_url, [
            'method' => 'POST', 'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'api-key' => $brevo_key],
            'body' => json_encode($brevo_data)
        ]);
    }

    // 3. Gallabox Dispatch
    if ($gallabox_url) {
        $galla_data = [
            "name" => $name, "phone" => (str_starts_with($phone, '+') ? $phone : '+' . $phone),
            "email" => $email, "course" => $course, "state" => $state, "source" => $gallabox_src,
            "utm_source" => $utm_source, "utm_medium" => $utm_medium, "utm_campaign" => $utm_campaign,
            "utm_term" => $utm_term, "utm_content" => $utm_content
        ];
        wp_remote_post($gallabox_url, [
            'method' => 'POST', 'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($galla_data)
        ]);
    }

    wp_send_json_success('Lead Sent Successfully');
}

// ====================================================
// 5. GLOBAL FOOTER: POPUP MODALS & JS HANDLER
// ====================================================
add_action('wp_footer', function() {
    // 1. Output Legal Popups (Disclaimer, Privacy Policy, Terms) from Central Admin
    if (function_exists('sode_legal_popups_render')) {
        echo sode_legal_popups_render();
    } else {
        echo sode_fetch_remote_component('legal_popups');
    }

    // 2. Output Modals from Central Admin
    echo sode_fetch_remote_component('popup_modals');

    // 2. Output Unified Form & Popup Client JS
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        // Load Compare & Brochure Form contents on-demand
        const compareContent = document.getElementById('sode-compare-modal-content');
        const brochureContent = document.getElementById('sode-brochure-modal-content');
        const scholarshipContent = document.getElementById('sode-scholarship-modal-content');

        // Fetch sub-forms asynchronously
        function loadModalForms() {
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
        const compareOverlay = document.getElementById('compareFormPopupOverlay');
        const brochureOverlay = document.getElementById('brochureFormPopupOverlay');

        document.addEventListener('click', function(e) {
            if (e.target.closest('.open-compare-form')) {
                e.preventDefault();
                if (compareOverlay) { compareOverlay.classList.add('active'); document.body.style.overflow = 'hidden'; }
            }
            if (e.target.closest('.open-brochure-form')) {
                e.preventDefault();
                if (brochureOverlay) {
                    if (brochureContent) brochureContent.style.display = 'block';
                    if (scholarshipContent) scholarshipContent.style.display = 'none';
                    brochureOverlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            }
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

        // One-time Token Management
        let sodeToken = "";
        function fetchToken() {
            fetch("/wp-admin/admin-ajax.php?action=get_lead_token")
                .then(r => r.json()).then(r => { if(r.success) sodeToken = r.data; });
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
