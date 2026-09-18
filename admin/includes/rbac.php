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
        render_access_denied_page("You do not have permission to view this module.", $module_key);
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
        render_access_denied_page("You do not have permission to access the " . htmlspecialchars($module_key) . " module.", $module_key);
    }
}

/**
 * Render a beautiful, premium Access Denied (403) page with Dashboard button
 */
function render_access_denied_page($message = '', $module_key = '') {
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
            You do not have permission to access the <span class="module-badge"><?php echo htmlspecialchars($module_label); ?></span> module.<br>
            Your current account role does not have privileges for this section.
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
