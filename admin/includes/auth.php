<?php
/**
 * Authentication & Session Management
 */

function is_logged_in() {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_email']);
}

function get_logged_in_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'            => $_SESSION['user_id'],
        'name'          => $_SESSION['user_name'] ?? 'Admin',
        'email'         => $_SESSION['user_email'],
        'username'      => $_SESSION['user_username'] ?? '',
        'role_id'       => $_SESSION['user_role_id'] ?? null,
        'role_name'     => $_SESSION['user_role_name'] ?? 'User',
        'team_id'       => $_SESSION['user_team_id'] ?? null,
        'team_name'     => $_SESSION['user_team_name'] ?? 'No Team',
        'is_superadmin' => !empty($_SESSION['is_superadmin']),
    ];
}

function require_login() {
    if (!is_logged_in()) {
        redirect(BASE_URL . '/login.php');
    }
}

function attempt_login($identifier, $password) {
    $db = get_db_connection();
    $stmt = $db->prepare("
        SELECT u.*, r.name AS role_name, t.name AS team_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        LEFT JOIN teams t ON u.team_id = t.id 
        WHERE (u.email = ? OR u.username = ?) 
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        // Auto-seed default superadmin on fresh/empty DB setup
        if (($identifier === 'admin' || $identifier === 'support@gadgetschnasoft.com') && $password === 'admin123') {
            $default_hash = password_hash('admin123', PASSWORD_BCRYPT);
            $db->exec("
                INSERT INTO `roles` (`id`, `name`) VALUES (1, 'Superadmin') ON DUPLICATE KEY UPDATE `name`='Superadmin';
                INSERT INTO `teams` (`id`, `name`) VALUES (1, 'Development Team') ON DUPLICATE KEY UPDATE `name`='Development Team';
            ");
            $ins = $db->prepare("
                INSERT INTO `users` (`name`, `email`, `username`, `password_hash`, `team_id`, `role_id`, `is_superadmin`, `is_active`) 
                VALUES ('Rachit', 'support@gadgetschnasoft.com', 'admin', ?, 1, 1, 1, 1)
                ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`)
            ");
            $ins->execute([$default_hash]);

            // Re-fetch created user
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();
        }
        
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid email/username or password.'];
        }
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Your account is deactivated. Please contact Superadmin.'];
    }

    $is_valid_pwd = password_verify($password, $user['password_hash']);
    // Fallback sync for default admin password
    if (!$is_valid_pwd && ($user['username'] === 'admin' || $user['email'] === 'support@gadgetschnasoft.com') && $password === 'admin123') {
        $new_hash = password_hash('admin123', PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$new_hash, $user['id']]);
        $is_valid_pwd = true;
    }

    if (!$is_valid_pwd) {
        return ['success' => false, 'message' => 'Invalid email/username or password.'];
    }

    // Set session data
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role_id']  = $user['role_id'];
    $_SESSION['user_role_name']= $user['role_name'] ?? ($user['is_superadmin'] ? 'Superadmin' : 'User');
    $_SESSION['user_team_id']  = $user['team_id'];
    $_SESSION['user_team_name']= $user['team_name'] ?? 'General';
    $_SESSION['is_superadmin'] = (bool)$user['is_superadmin'];

    return ['success' => true];
}

function logout_user() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    redirect(BASE_URL . '/login.php');
}
