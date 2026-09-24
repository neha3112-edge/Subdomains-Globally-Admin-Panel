<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('login_logs');

$page_title = 'User Login Logs & Security Trail';
$page_subtitle = 'Complete audit log of all successful logins, failed attempts, and logout events across the admin portal';
$active_page_key = 'login_logs';

$db = get_db_connection();
sode_ensure_login_log_system($db);
if (function_exists('sode_prune_login_logs')) {
    sode_prune_login_logs($db, 500);
}

$is_super = is_superadmin();

// Handle Clear Logs POST (Superadmin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
    if (!$is_super) {
        set_flash('error', 'Only superadmins are permitted to clear login audit logs.');
        redirect(BASE_URL . '/modules/login_logs/index.php');
    }
    check_csrf();
    $clear_type = $_POST['clear_type'] ?? 'older_30';
    if ($clear_type === 'all') {
        $db->exec("TRUNCATE TABLE login_logs");
        set_flash('success', 'All login history logs have been permanently cleared.');
    } elseif ($clear_type === 'failed_only') {
        $db->exec("DELETE FROM login_logs WHERE status = 'FAILED'");
        set_flash('success', 'All failed login attempt logs have been cleared.');
    } else {
        $db->exec("DELETE FROM login_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        set_flash('success', 'Login logs older than 30 days have been cleared.');
    }
    redirect(BASE_URL . '/modules/login_logs/index.php');
}

// Handle Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_stmt = $db->query("
        SELECT id, identifier, user_name, user_email, status, reason, ip_address, browser, platform, created_at 
        FROM login_logs 
        ORDER BY id DESC 
        LIMIT 5000
    ");
    $rows = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="user_login_logs_' . date('Y-m-d_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Log ID', 'Attempted Identifier', 'User Name', 'User Email', 'Status', 'Result / Reason', 'IP Address', 'Browser', 'Platform / OS', 'Date & Time']);
    foreach ($rows as $r) {
        fputcsv($output, [
            $r['id'],
            $r['identifier'],
            $r['user_name'] ?? 'N/A',
            $r['user_email'] ?? 'N/A',
            $r['status'],
            $r['reason'] ?? '',
            $r['ip_address'],
            $r['browser'],
            $r['platform'],
            $r['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// Fetch filter parameters
$status_filter = trim($_GET['status'] ?? '');
$user_id_filter = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$date_from     = trim($_GET['date_from'] ?? '');
$date_to       = trim($_GET['date_to'] ?? '');
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['p'] ?? 1));
$per_page      = 30;
$offset        = ($page - 1) * $per_page;

// Build WHERE query
$where = [];
$params = [];

if (!empty($status_filter) && $status_filter !== 'all') {
    $where[] = "status = :status";
    $params[':status'] = strtoupper($status_filter);
}
if ($user_id_filter > 0) {
    $where[] = "user_id = :user_id";
    $params[':user_id'] = $user_id_filter;
}
if (!empty($date_from)) {
    $where[] = "created_at >= :date_from";
    $params[':date_from'] = $date_from . ' 00:00:00';
}
if (!empty($date_to)) {
    $where[] = "created_at <= :date_to";
    $params[':date_to'] = $date_to . ' 23:59:59';
}
if (!empty($search)) {
    $where[] = "(identifier LIKE :s1 OR user_name LIKE :s2 OR user_email LIKE :s3 OR ip_address LIKE :s4 OR reason LIKE :s5 OR browser LIKE :s6 OR platform LIKE :s7)";
    $s_val = '%' . $search . '%';
    $params[':s1'] = $s_val;
    $params[':s2'] = $s_val;
    $params[':s3'] = $s_val;
    $params[':s4'] = $s_val;
    $params[':s5'] = $s_val;
    $params[':s6'] = $s_val;
    $params[':s7'] = $s_val;
}

$where_sql = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

// Count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM login_logs" . $where_sql);
$count_stmt->execute($params);
$total_records = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));

// Fetch Records
$data_stmt = $db->prepare("
    SELECT * FROM login_logs 
    " . $where_sql . " 
    ORDER BY id DESC 
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $data_stmt->bindValue($k, $v);
}
$data_stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$data_stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$data_stmt->execute();
$logs = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics Overview
$total_all_logins   = (int)$db->query("SELECT COUNT(*) FROM login_logs")->fetchColumn();
$today_all_logins   = (int)$db->query("SELECT COUNT(*) FROM login_logs WHERE created_at >= CURDATE()")->fetchColumn();
$today_success      = (int)$db->query("SELECT COUNT(*) FROM login_logs WHERE status = 'SUCCESS' AND created_at >= CURDATE()")->fetchColumn();
$today_failed       = (int)$db->query("SELECT COUNT(*) FROM login_logs WHERE status = 'FAILED' AND created_at >= CURDATE()")->fetchColumn();
$today_unique_ips   = (int)$db->query("SELECT COUNT(DISTINCT ip_address) FROM login_logs WHERE created_at >= CURDATE()")->fetchColumn();

// Fetch distinct users for filter dropdown
$all_users = $db->query("SELECT id, name, email, username FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Helper for status badge
function get_login_status_badge($status) {
    switch (strtoupper($status)) {
        case 'SUCCESS':
            return [
                'bg' => 'rgba(16, 185, 129, 0.15)',
                'color' => '#34d399',
                'border' => 'rgba(16, 185, 129, 0.35)',
                'label' => 'Success',
                'icon' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>'
            ];
        case 'FAILED':
            return [
                'bg' => 'rgba(239, 68, 68, 0.15)',
                'color' => '#f87171',
                'border' => 'rgba(239, 68, 68, 0.35)',
                'label' => 'Failed',
                'icon' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>'
            ];
        case 'LOGOUT':
            return [
                'bg' => 'rgba(168, 85, 247, 0.15)',
                'color' => '#c084fc',
                'border' => 'rgba(168, 85, 247, 0.35)',
                'label' => 'Logged Out',
                'icon' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>'
            ];
        default:
            return [
                'bg' => 'rgba(100, 116, 139, 0.15)',
                'color' => '#94a3b8',
                'border' => 'rgba(100, 116, 139, 0.35)',
                'label' => $status,
                'icon' => ''
            ];
    }
}

// Relative time helper
function time_ago_str($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M, h:i A', $time);
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Top Header & Actions -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span class="section-heading-sm" style="margin-bottom:0;">
            User Login Logs
            <span class="badge badge-info" style="margin-left:6px; font-size:11px;"><?php echo number_format($total_all_logins); ?> Total Logs</span>
        </span>
    </div>
    
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="?export=csv" class="btn-primary btn-sm" style="padding:9px 18px; font-size:13px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border-radius:var(--radius-md); box-shadow:0 4px 14px rgba(79,70,229,0.35);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Export CSV
        </a>
        <?php if ($is_super): ?>
            <button type="button" onclick="openClearModal()" class="btn-sm" style="padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; border-radius:var(--radius-md); background:rgba(239,68,68,0.12); color:#f87171; border:1px solid rgba(239,68,68,0.28);">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Clear Logs
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- 1. Stats Grid -->
<div class="stats-grid" style="margin-bottom:20px;">
    <!-- Stat 1: Total Attempts Today -->
    <div class="stat-card accent-indigo">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Total Attempts Today</div>
                    <div class="stat-number"><?php echo number_format($today_all_logins); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#6366f1; background:rgba(99,102,241,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
                </div>
            </div>
            <div class="stat-sub">Login requests processed today</div>
        </div>
    </div>

    <!-- Stat 2: Success Today -->
    <div class="stat-card accent-emerald">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Successful Logins</div>
                    <div class="stat-number" style="color:#10b981;"><?php echo number_format($today_success); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#10b981; background:rgba(16,185,129,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
            </div>
            <div class="stat-sub">Authenticated sessions today</div>
        </div>
    </div>

    <!-- Stat 3: Failed Attempts -->
    <div class="stat-card accent-rose">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Failed Attempts</div>
                    <div class="stat-number" style="color:#ef4444;"><?php echo number_format($today_failed); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#ef4444; background:rgba(239,68,68,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                </div>
            </div>
            <div class="stat-sub">Invalid passwords / users today</div>
        </div>
    </div>

    <!-- Stat 4: Unique IPs Today -->
    <div class="stat-card accent-blue">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Unique Active IPs</div>
                    <div class="stat-number" style="color:#3b82f6;"><?php echo number_format($today_unique_ips); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#3b82f6; background:rgba(59,130,246,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                </div>
            </div>
            <div class="stat-sub">Distinct client addresses</div>
        </div>
    </div>
</div>

<!-- 2. Status Category Filter Tabs -->
<div class="shortcode-cat-filters" style="margin-bottom:18px;">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => '', 'p' => 1])); ?>" class="sc-filter-btn <?php echo empty($status_filter) || $status_filter === 'all' ? 'active' : ''; ?>">
        All Login Events (<?php echo number_format($total_all_logins); ?>)
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'SUCCESS', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $status_filter === 'SUCCESS' ? 'active' : ''; ?>">
        ✅ Successful Logins
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'FAILED', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $status_filter === 'FAILED' ? 'active' : ''; ?>">
        ❌ Failed Attempts
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'LOGOUT', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $status_filter === 'LOGOUT' ? 'active' : ''; ?>">
        🚪 User Logouts
    </a>
</div>

<!-- 3. Advanced Filter Card -->
<div class="admin-card" style="margin-bottom:20px; padding:18px 22px;">
    <form method="GET" style="display:flex; flex-direction:column; gap:14px; margin:0;">
        <!-- Row 1: Search Bar -->
        <div style="display:flex; gap:10px; align-items:center;">
            <div style="position:relative; flex:1;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search attempted email/username, name, IP address, device, failure reason..." class="form-control" style="padding-left:36px; height:38px; font-size:13px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-dim);">
                    <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
            <button type="submit" class="btn-primary btn-sm" style="height:38px; padding:0 20px; font-size:13px; font-weight:700;">Search & Filter</button>
            <?php if ($status_filter || $user_id_filter || $date_from || $date_to || $search): ?>
                <a href="<?php echo BASE_URL; ?>/modules/login_logs/index.php" class="btn-sm" style="height:38px; padding:0 14px; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.06); border:1px solid var(--border-color); color:var(--text-muted); text-decoration:none; border-radius:var(--radius-md);" title="Reset Filters">
                    Reset
                </a>
            <?php endif; ?>
        </div>

        <!-- Row 2: Secondary Dropdowns Grid -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px;">
            <div>
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">Status</label>
                <select name="status" class="form-control" style="height:36px; font-size:12.5px;" onchange="this.form.submit()">
                    <option value="all">All Statuses</option>
                    <option value="SUCCESS" <?php echo $status_filter === 'SUCCESS' ? 'selected' : ''; ?>>SUCCESS (Logged In)</option>
                    <option value="FAILED" <?php echo $status_filter === 'FAILED' ? 'selected' : ''; ?>>FAILED (Invalid Credentials / Blocked)</option>
                    <option value="LOGOUT" <?php echo $status_filter === 'LOGOUT' ? 'selected' : ''; ?>>LOGOUT (User Signed Out)</option>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">Target User</label>
                <select name="user_id" class="form-control" style="height:36px; font-size:12.5px;" onchange="this.form.submit()">
                    <option value="">All Users</option>
                    <?php foreach ($all_users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $user_id_filter == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['name'] . ' (' . ($u['username'] ?: $u['email']) . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="form-control" style="height:36px; font-size:12.5px;" onchange="this.form.submit()">
            </div>

            <div>
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="form-control" style="height:36px; font-size:12.5px;" onchange="this.form.submit()">
            </div>
        </div>
    </form>
</div>

<!-- 4. Login Logs Feed Table -->
<div class="admin-card card-overflow-hidden">
    <div class="card-header">
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="card-title">User Login Audit Feed</span>
            <span class="badge badge-info"><?php echo number_format(min($total_records, count($logs))); ?> of <?php echo number_format($total_records); ?></span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:12%;">Status</th>
                    <th style="width:24%;">Attempted Identifier / User</th>
                    <th style="width:22%;">Result & Event Details</th>
                    <th style="width:18%;">Browser & Platform</th>
                    <th style="width:12%;">Date & Time</th>
                    <th style="width:12%; text-align:right;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:50px 16px; color:var(--text-dim);">
                            <div style="width:48px; height:48px; border-radius:50%; background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; margin:0 auto 12px; color:var(--text-dim);">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line>
                                </svg>
                            </div>
                            <div style="font-weight:700; font-size:15px; color:var(--text-main);">No login records found</div>
                            <div style="font-size:12.5px; margin-top:4px;">No user login or authentication attempts match your current filters.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $badge = get_login_status_badge($log['status']);
                    ?>
                        <tr class="log-row">
                            <!-- Status Badge -->
                            <td>
                                <span style="display:inline-flex; align-items:center; gap:5px; padding:4px 9px; font-size:11.5px; font-weight:700; border-radius:20px; background:<?php echo $badge['bg']; ?>; color:<?php echo $badge['color']; ?>; border:1px solid <?php echo $badge['border']; ?>;">
                                    <?php echo $badge['icon']; ?>
                                    <?php echo $badge['label']; ?>
                                </span>
                            </td>

                            <!-- User & Identifier -->
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div style="width:34px; height:34px; border-radius:50%; background:<?php echo $log['status'] === 'SUCCESS' ? 'linear-gradient(135deg, #4f46e5, #06b6d4)' : ($log['status'] === 'FAILED' ? 'linear-gradient(135deg, #ef4444, #f97316)' : 'linear-gradient(135deg, #8b5cf6, #ec4899)'); ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; flex-shrink:0;">
                                        <?php 
                                            $initial = strtoupper(substr($log['user_name'] ?: $log['identifier'] ?: 'U', 0, 1));
                                            echo $initial;
                                        ?>
                                    </div>
                                    <div style="min-width:0;">
                                        <?php if (!empty($log['user_name'])): ?>
                                            <div style="font-weight:700; font-size:13px; color:var(--text-main); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?php echo htmlspecialchars($log['user_name']); ?>
                                                <?php if (!empty($log['role_name'])): ?>
                                                    <span style="font-size:10px; padding:1px 5px; border-radius:4px; background:rgba(99,102,241,0.15); color:#818cf8; margin-left:4px; font-weight:600; border:1px solid rgba(99,102,241,0.25);">
                                                        <?php echo htmlspecialchars($log['role_name']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:11.5px; color:var(--text-muted); font-family:monospace; margin-top:1px;">
                                                <?php echo htmlspecialchars($log['user_email'] ?: $log['identifier']); ?>
                                            </div>
                                        <?php else: ?>
                                            <div style="font-weight:700; font-size:13px; color:#f87171; font-family:monospace;">
                                                <?php echo htmlspecialchars($log['identifier']); ?>
                                            </div>
                                            <div style="font-size:11px; color:var(--text-dim); margin-top:1px;">Unrecognized Identifier</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Event / Reason -->
                            <td>
                                <div style="font-weight:600; font-size:12.5px; color:<?php echo $log['status'] === 'SUCCESS' ? '#34d399' : ($log['status'] === 'FAILED' ? '#f87171' : '#c084fc'); ?>;">
                                    <?php echo htmlspecialchars($log['reason'] ?: ($log['status'] === 'SUCCESS' ? 'Login Successful' : 'Auth Event')); ?>
                                </div>
                                <?php if (!empty($log['team_name'])): ?>
                                    <div style="font-size:11px; color:var(--text-dim); margin-top:2px;">
                                        Team: <?php echo htmlspecialchars($log['team_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Browser & Platform -->
                            <td>
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <span style="display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:600; color:var(--text-main); background:var(--bg-input); padding:2px 7px; border-radius:4px; border:1px solid var(--border-color);">
                                        🌐 <?php echo htmlspecialchars($log['browser'] ?: 'Browser'); ?>
                                    </span>
                                    <span style="display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:600; color:var(--text-muted); background:var(--bg-input); padding:2px 7px; border-radius:4px; border:1px solid var(--border-color);">
                                        💻 <?php echo htmlspecialchars($log['platform'] ?: 'OS'); ?>
                                    </span>
                                </div>
                                <div style="font-size:10.5px; color:var(--text-dim); margin-top:3px; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>">
                                    <?php echo htmlspecialchars(substr($log['user_agent'] ?? '', 0, 45) . (strlen($log['user_agent'] ?? '') > 45 ? '...' : '')); ?>
                                </div>
                            </td>

                            <!-- Date & Time -->
                            <td>
                                <div style="font-size:12.5px; font-weight:600; color:var(--text-main);">
                                    <?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?>
                                </div>
                                <div style="font-size:11px; color:var(--text-dim); margin-top:2px;">
                                    <?php echo time_ago_str($log['created_at']); ?>
                                </div>
                            </td>

                            <!-- IP Address -->
                            <td style="text-align:right;">
                                <div style="display:inline-flex; align-items:center; gap:6px; background:var(--bg-input); padding:3px 8px; border-radius:4px; border:1px solid var(--border-color); font-family:monospace; font-size:12px; color:var(--text-muted);">
                                    <span><?php echo htmlspecialchars($log['ip_address'] ?: '127.0.0.1'); ?></span>
                                    <button type="button" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($log['ip_address'] ?: '127.0.0.1'); ?>'); showToast('IP copied');" style="background:none; border:none; color:var(--text-dim); cursor:pointer; padding:0; display:flex; align-items:center;" title="Copy IP">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="padding:16px 20px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="font-size:12.5px; color:var(--text-muted);">
                Showing page <strong style="color:var(--text-main);"><?php echo $page; ?></strong> of <strong style="color:var(--text-main);"><?php echo $total_pages; ?></strong> (<?php echo number_format($total_records); ?> records)
            </div>
            
            <div style="display:flex; gap:6px;">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => 1])); ?>" class="btn-sm" style="padding:5px 10px; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main); text-decoration:none; border-radius:4px; font-size:12px;">&laquo; First</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page - 1])); ?>" class="btn-sm" style="padding:5px 10px; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main); text-decoration:none; border-radius:4px; font-size:12px;">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php 
                $start_p = max(1, $page - 2);
                $end_p = min($total_pages, $page + 2);
                for ($p_i = $start_p; $p_i <= $end_p; $p_i++): 
                ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $p_i])); ?>" class="btn-sm" style="padding:5px 11px; font-size:12px; font-weight:700; border-radius:4px; text-decoration:none; <?php echo $p_i === $page ? 'background:var(--primary); color:#fff;' : 'background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-muted);'; ?>">
                        <?php echo $p_i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page + 1])); ?>" class="btn-sm" style="padding:5px 10px; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main); text-decoration:none; border-radius:4px; font-size:12px;">Next &rsaquo;</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $total_pages])); ?>" class="btn-sm" style="padding:5px 10px; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main); text-decoration:none; border-radius:4px; font-size:12px;">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Clear Logs Modal (Superadmin Only) -->
<?php if ($is_super): ?>
<div id="clearModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center; padding:16px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); width:100%; max-width:480px; box-shadow:0 20px 40px rgba(0,0,0,0.5); overflow:hidden;">
        <div style="padding:18px 22px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:32px; height:32px; border-radius:50%; background:rgba(239,68,68,0.15); color:#f87171; display:flex; align-items:center; justify-content:center;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </div>
                <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main);">Clear Login Audit Logs</h3>
            </div>
            <button type="button" onclick="closeClearModal()" style="background:none; border:none; color:var(--text-dim); font-size:20px; cursor:pointer; line-height:1;">&times;</button>
        </div>

        <form method="POST" style="margin:0;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_logs">
            
            <div style="padding:22px;">
                <p style="font-size:13.5px; color:var(--text-muted); margin:0 0 18px; line-height:1.5;">
                    Select the log cleanup policy to purge authentication audit records from the database:
                </p>

                <div style="display:flex; flex-direction:column; gap:10px;">
                    <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-input); cursor:pointer;">
                        <input type="radio" name="clear_type" value="older_30" checked style="margin-top:3px;">
                        <div>
                            <strong style="display:block; font-size:13px; color:var(--text-main);">Prune logs older than 30 days</strong>
                            <span style="font-size:11.5px; color:var(--text-muted);">Recommended. Preserves recent 30-day security history while freeing DB space.</span>
                        </div>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; border-radius:var(--radius-md); border:1px solid var(--border-color); background:var(--bg-input); cursor:pointer;">
                        <input type="radio" name="clear_type" value="failed_only" style="margin-top:3px;">
                        <div>
                            <strong style="display:block; font-size:13px; color:var(--text-main);">Clear only failed login attempts</strong>
                            <span style="font-size:11.5px; color:var(--text-muted);">Deletes only failed attempts, keeping all successful login records.</span>
                        </div>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; border-radius:var(--radius-md); border:1px solid rgba(239,68,68,0.3); background:rgba(239,68,68,0.06); cursor:pointer;">
                        <input type="radio" name="clear_type" value="all" style="margin-top:3px;">
                        <div>
                            <strong style="display:block; font-size:13px; color:#f87171;">Purge ALL login audit logs (Truncate)</strong>
                            <span style="font-size:11.5px; color:var(--text-muted);">Wipes all historical login data permanently. Action cannot be undone.</span>
                        </div>
                    </label>
                </div>
            </div>

            <div style="padding:16px 22px; border-top:1px solid var(--border-color); background:var(--bg-input); display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeClearModal()" class="btn-sm" style="padding:8px 16px; border:1px solid var(--border-color); background:transparent; color:var(--text-muted); cursor:pointer; border-radius:var(--radius-md);">Cancel</button>
                <button type="submit" class="btn-sm" style="padding:8px 18px; font-weight:700; background:#ef4444; color:#fff; border:none; cursor:pointer; border-radius:var(--radius-md); box-shadow:0 4px 12px rgba(239,68,68,0.35);">Confirm Cleanup</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Toast Notification -->
<div id="toastMessage" style="display:none; position:fixed; bottom:24px; right:24px; background:#10b981; color:#fff; padding:10px 18px; border-radius:6px; font-size:13px; font-weight:700; box-shadow:0 6px 20px rgba(0,0,0,0.35); z-index:999999; animation:fadeIn 0.2s ease;"></div>

<script>
function showToast(msg) {
    const t = document.getElementById('toastMessage');
    t.innerText = msg;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 2200);
}

function openClearModal() {
    const m = document.getElementById('clearModal');
    if (m) m.style.display = 'flex';
}

function closeClearModal() {
    const m = document.getElementById('clearModal');
    if (m) m.style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeClearModal();
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
