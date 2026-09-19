<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('courses');
require_action_permission('create', 'courses');

$page_title = 'Add New Course';
$page_subtitle = 'Create a new academic degree program and configure degree level';
$active_page_key = 'courses';

$db = get_db_connection();

// Fetch dynamic active degree levels
$master_degree_levels = $db->query("SELECT * FROM degree_levels_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

$errors = [];
$full_name = '';
$short_name = '';
$slug = '';
$level = 'PG';
$description = '';

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
        // Check for duplicate slug
        $check_slug = $db->prepare("SELECT id FROM courses WHERE slug = ?");
        $check_slug->execute([$slug]);
        if ($check_slug->fetch()) {
            $errors[] = "A course with slug '{$slug}' already exists. Please choose a different slug.";
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO courses (full_name, short_name, slug, level, description) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$full_name, $short_name, $slug, $level, $description]);
                $new_id = $db->lastInsertId();

                if (function_exists('log_activity')) {
                    log_activity('CREATE', 'courses', "Created new Course '{$full_name}' ({$short_name})", [
                        'item_type' => 'Course',
                        'item_id' => $new_id,
                        'item_title' => $full_name,
                        'new_values' => ['full_name' => $full_name, 'short_name' => $short_name, 'slug' => $slug, 'level' => $level]
                    ]);
                }

                set_flash_message("Course '{$full_name}' ({$short_name}) created successfully!", 'success');
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
        <span class="section-heading-sm" style="margin-bottom:2px;">Course Details</span>
        <div style="font-size:12px; color:var(--text-dim);">Fill in the program details to add a new course to the universal catalog</div>
    </div>
    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="btn-sm action-btn" style="width:auto; padding:7px 16px; text-decoration:none; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
        &larr; Back to All Courses
    </a>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
        <!-- Left: Primary Details -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">1. Course Identity</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Full Degree Name *</label>
                        <input type="text" name="full_name" id="field_course_full_name" class="form-control" value="<?php echo htmlspecialchars($full_name); ?>" placeholder="e.g. Master of Business Administration" required autofocus>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Full academic program title displayed across candidate landing pages</span>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Short Name / Degree Code *</label>
                            <input type="text" name="short_name" id="field_course_short_name" class="form-control" value="<?php echo htmlspecialchars($short_name); ?>" placeholder="e.g. MBA" required>
                            <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Short abbreviation (e.g. MBA, MCA, BBA, B.Com)</span>
                        </div>

                        <div class="form-group">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label class="form-label" style="margin-bottom:0;">Degree Level *</label>
                                <a href="<?php echo BASE_URL; ?>/modules/settings/levels.php" style="font-size:11.5px; color:var(--primary); text-decoration:none; font-weight:600;" target="_blank">+ Manage Levels</a>
                            </div>
                            <select name="level" class="form-select" required>
                                <option value="">-- Select Degree Level --</option>
                                <?php foreach ($master_degree_levels as $lvl): 
                                    $val = $lvl['level_code'];
                                    $is_sel = ($level === $lvl['level_code'] || $level === $lvl['level_name']);
                                ?>
                                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($lvl['level_name']); ?> (<?php echo htmlspecialchars($lvl['level_code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Academic tier (Undergraduate, Postgraduate, Diploma, etc.)</span>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Slug (URL & Subdomain Identifier) *</label>
                        <input type="text" name="slug" id="field_course_slug" class="form-control" value="<?php echo htmlspecialchars($slug); ?>" placeholder="Auto-generated from degree name (e.g. mba)" required>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Unique system slug used for routing and API mappings</span>
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
                        <textarea name="description" class="form-textarea" rows="6" placeholder="Provide an engaging overview of this degree course, academic career scope, and program curriculum highlights..."><?php echo htmlspecialchars($description); ?></textarea>
                        <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">This description is displayed on program landing pages and candidate inquiry modals</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Action & Info Sidecard -->
        <div style="display:flex; flex-direction:column; gap:20px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Publish Actions</span>
                </div>
                <div class="card-body">
                    <p style="font-size:12.5px; color:var(--text-dim); line-height:1.5; margin-bottom:16px;">
                        Once created, this course will instantly be available for university mappings, fee structures, syllabus subjects, and career job roles.
                    </p>

                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <button type="submit" class="btn-primary" style="width:100%; padding:12px; font-weight:600; font-size:13.5px; display:flex; align-items:center; justify-content:center; gap:8px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Create Course Record
                        </button>
                        <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="btn-sm action-btn" style="width:100%; text-align:center; padding:10px 14px; text-decoration:none; font-weight:600; display:block;">
                            Cancel & Discard
                        </a>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Next Steps After Creation</span>
                </div>
                <div class="card-body" style="font-size:12px; color:var(--text-muted); line-height:1.6;">
                    <div style="display:flex; gap:8px; margin-bottom:12px; align-items:flex-start;">
                        <span style="background:rgba(99,102,241,0.2); color:var(--primary); width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0;">1</span>
                        <span>Map course to universities under <strong>Course Mappings</strong></span>
                    </div>
                    <div style="display:flex; gap:8px; margin-bottom:12px; align-items:flex-start;">
                        <span style="background:rgba(99,102,241,0.2); color:var(--primary); width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0;">2</span>
                        <span>Add salary packages & career options under <strong>Job Roles & Salary</strong></span>
                    </div>
                    <div style="display:flex; gap:8px; align-items:flex-start;">
                        <span style="background:rgba(99,102,241,0.2); color:var(--primary); width:20px; height:20px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0;">3</span>
                        <span>Attach semester curriculums under <strong>Syllabus Subjects</strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fullNameInput = document.getElementById('field_course_full_name');
    const shortNameInput = document.getElementById('field_course_short_name');
    const slugInput = document.getElementById('field_course_slug');
    
    if (!slugInput) return;

    let isSlugManuallyEdited = slugInput.value.trim() !== '';

    function generateSlug(text) {
        return text
            .toString()
            .toLowerCase()
            .trim()
            .replace(/&/g, '-and-')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function syncSlug() {
        if (isSlugManuallyEdited) return;
        const sourceText = (shortNameInput && shortNameInput.value.trim() !== '') ? shortNameInput.value : (fullNameInput ? fullNameInput.value : '');
        slugInput.value = generateSlug(sourceText);
    }

    if (shortNameInput) {
        shortNameInput.addEventListener('input', syncSlug);
    }

    if (fullNameInput) {
        fullNameInput.addEventListener('input', function() {
            if (!shortNameInput || shortNameInput.value.trim() === '') {
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
