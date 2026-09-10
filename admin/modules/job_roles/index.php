<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('job_roles');

$page_title = 'Job Roles & Salary';
$page_subtitle = 'Manage course-wise career roles and salary expectations';
$active_page_key = 'job_roles';

$db = get_db_connection();

// Courses list for dropdown
$courses = $db->query("SELECT id, full_name, short_name FROM courses ORDER BY full_name ASC")->fetchAll();

$filter_course_id = (int)($_GET['course_id'] ?? 0);

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $course_id = (int)($_POST['course_id'] ?? 0);
        $role_name = trim($_POST['role_name'] ?? '');
        $role_link = trim($_POST['role_link'] ?? '');
        $role_description = trim($_POST['role_description'] ?? '');
        $salary_range_india = trim($_POST['salary_range_india'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if (!$course_id || empty($role_name) || empty($salary_range_india)) {
            set_flash_message('Course, Role Name, and Salary Range are required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE job_roles 
                    SET course_id = ?, role_name = ?, role_link = ?, role_description = ?, salary_range_india = ?, sort_order = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$course_id, $role_name, $role_link, $role_description, $salary_range_india, $sort_order, $id]);
                set_flash_message('Job role updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO job_roles (course_id, role_name, role_link, role_description, salary_range_india, sort_order) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$course_id, $role_name, $role_link, $role_description, $salary_range_india, $sort_order]);
                set_flash_message('Job role created successfully!', 'success');
            }
            redirect(BASE_URL . '/modules/job_roles/index.php' . ($course_id ? '?course_id=' . $course_id : ''));
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM job_roles WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('Job role deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/job_roles/index.php' . ($filter_course_id ? '?course_id=' . $filter_course_id : ''));
        }
    }
}

// Edit Mode
$edit_role = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM job_roles WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_role = $stmt->fetch();
}

// Fetch Job Roles
$query = "
    SELECT jr.*, c.full_name AS course_name, c.short_name AS course_short 
    FROM job_roles jr 
    INNER JOIN courses c ON jr.course_id = c.id
";
$params = [];
if ($filter_course_id) {
    $query .= " WHERE jr.course_id = ?";
    $params[] = $filter_course_id;
}
$query .= " ORDER BY jr.course_id ASC, jr.sort_order ASC, jr.id ASC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$roles = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_role ? 'Edit Job Role' : 'Add Job Role'; ?></span>
            <?php if ($edit_role): ?>
                <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_role['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Course *</label>
                    <select name="course_id" class="form-select" required>
                        <option value="">-- Select Course --</option>
                        <?php foreach ($courses as $c): ?>
                            <?php 
                            $selected = ($edit_role && $edit_role['course_id'] == $c['id']) || (!$edit_role && $filter_course_id == $c['id']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($c['full_name'] . ' (' . $c['short_name'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Job Role Title *</label>
                    <input type="text" name="role_name" class="form-control" value="<?php echo htmlspecialchars($edit_role['role_name'] ?? ''); ?>" placeholder="e.g. Data Scientist / Assistant Librarian" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Salary Range in India *</label>
                    <input type="text" name="salary_range_india" class="form-control" value="<?php echo htmlspecialchars($edit_role['salary_range_india'] ?? ''); ?>" placeholder="e.g. ₹4–₹8 LPA" required>
                </div>

                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Role Link (Optional)</label>
                        <input type="url" name="role_link" class="form-control" value="<?php echo htmlspecialchars($edit_role['role_link'] ?? ''); ?>" placeholder="https://...">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?php echo htmlspecialchars($edit_role['sort_order'] ?? '0'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Role Description</label>
                    <textarea name="role_description" class="form-textarea" placeholder="Short description of career responsibilities..."><?php echo htmlspecialchars($edit_role['role_description'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo $edit_role ? 'Update Job Role' : 'Create Job Role'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Job Roles List (<?php echo count($roles); ?>)</span>
            <div>
                <select class="form-select" onchange="location.href='<?php echo BASE_URL; ?>/modules/job_roles/index.php' + (this.value ? '?course_id=' + this.value : '')" style="padding:6px 12px; font-size:12.5px;">
                    <option value="">-- All Courses --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($filter_course_id == $c['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['short_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Job Role</th>
                        <th>Salary Range</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($roles)): ?>
                        <tr><td colspan="5" style="text-align:center; color:var(--text-dim);">No job roles found for this selection.</td></tr>
                    <?php else: ?>
                        <?php foreach ($roles as $r): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($r['course_short']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['role_name']); ?></strong>
                                </td>
                                <td>
                                    <strong style="color:var(--success);"><?php echo htmlspecialchars($r['salary_range_india']); ?></strong>
                                </td>
                                <td style="color:var(--text-muted); font-size:12px; max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?php echo htmlspecialchars($r['role_description'] ?? '-'); ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php?edit_id=<?php echo $r['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>

                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete">
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

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
