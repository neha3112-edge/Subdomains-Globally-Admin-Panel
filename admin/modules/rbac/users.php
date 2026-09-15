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
        $username      = trim($_POST['username'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $country_code  = trim($_POST['country_code'] ?? '+91');
        $phone         = trim($_POST['phone'] ?? '');
        $password      = $_POST['password'] ?? '';
        $team_id       = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
        $role_id       = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
        $is_superadmin = isset($_POST['is_superadmin']) ? 1 : 0;
        $is_active     = isset($_POST['is_active']) ? 1 : 0;

        $redirect_back = BASE_URL . '/modules/rbac/users.php' . (($post_action === 'edit' && $user_id > 0) ? '?action=edit&id=' . $user_id : '');

        if (empty($name) || empty($email) || empty($username)) {
            set_flash_message('error', 'Name, Username, and Email are required.');
            redirect($redirect_back);
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
            set_flash_message('error', 'Username must be between 3 and 50 characters and can only contain letters, numbers, underscores, hyphens, and dots.');
            redirect($redirect_back);
        } else {
            // Check username uniqueness
            $check_u = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_u->execute([$username, $user_id]);
            if ($check_u->fetch()) {
                set_flash_message('error', "Username '{$username}' is already taken. Please choose a different one.");
                redirect($redirect_back);
            }

            // Check email uniqueness
            $check_e = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_e->execute([$email, $user_id]);
            if ($check_e->fetch()) {
                set_flash_message('error', "Email '{$email}' is already registered with another account.");
                redirect($redirect_back);
            }

            if ($post_action === 'create') {
                if (empty($password) || strlen($password) < 6) {
                    set_flash_message('error', 'Password must be at least 6 characters.');
                    redirect($redirect_back);
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("
                        INSERT INTO users (name, username, email, country_code, phone, password_hash, plain_password, team_id, role_id, is_superadmin, is_active)
                        VALUES (:name, :username, :email, :country_code, :phone, :hash, :plain_password, :team_id, :role_id, :is_superadmin, :is_active)
                    ");
                    try {
                        $stmt->execute([
                            'name' => $name, 'username' => $username, 'email' => $email, 'country_code' => $country_code,
                            'phone' => $phone, 'hash' => $hash, 'plain_password' => $password, 'team_id' => $team_id,
                            'role_id' => $role_id, 'is_superadmin' => $is_superadmin, 'is_active' => $is_active
                        ]);
                        set_flash_message('success', 'User created successfully.');
                        redirect(BASE_URL . '/modules/rbac/users.php');
                    } catch (PDOException $e) {
                        set_flash_message('error', 'Failed to create user: ' . $e->getMessage());
                        redirect($redirect_back);
                    }
                }
            } elseif ($post_action === 'edit' && $user_id > 0) {
                try {
                    if (!empty($password)) {
                        if (strlen($password) < 6) {
                            set_flash_message('error', 'Password must be at least 6 characters.');
                            redirect($redirect_back);
                        }
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $db->prepare("
                            UPDATE users SET name = :name, username = :username, email = :email, country_code = :country_code,
                            phone = :phone, password_hash = :hash, plain_password = :plain_password, team_id = :team_id, role_id = :role_id,
                            is_superadmin = :is_superadmin, is_active = :is_active WHERE id = :id
                        ");
                        $params = ['name' => $name, 'username' => $username, 'email' => $email, 'country_code' => $country_code,
                            'phone' => $phone, 'hash' => $hash, 'plain_password' => $password, 'team_id' => $team_id, 'role_id' => $role_id,
                            'is_superadmin' => $is_superadmin, 'is_active' => $is_active, 'id' => $user_id];
                    } else {
                        $stmt = $db->prepare("
                            UPDATE users SET name = :name, username = :username, email = :email, country_code = :country_code,
                            phone = :phone, team_id = :team_id, role_id = :role_id,
                            is_superadmin = :is_superadmin, is_active = :is_active WHERE id = :id
                        ");
                        $params = ['name' => $name, 'username' => $username, 'email' => $email, 'country_code' => $country_code,
                            'phone' => $phone, 'team_id' => $team_id, 'role_id' => $role_id,
                            'is_superadmin' => $is_superadmin, 'is_active' => $is_active, 'id' => $user_id];
                    }
                    $stmt->execute($params);

                    // Update active session if editing currently logged-in user
                    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
                        $_SESSION['user_name']     = $name;
                        $_SESSION['user_username'] = $username;
                        $_SESSION['user_email']    = $email;
                    }

                    set_flash_message('success', 'User updated successfully.');
                    redirect(BASE_URL . '/modules/rbac/users.php');
                } catch (PDOException $e) {
                    set_flash_message('error', 'Update failed: ' . $e->getMessage());
                    redirect($redirect_back);
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

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (u.name LIKE :q1 OR u.username LIKE :q2 OR u.email LIKE :q3 OR u.phone LIKE :q4 OR t.name LIKE :q5 OR r.name LIKE :q6)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
    $params[':q4'] = '%' . $search . '%';
    $params[':q5'] = '%' . $search . '%';
    $params[':q6'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
$count_query = "
    SELECT COUNT(*) 
    FROM users u 
    LEFT JOIN teams t ON u.team_id = t.id 
    LEFT JOIN roles r ON u.role_id = r.id 
    $where_sql
";
if ($search !== '') {
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_users = (int)$count_stmt->fetchColumn();
} else {
    $total_users = (int)$db->query($count_query)->fetchColumn();
}

// Fetch paginated users
$stmt = $db->prepare("
    SELECT u.*, t.name AS team_name, r.name AS role_name 
    FROM users u 
    LEFT JOIN teams t ON u.team_id = t.id 
    LEFT JOIN roles r ON u.role_id = r.id 
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
$users = $stmt->fetchAll();

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

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search users by name, username, email, phone, team or role..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="users.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

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
                    <label class="form-label">Full Name <span style="color:var(--danger, #ef4444);">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="Full Name" value="<?php echo htmlspecialchars($edit_user['name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Username <span style="color:var(--danger, #ef4444);">*</span></label>
                    <input type="text" name="username" class="form-control" placeholder="e.g. rachit or admin_user" value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>" pattern="^[a-zA-Z0-9_.-]{3,50}$" title="3-50 characters: letters, numbers, _, -, ." required autocomplete="off">
                    <small style="font-size:11px; color:var(--text-dim); display:block; margin-top:3px;">Can be used along with password to sign in.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Email <span style="color:var(--danger, #ef4444);">*</span></label>
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
                    <label class="form-label">
                        Password 
                        <?php if ($edit_user): ?>
                            <span style="font-size:11.5px; font-weight:normal; color:var(--text-dim);">(leave blank to keep current)</span>
                        <?php else: ?>
                            <span style="color:var(--danger, #ef4444);">*</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="password" class="form-control" placeholder="<?php echo $edit_user ? 'New Password' : 'Min 6 characters'; ?>" <?php echo $edit_user ? '' : 'required'; ?>>
                </div>

                <div class="form-group">
                    <label class="form-label">Team Assignment</label>
                    <select name="team_id" class="form-select">
                        <option value="">-- Select Team (Optional) --</option>
                        <?php foreach ($teams as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo (isset($edit_user['team_id']) && $edit_user['team_id'] == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Role Assignment <span style="color:var(--danger, #ef4444);">*</span></label>
                    <select name="role_id" class="form-select" required>
                        <option value="">-- Select Role --</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo (isset($edit_user['role_id']) && $edit_user['role_id'] == $r['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-check-group" style="margin-bottom:14px;">
                    <label class="checkbox-label" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_superadmin" value="1" <?php echo !empty($edit_user['is_superadmin']) ? 'checked' : ''; ?>>
                        <span style="font-size:13px; font-weight:500;">Superadmin (Full Access, bypasses all permissions)</span>
                    </label>
                </div>

                <div class="form-check-group" style="margin-bottom:20px;">
                    <label class="checkbox-label" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_active" value="1" <?php echo (!isset($edit_user) || !empty($edit_user['is_active'])) ? 'checked' : ''; ?>>
                        <span style="font-size:13px; font-weight:500;">Account Active</span>
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
            <span class="card-title">All Users (<?php echo $total_users; ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Team</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-dim);"><?php echo $search !== '' ? 'No users match your search query "' . htmlspecialchars($search) . '".' : 'No users found.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600; color:var(--text-main);"><?php echo htmlspecialchars($u['name']); ?></div>
                                    <div style="font-size:11.5px; color:var(--text-dim);"><?php echo htmlspecialchars($u['email']); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($u['username'])): ?>
                                        <code style="font-size:12px; font-weight:600; color:var(--primary); background:rgba(99, 102, 241, 0.1); padding:2px 7px; border-radius:4px; border:1px solid rgba(99, 102, 241, 0.25);">@<?php echo htmlspecialchars($u['username']); ?></code>
                                    <?php else: ?>
                                        <span style="color:var(--text-dim); font-size:12px;">—</span>
                                    <?php endif; ?>
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
                                        <button type="button" class="action-btn" title="View User Details" onclick='openViewUserModal(<?php echo htmlspecialchars(json_encode([
                                            "id"            => (int)$u["id"],
                                            "name"          => $u["name"] ?? "",
                                            "username"      => $u["username"] ?? "",
                                            "email"         => $u["email"] ?? "",
                                            "country_code"  => $u["country_code"] ?? "+91",
                                            "phone"         => $u["phone"] ?? "",
                                            "plain_password"=> $u["plain_password"] ?? "",
                                            "team_name"     => $u["team_name"] ?? "No Team",
                                            "role_name"     => $u["role_name"] ?? "User",
                                            "is_superadmin" => !empty($u["is_superadmin"]),
                                            "is_active"     => !empty($u["is_active"]),
                                            "created_at"    => !empty($u["created_at"]) ? date("d M Y, h:i A", strtotime($u["created_at"])) : "—"
                                        ]), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        </button>
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
        <?php echo sode_render_pagination($total_users, $page, $per_page); ?>
    </div>

</div>

<!-- View User Details Modal -->
<div id="viewUserModal" class="sode-modal-overlay">
    <div class="sode-modal" style="max-width:520px;">
        <div class="sode-modal-header">
            <div style="display:flex; align-items:center; gap:12px;">
                <div id="viewUserAvatar" style="width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg, #6366f1, #8b5cf6); display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff; font-size:15px; box-shadow:0 4px 12px rgba(99,102,241,0.3);">
                    U
                </div>
                <div>
                    <div class="sode-modal-title" id="viewUserName" style="font-size:16px; font-weight:700;">User Details</div>
                    <div id="viewUserHandle" style="font-size:12px; color:var(--primary, #6366f1); font-weight:600;">@username</div>
                </div>
            </div>
            <button type="button" class="sode-modal-close" onclick="closeViewUserModal()">&times;</button>
        </div>
        <div class="sode-modal-body" style="padding:22px;">
            <!-- Badges Bar -->
            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid var(--border-color, #1e2b45);">
                <span id="viewUserRoleBadge" class="badge badge-info">Role</span>
                <span id="viewUserStatusBadge" class="badge badge-success">Active</span>
                <span id="viewUserTeamBadge" class="badge badge-muted">Team</span>
            </div>

            <!-- Details Grid -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                
                <div class="detail-box">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Full Name</label>
                    <div id="viewUserFullName" style="font-size:14px; font-weight:600; color:var(--text-main, #f8fafc);">—</div>
                </div>

                <div class="detail-box">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Username</label>
                    <div id="viewUserUsername" style="font-size:13px; font-weight:600; color:var(--primary, #6366f1); font-family:monospace;">—</div>
                </div>

                <div class="detail-box">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Email Address</label>
                    <div id="viewUserEmail" style="font-size:13px; color:var(--text-main, #f8fafc); word-break:break-all;">—</div>
                </div>

                <div class="detail-box">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Phone Number</label>
                    <div id="viewUserPhone" style="font-size:13px; color:var(--text-main, #f8fafc);">—</div>
                </div>

                <!-- Password Box -->
                <div class="detail-box" style="grid-column: span 2; background:rgba(99, 102, 241, 0.05); border:1px solid rgba(99, 102, 241, 0.2); border-radius:10px; padding:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--primary, #6366f1); letter-spacing:0.5px; display:flex; align-items:center; gap:6px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            Password
                        </label>
                        <span id="copyPassAlert" style="font-size:11px; color:#10b981; font-weight:600; display:none;">Copied to clipboard!</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="password" id="viewUserPassInput" readonly value="" style="background:rgba(0,0,0,0.35); border:1px solid rgba(255,255,255,0.14); border-radius:8px; color:#f8fafc; font-family:monospace; font-size:15px; height:44px; padding:0 14px; flex:1; outline:none; letter-spacing:1px;" />
                        <button type="button" id="togglePassBtn" class="action-btn pass-action-btn" title="Show / Hide Password" onclick="toggleViewUserPassword()">
                            <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                        <button type="button" class="action-btn pass-action-btn" title="Copy Password" onclick="copyViewUserPassword()">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                    </div>
                    <small id="passNote" style="font-size:11px; color:var(--text-dim, #64748b); display:block; margin-top:6px;">Current password configured for this user.</small>
                </div>

                <div class="detail-box">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Team</label>
                    <div id="viewUserTeam" style="font-size:13px; color:var(--text-main, #f8fafc);">—</div>
                </div>

                <div class="detail-box">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Role</label>
                    <div id="viewUserRole" style="font-size:13px; color:var(--text-main, #f8fafc);">—</div>
                </div>

                <div class="detail-box" style="grid-column: span 2;">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim, #64748b); letter-spacing:0.5px; display:block; margin-bottom:4px;">Account Created</label>
                    <div id="viewUserCreatedAt" style="font-size:12.5px; color:var(--text-dim, #94a3b8);">—</div>
                </div>

            </div>
        </div>
        <div class="sode-modal-footer" style="display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid var(--border-color, #1e2b45); background:rgba(255,255,255,0.02);">
            <button type="button" class="btn-sm action-btn" onclick="closeViewUserModal()">Close</button>
            <a id="viewUserEditLink" href="#" class="btn-sm btn-primary" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none; padding:7px 16px; font-size:12.5px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                Edit User
            </a>
        </div>
    </div>
</div>

<style>
/* View User Modal Styles */
.sode-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.sode-modal {
    background: var(--bg-card, #0f172a);
    border: 1px solid var(--border-color, #1e2b45);
    border-radius: 14px;
    width: 100%;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.sode-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color, #1e2b45);
    background: rgba(255, 255, 255, 0.02);
}
.sode-modal-title {
    color: var(--text-main, #f8fafc);
}
.sode-modal-close {
    background: transparent;
    border: none;
    color: var(--text-dim, #64748b);
    font-size: 20px;
    cursor: pointer;
    line-height: 1;
    padding: 4px;
}
.sode-modal-close:hover {
    color: #fff;
}
.pass-action-btn {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    padding: 0 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 8px !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border: 1px solid rgba(255, 255, 255, 0.16) !important;
    color: #f8fafc !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
}
.pass-action-btn:hover {
    background: rgba(99, 102, 241, 0.25) !important;
    border-color: rgba(99, 102, 241, 0.6) !important;
    color: #fff !important;
    transform: translateY(-1px);
}
.pass-action-btn svg {
    width: 20px !important;
    height: 20px !important;
    stroke: currentColor !important;
    stroke-width: 2.2 !important;
}
</style>

<script>
function openViewUserModal(user) {
    document.getElementById('viewUserFullName').textContent = user.name || '—';
    document.getElementById('viewUserName').textContent = user.name || 'User Details';
    document.getElementById('viewUserHandle').textContent = user.username ? '@' + user.username : '@—';
    document.getElementById('viewUserUsername').textContent = user.username ? '@' + user.username : '—';
    document.getElementById('viewUserEmail').textContent = user.email || '—';
    document.getElementById('viewUserPhone').textContent = (user.phone ? ((user.country_code ? user.country_code + ' ' : '') + user.phone) : '—');
    document.getElementById('viewUserTeam').textContent = user.team_name || 'No Team';
    document.getElementById('viewUserRole').textContent = user.role_name || (user.is_superadmin ? 'Superadmin' : 'User');
    document.getElementById('viewUserCreatedAt').textContent = user.created_at || '—';
    
    // Initials avatar
    var initials = (user.name || 'U').split(' ').map(function(n){ return n ? n[0] : ''; }).join('').substring(0, 2).toUpperCase();
    document.getElementById('viewUserAvatar').textContent = initials || 'U';

    // Badges
    var roleBadge = document.getElementById('viewUserRoleBadge');
    if (user.is_superadmin) {
        roleBadge.className = 'badge badge-info';
        roleBadge.textContent = 'Superadmin';
    } else {
        roleBadge.className = 'badge badge-muted';
        roleBadge.textContent = user.role_name || 'User';
    }

    var statusBadge = document.getElementById('viewUserStatusBadge');
    if (user.is_active) {
        statusBadge.className = 'badge badge-success';
        statusBadge.textContent = 'Active Account';
    } else {
        statusBadge.className = 'badge badge-danger';
        statusBadge.textContent = 'Inactive Account';
    }

    document.getElementById('viewUserTeamBadge').textContent = user.team_name || 'No Team';

    // Password
    var passInput = document.getElementById('viewUserPassInput');
    var passNote = document.getElementById('passNote');
    passInput.type = 'password';
    passInput.value = user.plain_password || '';
    if (!user.plain_password) {
        passInput.placeholder = 'Password encrypted (legacy)';
        passNote.textContent = 'Password was set prior to plain storage or encrypted.';
    } else {
        passInput.placeholder = '';
        passNote.textContent = 'Current password configured for this user.';
    }
    document.getElementById('copyPassAlert').style.display = 'none';
    updateEyeIcon(false);

    // Edit link
    document.getElementById('viewUserEditLink').href = '<?php echo BASE_URL; ?>/modules/rbac/users.php?action=edit&id=' + user.id;

    // Show modal
    var modal = document.getElementById('viewUserModal');
    modal.style.display = 'flex';
}

function closeViewUserModal() {
    var modal = document.getElementById('viewUserModal');
    if (modal) modal.style.display = 'none';
}

function toggleViewUserPassword() {
    var passInput = document.getElementById('viewUserPassInput');
    if (passInput.type === 'password') {
        passInput.type = 'text';
        updateEyeIcon(true);
    } else {
        passInput.type = 'password';
        updateEyeIcon(false);
    }
}

function updateEyeIcon(showing) {
    var eyeSvg = document.getElementById('eyeIcon');
    if (showing) {
        eyeSvg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        eyeSvg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
}

function copyViewUserPassword() {
    var passInput = document.getElementById('viewUserPassInput');
    if (!passInput.value) return;
    navigator.clipboard.writeText(passInput.value).then(function() {
        var alert = document.getElementById('copyPassAlert');
        alert.style.display = 'inline';
        setTimeout(function(){ alert.style.display = 'none'; }, 2500);
    });
}

// Close on background click
window.addEventListener('click', function(e) {
    var modal = document.getElementById('viewUserModal');
    if (e.target === modal) {
        closeViewUserModal();
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
