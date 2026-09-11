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

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 1. Require Core Modular Components
$root_dir = dirname(__DIR__, 2);
require_once $root_dir . '/lead-form-universal.php';
require_once $root_dir . '/education-banner-universal.php';
require_once $root_dir . '/news-marquee-universal.php';

// 2. Resolve Component and Request Parameters
$component = trim($_GET['component'] ?? ($_POST['component'] ?? 'banner'));
$params = array_merge($_GET, $_POST);

if (!isset($params['university']) && isset($params['uni'])) {
    $params['university'] = $params['uni'];
}

// 3. Dispatch Component Render
header('Content-Type: text/html; charset=utf-8');

switch ($component) {
    case 'banner':
    case 'edu_banner':
        echo edu_banner_shortcode($params);
        break;

    case 'latest_news':
    case 'news':
    case 'news_marquee':
    case 'universal_news':
        echo sode_news_marquee_render($params);
        break;

    case 'lead_form':
    case 'custom_lead_form':
        echo custom_lead_form_shortcode($params);
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
        <div id="compareFormPopupOverlay" class="sode-modal-overlay">
            <div class="sode-modal-box">
                <button type="button" class="sode-modal-close compare-popup-close">&times;</button>
                <div id="sode-compare-modal-content"></div>
            </div>
        </div>
        <div id="brochureFormPopupOverlay" class="sode-modal-overlay">
            <div class="sode-modal-box">
                <button type="button" class="sode-modal-close brochure-popup-close">&times;</button>
                <div class="popup-brochure-form" style="display:none;" id="sode-brochure-modal-content"></div>
                <div class="popup-scholarship-form" style="display:none;" id="sode-scholarship-modal-content"></div>
            </div>
        </div>
        <?php
        break;

    default:
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'message' => 'SODE Universal Component SSR API Online',
            'available_components' => ['banner', 'lead_form', 'compare_form', 'brochure_form', 'scholarship_form', 'popup_modals']
        ]);
        break;
}
exit;
