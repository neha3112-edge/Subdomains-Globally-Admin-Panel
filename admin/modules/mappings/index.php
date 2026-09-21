<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('mappings');

$page_title = 'Course Mappings';
$page_subtitle = 'Map universities to courses and configure fees structures';
$db = get_db_connection();
sode_ensure_mapping_mode_unique_index($db);

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    if (!user_can('delete')) {
        set_flash_message('Access Denied: You do not have permission to delete.', 'error');
        redirect(BASE_URL . '/modules/mappings/index.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $m_info = $db->query("
            SELECT u.short_name AS u_name, c.short_name AS c_name 
            FROM university_course_mappings m 
            LEFT JOIN universities u ON m.university_id = u.id 
            LEFT JOIN courses c ON m.course_id = c.id 
            WHERE m.id = $id
        ")->fetch();
        $title = ($m_info['u_name'] ?? 'Uni') . ' - ' . ($m_info['c_name'] ?? 'Course');
        move_to_trash('university_course_mappings', $id, $title);
        set_flash_message('Course mapping moved to Trash! You can restore it anytime.', 'success');
        redirect(BASE_URL . '/modules/mappings/index.php');
    }
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (u.full_name LIKE :q1 OR u.short_name LIKE :q2 OR c.full_name LIKE :q3 OR c.short_name LIKE :q4 OR ucm.mode LIKE :q5)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
    $params[':q4'] = '%' . $search . '%';
    $params[':q5'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM university_course_mappings ucm
        INNER JOIN universities u ON ucm.university_id = u.id
        INNER JOIN courses c ON ucm.course_id = c.id
        $where_sql
    ");
    $count_stmt->execute($params);
    $total_mappings = (int)$count_stmt->fetchColumn();
} else {
    $total_mappings = (int)$db->query("
        SELECT COUNT(*) 
        FROM university_course_mappings ucm
        INNER JOIN universities u ON ucm.university_id = u.id
        INNER JOIN courses c ON ucm.course_id = c.id
    ")->fetchColumn();
}

// Fetch paginated mappings
$stmt = $db->prepare("
    SELECT ucm.*, 
           u.full_name AS uni_name, u.short_name AS uni_short, u.logo_url,
           c.full_name AS course_name, c.short_name AS course_short, c.level,
           (SELECT COUNT(*) FROM course_specializations WHERE mapping_id = ucm.id) AS specs_count
    FROM university_course_mappings ucm
    INNER JOIN universities u ON ucm.university_id = u.id
    INNER JOIN courses c ON ucm.course_id = c.id
    $where_sql
    ORDER BY ucm.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$mappings = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">All University-Course Mappings (<?php echo $total_mappings; ?>)</span>
    </div>
    <?php echo rbac_render_add_button(BASE_URL . '/modules/mappings/create.php', 'Map New Course', 'btn-primary btn-sm', 'padding:10px 18px; font-size:13.5px; text-decoration:none;'); ?>
</div>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search mappings by university, course, or mode..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="admin-card">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>University</th>
                    <th>Course</th>
                    <th>Mode</th>
                    <th>Level</th>
                    <th>Per Sem Fee</th>
                    <th>Total Fee</th>
                    <th>Specializations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mappings)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:40px; color:var(--text-dim);">
                            <?php echo $search !== '' ? 'No mappings match your search query "' . htmlspecialchars($search) . '".' : 'No mappings configured. Click "Map New Course" to link a university to a course.'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mappings as $m): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($m['uni_short']); ?></strong>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($m['course_name']); ?></strong>
                                <div style="font-size:11.5px; color:var(--text-muted); font-weight:600;"><?php echo htmlspecialchars($m['course_short']); ?></div>
                            </td>
                            <td>
                                <span class="badge" style="background:<?php echo ($m['mode'] === 'Online') ? 'rgba(59, 130, 246, 0.15)' : 'rgba(245, 158, 11, 0.15)'; ?>; color:<?php echo ($m['mode'] === 'Online') ? '#3b82f6' : '#f59e0b'; ?>; font-weight:700;">
                                    <?php echo htmlspecialchars($m['mode'] ?? 'Online'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($m['level']); ?></span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($m['per_semester_fee'] ?? '₹0'); ?></strong>
                            </td>
                            <td>
                                <strong style="color:var(--primary);"><?php echo htmlspecialchars($m['total_program_fee'] ?? '₹0'); ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-warning"><?php echo $m['specs_count']; ?> Specializations</span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <?php echo rbac_render_edit_button(BASE_URL . '/modules/mappings/edit.php?id=' . $m['id'], 'Edit Mapping'); ?>

                                    <?php echo rbac_render_delete_button($m['id'], 'Delete Mapping'); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo sode_render_pagination($total_mappings, $page, $per_page); ?>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
