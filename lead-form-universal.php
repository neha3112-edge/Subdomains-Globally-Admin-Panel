<?php
/**
 * ====================================================================
 * Plugin Name: SODE Lead Form & CRM Integration (Universal Admin-Connected)
 * Description: Universal multi-endpoint lead capture form dynamically connected to SODE Admin Panel
 * Version: 3.0
 * ====================================================================
 */

if ( ! function_exists( 'add_action' ) ) {
    http_response_code( 403 );
    exit;
}

if ( defined( 'SODE_LEAD_FORM_UNIVERSAL_LOADED' ) ) {
    return;
}
define( 'SODE_LEAD_FORM_UNIVERSAL_LOADED', true );

// ---------- CONFIGURATION: Central Admin API Endpoint ----------
if ( ! defined( 'SODE_FORM_CONFIG_API_URL' ) ) {
    define( 'SODE_FORM_CONFIG_API_URL', 'https://admin.distanceeducationschool.com/api/get_form_config.php' );
}

/**
 * Helper: Detect current university slug
 */
if ( ! function_exists( 'sode_form_detect_uni_slug' ) ) {
    function sode_form_detect_uni_slug( $explicit = '' ) {
        if ( ! empty( $explicit ) ) {
            return sanitize_title( $explicit );
        }
        if ( defined( 'SODE_UNIVERSITY_SLUG' ) && SODE_UNIVERSITY_SLUG ) {
            return sanitize_title( SODE_UNIVERSITY_SLUG );
        }
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( $_SERVER['HTTP_HOST'] ) : '';
        if ( $host ) {
            $parts = explode( '.', $host );
            if ( count( $parts ) >= 3 && ! in_array( $parts[0], array( 'www', 'mail', 'webmail', 'admin', 'cpanel' ) ) ) {
                return sanitize_title( $parts[0] );
            }
        }
        return 'dsu';
    }
}

/**
 * Helper: Fetch University Form & Lead Configurations
 */
if ( ! function_exists( 'sode_get_university_form_config' ) ) {
    function sode_get_university_form_config( $slug = '' ) {
        $slug = sode_form_detect_uni_slug( $slug );

        // 1. Check local DB connection if available on same server
        $local_config = __DIR__ . '/admin/config/config.php';
        if ( file_exists( $local_config ) ) {
            try {
                require_once $local_config;
                if ( function_exists( 'get_db_connection' ) ) {
                    $db = get_db_connection();

                    // Global API settings
                    $global_rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'integrations'")->fetchAll(PDO::FETCH_KEY_PAIR);

                    $stmt = $db->prepare("
                        SELECT ufc.*, u.full_name, u.short_name, u.slug, u.brochure_pdf_url, u.mode
                        FROM university_form_configs ufc 
                        INNER JOIN universities u ON ufc.university_id = u.id 
                        WHERE (u.slug = ? OR LOWER(u.short_name) = ?) AND u.is_active = 1
                        LIMIT 1
                    ");
                    $stmt->execute([$slug, strtolower($slug)]);
                    $row = $stmt->fetch();
                    if ( $row ) {
                        $row['crm_api_url']          = $global_rows['crm_api_url'] ?? (getenv('CRM_API_URL') ?: 'https://api.crm.mysode.com/api/lead/apicreated');
                        $row['crm_api_key']          = $global_rows['crm_api_key'] ?? (getenv('CRM_API_KEY') ?: '');
                        $row['crm_secret']           = $global_rows['crm_secret'] ?? (getenv('CRM_SECRET') ?: '');
                        $row['brevo_api_url']        = $global_rows['brevo_api_url'] ?? (getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/contacts');
                        $row['brevo_api_key']        = $global_rows['brevo_api_key'] ?? (getenv('BREVO_API_KEY') ?: '');
                        $row['gallabox_webhook_url'] = $global_rows['gallabox_webhook_url'] ?? (getenv('GALLABOX_WEBHOOK_URL') ?: '');
                        return $row;
                    }
                }
            } catch ( Exception $e ) {
                // fallback to HTTP API
            }
        }

        // 2. HTTP Remote API with Transient Cache
        $transient_key = 'sode_form_cfg_' . md5( $slug );
        if ( function_exists( 'get_transient' ) ) {
            $cached = get_transient( $transient_key );
            if ( ! empty( $cached ) && is_array( $cached ) ) {
                return $cached;
            }
        }

        if ( function_exists( 'wp_remote_get' ) ) {
            $api_url = add_query_arg( array( 'uni' => $slug, 't' => time() ), SODE_FORM_CONFIG_API_URL );
            $resp = wp_remote_get( $api_url, array( 'timeout' => 8, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $body = wp_remote_retrieve_body( $resp );
                $json = json_decode( $body, true );
                if ( ! empty( $json['success'] ) ) {
                    if ( function_exists( 'set_transient' ) ) {
                        set_transient( $transient_key, $json, 600 );
                    }
                    return $json;
                }
            }
        }

        // 3. Fallback defaults
        return array(
            'source' => 'MISC',
            'default_utm_source' => 'Organic',
            'default_utm_medium' => 'DSU_Organic',
            'default_utm_campaign' => 'DSU_Organic',
            'gallabox_source' => 'MISC',
            'gallabox_webhook_url' => getenv('GALLABOX_WEBHOOK_URL') ?: '',
            'brevo_source' => 'MISC',
            'brevo_list_id' => 124,
            'brevo_api_url' => getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/contacts',
            'brevo_api_key' => getenv('BREVO_API_KEY') ?: '',
            'crm_api_url' => getenv('CRM_API_URL') ?: 'https://api.crm.mysode.com/api/lead/apicreated',
            'crm_api_key' => getenv('CRM_API_KEY') ?: '',
            'crm_secret' => getenv('CRM_SECRET') ?: '',
            'allowed_courses_json' => 'MBA, MCA, MCOM, MA, MSC, MLIS, BBA, BCA, BCOM, BA, BSC, BLIS, Other'
        );
    }
}


// ====================================================
// 🔥 STEP 1 — EARLY TOKEN BLOCK (init hook)
// ====================================================
add_action('init', function () {

    if (
        isset($_POST['action']) &&
        $_POST['action'] === 'send_lead'
    ) {
        $token = $_SERVER['HTTP_X_LEAD_TOKEN'] ?? '';

        if (!$token || !function_exists('get_transient') || !get_transient('lead_token_' . $token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'data' => 'Forbidden - Invalid Token']);
            exit();
        }
    }
});


// ====================================================
// ✅ STEP 2 — TOKEN GENERATOR
// ====================================================
add_action('wp_ajax_get_lead_token', 'sode_generate_lead_token');
add_action('wp_ajax_nopriv_get_lead_token', 'sode_generate_lead_token');

function sode_generate_lead_token()
{
    $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : md5(uniqid(rand(), true));
    if ( function_exists('set_transient') ) {
        set_transient('lead_token_' . $token, true, (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400));
    }
    if ( function_exists('wp_send_json_success') ) {
        wp_send_json_success($token);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $token]);
        exit;
    }
}


// ====================================================
// ✅ STEP 3 — LEAD SENDER (Connected to Admin Integrations)
// ====================================================
add_action('wp_ajax_send_lead', 'sode_send_lead_to_crm');
add_action('wp_ajax_nopriv_send_lead', 'sode_send_lead_to_crm');

function sode_send_lead_to_crm()
{
    // Token consume (one-time use)
    $token = $_SERVER['HTTP_X_LEAD_TOKEN'] ?? '';
    if ( function_exists('delete_transient') ) {
        delete_transient('lead_token_' . $token);
    }

    // ✅ HONEYPOT
    if (!empty($_POST['website'])) {
        if ( function_exists('wp_send_json_error') ) {
            wp_send_json_error('Spam detected');
        } else {
            echo json_encode(['success' => false, 'data' => 'Spam detected']);
            exit;
        }
    }

    // ✅ RATE LIMIT
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $key = 'lead_limit_' . md5($ip);

    if ( function_exists('get_transient') && get_transient($key) ) {
        if ( function_exists('wp_send_json_error') ) {
            wp_send_json_error('Too many requests. Please wait.');
        } else {
            echo json_encode(['success' => false, 'data' => 'Too many requests. Please wait.']);
            exit;
        }
    }

    if ( function_exists('set_transient') ) {
        set_transient($key, true, 30);
    }

    // ✅ VALIDATION
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');

    if (strlen($phone) < 10) {
        if ( function_exists('wp_send_json_error') ) {
            wp_send_json_error('Invalid phone number');
        } else {
            echo json_encode(['success' => false, 'data' => 'Invalid phone number']);
            exit;
        }
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if ( function_exists('wp_send_json_error') ) {
            wp_send_json_error('Invalid email address');
        } else {
            echo json_encode(['success' => false, 'data' => 'Invalid email address']);
            exit;
        }
    }

    // ✅ RESOLVE DYNAMIC CONFIG FOR CURRENT UNIVERSITY
    $uni_slug = sanitize_title($_POST['uni_slug'] ?? sode_form_detect_uni_slug());
    $cfg = sode_get_university_form_config($uni_slug);

    // Dynamic configuration variables
    $configured_source       = ! empty($_POST['source']) ? sanitize_text_field($_POST['source']) : (!empty($cfg['source']) ? $cfg['source'] : 'MISC');
    $configured_crm_url      = ! empty($cfg['crm_api_url']) ? $cfg['crm_api_url'] : (getenv('CRM_API_URL') ?: 'https://api.crm.mysode.com/api/lead/apicreated');
    $configured_crm_key      = ! empty($cfg['crm_api_key']) ? $cfg['crm_api_key'] : (getenv('CRM_API_KEY') ?: '');
    $configured_crm_secret   = ! empty($cfg['crm_secret']) ? $cfg['crm_secret'] : (getenv('CRM_SECRET') ?: '');
    $configured_brevo_url    = ! empty($cfg['brevo_api_url']) ? $cfg['brevo_api_url'] : (getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/contacts');
    $configured_brevo_key    = ! empty($cfg['brevo_api_key']) ? $cfg['brevo_api_key'] : (getenv('BREVO_API_KEY') ?: '');
    $configured_brevo_list_id= ! empty($cfg['brevo_list_id']) ? (int)$cfg['brevo_list_id'] : 124;
    $configured_brevo_source = ! empty($cfg['brevo_source']) ? $cfg['brevo_source'] : 'MISC';
    $configured_gallabox_src = ! empty($cfg['gallabox_source']) ? $cfg['gallabox_source'] : 'MISC';
    $configured_gallabox_url = ! empty($cfg['gallabox_webhook_url']) ? $cfg['gallabox_webhook_url'] : (getenv('GALLABOX_WEBHOOK_URL') ?: '');

    // ✅ COMMON LEAD DATA
    $name = sanitize_text_field($_POST['name'] ?? '');
    $course = sanitize_text_field($_POST['course'] ?? '');
    $state = sanitize_text_field($_POST['state'] ?? '');
    $form_name = sanitize_text_field($_POST['form_name'] ?? 'DSU Lead Form');
    $utm_source = sanitize_text_field($_POST['utm_source'] ?? ($cfg['default_utm_source'] ?? 'Organic'));
    $utm_medium = sanitize_text_field($_POST['utm_medium'] ?? ($cfg['default_utm_medium'] ?? 'Direct'));
    $utm_campaign = sanitize_text_field($_POST['utm_campaign'] ?? ($cfg['default_utm_campaign'] ?? 'Universal'));
    $utm_term = sanitize_text_field($_POST['utm_term'] ?? '');
    $utm_content = sanitize_text_field($_POST['utm_content'] ?? '');
    $page_url = function_exists('esc_url_raw') ? esc_url_raw($_POST['page_url'] ?? '') : filter_var($_POST['page_url'] ?? '', FILTER_SANITIZE_URL);

    $ip_address = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
    if (str_contains($ip_address, ',')) {
        $ip_address = trim(explode(',', $ip_address)[0]);
    }

    // ============================================================
    // 🔵 1. MAIN CRM — mysode.com
    // ============================================================
    $crm_data = [
        "name" => $name,
        "email" => $email,
        "phone" => $phone,
        "course" => $course,
        "state" => $state,
        "form_name" => $form_name,
        "source" => $configured_source,
        "utm_source" => $utm_source,
        "utm_medium" => $utm_medium,
        "utm_campaign" => $utm_campaign,
        "utm_term" => $utm_term,
        "utm_content" => $utm_content,
        "page_url" => $page_url,
        "ip_address" => $ip_address,
    ];

    error_log('🔵 [CRM] Sending data for ' . $uni_slug . ' to ' . $configured_crm_url . ': ' . json_encode($crm_data));

    if ( function_exists('wp_remote_post') ) {
        $crm_response = wp_remote_post($configured_crm_url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $configured_crm_key,
                'secret' => $configured_crm_secret
            ],
            'body' => json_encode($crm_data),
        ]);

        if (is_wp_error($crm_response)) {
            error_log('🔵 [CRM] ❌ WP Error: ' . $crm_response->get_error_message());
        } else {
            $crm_code = wp_remote_retrieve_response_code($crm_response);
            $crm_body = wp_remote_retrieve_body($crm_response);
            error_log('🔵 [CRM] ✅ Status: ' . $crm_code . ' | Response: ' . $crm_body);
        }
    }

    // ============================================================
    // 🟢 2. BREVO — Contact Add
    // ============================================================
    $brevo_data = [
        "email" => $email,
        "listIds" => [$configured_brevo_list_id],
        "attributes" => [
            "FULLNAME" => $name,
            "MOBILE" => $phone,
            "COURSES" => $course,
            "STATES" => $state,
            "UTM_SOURCE" => $utm_source,
            "UTM_CAMPAIGN" => $utm_campaign,
            "UTM_MEDIUM" => $utm_medium,
            "UTM_TERM" => $utm_term,
            "SOURCE" => $configured_brevo_source
        ],
        "updateEnabled" => true
    ];

    error_log('🟢 [BREVO] Sending data for ' . $uni_slug . ' to ' . $configured_brevo_url . ': ' . json_encode($brevo_data));

    if ( function_exists('wp_remote_post') ) {
        $brevo_response = wp_remote_post($configured_brevo_url, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'api-key' => $configured_brevo_key,
            ],
            'body' => json_encode($brevo_data),
        ]);

        if (is_wp_error($brevo_response)) {
            error_log('🟢 [BREVO] ❌ WP Error: ' . $brevo_response->get_error_message());
        } else {
            $brevo_code = wp_remote_retrieve_response_code($brevo_response);
            $brevo_body = wp_remote_retrieve_body($brevo_response);
            error_log('🟢 [BREVO] ✅ Status: ' . $brevo_code . ' | Response: ' . $brevo_body);
        }
    }

    // ============================================================
    // 🟡 3. GALLABOX — Webhook
    // ============================================================
    if ( ! empty($configured_gallabox_url) ) {
        $gallabox_webhook_data = [
            "name" => $name,
            "phone" => (str_starts_with($phone, '+') ? $phone : '+' . $phone),
            "email" => $email,
            "course" => $course,
            "state" => $state,
            "source" => $configured_gallabox_src,
            "utm_source" => $utm_source,
            "utm_medium" => $utm_medium,
            "utm_campaign" => $utm_campaign,
            "utm_term" => $utm_term,
            "utm_content" => $utm_content,
        ];

        error_log('🟡 [GALLABOX WEBHOOK] Sending data for ' . $uni_slug . ': ' . json_encode($gallabox_webhook_data));

        if ( function_exists('wp_remote_post') ) {
            $gallabox_response = wp_remote_post($configured_gallabox_url, [
                'method' => 'POST',
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($gallabox_webhook_data),
            ]);

            if (is_wp_error($gallabox_response)) {
                error_log('🟡 [GALLABOX WEBHOOK] ❌ WP Error: ' . $gallabox_response->get_error_message());
            } else {
                $gallabox_code = wp_remote_retrieve_response_code($gallabox_response);
                $gallabox_body = wp_remote_retrieve_body($gallabox_response);
                error_log('🟡 [GALLABOX WEBHOOK] ✅ Status: ' . $gallabox_code . ' | Response: ' . $gallabox_body);
            }
        }
    }

    // ============================================================
    // ✅ DONE
    // ============================================================
    error_log('✅ [LEAD] All API calls completed for: ' . $phone . ' / ' . $email . ' (' . $uni_slug . ')');
    if ( function_exists('wp_send_json_success') ) {
        wp_send_json_success('Lead Sent Successfully');
    } else {
        echo json_encode(['success' => true, 'data' => 'Lead Sent Successfully']);
        exit;
    }
}


// ====================================================
// ✅ STEP 4 — SHORTCODE FORM [custom_lead_form]
// ====================================================
function custom_lead_form_shortcode($atts = [])
{
    $uni_slug = sode_form_detect_uni_slug($atts['university'] ?? '');
    $cfg = sode_get_university_form_config($uni_slug);

    $full_uni_name = ! empty($cfg['full_name']) ? $cfg['full_name'] : 'Dayananda Sagar University';
    $short_uni_name = ! empty($cfg['short_name']) ? $cfg['short_name'] : 'DSU';

    $atts = shortcode_atts([
        'heading'     => 'Book 100% Free Counseling',
        'sub-heading' => 'Get 1 to 1 Expert Guidance from SODE&trade;',
        'form_name'   => $short_uni_name . ' Lead Form',
        'button_text' => 'Submit',
        'university'  => $uni_slug
    ], $atts);

    // Dynamic key replacements in heading & form name
    $heading = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$full_uni_name, $short_uni_name, $short_uni_name], $atts['heading']);
    $subheading = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$full_uni_name, $short_uni_name, $short_uni_name], $atts['sub-heading']);
    $form_name = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$full_uni_name, $short_uni_name, $short_uni_name], $atts['form_name']);

    // Allowed courses list
    $courses_str = ! empty($cfg['allowed_courses_json']) ? $cfg['allowed_courses_json'] : 'MBA, MCA, MCOM, MA, MSC, MLIS, BBA, BCA, BCOM, BA, BSC, BLIS, Other';
    $courses_list = array_filter(array_map('trim', explode(',', $courses_str)));

    // Default UTM parameters from Admin
    $default_utm_source = ! empty($cfg['default_utm_source']) ? $cfg['default_utm_source'] : 'Organic';
    $default_utm_medium = ! empty($cfg['default_utm_medium']) ? $cfg['default_utm_medium'] : ($short_uni_name . '_Organic');
    $default_utm_campaign = ! empty($cfg['default_utm_campaign']) ? $cfg['default_utm_campaign'] : ($short_uni_name . '_Organic');
    $default_source = ! empty($cfg['source']) ? $cfg['source'] : 'MISC';

    ob_start();
    ?>

    <style>
        .phone-wrapper {
            display: grid;
            grid-template-columns: 35% 62%;
            gap: 10px;
        }

        .customLeadForm input[type="checkbox"] {
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            appearance: none !important;
            width: 18px !important;
            height: 18px !important;
            min-width: 18px !important;
            min-height: 18px !important;
            margin: 0px !important;
            padding: 0 !important;
            border: 2px solid #999 !important;
            border-radius: 3px !important;
            background: #fff !important;
            cursor: pointer;
            vertical-align: middle;
            flex-shrink: 0;
            position: relative;
            outline: none !important;
            box-shadow: none !important;
        }

        .customLeadForm input[type="checkbox"]:checked {
            background-color: #074a76 !important;
            border-color: #074a76 !important;
        }

        .customLeadForm input[type="checkbox"]:checked::after {
            content: '' !important;
            position: absolute;
            left: 4px;
            top: 1px;
            width: 6px;
            height: 10px;
            border: solid #fff;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .checkbox_content a {
            font-size: 10px !important;
        }

        .customLeadForm input,
        .customLeadForm select {
            margin-bottom: 10px;
        }

        .customLeadForm .submitBtn {
            width: 100%;
            background: #074a76 !important;
            color: #fff !important;
            border: none !important;
            padding: 10px 14px !important;
            border-radius: 5px !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            cursor: pointer !important;
            transition: background 0.2s !important;
        }

        .customLeadForm .submitBtn:hover {
            background: #053352 !important;
        }

        .customLeadForm h2 {
            font-size: 18px !important;
            font-weight: bold;
            text-align: center;
            color: #074a76 !important;
        }

        .customLeadForm p {
            font-size: 12px;
            text-align: center !important;
            font-weight: normal;
            color: #000 !important;
        }

        .customLeadForm input:not([type="checkbox"]),
        .customLeadForm select {
            border: 1px solid #ccc !important;
            color: #333 !important;
            border-radius: 5px !important;
            padding: 8px 12px !important;
            width: 100% !important;
            box-sizing: border-box !important;
            font-size: 13px !important;
        }

        .customLeadForm input:focus,
        .customLeadForm input:active,
        .customLeadForm input:hover,
        .customLeadForm select:focus,
        .customLeadForm select:active,
        .customLeadForm select:hover {
            border: 1px solid #4c5054ff !important;
            outline: none !important;
            box-shadow: 0 0 6px rgba(7, 74, 118, 0.25) !important;
        }

        .customLeadForm input::placeholder {
            color: #999 !important;
            opacity: 1 !important;
        }

        .customLeadForm select,
        .customLeadForm select option {
            color: #333 !important;
        }

        .customLeadForm input.field-error,
        .customLeadForm input.field-error:focus,
        .customLeadForm input.field-error:hover,
        .customLeadForm input.field-error:active,
        .customLeadForm select.field-error,
        .customLeadForm select.field-error:focus,
        .customLeadForm select.field-error:hover,
        .customLeadForm select.field-error:active {
            border: 1px solid red !important;
            box-shadow: 0 0 6px rgba(255, 0, 0, 0.3) !important;
        }
    </style>

    <form class="customLeadForm" translate="no" 
          data-form-name="<?php echo esc_attr($form_name); ?>"
          data-uni-slug="<?php echo esc_attr($uni_slug); ?>"
          data-default-source="<?php echo esc_attr($default_source); ?>"
          data-default-utm-source="<?php echo esc_attr($default_utm_source); ?>"
          data-default-utm-medium="<?php echo esc_attr($default_utm_medium); ?>"
          data-default-utm-campaign="<?php echo esc_attr($default_utm_campaign); ?>">

        <h2 style="margin-bottom:4px;"><?php echo esc_html($heading); ?></h2>
        <p style="margin-bottom:15px;"><?php echo esc_html($subheading); ?></p>

        <input type="text" name="name" placeholder="Enter Your Name" required>
        <input type="email" name="email" placeholder="Enter Your Email" required>

        <div class="phone-wrapper">
            <select name="country_code" class="country_code" required>
                <option value="91" selected>🇮🇳 +91 (India)</option>
                <option value="971">🇦🇪 +971 (UAE)</option>
                <option value="1">🇺🇸 +1 (USA)</option>
                <option value="44">🇬🇧 +44 (UK)</option>
                <option value="1">🇨🇦 +1 (Canada)</option>
                <option value="61">🇦🇺 +61 (Australia)</option>
                <option value="966">🇸🇦 +966 (Saudi Arabia)</option>
                <option value="974">🇶🇦 +974 (Qatar)</option>
                <option value="968">🇴🇲 +968 (Oman)</option>
                <option value="973">🇧🇭 +973 (Bahrain)</option>
                <option value="965">🇰🇼 +965 (Kuwait)</option>
                <option value="65">🇸🇬 +65 (Singapore)</option>
                <option value="60">🇲🇾 +60 (Malaysia)</option>
                <option value="880">🇧🇩 +880 (Bangladesh)</option>
                <option value="977">🇳🇵 +977 (Nepal)</option>
                <option value="94">🇱🇰 +94 (Sri Lanka)</option>
                <option value="49">🇩🇪 +49 (Germany)</option>
                <option value="33">🇫🇷 +33 (France)</option>
                <option value="39">🇮🇹 +39 (Italy)</option>
                <option value="34">🇪🇸 +34 (Spain)</option>
                <option value="31">🇳🇱 +31 (Netherlands)</option>
                <option value="41">🇨🇭 +41 (Switzerland)</option>
                <option value="46">🇸🇪 +46 (Sweden)</option>
                <option value="47">🇳🇴 +47 (Norway)</option>
                <option value="358">🇫🇮 +358 (Finland)</option>
                <option value="45">🇩🇰 +45 (Denmark)</option>
                <option value="353">🇮🇪 +353 (Ireland)</option>
                <option value="64">🇳🇿 +64 (New Zealand)</option>
                <option value="27">🇿🇦 +27 (South Africa)</option>
                <option value="234">🇳🇬 +234 (Nigeria)</option>
                <option value="254">🇰🇪 +254 (Kenya)</option>
                <option value="20">🇪🇬 +20 (Egypt)</option>
                <option value="81">🇯🇵 +81 (Japan)</option>
                <option value="82">🇰🇷 +82 (South Korea)</option>
                <option value="86">🇨🇳 +86 (China)</option>
                <option value="852">🇭🇰 +852 (Hong Kong)</option>
                <option value="886">🇹🇼 +886 (Taiwan)</option>
                <option value="66">🇹🇭 +66 (Thailand)</option>
                <option value="84">🇻🇳 +84 (Vietnam)</option>
                <option value="62">🇮🇩 +62 (Indonesia)</option>
                <option value="63">🇵🇭 +63 (Philippines)</option>
                <option value="92">🇵🇰 +92 (Pakistan)</option>
                <option value="90">🇹🇷 +90 (Turkey)</option>
                <option value="7">🇷🇺 +7 (Russia)</option>
                <option value="55">🇧🇷 +55 (Brazil)</option>
                <option value="54">🇦🇷 +54 (Argentina)</option>
                <option value="52">🇲🇽 +52 (Mexico)</option>
                <option value="57">🇨🇴 +57 (Colombia)</option>
            </select>
            <input type="tel" class="phone" name="phone" placeholder="Enter Your Number" required>
        </div>

        <!-- ✅ Honeypot -->
        <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">

        <select name="course" required>
            <option value="">Select Course</option>
            <?php foreach ($courses_list as $c_item): ?>
                <option value="<?php echo esc_attr($c_item); ?>"><?php echo esc_html($c_item); ?></option>
            <?php endforeach; ?>
        </select>

        <select name="state" required>
            <option value="">Select State</option>
            <option value="Andhra Pradesh">Andhra Pradesh</option>
            <option value="Arunachal Pradesh">Arunachal Pradesh</option>
            <option value="Assam">Assam</option>
            <option value="Bihar">Bihar</option>
            <option value="Chhattisgarh">Chhattisgarh</option>
            <option value="Delhi">Delhi</option>
            <option value="Goa">Goa</option>
            <option value="Gujarat">Gujarat</option>
            <option value="Haryana">Haryana</option>
            <option value="Himachal Pradesh">Himachal Pradesh</option>
            <option value="Jharkhand">Jharkhand</option>
            <option value="Karnataka">Karnataka</option>
            <option value="Kerala">Kerala</option>
            <option value="Madhya Pradesh">Madhya Pradesh</option>
            <option value="Maharashtra">Maharashtra</option>
            <option value="Manipur">Manipur</option>
            <option value="Meghalaya">Meghalaya</option>
            <option value="Mizoram">Mizoram</option>
            <option value="Nagaland">Nagaland</option>
            <option value="Odisha">Odisha</option>
            <option value="Punjab">Punjab</option>
            <option value="Rajasthan">Rajasthan</option>
            <option value="Sikkim">Sikkim</option>
            <option value="Tamil Nadu">Tamil Nadu</option>
            <option value="Telangana">Telangana</option>
            <option value="Tripura">Tripura</option>
            <option value="Uttar Pradesh">Uttar Pradesh</option>
            <option value="Uttarakhand">Uttarakhand</option>
            <option value="West Bengal">West Bengal</option>
            <option value="Jammu and Kashmir">Jammu and Kashmir</option>
            <option value="Puducherry">Puducherry</option>
            <option value="Lakshadweep">Lakshadweep</option>
            <option value="Ladakh">Ladakh</option>
            <option value="Chandigarh">Chandigarh</option>
            <option value="Andaman and Nicobar Islands">Andaman and Nicobar Islands</option>
            <option value="Dadra and Nagar Haveli and Daman and Diu">Dadra and Nagar Haveli and Daman and Diu</option>
        </select>

        <label style="display:flex; align-items:flex-start; gap:4px; margin:10px 0; font-size:10px; line-height:1.4; cursor:pointer;">
            <input type="checkbox" name="consent" required>
            <div class="checkbox_content">
                I consent to share my details with UGC-DEB approved universities and receive updates via email/mobile.
                <a class="disclaimer-main-popup" style="color:blue; cursor:pointer;"> Disclaimer</a>
            </div>
        </label>

        <button type="submit" class="submitBtn"><?php echo esc_html($atts['button_text']); ?></button>
    </form>

    <script>
        document.addEventListener("DOMContentLoaded", function () {

            if (window.sodeFormHandlerAttached) return;
            window.sodeFormHandlerAttached = true;

            // ================================================
            // ✅ TOKEN
            // ================================================
            let sessionToken = "";

            function loadToken() {
                return fetch("/wp-admin/admin-ajax.php?action=get_lead_token")
                    .then(res => res.json())
                    .then(res => {
                        if (res.success) sessionToken = res.data;
                    })
                    .catch(() => console.error("Token load failed"));
            }

            loadToken();

            // ================================================
            // ✅ UTM PARSER
            // ================================================
            function getParam(param) {
                const searchParams = new URLSearchParams(window.location.search);
                if (searchParams.has(param)) return searchParams.get(param);

                const hash = window.location.hash;
                if (hash.includes('?')) {
                    const hashQuery = hash.substring(hash.indexOf('?') + 1);
                    const hashParams = new URLSearchParams(hashQuery);
                    if (hashParams.has(param)) return hashParams.get(param);
                }

                return null;
            }

            function saveUTM() {
                ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content"].forEach(p => {
                    const val = getParam(p);
                    if (val) localStorage.setItem(p, val);
                });
            }

            function getUTM(param, def) {
                return getParam(param) || localStorage.getItem(param) || def || "";
            }

            saveUTM();

            // ================================================
            // ✅ RED BORDER ON INVALID FIELDS
            // ================================================
            document.addEventListener("click", function (e) {
                const btn = e.target.closest(".submitBtn");
                if (!btn) return;
                const form = btn.closest(".customLeadForm");
                if (!form) return;

                form.querySelectorAll("[required]").forEach(field => {
                    let isEmpty = false;
                    if (field.type === "checkbox") {
                        isEmpty = !field.checked;
                    } else {
                        isEmpty = !field.value || field.value.trim() === "";
                    }

                    if (isEmpty) {
                        field.classList.add("field-error");
                    } else {
                        field.classList.remove("field-error");
                    }

                    field.addEventListener("input", function () {
                        this.classList.remove("field-error");
                    }, { once: true });
                    field.addEventListener("change", function () {
                        this.classList.remove("field-error");
                    }, { once: true });
                });
            });

            // ================================================
            // ✅ FORM SUBMIT
            // ================================================
            document.addEventListener("submit", function (e) {

                if (!e.target.classList.contains("customLeadForm")) return;
                e.preventDefault();

                const form = e.target;
                const btn = form.querySelector(".submitBtn");

                if (btn.disabled) return;

                // Phone validation
                const countryCode = form.querySelector(".country_code").value;
                const phoneValue = form.querySelector(".phone").value.trim();

                if (!/^[6-9]\d{9}$/.test(phoneValue)) {
                    alert("Please enter a valid 10-digit mobile number");
                    return;
                }

                const fullPhone = countryCode + phoneValue;

                // UI lock
                btn.disabled = true;
                btn.innerText = "Submitting...";

                // Dynamic defaults from data attributes
                const uniSlug = form.dataset.uniSlug || "dsu";
                const formName = form.dataset.formName || "DSU Lead Form";
                const defSource = form.dataset.defaultSource || "MISC";
                const defUtmSource = form.dataset.defaultUtmSource || "Organic";
                const defUtmMedium = form.dataset.defaultUtmMedium || "DSU_Organic";
                const defUtmCampaign = form.dataset.defaultUtmCampaign || "DSU_Organic";

                // FormData
                const formData = new FormData(form);

                formData.set("phone", fullPhone);
                formData.append("action", "send_lead");
                formData.append("uni_slug", uniSlug);
                formData.append("form_name", formName);
                formData.append("source", defSource);
                formData.append("utm_source", getUTM("utm_source", defUtmSource));
                formData.append("utm_medium", getUTM("utm_medium", defUtmMedium));
                formData.append("utm_campaign", getUTM("utm_campaign", defUtmCampaign));
                formData.append("utm_term", getUTM("utm_term"));
                formData.append("utm_content", getUTM("utm_content"));
                formData.append("page_url", window.location.href);

                console.log("📋 [FORM] Submitting with dynamic config for:", uniSlug);
                for (let [key, value] of formData.entries()) {
                    console.log(`   ➡️ ${key}: ${value}`);
                }

                fetch("/wp-admin/admin-ajax.php", {
                    method: "POST",
                    headers: {
                        "X-Lead-Token": sessionToken
                    },
                    body: formData
                })
                    .then(res => {
                        if (res.status === 403) {
                            alert("Session expired. Please refresh the page and try again.");
                            resetBtn();
                            loadToken();
                            return null;
                        }
                        return res.json();
                    })
                    .then(res => {
                        if (!res) return;

                        if (res.success) {
                            console.log("✅ [SUCCESS] Form: " + formName);

                            // Compare form
                            if (formName.includes("Compare")) {
                                window.open('https://distanceeducationschool.com/compare-university/', '_blank');
                            }

                            // Brochure Download Form
                            if (formName.includes("Brochure")) {
                                window.location.href = "/thank-you?download_brochure=1";
                                return;
                            }

                            window.location.href = "/thank-you";

                        } else {
                            console.error("❌ [FAILED] Server response:", res.data);
                            alert(res.data || "Something went wrong. Please try again.");
                            resetBtn();
                            loadToken();
                        }
                    })
                    .catch(err => {
                        console.error("❌ [NETWORK ERROR]:", err);
                        alert("Submission failed. Please check your connection.");
                        resetBtn();
                    });

                function resetBtn() {
                    btn.disabled = false;
                    btn.innerText = "Submit";
                }
            });
        });
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('custom_lead_form', 'custom_lead_form_shortcode');


// ====================================================
// ✅ STEP 5 — COMPARE UNIVERSITIES SHORTCODE
// [compare_universities_form]
// ====================================================
function compare_universities_form_shortcode($atts = [])
{
    $uni_slug = sode_form_detect_uni_slug($atts['university'] ?? '');
    $cfg = sode_get_university_form_config($uni_slug);
    $short_name = ! empty($cfg['short_name']) ? $cfg['short_name'] : 'DSU';

    $atts = shortcode_atts([
        'heading'     => 'Compare Universities',
        'sub-heading' => 'Get expert help to compare universities',
        'form_name'   => 'Compare Universities Form',
        'university'  => $uni_slug
    ], $atts);
    return custom_lead_form_shortcode($atts);
}
add_shortcode('compare_universities_form', 'compare_universities_form_shortcode');


// ====================================================
// ✅ STEP 6 — POPUP MODAL SYSTEM (Compare)
// Class: .open-compare-form
// ====================================================
add_action('wp_footer', 'sode_compare_form_popup_modal');

function sode_compare_form_popup_modal()
{
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    ?>

    <style>
        #compareFormPopupOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 99999;
            justify-content: center;
            align-items: center;
        }
        #compareFormPopupOverlay.active {
            display: flex;
        }
        #compareFormPopupBox {
            background: #fff;
            border-radius: 10px;
            padding: 30px 25px 20px;
            width: 90%;
            max-width: 450px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: popupSlideIn 0.3s ease;
        }
        @keyframes popupSlideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to   { transform: translateY(0); opacity: 1; }
        }
        .compare-popup-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 26px;
            cursor: pointer;
            color: #333;
            background: none;
            border: none;
            line-height: 1;
            z-index: 1;
        }
        .compare-popup-close:hover { color: #e00; }
    </style>

    <div id="compareFormPopupOverlay">
        <div id="compareFormPopupBox">
            <button type="button" class="compare-popup-close" aria-label="Close">&times;</button>
            <?php echo function_exists('do_shortcode') ? do_shortcode('[compare_universities_form]') : ''; ?>
        </div>
    </div>

    <script>
        (function () {
            const overlay = document.getElementById('compareFormPopupOverlay');
            if (!overlay) return;

            document.addEventListener('click', function (e) {
                if (e.target.closest('.open-compare-form')) {
                    e.preventDefault();
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });

            overlay.querySelector('.compare-popup-close').addEventListener('click', function () {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.classList.contains('active')) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        })();
    </script>
    <?php
}


// ====================================================
// ✅ STEP 7 — AUTO OPEN BROCHURE ON THANK YOU PAGE
// Dynamically pulls brochure URL from Admin Panel
// ====================================================
add_action('wp_footer', 'sode_auto_open_brochure_on_thankyou');

function sode_auto_open_brochure_on_thankyou() {
    $uni_slug = sode_form_detect_uni_slug();
    $cfg = sode_get_university_form_config($uni_slug);
    $brochure_url = ! empty($cfg['brochure_pdf_url']) ? $cfg['brochure_pdf_url'] : 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/dsu_main_brochure.pdf';
    ?>
    <script>
    (function(){
        var params = new URLSearchParams(window.location.search);
        if (params.get('download_brochure') === '1') {
            setTimeout(function(){
                var a = document.createElement('a');
                a.href = '<?php echo esc_url($brochure_url); ?>';
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }, 1000);

            var cleanUrl = new URL(window.location);
            cleanUrl.searchParams.delete('download_brochure');
            window.history.replaceState({}, '', cleanUrl);
        }
    })();
    </script>
    <?php
}


// ====================================================
// ✅ STEP 8 — BROCHURE DOWNLOAD FORM SHORTCODE
// [brochure_download_form]
// ====================================================
function brochure_download_form_shortcode($atts = [])
{
    $uni_slug = sode_form_detect_uni_slug($atts['university'] ?? '');
    $cfg = sode_get_university_form_config($uni_slug);
    $short_name = ! empty($cfg['short_name']) ? $cfg['short_name'] : 'DSU';

    $atts = shortcode_atts([
        'heading'     => 'Download Brochure',
        'sub-heading' => 'Fill the form to get your free brochure',
        'form_name'   => $short_name . ' Brochure Download Form',
        'university'  => $uni_slug
    ], $atts);
    return custom_lead_form_shortcode($atts);
}
add_shortcode('brochure_download_form', 'brochure_download_form_shortcode');


// ====================================================
// ✅ SCHOLARSHIP COUPON FORM SHORTCODE
// [scholarship_coupon_form]
// ====================================================
function scholarship_coupon_form_shortcode($atts = [])
{
    $uni_slug = sode_form_detect_uni_slug($atts['university'] ?? '');
    $cfg = sode_get_university_form_config($uni_slug);
    $short_name = ! empty($cfg['short_name']) ? $cfg['short_name'] : 'DSU';

    $atts = shortcode_atts([
        'heading'     => 'Get Scholarship Coupon Code',
        'sub-heading' => 'Get expert academic guidance and claim your exclusive scholarship today!',
        'form_name'   => $short_name . ' Scholarship Coupon Form',
        'button_text' => 'Claim Scholarship Code',
        'university'  => $uni_slug
    ], $atts);

    return custom_lead_form_shortcode($atts);
}
add_shortcode('scholarship_coupon_form', 'scholarship_coupon_form_shortcode');


// ====================================================
// ✅ STEP 9 — POPUP MODAL SYSTEM (Brochure & Scholarship)
// Class: .open-brochure-form
// Class: .get-scholarship
// ====================================================
add_action('wp_footer', 'sode_brochure_form_popup_modal');

function sode_brochure_form_popup_modal()
{
    static $rendered = false;
    if ($rendered) return;
    $rendered = true;
    ?>

    <style>
        #brochureFormPopupOverlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 99999;
            justify-content: center;
            align-items: center;
        }

        #brochureFormPopupOverlay.active {
            display: flex;
        }

        #brochureFormPopupBox {
            background: #fff;
            border-radius: 10px;
            padding: 30px 25px 20px;
            width: 90%;
            max-width: 450px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: popupSlideIn 0.3s ease;
        }

        .brochure-popup-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 26px;
            cursor: pointer;
            color: #333;
            background: none;
            border: none;
            line-height: 1;
            z-index: 1;
        }

        .brochure-popup-close:hover {
            color: #e00;
        }
    </style>

    <div id="brochureFormPopupOverlay">
        <div id="brochureFormPopupBox">
            <button type="button" class="brochure-popup-close" aria-label="Close">&times;</button>
            <div class="popup-brochure-form" style="display:none;">
                <?php echo function_exists('do_shortcode') ? do_shortcode('[brochure_download_form]') : ''; ?>
            </div>
            <div class="popup-scholarship-form" style="display:none;">
                <?php echo function_exists('do_shortcode') ? do_shortcode('[scholarship_coupon_form]') : ''; ?>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const overlay = document.getElementById('brochureFormPopupOverlay');
            if (!overlay) return;

            document.addEventListener('click', function (e) {
                // BROCHURE
                const brochureButton = e.target.closest('.open-brochure-form');
                if (brochureButton) {
                    e.preventDefault();
                    const brochureForm = overlay.querySelector('.popup-brochure-form');
                    const scholarshipForm = overlay.querySelector('.popup-scholarship-form');
                    if (brochureForm) brochureForm.style.display = 'block';
                    if (scholarshipForm) scholarshipForm.style.display = 'none';
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                    return;
                }

                // SCHOLARSHIP
                const scholarshipButton = e.target.closest('.get-scholarship');
                if (scholarshipButton) {
                    e.preventDefault();
                    const brochureForm = overlay.querySelector('.popup-brochure-form');
                    const scholarshipForm = overlay.querySelector('.popup-scholarship-form');
                    if (brochureForm) brochureForm.style.display = 'none';
                    if (scholarshipForm) scholarshipForm.style.display = 'block';
                    overlay.classList.add('active');
                    document.body.style.overflow = 'hidden';
                    return;
                }
            });

            overlay.querySelector('.brochure-popup-close').addEventListener('click', function () {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            });

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.classList.contains('active')) {
                    overlay.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        })();
    </script>
    <?php
}
