<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('courses');

// Backwards compatibility: redirect edit_id to dedicated edit.php page
if (isset($_GET['edit_id'])) {
    redirect(BASE_URL . '/modules/courses/edit.php?id=' . (int)$_GET['edit_id']);
}

$page_title = 'Courses Master';
$page_subtitle = 'Manage academic degree programs, program levels, and university mappings';
$active_page_key = 'courses';

$db = get_db_connection();

// Handle Delete Course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        // Check if course has university mappings
        $check_map = $db->prepare("SELECT COUNT(*) FROM university_course_mappings WHERE course_id = ?");
        $check_map->execute([$id]);
        $map_count = (int)$check_map->fetchColumn();

        if ($map_count > 0) {
            set_flash_message("Cannot delete course because it is currently mapped to {$map_count} university(ies). Please remove those mappings first.", 'error');
        } else {
            $c_info = $db->query("SELECT full_name, short_name FROM courses WHERE id = $id")->fetch();
            $title = $c_info['short_name'] ?? ($c_info['full_name'] ?? 'Course');
            move_to_trash('courses', $id, $title);
            set_flash_message('Course moved to Trash! You can restore it anytime.', 'success');
        }
        redirect(BASE_URL . '/modules/courses/index.php');
    }
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (c.full_name LIKE :q1 OR c.short_name LIKE :q2 OR c.slug LIKE :q3 OR c.level LIKE :q4)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
    $params[':q4'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM courses c $where_sql");
    $count_stmt->execute($params);
    $total_courses = (int)$count_stmt->fetchColumn();
} else {
    $total_courses = (int)$db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
}

// Fetch paginated courses with real-time mappings count and job roles count
$stmt = $db->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM university_course_mappings WHERE course_id = c.id) AS mapped_unis_count,
           IFNULL(JSON_LENGTH(cjr.roles_json), 0) AS job_roles_count
    FROM courses c
    LEFT JOIN course_job_roles cjr ON (LOWER(cjr.course_slug) = LOWER(c.slug) OR LOWER(cjr.course_slug) = LOWER(c.short_name))
    $where_sql
    ORDER BY c.id ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$courses = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Top Action Header -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">All Courses Master (<?php echo $total_courses; ?>)</span>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="<?php echo BASE_URL; ?>/modules/settings/levels.php" style="width:auto; height:auto; padding:9px 15px; font-size:13px; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:var(--radius-md); color:var(--text-main); transition:all 0.2s ease;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
            Degree Levels
        </a>
        <a href="<?php echo BASE_URL; ?>/modules/courses/create.php" class="btn-primary btn-sm" style="padding:10px 18px; font-size:13.5px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add New Course
        </a>
    </div>
</div>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search courses by degree name, short code, slug, or level..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Full-Width Courses Master Table -->
<div class="admin-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">Academic Courses Directory</span>
        <span style="font-size:12px; color:var(--text-dim);">Showing <?php echo count($courses); ?> of <?php echo $total_courses; ?> programs</span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th>Course Program</th>
                    <th>Level</th>
                    <th>URL Slug</th>
                    <th>Mapped Universities</th>
                    <th>Career Job Roles</th>
                    <th style="width:130px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($courses)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:48px; color:var(--text-dim);">
                            <div style="font-size:15px; font-weight:600; margin-bottom:6px;">No courses found</div>
                            <div style="font-size:13px;"><?php echo $search !== '' ? 'No courses match your query "' . htmlspecialchars($search) . '". Try a different search.' : 'Get started by creating your first academic degree program.'; ?></div>
                            <?php if (empty($search)): ?>
                                <a href="<?php echo BASE_URL; ?>/modules/courses/create.php" class="btn-primary btn-sm" style="margin-top:14px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                                    + Add New Course
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $row_idx = $offset + 1;
                    foreach ($courses as $c): 
                    ?>
                        <tr>
                            <td style="color:var(--text-dim); font-size:12px; font-weight:600;"><?php echo $row_idx++; ?></td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/modules/courses/edit.php?id=<?php echo $c['id']; ?>" style="text-decoration:none; color:inherit;">
                                    <strong style="font-size:13.5px; color:var(--text-main);"><?php echo htmlspecialchars($c['full_name']); ?></strong>
                                </a>
                                <div style="font-size:12px; color:var(--primary); font-weight:600; margin-top:2px;">
                                    <?php echo htmlspecialchars($c['short_name']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-info" style="font-weight:600; padding:4px 10px; font-size:11.5px;">
                                    <?php echo htmlspecialchars($c['level']); ?>
                                </span>
                            </td>
                            <td>
                                <code style="font-size:11.5px; background:rgba(255,255,255,0.05); padding:3px 7px; border-radius:4px; color:var(--text-dim);">
                                    <?php echo htmlspecialchars($c['slug']); ?>
                                </code>
                            </td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/modules/course_universities/index.php?course_id=<?php echo $c['id']; ?>" class="badge badge-warning" style="text-decoration:none; padding:4px 10px; font-size:11.5px;">
                                    <?php echo $c['mapped_unis_count']; ?> Universities &rarr;
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php?course_id=<?php echo $c['id']; ?>" class="badge badge-success" style="text-decoration:none; padding:4px 10px; font-size:11.5px;">
                                    <?php echo $c['job_roles_count']; ?> Roles &rarr;
                                </a>
                            </td>
                            <td>
                                <div class="table-actions" style="justify-content:flex-end;">
                                    <a href="<?php echo BASE_URL; ?>/modules/courses/edit.php?id=<?php echo $c['id']; ?>" class="action-btn" title="Edit Course">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>

                                    <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="action-btn delete-btn" title="Delete Course" onclick="return confirm('Are you sure you want to delete course \'<?php echo htmlspecialchars(addslashes($c['full_name'])); ?>\'?');">
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
    <?php echo sode_render_pagination($total_courses, $page, $per_page); ?>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
