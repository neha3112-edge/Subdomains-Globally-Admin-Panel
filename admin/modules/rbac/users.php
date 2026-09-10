<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_module_access('users');

$page_title = 'User Management';
$page_subtitle = 'Manage administrative logins and user access';
$active_page_key = 'users';

$db = get_db_connection();

// Handle Form Submissions (Create / Edit / Delete)
$action = $_GET['action'] ?? '';
$user_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_action = $_POST['post_action'] ?? 'create';

    if ($post_action === 'create' || $post_action === 'edit') {
        $name          = trim($_POST['name'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $country_code  = trim($_POST['country_code'] ?? '+91');
        $phone         = trim($_POST['phone'] ?? '');
        $password      = $_POST['password'] ?? '';
        $team_id       = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
        $role_id       = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
        $is_superadmin = isset($_POST['is_superadmin']) ? 1 : 0;
        $is_active     = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name) || empty($email)) {
            set_flash_message('error', 'Name and Email are required.');
        } else {
            if ($post_action === 'create') {
                if (empty($password) || strlen($password) < 6) {
                    set_flash_message('error', 'Password must be at least 6 characters.');
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("
                        INSERT INTO users (name, email, country_code, phone, password_hash, team_id, role_id, is_superadmin, is_active)
                        VALUES (:name, :email, :country_code, :phone, :hash, :team_id, :role_id, :is_superadmin, :is_active)
                    ");
                    try {
                        $stmt->execute([
                            'name' => $name, 'email' => $email, 'country_code' => $country_code,
                            'phone' => $phone, 'hash' => $hash, 'team_id' => $team_id,
                            'role_id' => $role_id, 'is_superadmin' => $is_superadmin, 'is_active' => $is_active
                        ]);
                        set_flash_message('success', 'User created successfully.');
                        redirect(BASE_URL . '/modules/rbac/users.php');
                    } catch (PDOException $e) {
                        set_flash_message('error', 'Email already exists or invalid data.');
                    }
                }
            } elseif ($post_action === 'edit' && $user_id > 0) {
                if (!empty($password)) {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("
                        UPDATE users SET name = :name, email = :email, country_code = :country_code,
                        phone = :phone, password_hash = :hash, team_id = :team_id, role_id = :role_id,
                        is_superadmin = :is_superadmin, is_active = :is_active WHERE id = :id
                    ");
                    $params = ['name' => $name, 'email' => $email, 'country_code' => $country_code,
                        'phone' => $phone, 'hash' => $hash, 'team_id' => $team_id, 'role_id' => $role_id,
                        'is_superadmin' => $is_superadmin, 'is_active' => $is_active, 'id' => $user_id];
                } else {
                    $stmt = $db->prepare("
                        UPDATE users SET name = :name, email = :email, country_code = :country_code,
                        phone = :phone, team_id = :team_id, role_id = :role_id,
                        is_superadmin = :is_superadmin, is_active = :is_active WHERE id = :id
                    ");
                    $params = ['name' => $name, 'email' => $email, 'country_code' => $country_code,
                        'phone' => $phone, 'team_id' => $team_id, 'role_id' => $role_id,
                        'is_superadmin' => $is_superadmin, 'is_active' => $is_active, 'id' => $user_id];
                }
                try {
                    $stmt->execute($params);
                    set_flash_message('success', 'User updated successfully.');
                    redirect(BASE_URL . '/modules/rbac/users.php');
                } catch (PDOException $e) {
                    set_flash_message('error', 'Update failed: ' . $e->getMessage());
                }
            }
        }
    } elseif ($post_action === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        if ($del_id === 1) {
            set_flash_message('error', 'Primary Superadmin cannot be deleted.');
        } elseif ($del_id > 0) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $del_id]);
            set_flash_message('success', 'User deleted successfully.');
        }
        redirect(BASE_URL . '/modules/rbac/users.php');
    }
}

// Fetch all users
$users = $db->query("
    SELECT u.*, t.name AS team_name, r.name AS role_name 
    FROM users u 
    LEFT JOIN teams t ON u.team_id = t.id 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
")->fetchAll();

// Fetch teams and roles for dropdowns
$teams = $db->query("SELECT id, name FROM teams ORDER BY name ASC")->fetchAll();
$roles = $db->query("SELECT id, name FROM roles ORDER BY name ASC")->fetchAll();

// Edit state
$edit_user = null;
if ($action === 'edit' && $user_id > 0) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $user_id]);
    $edit_user = $stmt->fetch();
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    
    <!-- LEFT: Create / Edit User Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_user ? 'Edit User' : 'Create User'; ?></span>
            <?php if ($edit_user): ?>
                <a href="<?php echo BASE_URL; ?>/modules/rbac/users.php" class="badge badge-muted" style="text-decoration:none;">Cancel</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="post_action" value="<?php echo $edit_user ? 'edit' : 'create'; ?>">

                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control" placeholder="Full Name" value="<?php echo htmlspecialchars($edit_user['name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="email@example.com" value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <div class="phone-input-group">
                        <input type="text" name="country_code" class="form-control" placeholder="+91" value="<?php echo htmlspecialchars($edit_user['country_code'] ?? '+91'); ?>">
                        <input type="tel" name="phone" class="form-control" placeholder="Phone Number" value="<?php echo htmlspecialchars($edit_user['phone'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password <?php echo $edit_user ? '<small style="color:var(--text-dim);">(leave blank to keep current)</small>' : ''; ?></label>
                    <input type="password" name="password" class="form-control" placeholder="Min 6 characters" <?php echo $edit_user ? '' : 'required'; ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">Team</label>
                    <select name="team_id" class="form-select">
                        <option value="">No Team</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo (($edit_user['team_id'] ?? 0) == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role_id" class="form-select">
                        <option value="">No Role (Access Denied by default)</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo (($edit_user['role_id'] ?? 0) == $r['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-top:10px;">
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
                        <input type="checkbox" name="is_superadmin" value="1" <?php echo !empty($edit_user['is_superadmin']) ? 'checked' : ''; ?>>
                        <span>Superadmin (Full access bypass)</span>
                    </label>
                </div>

                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;">
                        <input type="checkbox" name="is_active" value="1" <?php echo (!isset($edit_user) || !empty($edit_user['is_active'])) ? 'checked' : ''; ?>>
                        <span>Active Account</span>
                    </label>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:10px;">
                    <?php echo $edit_user ? 'Update User' : 'Create User'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT: All Users Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Users (<?php echo count($users); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Team</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" style="text-align:center; color:var(--text-dim);">No users found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div><strong><?php echo htmlspecialchars($u['name']); ?></strong></div>
                                    <div style="font-size:11.5px; color:var(--text-dim);"><?php echo htmlspecialchars($u['email']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($u['team_name'] ?? 'No Team'); ?></td>
                                <td>
                                    <span class="badge <?php echo $u['is_superadmin'] ? 'badge-info' : 'badge-muted'; ?>">
                                        <?php echo $u['is_superadmin'] ? 'Superadmin' : htmlspecialchars($u['role_name'] ?? 'User'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $u['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/rbac/users.php?action=edit&id=<?php echo $u['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <?php if ($u['id'] !== 1): ?>
                                            <form method="POST" action="" class="delete-form" style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="post_action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
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

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
