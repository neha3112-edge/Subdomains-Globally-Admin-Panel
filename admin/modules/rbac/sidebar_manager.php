<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('sidebar_manager');

$page_title = 'Sidebar Manager';
$page_subtitle = 'Configure dynamic sidebar menus and RBAC permissions';
$active_page_key = 'sidebar_manager';

$db = get_db_connection();

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $display_name = trim($_POST['display_name'] ?? '');
        $page_route = trim($_POST['page_route'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $active_page_key = trim($_POST['active_page_key'] ?? '');
        $rbac_module_key = trim($_POST['rbac_module_key'] ?? '');
        $menu_section = trim($_POST['menu_section'] ?? 'MANAGE');
        $icon_svg = trim($_POST['icon_svg'] ?? '');
        $is_superadmin_only = isset($_POST['is_superadmin_only']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($display_name) || empty($page_route) || empty($active_page_key) || empty($rbac_module_key)) {
            set_flash_message('Display Name, Route, Active Key, and Module Key are required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE sidebar_items 
                    SET display_name = ?, page_route = ?, sort_order = ?, active_page_key = ?, rbac_module_key = ?, menu_section = ?, icon_svg = ?, is_superadmin_only = ?, is_active = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$display_name, $page_route, $sort_order, $active_page_key, $rbac_module_key, $menu_section, $icon_svg, $is_superadmin_only, $is_active, $id]);
                set_flash_message('Sidebar item updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$display_name, $page_route, $sort_order, $active_page_key, $rbac_module_key, $menu_section, $icon_svg, $is_superadmin_only, $is_active]);
                set_flash_message('Sidebar item created successfully!', 'success');
            }
            redirect(BASE_URL . '/modules/rbac/sidebar_manager.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM sidebar_items WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('Sidebar item deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/rbac/sidebar_manager.php');
        }
    }
}

// Edit Mode
$edit_item = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM sidebar_items WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_item = $stmt->fetch();
}

// Fetch all sidebar items
$items = $db->query("SELECT * FROM sidebar_items ORDER BY menu_section ASC, sort_order ASC, id ASC")->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_item ? 'Edit Sidebar Item' : 'Add Sidebar Item'; ?></span>
            <?php if ($edit_item): ?>
                <a href="<?php echo BASE_URL; ?>/modules/rbac/sidebar_manager.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_item['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Display Name *</label>
                    <input type="text" name="display_name" class="form-control" value="<?php echo htmlspecialchars($edit_item['display_name'] ?? ''); ?>" placeholder="e.g. Universities" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Page Route *</label>
                    <input type="text" name="page_route" class="form-control" value="<?php echo htmlspecialchars($edit_item['page_route'] ?? ''); ?>" placeholder="modules/universities/index.php" required>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Active Key *</label>
                        <input type="text" name="active_page_key" class="form-control" value="<?php echo htmlspecialchars($edit_item['active_page_key'] ?? ''); ?>" placeholder="universities" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">RBAC Key *</label>
                        <input type="text" name="rbac_module_key" class="form-control" value="<?php echo htmlspecialchars($edit_item['rbac_module_key'] ?? ''); ?>" placeholder="universities" required>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Menu Section</label>
                        <input type="text" name="menu_section" class="form-control" value="<?php echo htmlspecialchars($edit_item['menu_section'] ?? 'MANAGE'); ?>" placeholder="MAIN, MANAGE, SETTINGS">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?php echo htmlspecialchars($edit_item['sort_order'] ?? '0'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Icon SVG (Raw &lt;svg&gt;...&lt;/svg&gt;)</label>
                    <textarea name="icon_svg" class="form-textarea" rows="3" placeholder="<svg width=&quot;18&quot; height=&quot;18&quot; ...>"><?php echo htmlspecialchars($edit_item['icon_svg'] ?? ''); ?></textarea>
                </div>

                <div style="display:flex; gap:20px; margin-bottom:18px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px;">
                        <input type="checkbox" name="is_superadmin_only" value="1" <?php echo (!empty($edit_item['is_superadmin_only'])) ? 'checked' : ''; ?>>
                        Superadmin Only
                    </label>

                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px;">
                        <input type="checkbox" name="is_active" value="1" <?php echo (!isset($edit_item['is_active']) || !empty($edit_item['is_active'])) ? 'checked' : ''; ?>>
                        Active
                    </label>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo $edit_item ? 'Update Sidebar Item' : 'Add Sidebar Item'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Sidebar Items (<?php echo count($items); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Icon</th>
                        <th>Display Name</th>
                        <th>Route / Key</th>
                        <th>Section</th>
                        <th>Access</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="6" style="text-align:center; color:var(--text-dim);">No sidebar items configured.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td style="width:30px;">
                                    <?php if (!empty($it['icon_svg'])): ?>
                                        <div style="color:var(--primary);"><?php echo $it['icon_svg']; ?></div>
                                    <?php else: ?>
                                        <span style="color:var(--text-dim);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($it['display_name']); ?></strong>
                                    <div style="font-size:11px; color:var(--text-dim);">Order: <?php echo $it['sort_order']; ?></div>
                                </td>
                                <td>
                                    <code style="font-size:11px; color:var(--text-muted);"><?php echo htmlspecialchars($it['page_route']); ?></code>
                                    <div style="font-size:11px; color:var(--text-dim);">Key: <?php echo htmlspecialchars($it['rbac_module_key']); ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-muted"><?php echo htmlspecialchars($it['menu_section']); ?></span>
                                </td>
                                <td>
                                    <?php if ($it['is_superadmin_only']): ?>
                                        <span class="badge badge-warning">Superadmin</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Role Based</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/rbac/sidebar_manager.php?edit_id=<?php echo $it['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>

                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
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
