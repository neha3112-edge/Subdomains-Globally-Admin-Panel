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
        $title = trim($_POST['title'] ?? '');
        $image_url = trim($_POST['image_url'] ?? '');
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
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM accreditations WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('Accreditation deleted successfully!', 'success');
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

// Fetch all accreditations
$accreditations = $db->query("
    SELECT a.*,
           (SELECT COUNT(*) FROM university_accreditations WHERE accreditation_id = a.id) AS universities_count
    FROM accreditations a 
    ORDER BY a.id ASC
")->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

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
                    <div class="media-preview-inline" id="preview_acc_logo" style="margin-top:6px; <?php echo empty($edit_acc['image_url']) ? 'display:none;' : ''; ?>">
                        <?php if (!empty($edit_acc['image_url'])): ?>
                            <img src="<?php echo htmlspecialchars($edit_acc['image_url']); ?>" alt="thumb" style="height:34px; width:34px; object-fit:contain; background:#fff; border-radius:4px; padding:2px; border:1px solid var(--border-color);">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Official Portal Link</label>
                    <input type="url" name="official_link" class="form-control" value="<?php echo htmlspecialchars($edit_acc['official_link'] ?? ''); ?>" placeholder="https://ugc.ac.in">
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Legal Authority Notes</label>
                    <textarea name="description" class="form-textarea" placeholder="Entitled by University Grants Commission Distance Education Bureau..."><?php echo htmlspecialchars($edit_acc['description'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo $edit_acc ? 'Update Accreditation' : 'Add Accreditation'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Accreditations Master (<?php echo count($accreditations); ?>)</span>
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
                        <tr><td colspan="5" style="text-align:center; color:var(--text-dim);">No accreditations added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($accreditations as $a): ?>
                            <tr>
                                <td style="width:40px;">
                                    <?php if (!empty($a['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($a['image_url']); ?>" alt="logo" style="width:32px; height:32px; object-fit:contain; border-radius:4px; background:#fff; padding:2px;">
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
                                        <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php?edit_id=<?php echo $a['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>

                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
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
