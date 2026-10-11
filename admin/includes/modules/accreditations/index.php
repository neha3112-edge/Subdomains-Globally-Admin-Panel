<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('accreditations');

$page_title = 'Accreditations';
$page_subtitle = 'Manage UGC-DEB, AICTE, NAAC, NIRF, and other approvals';
$active_page_key = 'accreditations';

$db = get_db_connection();

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        if ($id && !user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update accreditations.', 'error');
            redirect(BASE_URL . '/modules/accreditations/index.php');
        } elseif (!$id && !user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create accreditations.', 'error');
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
        $title = trim($_POST['title'] ?? '');
        $image_url = get_relative_asset_path(trim($_POST['image_url'] ?? ''));
        $official_link = trim($_POST['official_link'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($title)) {
            set_flash_message('Accreditation Title is required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("UPDATE accreditations SET title = ?, image_url = ?, official_link = ?, description = ? WHERE id = ?");
                $stmt->execute([$title, $image_url, $official_link, $description, $id]);
                set_flash_message('Accreditation updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("INSERT INTO accreditations (title, image_url, official_link, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $image_url, $official_link, $description]);
                set_flash_message('Accreditation added successfully!', 'success');
            }
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
    } elseif ($action === 'delete') {
        if (!user_can('delete')) {
            set_flash_message('Access Denied: You do not have permission to delete accreditations.', 'error');
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $ok = move_to_trash('accreditations', $id);
            if ($ok) {
                set_flash_message('Accreditation moved to Trash! You can restore it anytime.', 'success');
            } else {
                set_flash_message('Failed to move accreditation to Trash.', 'error');
            }
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
    }
}

// Edit Mode
$edit_acc = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM accreditations WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_acc = $stmt->fetch();
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (a.title LIKE :q1 OR a.description LIKE :q2)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM accreditations a $where_sql");
    $count_stmt->execute($params);
    $total_accreditations = (int)$count_stmt->fetchColumn();
} else {
    $total_accreditations = (int)$db->query("SELECT COUNT(*) FROM accreditations")->fetchColumn();
}

// Fetch paginated accreditations
$stmt = $db->prepare("
    SELECT a.*,
           (SELECT COUNT(*) FROM university_accreditations WHERE accreditation_id = a.id) AS universities_count
    FROM accreditations a 
    $where_sql
    ORDER BY a.id ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$accreditations = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search accreditations by title or description..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_acc ? 'Edit Accreditation' : 'Add Accreditation'; ?></span>
            <?php if ($edit_acc): ?>
                <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_acc['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Title * (e.g. UGC-DEB Approved / NAAC A++)</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($edit_acc['title'] ?? ''); ?>" placeholder="UGC-DEB Approved" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Badge / Logo Image URL</label>
                    <div class="media-input-group">
                        <input type="text" name="image_url" id="field_acc_logo" class="form-control" value="<?php echo htmlspecialchars($edit_acc['image_url'] ?? ''); ?>" placeholder="https://.../ugc-logo.png">
                        <button type="button" class="btn-media-choose media-picker-btn" data-target="field_acc_logo" data-preview="preview_acc_logo" data-type="image">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            Choose
                        </button>
                    </div>
                    <div class="media-preview-container" id="preview_acc_logo">
                        <?php if (!empty($edit_acc['image_url'])): ?>
                            <div class="media-preview-item">
                                <img src="<?php echo htmlspecialchars(get_asset_url($edit_acc['image_url'])); ?>" alt="Preview" style="max-height:80px;">
                                <span class="media-preview-remove" onclick="removeMediaPreview('field_acc_logo', 'preview_acc_logo')">&times;</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Official Link</label>
                    <input type="url" name="official_link" class="form-control" value="<?php echo htmlspecialchars($edit_acc['official_link'] ?? ''); ?>" placeholder="https://deb.ugc.ac.in">
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Importance Note</label>
                    <textarea name="description" class="form-textarea" placeholder="Why this accreditation matters..."><?php echo htmlspecialchars($edit_acc['description'] ?? ''); ?></textarea>
                </div>

                <div style="margin-top:24px; display:flex; gap:10px;">
                    <?php if ($edit_acc): ?>
                        <?php if (can_update()): ?>
                            <button type="submit" class="btn-primary" style="flex:1;">Update Accreditation</button>
                        <?php else: ?>
                            <button type="button" class="btn-secondary btn-disabled-locked" disabled style="flex:1;" title="Access Denied: You do not have permission to update">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                Update Accreditation (Locked)
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (can_create()): ?>
                            <button type="submit" class="btn-primary" style="flex:1;">Save Accreditation</button>
                        <?php else: ?>
                            <button type="button" class="btn-secondary btn-disabled-locked" disabled style="flex:1;" title="Access Denied: You do not have permission to create">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                Save Accreditation (Locked)
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($edit_acc): ?>
                        <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php" class="btn-sm action-btn" style="text-decoration:none; padding:10px 16px; font-weight:600;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Accreditations Master (<?php echo $total_accreditations; ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Badge</th>
                        <th>Title</th>
                        <th>Universities</th>
                        <th>Link</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accreditations)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:32px; color:var(--text-dim);"><?php echo $search !== '' ? 'No accreditations match your search query "' . htmlspecialchars($search) . '".' : 'No accreditations added yet.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($accreditations as $a): ?>
                            <tr>
                                <td style="width:40px;">
                                    <?php if (!empty($a['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars(get_asset_url($a['image_url'])); ?>" alt="logo" style="width:32px; height:32px; object-fit:contain; border-radius:4px; background:#fff; padding:2px;" loading="lazy">
                                    <?php else: ?>
                                        <div style="width:32px; height:32px; border-radius:4px; background:var(--bg-input); display:flex; align-items:center; justify-content:center; color:var(--text-dim); font-size:10px; font-weight:700;">
                                            DOC
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                                    <div style="font-size:11.5px; color:var(--text-dim);"><?php echo htmlspecialchars($a['description'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-warning"><?php echo $a['universities_count']; ?> Universities</span>
                                </td>
                                <td>
                                    <?php if (!empty($a['official_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($a['official_link']); ?>" target="_blank" class="action-btn" title="Visit Link">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--text-dim);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <?php echo rbac_render_edit_button(BASE_URL . '/modules/accreditations/index.php?edit_id=' . $a['id'], 'Edit'); ?>

                                        <?php echo rbac_render_delete_button($a['id'], 'Delete'); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo sode_render_pagination($total_accreditations, $page, $per_page); ?>
    </div>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
