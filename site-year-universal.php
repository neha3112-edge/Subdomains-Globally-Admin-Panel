<?php
/**
 * ==========================================================
 * SITE YEAR, SESSION & GLOBAL KEYS - UNIVERSAL DYNAMIC SYSTEM
 * ==========================================================
 * Connected dynamically to SODE Central Admin Global Keys Engine.
 * 
 * Features:
 *  1. Any key created in Admin Panel (Global Keys module) is instantly
 *     available on all subdomains without modifying code.
 *  2. Auto replaces $KEY$, {KEY}, {{KEY}} in entire HTML output (Elementor,
 *     widgets, post titles, page content, Yoast SEO, headers, footers).
 *  3. Dynamic Shortcodes: [site_year], [site_session], [site_next_year],
 *     [gkey code="KEY_NAME"], and [global_key key="KEY_NAME"].
 *  4. High performance caching (transient) with auto-refresh on preview.
 * ==========================================================
 */

// WordPress environment check
if (!function_exists('add_action') && !defined('ABSPATH')) {
    // If called directly outside WP
    return;
}

// Ensure single load
if (defined('SITE_YEAR_UNIVERSAL_LOADED')) {
    return;
}
define('SITE_YEAR_UNIVERSAL_LOADED', true);

// Central API URL
if (!defined('SODE_CENTRAL_ADMIN_URL')) {
    define('SODE_CENTRAL_ADMIN_URL', 'https://admin.distanceeducationschool.com');
}

/**
 * Fetch all active global keys from Admin DB or Remote API (Cached)
 */
if (!function_exists('sode_get_all_global_keys')) {
    function sode_get_all_global_keys() {
        static $memory_cache = null;
        if ($memory_cache !== null) {
            return $memory_cache;
        }

        // 1. Direct Local DB check (if running on same server)
        $local_config = __DIR__ . '/admin/config/config.php';
        if (file_exists($local_config)) {
            try {
                require_once $local_config;
                if (function_exists('get_db_connection')) {
                    $db = get_db_connection();
                    $stmt = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1");
                    $keys = [];
                    while ($row = $stmt->fetch()) {
                        $keys[$row['key_code']] = $row['key_value'];
                    }
                    if (!empty($keys)) {
                        $memory_cache = $keys;
                        return $memory_cache;
                    }
                }
            } catch (Exception $e) {
                // Fallback to transient / API
            }
        }

        // No transient caching — always fetch fresh so Admin Panel changes reflect instantly

        // 3. Remote HTTP API Call
        $keys = [];
        $api_url = rtrim(SODE_CENTRAL_ADMIN_URL, '/') . '/api/get_global_keys.php?t=' . time();

        if (function_exists('wp_remote_get')) {
            $resp = wp_remote_get($api_url, [
                'timeout' => 5,
                'headers' => ['Cache-Control' => 'no-cache']
            ]);

            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $body = wp_remote_retrieve_body($resp);
                $json = json_decode($body, true);
                if (!empty($json['success']) && !empty($json['keys']) && is_array($json['keys'])) {
                    $keys = $json['keys'];
                } elseif (!empty($json['data']) && is_array($json['data'])) {
                    $keys = $json['data'];
                }
            }
        }

        // 4. Safe Default Fallbacks if connection fails
        if (empty($keys)) {
            $current_year = date('Y');
            $next_year = (int)$current_year + 1;
            $keys = [
                '$YEAR$' => (string)$current_year,
                '$session$' => $current_year . '-' . substr($next_year, -2),
                '$nextyear$' => (string)$next_year,
                '$BANNER_TOP_TEXT$' => 'Welcome to SODE™ (School of Online and Distance Education)'
            ];
        }

        // (transient cache removed — no delay on key updates)

        $memory_cache = $keys;
        return $memory_cache;
    }
}

/**
 * Generic Getter for any global key
 * Supports both with and without $ symbol (e.g. '$YEAR$' or 'YEAR')
 */
if (!function_exists('sode_get_global_key')) {
    function sode_get_global_key($code, $fallback = '') {
        $keys = sode_get_all_global_keys();
        $code_clean = trim((string)$code);
        
        if (isset($keys[$code_clean])) {
            return $keys[$code_clean];
        }

        // Try normalized variations
        $with_dollars = '$' . trim($code_clean, '$') . '$';
        if (isset($keys[$with_dollars])) {
            return $keys[$with_dollars];
        }

        // Case-insensitive match
        foreach ($keys as $k => $v) {
            if (strcasecmp(trim($k, '$'), trim($code_clean, '$')) === 0) {
                return $v;
            }
        }

        return $fallback;
    }
}

/**
 * Standard Helper Functions (Legacy & Theme Compatibility)
 */
if (!function_exists('get_site_year')) {
    function get_site_year() {
        return sode_get_global_key('$YEAR$', date('Y'));
    }
}

if (!function_exists('get_site_session')) {
    function get_site_session() {
        return sode_get_global_key('$session$', date('Y') . '-' . substr((string)(date('Y') + 1), -2));
    }
}

if (!function_exists('get_site_next_year')) {
    function get_site_next_year() {
        return sode_get_global_key('$nextyear$', (string)(date('Y') + 1));
    }
}

/**
 * ==========================================================
 * DYNAMIC OUTPUT BUFFER: Auto-Replace ALL Admin Global Keys
 * (Elementor, Yoast SEO, Widgets, Shortcodes, Templates, Footer)
 * ==========================================================
 */
if (!function_exists('start_universal_global_keys_buffer')) {
    function start_universal_global_keys_buffer() {
        // Skip for Admin, AJAX, REST, and Feeds
        if ((function_exists('is_admin') && is_admin()) || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || defined('REST_REQUEST')) {
            return;
        }
        ob_start('replace_universal_global_keys_in_output');
    }
    if (function_exists('add_action')) {
        add_action('template_redirect', 'start_universal_global_keys_buffer', 0);
    }
}

if (!function_exists('replace_universal_global_keys_in_output')) {
    function replace_universal_global_keys_in_output($html) {
        if (!is_string($html) || empty($html)) {
            return $html;
        }

        $keys = sode_get_all_global_keys();
        if (empty($keys) || !is_array($keys)) {
            return $html;
        }

        foreach ($keys as $key_code => $val) {
            if (!is_string($val) && !is_numeric($val)) continue;
            $val_str = (string)$val;

            // 1. Exact Key Match (e.g. $YEAR$, $session$, $BANNER_TOP_TEXT$)
            if (strpos($html, $key_code) !== false) {
                $html = str_replace($key_code, $val_str, $html);
            }

            // 2. Case variations & Brackets: {KEY}, {{KEY}}, $lowercase$
            $raw_name = trim($key_code, '$');
            $alt_patterns = [
                '{{' . $raw_name . '}}',
                '{{' . strtoupper($raw_name) . '}}',
                '{{' . strtolower($raw_name) . '}}',
                '$' . strtoupper($raw_name) . '$',
                '$' . strtolower($raw_name) . '$',
                '{' . $raw_name . '}',
                '{' . strtoupper($raw_name) . '}',
                '{' . strtolower($raw_name) . '}'
            ];

            foreach ($alt_patterns as $alt) {
                if ($alt !== $key_code && strpos($html, $alt) !== false) {
                    $html = str_replace($alt, $val_str, $html);
                }
            }
        }

        return $html;
    }
}

/**
 * ==========================================================
 * UNIVERSAL SHORTCODES:
 *  - [site_year] -> 2026
 *  - [site_session] -> July 2026-2027
 *  - [site_next_year] -> 2027
 *  - [gkey code="KEY_NAME"] -> dynamic value
 *  - [global_key key="KEY_NAME"] -> dynamic value
 * ==========================================================
 */
if (function_exists('add_shortcode')) {
    add_shortcode('site_year', 'get_site_year');
    add_shortcode('site_session', 'get_site_session');
    add_shortcode('site_next_year', 'get_site_next_year');

    // Generic Shortcode for any key created now or in the future
    add_shortcode('gkey', function($atts) {
        $atts = shortcode_atts([
            'code'    => '$YEAR$',
            'default' => ''
        ], $atts, 'gkey');
        return sode_get_global_key($atts['code'], $atts['default']);
    });

    add_shortcode('global_key', function($atts) {
        $atts = shortcode_atts([
            'key'     => '$YEAR$',
            'code'    => '',
            'default' => ''
        ], $atts, 'global_key');
        $target = !empty($atts['code']) ? $atts['code'] : $atts['key'];
        return sode_get_global_key($target, $atts['default']);
    });
}
