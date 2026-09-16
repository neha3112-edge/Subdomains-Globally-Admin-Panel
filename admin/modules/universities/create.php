<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');

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

            // Handle Course Items (Label, Key, Enabled, Custom)
            $course_items_post = $_POST['course_items'] ?? [];
            $allowed_courses_arr = [];

            if (!empty($course_items_post) && is_array($course_items_post)) {
                foreach ($course_items_post as $item) {
                    $lbl = trim($item['label'] ?? '');
                    $k = strtoupper(trim($item['key'] ?? ''));
                    $enabled = !empty($item['enabled']) ? 1 : 0;
                    $is_custom = !empty($item['is_custom']) ? 1 : 0;
                    $level = trim($item['level'] ?? '');

                    if ($lbl === '') continue;
                    if ($k === '') {
                        $k = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $lbl));
                    }

                    $allowed_courses_arr[] = [
                        'label'     => $lbl,
                        'key'       => $k,
                        'enabled'   => $enabled,
                        'is_custom' => $is_custom,
                        'level'     => $level
                    ];
                }
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

            set_flash_message('University added successfully!', 'success');
            redirect(BASE_URL . '/modules/universities/index.php');
        } catch (PDOException $e) {
            set_flash_message('Database Error: ' . $e->getMessage(), 'error');
        }
    }
}

if (!function_exists('sode_get_configured_course_items')) {
    function sode_get_configured_course_items($raw_json, $all_master_courses) {
        $items = [];
        $seen = [];

        $decoded = !empty($raw_json) ? json_decode($raw_json, true) : null;

        if (is_array($decoded)) {
            foreach ($decoded as $c) {
                if (!is_array($c)) continue;
                $lbl = trim($c['label'] ?? '');
                $k = strtoupper(trim($c['key'] ?? ''));
                if ($lbl === '') continue;
                if ($k === '') $k = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $lbl));
                $enabled = isset($c['enabled']) ? (int)(bool)$c['enabled'] : 1;
                $is_custom = !empty($c['is_custom']) ? 1 : 0;
                $level = trim($c['level'] ?? ($is_custom ? 'Custom' : 'General'));

                $items[] = [
                    'label'     => $lbl,
                    'key'       => $k,
                    'enabled'   => $enabled,
                    'is_custom' => $is_custom,
                    'level'     => $level
                ];
                $seen[strtoupper($lbl)] = true;
                $seen[$k] = true;
            }

            foreach ($all_master_courses as $mc) {
                $lbl = $mc['short_name'];
                $k = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $lbl));
                if (!isset($seen[strtoupper($lbl)]) && !isset($seen[$k])) {
                    $items[] = [
                        'label'     => $lbl,
                        'key'       => $k,
                        'enabled'   => 0,
                        'is_custom' => 0,
                        'level'     => $mc['level'] ?? ''
                    ];
                    $seen[strtoupper($lbl)] = true;
                    $seen[$k] = true;
                }
            }
        } else {
            $enabled_map = [];
            if (!empty($raw_json)) {
                $parts = array_map('trim', explode(',', $raw_json));
                foreach ($parts as $p) {
                    if ($p !== '') {
                        $enabled_map[strtoupper($p)] = true;
                        $enabled_map[strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $p))] = true;
                    }
                }
            } else {
                $enabled_map = null;
            }

            foreach ($all_master_courses as $mc) {
                $lbl = $mc['short_name'];
                $k = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $lbl));
                $is_en = ($enabled_map === null) ? 1 : (isset($enabled_map[strtoupper($lbl)]) || isset($enabled_map[$k]) ? 1 : 0);

                $items[] = [
                    'label'     => $lbl,
                    'key'       => $k,
                    'enabled'   => $is_en,
                    'is_custom' => 0,
                    'level'     => $mc['level'] ?? ''
                ];
                $seen[strtoupper($lbl)] = true;
                $seen[$k] = true;
            }

            $is_other_en = ($enabled_map === null) ? 1 : (isset($enabled_map['OTHER']) ? 1 : 0);
            $items[] = [
                'label'     => 'Other',
                'key'       => 'OTHER',
                'enabled'   => $is_other_en,
                'is_custom' => 0,
                'level'     => 'General'
            ];
        }

        return $items;
    }
}

// Fetch Master Courses for Checkbox Grid
$all_master_courses = $db->query("SELECT * FROM courses ORDER BY level ASC, short_name ASC")->fetchAll();
$configured_courses = sode_get_configured_course_items('', $all_master_courses);

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <span class="section-heading-sm" style="margin-bottom:0;">New University Details</span>
    <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn-sm action-btn" style="width:auto; padding:6px 14px; text-decoration:none;">&larr; Back to List</a>
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

                    <!-- Allowed Courses with Label, Key & Custom Add -->
                    <div class="form-group" style="margin-top:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                            <div>
                                <label class="form-label" style="margin-bottom:2px; font-size:13.5px; font-weight:700;">Allowed Courses in Form Dropdown</label>
                                <span style="font-size:12px; color:var(--text-dim); display:block;">
                                    Configure dropdown options with customized <strong>Display Label</strong> and backend <strong>Key (All CAPITAL)</strong>. Uncheck to hide from form.
                                </span>
                            </div>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <button type="button" class="btn-sm" onclick="addCustomCourseRow()" style="background:linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; border:none; padding:5px 12px; font-size:12px; border-radius:6px; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; gap:5px; box-shadow: 0 2px 8px rgba(99,102,241,0.3);">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    + Add Custom Course
                                </button>
                                <button type="button" class="btn-sm action-btn" onclick="selectAllCourseRows(true)" style="padding:5px 10px; font-size:11.5px;">Select All</button>
                                <button type="button" class="btn-sm action-btn" onclick="selectAllCourseRows(false)" style="padding:5px 10px; font-size:11.5px;">Clear All</button>
                            </div>
                        </div>

                        <div style="border:1px solid var(--border-color); border-radius:8px; overflow:hidden; background:rgba(255,255,255,0.015); box-shadow:inset 0 1px 3px rgba(0,0,0,0.2);">
                            <div style="max-height: 480px; overflow-y: auto;">
                                <table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">
                                    <thead>
                                        <tr style="background:rgba(255,255,255,0.04); border-bottom:1px solid var(--border-color); color:var(--text-dim); font-size:11.5px; text-transform:uppercase; letter-spacing:0.5px; position:sticky; top:0; z-index:2; backdrop-filter:blur(4px);">
                                            <th style="padding:10px 12px; width:60px; text-align:center;">Active</th>
                                            <th style="padding:10px 12px;">Display Label (Visitor Sees)</th>
                                            <th style="padding:10px 12px; width:260px;">Form & CRM Key (All CAPITAL)</th>
                                            <th style="padding:10px 12px; width:90px; text-align:center;">Level</th>
                                            <th style="padding:10px 12px; width:60px; text-align:center;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="allowedCoursesTableBody">
                                        <?php foreach ($configured_courses as $idx => $c): ?>
                                            <tr class="course-item-row" style="border-bottom:1px solid rgba(255,255,255,0.04); transition:background 0.2s;">
                                                <td style="padding:8px 12px; text-align:center; vertical-align:middle;">
                                                    <input type="hidden" name="course_items[<?php echo $idx; ?>][enabled]" value="0">
                                                    <input type="checkbox" name="course_items[<?php echo $idx; ?>][enabled]" value="1" class="course-row-checkbox" <?php echo !empty($c['enabled']) ? 'checked' : ''; ?> style="width:16px; height:16px; cursor:pointer;">
                                                </td>
                                                <td style="padding:8px 12px; vertical-align:middle;">
                                                    <input type="text" name="course_items[<?php echo $idx; ?>][label]" class="form-control course-label-input" value="<?php echo htmlspecialchars($c['label']); ?>" placeholder="e.g. B.Com" required style="font-size:13px; padding:6px 10px;">
                                                </td>
                                                <td style="padding:8px 12px; vertical-align:middle;">
                                                    <input type="text" name="course_items[<?php echo $idx; ?>][key]" class="form-control course-key-input" value="<?php echo htmlspecialchars($c['key']); ?>" placeholder="e.g. BCOM" required style="font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:6px 10px; color:#38bdf8;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '');">
                                                </td>
                                                <td style="padding:8px 12px; text-align:center; vertical-align:middle;">
                                                    <input type="hidden" name="course_items[<?php echo $idx; ?>][level]" value="<?php echo htmlspecialchars($c['level']); ?>">
                                                    <input type="hidden" name="course_items[<?php echo $idx; ?>][is_custom]" value="<?php echo !empty($c['is_custom']) ? 1 : 0; ?>">
                                                    <span class="badge" style="background:<?php echo !empty($c['is_custom']) ? 'rgba(168,85,247,0.18)' : 'rgba(255,255,255,0.06)'; ?>; color:<?php echo !empty($c['is_custom']) ? '#c084fc' : 'var(--text-dim)'; ?>; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:600;">
                                                        <?php echo htmlspecialchars($c['level'] ?: (!empty($c['is_custom']) ? 'Custom' : 'General')); ?>
                                                    </span>
                                                </td>
                                                <td style="padding:8px 12px; text-align:center; vertical-align:middle;">
                                                    <?php if (!empty($c['is_custom'])): ?>
                                                        <button type="button" class="btn-icon" onclick="removeCourseRow(this)" title="Delete Custom Course" style="background:none; border:none; color:#ef4444; font-size:18px; cursor:pointer; line-height:1; padding:4px;">&times;</button>
                                                    <?php else: ?>
                                                        <span style="color:var(--text-dim); font-size:12px;" title="Standard course">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            let courseRowIndex = <?php echo count($configured_courses); ?>;

            function selectAllCourseRows(checked) {
                document.querySelectorAll('.course-row-checkbox').forEach(cb => cb.checked = checked);
            }

            function removeCourseRow(btn) {
                const row = btn.closest('tr');
                if (row) row.remove();
            }

            function addCustomCourseRow() {
                const tbody = document.getElementById('allowedCoursesTableBody');
                const tr = document.createElement('tr');
                tr.className = 'course-item-row';
                tr.style.cssText = 'border-bottom:1px solid rgba(255,255,255,0.05); background:rgba(99,102,241,0.06); transition:background 0.2s;';
                
                const idx = courseRowIndex++;
                tr.innerHTML = `
                    <td style="padding:8px 12px; text-align:center; vertical-align:middle;">
                        <input type="hidden" name="course_items[${idx}][enabled]" value="0">
                        <input type="checkbox" name="course_items[${idx}][enabled]" value="1" class="course-row-checkbox" checked style="width:16px; height:16px; cursor:pointer;">
                    </td>
                    <td style="padding:8px 12px; vertical-align:middle;">
                        <input type="text" name="course_items[${idx}][label]" class="form-control course-label-input" placeholder="e.g. Executive MBA" required style="font-size:13px; padding:6px 10px;" oninput="autoSuggestCourseKey(this, ${idx})">
                    </td>
                    <td style="padding:8px 12px; vertical-align:middle;">
                        <input type="text" id="course_key_${idx}" name="course_items[${idx}][key]" class="form-control course-key-input" placeholder="e.g. EXECUTIVE_MBA" required style="font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:6px 10px; color:#38bdf8;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, ''); this.dataset.manualEdited='1';">
                    </td>
                    <td style="padding:8px 12px; text-align:center; vertical-align:middle;">
                        <input type="hidden" name="course_items[${idx}][level]" value="Custom">
                        <input type="hidden" name="course_items[${idx}][is_custom]" value="1">
                        <span class="badge" style="background:rgba(168,85,247,0.18); color:#c084fc; font-size:11px; padding:3px 8px; border-radius:4px; font-weight:600;">Custom</span>
                    </td>
                    <td style="padding:8px 12px; text-align:center; vertical-align:middle;">
                        <button type="button" class="btn-icon" onclick="removeCourseRow(this)" title="Delete Custom Course" style="background:none; border:none; color:#ef4444; font-size:18px; cursor:pointer; line-height:1; padding:4px;">&times;</button>
                    </td>
                `;
                tbody.appendChild(tr);
                const labelInput = tr.querySelector('.course-label-input');
                if (labelInput) labelInput.focus();
            }

            function autoSuggestCourseKey(input, idx) {
                const keyInput = document.getElementById('course_key_' + idx);
                if (!keyInput || keyInput.dataset.manualEdited === '1') return;
                const clean = input.value.toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
                keyInput.value = clean;
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
                <div class="card-body" style="max-height:350px; overflow-y:auto;">
                    <?php if (empty($all_accreditations)): ?>
                        <p style="font-size:12px; color:var(--text-dim);">No accreditations created yet. Add them in Accreditations module.</p>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($all_accreditations as $acc): ?>
                                <label style="display:flex; align-items:center; gap:10px; font-size:13px; cursor:pointer;">
                                    <input type="checkbox" name="accreditations[]" value="<?php echo $acc['id']; ?>">
                                    <span><?php echo htmlspecialchars($acc['title']); ?></span>
                                </label>
                            <?php endforeach; ?>
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
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
