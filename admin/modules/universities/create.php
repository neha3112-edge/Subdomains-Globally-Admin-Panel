<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');
require_action_permission('create', 'universities');


$page_title = 'Add University';
$page_subtitle = 'Create a new university master record';
$active_page_key = 'universities';

$db = get_db_connection();

// Auto-check and add Alternate Universities columns if not yet present
try {
    $alt_col_chk = $db->query("SHOW COLUMNS FROM universities LIKE 'show_in_alternate'")->fetch();
    if (!$alt_col_chk) {
        $db->exec("ALTER TABLE universities 
            ADD COLUMN alt_desktop_img TEXT NULL AFTER campus_mobile_img,
            ADD COLUMN alt_mobile_img TEXT NULL AFTER alt_desktop_img,
            ADD COLUMN sample_degree_img TEXT NULL AFTER alt_mobile_img,
            ADD COLUMN alt_description TEXT NULL AFTER sample_degree_img,
            ADD COLUMN show_in_alternate TINYINT(1) DEFAULT 0 AFTER alt_description");
    }
} catch (Exception $e) {}

// Fetch available accreditations
$all_accreditations = $db->query("SELECT * FROM accreditations ORDER BY title ASC")->fetchAll();

// Fetch dynamic active education modes
$active_modes = $db->query("SELECT mode_name FROM education_modes_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($active_modes)) {
    $active_modes = ['Online & Distance', 'Online', 'Distance'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full_name = trim($_POST['full_name'] ?? '');
    $short_name = trim($_POST['short_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $short_name ?: $full_name)));
    }

    $mode = trim($_POST['mode'] ?? 'Online & Distance');
    $location = trim($_POST['location'] ?? '');
    $official_url = trim($_POST['official_url'] ?? '');
    $advantage_text = trim($_POST['advantage_text'] ?? '');
    $logo_url = get_relative_asset_path(trim($_POST['logo_url'] ?? ''));
    $desktop_banner_bg = get_relative_asset_path(trim($_POST['desktop_banner_bg'] ?? ''));
    $mobile_banner_bg = get_relative_asset_path(trim($_POST['mobile_banner_bg'] ?? ''));
    $campus_mobile_img = get_relative_asset_path(trim($_POST['campus_mobile_img'] ?? ''));
    $alt_desktop_img = get_relative_asset_path(trim($_POST['alt_desktop_img'] ?? ''));
    $alt_mobile_img = get_relative_asset_path(trim($_POST['alt_mobile_img'] ?? ''));
    $sample_degree_img = get_relative_asset_path(trim($_POST['sample_degree_img'] ?? ''));
    $alt_description = trim($_POST['alt_description'] ?? '');
    $show_in_alternate = isset($_POST['show_in_alternate']) ? 1 : 0;
    $brochure_pdf_url = get_relative_asset_path(trim($_POST['brochure_pdf_url'] ?? ''));
    $podcast_audio_url = get_relative_asset_path(trim($_POST['podcast_audio_url'] ?? ''));
    $youtube_video_url = trim($_POST['youtube_video_url'] ?? '');
    $whatsapp_btn_intent = trim($_POST['whatsapp_btn_intent'] ?? '');
    $gallabox_message_text = trim($_POST['gallabox_message_text'] ?? '');
    $exam_date = trim($_POST['exam_date'] ?? '');
    $extended_exam_date = trim($_POST['extended_exam_date'] ?? '');
    $admission_last_date = trim($_POST['admission_last_date'] ?? '');
    $admission_start_date = trim($_POST['admission_start_date'] ?? '');
    $assignment_date = trim($_POST['assignment_date'] ?? '');
    $rating = (float)($_POST['rating'] ?? 4.0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $selected_accreditations = $_POST['accreditations'] ?? [];

    if (empty($full_name) || empty($short_name) || empty($slug)) {
        set_flash_message('Full Name, Short Name, and Slug are required.', 'error');
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO universities (
                    full_name, short_name, slug, mode, location, official_url, advantage_text,
                    logo_url, desktop_banner_bg, mobile_banner_bg, campus_mobile_img,
                    alt_desktop_img, alt_mobile_img, sample_degree_img, alt_description, show_in_alternate,
                    brochure_pdf_url, podcast_audio_url, youtube_video_url, whatsapp_btn_intent, gallabox_message_text,
                    exam_date, extended_exam_date, admission_last_date, admission_start_date, assignment_date,
                    rating, is_active
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?
                )
            ");
            $stmt->execute([
                $full_name, $short_name, $slug, $mode, $location, $official_url, $advantage_text,
                $logo_url, $desktop_banner_bg, $mobile_banner_bg, $campus_mobile_img,
                $alt_desktop_img, $alt_mobile_img, $sample_degree_img, $alt_description, $show_in_alternate,
                $brochure_pdf_url, $podcast_audio_url, $youtube_video_url, $whatsapp_btn_intent, $gallabox_message_text,
                $exam_date, $extended_exam_date, $admission_last_date, $admission_start_date, $assignment_date,
                $rating, $is_active
            ]);
            $uni_id = $db->lastInsertId();

            // Insert Accreditations
            if (!empty($selected_accreditations)) {
                $acc_stmt = $db->prepare("INSERT INTO university_accreditations (university_id, accreditation_id) VALUES (?, ?)");
                foreach ($selected_accreditations as $acc_id) {
                    $acc_stmt->execute([$uni_id, (int)$acc_id]);
                }
            }

            // Create form configuration
            $cfg_source = trim($_POST['cfg_source'] ?? 'MISC');
            $cfg_default_utm_source = trim($_POST['cfg_default_utm_source'] ?? 'Organic');
            $cfg_default_utm_medium = trim($_POST['cfg_default_utm_medium'] ?? ($short_name ? $short_name . '_Organic' : 'Direct'));
            $cfg_default_utm_campaign = trim($_POST['cfg_default_utm_campaign'] ?? ($short_name ? $short_name . '_Organic' : 'Universal'));
            $cfg_gallabox_source = trim($_POST['cfg_gallabox_source'] ?? 'MISC');
            $cfg_gallabox_webhook = '';
            $cfg_brevo_source = trim($_POST['cfg_brevo_source'] ?? 'MISC');
            $cfg_brevo_list_id = (int)($_POST['cfg_brevo_list_id'] ?? 124);
            $cfg_brevo_api_key = '';
            $cfg_crm_api_key = '';
            $cfg_crm_secret = '';

            // Handle Selected Form Courses from Form Courses Master
            $selected_ids = $_POST['selected_form_courses'] ?? [];
            if (!is_array($selected_ids)) $selected_ids = [];
            $selected_ids_map = array_flip(array_map('intval', $selected_ids));

            $master_courses = $db->query("SELECT * FROM form_courses WHERE is_active = 1 ORDER BY sort_order ASC, level ASC, display_label ASC")->fetchAll();

            $allowed_courses_arr = [];
            foreach ($master_courses as $mc) {
                $is_enabled = isset($selected_ids_map[(int)$mc['id']]) ? 1 : 0;
                $allowed_courses_arr[] = [
                    'label'     => $mc['display_label'],
                    'key'       => strtoupper($mc['form_key']),
                    'enabled'   => $is_enabled,
                    'level'     => $mc['level'],
                    'is_custom' => 0
                ];
            }

            $cfg_allowed_courses = json_encode($allowed_courses_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $form_stmt = $db->prepare("
                INSERT INTO university_form_configs (
                    university_id, source, default_utm_source, default_utm_medium, default_utm_campaign,
                    gallabox_source, brevo_source, brevo_list_id, allowed_courses_json
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");
            $form_stmt->execute([
                $uni_id, $cfg_source, $cfg_default_utm_source, $cfg_default_utm_medium, $cfg_default_utm_campaign,
                $cfg_gallabox_source, $cfg_brevo_source, $cfg_brevo_list_id, $cfg_allowed_courses
            ]);

            if (function_exists('log_activity')) {
                log_activity('CREATE', 'universities', "Created new University '{$full_name}' ({$slug})", [
                    'item_type' => 'University',
                    'item_id' => $uni_id,
                    'item_title' => $full_name,
                    'new_values' => ['full_name' => $full_name, 'short_name' => $short_name, 'slug' => $slug, 'mode' => $mode, 'location' => $location]
                ]);
            }

            if (function_exists('sode_bust_all_subdomain_caches')) {
                sode_bust_all_subdomain_caches($db);
            }

            set_flash_message('University added successfully!', 'success');
            redirect(BASE_URL . '/modules/universities/index.php');
        } catch (PDOException $e) {
            set_flash_message('Database Error: ' . $e->getMessage(), 'error');
        }
    }
}

if (!function_exists('sode_get_university_form_courses')) {
    function sode_get_university_form_courses(PDO $db, $saved_json = '') {
        $master_courses = $db->query("SELECT * FROM form_courses WHERE is_active = 1 ORDER BY sort_order ASC, level ASC, display_label ASC")->fetchAll();
        
        $selected_map = [];
        $is_new_or_empty = empty($saved_json);
        
        if (!$is_new_or_empty) {
            $decoded = json_decode($saved_json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $c) {
                    if (!is_array($c)) continue;
                    $lbl = trim($c['label'] ?? '');
                    $k = strtoupper(trim($c['key'] ?? ''));
                    $enabled = isset($c['enabled']) ? (int)(bool)$c['enabled'] : 1;
                    if ($lbl !== '') {
                        $selected_map['lbl:' . mb_strtolower($lbl)] = $enabled;
                    }
                    if ($k !== '') {
                        if (!isset($selected_map['key:' . $k])) {
                            $selected_map['key:' . $k] = $enabled;
                        }
                    }
                }
            } else {
                $parts = array_filter(array_map('trim', explode(',', $saved_json)));
                foreach ($parts as $p) {
                    $k = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $p));
                    if ($k !== '') {
                        $selected_map['key:' . $k] = 1;
                        $selected_map['lbl:' . mb_strtolower($p)] = 1;
                    }
                }
            }
        }

        $result = [];
        foreach ($master_courses as $mc) {
            $lbl = trim($mc['display_label']);
            $k = strtoupper($mc['form_key']);
            $lbl_key = 'lbl:' . mb_strtolower($lbl);
            $k_key = 'key:' . $k;

            if ($is_new_or_empty) {
                $is_enabled = 1;
            } elseif (isset($selected_map[$lbl_key])) {
                $is_enabled = $selected_map[$lbl_key];
            } elseif (isset($selected_map[$k_key])) {
                $is_enabled = $selected_map[$k_key];
            } else {
                $is_enabled = 0;
            }
            
            $result[] = [
                'id'            => $mc['id'],
                'label'         => $mc['display_label'],
                'key'           => $k,
                'level'         => $mc['level'],
                'sort_order'    => $mc['sort_order'],
                'enabled'       => $is_enabled
            ];
        }

        return $result;
    }
}

// Fetch Form Courses for selection
$configured_courses = sode_get_university_form_courses($db, '');

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <span class="section-heading-sm" style="margin-bottom:0;">New University Details</span>
    <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn-secondary btn-sm" style="text-decoration:none;">&larr; Back to List</a>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
        <!-- Left Column: Primary Details -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- Basic Information -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">1. Basic Information</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Full University Name *</label>
                            <input type="text" name="full_name" id="field_full_name" class="form-control" placeholder="e.g. Jain (Deemed-to-be-University)" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Short Name *</label>
                            <input type="text" name="short_name" id="field_short_name" class="form-control" placeholder="e.g. Jain University" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Slug (Subdomain Identifier) *</label>
                            <input type="text" name="slug" id="field_slug" class="form-control" placeholder="e.g. jain-university" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Education Mode</label>
                            <select name="mode" class="form-select">
                                <?php foreach ($active_modes as $m_opt): ?>
                                    <option value="<?php echo htmlspecialchars($m_opt); ?>" <?php echo ($m_opt === 'Online & Distance') ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m_opt); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rating (Out of 5.0)</label>
                            <input type="number" step="0.1" min="1" max="5" name="rating" class="form-control" value="4.2">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Location / State</label>
                            <input type="text" name="location" class="form-control" placeholder="e.g. Bangalore, Karnataka">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Official Portal URL</label>
                            <input type="url" name="official_url" class="form-control" placeholder="https://...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Advantage / Key Highlights</label>
                        <textarea name="advantage_text" class="form-textarea" placeholder="e.g. NAAC A++ Accredited, Global Curriculum, 100% Placement Assistance"></textarea>
                    </div>
                </div>
            </div>

            <!-- Media & Assets -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">2. Media & Assets</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">University Logo</label>
                            <div class="media-input-group">
                                <input type="text" name="logo_url" id="field_logo_url" class="form-control" placeholder="https://.../logo.png">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_logo_url" data-preview="preview_logo" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_logo" style="display:none; margin-top:6px;"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Brochure PDF Document</label>
                            <div class="media-input-group">
                                <input type="text" name="brochure_pdf_url" id="field_brochure_pdf" class="form-control" placeholder="https://.../brochure.pdf">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_brochure_pdf" data-preview="preview_brochure" data-type="pdf">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_brochure" style="display:none; margin-top:6px;"></div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Desktop Hero Banner Image</label>
                            <div class="media-input-group">
                                <input type="text" name="desktop_banner_bg" id="field_desktop_banner" class="form-control" placeholder="https://.../banner-desktop.webp">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_desktop_banner" data-preview="preview_desktop_banner" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_desktop_banner" style="display:none; margin-top:6px;"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Hero Banner Image</label>
                            <div class="media-input-group">
                                <input type="text" name="mobile_banner_bg" id="field_mobile_banner" class="form-control" placeholder="https://.../banner-mobile.webp">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_mobile_banner" data-preview="preview_mobile_banner" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_mobile_banner" style="display:none; margin-top:6px;"></div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Campus Mobile Image</label>
                            <div class="media-input-group">
                                <input type="text" name="campus_mobile_img" id="field_campus_img" class="form-control" placeholder="https://...">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_campus_img" data-preview="preview_campus_img" data-type="image">
                                    Choose
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_campus_img" style="display:none; margin-top:6px;"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Podcast Audio (MP3/M4A)</label>
                            <div class="media-input-group">
                                <input type="text" name="podcast_audio_url" id="field_podcast_audio" class="form-control" placeholder="https://.../audio.mp3">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_podcast_audio" data-preview="preview_podcast_audio" data-type="audio">
                                    Choose
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_podcast_audio" style="display:none; margin-top:6px;"></div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">YouTube Video Embed URL</label>
                            <input type="text" name="youtube_video_url" class="form-control" placeholder="https://youtube.com/embed/...">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">WhatsApp Brochure Button Intent / Message</label>
                        <input type="text" name="whatsapp_btn_intent" class="form-control" placeholder="I want to Download {UNIVERSITY_NAME} {MODE} Brochure">
                        <small style="color:var(--text-muted); font-size:12px; margin-top:4px; display:block;">
                            Placeholders available: <code>{UNIVERSITY_NAME}</code>, <code>{UNI}</code>, <code>{MODE}</code>. If left empty, default message will be used.
                        </small>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">Gallabox WhatsApp Widget Message Text (messageText)</label>
                        <input type="text" name="gallabox_message_text" class="form-control" placeholder="Start Your {UNIVERSITY_NAME} {MODE} Counseling with an Expert Now">
                        <small style="color:var(--text-muted); font-size:12px; margin-top:4px; display:block;">
                            WhatsApp prefilled message for Gallabox widget. Placeholders available: <code>{UNIVERSITY_NAME}</code>, <code>{UNI}</code>, <code>{MODE}</code>. If left empty, global default message text will be used.
                        </small>
                    </div>
                </div>
            </div>

            <!-- Alternate Universities Listing Details -->
            <div class="admin-card" id="alternate-universities-section">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <span class="card-title">3. Alternate Universities Listing Details</span>
                    <span class="badge badge-info" style="font-size:11px;">Alternatives Universities</span>
                </div>
                <div class="card-body">
                    <div style="background:var(--bg-main, #f8fafc); border:1px solid var(--border-color, #e2e8f0); border-radius:8px; padding:14px 16px; margin-bottom:18px;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; font-weight:700; margin:0;">
                            <input type="checkbox" name="show_in_alternate" value="1" style="width:18px; height:18px; cursor:pointer;">
                            <span>Show this university in "Alternatives Universities" list</span>
                        </label>
                        <div style="font-size:12px; color:var(--text-muted); margin-top:4px; margin-left:28px;">
                            When checked, this university will appear in the frontend Alternative Universities showcase cards.
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <!-- Alternate List Desktop Image -->
                        <div class="form-group">
                            <label class="form-label">Alternate List Desktop Image</label>
                            <div class="media-input-group">
                                <input type="text" name="alt_desktop_img" id="field_alt_desktop_img" class="form-control" placeholder="/assets/images/...">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_alt_desktop_img" data-preview="preview_alt_desktop_img" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_alt_desktop_img" style="display:none; margin-top:6px;"></div>
                        </div>

                        <!-- Alternate List Mobile Image -->
                        <div class="form-group">
                            <label class="form-label">Alternate List Mobile Image</label>
                            <div class="media-input-group">
                                <input type="text" name="alt_mobile_img" id="field_alt_mobile_img" class="form-control" placeholder="/assets/images/...">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_alt_mobile_img" data-preview="preview_alt_mobile_img" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_alt_mobile_img" style="display:none; margin-top:6px;"></div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr; gap:16px; margin-top:16px;">
                        <!-- Sample Degree Image -->
                        <div class="form-group">
                            <label class="form-label">Sample Degree Image</label>
                            <div class="media-input-group">
                                <input type="text" name="sample_degree_img" id="field_sample_degree_img" class="form-control" placeholder="/assets/images/...">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_sample_degree_img" data-preview="preview_sample_degree_img" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_sample_degree_img" style="display:none; margin-top:6px;"></div>
                            <small style="color:var(--text-muted); font-size:12px; margin-top:4px; display:block;">
                                This image opens in a modal popup when user clicks "View Sample Degree" in the Alternatives list.
                            </small>
                        </div>
                    </div>

                    <!-- Alternate List Description -->
                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">Alternate List Description</label>
                        <textarea name="alt_description" class="form-control" rows="3" placeholder="Description that will appear only in the Alternate Universities card..."></textarea>
                        <small style="color:var(--text-muted); font-size:12px; margin-top:4px; display:block;">
                            This description will only be shown in the Alternate Universities cards section.
                        </small>
                    </div>
                </div>
            </div>

            <!-- Important Dates -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">4. Important Dates & Deadlines</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Admission Last Date</label>
                            <input type="text" name="admission_last_date" class="form-control" placeholder="e.g. 30 September 2026">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Exam Date</label>
                            <input type="text" name="exam_date" class="form-control" placeholder="e.g. December 2026">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Extended Exam Date</label>
                            <input type="text" name="extended_exam_date" class="form-control" placeholder="e.g. 15 January 2027">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Admission Start Date</label>
                            <input type="text" name="admission_start_date" class="form-control" placeholder="e.g. 01 June 2026">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Assignment Submission Date</label>
                            <input type="text" name="assignment_date" class="form-control" placeholder="e.g. 15 November 2026">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form & Lead Integrations Configuration -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <span class="card-title">5. Form & Lead Integrations Configuration</span>
                    <a href="<?php echo BASE_URL; ?>/modules/settings/api_integrations.php" target="_blank" style="font-size:12px; color:var(--primary); text-decoration:none;">
                        Manage Global API Keys &rarr;
                    </a>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Default Source</label>
                            <input type="text" name="cfg_source" class="form-control" value="MISC" placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default UTM Source</label>
                            <input type="text" name="cfg_default_utm_source" class="form-control" value="Organic" placeholder="e.g. Organic">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default UTM Medium</label>
                            <input type="text" name="cfg_default_utm_medium" class="form-control" placeholder="e.g. DSU_Organic">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Default UTM Campaign</label>
                            <input type="text" name="cfg_default_utm_campaign" class="form-control" placeholder="e.g. DSU_Organic">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Gallabox Source</label>
                            <input type="text" name="cfg_gallabox_source" class="form-control" value="MISC" placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Brevo Source</label>
                            <input type="text" name="cfg_brevo_source" class="form-control" value="MISC" placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Brevo List ID</label>
                            <input type="number" name="cfg_brevo_list_id" class="form-control" value="124" placeholder="124">
                        </div>
                    </div>

                    <!-- Allowed Courses from Form Courses Master -->
                    <div class="form-group" style="margin-top:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                            <div>
                                <label class="form-label" style="margin-bottom:2px; font-size:13.5px; font-weight:700;">Allowed Courses in Form Dropdown</label>
                                <span style="font-size:12px; color:var(--text-dim); display:block;">
                                    Select which courses appear in this university's lead form dropdown. To add new courses or edit labels, visit <a href="<?php echo BASE_URL; ?>/modules/form_courses/index.php" target="_blank" style="color:#60a5fa; text-decoration:underline; font-weight:600;">Form Courses Master</a>.
                                </span>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <input type="text" id="uniCourseSearch" class="form-control" placeholder="🔍 Search courses..." onkeyup="filterUniCourses(this.value)" style="font-size:12px; padding:5px 10px; width:170px;">
                                <button type="button" class="btn-sm action-btn" onclick="selectAllCourseRows(true)" style="padding:5px 10px; font-size:11.5px;">Select All</button>
                                <button type="button" class="btn-sm action-btn" onclick="selectAllCourseRows(false)" style="padding:5px 10px; font-size:11.5px;">Clear All</button>
                            </div>
                        </div>

                        <div style="border:1px solid var(--border-color); border-radius:8px; overflow:hidden; background:rgba(255,255,255,0.015); box-shadow:inset 0 1px 3px rgba(0,0,0,0.2);">
                            <div style="max-height: 440px; overflow-y: auto;">
                                <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
                                    <thead>
                                        <tr style="background:rgba(255,255,255,0.04); border-bottom:1px solid var(--border-color); color:var(--text-dim); font-size:11.5px; text-transform:uppercase; letter-spacing:0.5px; position:sticky; top:0; z-index:2; backdrop-filter:blur(4px);">
                                            <th style="padding:10px 14px; width:60px; text-align:center;">Select</th>
                                            <th style="padding:10px 14px;">Display Label (Visitor Sees)</th>
                                            <th style="padding:10px 14px; width:220px;">Form & CRM Key</th>
                                            <th style="padding:10px 14px; width:110px; text-align:center;">Level</th>
                                        </tr>
                                    </thead>
                                    <tbody id="allowedCoursesTableBody">
                                        <?php if (empty($configured_courses)): ?>
                                            <tr>
                                                <td colspan="4" style="text-align:center; padding:30px 15px; color:var(--text-dim);">
                                                    No courses found in Form Courses Master. <a href="<?php echo BASE_URL; ?>/modules/form_courses/index.php" target="_blank" style="color:#60a5fa;">Add Form Courses &rarr;</a>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($configured_courses as $c): 
                                                $lvl_color = '#94a3b8';
                                                $lvl_bg = 'rgba(255,255,255,0.06)';
                                                $lvl_upper = strtoupper($c['level']);
                                                if ($lvl_upper === 'PG') { $lvl_color = '#c084fc'; $lvl_bg = 'rgba(168,85,247,0.15)'; }
                                                elseif ($lvl_upper === 'UG') { $lvl_color = '#60a5fa'; $lvl_bg = 'rgba(59,130,246,0.15)'; }
                                                elseif ($lvl_upper === 'DIPLOMA') { $lvl_color = '#fbbf24'; $lvl_bg = 'rgba(245,158,11,0.15)'; }
                                            ?>
                                                <tr class="uni-course-row" data-search="<?php echo htmlspecialchars(strtolower($c['label'] . ' ' . $c['key'])); ?>" style="border-bottom:1px solid rgba(255,255,255,0.04); transition:background 0.2s;">
                                                    <td style="padding:9px 14px; text-align:center; vertical-align:middle;">
                                                        <input type="checkbox" name="selected_form_courses[]" value="<?php echo (int)$c['id']; ?>" class="course-row-checkbox" <?php echo !empty($c['enabled']) ? 'checked' : ''; ?> style="width:17px; height:17px; cursor:pointer;">
                                                    </td>
                                                    <td style="padding:9px 14px; vertical-align:middle;">
                                                        <span style="font-weight:700; color:var(--text-main); font-size:13.5px;">
                                                            <?php echo htmlspecialchars($c['label']); ?>
                                                        </span>
                                                    </td>
                                                    <td style="padding:9px 14px; vertical-align:middle;">
                                                        <code style="color:#38bdf8; font-weight:700; font-size:12.5px; background:rgba(56,189,248,0.1); padding:3px 8px; border-radius:4px; border:1px solid rgba(56,189,248,0.2);">
                                                            <?php echo htmlspecialchars($c['key']); ?>
                                                        </code>
                                                    </td>
                                                    <td style="padding:9px 14px; text-align:center; vertical-align:middle;">
                                                        <span class="badge" style="background:<?php echo $lvl_bg; ?>; color:<?php echo $lvl_color; ?>; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:600;">
                                                            <?php echo htmlspecialchars($c['level']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function selectAllCourseRows(checked) {
                document.querySelectorAll('.course-row-checkbox').forEach(cb => cb.checked = checked);
            }

            function filterUniCourses(query) {
                query = (query || '').toLowerCase().trim();
                document.querySelectorAll('.uni-course-row').forEach(row => {
                    const txt = row.getAttribute('data-search') || '';
                    row.style.display = (!query || txt.indexOf(query) !== -1) ? '' : 'none';
                });
            }
        </script>

        <!-- Right Column: Accreditations & Status -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Publish & Status</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:600;">
                            <input type="checkbox" name="is_active" value="1" checked>
                            Active & Published
                        </label>
                    </div>

                    <button type="submit" class="btn-primary" style="padding:13px 20px;">
                        Save University Record
                    </button>
                </div>
            </div>

            <!-- Accreditations Picker -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Accreditations & Approvals</span>
                </div>
                <div class="card-body">
                    <?php if (empty($all_accreditations)): ?>
                        <p style="font-size:12px; color:var(--text-dim);">No accreditations created yet. Add them in Accreditations module.</p>
                    <?php else: ?>
                        <!-- Live Search Bar -->
                        <div style="margin-bottom: 12px;">
                            <input type="text" 
                                   id="search_accreditations" 
                                   class="form-control" 
                                   placeholder="🔍 Search accreditations..." 
                                   style="font-size: 12.5px; padding: 7px 10px; width: 100%; border-radius: 6px;"
                                   oninput="filterAccreditations(this.value)">
                        </div>
                        <div id="accreditations_list" style="max-height:300px; overflow-y:auto; display:flex; flex-direction:column; gap:10px; padding-right: 4px;">
                            <?php foreach ($all_accreditations as $acc): ?>
                                <label class="acc-checkbox-item" data-title="<?php echo strtolower(htmlspecialchars($acc['title'])); ?>" style="display:flex; align-items:center; gap:10px; font-size:13px; cursor:pointer;">
                                    <input type="checkbox" name="accreditations[]" value="<?php echo $acc['id']; ?>">
                                    <span><?php echo htmlspecialchars($acc['title']); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <div id="no_acc_found" style="display:none; font-size:12px; color:var(--text-dim); text-align:center; padding:12px 0;">
                                No matching accreditations found
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fullNameInput = document.getElementById('field_full_name');
    const shortNameInput = document.getElementById('field_short_name');
    const slugInput = document.getElementById('field_slug');
    
    if (!slugInput) return;

    let isSlugManuallyEdited = false;

    function generateSlug(text) {
        return text
            .toString()
            .toLowerCase()
            .trim()
            .replace(/&/g, '-and-')
            .replace(/[^a-z0-9]+/g, '-')   // Replace non-alphanumeric chars with hyphens
            .replace(/^-+|-+$/g, '');      // Trim hyphens from start and end
    }

    function syncSlug() {
        if (isSlugManuallyEdited) return;
        const sourceText = (fullNameInput && fullNameInput.value.trim() !== '') ? fullNameInput.value : (shortNameInput ? shortNameInput.value : '');
        slugInput.value = generateSlug(sourceText);
    }

    if (fullNameInput) {
        fullNameInput.addEventListener('input', syncSlug);
    }

    if (shortNameInput) {
        shortNameInput.addEventListener('input', function() {
            if (!fullNameInput || fullNameInput.value.trim() === '') {
                syncSlug();
            }
        });
    }

    slugInput.addEventListener('input', function() {
        if (this.value.trim() === '') {
            isSlugManuallyEdited = false;
            syncSlug();
        } else {
            isSlugManuallyEdited = true;
        }
    });
});

function filterAccreditations(query) {
    const q = (query || '').toLowerCase().trim();
    const items = document.querySelectorAll('.acc-checkbox-item');
    let visibleCount = 0;
    items.forEach(function(item) {
        const title = item.getAttribute('data-title') || item.innerText.toLowerCase();
        if (q === '' || title.indexOf(q) !== -1) {
            item.style.display = 'flex';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    const noFound = document.getElementById('no_acc_found');
    if (noFound) {
        noFound.style.display = (visibleCount === 0 && items.length > 0) ? 'block' : 'none';
    }
}
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
