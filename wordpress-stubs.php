<?php
/**
 * ==========================================================
 * WORDPRESS STUBS (FOR IDE / LINTER / INTELEPHENSE SUPPORT)
 * ==========================================================
 * This file provides IDE autocomplete, method hints, and disables
 * undefined function/constant yellow warnings when editing WordPress
 * plugins in standalone IDE workspaces.
 */

namespace Elementor {
    if ( ! class_exists( 'Elementor\Plugin' ) ) {
        class Plugin {
            /** @var Plugin */
            public static $instance;
            /** @var object */
            public $files_manager;
            public function __construct() {
                $this->files_manager = new class {
                    public function clear_cache() {}
                };
            }
        }
    }
}

namespace {

// ==========================================================
// 1. CONSTANTS
// ==========================================================
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
    define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
    define( 'MONTH_IN_SECONDS', 2592000 );
}
if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
    define( 'YEAR_IN_SECONDS', 31536000 );
}
if ( ! defined( 'WP_REDIS_VERSION' ) ) {
    define( 'WP_REDIS_VERSION', '2.5.0' );
}
if ( ! defined( 'WP_CACHE' ) ) {
    define( 'WP_CACHE', true );
}
if ( ! defined( 'SODE_UNIVERSITY_SLUG' ) ) {
    define( 'SODE_UNIVERSITY_SLUG', '' );
}
if ( ! defined( 'SODE_CENTRAL_ADMIN_URL' ) ) {
    define( 'SODE_CENTRAL_ADMIN_URL', 'https://admin.distanceeducationschool.com' );
}

// ==========================================================
// 2. CORE CLASSES
// ==========================================================
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        /**
         * @param string $code
         * @return string
         */
        public function get_error_message( $code = '' ) { return ''; }

        /**
         * @return string|int
         */
        public function get_error_code() { return ''; }

        /**
         * @param string $code
         * @return mixed
         */
        public function get_error_data( $code = '' ) { return null; }
    }
}

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        /** @var int */
        public $ID = 0;
        /** @var string */
        public $post_title = '';
        /** @var string */
        public $post_content = '';
        /** @var string */
        public $post_status = 'publish';
    }
}

// ==========================================================
// 3. HOOKS & ACTIONS
// ==========================================================
if ( ! function_exists( 'add_action' ) ) {
    /**
     * @param string $hook_name
     * @param callable|string $callback
     * @param int $priority
     * @param int $accepted_args
     * @return true
     */
    function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    /**
     * @param string $hook_name
     * @param callable|string $callback
     * @param int $priority
     * @param int $accepted_args
     * @return true
     */
    function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
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

if ( ! function_exists( 'apply_filters' ) ) {
    /**
     * @param string $hook_name
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    function apply_filters( $hook_name, $value, ...$args ) {
        return $value;
    }
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

// ==========================================================
// 4. SHORTCODES
// ==========================================================
if ( ! function_exists( 'add_shortcode' ) ) {
    /**
     * @param string $tag
     * @param callable|string $callback
     * @return void
     */
    function add_shortcode( $tag, $callback ) {}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
    /**
     * @param array $pairs
     * @param array|string $atts
     * @param string $shortcode
     * @return array
     */
    function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
        $atts = (array)$atts;
        $out = array();
        foreach ( $pairs as $name => $default ) {
            if ( array_key_exists( $name, $atts ) )
                $out[$name] = $atts[$name];
            else
                $out[$name] = $default;
        }
        return $out;
    }
}

// ==========================================================
// 5. HTTP & REMOTE REQUESTS
// ==========================================================
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

if ( ! function_exists( 'wp_remote_post' ) ) {
    /**
     * @param string $url
     * @param array $args
     * @return array|WP_Error
     */
    function wp_remote_post( $url, $args = array() ) {
        return array();
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * @param mixed $thing
     * @return bool
     */
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
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

// ==========================================================
// 6. TRANSIENTS & CACHE
// ==========================================================
if ( ! function_exists( 'get_transient' ) ) {
    /**
     * @param string $transient
     * @return mixed
     */
    function get_transient( $transient ) {
        return false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    /**
     * @param string $transient
     * @param mixed $value
     * @param int $expiration
     * @return bool
     */
    function set_transient( $transient, $value, $expiration = 0 ) {
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    /**
     * @param string $transient
     * @return bool
     */
    function delete_transient( $transient ) {
        return true;
    }
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
    /**
     * @param int|string $key
     * @param string $group
     * @return bool
     */
    function wp_cache_delete( $key, $group = '' ) {
        return true;
    }
}

if ( ! function_exists( 'wp_cache_flush' ) ) {
    /**
     * @return bool
     */
    function wp_cache_flush() {
        return true;
    }
}

if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
    /**
     * @return bool
     */
    function wp_cache_clear_cache() {
        return true;
    }
}

if ( ! function_exists( 'rocket_clean_domain' ) ) {
    /**
     * @param string $lang
     * @return void
     */
    function rocket_clean_domain( $lang = '' ) {}
}

if ( ! function_exists( 'rocket_clean_post' ) ) {
    /**
     * @param int $post_id
     * @return void
     */
    function rocket_clean_post( $post_id = 0 ) {}
}

if ( ! function_exists( 'w3tc_flush_all' ) ) {
    /**
     * @param mixed $extras
     * @return bool
     */
    function w3tc_flush_all( $extras = null ) {
        return true;
    }
}

if ( ! function_exists( 'clear_cache' ) ) {
    function clear_cache() {}
}

// ==========================================================
// 7. JSON & AJAX
// ==========================================================
if ( ! function_exists( 'wp_send_json_success' ) ) {
    /**
     * @param mixed $data
     * @param int|null $status_code
     * @param int $flags
     * @return void
     */
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    /**
     * @param mixed $data
     * @param int|null $status_code
     * @param int $flags
     * @return void
     */
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        echo json_encode(['success' => false, 'data' => $data]);
        exit;
    }
}

if ( ! function_exists( 'wp_send_json' ) ) {
    /**
     * @param mixed $response
     * @param int|null $status_code
     * @param int $flags
     * @return void
     */
    function wp_send_json( $response = null, $status_code = null, $flags = 0 ) {
        echo json_encode($response);
        exit;
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    /**
     * @return string
     */
    function wp_generate_uuid4() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// ==========================================================
// 8. SANITIZATION & ESCAPING
// ==========================================================
if ( ! function_exists( 'sanitize_text_field' ) ) {
    /**
     * @param string $str
     * @return string
     */
    function sanitize_text_field( $str ) {
        return is_string($str) ? strip_tags(trim($str)) : '';
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    /**
     * @param string $email
     * @return string
     */
    function sanitize_email( $email ) {
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    /**
     * @param string $title
     * @param string $fallback_title
     * @param string $context
     * @return string
     */
    function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9_-]/', '-', $title);
        return preg_replace('/-+/', '-', $title) ?: $fallback_title;
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
        return (string)$url;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    /**
     * @param string $url
     * @param array|null $protocols
     * @return string
     */
    function esc_url_raw( $url, $protocols = null ) {
        return (string)$url;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    /**
     * @param string $text
     * @return string
     */
    function esc_html( $text ) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    /**
     * @param string $text
     * @return string
     */
    function esc_attr( $text ) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }
}

if ( ! function_exists( 'esc_js' ) ) {
    /**
     * @param string $text
     * @return string
     */
    function esc_js( $text ) {
        return addslashes((string)$text);
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    /**
     * @param mixed ...$args
     * @return string
     */
    function add_query_arg( ...$args ) {
        if (count($args) === 2 && is_array($args[0]) && is_string($args[1])) {
            $url = $args[1];
            $params = http_build_query($args[0]);
            return $url . (strpos($url, '?') !== false ? '&' : '?') . $params;
        }
        return '';
    }
}

// ==========================================================
// 9. USERS & CAPABILITIES
// ==========================================================
if ( ! function_exists( 'is_user_logged_in' ) ) {
    /**
     * @return bool
     */
    function is_user_logged_in() {
        return false;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    /**
     * @param string $capability
     * @param mixed ...$args
     * @return bool
     */
    function current_user_can( $capability, ...$args ) {
        return false;
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    /**
     * @return bool
     */
    function is_admin() {
        return false;
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    /**
     * @return bool
     */
    function wp_doing_ajax() {
        return false;
    }
}

if ( ! function_exists( 'wp_rand' ) ) {
    /**
     * @param int $min
     * @param int $max
     * @return int
     */
    function wp_rand( $min = 0, $max = 0 ) {
        return mt_rand($min, $max);
    }
}

// ==========================================================
// 10. SITE YEAR / SESSION HELPERS
// ==========================================================
if ( ! function_exists( 'get_site_year' ) ) {
    /**
     * @return string
     */
    function get_site_year() {
        return date( 'Y' );
    }
}

if ( ! function_exists( 'get_site_session' ) ) {
    /**
     * @return string
     */
    function get_site_session() {
        return '2026-27';
    }
}

if ( ! function_exists( 'get_site_next_year' ) ) {
    /**
     * @return string
     */
    function get_site_next_year() {
        return '2027';
    }
}

// ==========================================================
// 11. SODE UNIVERSAL COMPONENT RENDER STUBS
// ==========================================================
if ( ! function_exists( 'sode_fetch_remote_component' ) ) {
    /**
     * @param string $component
     * @param array $args
     * @return string
     */
    function sode_fetch_remote_component( $component, $args = array() ) {
        return '';
    }
}

if ( ! function_exists( 'sode_news_marquee_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_news_marquee_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_courses_tabs_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_courses_tabs_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_courses_list_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_courses_list_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_courses_eligibility_table_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_courses_eligibility_table_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_application_process_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_application_process_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_footer_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_footer_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_course_syllabus_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_course_syllabus_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_course_fees_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_course_fees_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_course_specializations_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_course_specializations_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_job_roles_table_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_job_roles_table_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_course_universities_table_render' ) ) {
    /**
     * @param array $atts
     * @return string
     */
    function sode_course_universities_table_render( $atts = array() ) { return ''; }
}

if ( ! function_exists( 'sode_legal_popups_render' ) ) {
    /**
     * @return string
     */
    function sode_legal_popups_render() { return ''; }
}

} // end namespace
