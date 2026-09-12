<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('courses');

$page_title = 'Courses Master';
$page_subtitle = 'Manage academic programs, degree levels, and descriptions';
$active_page_key = 'courses';

$db = get_db_connection();

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $full_name = trim($_POST['full_name'] ?? '');
        $short_name = trim($_POST['short_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        if (empty($slug)) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $short_name ?: $full_name)));
        }
        $level = trim($_POST['level'] ?? 'PG');
        $description = trim($_POST['description'] ?? '');

        if (empty($full_name) || empty($short_name) || empty($slug)) {
            set_flash_message('Full Name, Short Name, and Slug are required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("UPDATE courses SET full_name = ?, short_name = ?, slug = ?, level = ?, description = ? WHERE id = ?");
                $stmt->execute([$full_name, $short_name, $slug, $level, $description, $id]);
                set_flash_message('Course updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("INSERT INTO courses (full_name, short_name, slug, level, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$full_name, $short_name, $slug, $level, $description]);
                set_flash_message('Course created successfully!', 'success');
            }
            redirect(BASE_URL . '/modules/courses/index.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('Course deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/courses/index.php');
        }
    }
}

// Edit Mode
$edit_course = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_course = $stmt->fetch();
}

// Fetch all courses with real-time job roles count from course_job_roles
$courses = $db->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM university_course_mappings WHERE course_id = c.id) AS mapped_unis_count,
           IFNULL(JSON_LENGTH(cjr.roles_json), 0) AS job_roles_count
    FROM courses c
    LEFT JOIN course_job_roles cjr ON (LOWER(cjr.course_slug) = LOWER(c.slug) OR LOWER(cjr.course_slug) = LOWER(c.short_name))
    ORDER BY c.id ASC
")->fetchAll();


require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    <!-- Left: Add / Edit Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_course ? 'Edit Course' : 'Add New Course'; ?></span>
            <?php if ($edit_course): ?>
                <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_course['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Full Degree Name *</label>
                    <input type="text" name="full_name" id="field_course_full_name" class="form-control" value="<?php echo htmlspecialchars($edit_course['full_name'] ?? ''); ?>" placeholder="e.g. Master of Business Administration" required>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Short Name *</label>
                        <input type="text" name="short_name" id="field_course_short_name" class="form-control" value="<?php echo htmlspecialchars($edit_course['short_name'] ?? ''); ?>" placeholder="e.g. MBA" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Level *</label>
                        <select name="level" class="form-select">
                            <option value="PG" <?php echo (($edit_course['level'] ?? '') === 'PG') ? 'selected' : ''; ?>>Postgraduate (PG)</option>
                            <option value="UG" <?php echo (($edit_course['level'] ?? '') === 'UG') ? 'selected' : ''; ?>>Undergraduate (UG)</option>
                            <option value="Diploma" <?php echo (($edit_course['level'] ?? '') === 'Diploma') ? 'selected' : ''; ?>>Diploma</option>
                            <option value="Certificate" <?php echo (($edit_course['level'] ?? '') === 'Certificate') ? 'selected' : ''; ?>>Certificate</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Slug *</label>
                    <input type="text" name="slug" id="field_course_slug" class="form-control" value="<?php echo htmlspecialchars($edit_course['slug'] ?? ''); ?>" placeholder="e.g. mba" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Overview / Description</label>
                    <textarea name="description" class="form-textarea" placeholder="Detailed syllabus or program scope..."><?php echo htmlspecialchars($edit_course['description'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo $edit_course ? 'Update Course' : 'Create Course'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Right: All Courses Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Courses Master (<?php echo count($courses); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Level</th>
                        <th>Slug</th>
                        <th>Universities</th>
                        <th>Job Roles</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($courses)): ?>
                        <tr><td colspan="6" style="text-align:center; color:var(--text-dim);">No courses created yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($courses as $c): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['full_name']); ?></strong>
                                    <div style="font-size:11.5px; color:var(--text-muted); font-weight:600;"><?php echo htmlspecialchars($c['short_name']); ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($c['level']); ?></span>
                                </td>
                                <td>
                                    <code style="font-size:11px; color:var(--text-dim);"><?php echo htmlspecialchars($c['slug']); ?></code>
                                </td>
                                <td>
                                    <span class="badge badge-warning"><?php echo $c['mapped_unis_count']; ?> Universities</span>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php?course_id=<?php echo $c['id']; ?>" class="badge badge-success" style="text-decoration:none;">
                                        <?php echo $c['job_roles_count']; ?> Roles &rarr;
                                    </a>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/courses/index.php?edit_id=<?php echo $c['id']; ?>" class="action-btn" title="Edit Course">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>

                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete Course">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
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
