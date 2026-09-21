<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');

$page_title = 'Universities';
$page_subtitle = 'Manage university profiles, accreditations, and configurations';
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

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    if (!user_can('delete')) {
        set_flash_message('Access Denied: You do not have permission to delete.', 'error');
        redirect(BASE_URL . '/modules/universities/index.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $u_info = $db->query("SELECT short_name, full_name FROM universities WHERE id = $id")->fetch();
        $title = $u_info['short_name'] ?? ($u_info['full_name'] ?? 'University');
        move_to_trash('universities', $id, $title);
        set_flash_message('University moved to Trash! You can restore it anytime.', 'success');
        redirect(BASE_URL . '/modules/universities/index.php');
    }
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (u.full_name LIKE :q1 OR u.short_name LIKE :q2 OR u.slug LIKE :q3)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM universities u $where_sql");
    $count_stmt->execute($params);
    $total_universities = (int)$count_stmt->fetchColumn();
} else {
    $total_universities = (int)$db->query("SELECT COUNT(*) FROM universities")->fetchColumn();
}

// Fetch paginated universities
$stmt = $db->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM university_course_mappings WHERE university_id = u.id) AS mapped_courses_count,
           (SELECT COUNT(*) FROM news_items WHERE university_id = u.id AND is_global = 0) AS news_count
    FROM universities u
    $where_sql
    ORDER BY u.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$universities = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">All Universities (<?php echo $total_universities; ?>)</span>
    </div>
    <?php echo rbac_render_add_button(BASE_URL . '/modules/universities/create.php', 'Add New University', 'btn-primary btn-sm', 'padding:10px 18px; font-size:13.5px; text-decoration:none;'); ?>
</div>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search universities by name, short code, or slug..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="admin-card">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Logo</th>
                    <th>University Name</th>
                    <th>Slug</th>
                    <th>Mode</th>
                    <th>Rating</th>
                    <th>Courses Mapped</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($universities)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:40px; color:var(--text-dim);">
                            <?php echo $search !== '' ? 'No universities match your search query "' . htmlspecialchars($search) . '".' : 'No universities found. Click "Add New University" to create your first record.'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($universities as $uni): ?>
                        <tr>
                            <td style="width:50px;">
                                <?php if (!empty($uni['logo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars(get_asset_url($uni['logo_url'])); ?>" alt="logo" style="height:32px; max-width:60px; object-fit:contain; border-radius:4px; background:#fff; padding:2px;" loading="lazy">
                                <?php else: ?>
                                    <div style="width:36px; height:36px; border-radius:6px; background:var(--bg-input); display:flex; align-items:center; justify-content:center; color:var(--text-dim); font-size:11px; font-weight:700;">
                                        <?php echo substr($uni['short_name'], 0, 3); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($uni['full_name']); ?></strong>
                                <?php if (!empty($uni['show_in_alternate'])): ?>
                                    <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; font-size:10.5px; padding:2px 7px; margin-left:6px; font-weight:600;" title="Included in Alternate Universities Listing">Alt List</span>
                                <?php endif; ?>
                                <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($uni['short_name']); ?></div>
                            </td>
                            <td>
                                <code style="font-size:11px; color:var(--text-dim);"><?php echo htmlspecialchars($uni['slug']); ?></code>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($uni['mode'] ?? 'Online'); ?></span>
                            </td>
                            <td>
                                <span style="color:#f59e0b; font-weight:700;">★ <?php echo htmlspecialchars($uni['rating'] ?? '4.0'); ?></span>
                            </td>
                            <td>
                                <span class="badge badge-warning"><?php echo $uni['mapped_courses_count']; ?> Courses</span>
                            </td>
                            <td>
                                <?php if ($uni['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $uni['id']; ?>#uni-news-section" class="action-btn" title="Manage News Announcements (<?php echo $uni['news_count']; ?>)">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                                    </a>

                                    <?php echo rbac_render_edit_button(BASE_URL . '/modules/universities/edit.php?id=' . $uni['id'], 'Edit University'); ?>

                                    <?php echo rbac_render_delete_button($uni['id'], 'Delete University'); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo sode_render_pagination($total_universities, $page, $per_page); ?>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
