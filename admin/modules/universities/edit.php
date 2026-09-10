<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');

$page_title = 'Edit University';
$page_subtitle = 'Update university profile and settings';
$active_page_key = 'universities';

$db = get_db_connection();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    redirect(BASE_URL . '/modules/universities/index.php');
}

// Fetch University
$stmt = $db->prepare("SELECT * FROM universities WHERE id = ?");
$stmt->execute([$id]);
$uni = $stmt->fetch();

if (!$uni) {
    set_flash_message('University not found.', 'error');
    redirect(BASE_URL . '/modules/universities/index.php');
}

// Fetch selected accreditations
$stmt = $db->prepare("SELECT accreditation_id FROM university_accreditations WHERE university_id = ?");
$stmt->execute([$id]);
$assigned_accs = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch all accreditations
$all_accreditations = $db->query("SELECT * FROM accreditations ORDER BY title ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full_name = trim($_POST['full_name'] ?? '');
    $short_name = trim($_POST['short_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $mode = trim($_POST['mode'] ?? 'Online & Distance');
    $location = trim($_POST['location'] ?? '');
    $official_url = trim($_POST['official_url'] ?? '');
    $advantage_text = trim($_POST['advantage_text'] ?? '');
    $logo_url = trim($_POST['logo_url'] ?? '');
    $desktop_banner_bg = trim($_POST['desktop_banner_bg'] ?? '');
    $mobile_banner_bg = trim($_POST['mobile_banner_bg'] ?? '');
    $campus_mobile_img = trim($_POST['campus_mobile_img'] ?? '');
    $brochure_pdf_url = trim($_POST['brochure_pdf_url'] ?? '');
    $podcast_audio_url = trim($_POST['podcast_audio_url'] ?? '');
    $youtube_video_url = trim($_POST['youtube_video_url'] ?? '');
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
                UPDATE universities SET
                    full_name = ?, short_name = ?, slug = ?, mode = ?, location = ?, official_url = ?, advantage_text = ?,
                    logo_url = ?, desktop_banner_bg = ?, mobile_banner_bg = ?, campus_mobile_img = ?,
                    brochure_pdf_url = ?, podcast_audio_url = ?, youtube_video_url = ?,
                    exam_date = ?, extended_exam_date = ?, admission_last_date = ?, admission_start_date = ?, assignment_date = ?,
                    rating = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $full_name, $short_name, $slug, $mode, $location, $official_url, $advantage_text,
                $logo_url, $desktop_banner_bg, $mobile_banner_bg, $campus_mobile_img,
                $brochure_pdf_url, $podcast_audio_url, $youtube_video_url,
                $exam_date, $extended_exam_date, $admission_last_date, $admission_start_date, $assignment_date,
                $rating, $is_active,
                $id
            ]);

            // Sync Accreditations
            $db->prepare("DELETE FROM university_accreditations WHERE university_id = ?")->execute([$id]);
            if (!empty($selected_accreditations)) {
                $acc_stmt = $db->prepare("INSERT INTO university_accreditations (university_id, accreditation_id) VALUES (?, ?)");
                foreach ($selected_accreditations as $acc_id) {
                    $acc_stmt->execute([$id, (int)$acc_id]);
                }
            }

            // Sync Form & Lead Configuration
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

            // Handle Course Checkboxes
            $allowed_courses_post = $_POST['allowed_courses'] ?? [];
            if (!empty($allowed_courses_post) && is_array($allowed_courses_post)) {
                $cfg_allowed_courses = implode(', ', array_map('trim', $allowed_courses_post));
            } else {
                $cfg_allowed_courses = trim($_POST['cfg_allowed_courses_json'] ?? '');
            }

            $upsert_cfg = $db->prepare("
                INSERT INTO university_form_configs (
                    university_id, source, default_utm_source, default_utm_medium, default_utm_campaign,
                    gallabox_source, brevo_source, brevo_list_id, allowed_courses_json
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?
                ) ON DUPLICATE KEY UPDATE
                    source = VALUES(source),
                    default_utm_source = VALUES(default_utm_source),
                    default_utm_medium = VALUES(default_utm_medium),
                    default_utm_campaign = VALUES(default_utm_campaign),
                    gallabox_source = VALUES(gallabox_source),
                    brevo_source = VALUES(brevo_source),
                    brevo_list_id = VALUES(brevo_list_id),
                    allowed_courses_json = VALUES(allowed_courses_json)
            ");
            $upsert_cfg->execute([
                $id, $cfg_source, $cfg_default_utm_source, $cfg_default_utm_medium, $cfg_default_utm_campaign,
                $cfg_gallabox_source, $cfg_brevo_source, $cfg_brevo_list_id, $cfg_allowed_courses
            ]);

            set_flash_message('University updated successfully!', 'success');
            redirect(BASE_URL . '/modules/universities/edit.php?id=' . $id);
        } catch (PDOException $e) {
            set_flash_message('Database Error: ' . $e->getMessage(), 'error');
        }
    }
}

// Fetch Form Config
$form_cfg_stmt = $db->prepare("SELECT * FROM university_form_configs WHERE university_id = ?");
$form_cfg_stmt->execute([$id]);
$form_cfg = $form_cfg_stmt->fetch();
if (!$form_cfg) {
    $form_cfg = [
        'source' => 'MISC',
        'default_utm_source' => 'Organic',
        'default_utm_medium' => $uni['slug'] ? strtoupper($uni['slug']) . '_Organic' : 'DSU_Organic',
        'default_utm_campaign' => $uni['slug'] ? strtoupper($uni['slug']) . '_Organic' : 'DSU_Organic',
        'gallabox_source' => 'MISC',
        'brevo_source' => 'MISC',
        'brevo_list_id' => 124,
        'allowed_courses_json' => 'MBA, MCA, MCOM, MA, MSC, MLIS, BBA, BCA, BCOM, BA, BSC, BLIS, Other'
    ];
}

// Fetch Master Courses for Checkbox Grid
$all_master_courses = $db->query("SELECT * FROM courses ORDER BY level ASC, short_name ASC")->fetchAll();
$selected_courses_arr = array_map('trim', explode(',', $form_cfg['allowed_courses_json'] ?? 'MBA, MCA, MCOM, MA, MSC, MLIS, BBA, BCA, BCOM, BA, BSC, BLIS, Other'));

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <span class="section-heading-sm" style="margin-bottom:0;">Edit University: <?php echo htmlspecialchars($uni['short_name']); ?></span>
    <div style="display:flex; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn-sm action-btn" style="width:auto; padding:6px 14px; text-decoration:none;">&larr; Back to List</a>
    </div>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
        <!-- Left Column -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">1. Basic Information</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Full University Name *</label>
                            <input type="text" name="full_name" id="field_full_name" class="form-control" value="<?php echo htmlspecialchars($uni['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Short Name *</label>
                            <input type="text" name="short_name" id="field_short_name" class="form-control" value="<?php echo htmlspecialchars($uni['short_name']); ?>" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Slug (Subdomain Identifier) *</label>
                            <input type="text" name="slug" id="field_slug" class="form-control" value="<?php echo htmlspecialchars($uni['slug']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Education Mode</label>
                            <select name="mode" class="form-select">
                                <option value="Online & Distance" <?php echo ($uni['mode'] === 'Online & Distance') ? 'selected' : ''; ?>>Online & Distance</option>
                                <option value="Online" <?php echo ($uni['mode'] === 'Online') ? 'selected' : ''; ?>>Online</option>
                                <option value="Distance" <?php echo ($uni['mode'] === 'Distance') ? 'selected' : ''; ?>>Distance</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rating (Out of 5.0)</label>
                            <input type="number" step="0.1" min="1" max="5" name="rating" class="form-control" value="<?php echo htmlspecialchars($uni['rating']); ?>">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Location / State</label>
                            <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($uni['location'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Official Portal URL</label>
                            <input type="url" name="official_url" class="form-control" value="<?php echo htmlspecialchars($uni['official_url'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Advantage / Key Highlights</label>
                        <textarea name="advantage_text" class="form-textarea"><?php echo htmlspecialchars($uni['advantage_text'] ?? ''); ?></textarea>
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
                                <input type="text" name="logo_url" id="field_logo_url" class="form-control" value="<?php echo htmlspecialchars($uni['logo_url'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_logo_url" data-preview="preview_logo" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_logo" style="margin-top:6px; <?php echo empty($uni['logo_url']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['logo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($uni['logo_url']); ?>" alt="thumb" style="height:38px; width:38px; object-fit:contain; background:#fff; border-radius:6px; padding:2px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Brochure PDF Document</label>
                            <div class="media-input-group">
                                <input type="text" name="brochure_pdf_url" id="field_brochure_pdf" class="form-control" value="<?php echo htmlspecialchars($uni['brochure_pdf_url'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_brochure_pdf" data-preview="preview_brochure" data-type="pdf">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_brochure" style="margin-top:6px; <?php echo empty($uni['brochure_pdf_url']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['brochure_pdf_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($uni['brochure_pdf_url']); ?>" target="_blank" class="badge badge-info" style="text-decoration:none;">PDF Attached &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Desktop Hero Banner Image</label>
                            <div class="media-input-group">
                                <input type="text" name="desktop_banner_bg" id="field_desktop_banner" class="form-control" value="<?php echo htmlspecialchars($uni['desktop_banner_bg'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_desktop_banner" data-preview="preview_desktop_banner" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_desktop_banner" style="margin-top:6px; <?php echo empty($uni['desktop_banner_bg']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['desktop_banner_bg'])): ?>
                                    <img src="<?php echo htmlspecialchars($uni['desktop_banner_bg']); ?>" alt="thumb" style="height:38px; width:70px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Hero Banner Image</label>
                            <div class="media-input-group">
                                <input type="text" name="mobile_banner_bg" id="field_mobile_banner" class="form-control" value="<?php echo htmlspecialchars($uni['mobile_banner_bg'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_mobile_banner" data-preview="preview_mobile_banner" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_mobile_banner" style="margin-top:6px; <?php echo empty($uni['mobile_banner_bg']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['mobile_banner_bg'])): ?>
                                    <img src="<?php echo htmlspecialchars($uni['mobile_banner_bg']); ?>" alt="thumb" style="height:38px; width:70px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Campus Mobile Image</label>
                            <div class="media-input-group">
                                <input type="text" name="campus_mobile_img" id="field_campus_img" class="form-control" value="<?php echo htmlspecialchars($uni['campus_mobile_img'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_campus_img" data-preview="preview_campus_img" data-type="image">
                                    Choose
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_campus_img" style="margin-top:6px; <?php echo empty($uni['campus_mobile_img']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['campus_mobile_img'])): ?>
                                    <img src="<?php echo htmlspecialchars($uni['campus_mobile_img']); ?>" alt="thumb" style="height:34px; width:34px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Podcast Audio (MP3/M4A)</label>
                            <div class="media-input-group">
                                <input type="text" name="podcast_audio_url" id="field_podcast_audio" class="form-control" value="<?php echo htmlspecialchars($uni['podcast_audio_url'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn" data-target="field_podcast_audio" data-preview="preview_podcast_audio" data-type="audio">
                                    Choose
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_podcast_audio" style="margin-top:6px; <?php echo empty($uni['podcast_audio_url']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['podcast_audio_url'])): ?>
                                    <span class="badge badge-info" style="font-size:10px;">Audio Linked</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">YouTube Video Embed URL</label>
                            <input type="text" name="youtube_video_url" class="form-control" value="<?php echo htmlspecialchars($uni['youtube_video_url'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Important Dates -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">3. Important Dates & Deadlines</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Admission Last Date</label>
                            <input type="text" name="admission_last_date" class="form-control" value="<?php echo htmlspecialchars($uni['admission_last_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Exam Date</label>
                            <input type="text" name="exam_date" class="form-control" value="<?php echo htmlspecialchars($uni['exam_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Extended Exam Date</label>
                            <input type="text" name="extended_exam_date" class="form-control" value="<?php echo htmlspecialchars($uni['extended_exam_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Admission Start Date</label>
                            <input type="text" name="admission_start_date" class="form-control" value="<?php echo htmlspecialchars($uni['admission_start_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Assignment Submission Date</label>
                            <input type="text" name="assignment_date" class="form-control" value="<?php echo htmlspecialchars($uni['assignment_date'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form & Lead Integrations Configuration -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <span class="card-title">4. Form & Lead Integrations Configuration</span>
                    <a href="<?php echo BASE_URL; ?>/modules/settings/api_integrations.php" target="_blank" style="font-size:12px; color:var(--primary); text-decoration:none;">
                        Manage Global API Keys &rarr;
                    </a>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Default Source</label>
                            <input type="text" name="cfg_source" class="form-control" value="<?php echo htmlspecialchars($form_cfg['source'] ?? 'MISC'); ?>" placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default UTM Source</label>
                            <input type="text" name="cfg_default_utm_source" class="form-control" value="<?php echo htmlspecialchars($form_cfg['default_utm_source'] ?? 'Organic'); ?>" placeholder="e.g. Organic">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default UTM Medium</label>
                            <input type="text" name="cfg_default_utm_medium" class="form-control" value="<?php echo htmlspecialchars($form_cfg['default_utm_medium'] ?? ($uni['short_name'] . '_Organic')); ?>" placeholder="e.g. DSU_Organic">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Default UTM Campaign</label>
                            <input type="text" name="cfg_default_utm_campaign" class="form-control" value="<?php echo htmlspecialchars($form_cfg['default_utm_campaign'] ?? ($uni['short_name'] . '_Organic')); ?>" placeholder="e.g. DSU_Organic">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Gallabox Source</label>
                            <input type="text" name="cfg_gallabox_source" class="form-control" value="<?php echo htmlspecialchars($form_cfg['gallabox_source'] ?? 'MISC'); ?>" placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Brevo Source</label>
                            <input type="text" name="cfg_brevo_source" class="form-control" value="<?php echo htmlspecialchars($form_cfg['brevo_source'] ?? 'MISC'); ?>" placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Brevo List ID</label>
                            <input type="number" name="cfg_brevo_list_id" class="form-control" value="<?php echo htmlspecialchars($form_cfg['brevo_list_id'] ?? 124); ?>" placeholder="124">
                        </div>
                    </div>

                    <!-- Allowed Courses Checkbox Matrix -->
                    <div class="form-group" style="margin-top:12px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <label class="form-label" style="margin-bottom:0;">Allowed Courses in Form Dropdown</label>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="btn-sm action-btn" onclick="selectAllCourses(true)" style="padding:3px 10px; font-size:11.5px;">Select All</button>
                                <button type="button" class="btn-sm action-btn" onclick="selectAllCourses(false)" style="padding:3px 10px; font-size:11.5px;">Clear All</button>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:10px; padding:14px; background:rgba(255,255,255,0.02); border:1px solid var(--border-color); border-radius:8px;">
                            <?php 
                            $rendered_codes = [];
                            foreach ($all_master_courses as $c): 
                                $c_code = $c['short_name'];
                                $rendered_codes[] = $c_code;
                                $is_chk = in_array($c_code, $selected_courses_arr) || in_array(strtoupper($c_code), array_map('strtoupper', $selected_courses_arr));
                            ?>
                                <label style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:6px; cursor:pointer; font-size:13px; transition:border 0.2s;">
                                    <input type="checkbox" name="allowed_courses[]" value="<?php echo htmlspecialchars($c_code); ?>" class="course-checkbox" <?php echo $is_chk ? 'checked' : ''; ?>>
                                    <span style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($c_code); ?></span>
                                    <span style="font-size:10.5px; color:var(--text-dim); margin-left:auto;"><?php echo htmlspecialchars($c['level']); ?></span>
                                </label>
                            <?php endforeach; ?>

                            <!-- Standard 'Other' Option -->
                            <?php $is_other_chk = in_array('Other', $selected_courses_arr); ?>
                            <label style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:6px; cursor:pointer; font-size:13px;">
                                <input type="checkbox" name="allowed_courses[]" value="Other" class="course-checkbox" <?php echo $is_other_chk ? 'checked' : ''; ?>>
                                <span style="font-weight:600; color:var(--text-main);">Other</span>
                                <span style="font-size:10.5px; color:var(--text-dim); margin-left:auto;">General</span>
                            </label>
                        </div>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:6px; display:block;">Select the courses that should appear in this university's lead capture form dropdown.</span>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function selectAllCourses(check) {
            document.querySelectorAll('.course-checkbox').forEach(cb => cb.checked = check);
        }
        </script>

        <!-- Right Column -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Publish & Status</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:600;">
                            <input type="checkbox" name="is_active" value="1" <?php echo $uni['is_active'] ? 'checked' : ''; ?>>
                            Active & Published
                        </label>
                    </div>

                    <button type="submit" class="btn-primary" style="padding:13px 20px;">
                        Update University
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
                        <p style="font-size:12px; color:var(--text-dim);">No accreditations created yet.</p>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($all_accreditations as $acc): ?>
                                <?php $is_checked = in_array($acc['id'], $assigned_accs); ?>
                                <label style="display:flex; align-items:center; gap:10px; font-size:13px; cursor:pointer;">
                                    <input type="checkbox" name="accreditations[]" value="<?php echo $acc['id']; ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
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

    // In edit mode, if slug is already filled, assume manual unless user empties it
    let isSlugManuallyEdited = slugInput.value.trim() !== '';

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
