<?php
/**
 * RBAC (Role-Based Access Control) & Dynamic Sidebar Engine
 */

function is_superadmin() {
    return !empty($_SESSION['is_superadmin']);
}

/**
 * Check if the currently logged in user has permission for a specific action
 * Checks against role_permissions table based on role_id and team_id.
 *
 * @param string $action 'read', 'create', 'update', 'delete', 'write', 'all'
 * @param int|null $team_id Optional team override
 * @return bool
 */
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
    $perm = null;

    if ($team_id > 0) {
        $stmt = $db->prepare("SELECT * FROM role_permissions WHERE role_id = :role_id AND team_id = :team_id LIMIT 1");
        $stmt->execute(['role_id' => $role_id, 'team_id' => $team_id]);
        $perm = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // If no specific team row found, check any team permission for this role
    if (!$perm) {
        $stmt_any = $db->prepare("SELECT * FROM role_permissions WHERE role_id = :role_id ORDER BY can_all DESC, can_write DESC LIMIT 1");
        $stmt_any->execute(['role_id' => $role_id]);
        $perm = $stmt_any->fetch(PDO::FETCH_ASSOC);
    }

    if (!$perm) {
        // Default: If no matrix entry exists, allow read only, disallow write/create/update/delete
        return strtolower($action) === 'read';
    }

    if (!empty($perm['can_all'])) {
        return true;
    }

    $act = strtolower(trim($action));
    if ($act === 'read') {
        return !empty($perm['can_read']) || !empty($perm['can_all']) || !empty($perm['can_write']);
    } elseif ($act === 'create' || $act === 'add') {
        return !empty($perm['can_create']) || !empty($perm['can_write']) || !empty($perm['can_all']);
    } elseif ($act === 'update' || $act === 'edit') {
        return !empty($perm['can_update']) || !empty($perm['can_write']) || !empty($perm['can_all']);
    } elseif ($act === 'delete' || $act === 'purge' || $act === 'trash') {
        return !empty($perm['can_delete']) || !empty($perm['can_all']);
    } elseif ($act === 'write') {
        return !empty($perm['can_write']) || !empty($perm['can_create']) || !empty($perm['can_update']) || !empty($perm['can_all']);
    }

    $col = 'can_' . $act;
    return !empty($perm[$col]);
}

function can_read($team_id = null)   { return user_can('read', $team_id); }
function can_create($team_id = null) { return user_can('create', $team_id); }
function can_update($team_id = null) { return user_can('update', $team_id); }
function can_edit($team_id = null)   { return user_can('update', $team_id); }
function can_delete($team_id = null) { return user_can('delete', $team_id); }
function can_write($team_id = null)  { return user_can('write', $team_id); }

/**
 * Render an Add / Create button or a disabled locked button if user cannot create
 */
function rbac_render_add_button($url, $label = 'Add New', $custom_class = 'btn-primary btn-sm', $custom_style = '') {
    $can = can_create();
    $style_attr = !empty($custom_style) ? ' style="' . htmlspecialchars($custom_style) . '"' : '';
    if ($can) {
        return '<a href="' . htmlspecialchars($url) . '" class="' . htmlspecialchars($custom_class) . '"' . $style_attr . '>'
            . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg> '
            . htmlspecialchars($label)
            . '</a>';
    } else {
        return '<button type="button" class="btn-secondary btn-sm btn-disabled-locked"' . $style_attr . ' disabled title="Access Denied: You do not have Create permission">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg> '
            . htmlspecialchars($label) . ' <span style="font-size:10px; font-weight:700; padding:1px 5px; background:rgba(239,68,68,0.18); color:#f87171; border-radius:4px; margin-left:4px;">Locked</span>'
            . '</button>';
    }
}

/**
 * Render an Edit table action button or a disabled locked button if user cannot update
 */
function rbac_render_edit_button($url, $title = 'Edit', $custom_class = 'action-btn') {
    $can = can_update();
    if ($can) {
        return '<a href="' . htmlspecialchars($url) . '" class="' . htmlspecialchars($custom_class) . '" title="' . htmlspecialchars($title) . '">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>'
            . '</a>';
    } else {
        return '<button type="button" class="' . htmlspecialchars($custom_class) . ' disabled" disabled title="Access Denied: You do not have Edit permission">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>'
            . '</button>';
    }
}

/**
 * Render a Delete table action button or a disabled locked button if user cannot delete
 */
function rbac_render_delete_button($id, $title = 'Delete', $action_name = 'delete', $extra_post_fields = []) {
    $can = can_delete();
    if ($can) {
        $csrf = function_exists('csrf_field') ? csrf_field() : '';
        $html = '<form method="POST" action="" class="confirm-delete" style="display:inline;">'
            . $csrf
            . '<input type="hidden" name="action" value="' . htmlspecialchars($action_name) . '">'
            . '<input type="hidden" name="id" value="' . (int)$id . '">';
        foreach ($extra_post_fields as $k => $v) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
        }
        $html .= '<button type="submit" class="action-btn delete-btn" title="' . htmlspecialchars($title) . '">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>'
            . '</button></form>';
        return $html;
    } else {
        return '<button type="button" class="action-btn delete-btn disabled" disabled title="Access Denied: You do not have Delete permission">'
            . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>'
            . '</button>';
    }
}

/**
 * Require a specific action permission (e.g. 'create', 'update', 'delete')
 * Halts execution with a 403 Access Denied page if user lacks permission.
 */
function require_action_permission($action, $module_key = '') {
    require_login();
    if (is_superadmin()) {
        return true;
    }

    if (!user_can($action)) {
        render_access_denied_page("Your account role does not have '" . strtoupper($action) . "' permission for this section.", $module_key, $action);
    }
}

/**
 * Detect the normalized relative route of the current executing admin script.
 * e.g. "modules/settings/api_health.php", "modules/universities/edit.php", "dashboard.php"
 */
function get_current_admin_route() {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (defined('ADMIN_PATH')) {
        $admin_root = rtrim(str_replace('\\', '/', ADMIN_PATH), '/');
        if (strpos($script, $admin_root) === 0) {
            return ltrim(substr($script, strlen($admin_root)), '/');
        }
    }
    $name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
    if (preg_match('#/admin/(modules/.+|dashboard\.php|change_password\.php)#i', $name, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Find the exact sidebar item that governs a given route or module key.
 * Strictly resolves to a single sidebar_items record without loose folder wildcards.
 * 
 * @param PDO $db
 * @param string $current_route e.g. "modules/settings/api_integrations.php", "modules/universities/edit.php"
 * @param string $module_key Optional explicit key e.g. "api_integrations", "universities"
 * @return array|null The matched sidebar_items record or null
 */
function sode_find_governing_sidebar_item(PDO $db, $current_route, $module_key = '') {
    $current_route = trim(str_replace('\\', '/', $current_route), '/');
    $module_key    = trim($module_key);

    // 1. Direct match on explicit module_key (most specific and canonical)
    if (!empty($module_key)) {
        $stmt = $db->prepare("
            SELECT * FROM sidebar_items 
            WHERE is_active = 1 
              AND (rbac_module_key = :k1 OR active_page_key = :k2)
            ORDER BY is_superadmin_only DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute(['k1' => $module_key, 'k2' => $module_key]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            return $item;
        }
    }

    if (empty($current_route)) {
        return null;
    }

    // 2. Exact match on full page_route (e.g. "modules/settings/api_integrations.php", "modules/universities/index.php")
    $stmt = $db->prepare("
        SELECT * FROM sidebar_items 
        WHERE is_active = 1 AND page_route = :r 
        ORDER BY is_superadmin_only DESC, id ASC 
        LIMIT 1
    ");
    $stmt->execute(['r' => $current_route]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($item) {
        return $item;
    }

    // 3. For sub-pages in dedicated module directories (e.g. "modules/universities/edit.php" -> "modules/universities/index.php")
    // NOTE: Shared container directories like "modules/settings" and "modules/rbac" are strictly excluded
    // because each file inside them is an independent module with its own unique permissions!
    $parent_dir = dirname($current_route);
    if ($parent_dir && !in_array($parent_dir, ['.', 'modules', 'modules/settings', 'modules/rbac'], true)) {
        $index_route = $parent_dir . '/index.php';
        $stmt = $db->prepare("
            SELECT * FROM sidebar_items 
            WHERE is_active = 1 AND page_route = :ir 
            ORDER BY is_superadmin_only DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute(['ir' => $index_route]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            return $item;
        }
    }

    // 4. Try matching active_page_key from basename if valid (e.g. "api_integrations", "whatsapp")
    $base = basename($current_route, '.php');
    if ($base && !in_array($base, ['index', 'edit', 'create', 'export'], true)) {
        $stmt = $db->prepare("
            SELECT * FROM sidebar_items 
            WHERE is_active = 1 
              AND (rbac_module_key = :b1 OR active_page_key = :b2)
            ORDER BY is_superadmin_only DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute(['b1' => $base, 'b2' => $base]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            return $item;
        }
    }

    return null;
}

/**
 * Automatically verify if the logged-in user is authorized to access the current URL/route.
 * If unauthorized, immediately renders the 403 Access Denied page and halts execution.
 */
function check_current_route_access() {
    if (!is_logged_in() || is_superadmin()) {
        return true;
    }

    $current_route = get_current_admin_route();
    if (empty($current_route)) {
        return true;
    }

    // Always allowed utility routes for authenticated users
    $allowed_utility = ['change_password.php', 'logout.php'];
    if (in_array(basename($current_route), $allowed_utility, true)) {
        return true;
    }

    $role_id = (int)($_SESSION['user_role_id'] ?? 0);
    if (!$role_id) {
        render_access_denied_page("Your account does not have an assigned role.", '', 'read');
    }

    try {
        $db = get_db_connection();
        sode_heal_core_sidebar_items($db);

        // Dashboard special handling: verify role has access to dashboard or redirect to their first module
        if (basename($current_route) === 'dashboard.php') {
            $dash_item = $db->query("SELECT id FROM sidebar_items WHERE page_route = 'dashboard.php' OR active_page_key = 'dashboard' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($dash_item) {
                $chk = $db->prepare("SELECT 1 FROM role_sidebar_access WHERE role_id = :role_id AND sidebar_item_id = :item_id LIMIT 1");
                $chk->execute(['role_id' => $role_id, 'item_id' => (int)$dash_item['id']]);
                if (!$chk->fetch()) {
                    // Redirect to first permitted sidebar item if role lacks dashboard access
                    $first = $db->prepare("
                        SELECT si.page_route 
                        FROM sidebar_items si
                        INNER JOIN role_sidebar_access rsa ON si.id = rsa.sidebar_item_id
                        WHERE rsa.role_id = :role_id AND si.is_active = 1 AND si.is_superadmin_only = 0
                        ORDER BY si.sort_order ASC, si.id ASC 
                        LIMIT 1
                    ");
                    $first->execute(['role_id' => $role_id]);
                    $first_route = $first->fetchColumn();
                    if ($first_route && $first_route !== 'dashboard.php') {
                        redirect(BASE_URL . '/' . ltrim($first_route, '/'));
                    } else {
                        render_access_denied_page("Your role does not have access to the Dashboard.", 'Dashboard', 'read');
                    }
                }
            }
            return true;
        }

        // For all other pages, resolve governing sidebar item
        global $active_page_key;
        $mod_key = !empty($active_page_key) ? $active_page_key : '';
        $gov_item = sode_find_governing_sidebar_item($db, $current_route, $mod_key);

        if ($gov_item) {
            // 1. Superadmin-only block
            if (!empty($gov_item['is_superadmin_only'])) {
                render_access_denied_page("The '" . htmlspecialchars($gov_item['display_name']) . "' section is restricted to Super Administrators only.", $gov_item['display_name'], 'read');
            }

            // 2. Strict Role access check: MUST have row in role_sidebar_access
            $stmt = $db->prepare("
                SELECT 1 
                FROM role_sidebar_access 
                WHERE role_id = :role_id AND sidebar_item_id = :item_id 
                LIMIT 1
            ");
            $stmt->execute([
                'role_id' => $role_id,
                'item_id' => (int)$gov_item['id']
            ]);
            if (!$stmt->fetch()) {
                render_access_denied_page("Your role does not have permission to access the '" . htmlspecialchars($gov_item['display_name']) . "' section.", $gov_item['display_name'], 'read');
            }
        } else {
            // Route is inside /modules/ but not mapped to any permitted sidebar item
            render_access_denied_page("You do not have permission to access this section.", basename($current_route, '.php'), 'read');
        }
    } catch (Throwable $e) {
        error_log("Route access check error: " . $e->getMessage());
    }

    return true;
}

/**
 * Require explicit access to a specific module.
 * Called at the top of each module script (e.g. require_permission('api_integrations')).
 */
function require_module_access($module_key) {
    require_login();
    if (is_superadmin()) {
        return true;
    }

    $role_id = (int)($_SESSION['user_role_id'] ?? 0);
    if (!$role_id) {
        render_access_denied_page("You do not have permission to view this module.", $module_key, 'read');
    }

    $db = get_db_connection();
    sode_heal_core_sidebar_items($db);

    $current_route = get_current_admin_route();
    $gov_item = sode_find_governing_sidebar_item($db, $current_route, $module_key);

    if ($gov_item) {
        // 1. Strict superadmin block
        if (!empty($gov_item['is_superadmin_only'])) {
            render_access_denied_page("The '" . htmlspecialchars($gov_item['display_name']) . "' section is restricted to Super Administrators only.", $module_key, 'read');
        }

        // 2. Strict role access check: MUST have row in role_sidebar_access for this exact item
        $stmt = $db->prepare("
            SELECT 1 
            FROM role_sidebar_access 
            WHERE role_id = :role_id AND sidebar_item_id = :item_id 
            LIMIT 1
        ");
        $stmt->execute([
            'role_id' => $role_id,
            'item_id' => (int)$gov_item['id']
        ]);
        if (!$stmt->fetch()) {
            render_access_denied_page("Your role does not have permission to access the '" . htmlspecialchars($gov_item['display_name']) . "' section.", $gov_item['display_name'], 'read');
        }
    } else {
        // If no registered sidebar item matches, non-superadmins are strictly blocked
        render_access_denied_page("You do not have permission to access the '" . htmlspecialchars($module_key) . "' section.", $module_key, 'read');
    }

    // 3. Also check read action permission
    if (!user_can('read')) {
        render_access_denied_page("Your account does not have read access to this section.", $module_key, 'read');
    }

    return true;
}

/**
 * Render a beautiful, premium Access Denied (403) page with Dashboard button
 */
function render_access_denied_page($message = '', $module_key = '', $action = '') {
    if (!headers_sent()) {
        http_response_code(403);
    }
    
    $user = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
    $user_name = $user['name'] ?? 'User';
    $user_role = $user['role_name'] ?? 'Sub Admin';
    $user_initials = function_exists('get_user_initials') ? get_user_initials($user_name) : strtoupper(substr($user_name, 0, 1));
    
    $module_label = !empty($module_key) ? ucwords(str_replace(['_', '-'], ' ', $module_key)) : 'Requested Module';
    $dashboard_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/dashboard.php' : '/admin/dashboard.php';
    $logout_url = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/logout.php' : '/admin/logout.php';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">
    <title>403 - Access Denied | <?php echo defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'SODE Admin'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        var theme = localStorage.getItem('theme') || localStorage.getItem('admin_theme') || 'dark';
        document.documentElement.setAttribute('data-theme', theme);
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        body {
            background-color: #0b0f19;
            background-image: 
                radial-gradient(at 0% 0%, rgba(239, 68, 68, 0.15) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(37, 99, 235, 0.12) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(15, 23, 42, 0.9) 0px, transparent 100%);
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            transition: background 0.3s ease, color 0.3s ease;
        }
        .portal-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            text-decoration: none;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .brand-logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 800;
            font-size: 15px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }
        .error-card {
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            box-shadow: 
                0 25px 60px -15px rgba(0, 0, 0, 0.7),
                0 0 0 1px rgba(239, 68, 68, 0.18),
                0 0 50px -10px rgba(239, 68, 68, 0.2);
            max-width: 520px;
            width: 100%;
            padding: 44px 36px;
            text-align: center;
            position: relative;
            overflow: hidden;
            animation: cardFadeIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes cardFadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .error-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ef4444 0%, #f97316 50%, #ef4444 100%);
        }
        .icon-wrapper {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 88px;
            height: 88px;
            margin-bottom: 22px;
        }
        .icon-glow {
            position: absolute;
            inset: -14px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.4) 0%, transparent 70%);
            border-radius: 50%;
            animation: pulseGlow 2.5s infinite ease-in-out;
        }
        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); opacity: 0.6; }
            50% { transform: scale(1.15); opacity: 0.9; }
        }
        .icon-box {
            position: relative;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.18) 0%, rgba(185, 28, 28, 0.22) 100%);
            border: 1px solid rgba(239, 68, 68, 0.4);
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef4444;
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
        }
        .badge-restricted {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.28);
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        .badge-dot {
            width: 7px;
            height: 7px;
            background-color: #ef4444;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px #ef4444;
        }
        .error-title {
            font-size: 26px;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        .error-desc {
            font-size: 14px;
            line-height: 1.6;
            color: #94a3b8;
            margin-bottom: 22px;
        }
        .error-desc strong {
            color: #ffffff;
            font-weight: 600;
        }
        .module-badge {
            display: inline-block;
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            padding: 1px 8px;
            border-radius: 6px;
            font-weight: 700;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .user-pill {
            background: rgba(30, 41, 59, 0.65);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 10px 16px;
            margin-bottom: 26px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            max-width: 100%;
        }
        .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-transform: uppercase;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.4);
        }
        .user-details {
            text-align: left;
            font-size: 12px;
            line-height: 1.35;
        }
        .user-name {
            font-weight: 600;
            color: #f8fafc;
        }
        .user-role {
            color: #94a3b8;
            font-size: 11px;
        }
        .actions-group {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-primary-dashboard {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 16px rgba(37, 99, 235, 0.4);
            flex: 1;
            min-width: 190px;
        }
        .btn-primary-dashboard:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 22px rgba(37, 99, 235, 0.55);
            color: #ffffff;
        }
        .btn-primary-dashboard:active {
            transform: translateY(0);
        }
        .btn-secondary-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: rgba(30, 41, 59, 0.8);
            color: #cbd5e1;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 120px;
        }
        .btn-secondary-back:hover {
            background: rgba(51, 65, 85, 0.9);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }
        .help-footer {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 12px;
            color: #64748b;
        }
        .help-footer a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 500;
        }
        .help-footer a:hover {
            text-decoration: underline;
        }

        /* Light Theme Support */
        [data-theme="light"] body {
            background-color: #f1f5f9;
            background-image: 
                radial-gradient(at 0% 0%, rgba(239, 68, 68, 0.10) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(37, 99, 235, 0.08) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(248, 250, 252, 0.85) 0px, transparent 100%);
            color: #0f172a;
        }
        [data-theme="light"] .portal-brand {
            color: #0f172a;
        }
        [data-theme="light"] .error-card {
            background: rgba(255, 255, 255, 0.95);
            border-color: rgba(226, 232, 240, 0.9);
            box-shadow: 0 20px 50px -10px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(239, 68, 68, 0.15);
        }
        [data-theme="light"] .error-title {
            color: #0f172a;
        }
        [data-theme="light"] .error-desc {
            color: #475569;
        }
        [data-theme="light"] .error-desc strong {
            color: #0f172a;
        }
        [data-theme="light"] .user-pill {
            background: #f8fafc;
            border-color: #e2e8f0;
        }
        [data-theme="light"] .user-name {
            color: #0f172a;
        }
        [data-theme="light"] .btn-secondary-back {
            background: #ffffff;
            color: #334155;
            border-color: #cbd5e1;
        }
        [data-theme="light"] .btn-secondary-back:hover {
            background: #f8fafc;
            color: #0f172a;
        }
        [data-theme="light"] .help-footer {
            border-top-color: #e2e8f0;
            color: #64748b;
        }
    </style>
</head>
<body>
    <a href="<?php echo htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8'); ?>" class="portal-brand">
        <div class="brand-logo-icon">S</div>
        <span><?php echo defined('APP_NAME') ? htmlspecialchars(APP_NAME) : 'SODE Admin'; ?></span>
    </a>

    <div class="error-card">
        <div class="icon-wrapper">
            <div class="icon-glow"></div>
            <div class="icon-box">
                <!-- Lock Shield Icon -->
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <rect x="9" y="11" width="6" height="5" rx="1"/>
                    <path d="M10 11V9a2 2 0 0 1 4 0v2"/>
                </svg>
            </div>
        </div>

        <div>
            <div class="badge-restricted">
                <span class="badge-dot"></span>
                <span>Restricted Access</span>
            </div>
        </div>

        <h1 class="error-title">Access Denied</h1>

        <p class="error-desc">
            <?php if (!empty($message)): ?>
                <?php echo htmlspecialchars($message); ?><br>
            <?php elseif (!empty($action) && $action !== 'read'): ?>
                You do not have <strong><?php echo strtoupper(htmlspecialchars($action)); ?></strong> permission for the <span class="module-badge"><?php echo htmlspecialchars($module_label); ?></span> module.<br>
            <?php else: ?>
                You do not have permission to access the <span class="module-badge"><?php echo htmlspecialchars($module_label); ?></span> module.<br>
            <?php endif; ?>
            Your account role is restricted by the <strong>Team Permissions Matrix</strong>.
        </p>

        <?php if (!empty($user)): ?>
            <div class="user-pill">
                <div class="user-avatar">
                    <?php echo htmlspecialchars($user_initials); ?>
                </div>
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="user-role">Role: <?php echo htmlspecialchars($user_role); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="actions-group">
            <button type="button" class="btn-secondary-back" onclick="window.history.length > 1 ? window.history.back() : window.location.href='<?php echo htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8'); ?>';">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
                <span>Go Back</span>
            </button>

            <a href="<?php echo htmlspecialchars($dashboard_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn-primary-dashboard">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                <span>Go to Dashboard</span>
            </a>
        </div>

        <div class="help-footer">
            Need access to this module? Contact your <strong style="color:#94a3b8;">Super Administrator</strong>. &bull; <a href="<?php echo htmlspecialchars($logout_url, ENT_QUOTES, 'UTF-8'); ?>">Logout</a>
        </div>
    </div>
</body>
</html>
    <?php
    exit;
}

function require_permission($module_key) {
    return require_module_access($module_key);
}

/**
 * Automatically ensure core sidebar items (Universal News, Site & Brand Logo, etc.)
 * have correct routes, icons, keys and role permissions.
 */
function sode_heal_core_sidebar_items(PDO $db) {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $news_svg   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>';
        $logo_svg   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>';
        $dates_svg  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>';
        $elig_svg   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"></path></svg>';
        $health_svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>';
        $redis_svg  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
        $lead_svg   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>';
        $wa_svg     = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
        $users_svg  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
        $roles_svg  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
        $teams_svg  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>';
        $sb_svg     = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>';

        // Full registry of core settings & access control items with discrete keys
        $core_items = [
            [
                'name' => 'Site & Brand Logo',
                'route' => 'modules/settings/logo.php',
                'active' => 'logo_settings',
                'rbac' => 'logo_settings',
                'section' => 'SETTINGS',
                'sort' => 17,
                'svg' => $logo_svg,
                'superadmin_only' => 0
            ],
            [
                'name' => 'Universal News',
                'route' => 'modules/universal_news/index.php',
                'active' => 'universal_news',
                'rbac' => 'universal_news',
                'section' => 'SETTINGS',
                'sort' => 13,
                'svg' => $news_svg,
                'superadmin_only' => 0
            ],
            [
                'name' => 'Default Dates',
                'route' => 'modules/settings/default_dates.php',
                'active' => 'default_dates',
                'rbac' => 'default_dates',
                'section' => 'SETTINGS',
                'sort' => 18,
                'svg' => $dates_svg,
                'superadmin_only' => 0
            ],
            [
                'name' => 'Default Eligibility',
                'route' => 'modules/settings/default_eligibility.php',
                'active' => 'default_eligibility',
                'rbac' => 'default_eligibility',
                'section' => 'SETTINGS',
                'sort' => 19,
                'svg' => $elig_svg,
                'superadmin_only' => 0
            ],
            [
                'name' => 'Lead & API Settings',
                'route' => 'modules/settings/api_integrations.php',
                'active' => 'api_integrations',
                'rbac' => 'api_integrations',
                'section' => 'SETTINGS',
                'sort' => 20,
                'svg' => $lead_svg,
                'superadmin_only' => 0
            ],
            [
                'name' => 'WhatsApp Settings',
                'route' => 'modules/settings/whatsapp.php',
                'active' => 'whatsapp_settings',
                'rbac' => 'whatsapp',
                'section' => 'SETTINGS',
                'sort' => 21,
                'svg' => $wa_svg,
                'superadmin_only' => 0
            ],
            [
                'name' => 'Education Modes',
                'route' => 'modules/settings/modes.php',
                'active' => 'modes',
                'rbac' => 'modes',
                'section' => 'SETTINGS',
                'sort' => 22,
                'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
                'superadmin_only' => 0
            ],
            [
                'name' => 'Degree Levels',
                'route' => 'modules/settings/levels.php',
                'active' => 'levels',
                'rbac' => 'levels',
                'section' => 'SETTINGS',
                'sort' => 23,
                'svg' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>',
                'superadmin_only' => 0
            ],
            [
                'name' => 'API Health',
                'route' => 'modules/settings/api_health.php',
                'active' => 'api_health',
                'rbac' => 'api_health',
                'section' => 'SETTINGS',
                'sort' => 36,
                'svg' => $health_svg,
                'superadmin_only' => 1
            ],
            [
                'name' => 'Redis Cache',
                'route' => 'modules/settings/redis.php',
                'active' => 'redis_manager',
                'rbac' => 'redis_manager',
                'section' => 'SETTINGS',
                'sort' => 35,
                'svg' => $redis_svg,
                'superadmin_only' => 1
            ],
            [
                'name' => 'Manage Users',
                'route' => 'modules/rbac/users.php',
                'active' => 'users',
                'rbac' => 'users',
                'section' => 'ACCESS CONTROL',
                'sort' => 8,
                'svg' => $users_svg,
                'superadmin_only' => 1
            ],
            [
                'name' => 'Manage Teams',
                'route' => 'modules/rbac/teams.php',
                'active' => 'teams',
                'rbac' => 'teams',
                'section' => 'ACCESS CONTROL',
                'sort' => 9,
                'svg' => $teams_svg,
                'superadmin_only' => 1
            ],
            [
                'name' => 'Manage Roles',
                'route' => 'modules/rbac/roles.php',
                'active' => 'roles',
                'rbac' => 'roles',
                'section' => 'ACCESS CONTROL',
                'sort' => 10,
                'svg' => $roles_svg,
                'superadmin_only' => 1
            ],
            [
                'name' => 'Sidebar Manager',
                'route' => 'modules/rbac/sidebar_manager.php',
                'active' => 'sidebar_manager',
                'rbac' => 'sidebar_manager',
                'section' => 'ACCESS CONTROL',
                'sort' => 11,
                'svg' => $sb_svg,
                'superadmin_only' => 1
            ]
        ];

        $superadmin_roles = [];
        try {
            $r_stmt = $db->query("SELECT id FROM roles WHERE LOWER(name) LIKE '%superadmin%' OR id = 1");
            if ($r_stmt) {
                $superadmin_roles = $r_stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Throwable $re) {}

        foreach ($core_items as $ci) {
            $chk = $db->prepare("SELECT id FROM sidebar_items WHERE page_route = ? OR active_page_key = ? LIMIT 1");
            $chk->execute([$ci['route'], $ci['active']]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $db->prepare("
                    UPDATE sidebar_items 
                    SET display_name = ?,
                        page_route = ?,
                        sort_order = ?,
                        active_page_key = ?,
                        rbac_module_key = ?,
                        menu_section = ?,
                        icon_svg = ?,
                        is_superadmin_only = ?,
                        is_active = 1
                    WHERE id = ?
                ")->execute([
                    $ci['name'], $ci['route'], $ci['sort'], $ci['active'],
                    $ci['rbac'], $ci['section'], $ci['svg'], $ci['superadmin_only'],
                    $existing['id']
                ]);
                $item_id = $existing['id'];
            } else {
                $ins = $db->prepare("
                    INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $ins->execute([
                    $ci['name'], $ci['route'], $ci['sort'], $ci['active'],
                    $ci['rbac'], $ci['section'], $ci['svg'], $ci['superadmin_only']
                ]);
                $item_id = (int)$db->lastInsertId();
            }

            // Always ensure superadmin has access to all core items
            if ($item_id && !empty($superadmin_roles)) {
                $rsa = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                foreach ($superadmin_roles as $s_rid) {
                    $rsa->execute([$s_rid, $item_id]);
                }
            }
        }

        // Clean up: Disallow non-superadmins from superadmin-only modules in role_sidebar_access
        if (!empty($superadmin_roles)) {
            $in_clause = implode(',', array_filter(array_map('intval', $superadmin_roles)));
            if (!empty($in_clause)) {
                $db->exec("
                    DELETE rsa FROM role_sidebar_access rsa
                    JOIN sidebar_items si ON rsa.sidebar_item_id = si.id
                    WHERE si.is_superadmin_only = 1 AND rsa.role_id NOT IN ($in_clause)
                ");
            }
        }

        // Ensure courses table has default_eligibility column
        $col_check = $db->query("SHOW COLUMNS FROM courses LIKE 'default_eligibility'")->fetch();
        if (!$col_check) {
            $db->exec("ALTER TABLE courses ADD COLUMN default_eligibility TEXT NULL AFTER description");
        }
    } catch (Throwable $e) {
        error_log("sode_heal_core_sidebar_items error: " . $e->getMessage());
    }
}

function get_user_sidebar_items() {
    $db = get_db_connection();
    sode_heal_core_sidebar_items($db);

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
