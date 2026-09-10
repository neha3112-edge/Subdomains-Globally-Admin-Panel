<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_module_access('roles');

$page_title = 'Role Management';
$page_subtitle = 'Define roles, sidebar module access, and team permissions';
$active_page_key = 'roles';

$db = get_db_connection();

$action = $_GET['action'] ?? '';
$role_id = (int)($_GET['id'] ?? 0);

// Fetch all sidebar modules and teams
$all_modules = $db->query("SELECT id, display_name, rbac_module_key FROM sidebar_items WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll();
$all_teams = $db->query("SELECT id, name FROM teams ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_action = $_POST['post_action'] ?? 'create';

    if ($post_action === 'create' || $post_action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $selected_modules = $_POST['modules'] ?? []; // array of sidebar_item_id
        $team_matrix = $_POST['matrix'] ?? []; // array of [team_id => [can_read, can_create, ...]]

        if (empty($name)) {
            set_flash_message('error', 'Role name is required.');
        } else {
            if ($post_action === 'create') {
                $stmt = $db->prepare("INSERT INTO roles (name) VALUES (:name)");
                try {
                    $stmt->execute(['name' => $name]);
                    $new_role_id = $db->lastInsertId();

                    // Insert sidebar access
                    if (!empty($selected_modules)) {
                        $m_stmt = $db->prepare("INSERT INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                        foreach ($selected_modules as $mod_id) {
                            $m_stmt->execute([$new_role_id, (int)$mod_id]);
                        }
                    }

                    // Insert permissions matrix
                    $p_stmt = $db->prepare("
                        INSERT INTO role_permissions (role_id, team_id, can_read, can_create, can_update, can_delete, can_write, can_all)
                        VALUES (:role_id, :team_id, :read, :create, :update, :delete, :write, :all)
                    ");
                    foreach ($all_teams as $t) {
                        $tid = $t['id'];
                        $m = $team_matrix[$tid] ?? [];
                        $p_stmt->execute([
                            'role_id' => $new_role_id,
                            'team_id' => $tid,
                            'read'    => isset($m['read']) ? 1 : 0,
                            'create'  => isset($m['create']) ? 1 : 0,
                            'update'  => isset($m['update']) ? 1 : 0,
                            'delete'  => isset($m['delete']) ? 1 : 0,
                            'write'   => isset($m['write']) ? 1 : 0,
                            'all'     => isset($m['all']) ? 1 : 0,
                        ]);
                    }

                    set_flash_message('success', 'Role created successfully.');
                    redirect(BASE_URL . '/modules/rbac/roles.php');
                } catch (PDOException $e) {
                    set_flash_message('error', 'Role creation failed: ' . $e->getMessage());
                }
            } elseif ($post_action === 'edit' && $role_id > 0) {
                $stmt = $db->prepare("UPDATE roles SET name = :name WHERE id = :id");
                $stmt->execute(['name' => $name, 'id' => $role_id]);

                // Update sidebar access
                $db->prepare("DELETE FROM role_sidebar_access WHERE role_id = ?")->execute([$role_id]);
                if (!empty($selected_modules)) {
                    $m_stmt = $db->prepare("INSERT INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                    foreach ($selected_modules as $mod_id) {
                        $m_stmt->execute([$role_id, (int)$mod_id]);
                    }
                }

                // Update permissions matrix
                $db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
                $p_stmt = $db->prepare("
                    INSERT INTO role_permissions (role_id, team_id, can_read, can_create, can_update, can_delete, can_write, can_all)
                    VALUES (:role_id, :team_id, :read, :create, :update, :delete, :write, :all)
                ");
                foreach ($all_teams as $t) {
                    $tid = $t['id'];
                    $m = $team_matrix[$tid] ?? [];
                    $p_stmt->execute([
                        'role_id' => $role_id,
                        'team_id' => $tid,
                        'read'    => isset($m['read']) ? 1 : 0,
                        'create'  => isset($m['create']) ? 1 : 0,
                        'update'  => isset($m['update']) ? 1 : 0,
                        'delete'  => isset($m['delete']) ? 1 : 0,
                        'write'   => isset($m['write']) ? 1 : 0,
                        'all'     => isset($m['all']) ? 1 : 0,
                    ]);
                }

                set_flash_message('success', 'Role updated successfully.');
                redirect(BASE_URL . '/modules/rbac/roles.php');
            }
        }
    } elseif ($post_action === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        if ($del_id === 1) {
            set_flash_message('error', 'Superadmin role cannot be deleted.');
        } elseif ($del_id > 0) {
            $stmt = $db->prepare("DELETE FROM roles WHERE id = :id");
            $stmt->execute(['id' => $del_id]);
            set_flash_message('success', 'Role deleted successfully.');
        }
        redirect(BASE_URL . '/modules/rbac/roles.php');
    }
}

// Fetch all roles with users count and modules
$roles = $db->query("
    SELECT r.*, 
    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users_count,
    (SELECT GROUP_CONCAT(si.display_name SEPARATOR ', ') 
     FROM role_sidebar_access rsa 
     JOIN sidebar_items si ON rsa.sidebar_item_id = si.id 
     WHERE rsa.role_id = r.id) AS modules_list
    FROM roles r 
    ORDER BY r.id ASC
")->fetchAll();

$edit_role = null;
$assigned_modules = [];
$assigned_matrix = [];

if ($action === 'edit' && $role_id > 0) {
    $stmt = $db->prepare("SELECT * FROM roles WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $role_id]);
    $edit_role = $stmt->fetch();

    // Fetch assigned modules
    $m_stmt = $db->prepare("SELECT sidebar_item_id FROM role_sidebar_access WHERE role_id = ?");
    $m_stmt->execute([$role_id]);
    $assigned_modules = $m_stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch assigned matrix
    $mat_stmt = $db->prepare("SELECT * FROM role_permissions WHERE role_id = ?");
    $mat_stmt->execute([$role_id]);
    $matrix_rows = $mat_stmt->fetchAll();
    foreach ($matrix_rows as $mr) {
        $assigned_matrix[$mr['team_id']] = $mr;
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    
    <!-- LEFT: Create / Edit Role Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_role ? 'Edit Role' : 'Create Role'; ?></span>
            <?php if ($edit_role): ?>
                <a href="<?php echo BASE_URL; ?>/modules/rbac/roles.php" class="badge badge-muted" style="text-decoration:none;">Cancel</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="post_action" value="<?php echo $edit_role ? 'edit' : 'create'; ?>">

                <div class="form-group">
                    <label class="form-label">Role Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Manager" value="<?php echo htmlspecialchars($edit_role['name'] ?? ''); ?>" required>
                </div>

                <!-- Sidebar Module Access Checkboxes -->
                <div class="form-group" style="margin-top:16px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                        <label class="form-label" style="margin:0;">Sidebar Module Access</label>
                        <label style="font-size:11px; cursor:pointer; color:var(--primary); font-weight:600;">
                            <input type="checkbox" id="select-all-modules"> Select All
                        </label>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:8px; background:var(--bg-input); padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); max-height:220px; overflow-y:auto;">
                        <?php foreach ($all_modules as $mod): 
                            $is_checked = in_array($mod['id'], $assigned_modules);
                        ?>
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; cursor:pointer;">
                                <input type="checkbox" name="modules[]" class="module-chk" value="<?php echo $mod['id']; ?>" <?php echo $is_checked ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($mod['display_name']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Team Permissions Matrix -->
                <div class="form-group" style="margin-top:20px;">
                    <label class="form-label">Team Permissions Matrix</label>
                    <div class="table-responsive" style="border:1px solid var(--border-color); border-radius:var(--radius-md);">
                        <table class="admin-table" style="font-size:12px;">
                            <thead>
                                <tr>
                                    <th>Team</th>
                                    <th>Read</th>
                                    <th>Create</th>
                                    <th>Update</th>
                                    <th>Delete</th>
                                    <th>Write</th>
                                    <th>All</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_teams as $t): 
                                    $tid = $t['id'];
                                    $pm = $assigned_matrix[$tid] ?? [];
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                                        <td><input type="checkbox" name="matrix[<?php echo $tid; ?>][read]" value="1" <?php echo !empty($pm['can_read']) ? 'checked' : ''; ?>></td>
                                        <td><input type="checkbox" name="matrix[<?php echo $tid; ?>][create]" value="1" <?php echo !empty($pm['can_create']) ? 'checked' : ''; ?>></td>
                                        <td><input type="checkbox" name="matrix[<?php echo $tid; ?>][update]" value="1" <?php echo !empty($pm['can_update']) ? 'checked' : ''; ?>></td>
                                        <td><input type="checkbox" name="matrix[<?php echo $tid; ?>][delete]" value="1" <?php echo !empty($pm['can_delete']) ? 'checked' : ''; ?>></td>
                                        <td><input type="checkbox" name="matrix[<?php echo $tid; ?>][write]" value="1" <?php echo !empty($pm['can_write']) ? 'checked' : ''; ?>></td>
                                        <td><input type="checkbox" name="matrix[<?php echo $tid; ?>][all]" value="1" <?php echo !empty($pm['can_all']) ? 'checked' : ''; ?>></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:14px;">
                    <?php echo $edit_role ? 'Update Role' : 'Create Role'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT: All Roles Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Roles (<?php echo count($roles); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Modules Access</th>
                        <th>Users</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($roles)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-dim);">No roles found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($roles as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['name']); ?></strong>
                                </td>
                                <td style="color:var(--text-muted); font-size:12px; max-width:300px;">
                                    <?php echo $r['id'] === 1 ? '<span class="badge badge-info">Full Access Bypass</span>' : htmlspecialchars($r['modules_list'] ?: 'None'); ?>
                                </td>
                                <td>
                                    <span class="badge badge-warning"><?php echo $r['users_count']; ?> Users</span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/rbac/roles.php?action=edit&id=<?php echo $r['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <?php if ($r['id'] !== 1): ?>
                                            <form method="POST" action="" class="delete-form" style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="post_action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="action-btn delete-btn" title="Delete">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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
document.getElementById('select-all-modules')?.addEventListener('change', function() {
    const chks = document.querySelectorAll('.module-chk');
    chks.forEach(c => c.checked = this.checked);
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
