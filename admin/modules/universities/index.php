<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');

$page_title = 'Universities';
$page_subtitle = 'Manage university profiles, accreditations, and configurations';
$active_page_key = 'universities';

$db = get_db_connection();

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("DELETE FROM universities WHERE id = ?");
        $stmt->execute([$id]);
        set_flash_message('University deleted successfully!', 'success');
        redirect(BASE_URL . '/modules/universities/index.php');
    }
}

// Fetch all universities
$universities = $db->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM university_course_mappings WHERE university_id = u.id) AS mapped_courses_count
    FROM universities u
    ORDER BY u.id DESC
")->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">All Universities (<?php echo count($universities); ?>)</span>
    </div>
    <a href="<?php echo BASE_URL; ?>/modules/universities/create.php" class="btn-primary btn-sm" style="padding:10px 18px; font-size:13.5px; text-decoration:none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Add New University
    </a>
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
                            No universities found. Click "Add New University" to create your first record.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($universities as $uni): ?>
                        <tr>
                            <td style="width:50px;">
                                <?php if (!empty($uni['logo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($uni['logo_url']); ?>" alt="logo" style="height:32px; max-width:60px; object-fit:contain; border-radius:4px; background:#fff; padding:2px;">
                                <?php else: ?>
                                    <div style="width:36px; height:36px; border-radius:6px; background:var(--bg-input); display:flex; align-items:center; justify-content:center; color:var(--text-dim); font-size:11px; font-weight:700;">
                                        <?php echo substr($uni['short_name'], 0, 3); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($uni['full_name']); ?></strong>
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
                                    <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $uni['id']; ?>" class="action-btn" title="Edit University">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>

                                    <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $uni['id']; ?>">
                                        <button type="submit" class="action-btn delete-btn" title="Delete University">
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

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
