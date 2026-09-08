<?php
/**
 * ==========================================================
 * WORDPRESS STUBS (FOR IDE / LINTER SUPPORT)
 * ==========================================================
 */

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function get_error_message( $code = '' ) { return ''; }
        public function get_error_code() { return ''; }
    }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    /**
     * @param string $url
     * @param array $args
     * @return array|WP_Error
     */
    function wp_remote_get( $url, $args = array() ) {
        return array();
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * @param mixed $thing
     * @return bool
     */
    function is_wp_error( $thing ) {
        return false;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    /**
     * @param array|WP_Error $response
     * @return int
     */
    function wp_remote_retrieve_response_code( $response ) {
        return 200;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    /**
     * @param array|WP_Error $response
     * @return string
     */
    function wp_remote_retrieve_body( $response ) {
        return '';
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    /**
     * @param string $url
     * @param array|null $protocols
     * @param string $_context
     * @return string
     */
    function esc_url( $url, $protocols = null, $_context = 'display' ) {
        return $url;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    /**
     * @param string $text
     * @return string
     */
    function esc_html( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    /**
     * @param string $text
     * @return string
     */
    function esc_attr( $text ) {
        return $text;
    }
}

if ( ! function_exists( 'shortcode_atts' ) ) {
    /**
     * @param array $pairs
     * @param array|string $atts
     * @param string $shortcode
     * @return array
     */
    function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
        return array();
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    /**
     * @param string $tag
     * @param callable|string $callback
     * @return void
     */
    function add_shortcode( $tag, $callback ) {}
}

if ( ! function_exists( 'did_action' ) ) {
    /**
     * @param string $tag
     * @return int
     */
    function did_action( $tag ) {
        return 0;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    /**
     * @param string $tag
     * @param mixed ...$arg
     * @return void
     */
    function do_action( $tag, ...$arg ) {}
}

if ( ! function_exists( 'wp_rand' ) ) {
    /**
     * @param int $min
     * @param int $max
     * @return int
     */
    function wp_rand( $min = 0, $max = 0 ) {
        return 0;
    }
}

if ( ! function_exists( 'get_site_year' ) ) {
    /**
     * @return string
     */
    function get_site_year() {
        return date( 'Y' );
    }
}
