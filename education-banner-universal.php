<?php
/**
 * ====================================================================
 * Plugin Name: Education Banner Section Shortcode (Universal Admin-Connected)
 * Description: Dynamic hero banner shortcode managed universally from SODE Admin Panel
 * Version: 3.0
 * ====================================================================
 * 
 * SHORTCODE USAGE (SIMPLIFIED):
 * [edu_banner
 *     heading="Distance Education Course, Fees, Admission 2026"
 *     subheading="Optional Subheading"
 *     description="Your description text..."
 * ]
 * 
 * Saari baaki cheezein (Desktop/Mobile Banners, Logo, Accreditations,
 * Brochure Form, Podcast Audio, Video Embed, Dates, WhatsApp) seedha
 * SODE Admin Panel se dynamically manage hoti hain.
 * ====================================================================
 */

if ( defined( 'EDUCATION_BANNER_UNIVERSAL_LOADED' ) ) {
    return;
}
define( 'EDUCATION_BANNER_UNIVERSAL_LOADED', true );

// Safe polyfills for WP helpers
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title ) {
        return strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', (string)$title ), '-' ) );
    }
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
    function sanitize_html_class( $class, $fallback = '' ) {
        $sanitized = preg_replace( '/[^\w_-]/', '', (string)$class );
        return $sanitized ?: $fallback;
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( (string)$text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( (string)$text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $url ) {
        return filter_var( (string)$url, FILTER_SANITIZE_URL );
    }
}
if ( ! function_exists( 'shortcode_atts' ) ) {
    function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
        $atts = (array)$atts;
        $out = array();
        foreach ( $pairs as $name => $default ) {
            if ( array_key_exists( $name, $atts ) ) {
                $out[ $name ] = $atts[ $name ];
            } else {
                $out[ $name ] = $default;
            }
        }
        return $out;
    }
}

// Asset URL normalizer
if ( ! function_exists( 'sode_normalize_asset_url' ) ) {
    function sode_normalize_asset_url( $url ) {
        if ( empty( $url ) ) return '';
        $url = trim( $url );
        if ( strpos( $url, 'localhost' ) !== false ) {
            $url = preg_replace( '#https?://localhost[^/]*/subdomain_universal_codes/admin/#i', 'https://admin.distanceeducationschool.com/admin/', $url );
            $url = preg_replace( '#https?://localhost[^/]*/subdomain_universal_codes/#i', 'https://admin.distanceeducationschool.com/admin/', $url );
        }
        if ( strpos( $url, 'http://' ) !== 0 && strpos( $url, 'https://' ) !== 0 && strpos( $url, '//' ) !== 0 ) {
            $url = 'https://admin.distanceeducationschool.com/admin/' . ltrim( $url, '/' );
        }
        return $url;
    }
}

// ---------- CONFIGURATION: Central Admin API Endpoint ----------
if ( ! defined( 'SODE_BANNER_API_URL' ) ) {
    define( 'SODE_BANNER_API_URL', 'https://admin.distanceeducationschool.com/api/get_university_banner.php' );
}

/**
 * Helper: Detect current university slug from Subdomain or Constant
 */
if ( ! function_exists( 'sode_detect_university_slug' ) ) {
    function sode_detect_university_slug( $explicit_slug = '' ) {
        if ( ! empty( $explicit_slug ) ) {
            return sanitize_title( $explicit_slug );
        }

        // If constant defined in wp-config.php or functions.php
        if ( defined( 'SODE_UNIVERSITY_SLUG' ) && SODE_UNIVERSITY_SLUG ) {
            return sanitize_title( SODE_UNIVERSITY_SLUG );
        }

        // Auto detect from HTTP host (e.g., "dsu.distanceeducationschool.com" -> "dsu")
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';
        if ( $host ) {
            $parts = explode( '.', $host );
            if ( count( $parts ) >= 3 ) {
                $subdomain = $parts[0];
                if ( ! in_array( $subdomain, array( 'www', 'mail', 'webmail', 'admin', 'cpanel' ) ) ) {
                    return sanitize_title( $subdomain );
                }
            }
        }

        return 'dsu'; // default fallback
    }
}

/**
 * Fetch University Banner details from Admin (Local DB fallback or HTTP API with caching)
 */
if ( ! function_exists( 'sode_get_university_banner_data' ) ) {
    function sode_get_university_banner_data( $slug ) {
        $slug = sanitize_title( $slug );
        if ( empty( $slug ) ) {
            return false;
        }

        // 1. Direct Local DB Check (if Admin is hosted on the same server/filesystem)
        $local_config = __DIR__ . '/admin/config/config.php';
        if ( file_exists( $local_config ) ) {
            try {
                require_once $local_config;
                if ( function_exists( 'get_db_connection' ) ) {
                    $db = get_db_connection();
                    $stmt = $db->prepare("SELECT * FROM universities WHERE (slug = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?) AND is_active = 1 LIMIT 1");
                    $stmt->execute([$slug, strtolower($slug), strtolower($slug)]);
                    $uni = $stmt->fetch();

                    if ( $uni ) {
                        // Fetch accreditations
                        $acc_stmt = $db->prepare("
                            SELECT a.id, a.title, a.image_url, a.image_url AS badge_image_url, a.description, a.official_link
                            FROM university_accreditations ua
                            INNER JOIN accreditations a ON ua.accreditation_id = a.id
                            WHERE ua.university_id = ?
                            ORDER BY a.title ASC
                        ");
                        $acc_stmt->execute([$uni['id']]);
                        $accreditations = $acc_stmt->fetchAll();

                        // Fetch global keys
                        $keys_stmt = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1");
                        $global_keys = [];
                        while ( $row = $keys_stmt->fetch() ) {
                            $global_keys[$row['key_code']] = $row['key_value'];
                        }

                        return array(
                            'id' => (int)$uni['id'],
                            'full_name' => $uni['full_name'],
                            'short_name' => $uni['short_name'],
                            'slug' => $uni['slug'],
                            'mode' => $uni['mode'] ?? 'Online & Distance',
                            'location' => $uni['location'] ?? '',
                            'official_url' => $uni['official_url'] ?? '',
                            'advantage_text' => $uni['advantage_text'] ?? '',
                            'logo_url' => $uni['logo_url'] ?? '',
                            'desktop_banner_bg' => $uni['desktop_banner_bg'] ?? '',
                            'mobile_banner_bg' => $uni['mobile_banner_bg'] ?? '',
                            'campus_mobile_img' => $uni['campus_mobile_img'] ?? '',
                            'brochure_pdf_url' => $uni['brochure_pdf_url'] ?? '',
                            'podcast_audio_url' => $uni['podcast_audio_url'] ?? '',
                            'youtube_video_url' => $uni['youtube_video_url'] ?? '',
                            'exam_date' => $uni['exam_date'] ?? '',
                            'extended_exam_date' => $uni['extended_exam_date'] ?? '',
                            'admission_last_date' => $uni['admission_last_date'] ?? '',
                            'admission_start_date' => $uni['admission_start_date'] ?? '',
                            'assignment_date' => $uni['assignment_date'] ?? '',
                            'rating' => (float)$uni['rating'],
                            'accreditations' => $accreditations,
                            'global_keys' => $global_keys
                        );
                    }
                }
            } catch ( Exception $e ) {
                // Ignore and fallback to HTTP API
            }
        }

        // 2. HTTP Remote API (for remote subdomains)
        $transient_key = 'sode_uni_banner_' . md5( $slug );
        
        // Check transient cache
        $force_refresh = isset( $_GET['refresh_cache'] ) || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && isset( $_GET['preview'] ) );
        if ( ! $force_refresh && function_exists( 'get_transient' ) ) {
            $cached = get_transient( $transient_key );
            if ( ! empty( $cached ) && is_array( $cached ) ) {
                return $cached;
            }
        }

        $api_url = add_query_arg( array(
            'slug' => $slug,
            't'    => time()
        ), SODE_BANNER_API_URL );

        if ( function_exists( 'wp_remote_get' ) ) {
            $response = wp_remote_get( $api_url, array(
                'timeout' => 8,
                'headers' => array( 'Cache-Control' => 'no-cache' )
            ) );

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $body = wp_remote_retrieve_body( $response );
                $json = json_decode( $body, true );
                if ( ! empty( $json['success'] ) && ! empty( $json['data'] ) ) {
                    if ( function_exists( 'set_transient' ) ) {
                        // Cache for 10 minutes
                        set_transient( $transient_key, $json['data'], 600 );
                    }
                    return $json['data'];
                }
            }
        }

        return false;
    }
}

/**
 * ============================================================
 * SHORTCODE: [edu_banner ...]
 * ============================================================
 */
function edu_banner_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'heading'            => 'Distance Education Course, Fees, Admission 2026',
        'subheading'         => '',
        'description'        => 'Earn your degree without attending regular college with our Distance Education programs.',
        'audio_url'          => '',
        'video_url'          => '',
        'brochure_btn_class' => '',
        'university'         => '',
    ), $atts, 'edu_banner' );

    $heading            = esc_html( $atts['heading'] );
    $subheading         = esc_html( $atts['subheading'] );
    $description        = esc_html( $atts['description'] );
    $audio_url          = esc_url( $atts['audio_url'] );
    $video_url          = esc_url( $atts['video_url'] );
    $brochure_btn_class = ! empty( $atts['brochure_btn_class'] ) ? sanitize_html_class( $atts['brochure_btn_class'] ) : 'download-brochure';

    // 1. Resolve university data from Admin Panel
    $uni_slug = sode_detect_university_slug( $atts['university'] );
    $uni_data = sode_get_university_banner_data( $uni_slug );

    // Fallbacks from Admin Data if not supplied in shortcode attributes
    $full_uni_name       = ! empty( $uni_data['full_name'] ) ? $uni_data['full_name'] : 'Dayananda Sagar University';
    $short_uni_name      = ! empty( $uni_data['short_name'] ) ? $uni_data['short_name'] : 'DSU';
    $mode_text           = ! empty( $uni_data['mode'] ) ? $uni_data['mode'] : 'Online';
    $desktop_bg          = sode_normalize_asset_url(! empty( $uni_data['desktop_banner_bg'] ) ? $uni_data['desktop_banner_bg'] : 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/DSU_Desktop.png');
    $mobile_bg           = sode_normalize_asset_url(! empty( $uni_data['mobile_banner_bg'] ) ? $uni_data['mobile_banner_bg'] : 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/07/mobile_new_bg_main.png');
    $logo_url            = sode_normalize_asset_url(! empty( $uni_data['logo_url'] ) ? $uni_data['logo_url'] : 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/DSU-online-Logo-2.png');
    $campus_mobile_img   = sode_normalize_asset_url(! empty( $uni_data['campus_mobile_img'] ) ? $uni_data['campus_mobile_img'] : 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/DSU-Mobile-Image-2.png');
    $db_admission_date   = ! empty( $uni_data['admission_last_date'] ) ? $uni_data['admission_last_date'] : '';

    // Global keys & Dynamic replacements
    $top_heading_text = ! empty( $uni_data['global_keys']['$BANNER_TOP_TEXT$'] ) ? $uni_data['global_keys']['$BANNER_TOP_TEXT$'] : ( ! empty( $uni_data['global_keys']['BANNER_TOP_TEXT'] ) ? $uni_data['global_keys']['BANNER_TOP_TEXT'] : 'Welcome to SODE™ (School of Online and Distance Education)' );

    if ( ! empty( $uni_data['global_keys'] ) && is_array( $uni_data['global_keys'] ) ) {
        foreach ( $uni_data['global_keys'] as $gk_key => $gk_val ) {
            if ( is_string( $gk_val ) ) {
                $heading     = str_replace( $gk_key, $gk_val, $heading );
                $subheading  = str_replace( $gk_key, $gk_val, $subheading );
                $description = str_replace( $gk_key, $gk_val, $description );
            }
        }
    }
    // Guaranteed $YEAR$ replacement fallback
    $current_year = ! empty( $uni_data['global_keys']['$YEAR$'] ) ? $uni_data['global_keys']['$YEAR$'] : date('Y');
    $heading     = str_replace( '$YEAR$', $current_year, $heading );
    $subheading  = str_replace( '$YEAR$', $current_year, $subheading );
    $description = str_replace( '$YEAR$', $current_year, $description );

    // If audio_url not in shortcode, take from Admin Panel
    if ( empty( $audio_url ) && ! empty( $uni_data['podcast_audio_url'] ) ) {
        $audio_url = esc_url( $uni_data['podcast_audio_url'] );
    }

    // If video_url not in shortcode, take from Admin Panel
    if ( empty( $video_url ) && ! empty( $uni_data['youtube_video_url'] ) ) {
        $video_url = esc_url( $uni_data['youtube_video_url'] );
    }

    // WhatsApp Dynamic Details
    $wa_phone = ! empty( $uni_data['global_keys']['whatsapp_number'] ) ? preg_replace('/[^0-9+]/', '', $uni_data['global_keys']['whatsapp_number']) : '+917065777755';
    $wa_text  = 'I want to Download ' . $full_uni_name . ' ' . $mode_text . ' Brochure';
    $wa_url   = 'https://api.whatsapp.com/send/?phone=' . urlencode( $wa_phone ) . '&text=' . urlencode( $wa_text );

    /* ---- Convert YouTube URL to embed URL ---- */
    $yt_embed = '';
    if ( $video_url ) {
        if ( preg_match( '/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|v\/))([A-Za-z0-9_\-]{11})/', $video_url, $m ) ) {
            $yt_embed = 'https://www.youtube.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
        } else {
            $yt_embed = $video_url;
        }
    }

    /* Unique ID so multiple banners on one page don't clash */
    $uid = 'edb-' . substr( md5( $heading . $audio_url . $video_url . $uni_slug ), 0, 8 );

    /* Detect home vs inner page */
    $page_class = ( function_exists( 'is_front_page' ) && ( is_front_page() || is_home() ) ) ? 'is-homepage' : 'is-innerpage';

    ob_start();
    ?>
    <!-- ======================================================
         STYLES  (scoped to uid so multiple banners are safe)
         ====================================================== -->
    <style>
        /* ----- RESET ----- */
        #<?php echo $uid; ?> *,
        #<?php echo $uid; ?> *::before,
        #<?php echo $uid; ?> *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        #<?php echo $uid; ?> {
            position: relative;
            width: 100%;
        }

        .edu-banner-right.is-form input:not([type="checkbox"]), .edu-banner-right.is-form select {
            padding: 7px 12px !important;
            margin-bottom: 9px !important;
        }

        .edu-banner-right.is-form .submitBtn {
            padding: 11px !important;
        }

        /* =====================================================
           DESKTOP  (≥ 769px)
           ===================================================== */
        @media (min-width: 769px) {

            #<?php echo $uid; ?> .edu-banner-desktop {
                display: block;
                width: 100%;
                background-image: url("<?php echo esc_url( $desktop_bg ); ?>");
                background-size: cover;
                background-position: center top;
                background-repeat: no-repeat;
                min-height: 520px;
            }

            #<?php echo $uid; ?> .edu-banner-desktop-inner {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 1200px;
                max-width: 90%;
                margin: 0 auto;
                padding: 40px 0;
                gap: 60px;
            }

            /* LEFT */
            #<?php echo $uid; ?> .edu-banner-left {
                flex: 1 1 auto;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            #<?php echo $uid; ?> .top_heading {
                font-size: 15px;
                font-weight: 600;
                color: #1a2e5a;
                margin-bottom: 2px;
            }

            #<?php echo $uid; ?> .edu-banner-logo {
                width: auto;
                max-width: 240px;
                max-height: 65px;
                object-fit: contain;
                display: block;
            }

            #<?php echo $uid; ?> .edu-banner-heading {
                font-weight: 800;
                color: #1a2e5a;
                line-height: 1.25;
                text-align: left;
                width: 85%;
                font-size: 32px;
            }

            /* Subheading */
            #<?php echo $uid; ?> .edu-banner-subheading {
                font-size: 15px;
                font-weight: 600;
                color: #111;
                text-align: left;
                margin-top: -4px;
            }

            #<?php echo $uid; ?> .edu-banner-desc {
                font-size: 13.5px;
                color: #333;
                line-height: 1.6;
                text-align: left;
                max-width: 440px;
            }

            /* Admission Last Date */
            #<?php echo $uid; ?> .edu-admission-date-text {
                font-size: 14.5px;
                font-weight: 700;
                color: #d90429;
            }

            /* Green WhatsApp Button */
            #<?php echo $uid; ?> .custom_whatsapp_brochure_btn a {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #189823;
                color: #ffffff;
                padding: 9px 18px;
                border-radius: 6px;
                font-weight: 600;
                font-size: 14px;
                text-decoration: none;
                transition: background 0.2s;
                box-shadow: 0 3px 10px rgba(24, 152, 35, 0.25);
            }
            #<?php echo $uid; ?> .custom_whatsapp_brochure_btn a:hover {
                background: #13791c;
                color: #ffffff;
            }
            #<?php echo $uid; ?> .custom_whatsapp_brochure_btn svg {
                width: 17px;
                height: 17px;
                fill: currentColor;
                flex-shrink: 0;
            }

            /* ---- BUTTON GROUP ---- */
            #<?php echo $uid; ?> .edu-banner-btn-wrap {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-top: 4px;
            }

            /* Podcast button */
            #<?php echo $uid; ?> .edu-podcast-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 14px;
                background: #fff;
                color: #074A76;
                border: 2px solid #074A76;
                border-radius: 5px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                width: fit-content;
                transition: background 0.2s, color 0.2s;
            }
            #<?php echo $uid; ?> .edu-podcast-btn:hover {
                background: #074A76;
                color: #fff;
            }

            /* Counseling button */
            #<?php echo $uid; ?> .edu-banner-btn-wrap button.applynow {
                padding: 8px 14px;
                background: #fff;
                color: #074A76;
                border-radius: 5px;
                font-size: 13px;
                font-weight: 600;
                border: 2px solid #074A76;
                cursor: pointer;
                width: fit-content;
            }

            /* Brochure button */
            #<?php echo $uid; ?> .edu-brochure-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 14px;
                background: #074A76;
                color: #fff;
                border: 2px solid #074A76;
                border-radius: 5px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                width: fit-content;
                transition: background 0.2s, color 0.2s;
            }
            #<?php echo $uid; ?> .edu-brochure-btn:hover {
                background: #074A76;
                color: #fff;
            }

            /* RIGHT column – FORM */
            #<?php echo $uid; ?> .edu-banner-right.is-form {
                flex: 0 0 380px;
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 10px 36px rgba(0,0,0,0.18);
                padding: 22px;
                align-self: center;
            }

            /* RIGHT column – VIDEO */
            #<?php echo $uid; ?> .edu-banner-right.is-video {
                flex: 0 0 540px;
                background: transparent;
                border-radius: 0;
                box-shadow: none;
                padding: 0;
                align-self: center;
            }

            #<?php echo $uid; ?> .edu-yt-wrap {
                position: relative;
                width: 100%;
                padding-top: 56.25%;
                overflow: hidden;
                box-shadow: 0 8px 32px rgba(0,0,0,0.22);
                border-radius: 10px;
            }
            #<?php echo $uid; ?> .edu-yt-wrap iframe {
                position: absolute;
                inset: 0;
                width: 100%;
                height: 100%;
                border: none;
            }

            /* Hide mobile on desktop */
            #<?php echo $uid; ?> .edu-banner-mobile {
                display: none !important;
            }
        }

        /* =====================================================
           DYNAMIC ACCREDITATIONS GOLDEN BAR (Desktop & Mobile)
           ===================================================== */
        #<?php echo $uid; ?> .edu-banner-approvals-bar {
            width: 100%;
            background: #ffc800;
            padding: 34px 20px 38px;
            box-sizing: border-box;
        }
        #<?php echo $uid; ?> .edu-banner-approvals-container {
            width: 1220px;
            max-width: 95%;
            margin: 0 auto;
            text-align: center;
        }
        #<?php echo $uid; ?> .edu-approvals-title {
            font-size: 26px;
            font-weight: 800;
            color: #1a2e5a;
            margin-bottom: 24px;
            text-align: center;
            line-height: 1.3;
        }
        #<?php echo $uid; ?> .edu-approvals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            justify-content: center;
            align-items: stretch;
        }
        #<?php echo $uid; ?> .edu-approval-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            text-align: left;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        #<?php echo $uid; ?> .edu-approval-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        }
        #<?php echo $uid; ?> .edu-approval-logo-wrap {
            width: 58px;
            height: 58px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            border-radius: 8px;
        }
        #<?php echo $uid; ?> .edu-approval-logo-wrap img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }
        #<?php echo $uid; ?> .edu-approval-info {
            flex: 1;
        }
        #<?php echo $uid; ?> .edu-approval-name {
            font-size: 16px;
            font-weight: 800;
            color: #1a2e5a;
            margin-bottom: 3px;
            line-height: 1.2;
        }
        #<?php echo $uid; ?> .edu-approval-desc {
            font-size: 11.5px;
            color: #333333;
            line-height: 1.4;
            font-weight: 500;
        }

        /* =====================================================
           MOBILE  (≤ 768px)
           ===================================================== */
        @media (max-width: 768px) {

            #<?php echo $uid; ?> .edu-banner-desktop {
                display: none !important;
            }

            #<?php echo $uid; ?> .edu-banner-mobile {
                display: flex;
                flex-direction: column;
                align-items: center;
                width: 100%;
                overflow: hidden;
                background-image: url("<?php echo esc_url( $mobile_bg ); ?>");
                background-size: cover;
                background-position: center top;
                background-repeat: no-repeat;
            }

            #<?php echo $uid; ?> .edu-mobile-hero {
                width: 100%;
                padding: 16px 14px 24px;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 12px;
                text-align: center;
            }

            #<?php echo $uid; ?> .top_heading {
                text-align: center;
                width: 95%;
                font-size: 13.5px;
                font-weight: 600;
                color: #1a2e5a;
                line-height: 1.35;
            }

            #<?php echo $uid; ?> .edu-banner-heading {
                font-weight: 800;
                color: #1a2e5a;
                line-height: 1.25;
                text-align: center;
                padding: 0px 10px;
                font-size: 24px;
            }

            #<?php echo $uid; ?> .edu-banner-logo {
                width: auto;
                max-width: 220px;
                max-height: 60px;
                height: auto;
                object-fit: contain;
                display: block;
                margin: 4px auto;
            }

            #<?php echo $uid; ?> .edu-mobile-img-wrap {
                width: 100%;
                margin: 6px 0;
                padding: 0;
                line-height: 0;
            }
            #<?php echo $uid; ?> .edu-mobile-img-wrap img {
                width: 100%;
                max-width: 420px;
                margin: 0 auto;
                height: auto;
                display: block;
                border-radius: 8px;
            }

            #<?php echo $uid; ?> .edu-banner-subheading {
                font-size: 13px;
                font-weight: 600;
                text-align: center;
            }

            #<?php echo $uid; ?> .edu-banner-desc {
                font-size: 12.5px;
                color: #333;
                line-height: 1.55;
                text-align: center;
                padding: 0 10px;
            }

            #<?php echo $uid; ?> .edu-admission-date-text {
                font-size: 13.5px;
                font-weight: 700;
                color: #d90429;
                margin: 4px 0;
            }

            /* Green WhatsApp Button Mobile */
            #<?php echo $uid; ?> .custom_whatsapp_brochure_btn a {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #189823;
                color: #ffffff;
                padding: 10px 22px;
                border-radius: 6px;
                font-weight: 600;
                font-size: 14px;
                text-decoration: none;
                box-shadow: 0 3px 10px rgba(24, 152, 35, 0.25);
            }
            #<?php echo $uid; ?> .custom_whatsapp_brochure_btn svg {
                width: 18px;
                height: 18px;
                fill: currentColor;
            }

            /* Accreditations Mobile Styles */
            #<?php echo $uid; ?> .edu-banner-approvals-bar {
                padding: 26px 16px 30px;
            }
            #<?php echo $uid; ?> .edu-approvals-title {
                font-size: 20px;
                margin-bottom: 18px;
                line-height: 1.3;
            }
            #<?php echo $uid; ?> .edu-approvals-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            #<?php echo $uid; ?> .edu-approval-card {
                border-radius: 14px;
                padding: 14px 16px;
            }
            #<?php echo $uid; ?> .edu-approval-logo-wrap {
                width: 52px;
                height: 52px;
            }
            #<?php echo $uid; ?> .edu-approval-name {
                font-size: 15px;
            }
            #<?php echo $uid; ?> .edu-approval-desc {
                font-size: 11.5px;
            }

            /* Mobile Form Section */
            #<?php echo $uid; ?> .edu-mobile-form-section {
                width: calc(100% - 24px);
                margin: 20px 12px;
            }
            #<?php echo $uid; ?> .edu-banner-right.is-form {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 6px 24px rgba(0,0,0,0.15);
                padding: 22px 16px;
                width: 100%;
            }
        }

        /* =====================================================
           PODCAST & BROCHURE POPUPS (shared)
           ===================================================== */
        .edu-podcast-overlay, .edu-brochure-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .edu-podcast-overlay.active, .edu-brochure-overlay.active {
            display: flex;
        }
        .edu-podcast-modal, .edu-brochure-modal {
            background: #fff;
            border-radius: 16px;
            padding: 32px 28px;
            max-width: 480px;
            width: 100%;
            position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            animation: eduPopIn 0.25s ease;
        }
        @keyframes eduPopIn {
            from { transform: scale(0.92); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }
        .edu-podcast-modal .edu-pm-close, .edu-brochure-modal .edu-bm-close {
            position: absolute;
            top: 14px;
            right: 16px;
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #666;
            line-height: 1;
        }
        .edu-podcast-modal .edu-pm-close:hover, .edu-brochure-modal .edu-bm-close:hover {
            color: #111;
        }
    </style>

    <!-- ===========================================================
         DESKTOP MARKUP
         =========================================================== -->
    <div id="<?php echo $uid; ?>">
        <div class="edu-banner-desktop">
            <div class="edu-banner-desktop-inner">

                <!-- LEFT -->
                <div class="edu-banner-left">

                    <!-- Top Heading (From Global Keys) -->
                    <p class="top_heading"><?php echo esc_html( $top_heading_text ); ?></p>

                    <!-- Heading (with $YEAR$ auto replaced) -->
                    <h1 class="edu-banner-heading <?php echo $page_class; ?>"><?php echo $heading; ?></h1>

                    <!-- Dynamic Logo from Admin Panel -->
                    <?php if ( ! empty( $logo_url ) ) : ?>
                    <img
                        class="edu-banner-logo"
                        src="<?php echo esc_url( $logo_url ); ?>"
                        alt="<?php echo esc_attr( $full_uni_name ); ?> Logo"
                    />
                    <?php endif; ?>

                    <!-- Subheading (only if provided) -->
                    <?php if ( $subheading ) : ?>
                        <h2 class="edu-banner-subheading"><?php echo $subheading; ?></h2>
                    <?php endif; ?>

                    <!-- Description -->
                    <p class="edu-banner-desc"><?php echo $description; ?></p>
                    
                    <!-- Dynamic Last date of Admission from Admin Panel -->
                    <p class="edu-admission-date-text">
                        Last date of Admission : <strong class="admission-date"><?php echo esc_html( $db_admission_date ); ?></strong>
                    </p>

                    <!-- Dynamic WhatsApp Download Brochure Link -->
                    <div class="custom_whatsapp_brochure_btn">
                        <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank">
                            <svg viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/>
                            </svg>
                            Download Brochure
                        </a>
                    </div>

                    <!-- Additional Buttons if Podcast Audio exists in Admin -->
                    <?php if ( $audio_url ) : ?>
                    <div class="edu-banner-btn-wrap">
                        <button
                            type="button"
                            class="edu-podcast-btn"
                            onclick="document.getElementById('<?php echo $uid; ?>-popup').classList.add('active')"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                                <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                                <line x1="12" y1="19" x2="12" y2="23"/>
                                <line x1="8" y1="23" x2="16" y2="23"/>
                            </svg>
                            Listen Podcast
                        </button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- RIGHT – Video OR Lead Form -->
                <div class="edu-banner-right <?php echo $yt_embed ? 'is-video' : 'is-form'; ?>" id="edu-form">
                    <?php if ( $yt_embed ) : ?>
                        <div class="edu-yt-wrap">
                            <iframe
                                src="<?php echo esc_url( $yt_embed ); ?>"
                                title="Video"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen
                            ></iframe>
                        </div>
                    <?php else : ?>
                        <?php echo function_exists('custom_lead_form_shortcode') ? custom_lead_form_shortcode(['university' => $uni_slug]) : (function_exists('do_shortcode') ? do_shortcode('[custom_lead_form university="' . esc_attr($uni_slug) . '"]') : ''); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- =====================================================
             DESKTOP DYNAMIC ACCREDITATIONS GOLDEN BAR
             ===================================================== -->
        <?php if ( ! empty( $uni_data['accreditations'] ) && is_array( $uni_data['accreditations'] ) ) : ?>
        <div class="edu-banner-approvals-bar">
            <div class="edu-banner-approvals-container">
                <h3 class="edu-approvals-title"><?php echo esc_html( $full_uni_name . ' ' . $mode_text ); ?> Approvals & Accreditations</h3>
                <div class="edu-approvals-grid">
                    <?php foreach ( $uni_data['accreditations'] as $acc ) : ?>
                        <div class="edu-approval-card">
                            <?php $acc_img = sode_normalize_asset_url( ! empty( $acc['image_url'] ) ? $acc['image_url'] : ( ! empty( $acc['badge_image_url'] ) ? $acc['badge_image_url'] : '' ) ); ?>
                            <?php if ( ! empty( $acc_img ) ) : ?>
                                <div class="edu-approval-logo-wrap">
                                    <img src="<?php echo esc_url( $acc_img ); ?>" alt="<?php echo esc_attr( $acc['title'] ); ?>" />
                                </div>
                            <?php endif; ?>
                            <div class="edu-approval-info">
                                <div class="edu-approval-name"><?php echo esc_html( $acc['title'] ); ?></div>
                                <?php if ( ! empty( $acc['description'] ) ) : ?>
                                    <div class="edu-approval-desc"><?php echo esc_html( $acc['description'] ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ===========================================================
             MOBILE MARKUP
             =========================================================== -->
        <div class="edu-banner-mobile">

            <?php if ( $yt_embed ) : ?>
            <div class="edu-mobile-video-top" style="width:100%; padding:14px 14px 0;">
                <div class="edu-yt-wrap">
                    <iframe
                        src="<?php echo esc_url( $yt_embed ); ?>"
                        title="Video"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                    ></iframe>
                </div>
            </div>
            <?php endif; ?>

            <!-- Hero text -->
            <div class="edu-mobile-hero">

                <!-- Top Heading -->
                <p class="top_heading"><?php echo esc_html( $top_heading_text ); ?></p>

                <div class="edu-banner-heading <?php echo $page_class; ?>"><?php echo $heading; ?></div>
                
                <!-- Dynamic Logo -->
                <?php if ( ! empty( $logo_url ) ) : ?>
                <img
                    class="edu-banner-logo"
                    src="<?php echo esc_url( $logo_url ); ?>"
                    alt="<?php echo esc_attr( $full_uni_name ); ?> Logo"
                />
                <?php endif; ?>
                
                <!-- University Campus Mobile Image -->
                <?php if ( ! empty( $campus_mobile_img ) ) : ?>
                <div class="edu-mobile-img-wrap">
                    <img
                        src="<?php echo esc_url( $campus_mobile_img ); ?>"
                        alt="<?php echo esc_attr( $full_uni_name ); ?> Campus"
                    />
                </div>
                <?php endif; ?>

                <?php if ( $subheading ) : ?>
                    <h2 class="edu-banner-subheading"><?php echo $subheading; ?></h2>
                <?php endif; ?>

                <p class="edu-banner-desc"><?php echo $description; ?></p>
                
                <!-- Dynamic Last date of Admission from Admin Panel -->
                <p class="edu-admission-date-text">
                    Last date of Admission : <strong class="admission-date"><?php echo esc_html( $db_admission_date ); ?></strong>
                </p>
                
                <div class="custom_whatsapp_brochure_btn">
                    <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank">
                        <svg viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/>
                        </svg>
                        Download Brochure
                    </a>
                </div>
            </div>

            <!-- =====================================================
                 MOBILE DYNAMIC ACCREDITATIONS GOLDEN BAR
                 ===================================================== -->
            <?php if ( ! empty( $uni_data['accreditations'] ) && is_array( $uni_data['accreditations'] ) ) : ?>
            <div class="edu-banner-approvals-bar">
                <div class="edu-banner-approvals-container">
                    <h3 class="edu-approvals-title"><?php echo esc_html( $full_uni_name . ' ' . $mode_text ); ?><br>Approvals & Accreditations</h3>
                    <div class="edu-approvals-grid">
                        <?php foreach ( $uni_data['accreditations'] as $acc ) : ?>
                            <div class="edu-approval-card">
                                <?php $acc_img = sode_normalize_asset_url( ! empty( $acc['image_url'] ) ? $acc['image_url'] : ( ! empty( $acc['badge_image_url'] ) ? $acc['badge_image_url'] : '' ) ); ?>
                                <?php if ( ! empty( $acc_img ) ) : ?>
                                    <div class="edu-approval-logo-wrap">
                                        <img src="<?php echo esc_url( $acc_img ); ?>" alt="<?php echo esc_attr( $acc['title'] ); ?>" />
                                    </div>
                                <?php endif; ?>
                                <div class="edu-approval-info">
                                    <div class="edu-approval-name"><?php echo esc_html( $acc['title'] ); ?></div>
                                    <?php if ( ! empty( $acc['description'] ) ) : ?>
                                        <div class="edu-approval-desc"><?php echo esc_html( $acc['description'] ); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Form section -->
            <div class="edu-mobile-form-section">
                <div class="edu-banner-right is-form" id="edu-form-mobile">
                    <?php echo function_exists('custom_lead_form_shortcode') ? custom_lead_form_shortcode(['university' => $uni_slug]) : (function_exists('do_shortcode') ? do_shortcode('[custom_lead_form university="' . esc_attr($uni_slug) . '"]') : ''); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ( $audio_url ) : ?>
    <!-- ===========================================================
         PODCAST POPUP
         =========================================================== -->
    <div class="edu-podcast-overlay" id="<?php echo $uid; ?>-popup">
        <div class="edu-podcast-modal">
            <button
                class="edu-pm-close"
                type="button"
                onclick="
                    document.getElementById('<?php echo $uid; ?>-popup').classList.remove('active');
                    var a = document.getElementById('<?php echo $uid; ?>-audio');
                    if(a){ a.pause(); a.currentTime = 0; }
                "
            >&#x2715;</button>

            <p class="edu-pm-title" style="font-size:18px; font-weight:700; color:#1a2e5a; margin-bottom:6px;"><?php echo $heading; ?></p>
            <p style="font-size:13px; color:#777; margin-bottom:20px;">Listen to our podcast for more information</p>

            <audio id="<?php echo $uid; ?>-audio" controls preload="metadata" style="width:100%;">
                <source src="<?php echo esc_url( $audio_url ); ?>" type="audio/mpeg">
                Your browser does not support the audio element.
            </audio>

            <p style="margin-top:16px; font-size:12px; color:#aaa;">&#x1F3A7; Powered by SODE</p>
        </div>
    </div>

    <script>
    (function(){
        var overlay = document.getElementById('<?php echo $uid; ?>-popup');
        if( !overlay ) return;
        overlay.addEventListener('click', function(e){
            if( e.target === overlay ){
                overlay.classList.remove('active');
                var a = document.getElementById('<?php echo $uid; ?>-audio');
                if(a){ a.pause(); a.currentTime = 0; }
            }
        });
    })();
    </script>
    <?php endif; ?>

    <!-- Real-time Admission Date Fallback (if date is not manually set in Admin) -->
    <script>
    (function(){
        const admissionEls = document.querySelectorAll("#<?php echo $uid; ?> .admission-date");
        admissionEls.forEach(el => {
            if (!el.textContent.trim()) {
                const d = new Date();
                const date = d.getDate() <= 15 ? 15 : new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
                el.textContent = date + " " + d.toLocaleString("en-US", { month: "long" }) + " " + d.getFullYear();
            }
        });
    })();
    </script>

    <?php
    return ob_get_clean();
}
if ( function_exists( 'add_shortcode' ) ) {
    add_shortcode( 'edu_banner', 'edu_banner_shortcode' );
}
