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
        return ['success' => false, 'message' => 'Invalid email/username or password.'];
    }

    if (!$user['is_active']) {
        return ['success' => false, 'message' => 'Your account is deactivated. Please contact Superadmin.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
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
