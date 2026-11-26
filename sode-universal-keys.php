<?php
/**
 * ====================================================================
 * SODE Universal Server-Side Dynamic Keys Engine
 * File: sode-universal-keys.php
 * 
 * Purpose:
 * Replaces all dynamic admin keys ({UNIVERSITY_NAME}, {MODE}, $YEAR$, etc.)
 * in the server-side HTML BEFORE it is sent over the wire.
 * 
 * This ensures:
 * 1. Python tools / Webpage Content Extractor get 100% real content (0 keys).
 * 2. SEO bots (Googlebot, Bingbot, Social scrapers) crawl real university data.
 * 3. WP Rocket caches the fully rendered page with proper text.
 * 
 * Deployment:
 * - Option A (Recommended): Paste into WPCode as a "PHP Snippet" (Run Everywhere).
 * - Option B: Drop into wp-content/mu-plugins/sode-universal-keys.php.
 * - Option C: Include in active theme's functions.php.
 * ====================================================================
 */

if (!defined('ABSPATH')) {
    // If running outside WordPress, exit
    return;
}

if (!function_exists('sode_get_server_keys')) {
    function sode_get_server_keys()
    {
        static $cached_keys = null;
        if ($cached_keys !== null) {
            return $cached_keys;
        }

        // 1. Detect University Slug dynamically from host
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
        if (!$uni) {
            $uni = 'dsu';
        }

        $cache_key = 'sode_server_keys_' . $uni;

        // 2. High-speed local Transient Cache (12 Hours)
        if (function_exists('get_transient') && empty($_GET['sode_flush'])) {
            $stored = get_transient($cache_key);
            if (!empty($stored) && is_array($stored)) {
                $cached_keys = $stored;
                return $cached_keys;
            }
        }

        // 3. Fetch from Central Admin API
        $api_url = 'https://admin.distanceeducationschool.com/admin/api/get_global_keys.php?uni=' . urlencode($uni);
        $resp = wp_remote_get($api_url, [
            'timeout'   => 4,
            'sslverify' => false,
            'headers'   => ['Accept' => 'application/json']
        ]);

        $keys = [];
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $json = json_decode(wp_remote_retrieve_body($resp), true);
            if (!empty($json['keys']) && is_array($json['keys'])) {
                $keys = $json['keys'];
                if (function_exists('set_transient')) {
                    set_transient($cache_key, $keys, 12 * HOUR_IN_SECONDS);
                }
            }
        }

        // 4. Guaranteed fallbacks if central API is temporarily slow
        $cur_year = date('Y');
        $fallbacks = [
            '$YEAR$'                   => $cur_year,
            '{YEAR}'                   => $cur_year,
            '[YEAR]'                   => $cur_year,
            '{DSU_LOWER}'              => $uni,
            '{UNI_LOWER}'              => $uni,
            '{UNIVERSITY_SLUG}'        => $uni,
            '{UNI_SLUG}'               => $uni,
            '{UNIVERSITY_SHORT_NAME_LOWER}' => $uni,
            '{BASE_URL}'               => 'https://distanceeducationschool.com'
        ];

        foreach ($fallbacks as $fk => $fv) {
            if (!isset($keys[$fk])) {
                $keys[$fk] = $fv;
            }
        }

        $cached_keys = $keys;
        return $cached_keys;
    }
}

if (!function_exists('sode_server_replace_all_keys')) {
    function sode_server_replace_all_keys($html)
    {
        if (empty($html) || !is_string($html)) {
            return $html;
        }

        // Only process HTML responses (skip XML, JSON, image streams)
        if (strpos($html, '<html') === false && strpos($html, '<body') === false) {
            return $html;
        }

        $keys = sode_get_server_keys();
        if (empty($keys)) {
            return $html;
        }

        // Step 1: Protect <script> and <style> blocks to prevent touching JavaScript source code
        $protected = [];
        $html = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\\1>/is', function ($matches) use (&$protected) {
            $token = '###SODE_PROTECTED_TAG_' . count($protected) . '###';
            $protected[$token] = $matches[0];
            return $token;
        }, $html);

        // Step 2: Build replacements map sorted by length DESC (specific long keys match before short ones)
        $replace_pairs = [];
        foreach ($keys as $raw_key => $val) {
            if (!is_string($val) && !is_numeric($val)) {
                continue;
            }
            $val_str = (string) $val;
            if ($val_str === '') {
                continue;
            }

            // Clean key
            $clean_key = trim($raw_key);
            $bare = trim($clean_key, '$[]{}#');

            // Register standard variants for delimited keys
            $variants = [
                $clean_key,
                '{' . $bare . '}',
                '{{' . $bare . '}}',
                '$' . $bare . '$',
                '[' . $bare . ']'
            ];

            foreach ($variants as $v) {
                if ($v !== '' && !isset($replace_pairs[$v])) {
                    $replace_pairs[$v] = $val_str;
                }
            }
        }

        // Ensure key university parameters are explicitly covered
        $uni_name = $keys['{UNIVERSITY_NAME}'] ?? $keys['UNIVERSITY_NAME'] ?? '';
        $mode     = $keys['{MODE}'] ?? $keys['MODE'] ?? 'Online';
        $short    = $keys['{UNIVERSITY_SHORT_NAME}'] ?? $keys['UNIVERSITY_SHORT_NAME'] ?? '';

        if ($uni_name) {
            $replace_pairs['{UNIVERSITY_NAME}'] = $uni_name;
            $replace_pairs['[UNIVERSITY_NAME]'] = $uni_name;
            $replace_pairs['$UNIVERSITY_NAME$'] = $uni_name;
            $replace_pairs['{{UNIVERSITY_NAME}}'] = $uni_name;
        }
        if ($mode) {
            $replace_pairs['{MODE}'] = $mode;
            $replace_pairs['[MODE]'] = $mode;
            $replace_pairs['$MODE$'] = $mode;
            $replace_pairs['{{MODE}}'] = $mode;
        }
        if ($short) {
            $replace_pairs['{UNIVERSITY_SHORT_NAME}'] = $short;
            $replace_pairs['[UNIVERSITY_SHORT_NAME]'] = $short;
            $replace_pairs['$UNIVERSITY_SHORT_NAME$'] = $short;
            $replace_pairs['{UNI_SHORT_NAME}'] = $short;
            $replace_pairs['{UNI}'] = $short;
        }

        // Sort by length DESC
        uksort($replace_pairs, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        // Fast string replacement pass
        $html = str_replace(array_keys($replace_pairs), array_values($replace_pairs), $html);

        // Step 3: Restore protected <script> and <style> blocks
        if (!empty($protected)) {
            $html = strtr($html, $protected);
        }

        return $html;
    }
}

// Hook into template_redirect at priority 0 to buffer the entire HTML output
add_action('template_redirect', function () {
    // Avoid buffering WP REST API, XMLRPC, or Cron
    if (defined('REST_REQUEST') || defined('DOING_CRON') || defined('XMLRPC_REQUEST')) {
        return;
    }
    ob_start('sode_server_replace_all_keys');
}, 0);

// Also hook standard WordPress filter hooks for plugins/themes that bypass template_redirect
add_filter('the_content', 'sode_server_replace_all_keys', 99);
add_filter('the_title', 'sode_server_replace_all_keys', 99);
add_filter('pre_get_document_title', 'sode_server_replace_all_keys', 99);
add_filter('wpseo_title', 'sode_server_replace_all_keys', 99);
add_filter('wpseo_metadesc', 'sode_server_replace_all_keys', 99);
add_filter('rank_math/frontend/title', 'sode_server_replace_all_keys', 99);
add_filter('rank_math/frontend/description', 'sode_server_replace_all_keys', 99);
