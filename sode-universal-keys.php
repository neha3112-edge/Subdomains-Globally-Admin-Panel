<?php
/**
 * ====================================================================
 * SODE Universal Server-Side Dynamic Engine (Keys & Sections SSR)
 * File: sode-universal-keys.php - Version 2.0 (100% Dynamic - Zero Hardcoding)
 * 
 * Purpose:
 * 1. Automatically renders all Central Shortcodes (banners, news, courses,
 *    tables, eligibility, admission process, footers) on the server side.
 * 2. Dynamically replaces all Admin Keys ({UNIVERSITY_NAME}, {MODE}, $YEAR$, etc.)
 *    before the HTML stream leaves the WordPress server.
 * 3. Future-Proof: Zero hardcoded shortcode lists or keys. Any new component
 *    or key added in Central Admin is automatically fetched and rendered!
 * 4. Compatible with Python extractors, SEO crawlers, and human browsers.
 * ====================================================================
 */

if (!defined('ABSPATH')) {
    return;
}

if (!function_exists('sode_get_current_uni_slug')) {
    function sode_get_current_uni_slug()
    {
        if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
            return sanitize_title(SODE_UNIVERSITY_SLUG);
        }
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $parts = explode('.', $host);
        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
            return sanitize_title($parts[0]);
        }
        return 'dsu';
    }
}

if (!function_exists('sode_fetch_batch_data')) {
    /**
     * Fetch uncached shortcodes and global keys in a single batch request
     */
    function sode_fetch_batch_data($uni, $uncached_items)
    {
        $api_url = 'https://admin.distanceeducationschool.com/admin/api/render_component.php?component=batch';
        $payload = [
            'uni'   => $uni,
            'items' => $uncached_items
        ];

        $resp = wp_remote_post($api_url, [
            'timeout'   => 5,
            'sslverify' => false,
            'headers'   => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'body'      => json_encode($payload)
        ]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return ['results' => [], 'keys' => []];
        }

        $json = json_decode(wp_remote_retrieve_body($resp), true);
        return [
            'results' => $json['results'] ?? [],
            'keys'    => $json['keys'] ?? []
        ];
    }
}

if (!function_exists('sode_server_universal_processor')) {
    function sode_server_universal_processor($html)
    {
        if (empty($html) || !is_string($html)) {
            return $html;
        }

        // Only process HTML document responses
        if (strpos($html, '<html') === false && strpos($html, '<body') === false) {
            return $html;
        }

        $uni = sode_get_current_uni_slug();
        $is_flush = !empty($_GET['sode_flush']);

        // Step 1: Protect <script> and <style> blocks from accidental replacement
        $protected = [];
        $html = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\\1>/is', function ($matches) use (&$protected) {
            $token = '###SODE_PROTECT_' . count($protected) . '###';
            $protected[$token] = $matches[0];
            return $token;
        }, $html);

        // Step 2: Dynamically detect all shortcodes in the HTML body
        // Matches [shortcode_tag attr1="val1" ...]
        $matched_shortcodes = [];
        $uncached_batch = [];
        $rendered_map = [];

        // Common HTML tag exclusions that might use bracket syntax in inline scripts or text
        $skip_tags = ['a', 'b', 'i', 'p', 'span', 'div', 'img', 'table', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'button', 'input', 'label', 'select', 'option'];

        if (preg_match_all('/\[([a-zA-Z][a-zA-Z0-9_-]{2,})\b([^\]]*)\]/s', $html, $sc_matches, PREG_SET_ORDER)) {
            $count = 0;
            foreach ($sc_matches as $m) {
                $raw_shortcode = $m[0];
                $tag           = strtolower($m[1]);
                $attrs_raw     = $m[2];

                if (in_array($tag, $skip_tags, true)) {
                    continue;
                }

                if (isset($matched_shortcodes[$raw_shortcode])) {
                    continue;
                }

                $matched_shortcodes[$raw_shortcode] = $tag;
                $cache_key = 'sode_ssr_' . $uni . '_' . md5($raw_shortcode);

                // Check WordPress transient cache
                $cached_rendered = ($is_flush) ? false : get_transient($cache_key);

                if (!empty($cached_rendered) && is_string($cached_rendered)) {
                    $rendered_map[$raw_shortcode] = $cached_rendered;
                } else {
                    // Parse attributes cleanly
                    $params = ['uni' => $uni, 'university' => $uni];
                    if (!empty($attrs_raw)) {
                        $clean_attrs = str_replace(
                            ['“', '”', '‘', '’', '&quot;', '&#8220;', '&#8221;', '&#8216;', '&#8217;', '&amp;'],
                            ['"', '"', "'", "'", '"', '"', '"', "'", "'", '&'],
                            $attrs_raw
                        );
                        if (preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+))/s', $clean_attrs, $attr_matches, PREG_SET_ORDER)) {
                            foreach ($attr_matches as $am) {
                                $k = strtolower($am[1]);
                                $v = $am[2] !== '' ? $am[2] : ($am[3] !== '' ? $am[3] : $am[4]);
                                $params[$k] = $v;
                            }
                        }
                    }

                    $item_id = 'sc_' . ($count++);
                    $uncached_batch[$item_id] = [
                        'component' => $tag,
                        'params'    => $params,
                        '_raw'      => $raw_shortcode,
                        '_cache_key'=> $cache_key
                    ];
                }
            }
        }

        // Step 3: Fetch uncached shortcodes and global keys from Central Admin API
        $keys_cache_key = 'sode_keys_' . $uni;
        $cached_keys = ($is_flush) ? false : get_transient($keys_cache_key);
        $global_keys = is_array($cached_keys) ? $cached_keys : [];

        if (!empty($uncached_batch) || empty($global_keys)) {
            $api_items = [];
            foreach ($uncached_batch as $iid => $info) {
                $api_items[$iid] = [
                    'component' => $info['component'],
                    'params'    => $info['params']
                ];
            }

            $batch_data = sode_fetch_batch_data($uni, $api_items);

            // Save rendered shortcodes into map & transient cache
            if (!empty($batch_data['results'])) {
                foreach ($batch_data['results'] as $iid => $rendered_html) {
                    if (isset($uncached_batch[$iid]) && !empty($rendered_html)) {
                        $raw_sc    = $uncached_batch[$iid]['_raw'];
                        $cache_key = $uncached_batch[$iid]['_cache_key'];

                        $rendered_map[$raw_sc] = $rendered_html;
                        set_transient($cache_key, $rendered_html, 12 * HOUR_IN_SECONDS);
                    }
                }
            }

            // Save global keys
            if (!empty($batch_data['keys'])) {
                $global_keys = array_merge($global_keys, $batch_data['keys']);
                set_transient($keys_cache_key, $global_keys, 12 * HOUR_IN_SECONDS);
            }
        }

        // Step 4: Substitute rendered shortcodes into the HTML
        if (!empty($rendered_map)) {
            // Sort by length DESC so larger nested shortcodes replace first
            uksort($rendered_map, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            $html = str_replace(array_keys($rendered_map), array_values($rendered_map), $html);

            // Mark shortcode containers as hydrated so sode-anti-flash reveals them instantly
            $html = str_replace(
                ['elementor-widget-shortcode', 'elementor-shortcode', 'wp-block-shortcode'],
                ['elementor-widget-shortcode sode-hydrated', 'elementor-shortcode sode-hydrated', 'wp-block-shortcode sode-hydrated'],
                $html
            );
        }

        // Step 5: Dynamically substitute all global admin keys
        if (!empty($global_keys)) {
            $key_replacements = [];
            foreach ($global_keys as $k_code => $k_val) {
                if (!is_string($k_val) && !is_numeric($k_val)) {
                    continue;
                }
                $val_str = (string) $k_val;
                if ($val_str === '') {
                    continue;
                }

                $clean_code = trim($k_code);
                $bare       = trim($clean_code, '$[]{}#');

                $variants = [
                    $clean_code,
                    '{' . $bare . '}',
                    '{{' . $bare . '}}',
                    '$' . $bare . '$',
                    '[' . $bare . ']'
                ];

                foreach ($variants as $v) {
                    if ($v !== '' && !isset($key_replacements[$v])) {
                        $key_replacements[$v] = $val_str;
                    }
                }
            }

            // Guaranteed fallbacks for core university keys
            $cur_year = date('Y');
            $fallbacks = [
                '$YEAR$'                        => $cur_year,
                '{YEAR}'                        => $cur_year,
                '[YEAR]'                        => $cur_year,
                '{DSU_LOWER}'                   => $uni,
                '{UNI_LOWER}'                   => $uni,
                '{UNIVERSITY_SLUG}'             => $uni,
                '{UNI_SLUG}'                    => $uni,
                '{UNIVERSITY_SHORT_NAME_LOWER}' => $uni,
                '{BASE_URL}'                    => 'https://distanceeducationschool.com'
            ];
            foreach ($fallbacks as $fb_k => $fb_v) {
                if (!isset($key_replacements[$fb_k])) {
                    $key_replacements[$fb_k] = $fb_v;
                }
            }

            uksort($key_replacements, function ($a, $b) {
                return strlen($b) - strlen($a);
            });

            $html = str_replace(array_keys($key_replacements), array_values($key_replacements), $html);
        }

        // Step 6: Restore protected <script> and <style> tags
        if (!empty($protected)) {
            $html = strtr($html, $protected);
        }

        // Step 7: Automatically extract & inject Dynamic LCP Banner Image Preloads into <head>
        if (strpos($html, '<head') !== false) {
            $preload_tags = '';
            // Extract desktop banner background URL
            if (preg_match('/\.edu-banner-desktop\b[^{]*\{[^}]*background-image:\s*url\(["\']?([^"\')]+)["\']?\)/i', $html, $m_desk)) {
                $preload_tags .= '<link rel="preload" as="image" href="' . esc_url($m_desk[1]) . '" fetchpriority="high" media="(min-width: 769px)">' . "\n";
            }
            // Extract mobile banner background URL
            if (preg_match('/\.edu-banner-mobile\b[^{]*\{[^}]*background-image:\s*url\(["\']?([^"\')]+)["\']?\)/i', $html, $m_mob)) {
                $preload_tags .= '<link rel="preload" as="image" href="' . esc_url($m_mob[1]) . '" fetchpriority="high" media="(max-width: 768px)">' . "\n";
            }
            if (!empty($preload_tags)) {
                $html = preg_replace('/<head\b[^>]*>/i', '$0' . "\n" . $preload_tags, $html, 1);
            }
        }

        return $html;
    }
}

// Hook into template_redirect at priority 0 to buffer entire HTML output
add_action('template_redirect', function () {
    if (defined('REST_REQUEST') || defined('DOING_CRON') || defined('XMLRPC_REQUEST')) {
        return;
    }
    ob_start('sode_server_universal_processor');
}, 0);

// Also hook standard WordPress filter hooks
add_filter('the_content', 'sode_server_universal_processor', 99);
add_filter('the_title', 'sode_server_universal_processor', 99);
add_filter('pre_get_document_title', 'sode_server_universal_processor', 99);
add_filter('wpseo_title', 'sode_server_universal_processor', 99);
add_filter('wpseo_metadesc', 'sode_server_universal_processor', 99);
add_filter('rank_math/frontend/title', 'sode_server_universal_processor', 99);
add_filter('rank_math/frontend/description', 'sode_server_universal_processor', 99);
