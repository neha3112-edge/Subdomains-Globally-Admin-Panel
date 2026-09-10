<?php
/**
 * RBAC (Role-Based Access Control) & Dynamic Sidebar Engine
 */

function is_superadmin() {
    return !empty($_SESSION['is_superadmin']);
}

function user_can($action, $team_id = null) {
    if (is_superadmin()) {
        return true;
    }

    $role_id = $_SESSION['user_role_id'] ?? 0;
    if (!$role_id) return false;

    if ($team_id === null) {
        $team_id = $_SESSION['user_team_id'] ?? 0;
    }

    $db = get_db_connection();
    $stmt = $db->prepare("SELECT * FROM role_permissions WHERE role_id = :role_id AND team_id = :team_id LIMIT 1");
    $stmt->execute(['role_id' => $role_id, 'team_id' => $team_id]);
    $perm = $stmt->fetch();

    if (!$perm) return false;
    if (!empty($perm['can_all'])) return true;

    $col = 'can_' . strtolower($action);
    return !empty($perm[$col]);
}

function require_module_access($module_key) {
    require_login();
    if (is_superadmin()) {
        return true;
    }

    $role_id = $_SESSION['user_role_id'] ?? 0;
    if (!$role_id) {
        http_response_code(403);
        die("Access Denied: You do not have permission to view this module.");
    }

    $db = get_db_connection();
    $stmt = $db->prepare("
        SELECT si.id 
        FROM sidebar_items si
        INNER JOIN role_sidebar_access rsa ON si.id = rsa.sidebar_item_id
        WHERE rsa.role_id = :role_id AND si.rbac_module_key = :module_key AND si.is_active = 1
        LIMIT 1
    ");
    $stmt->execute(['role_id' => $role_id, 'module_key' => $module_key]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        die("Access Denied: You do not have permission to access the " . htmlspecialchars($module_key) . " module.");
    }
}

function require_permission($module_key) {
    return require_module_access($module_key);
}

function get_user_sidebar_items() {
    $db = get_db_connection();
    if (is_superadmin()) {
        $stmt = $db->query("SELECT * FROM sidebar_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
        $items = $stmt->fetchAll();
        if (!empty($items)) return $items;
    }

    $role_id = $_SESSION['user_role_id'] ?? 0;
    if (!$role_id) return [];

    $stmt = $db->prepare("
        SELECT si.* 
        FROM sidebar_items si
        INNER JOIN role_sidebar_access rsa ON si.id = rsa.sidebar_item_id
        WHERE rsa.role_id = :role_id AND si.is_active = 1 AND si.is_superadmin_only = 0
        ORDER BY si.sort_order ASC, si.id ASC
    ");
    $stmt->execute(['role_id' => $role_id]);
    return $stmt->fetchAll();
}
