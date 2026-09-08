<?php
/**
 * ==========================================================
 * SITE YEAR & SESSION - UNIVERSAL FILE
 * ==========================================================
 * YE FILE SIRF "dusol" SUBDOMAIN PAR RAKHNI HAI:
 *   dynamic-data-files/site-year-universal.php
 *
 * Baaki SAARE subdomains isko apni functions.php / Code Snippets me
 * loader ke through require karenge.
 *
 * Isliye ab Year, Session aur Next Year sirf EK jagah change karna
 * padega - saare subdomains par apne aap reflect ho jaayega.
 * ==========================================================
 */

// WordPress environment check (direct access block)
if ( ! function_exists( 'add_action' ) ) {
    http_response_code( 403 );
    exit;
}

// Ek hi baar load ho taaki duplicate function declaration error na aaye
if ( defined( 'SITE_YEAR_UNIVERSAL_LOADED' ) ) {
    return;
}
define( 'SITE_YEAR_UNIVERSAL_LOADED', true );


/**
 * ==========================================================
 * SETTINGS: Year, Session aur Next Year (Global Values)
 * Future me bas yahan change karna hai, sab jagah update ho jayega
 * ==========================================================
 */
if ( ! function_exists( 'get_site_year' ) ) {
    function get_site_year() {
        return '2026';
    }
}

if ( ! function_exists( 'get_site_session' ) ) {
    function get_site_session() {
        return '2026-27';
    }
}

if ( ! function_exists( 'get_site_next_year' ) ) {
    function get_site_next_year() {
        return '2027';
    }
}


/**
 * ==========================================================
 * OUTPUT BUFFER: $YEAR$, $session$, $nextyear$ Ko Auto-Replace Karo
 * (Elementor, Yoast, Widgets, Shortcodes, Templates - Sab par kaam karega)
 * ==========================================================
 */
if ( ! function_exists( 'start_year_replacement_buffer' ) ) {
    function start_year_replacement_buffer() {
        // Admin area, AJAX, REST API aur Feeds me mat chalao
        if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || defined( 'REST_REQUEST' ) ) {
            return;
        }
        ob_start( 'replace_year_in_final_output' );
    }
    add_action( 'template_redirect', 'start_year_replacement_buffer', 0 );
}

if ( ! function_exists( 'replace_year_in_final_output' ) ) {
    function replace_year_in_final_output( $html ) {
        if ( is_string( $html ) ) {
            $replacements = array(
                '$YEAR$'     => get_site_year(),
                '$session$'  => get_site_session(),
                '$nextyear$' => get_site_next_year(),
            );

            foreach ( $replacements as $key => $value ) {
                if ( strpos( $html, $key ) !== false ) {
                    $html = str_replace( $key, $value, $html );
                }
            }
        }
        return $html;
    }
}


/**
 * ==========================================================
 * SHORTCODES (Optional convenience):
 * [site_year] -> 2026
 * [site_session] -> 2026-27
 * [site_next_year] -> 2027
 * ==========================================================
 */
if ( function_exists( 'add_shortcode' ) ) {
    add_shortcode( 'site_year', 'get_site_year' );
    add_shortcode( 'site_session', 'get_site_session' );
    add_shortcode( 'site_next_year', 'get_site_next_year' );
}
