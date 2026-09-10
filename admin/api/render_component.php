<?php
/**
 * ====================================================================
 * SODE Central Universal Component Rendering Engine (SSR API)
 * Endpoint: https://admin.distanceeducationschool.com/api/render_component.php
 * 
 * Provides centralized Server-Side Rendering (HTML + CSS + JS)
 * for all university subdomains. Any UI/CSS/JS change made on Admin Panel
 * updates all 50+ subdomains instantly without editing subdomain files.
 * ====================================================================
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Lead-Token, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$component = trim($_GET['component'] ?? ($_POST['component'] ?? ''));
$uni_slug  = trim($_GET['uni'] ?? ($_POST['uni'] ?? ''));

// Subdomain auto-detection if uni_slug is empty
if (empty($uni_slug) && !empty($_SERVER['HTTP_ORIGIN'])) {
    $origin_host = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    if ($origin_host) {
        $parts = explode('.', $origin_host);
        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'admin', 'mail'])) {
            $uni_slug = strtolower($parts[0]);
        }
    }
}
if (empty($uni_slug)) {
    $uni_slug = 'dsu';
}

// -------------------------------------------------------------
// HELPER: Fetch University Record + Accreditations + Global Keys
// -------------------------------------------------------------
function sode_get_uni_full_data($db, $slug) {
    $stmt = $db->prepare("
        SELECT u.*, ufc.source, ufc.default_utm_source, ufc.default_utm_medium, 
               ufc.default_utm_campaign, ufc.gallabox_source, ufc.brevo_source, 
               ufc.brevo_list_id, ufc.allowed_courses_json
        FROM universities u
        LEFT JOIN university_form_configs ufc ON ufc.university_id = u.id
        WHERE (u.slug = ? OR LOWER(u.short_name) = ?) AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$slug, strtolower($slug)]);
    $uni = $stmt->fetch();

    if (!$uni) {
        // Fallback default
        $uni = [
            'id' => 0,
            'full_name' => 'Dayananda Sagar University',
            'short_name' => 'DSU',
            'slug' => 'dsu',
            'mode' => 'Online & Distance',
            'rating' => '4.2',
            'desktop_banner_bg' => 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/dsu_desktop_banner.png',
            'mobile_banner_bg' => 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/dsu_mobile_banner.png',
            'campus_mobile_img' => 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/dsu_campus.png',
            'logo_url' => 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/dsu_logo.png',
            'brochure_pdf_url' => 'https://dsu.distanceeducationschool.com/wp-content/uploads/2026/08/dsu_main_brochure.pdf',
            'podcast_audio_url' => '',
            'youtube_video_url' => '',
            'admission_last_date' => '30th Sept 2026',
            'source' => 'MISC',
            'default_utm_source' => 'Organic',
            'default_utm_medium' => 'DSU_Organic',
            'default_utm_campaign' => 'DSU_Organic',
            'gallabox_source' => 'DSU',
            'brevo_source' => 'DSU',
            'brevo_list_id' => 124,
            'allowed_courses_json' => 'BA, BBA, BCA, BLIS, MA, MBA, MCA, MLIS, Other'
        ];
    }

    // Accreditations
    $acc_stmt = $db->prepare("
        SELECT a.* FROM accreditations a
        INNER JOIN university_accreditations ua ON ua.accreditation_id = a.id
        WHERE ua.university_id = ?
    ");
    $acc_stmt->execute([(int)($uni['id'] ?? 0)]);
    $accreditations = $acc_stmt->fetchAll();

    // Global text keys
    $keys_stmt = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1");
    $global_keys = $keys_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Global settings
    $settings_stmt = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'integrations'");
    $global_settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'uni' => $uni,
        'accreditations' => $accreditations,
        'global_keys' => $global_keys,
        'global_settings' => $global_settings
    ];
}

$data = sode_get_uni_full_data($db, $uni_slug);
$uni = $data['uni'];
$accreditations = $data['accreditations'];
$global_keys = $data['global_keys'];
$global_settings = $data['global_settings'];

// -------------------------------------------------------------
// COMPONENT 1: HERO BANNER SECTION (Render Server-Side HTML)
// -------------------------------------------------------------
if ($component === 'banner') {
    $heading     = trim($_GET['heading'] ?? ($_POST['heading'] ?? ($uni['short_name'] . ' Online & Distance Education')));
    $subheading  = trim($_GET['subheading'] ?? ($_POST['subheading'] ?? ''));
    $description = trim($_GET['description'] ?? ($_POST['description'] ?? ''));
    $audio_url   = trim($_GET['audio_url'] ?? ($_POST['audio_url'] ?? ($uni['podcast_audio_url'] ?? '')));
    $video_url   = trim($_GET['video_url'] ?? ($_POST['video_url'] ?? ($uni['youtube_video_url'] ?? '')));
    $top_heading = trim($_GET['top_heading'] ?? ($_POST['top_heading'] ?? ''));

    // Global key replacements
    foreach ($global_keys as $k => $v) {
        $heading = str_replace($k, $v, $heading);
        $subheading = str_replace($k, $v, $subheading);
        $description = str_replace($k, $v, $description);
    }
    $heading = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$uni['full_name'], $uni['short_name'], $uni['short_name']], $heading);

    $banner_desktop = !empty($uni['desktop_banner_bg']) ? $uni['desktop_banner_bg'] : '';
    $banner_mobile  = !empty($uni['mobile_banner_bg']) ? $uni['mobile_banner_bg'] : $banner_desktop;
    $campus_img     = !empty($uni['campus_mobile_img']) ? $uni['campus_mobile_img'] : '';
    $last_date      = !empty($uni['admission_last_date']) ? $uni['admission_last_date'] : '30th Sept 2026';
    $year           = $global_keys['$YEAR$'] ?? '2026';
    $top_bar_text   = !empty($top_heading) ? $top_heading : ($global_keys['$BANNER_TOP_TEXT$'] ?? ('Welcome to SODE™ (School of Online and Distance Education) - ' . $year));

    // Render HTML Output
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!-- ===== SODE UNIVERSAL CENTRAL HERO BANNER (v3.0) ===== -->
    <style>
        .edu-hero-banner-wrap { width: 100%; position: relative; font-family: 'Plus Jakarta Sans', -apple-system, sans-serif; background-color: #0b1528; }
        .edu-banner-top-bar { background: #074a76; color: #fff; text-align: center; padding: 6px 15px; font-size: 13px; font-weight: 600; letter-spacing: 0.3px; }
        .edu-hero-main {
            position: relative;
            min-height: 480px;
            background-size: cover;
            background-position: center right;
            background-repeat: no-repeat;
            background-image: url('<?php echo htmlspecialchars($banner_desktop); ?>');
            display: flex;
            align-items: center;
        }
        .edu-hero-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(7, 24, 48, 0.95) 0%, rgba(7, 24, 48, 0.85) 45%, rgba(7, 24, 48, 0.2) 100%);
            z-index: 1;
        }
        .edu-hero-container {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 1240px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .edu-hero-content { max-width: 650px; color: #fff; }
        .edu-hero-badge-row { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .edu-mode-badge {
            background: #f59e0b;
            color: #000;
            font-weight: 700;
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .edu-rating-badge {
            display: flex;
            align-items: center;
            gap: 4px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(4px);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .edu-hero-title {
            font-size: 34px;
            font-weight: 800;
            line-height: 1.25;
            color: #ffffff;
            margin: 0 0 12px 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .edu-hero-subheading { font-size: 16px; font-weight: 600; color: #93c5fd; margin: 0 0 12px 0; }
        .edu-hero-desc { font-size: 14px; line-height: 1.6; color: #e2e8f0; margin-bottom: 22px; }
        
        .edu-hero-cta-group { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 24px; }
        .edu-btn-whatsapp-brochure {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #25D366;
            color: #fff !important;
            font-size: 14px;
            font-weight: 700;
            padding: 12px 22px;
            border-radius: 6px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.35);
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
        }
        .edu-btn-whatsapp-brochure:hover { background: #1ebc57; transform: translateY(-2px); color:#fff !important; }
        
        .edu-btn-audio-listen, .edu-btn-video-watch {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.12);
            color: #fff !important;
            border: 1px solid rgba(255, 255, 255, 0.3);
            font-size: 13px;
            font-weight: 600;
            padding: 11px 18px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .edu-btn-audio-listen:hover, .edu-btn-video-watch:hover { background: rgba(255, 255, 255, 0.25); }

        .edu-admission-deadline-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #fca5a5;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 600;
        }

        /* Golden Accreditations Bar */
        .edu-accreditations-gold-bar {
            background: #0f1c30;
            border-top: 2px solid #b48529;
            border-bottom: 2px solid #b48529;
            padding: 14px 20px;
        }
        .edu-accred-container {
            max-width: 1240px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .edu-accred-title { font-size: 13px; font-weight: 700; color: #f3ba4b; text-transform: uppercase; letter-spacing: 0.8px; }
        .edu-accred-items-wrap { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
        .edu-accred-single-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.04);
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid rgba(243, 186, 75, 0.25);
        }
        .edu-accred-single-item img { height: 26px; width: auto; object-fit: contain; }
        .edu-accred-single-item span { font-size: 12px; font-weight: 600; color: #fff; }

        @media (max-width: 768px) {
            .edu-hero-main {
                background-image: url('<?php echo htmlspecialchars($banner_mobile); ?>');
                min-height: 400px;
                background-position: center top;
            }
            .edu-hero-title { font-size: 24px; }
            .edu-accred-container { justify-content: center; text-align: center; }
        }
    </style>

    <div class="edu-hero-banner-wrap" translate="no">
        <div class="edu-banner-top-bar">
            <?php echo htmlspecialchars($top_bar_text); ?>
        </div>

        <div class="edu-hero-main">
            <div class="edu-hero-overlay"></div>
            <div class="edu-hero-container">
                <div class="edu-hero-content">
                    <div class="edu-hero-badge-row">
                        <span class="edu-mode-badge"><?php echo htmlspecialchars($uni['mode'] ?? 'Online & Distance'); ?></span>
                        <div class="edu-rating-badge">
                            <span style="color:#f59e0b;">★</span>
                            <span><?php echo htmlspecialchars($uni['rating'] ?? '4.2'); ?> / 5.0 Rating</span>
                        </div>
                    </div>

                    <h1 class="edu-hero-title"><?php echo htmlspecialchars($heading); ?></h1>
                    
                    <?php if (!empty($subheading)): ?>
                        <div class="edu-hero-subheading"><?php echo htmlspecialchars($subheading); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($description)): ?>
                        <div class="edu-hero-desc"><?php echo htmlspecialchars($description); ?></div>
                    <?php endif; ?>

                    <div class="edu-hero-cta-group">
                        <button type="button" class="edu-btn-whatsapp-brochure open-brochure-form">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.699c.971.53 1.771.814 2.796.814 3.18 0 5.766-2.586 5.767-5.766.001-3.18-2.585-5.766-5.767-5.766zm0 10.375c-.878 0-1.611-.252-2.317-.671l-.166-.099-1.58.415.422-1.54-.108-.172c-.463-.736-.708-1.503-.707-2.542.001-2.539 2.066-4.605 4.607-4.605 2.54 0 4.605 2.066 4.606 4.605-.001 2.54-2.067 4.609-4.756 4.609z"/></svg>
                            Download Brochure on WhatsApp
                        </button>

                        <?php if (!empty($audio_url)): ?>
                            <a href="<?php echo htmlspecialchars($audio_url); ?>" target="_blank" class="edu-btn-audio-listen">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>
                                Listen Podcast
                            </a>
                        <?php endif; ?>

                        <?php if (!empty($video_url)): ?>
                            <a href="<?php echo htmlspecialchars($video_url); ?>" target="_blank" class="edu-btn-video-watch">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                Watch Video
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="edu-admission-deadline-badge">
                        <span>⏳ Admission Last Date:</span>
                        <strong style="color:#fff;"><?php echo htmlspecialchars($last_date); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Golden Accreditations Bar -->
        <?php if (!empty($accreditations)): ?>
            <div class="edu-accreditations-gold-bar">
                <div class="edu-accred-container">
                    <span class="edu-accred-title">Recognitions & Approvals</span>
                    <div class="edu-accred-items-wrap">
                        <?php foreach ($accreditations as $acc): ?>
                            <div class="edu-accred-single-item">
                                <?php if (!empty($acc['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($acc['image_url']); ?>" alt="<?php echo htmlspecialchars($acc['title']); ?>">
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($acc['title']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

// -------------------------------------------------------------
// COMPONENT 2: LEAD CAPTURE FORM / SHORTCODE SSR
// -------------------------------------------------------------
if (in_array($component, ['lead_form', 'compare_form', 'brochure_form', 'scholarship_form', 'custom_lead_form'])) {
    $heading     = trim($_GET['heading'] ?? ($_POST['heading'] ?? 'Book 100% Free Counseling'));
    $subheading  = trim($_GET['subheading'] ?? ($_POST['subheading'] ?? 'Get 1 to 1 Expert Guidance from SODE&trade;'));
    $button_text = trim($_GET['button_text'] ?? ($_POST['button_text'] ?? 'Submit'));
    $form_name   = trim($_GET['form_name'] ?? ($_POST['form_name'] ?? ($uni['short_name'] . ' Lead Form')));

    if ($component === 'compare_form') {
        $heading = !empty($_GET['heading']) ? $_GET['heading'] : 'Compare Universities';
        $subheading = !empty($_GET['subheading']) ? $_GET['subheading'] : 'Get expert help to compare universities';
        $form_name = 'Compare Universities Form';
    } elseif ($component === 'brochure_form') {
        $heading = !empty($_GET['heading']) ? $_GET['heading'] : 'Download Brochure';
        $subheading = !empty($_GET['subheading']) ? $_GET['subheading'] : 'Fill the form to get your free brochure';
        $form_name = $uni['short_name'] . ' Brochure Download Form';
    } elseif ($component === 'scholarship_form') {
        $heading = !empty($_GET['heading']) ? $_GET['heading'] : 'Get Scholarship Coupon Code';
        $subheading = !empty($_GET['subheading']) ? $_GET['subheading'] : 'Claim your exclusive academic scholarship today!';
        $form_name = $uni['short_name'] . ' Scholarship Coupon Form';
        $button_text = 'Claim Scholarship Code';
    }

    // Dynamic key replacements
    $heading = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$uni['full_name'], $uni['short_name'], $uni['short_name']], $heading);
    $subheading = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$uni['full_name'], $uni['short_name'], $uni['short_name']], $subheading);
    $form_name = str_replace(['{UNIVERSITY_NAME}', '{UNI}', '{SHORT_NAME}'], [$uni['full_name'], $uni['short_name'], $uni['short_name']], $form_name);

    $courses_str = !empty($uni['allowed_courses_json']) ? $uni['allowed_courses_json'] : 'MBA, MCA, MCOM, MA, MSC, MLIS, BBA, BCA, BCOM, BA, BSC, BLIS, Other';
    $courses_list = array_filter(array_map('trim', explode(',', $courses_str)));

    $default_utm_source = !empty($uni['default_utm_source']) ? $uni['default_utm_source'] : 'Organic';
    $default_utm_medium = !empty($uni['default_utm_medium']) ? $uni['default_utm_medium'] : ($uni['short_name'] . '_Organic');
    $default_utm_campaign = !empty($uni['default_utm_campaign']) ? $uni['default_utm_campaign'] : ($uni['short_name'] . '_Organic');
    $default_source = !empty($uni['source']) ? $uni['source'] : 'MISC';

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <style>
        .customLeadForm { font-family: 'Plus Jakarta Sans', sans-serif; }
        .customLeadForm .phone-wrapper { display: grid; grid-template-columns: 35% 62%; gap: 10px; }
        .customLeadForm input[type="checkbox"] {
            -webkit-appearance: none !important; appearance: none !important;
            width: 18px !important; height: 18px !important; min-width: 18px !important; min-height: 18px !important;
            margin: 0px !important; padding: 0 !important; border: 2px solid #999 !important; border-radius: 3px !important;
            background: #fff !important; cursor: pointer; vertical-align: middle; flex-shrink: 0; position: relative;
        }
        .customLeadForm input[type="checkbox"]:checked { background-color: #074a76 !important; border-color: #074a76 !important; }
        .customLeadForm input[type="checkbox"]:checked::after {
            content: '' !important; position: absolute; left: 4px; top: 1px; width: 6px; height: 10px;
            border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
        }
        .customLeadForm input, .customLeadForm select { margin-bottom: 10px; }
        .customLeadForm .submitBtn {
            width: 100%; background: #074a76 !important; color: #fff !important; border: none !important;
            padding: 11px 16px !important; border-radius: 6px !important; font-weight: 700 !important; font-size: 14px !important;
            cursor: pointer !important; transition: background 0.2s !important;
        }
        .customLeadForm .submitBtn:hover { background: #053352 !important; }
        .customLeadForm h2 { font-size: 18px !important; font-weight: bold; text-align: center; color: #074a76 !important; margin: 0 0 4px 0; }
        .customLeadForm p { font-size: 12px; text-align: center !important; color: #444 !important; margin: 0 0 15px 0; }
        .customLeadForm input:not([type="checkbox"]), .customLeadForm select {
            border: 1px solid #ccc !important; color: #333 !important; border-radius: 5px !important;
            padding: 8px 12px !important; width: 100% !important; box-sizing: border-box !important; font-size: 13px !important;
        }
        .customLeadForm input.field-error, .customLeadForm select.field-error {
            border: 1px solid red !important; box-shadow: 0 0 6px rgba(255, 0, 0, 0.3) !important;
        }
    </style>

    <form class="customLeadForm" translate="no" 
          data-form-name="<?php echo htmlspecialchars($form_name); ?>"
          data-uni-slug="<?php echo htmlspecialchars($uni_slug); ?>"
          data-default-source="<?php echo htmlspecialchars($default_source); ?>"
          data-default-utm-source="<?php echo htmlspecialchars($default_utm_source); ?>"
          data-default-utm-medium="<?php echo htmlspecialchars($default_utm_medium); ?>"
          data-default-utm-campaign="<?php echo htmlspecialchars($default_utm_campaign); ?>">

        <h2><?php echo htmlspecialchars($heading); ?></h2>
        <p><?php echo htmlspecialchars($subheading); ?></p>

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
            </select>
            <input type="tel" class="phone" name="phone" placeholder="Enter Your Number" required>
        </div>

        <input type="text" name="website" style="display:none;" tabindex="-1" autocomplete="off">

        <select name="course" required>
            <option value="">Select Course</option>
            <?php foreach ($courses_list as $c_item): ?>
                <option value="<?php echo htmlspecialchars($c_item); ?>"><?php echo htmlspecialchars($c_item); ?></option>
            <?php endforeach; ?>
        </select>

        <select name="state" required>
            <option value="">Select State</option>
            <option value="Delhi">Delhi</option>
            <option value="Maharashtra">Maharashtra</option>
            <option value="Karnataka">Karnataka</option>
            <option value="Uttar Pradesh">Uttar Pradesh</option>
            <option value="Bihar">Bihar</option>
            <option value="West Bengal">West Bengal</option>
            <option value="Haryana">Haryana</option>
            <option value="Punjab">Punjab</option>
            <option value="Rajasthan">Rajasthan</option>
            <option value="Gujarat">Gujarat</option>
            <option value="Madhya Pradesh">Madhya Pradesh</option>
            <option value="Tamil Nadu">Tamil Nadu</option>
            <option value="Telangana">Telangana</option>
            <option value="Andhra Pradesh">Andhra Pradesh</option>
            <option value="Kerala">Kerala</option>
            <option value="Odisha">Odisha</option>
            <option value="Assam">Assam</option>
            <option value="Jharkhand">Jharkhand</option>
            <option value="Chhattisgarh">Chhattisgarh</option>
            <option value="Uttarakhand">Uttarakhand</option>
            <option value="Himachal Pradesh">Himachal Pradesh</option>
            <option value="Goa">Goa</option>
            <option value="Jammu and Kashmir">Jammu and Kashmir</option>
            <option value="Chandigarh">Chandigarh</option>
        </select>

        <label style="display:flex; align-items:flex-start; gap:6px; margin:10px 0; font-size:10px; line-height:1.4; cursor:pointer;">
            <input type="checkbox" name="consent" required>
            <div>
                I consent to share my details with UGC-DEB approved universities and receive updates via email/mobile.
                <a class="disclaimer-main-popup" style="color:#074a76; text-decoration:underline; cursor:pointer;">Disclaimer</a>
            </div>
        </label>

        <button type="submit" class="submitBtn"><?php echo htmlspecialchars($button_text); ?></button>
    </form>
    <?php
    exit;
}

// -------------------------------------------------------------
// COMPONENT 3: CENTRAL POPUP MODALS (Compare, Brochure, Scholarship)
// -------------------------------------------------------------
if ($component === 'popup_modals') {
    header('Content-Type: text/html; charset=utf-8');
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

    <!-- Compare Modal -->
    <div id="compareFormPopupOverlay" class="sode-modal-overlay">
        <div class="sode-modal-box">
            <button type="button" class="sode-modal-close compare-popup-close">&times;</button>
            <div id="sode-compare-modal-content"></div>
        </div>
    </div>

    <!-- Brochure & Scholarship Modal -->
    <div id="brochureFormPopupOverlay" class="sode-modal-overlay">
        <div class="sode-modal-box">
            <button type="button" class="sode-modal-close brochure-popup-close">&times;</button>
            <div class="popup-brochure-form" style="display:none;" id="sode-brochure-modal-content"></div>
            <div class="popup-scholarship-form" style="display:none;" id="sode-scholarship-modal-content"></div>
        </div>
    </div>
    <?php
    exit;
}

// Fallback JSON if component not specified
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => 'SODE Universal Component SSR API Online',
    'available_components' => ['banner', 'lead_form', 'compare_form', 'brochure_form', 'scholarship_form', 'popup_modals']
]);
