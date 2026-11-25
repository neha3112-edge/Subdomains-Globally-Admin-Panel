<?php
/**
 * ====================================================================
 * SODE Central Universal Component SSR Controller
 * Endpoint: https://admin.distanceeducationschool.com/admin/api/render_component.php
 * 
 * Cleanly dispatches and server-side renders modular components 
 * (education-banner-universal.php, lead-form-universal.php, etc.)
 * without code duplication.
 * ====================================================================
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Lead-Token, X-Requested-With');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. Require Core Modular Components & DB Config
$admin_dir = dirname(__DIR__);
$root_dir = dirname($admin_dir);

if (file_exists($admin_dir . '/config/config.php')) {
    require_once $admin_dir . '/config/config.php';
}

require_once $root_dir . '/lead-form-universal.php';
require_once $root_dir . '/education-banner-universal.php';
require_once $root_dir . '/news-marquee-universal.php';
require_once $root_dir . '/announcements-list-universal.php';
require_once $root_dir . '/courses-universal.php';
require_once $root_dir . '/admission-process-universal.php';
if (file_exists($root_dir . '/legal-pages-universal.php')) {
    require_once $root_dir . '/legal-pages-universal.php';
}
if (file_exists($root_dir . '/footer-universal.php')) {
    require_once $root_dir . '/footer-universal.php';
}
if (file_exists($root_dir . '/course-syllabus-universal.php')) {
    require_once $root_dir . '/course-syllabus-universal.php';
}
if (file_exists($root_dir . '/course-fees-universal.php')) {
    require_once $root_dir . '/course-fees-universal.php';
}
if (file_exists($root_dir . '/course-specializations-universal.php')) {
    require_once $root_dir . '/course-specializations-universal.php';
}
if (file_exists($root_dir . '/job-roles-table-universal.php')) {
    require_once $root_dir . '/job-roles-table-universal.php';
}
if (file_exists($root_dir . '/course-fees-table-universal.php')) {
    require_once $root_dir . '/course-fees-table-universal.php';
}
if (file_exists($root_dir . '/gallabox-widget-universal.php')) {
    require_once $root_dir . '/gallabox-widget-universal.php';
}
if (file_exists($root_dir . '/university-dates-table-universal.php')) {
    require_once $root_dir . '/university-dates-table-universal.php';
}
if (file_exists($root_dir . '/alternate-universities-universal.php')) {
    require_once $root_dir . '/alternate-universities-universal.php';
}
if (file_exists($root_dir . '/university-fees-table-universal.php')) {
    require_once $root_dir . '/university-fees-table-universal.php';
}
if (file_exists($root_dir . '/university-programmes-table-universal.php')) {
    require_once $root_dir . '/university-programmes-table-universal.php';
}
if (file_exists($root_dir . '/sample-degree-universal.php')) {
    require_once $root_dir . '/sample-degree-universal.php';
}



// 2. Resolve Component and Request Parameters
$raw_input = file_get_contents('php://input');
$json_input = !empty($raw_input) ? json_decode($raw_input, true) : [];
if (!is_array($json_input)) {
    $json_input = [];
}

$component = trim($_GET['component'] ?? ($json_input['component'] ?? ($_POST['component'] ?? 'banner')));
$params = array_merge($_GET, $_POST, $json_input);

// Handle lead token generation for client-side JS engine
if ($component === 'get_lead_token' || (isset($_GET['action']) && $_GET['action'] === 'get_lead_token')) {
    header('Content-Type: application/json; charset=utf-8');
    $token = bin2hex(random_bytes(16));
    if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
        Sode_Redis::set('lead_token_' . $token, '1', 86400);
    }
    echo json_encode(['success' => true, 'data' => $token]);
    exit;
}

// Handle direct lead submission from client-side JS engine
if ($component === 'send_lead' || (isset($_POST['action']) && $_POST['action'] === 'send_lead') || (isset($params['action']) && $params['action'] === 'send_lead')) {
    header('Content-Type: application/json; charset=utf-8');
    require_once $root_dir . '/lead-form-universal.php';
    if (function_exists('sode_send_lead_to_crm')) {
        sode_send_lead_to_crm();
    } else {
        echo json_encode(['success' => true, 'data' => 'Lead submitted successfully']);
    }
    exit;
}

if (empty($params['uni']) && empty($params['university'])) {
    $ref = $_SERVER['HTTP_REFERER'] ?? ($_SERVER['HTTP_ORIGIN'] ?? '');
    if (!empty($ref)) {
        $host = parse_url($ref, PHP_URL_HOST);
        if ($host && strpos($host, 'distanceeducationschool.com') !== false) {
            $parts = explode('.', $host);
            if (count($parts) >= 3 && $parts[0] !== 'admin' && $parts[0] !== 'www') {
                $params['uni'] = $parts[0];
                $params['university'] = $parts[0];
            }
        }
    }
}

if (!empty($params['uni']) && empty($params['university'])) {
    $params['university'] = $params['uni'];
}
if (!empty($params['university']) && empty($params['uni'])) {
    $params['uni'] = $params['university'];
}

// Clean params for consistent caching (strip cache busters like _t=timestamp)
$cache_params = $params;
unset($cache_params['_t'], $cache_params['t'], $cache_params['_']);

// Cache all display components in Redis (Bumped to v9)
$is_cacheable = true;

$cache_key = 'ssr:component:v9:' . md5($component . '|' . json_encode($cache_params));

if ($is_cacheable && class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
    $cached_html = Sode_Redis::get($cache_key);
    if ($cached_html !== null) {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Cache: HIT (Redis-SSR)');
        echo $cached_html;
        exit;
    }
}

// 3. Component Rendering Engine
function sode_render_single_component_internal($component, $params = []) {
    ob_start();
    switch ($component) {
        case 'banner':
        case 'edu_banner':
            echo edu_banner_shortcode($params);
            break;

        case 'courses':
        case 'university_courses':
        case 'uni_courses':
        case 'university_programs':
            echo sode_courses_tabs_render($params);
            break;

        case 'courses_list':
        case 'university_courses_list':
        case 'uni_courses_list':
        case 'uni_courses_text':
            echo sode_courses_list_render($params);
            break;

        case 'eligibility_table':
        case 'university_eligibility_table':
        case 'uni_eligibility_table':
        case 'courses_eligibility_table':
        case 'university_eligibility':
        case 'uni_eligibility':
            echo sode_courses_eligibility_table_render($params);
            break;

        case 'university_eligibility_text':
        case 'uni_eligibility_text':
        case 'courses_eligibility_text':
        case 'eligibility_text':
        case 'course_eligibility':
        case 'course_eligibility_text':
            echo sode_courses_eligibility_text_render($params);
            break;

        case 'university_programs_table':
        case 'uni_programs_table':
        case 'programs_table':
        case 'university_fees_table':
        case 'uni_fees_table':
        case 'programs_fee_table':
        case 'university_courses_table':
        case 'uni_courses_table':
            echo sode_university_programs_table_render($params);
            break;

        case 'application_process':
        case 'university_application_process':
        case 'uni_application_process':
        case 'idol_application_process':
        case 'admission_process':
        case 'sode_application_process':
            echo sode_application_process_render($params);
            break;

        case 'footer':
        case 'universal_footer':
        case 'sode_footer':
        case 'footer_section':
        case 'footer_ai_tools':
        case 'footer_tools':
        case 'ai_tools_grid':
            echo sode_footer_render($params);
            break;

        case 'course_syllabus':
        case 'syllabus':
        case 'uni_syllabus':
        case 'university_course_syllabus':
        case 'uni_course_syllabus':
            echo sode_course_syllabus_render($params);
            break;

        case 'course_fees':
        case 'course_fee_table':
        case 'course_fee':
        case 'fee_table':
        case 'fee_structure':
        case 'university_course_fees':
        case 'uni_course_fees':
            echo sode_course_fees_render($params);
            break;

        case 'course_specializations':
        case 'course_specialization_table':
        case 'course_specialization':
        case 'specializations_table':
        case 'university_course_specializations':
        case 'uni_course_specializations':
            echo sode_course_specializations_render($params);
            break;

        case 'job_roles_table':
        case 'job_roles':
        case 'course_job_roles':
        case 'job_roles_salary':
            echo sode_job_roles_table_render($params);
            break;

        case 'course_table':
        case 'universities_table':
        case 'course_universities':
        case 'top_universities':
            echo sode_course_universities_table_render($params);
            break;

        case 'legal_popups':
        case 'disclaimer_popup':
        case 'privacy_popup':
        case 'terms_popup':
        case 'legal_pages':
            echo sode_legal_popups_render($params);
            break;

        case 'latest_news':
        case 'news':
        case 'news_marquee':
        case 'universal_news':
            echo sode_news_marquee_render($params);
            break;

        case 'recent_announcements':
        case 'announcements':
        case 'announcements_list':
        case 'university_announcements':
        case 'uni_announcements':
        case 'inner_page_news':
            echo sode_announcements_list_render($params);
            break;

        case 'university_dates':
        case 'important_dates':
        case 'uni_important_dates':
        case 'admission_dates':
        case 'dates_table':
        case 'university_dates_table':
            echo sode_university_dates_table_render($params);
            break;

        case 'alternate_universities':
        case 'alternative_universities':
        case 'alternatives_universities':
        case 'alternate_university_list':
        case 'alternative_university_list':
        case 'alternatives_list':
            echo function_exists('sode_alternate_universities_render') ? sode_alternate_universities_render($params) : '';
            break;

        case 'compare_universities_table':
        case 'universities_comparison_table':
        case 'compare_universities_fees':
        case 'universities_fee_comparison':
        case 'alternate_universities_fees':
        case 'alternate_universities_fees_table':
        case 'subdomain_fees_table':
        case 'subdomain_course_fees_table':
            echo function_exists('sode_render_university_fees_table') ? sode_render_university_fees_table($params) : '';
            break;

        case 'subdomain_programmes_table':
        case 'university_programmes_table':
        case 'uni_programmes_table':
        case 'ugc_deb_approved_courses_table':
        case 'university_ugc_courses_table':
            echo function_exists('sode_render_university_programmes_table') ? sode_render_university_programmes_table($params) : '';
            break;

        case 'sample_degree':
        case 'university_sample_degree':
        case 'uni_sample_degree':
        case 'sample_degree_card':
        case 'sample_degree_image':
        case 'sample_degree_button':
        case 'sample_degree_url':
            echo function_exists('sode_sample_degree_render') ? sode_sample_degree_render($params) : '';
            break;

        case 'lead_form':
        case 'custom_lead_form':
            echo custom_lead_form_shortcode($params);
            break;

        case 'counseling_lead_form':
        case 'counseling_form':
        case 'lead_form_box':
        case 'sode_lead_form':
            echo function_exists('sode_counseling_lead_form_box_shortcode') ? sode_counseling_lead_form_box_shortcode($params) : '';
            break;

        case 'counseling_button':
        case 'counseling_btn':
        case 'apply_now_button':
        case 'free_counseling_button':
        case 'applynow_button':
        case 'applynow_btn':
            echo function_exists('sode_counseling_button_shortcode') ? sode_counseling_button_shortcode($params) : '';
            break;

        case 'compare_form':
        case 'compare_universities_form':
            echo compare_universities_form_shortcode($params);
            break;

        case 'brochure_form':
        case 'brochure_download_form':
            echo brochure_download_form_shortcode($params);
            break;

        case 'scholarship_form':
        case 'scholarship_coupon_form':
            echo scholarship_coupon_form_shortcode($params);
            break;

        case 'popup_modals':
            ?>
            <style>
                .sode-modal-overlay {
                    display: none; position: fixed; inset: 0; background: rgba(0, 0, 0, 0.65);
                    z-index: 999999; justify-content: center; align-items: center; backdrop-filter: blur(3px);
                }
                .sode-modal-overlay.active { display: flex; }
                .sode-modal-box {
                    background: #fff; border-radius: 12px; padding: 30px 24px 20px; width: 92%; max-width: 440px;
                    max-height: 90vh; overflow-y: auto; position: relative; box-shadow: 0 15px 50px rgba(0,0,0,0.4);
                    animation: sodeModalIn 0.3s ease;
                }
                @keyframes sodeModalIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
                .sode-modal-close {
                    position: absolute; top: 12px; right: 16px; font-size: 26px; cursor: pointer; color: #444;
                    background: none; border: none; line-height: 1; z-index: 10;
                }
                .sode-modal-close:hover { color: #e11d48; }
            </style>
            <div id="counselingFormPopupOverlay" class="sode-modal-overlay">
                <div class="sode-modal-box">
                    <button type="button" class="sode-modal-close counseling-popup-close" aria-label="Close">&times;</button>
                    <div id="sode-counseling-modal-content">
                        <?php echo function_exists('custom_lead_form_shortcode') ? custom_lead_form_shortcode(['university' => $params['uni'] ?? '', 'form_name' => 'Counseling Popup Form']) : ''; ?>
                    </div>
                </div>
            </div>
            <div id="compareFormPopupOverlay" class="sode-modal-overlay">
                <div class="sode-modal-box">
                    <button type="button" class="sode-modal-close compare-popup-close">&times;</button>
                    <div id="sode-compare-modal-content">
                        <?php echo function_exists('compare_universities_form_shortcode') ? compare_universities_form_shortcode(['university' => $params['uni'] ?? '', 'form_name' => 'Compare University Form']) : ''; ?>
                    </div>
                </div>
            </div>
            <div id="brochureFormPopupOverlay" class="sode-modal-overlay">
                <div class="sode-modal-box">
                    <button type="button" class="sode-modal-close brochure-popup-close">&times;</button>
                    <div class="popup-brochure-form" id="sode-brochure-modal-content">
                        <?php echo function_exists('custom_lead_form_shortcode') ? custom_lead_form_shortcode(['university' => $params['uni'] ?? '', 'form_name' => 'Brochure Download Form', 'button_text' => 'Download Brochure Now', 'heading' => 'Download Free Brochure', 'subheading' => 'Get detailed syllabus, fees & admission guide']) : ''; ?>
                    </div>
                </div>
            </div>
            <?php
            // Include Legal Popups (Disclaimer, Privacy Policy, Terms & Conditions)
            if (function_exists('sode_legal_popups_render')) {
                echo sode_legal_popups_render($params);
            }
            break;

        case 'gallabox_widget':
        case 'whatsapp_widget':
        case 'gallabox':
            echo function_exists('sode_gallabox_widget_render') ? sode_gallabox_widget_render($params) : '';
            break;

        default:
            echo '';
            break;
    }
    return ob_get_clean();
}

// 4. Handle Batch Component Request
if ($component === 'batch') {
    header('Content-Type: application/json; charset=utf-8');
    $raw_input = file_get_contents('php://input');
    $json_input = json_decode($raw_input, true) ?: [];
    $items = $json_input['items'] ?? $_POST['items'] ?? [];
    if (is_string($items)) {
        $items = json_decode($items, true) ?: [];
    }

    $results = [];
    $use_redis = class_exists('Sode_Redis') && Sode_Redis::isAvailable();

    if (is_array($items)) {
        foreach ($items as $k => $item) {
            $c_name = is_string($item) ? $item : ($item['component'] ?? $item['tag'] ?? '');
            $c_name = strtolower(trim((string)$c_name));
            $c_args = is_array($item) ? ($item['args'] ?? ($item['params'] ?? $item)) : [];
            if (!empty($params['uni']) && empty($c_args['uni'])) {
                $c_args['uni'] = $params['uni'];
            }
            if (!empty($params['uni']) && empty($c_args['university'])) {
                $c_args['university'] = $params['uni'];
            }

            // Per-component Redis caching (24 hours) - Bumped to v9
            $item_cache_key = 'ssr:comp:v9:' . md5($c_name . '|' . json_encode($c_args));
            $item_html = null;
            if ($use_redis) {
                $item_html = Sode_Redis::get($item_cache_key);
            }

            if ($item_html === null) {
                $item_html = sode_render_single_component_internal($c_name, $c_args);
                if ($use_redis && !empty($item_html)) {
                    Sode_Redis::set($item_cache_key, $item_html, 86400);
                }
            }

            $results[$k] = $item_html;
        }
    }

    // Resolve global keys in the same batch response
    $keys_map = [];
    $uni_slug = $params['uni'] ?? '';
    $keys_cache_key = "api:keys:" . ($uni_slug ?: 'global');
    if ($use_redis) {
        $cached_k = Sode_Redis::get($keys_cache_key);
        if ($cached_k && is_array($cached_k) && !empty($cached_k['keys'])) {
            $keys_map = $cached_k['keys'];
        }
    }
    if (empty($keys_map) && function_exists('get_db_connection')) {
        $db_conn = get_db_connection();
        if ($db_conn) {
            try {
                // 1. Fetch base global keys table
                $stmt = $db_conn->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1");
                while ($row = $stmt->fetch()) {
                    $keys_map[$row['key_code']] = $row['key_value'];
                }

                // 2. Fetch university details for {UNIVERSITY_NAME}, {MODE}, etc.
                if (!empty($uni_slug)) {
                    $u_stmt = $db_conn->prepare("SELECT * FROM universities WHERE (slug = ? OR LOWER(short_name) = ?) AND is_active = 1 LIMIT 1");
                    $u_stmt->execute([$uni_slug, strtolower($uni_slug)]);
                    $u_row = $u_stmt->fetch();
                    $u_full = $u_row['full_name'] ?? ($uni_slug === 'dsu' ? 'Dayananda Sagar University' : strtoupper($uni_slug) . ' University');
                    $u_short = strtoupper($u_row['short_name'] ?? $uni_slug);
                    $u_mode = $u_row['mode'] ?? 'Online';

                    $keys_map['{UNIVERSITY_NAME}'] = $u_full;
                    $keys_map['[UNIVERSITY_NAME]'] = $u_full;
                    $keys_map['$UNIVERSITY_NAME$'] = $u_full;

                    $keys_map['{MODE}']            = $u_mode;
                    $keys_map['[MODE]']            = $u_mode;
                    $keys_map['$MODE$']            = $u_mode;

                    $keys_map['{UNIVERSITY_SHORT_NAME}'] = $u_short;
                    $keys_map['[UNIVERSITY_SHORT_NAME]'] = $u_short;
                    $keys_map['$UNIVERSITY_SHORT_NAME$'] = $u_short;
                    $keys_map['{UNI_SHORT_NAME}']        = $u_short;
                    $keys_map['[UNI_SHORT_NAME]']        = $u_short;
                    $keys_map['{UNI}']                   = $u_short;
                    $keys_map['[UNI]']                   = $u_short;
                }
            } catch (Exception $e) {}
        }
    }

    echo json_encode([
        'success' => true,
        'uni' => $params['uni'] ?? '',
        'results' => $results,
        'keys' => $keys_map
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 5. Single Component Output
header('Content-Type: text/html; charset=utf-8');
$rendered_output = sode_render_single_component_internal($component, $params);

if (empty($rendered_output) && empty($component)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'SODE Universal Component SSR API Online',
        'available_components' => ['banner', 'lead_form', 'compare_form', 'brochure_form', 'scholarship_form', 'popup_modals', 'batch']
    ]);
    exit;
}

// Cache HTML output in Redis for both GET and POST requests (24 hours)
if ($is_cacheable && !empty($rendered_output) && class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
    Sode_Redis::set($cache_key, $rendered_output, 86400);
    header('X-Cache: MISS (Cached)');
}

echo $rendered_output;
exit;
