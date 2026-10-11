<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('courses');
require_action_permission('update', 'courses');

$db = get_db_connection();

$course_id = (int)($_GET['id'] ?? $_GET['edit_id'] ?? 0);
if (!$course_id) {
    set_flash_message('Invalid course ID.', 'error');
    redirect(BASE_URL . '/modules/courses/index.php');
}

$stmt = $db->prepare("SELECT * FROM courses WHERE id = ?");
$stmt->execute([$course_id]);
$course = $stmt->fetch();

if (!$course) {
    set_flash_message('Course not found.', 'error');
    redirect(BASE_URL . '/modules/courses/index.php');
}

$page_title = 'Edit Course';
$page_subtitle = 'Update degree details for ' . htmlspecialchars($course['full_name']);
$active_page_key = 'courses';

// Fetch dynamic active degree levels
$master_degree_levels = $db->query("SELECT * FROM degree_levels_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// Fetch mapping statistics
$cnt_stmt = $db->prepare("SELECT COUNT(*) FROM university_course_mappings WHERE course_id = ?");
$cnt_stmt->execute([$course_id]);
$uni_mappings_count = (int)$cnt_stmt->fetchColumn();

// Fetch job roles count
$roles_count = 0;
$cjr_stmt = $db->prepare("SELECT roles_json FROM course_job_roles WHERE LOWER(course_slug) = LOWER(?) OR LOWER(course_slug) = LOWER(?) LIMIT 1");
$cjr_stmt->execute([$course['slug'], $course['short_name']]);
$cjr = $cjr_stmt->fetch();
if ($cjr && !empty($cjr['roles_json'])) {
    $roles_arr = json_decode($cjr['roles_json'], true);
    if (is_array($roles_arr)) {
        $roles_count = count($roles_arr);
    }
}

$errors = [];
$full_name = $course['full_name'];
$short_name = $course['short_name'];
$slug = $course['slug'];
$level = $course['level'];
$description = $course['description'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full_name = trim($_POST['full_name'] ?? '');
    $short_name = trim($_POST['short_name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $short_name ?: $full_name)));
    }
    $level = trim($_POST['level'] ?? 'PG');
    $description = trim($_POST['description'] ?? '');

    if (empty($full_name)) {
        $errors[] = 'Full Degree Name is required.';
    }
    if (empty($short_name)) {
        $errors[] = 'Short Name is required.';
    }
    if (empty($slug)) {
        $errors[] = 'Slug is required.';
    }
    if (empty($level)) {
        $errors[] = 'Degree Level is required.';
    }

    if (empty($errors)) {
        // Check for duplicate slug on other courses
        $check_slug = $db->prepare("SELECT id FROM courses WHERE slug = ? AND id != ?");
        $check_slug->execute([$slug, $course_id]);
        if ($check_slug->fetch()) {
            $errors[] = "A different course with slug '{$slug}' already exists. Please choose a different slug.";
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE courses 
                    SET full_name = ?, short_name = ?, slug = ?, level = ?, description = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$full_name, $short_name, $slug, $level, $description, $course_id]);

                if (function_exists('log_activity')) {
                    log_activity('UPDATE', 'courses', "Updated Course '{$full_name}' ({$short_name})", [
                        'item_type' => 'Course',
                        'item_id' => $course_id,
                        'item_title' => $full_name,
                        'old_values' => $course,
                        'new_values' => ['full_name' => $full_name, 'short_name' => $short_name, 'slug' => $slug, 'level' => $level, 'description' => $description]
                    ]);
                }

                set_flash_message("Course '{$full_name}' updated successfully!", 'success');
                redirect(BASE_URL . '/modules/courses/index.php');
            } catch (PDOException $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    if (!empty($errors)) {
        set_flash_message(implode('<br>', $errors), 'error');
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:2px;">Edit Course: <?php echo htmlspecialchars($course['short_name']); ?></span>
        <div style="font-size:12px; color:var(--text-dim);">Modify degree program title, slug, level tier, or descriptive syllabus overview</div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="btn-secondary btn-sm" style="text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
            &larr; Back to All Courses
        </a>
    </div>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
        <!-- Left Column: Primary Details -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">1. Course Identity</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Full Degree Name *</label>
                        <input type="text" name="full_name" id="field_course_full_name" class="form-control" value="<?php echo htmlspecialchars($full_name); ?>" placeholder="e.g. Master of Business Administration" required>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Full academic degree title shown to students and university selectors</span>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Short Name / Degree Code *</label>
                            <input type="text" name="short_name" id="field_course_short_name" class="form-control" value="<?php echo htmlspecialchars($short_name); ?>" placeholder="e.g. MBA" required>
                            <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Standard short code (e.g. MBA, MCA, BBA)</span>
                        </div>

                        <div class="form-group">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label class="form-label" style="margin-bottom:0;">Degree Level *</label>
                                <a href="<?php echo BASE_URL; ?>/modules/settings/levels.php" style="font-size:11.5px; color:var(--primary); text-decoration:none; font-weight:600;" target="_blank">+ Manage Levels</a>
                            </div>
                            <select name="level" class="form-select" required>
                                <option value="">-- Select Degree Level --</option>
                                <?php 
                                $found_lvl = false;
                                foreach ($master_degree_levels as $lvl): 
                                    $val = $lvl['level_code'];
                                    $is_sel = ($level === $lvl['level_code'] || $level === $lvl['level_name']);
                                    if ($is_sel) $found_lvl = true;
                                ?>
                                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($lvl['level_name']); ?> (<?php echo htmlspecialchars($lvl['level_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!empty($level) && !$found_lvl): ?>
                                    <option value="<?php echo htmlspecialchars($level); ?>" selected>
                                        <?php echo htmlspecialchars($level); ?> (Legacy)
                                    </option>
                                <?php endif; ?>
                            </select>
                            <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Academic tier categorization</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Slug (URL & Subdomain Identifier) *</label>
                        <input type="text" name="slug" id="field_course_slug" class="form-control" value="<?php echo htmlspecialchars($slug); ?>" placeholder="e.g. mba" required>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Changing slug will update associated routes and landing URL identifiers</span>
                    </div>
                </div>
            </div>

            <!-- Description Card -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">2. Academic Overview & Description</span>
                </div>
                <div class="card-body">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Course Description</label>
                        <textarea name="description" class="form-textarea" rows="6" placeholder="Brief overview of this degree course..."><?php echo htmlspecialchars($description); ?></textarea>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Program highlights and overview displayed to candidate inquiries</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Action & Info Sidecard -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Save Changes</span>
                </div>
                <div class="card-body">
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <button type="submit" class="btn-primary" style="width:100%; padding:12px; font-weight:600; font-size:13.5px; display:flex; align-items:center; justify-content:center; gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Save Course Updates
                        </button>
                        <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="btn-sm action-btn" style="width:100%; text-align:center; padding:10px 14px; text-decoration:none; font-weight:600; display:block;">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>

            <!-- Linked Modules Card -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Course Ecosystem</span>
                </div>
                <div class="card-body" style="font-size:12.5px; display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.06);">
                        <span style="color:var(--text-muted);">Mapped Universities:</span>
                        <a href="<?php echo BASE_URL; ?>/modules/course_universities/index.php?course_id=<?php echo $course_id; ?>" class="badge badge-warning" style="text-decoration:none;">
                            <?php echo $uni_mappings_count; ?> Universities &rarr;
                        </a>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.06);">
                        <span style="color:var(--text-muted);">Configured Job Roles:</span>
                        <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php?course_id=<?php echo $course_id; ?>" class="badge badge-success" style="text-decoration:none;">
                            <?php echo $roles_count; ?> Roles &rarr;
                        </a>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="color:var(--text-muted);">Syllabus Subjects:</span>
                        <a href="<?php echo BASE_URL; ?>/modules/syllabus/index.php?course_id=<?php echo $course_id; ?>" class="badge badge-info" style="text-decoration:none;">
                            Manage Syllabus &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const shortNameInput = document.getElementById('field_course_short_name');
    const slugInput = document.getElementById('field_course_slug');
    
    // In edit mode, we only alter slug if user explicitly empties it
    if (slugInput) {
        slugInput.addEventListener('blur', function() {
            if (this.value.trim() === '' && shortNameInput && shortNameInput.value.trim() !== '') {
                this.value = shortNameInput.value
                    .toString()
                    .toLowerCase()
                    .trim()
                    .replace(/&/g, '-and-')
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
            }
        });
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
