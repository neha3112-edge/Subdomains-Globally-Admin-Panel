<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');

$page_title = 'Edit University';
$page_subtitle = 'Update university profile and settings';
$active_page_key = 'universities';

$db = get_db_connection();

$id = (int) ($_GET['id'] ?? 0);
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

// Fetch dynamic active education modes
$active_modes = $db->query("SELECT mode_name FROM education_modes_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($active_modes)) {
    $active_modes = ['Online & Distance', 'Online', 'Distance'];
}

// Handle University News Actions (Save, Delete, Toggle Active)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_action = $_POST['action'] ?? 'update_university';

    if ($post_action === 'save_university_news') {
        $news_id = !empty($_POST['news_id']) ? (int) $_POST['news_id'] : null;
        $news_text = trim($_POST['news_text'] ?? '');
        $news_link = trim($_POST['news_link'] ?? '');
        $has_badge = isset($_POST['has_badge']) ? 1 : 0;
        $badge_text = trim($_POST['badge_text'] ?? 'New');
        if (empty($badge_text))
            $badge_text = 'New';
        $sort_order = (int) ($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($news_text)) {
            set_flash_message('News text is required.', 'error');
        } else {
            if ($news_id) {
                $stmt = $db->prepare("
                    UPDATE news_items 
                    SET news_text = ?, news_link = ?, has_badge = ?, badge_text = ?, sort_order = ?, is_active = ?, is_global = 0, university_id = ?
                    WHERE id = ? AND university_id = ?
                ");
                $stmt->execute([$news_text, $news_link, $has_badge, $badge_text, $sort_order, $is_active, $id, $news_id, $id]);
                set_flash_message('University news updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO news_items (is_global, university_id, news_text, news_link, has_badge, badge_text, sort_order, is_active)
                    VALUES (0, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id, $news_text, $news_link, $has_badge, $badge_text, $sort_order, $is_active]);
                set_flash_message('University news added successfully!', 'success');
            }

            sode_bust_all_subdomain_caches($db);
            redirect(BASE_URL . '/modules/universities/edit.php?id=' . $id . '#uni-news-section');
        }
    } elseif ($post_action === 'delete_university_news') {
        $news_id = (int) ($_POST['news_id'] ?? 0);
        if ($news_id) {
            $stmt = $db->prepare("DELETE FROM news_items WHERE id = ? AND university_id = ?");
            $stmt->execute([$news_id, $id]);
            sode_bust_all_subdomain_caches($db);
            set_flash_message('University news deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/universities/edit.php?id=' . $id . '#uni-news-section');
        }
    } elseif ($post_action === 'toggle_university_news') {
        $news_id = (int) ($_POST['news_id'] ?? 0);
        if ($news_id) {
            $stmt = $db->prepare("UPDATE news_items SET is_active = IF(is_active=1, 0, 1) WHERE id = ? AND university_id = ?");
            $stmt->execute([$news_id, $id]);
            sode_bust_all_subdomain_caches($db);
            set_flash_message('News status updated!', 'success');
            redirect(BASE_URL . '/modules/universities/edit.php?id=' . $id . '#uni-news-section');
        }
    }
}

// Fetch all news items for this university
$uni_news_stmt = $db->prepare("SELECT * FROM news_items WHERE is_global = 0 AND university_id = ? ORDER BY sort_order ASC, id DESC");
$uni_news_stmt->execute([$id]);
$uni_news_list = $uni_news_stmt->fetchAll();
$uni_news_count = count($uni_news_list);

// Check if editing a specific news item
$edit_uni_news = null;
if (isset($_GET['edit_news_id'])) {
    $edit_nid = (int) $_GET['edit_news_id'];
    $stmt = $db->prepare("SELECT * FROM news_items WHERE id = ? AND university_id = ?");
    $stmt->execute([$edit_nid, $id]);
    $edit_uni_news = $stmt->fetch();
}

// Fetch global news count
$global_news_count = (int) $db->query("SELECT COUNT(*) FROM news_items WHERE is_global = 1 AND is_active = 1")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_university') {
    verify_csrf();

    $full_name = trim($_POST['full_name'] ?? '');
    $short_name = trim($_POST['short_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $mode = trim($_POST['mode'] ?? 'Online & Distance');
    $location = trim($_POST['location'] ?? '');
    $official_url = trim($_POST['official_url'] ?? '');
    $advantage_text = trim($_POST['advantage_text'] ?? '');
    $logo_url = get_relative_asset_path(trim($_POST['logo_url'] ?? ''));
    $desktop_banner_bg = get_relative_asset_path(trim($_POST['desktop_banner_bg'] ?? ''));
    $mobile_banner_bg = get_relative_asset_path(trim($_POST['mobile_banner_bg'] ?? ''));
    $campus_mobile_img = get_relative_asset_path(trim($_POST['campus_mobile_img'] ?? ''));
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
    $rating = (float) ($_POST['rating'] ?? 4.0);
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
                    brochure_pdf_url = ?, podcast_audio_url = ?, youtube_video_url = ?, whatsapp_btn_intent = ?, gallabox_message_text = ?,
                    exam_date = ?, extended_exam_date = ?, admission_last_date = ?, admission_start_date = ?, assignment_date = ?,
                    rating = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $full_name,
                $short_name,
                $slug,
                $mode,
                $location,
                $official_url,
                $advantage_text,
                $logo_url,
                $desktop_banner_bg,
                $mobile_banner_bg,
                $campus_mobile_img,
                $brochure_pdf_url,
                $podcast_audio_url,
                $youtube_video_url,
                $whatsapp_btn_intent,
                $gallabox_message_text,
                $exam_date,
                $extended_exam_date,
                $admission_last_date,
                $admission_start_date,
                $assignment_date,
                $rating,
                $is_active,
                $id
            ]);

            // Sync Accreditations
            $db->prepare("DELETE FROM university_accreditations WHERE university_id = ?")->execute([$id]);
            if (!empty($selected_accreditations)) {
                $acc_stmt = $db->prepare("INSERT INTO university_accreditations (university_id, accreditation_id) VALUES (?, ?)");
                foreach ($selected_accreditations as $acc_id) {
                    $acc_stmt->execute([$id, (int) $acc_id]);
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
            $cfg_brevo_list_id = (int) ($_POST['cfg_brevo_list_id'] ?? 124);
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
                $id,
                $cfg_source,
                $cfg_default_utm_source,
                $cfg_default_utm_medium,
                $cfg_default_utm_campaign,
                $cfg_gallabox_source,
                $cfg_brevo_source,
                $cfg_brevo_list_id,
                $cfg_allowed_courses
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

            // Add any master courses that weren't in the saved JSON
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
            // Old comma-separated string format or initial defaults
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

// Fetch Master Courses and configured courses
$all_master_courses = $db->query("SELECT * FROM courses ORDER BY level ASC, short_name ASC")->fetchAll();
$configured_courses = sode_get_configured_course_items($form_cfg['allowed_courses_json'] ?? '', $all_master_courses);

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <span class="section-heading-sm" style="margin-bottom:0;">Edit University:
        <?php echo htmlspecialchars($uni['short_name']); ?></span>
    <div style="display:flex; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn-sm action-btn"
            style="width:auto; padding:6px 14px; text-decoration:none;">&larr; Back to List</a>
    </div>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="update_university">

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
                            <input type="text" name="full_name" id="field_full_name" class="form-control"
                                value="<?php echo htmlspecialchars($uni['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Short Name *</label>
                            <input type="text" name="short_name" id="field_short_name" class="form-control"
                                value="<?php echo htmlspecialchars($uni['short_name']); ?>" required>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Slug (Subdomain Identifier) *</label>
                            <input type="text" name="slug" id="field_slug" class="form-control"
                                value="<?php echo htmlspecialchars($uni['slug']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Education Mode</label>
                            <select name="mode" class="form-select">
                                <?php 
                                $uni_mode = $uni['mode'] ?? '';
                                $found_mode = false;
                                foreach ($active_modes as $m_opt): 
                                    $is_sel = ($uni_mode === $m_opt);
                                    if ($is_sel) $found_mode = true;
                                ?>
                                    <option value="<?php echo htmlspecialchars($m_opt); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m_opt); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!empty($uni_mode) && !$found_mode): ?>
                                    <option value="<?php echo htmlspecialchars($uni_mode); ?>" selected>
                                        <?php echo htmlspecialchars($uni_mode); ?> (Existing)
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Rating (Out of 5.0)</label>
                            <input type="number" step="0.1" min="1" max="5" name="rating" class="form-control"
                                value="<?php echo htmlspecialchars($uni['rating']); ?>">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Location / State</label>
                            <input type="text" name="location" class="form-control"
                                value="<?php echo htmlspecialchars($uni['location'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Official Portal URL</label>
                            <input type="url" name="official_url" class="form-control"
                                value="<?php echo htmlspecialchars($uni['official_url'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Advantage / Key Highlights</label>
                        <textarea name="advantage_text"
                            class="form-textarea"><?php echo htmlspecialchars($uni['advantage_text'] ?? ''); ?></textarea>
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
                                <input type="text" name="logo_url" id="field_logo_url" class="form-control"
                                    value="<?php echo htmlspecialchars($uni['logo_url'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn"
                                    data-target="field_logo_url" data-preview="preview_logo" data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_logo"
                                style="margin-top:6px; <?php echo empty($uni['logo_url']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['logo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars(get_asset_url($uni['logo_url'])); ?>" alt="thumb"
                                        style="height:38px; width:38px; object-fit:contain; background:#fff; border-radius:6px; padding:2px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Brochure PDF Document</label>
                            <div class="media-input-group">
                                <input type="text" name="brochure_pdf_url" id="field_brochure_pdf" class="form-control"
                                    value="<?php echo htmlspecialchars($uni['brochure_pdf_url'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn"
                                    data-target="field_brochure_pdf" data-preview="preview_brochure" data-type="pdf">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_brochure"
                                style="margin-top:6px; <?php echo empty($uni['brochure_pdf_url']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['brochure_pdf_url'])): ?>
                                    <a href="<?php echo htmlspecialchars(get_asset_url($uni['brochure_pdf_url'])); ?>"
                                        target="_blank" class="badge badge-info" style="text-decoration:none;">PDF Attached
                                        &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Desktop Hero Banner Image</label>
                            <div class="media-input-group">
                                <input type="text" name="desktop_banner_bg" id="field_desktop_banner"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($uni['desktop_banner_bg'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn"
                                    data-target="field_desktop_banner" data-preview="preview_desktop_banner"
                                    data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_desktop_banner"
                                style="margin-top:6px; <?php echo empty($uni['desktop_banner_bg']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['desktop_banner_bg'])): ?>
                                    <img src="<?php echo htmlspecialchars(get_asset_url($uni['desktop_banner_bg'])); ?>"
                                        alt="thumb"
                                        style="height:38px; width:70px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mobile Hero Banner Image</label>
                            <div class="media-input-group">
                                <input type="text" name="mobile_banner_bg" id="field_mobile_banner" class="form-control"
                                    value="<?php echo htmlspecialchars($uni['mobile_banner_bg'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn"
                                    data-target="field_mobile_banner" data-preview="preview_mobile_banner"
                                    data-type="image">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                    Choose / Upload
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_mobile_banner"
                                style="margin-top:6px; <?php echo empty($uni['mobile_banner_bg']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['mobile_banner_bg'])): ?>
                                    <img src="<?php echo htmlspecialchars(get_asset_url($uni['mobile_banner_bg'])); ?>"
                                        alt="thumb"
                                        style="height:38px; width:70px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Campus Mobile Image</label>
                            <div class="media-input-group">
                                <input type="text" name="campus_mobile_img" id="field_campus_img" class="form-control"
                                    value="<?php echo htmlspecialchars($uni['campus_mobile_img'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn"
                                    data-target="field_campus_img" data-preview="preview_campus_img" data-type="image">
                                    Choose
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_campus_img"
                                style="margin-top:6px; <?php echo empty($uni['campus_mobile_img']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['campus_mobile_img'])): ?>
                                    <img src="<?php echo htmlspecialchars(get_asset_url($uni['campus_mobile_img'])); ?>"
                                        alt="thumb"
                                        style="height:34px; width:34px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Podcast Audio (MP3/M4A)</label>
                            <div class="media-input-group">
                                <input type="text" name="podcast_audio_url" id="field_podcast_audio"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($uni['podcast_audio_url'] ?? ''); ?>">
                                <button type="button" class="btn-media-choose media-picker-btn"
                                    data-target="field_podcast_audio" data-preview="preview_podcast_audio"
                                    data-type="audio">
                                    Choose
                                </button>
                            </div>
                            <div class="media-preview-inline" id="preview_podcast_audio"
                                style="margin-top:6px; <?php echo empty($uni['podcast_audio_url']) ? 'display:none;' : ''; ?>">
                                <?php if (!empty($uni['podcast_audio_url'])): ?>
                                    <span class="badge badge-info" style="font-size:10px;">Audio Linked</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">YouTube Video Embed URL</label>
                            <input type="text" name="youtube_video_url" class="form-control"
                                value="<?php echo htmlspecialchars($uni['youtube_video_url'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">WhatsApp Brochure Button Intent / Message</label>
                        <input type="text" name="whatsapp_btn_intent" class="form-control"
                            value="<?php echo htmlspecialchars($uni['whatsapp_btn_intent'] ?? ''); ?>"
                            placeholder="I want to Download {UNIVERSITY_NAME} {MODE} Brochure">
                        <small style="color:var(--text-muted); font-size:12px; margin-top:4px; display:block;">
                            Placeholders available: <code>{UNIVERSITY_NAME}</code>, <code>{UNI}</code>,
                            <code>{MODE}</code>. If left empty, default message will be used.
                        </small>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label">Gallabox WhatsApp Widget Message Text (messageText)</label>
                        <input type="text" name="gallabox_message_text" class="form-control"
                            value="<?php echo htmlspecialchars($uni['gallabox_message_text'] ?? ''); ?>"
                            placeholder="Start Your {UNIVERSITY_NAME} {MODE} Counseling with an Expert Now">
                        <small style="color:var(--text-muted); font-size:12px; margin-top:4px; display:block;">
                            WhatsApp prefilled message for Gallabox widget. Placeholders available: <code>{UNIVERSITY_NAME}</code>, <code>{UNI}</code>, <code>{MODE}</code>. If left empty, global default message text will be used.
                        </small>
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
                            <input type="text" name="admission_last_date" class="form-control"
                                value="<?php echo htmlspecialchars($uni['admission_last_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Exam Date</label>
                            <input type="text" name="exam_date" class="form-control"
                                value="<?php echo htmlspecialchars($uni['exam_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Extended Exam Date</label>
                            <input type="text" name="extended_exam_date" class="form-control"
                                value="<?php echo htmlspecialchars($uni['extended_exam_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Admission Start Date</label>
                            <input type="text" name="admission_start_date" class="form-control"
                                value="<?php echo htmlspecialchars($uni['admission_start_date'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Assignment Submission Date</label>
                            <input type="text" name="assignment_date" class="form-control"
                                value="<?php echo htmlspecialchars($uni['assignment_date'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form & Lead Integrations Configuration -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <span class="card-title">4. Form & Lead Integrations Configuration</span>
                    <a href="<?php echo BASE_URL; ?>/modules/settings/api_integrations.php" target="_blank"
                        style="font-size:12px; color:var(--primary); text-decoration:none;">
                        Manage Global API Keys &rarr;
                    </a>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Default Source</label>
                            <input type="text" name="cfg_source" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['source'] ?? 'MISC'); ?>"
                                placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default UTM Source</label>
                            <input type="text" name="cfg_default_utm_source" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['default_utm_source'] ?? 'Organic'); ?>"
                                placeholder="e.g. Organic">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Default UTM Medium</label>
                            <input type="text" name="cfg_default_utm_medium" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['default_utm_medium'] ?? ($uni['short_name'] . '_Organic')); ?>"
                                placeholder="e.g. DSU_Organic">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Default UTM Campaign</label>
                            <input type="text" name="cfg_default_utm_campaign" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['default_utm_campaign'] ?? ($uni['short_name'] . '_Organic')); ?>"
                                placeholder="e.g. DSU_Organic">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Gallabox Source</label>
                            <input type="text" name="cfg_gallabox_source" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['gallabox_source'] ?? 'MISC'); ?>"
                                placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Brevo Source</label>
                            <input type="text" name="cfg_brevo_source" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['brevo_source'] ?? 'MISC'); ?>"
                                placeholder="e.g. MISC">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Brevo List ID</label>
                            <input type="number" name="cfg_brevo_list_id" class="form-control"
                                value="<?php echo htmlspecialchars($form_cfg['brevo_list_id'] ?? 124); ?>"
                                placeholder="124">
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

        <!-- Right Column -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Publish & Status</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label
                            style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:600;">
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

            <!-- University News Summary Card -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">News & Marquee Status</span>
                </div>
                <div class="card-body">
                    <div style="font-size:13px; color:var(--text-dim); margin-bottom:12px;">
                        This university currently displays <strong><?php echo $uni_news_count; ?> specific</strong> and
                        <strong><?php echo $global_news_count; ?> universal</strong> announcements in the scrolling
                        marquee.
                    </div>
                    <a href="#uni-news-section" class="btn-secondary"
                        style="display:block; text-align:center; text-decoration:none; font-size:13px; padding:8px 12px; border-radius:6px;">
                        📢 Jump to News Section Below &darr;
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- ==================================================== -->
<!-- 6. UNIVERSITY-SPECIFIC NEWS & MARQUEE ANNOUNCEMENTS  -->
<!-- ==================================================== -->
<div class="admin-card" id="uni-news-section" style="margin-top: 28px;">
    <div class="card-header"
        style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <span class="card-title">6. University-Specific News & Marquee
                (<?php echo htmlspecialchars($uni['short_name']); ?>)</span>
            <div style="font-size:12px; color:var(--text-dim); margin-top:2px;">
                Announcements added here will display specifically alongside universal news.
            </div>
        </div>
        <span class="badge badge-info"><?php echo $uni_news_count; ?> Specific Announcements</span>
    </div>

    <div class="card-body">
        <div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 24px;">
            <!-- Left: Add / Edit University News Form -->
            <div
                style="background: var(--bg-card); padding: 18px; border: 1px solid var(--border-color); border-radius: 8px;">
                <h4 style="margin: 0 0 14px 0; font-size: 14.5px; font-weight: 600; color: var(--text-color);">
                    <?php echo $edit_uni_news ? 'Edit Announcement' : 'Add Announcement for ' . htmlspecialchars($uni['short_name']); ?>
                </h4>

                <form method="POST" action="#uni-news-section">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save_university_news">
                    <input type="hidden" name="news_id" value="<?php echo $edit_uni_news['id'] ?? ''; ?>">

                    <div class="form-group">
                        <label class="form-label">Announcement Text *</label>
                        <textarea name="news_text" id="field_uni_news_text" class="form-textarea" rows="3"
                            placeholder="e.g. For the latest notifications regarding student support, access the <?php echo htmlspecialchars($uni['short_name']); ?> Student Support Page."
                            required><?php echo htmlspecialchars($edit_uni_news['news_text'] ?? ''); ?></textarea>

                        <div style="margin-top: 6px; font-size: 11.5px; color: var(--text-dim);">
                            <span>Tags:</span>
                            <button type="button" class="btn-xs"
                                style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:3px; padding:1px 5px; font-size:10.5px; cursor:pointer;"
                                onclick="insertUniTag('{UNIVERSITY_NAME}')">{UNIVERSITY_NAME}</button>
                            <button type="button" class="btn-xs"
                                style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:3px; padding:1px 5px; font-size:10.5px; cursor:pointer;"
                                onclick="insertUniTag('{UNIVERSITY_SHORT_NAME}')">{UNIVERSITY_SHORT_NAME}</button>
                            <button type="button" class="btn-xs"
                                style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:3px; padding:1px 5px; font-size:10.5px; cursor:pointer;"
                                onclick="insertUniTag('$YEAR$')">$YEAR$</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Link URL (Optional)</label>
                        <input type="text" name="news_link" class="form-control"
                            value="<?php echo htmlspecialchars($edit_uni_news['news_link'] ?? ''); ?>"
                            placeholder="https://... or #">
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label class="form-label">Badge</label>
                            <label
                                style="display:flex; align-items:center; gap:6px; margin-top:6px; cursor:pointer; font-size:13px;">
                                <input type="checkbox" name="has_badge" value="1" <?php echo (!$edit_uni_news || !empty($edit_uni_news['has_badge'])) ? 'checked' : ''; ?>>
                                <span>Show Badge</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Badge Text</label>
                            <input type="text" name="badge_text" class="form-control"
                                value="<?php echo htmlspecialchars($edit_uni_news['badge_text'] ?? 'New'); ?>"
                                placeholder="New">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control"
                                value="<?php echo htmlspecialchars($edit_uni_news['sort_order'] ?? 0); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <label
                                style="display:flex; align-items:center; gap:6px; margin-top:6px; cursor:pointer; font-size:13px;">
                                <input type="checkbox" name="is_active" value="1" <?php echo (!$edit_uni_news || !empty($edit_uni_news['is_active'])) ? 'checked' : ''; ?>>
                                <span>Active</span>
                            </label>
                        </div>
                    </div>

                    <div style="margin-top: 14px; display:flex; gap:8px;">
                        <button type="submit" class="btn-primary" style="padding: 8px 16px; font-size: 13px;">
                            <?php echo $edit_uni_news ? 'Update Announcement' : 'Add Announcement'; ?>
                        </button>
                        <?php if ($edit_uni_news): ?>
                            <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $id; ?>#uni-news-section"
                                class="btn-secondary"
                                style="text-decoration:none; padding:8px 14px; font-size:13px;">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Right: Table of University News -->
            <div>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Announcement</th>
                                <th>Badge</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($uni_news_list)): ?>
                                <tr>
                                    <td colspan="5" style="text-align:center; padding:30px; color:var(--text-dim);">
                                        No specific news added for <?php echo htmlspecialchars($uni['short_name']); ?>
                                        yet.<br>
                                        <small>(Universal announcements from Settings &rarr; Universal News will still
                                            display on the website)</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($uni_news_list as $un): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 500; font-size: 13px;">
                                                <?php echo htmlspecialchars($un['news_text']); ?>
                                            </div>
                                            <?php if (!empty($un['news_link'])): ?>
                                                <div style="font-size: 11px; color: var(--accent-color); margin-top: 2px;">
                                                    <a href="<?php echo htmlspecialchars($un['news_link']); ?>" target="_blank"
                                                        style="color: inherit; text-decoration: underline;">
                                                        <?php echo htmlspecialchars(substr($un['news_link'], 0, 35)); ?>            <?php echo strlen($un['news_link']) > 35 ? '...' : ''; ?>
                                                        ↗
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($un['has_badge'])): ?>
                                                <span
                                                    style="background: #f3b23e; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                                    <?php echo htmlspecialchars($un['badge_text'] ?: 'New'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--text-dim); font-size: 11px;">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge"
                                                style="background: var(--bg-input); color: var(--text-color); font-size: 11px;">
                                                <?php echo (int) $un['sort_order']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" action="#uni-news-section" style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="toggle_university_news">
                                                <input type="hidden" name="news_id" value="<?php echo $un['id']; ?>">
                                                <button type="submit"
                                                    style="background:none; border:none; cursor:pointer; padding:0;">
                                                    <?php if (!empty($un['is_active'])): ?>
                                                        <span class="badge badge-success" style="cursor:pointer;"
                                                            title="Click to disable">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-danger" style="cursor:pointer;"
                                                            title="Click to activate">Inactive</span>
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $id; ?>&edit_news_id=<?php echo $un['id']; ?>#uni-news-section"
                                                    class="action-btn" title="Edit">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7">
                                                        </path>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z">
                                                        </path>
                                                    </svg>
                                                </a>

                                                <form method="POST" action="#uni-news-section" class="confirm-delete"
                                                    style="display:inline;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete_university_news">
                                                    <input type="hidden" name="news_id" value="<?php echo $un['id']; ?>">
                                                    <button type="submit" class="action-btn delete-btn" title="Delete">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                            stroke="currentColor" stroke-width="2">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path
                                                                d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                            </path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            </div>
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

<script>
    function insertUniTag(tag) {
        var textarea = document.getElementById('field_uni_news_text');
        if (!textarea) return;
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var text = textarea.value;
        textarea.value = text.substring(0, start) + tag + text.substring(end);
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + tag.length;
    }

    document.addEventListener('DOMContentLoaded', function () {
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
            shortNameInput.addEventListener('input', function () {
                if (!fullNameInput || fullNameInput.value.trim() === '') {
                    syncSlug();
                }
            });
        }

        slugInput.addEventListener('input', function () {
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