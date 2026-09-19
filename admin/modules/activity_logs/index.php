<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('activity_logs');

$page_title = 'Activity History & Audit Trail';
$page_subtitle = 'Live tracking of all admin panel user activities, content edits, creations, deletions, and auth events';
$active_page_key = 'activity_logs';

$db = get_db_connection();
sode_ensure_activity_log_system($db);
if (function_exists('sode_prune_activity_logs')) {
    sode_prune_activity_logs($db, 100);
}

$is_super = is_superadmin();

// Fetch filter parameters
$user_id_filter  = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$action_filter   = trim($_GET['action_type'] ?? '');
$module_filter   = trim($_GET['module_key'] ?? '');
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to'] ?? '');
$search          = trim($_GET['q'] ?? '');
$page            = max(1, (int)($_GET['p'] ?? 1));
$per_page        = 25;
$offset          = ($page - 1) * $per_page;

// Build WHERE query
$where = [];
$params = [];

if ($user_id_filter > 0) {
    $where[] = "user_id = :user_id";
    $params[':user_id'] = $user_id_filter;
}
if (!empty($action_filter) && $action_filter !== 'all') {
    if ($action_filter === 'AUTH') {
        $where[] = "action_type IN ('LOGIN', 'LOGOUT', 'PASSWORD_CHANGE')";
    } else {
        $where[] = "action_type = :action_type";
        $params[':action_type'] = strtoupper($action_filter);
    }
}
if (!empty($module_filter) && $module_filter !== 'all') {
    $where[] = "module_key = :module_key";
    $params[':module_key'] = strtolower($module_filter);
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
    $where[] = "(description LIKE :s1 OR item_title LIKE :s2 OR user_name LIKE :s3 OR user_email LIKE :s4 OR ip_address LIKE :s5)";
    $s_val = '%' . $search . '%';
    $params[':s1'] = $s_val;
    $params[':s2'] = $s_val;
    $params[':s3'] = $s_val;
    $params[':s4'] = $s_val;
    $params[':s5'] = $s_val;
}

$where_sql = !empty($where) ? " WHERE " . implode(" AND ", $where) : "";

// Total count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs" . $where_sql);
$count_stmt->execute($params);
$total_records = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));

// Fetch Records
$data_stmt = $db->prepare("
    SELECT * FROM activity_logs 
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
$total_all_logs = (int)$db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$today_logs = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= CURDATE()")->fetchColumn();
$today_creates = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action_type IN ('CREATE', 'RESTORE') AND created_at >= CURDATE()")->fetchColumn();
$today_updates = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action_type = 'UPDATE' AND created_at >= CURDATE()")->fetchColumn();
$today_deletes = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action_type IN ('DELETE', 'PURGE') AND created_at >= CURDATE()")->fetchColumn();

// Fetch distinct users & modules for filters
$all_users = $db->query("SELECT id, name, email, username FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_modules = $db->query("SELECT DISTINCT module_key FROM activity_logs ORDER BY module_key ASC")->fetchAll(PDO::FETCH_COLUMN);

// Helper for action badge colors in Dark Theme
function get_action_badge_style($action) {
    switch (strtoupper($action)) {
        case 'CREATE':
        case 'RESTORE':
            return ['bg' => 'rgba(16, 185, 129, 0.15)', 'color' => '#34d399', 'border' => 'rgba(16, 185, 129, 0.35)'];
        case 'UPDATE':
            return ['bg' => 'rgba(59, 130, 246, 0.15)', 'color' => '#60a5fa', 'border' => 'rgba(59, 130, 246, 0.35)'];
        case 'DELETE':
        case 'PURGE':
            return ['bg' => 'rgba(239, 68, 68, 0.15)', 'color' => '#f87171', 'border' => 'rgba(239, 68, 68, 0.35)'];
        case 'LOGIN':
        case 'LOGOUT':
            return ['bg' => 'rgba(168, 85, 247, 0.15)', 'color' => '#c084fc', 'border' => 'rgba(168, 85, 247, 0.35)'];
        case 'STATUS_CHANGE':
            return ['bg' => 'rgba(245, 158, 11, 0.15)', 'color' => '#fbbf24', 'border' => 'rgba(245, 158, 11, 0.35)'];
        case 'PASSWORD_CHANGE':
            return ['bg' => 'rgba(236, 72, 153, 0.15)', 'color' => '#f472b6', 'border' => 'rgba(236, 72, 153, 0.35)'];
        default:
            return ['bg' => 'rgba(100, 116, 139, 0.15)', 'color' => '#94a3b8', 'border' => 'rgba(100, 116, 139, 0.35)'];
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

<!-- Top Action Header & Quick Categories -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span class="section-heading-sm" style="margin-bottom:0;">
            Audit Logs
            <span class="badge badge-info" style="margin-left:6px; font-size:11px;"><?php echo number_format($total_all_logs); ?> Total (Latest 100 Auto-Retained)</span>
        </span>
    </div>
    
    <div style="display:flex; gap:10px; align-items:center;">
        <button type="button" onclick="openExportModal()" class="btn-primary btn-sm" style="padding:9px 18px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:8px; border-radius:var(--radius-md); box-shadow:0 4px 14px rgba(79,70,229,0.35);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            Export CSV Report
        </button>
    </div>
</div>

<!-- 1. Stats Counter Grid (Matching Dashboard Style) -->
<div class="stats-grid" style="margin-bottom:20px;">
    <!-- Stat 1: Today Actions -->
    <div class="stat-card accent-indigo">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Total Actions Today</div>
                    <div class="stat-number"><?php echo number_format($today_logs); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#6366f1; background:rgba(99,102,241,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
            </div>
            <div class="stat-sub">Administrative events logged</div>
        </div>
    </div>

    <!-- Stat 2: Creates -->
    <div class="stat-card accent-emerald">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Created / Restored</div>
                    <div class="stat-number" style="color:#10b981;"><?php echo number_format($today_creates); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#10b981; background:rgba(16,185,129,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </div>
            </div>
            <div class="stat-sub">New records & restored items</div>
        </div>
    </div>

    <!-- Stat 3: Updates -->
    <div class="stat-card accent-blue">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Edits & Updates</div>
                    <div class="stat-number" style="color:#3b82f6;"><?php echo number_format($today_updates); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#3b82f6; background:rgba(59,130,246,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </div>
            </div>
            <div class="stat-sub">Field modifications recorded</div>
        </div>
    </div>

    <!-- Stat 4: Deletions -->
    <div class="stat-card accent-rose">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Deletions / Trash</div>
                    <div class="stat-number" style="color:#ef4444;"><?php echo number_format($today_deletes); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#ef4444; background:rgba(239,68,68,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </div>
            </div>
            <div class="stat-sub">Archived or removed data</div>
        </div>
    </div>
</div>

<!-- 2. Action Category Filter Tabs -->
<div class="shortcode-cat-filters" style="margin-bottom:18px;">
    <a href="?<?php echo http_build_query(array_merge($_GET, ['action_type' => '', 'p' => 1])); ?>" class="sc-filter-btn <?php echo empty($action_filter) || $action_filter === 'all' ? 'active' : ''; ?>">
        All Events (<?php echo number_format($total_all_logs); ?>)
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['action_type' => 'UPDATE', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $action_filter === 'UPDATE' ? 'active' : ''; ?>">
        ✏️ Modifications
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['action_type' => 'CREATE', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $action_filter === 'CREATE' ? 'active' : ''; ?>">
        ➕ Creations
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['action_type' => 'DELETE', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $action_filter === 'DELETE' ? 'active' : ''; ?>">
        🗑️ Deletions
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['action_type' => 'RESTORE', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $action_filter === 'RESTORE' ? 'active' : ''; ?>">
        ♻️ Restores
    </a>
    <a href="?<?php echo http_build_query(array_merge($_GET, ['action_type' => 'AUTH', 'p' => 1])); ?>" class="sc-filter-btn <?php echo $action_filter === 'AUTH' ? 'active' : ''; ?>">
        🔐 Auth & Logins
    </a>
</div>

<!-- 3. Advanced Filter Card (Structured 2-Row Layout) -->
<div class="admin-card" style="margin-bottom:20px; padding:18px 22px;">
    <form method="GET" style="display:flex; flex-direction:column; gap:14px; margin:0;">
        <!-- Row 1: Search Bar -->
        <div style="display:flex; gap:10px; align-items:center;">
            <div style="position:relative; flex:1;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search activity changes, descriptions, item titles, users, IPs..." class="form-control" style="padding-left:36px; height:38px; font-size:13px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-dim);">
                    <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
            <button type="submit" class="btn-primary btn-sm" style="height:38px; padding:0 20px; font-size:13px; font-weight:700;">Search & Filter</button>
            <?php if ($user_id_filter || $action_filter || $module_filter || $date_from || $date_to || $search): ?>
                <a href="<?php echo BASE_URL; ?>/modules/activity_logs/index.php" class="btn-sm" style="height:38px; padding:0 14px; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.06); border:1px solid var(--border-color); color:var(--text-muted); text-decoration:none; border-radius:var(--radius-md);" title="Reset Filters">
                    Reset
                </a>
            <?php endif; ?>
        </div>

        <!-- Row 2: Secondary Dropdowns Filter Grid -->
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:12px;">
            <div>
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">User</label>
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
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">Action Type</label>
                <select name="action_type" class="form-control" style="height:36px; font-size:12.5px;" onchange="this.form.submit()">
                    <option value="all">All Actions</option>
                    <option value="CREATE" <?php echo $action_filter === 'CREATE' ? 'selected' : ''; ?>>CREATE (Added)</option>
                    <option value="UPDATE" <?php echo $action_filter === 'UPDATE' ? 'selected' : ''; ?>>UPDATE (Modified)</option>
                    <option value="DELETE" <?php echo $action_filter === 'DELETE' ? 'selected' : ''; ?>>DELETE (Trash)</option>
                    <option value="RESTORE" <?php echo $action_filter === 'RESTORE' ? 'selected' : ''; ?>>RESTORE (Recovered)</option>
                    <option value="PURGE" <?php echo $action_filter === 'PURGE' ? 'selected' : ''; ?>>PURGE (Permanent)</option>
                    <option value="LOGIN" <?php echo $action_filter === 'LOGIN' ? 'selected' : ''; ?>>LOGIN (Auth)</option>
                    <option value="LOGOUT" <?php echo $action_filter === 'LOGOUT' ? 'selected' : ''; ?>>LOGOUT</option>
                    <option value="STATUS_CHANGE" <?php echo $action_filter === 'STATUS_CHANGE' ? 'selected' : ''; ?>>STATUS_CHANGE</option>
                    <option value="PASSWORD_CHANGE" <?php echo $action_filter === 'PASSWORD_CHANGE' ? 'selected' : ''; ?>>PASSWORD_CHANGE</option>
                </select>
            </div>

            <div>
                <label style="display:block; font-size:10.5px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:4px; letter-spacing:0.5px;">Module</label>
                <select name="module_key" class="form-control" style="height:36px; font-size:12.5px;" onchange="this.form.submit()">
                    <option value="all">All Modules</option>
                    <?php foreach ($all_modules as $mod): ?>
                        <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo $module_filter === $mod ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $mod))); ?>
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

<!-- 4. Activity Logs Feed Table -->
<div class="admin-card card-overflow-hidden">
    <div class="card-header">
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="card-title">Audit Logs Feed</span>
            <span class="badge badge-info"><?php echo number_format(min($total_records, count($logs))); ?> of <?php echo number_format($total_records); ?></span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:20%;">User & Role</th>
                    <th style="width:12%;">Action</th>
                    <th style="width:18%;">Module / Target</th>
                    <th style="width:30%;">Activity Summary</th>
                    <th style="width:12%;">Date & Time</th>
                    <th style="width:8%; text-align:right;">IP / Device</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:50px 16px; color:var(--text-dim);">
                            <div style="width:48px; height:48px; border-radius:50%; background:rgba(255,255,255,0.04); display:flex; align-items:center; justify-content:center; margin:0 auto 12px; color:var(--text-dim);">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="12" y1="8" x2="12" y2="12"></line>
                                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                </svg>
                            </div>
                            <div style="font-weight:700; font-size:15px; color:var(--text-main);">No activity records found</div>
                            <div style="font-size:12.5px; margin-top:4px; color:var(--text-dim);">Try modifying or clearing your active filters.</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $badge = get_action_badge_style($log['action_type']);
                        $initials = get_user_initials($log['user_name'] ?? 'U');
                        $has_diff = !empty($log['old_values']) || !empty($log['new_values']);
                    ?>
                        <tr>
                            <!-- User -->
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div style="width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                        <?php echo htmlspecialchars($initials); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:700; color:var(--text-main); font-size:13px; line-height:1.2;">
                                            <?php echo htmlspecialchars($log['user_name'] ?: 'System'); ?>
                                        </div>
                                        <div style="margin-top:2px; display:flex; align-items:center; gap:4px;">
                                            <span style="display:inline-block; background:rgba(99,102,241,0.14); color:#818cf8; padding:1px 6px; border-radius:4px; font-weight:600; font-size:10px; border:1px solid rgba(99,102,241,0.25);">
                                                <?php echo htmlspecialchars($log['role_name'] ?: 'User'); ?>
                                            </span>
                                            <?php if (!empty($log['team_name']) && $log['team_name'] !== 'General'): ?>
                                                <span style="font-size:10px; color:var(--text-dim);"><?php echo htmlspecialchars($log['team_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Action -->
                            <td>
                                <span style="display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:12px; font-weight:700; font-size:11px; background:<?php echo $badge['bg']; ?>; color:<?php echo $badge['color']; ?>; border:1px solid <?php echo $badge['border']; ?>;">
                                    <?php echo htmlspecialchars($log['action_type']); ?>
                                </span>
                            </td>

                            <!-- Module / Target -->
                            <td>
                                <div style="font-weight:600; color:var(--text-main); font-size:12.5px;">
                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $log['module_key']))); ?>
                                </div>
                                <?php if (!empty($log['item_title'])): ?>
                                    <div style="font-size:11px; color:var(--text-dim); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; margin-top:1px;" title="<?php echo htmlspecialchars($log['item_title']); ?>">
                                        🏷️ <?php echo htmlspecialchars($log['item_title']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- Description -->
                            <td>
                                <div style="color:var(--text-main); font-size:12.5px; line-height:1.4;">
                                    <?php echo htmlspecialchars($log['description']); ?>
                                </div>
                                <?php if ($has_diff): ?>
                                    <button type="button" onclick="showDiffModal(<?php echo htmlspecialchars(json_encode($log)); ?>)" style="background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.3); color:#818cf8; padding:3px 8px; border-radius:5px; font-size:10.5px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px; margin-top:5px; transition:all 0.2s ease;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        View Changed Diff
                                    </button>
                                <?php endif; ?>
                            </td>

                            <!-- Date & Time -->
                            <td style="white-space:nowrap;">
                                <div style="font-weight:600; color:var(--text-main); font-size:12px;">
                                    <?php echo date('d M Y, h:i A', strtotime($log['created_at'])); ?>
                                </div>
                                <div style="font-size:10.5px; color:var(--text-dim); margin-top:2px;">
                                    ⏱️ <?php echo time_ago_str($log['created_at']); ?>
                                </div>
                            </td>

                            <!-- IP / Device -->
                            <td style="text-align:right;">
                                <div style="font-family:monospace; font-size:10.5px; color:var(--text-muted); background:var(--bg-input); border:1px solid var(--border-color); padding:2px 6px; border-radius:4px; display:inline-block;">
                                    <?php echo htmlspecialchars($log['ip_address'] ?: '127.0.0.1'); ?>
                                </div>
                                <?php if (!empty($log['user_agent'])): ?>
                                    <div style="font-size:9.5px; color:var(--text-dim); margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:110px; margin-left:auto;" title="<?php echo htmlspecialchars($log['user_agent']); ?>">
                                        <?php echo htmlspecialchars(substr($log['user_agent'], 0, 18) . '...'); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="padding:14px 20px; border-top:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="font-size:12.5px; color:var(--text-dim);">
                Page <strong style="color:var(--text-main);"><?php echo $page; ?></strong> of <?php echo $total_pages; ?>
            </div>
            <div style="display:flex; gap:6px;">
                <?php if ($page > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => 1])); ?>" class="sc-filter-btn" style="padding:3px 9px; font-size:11.5px;">&laquo; First</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page - 1])); ?>" class="sc-filter-btn" style="padding:3px 9px; font-size:11.5px;">Prev</a>
                <?php endif; ?>

                <span style="padding:3px 10px; border-radius:var(--radius-sm); background:var(--primary); color:#fff; font-weight:700; font-size:11.5px; display:inline-flex; align-items:center;"><?php echo $page; ?></span>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page + 1])); ?>" class="sc-filter-btn" style="padding:3px 9px; font-size:11.5px;">Next</a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $total_pages])); ?>" class="sc-filter-btn" style="padding:3px 9px; font-size:11.5px;">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     CUSTOM EXPORT CSV MODAL (POPUP)
     ============================================================ -->
<div id="exportModal" style="display:none; position:fixed; inset:0; background:rgba(5, 8, 16, 0.85); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); width:100%; max-width:680px; max-height:92vh; border-radius:var(--radius-lg); box-shadow:0 25px 60px rgba(0,0,0,0.6); display:flex; flex-direction:column; overflow:hidden;">
        <!-- Modal Header -->
        <div style="padding:18px 24px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.02);">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:34px; height:34px; border-radius:8px; background:rgba(99,102,241,0.14); color:#818cf8; display:flex; align-items:center; justify-content:center;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                </div>
                <div>
                    <div style="font-size:16px; font-weight:700; color:var(--text-main);">Export Custom CSV Report</div>
                    <div style="font-size:11.5px; color:var(--text-dim); margin-top:1px;">Configure the exact date range, user actions, and columns to download</div>
                </div>
            </div>
            <button type="button" onclick="closeExportModal()" style="background:rgba(255,255,255,0.06); border:1px solid var(--border-color); width:30px; height:30px; border-radius:50%; font-size:16px; font-weight:bold; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted);">&times;</button>
        </div>

        <!-- Modal Body Form -->
        <form id="exportCustomForm" method="GET" action="<?php echo BASE_URL; ?>/modules/activity_logs/export.php" style="padding:22px; overflow-y:auto; flex:1; display:flex; flex-direction:column; gap:16px; margin:0;">
            <!-- 1. Quick Date Presets -->
            <div>
                <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:8px; letter-spacing:0.5px;">1. Quick Time Preset</label>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="exp-preset-btn" onclick="setExportPreset('all', this)" style="padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:rgba(99,102,241,0.18); border:1px solid rgba(99,102,241,0.4); color:#a5b4fc;">All Available (Latest 100)</button>
                    <button type="button" class="exp-preset-btn" onclick="setExportPreset('today', this)" style="padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:var(--text-muted);">Today Only</button>
                    <button type="button" class="exp-preset-btn" onclick="setExportPreset('yesterday', this)" style="padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:var(--text-muted);">Yesterday</button>
                    <button type="button" class="exp-preset-btn" onclick="setExportPreset('7days', this)" style="padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:var(--text-muted);">Last 7 Days</button>
                    <button type="button" class="exp-preset-btn" onclick="setExportPreset('30days', this)" style="padding:6px 12px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; background:rgba(255,255,255,0.05); border:1px solid var(--border-color); color:var(--text-muted);">Last 30 Days</button>
                </div>
            </div>

            <!-- 2. Custom Date Range Pickers -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">Date From</label>
                    <input type="date" name="date_from" id="exp_date_from" value="" class="form-control" style="height:38px; font-size:13px;">
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">Date To</label>
                    <input type="date" name="date_to" id="exp_date_to" value="" class="form-control" style="height:38px; font-size:13px;">
                </div>
            </div>

            <!-- 3. Granular Filter Options -->
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">2. User</label>
                    <select name="user_id" class="form-control" style="height:38px; font-size:12.5px;">
                        <option value="">All Users</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $user_id_filter == $u['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name'] . ' (' . ($u['username'] ?: $u['email']) . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">3. Action Type</label>
                    <select name="action_type" class="form-control" style="height:38px; font-size:12.5px;">
                        <option value="all">All Actions</option>
                        <option value="UPDATE">Updates Only</option>
                        <option value="CREATE">Creations Only</option>
                        <option value="DELETE">Deletions / Trash</option>
                        <option value="RESTORE">Restores</option>
                        <option value="AUTH">Logins & Auth</option>
                        <option value="PASSWORD_CHANGE">Password Resets</option>
                    </select>
                </div>

                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">4. Module</label>
                    <select name="module_key" class="form-control" style="height:38px; font-size:12.5px;">
                        <option value="all">All Modules</option>
                        <?php foreach ($all_modules as $mod): ?>
                            <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo $module_filter === $mod ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $mod))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 4. Limit & Quantity -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">5. Records Limit</label>
                    <select name="export_limit" class="form-control" style="height:38px; font-size:12.5px;">
                        <option value="100" selected>Latest 100 Records (Max Auto-Stored)</option>
                        <option value="50">Latest 50 Records</option>
                        <option value="25">Latest 25 Records</option>
                        <option value="1000">All Matching Filtered Records</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); margin-bottom:5px; letter-spacing:0.5px;">Search Filter (Optional)</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Only matching keyword..." class="form-control" style="height:38px; font-size:12.5px;">
                </div>
            </div>

            <!-- 5. Columns to Include in CSV -->
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); letter-spacing:0.5px; margin:0;">6. Columns to Include</label>
                    <button type="button" onclick="toggleAllCols(true)" style="background:none; border:none; color:#818cf8; font-size:11px; font-weight:600; cursor:pointer;">Select All</button>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:8px; background:var(--bg-input); border:1px solid var(--border-color); padding:12px; border-radius:var(--radius-sm);">
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="created_at" checked> Date & Time
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="user_name" checked> User Name
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="role_name" checked> Role
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="action_type" checked> Action Type
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="module_key" checked> Module
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="item_title" checked> Target Item
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="description" checked> Description
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-main); cursor:pointer;">
                        <input type="checkbox" name="cols[]" value="ip_address" checked> IP Address
                    </label>
                </div>
            </div>

            <!-- Modal Footer Buttons inside form -->
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:8px; padding-top:12px; border-top:1px solid var(--border-color);">
                <button type="button" onclick="closeExportModal()" class="btn-sm" style="padding:8px 18px; border-radius:var(--radius-sm); font-weight:600; cursor:pointer; background:rgba(255,255,255,0.06); border:1px solid var(--border-color); color:var(--text-muted);">Cancel</button>
                <button type="submit" class="btn-primary btn-sm" style="padding:8px 22px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Download Filtered CSV
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     DIFF MODAL
     ============================================================ -->
<div id="diffModal" style="display:none; position:fixed; inset:0; background:rgba(5, 8, 16, 0.85); backdrop-filter:blur(8px); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); width:100%; max-width:860px; max-height:90vh; border-radius:var(--radius-lg); box-shadow:0 25px 60px rgba(0,0,0,0.6); display:flex; flex-direction:column; overflow:hidden;">
        <!-- Modal Header -->
        <div style="padding:18px 24px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.02);">
            <div>
                <div id="diffModalTitle" style="font-size:16px; font-weight:700; color:var(--text-main);">Change Details & Diff</div>
                <div id="diffModalSubtitle" style="font-size:11.5px; color:var(--text-dim); margin-top:2px;"></div>
            </div>
            <button type="button" onclick="closeDiffModal()" style="background:rgba(255,255,255,0.06); border:1px solid var(--border-color); width:30px; height:30px; border-radius:50%; font-size:16px; font-weight:bold; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--text-muted);">&times;</button>
        </div>

        <!-- Modal Body -->
        <div id="diffModalBody" style="padding:22px; overflow-y:auto; flex:1;">
            <!-- Rendered dynamically -->
        </div>

        <!-- Modal Footer -->
        <div style="padding:14px 24px; border-top:1px solid var(--border-color); background:rgba(255,255,255,0.02); display:flex; justify-content:flex-end;">
            <button type="button" onclick="closeDiffModal()" class="btn-primary btn-sm" style="padding:7px 20px; font-size:13px; font-weight:600; cursor:pointer;">Close Viewer</button>
        </div>
    </div>
</div>

<script>
function openExportModal() {
    document.getElementById('exportModal').style.display = 'flex';
}

function closeExportModal() {
    document.getElementById('exportModal').style.display = 'none';
}

function setExportPreset(preset, btn) {
    // Highlight button
    document.querySelectorAll('.exp-preset-btn').forEach(b => {
        b.style.background = 'rgba(255,255,255,0.05)';
        b.style.borderColor = 'var(--border-color)';
        b.style.color = 'var(--text-muted)';
    });
    if (btn) {
        btn.style.background = 'rgba(99,102,241,0.18)';
        btn.style.borderColor = 'rgba(99,102,241,0.4)';
        btn.style.color = '#a5b4fc';
    }

    const today = new Date();
    const formatDate = (d) => d.toISOString().split('T')[0];

    const fromInput = document.getElementById('exp_date_from');
    const toInput = document.getElementById('exp_date_to');

    if (preset === 'today') {
        const tStr = formatDate(today);
        fromInput.value = tStr;
        toInput.value = tStr;
    } else if (preset === 'yesterday') {
        const y = new Date();
        y.setDate(today.getDate() - 1);
        const yStr = formatDate(y);
        fromInput.value = yStr;
        toInput.value = yStr;
    } else if (preset === '7days') {
        const d = new Date();
        d.setDate(today.getDate() - 7);
        fromInput.value = formatDate(d);
        toInput.value = formatDate(today);
    } else if (preset === '30days') {
        const d = new Date();
        d.setDate(today.getDate() - 30);
        fromInput.value = formatDate(d);
        toInput.value = formatDate(today);
    } else if (preset === 'all') {
        fromInput.value = '';
        toInput.value = '';
    }
}

function toggleAllCols(checked) {
    document.querySelectorAll('#exportCustomForm input[name="cols[]"]').forEach(cb => {
        cb.checked = checked;
    });
}

function showDiffModal(log) {
    document.getElementById('diffModalTitle').innerText = `${log.action_type} • ${log.module_key.toUpperCase()} - ${log.item_title || 'Record #' + (log.item_id || '')}`;
    document.getElementById('diffModalSubtitle').innerText = `Executed by ${log.user_name} (${log.role_name}) on ${log.created_at} • IP: ${log.ip_address || '127.0.0.1'}`;

    let oldVal = null;
    let newVal = null;

    try {
        if (log.old_values) oldVal = typeof log.old_values === 'string' ? JSON.parse(log.old_values) : log.old_values;
    } catch(e) { oldVal = log.old_values; }

    try {
        if (log.new_values) newVal = typeof log.new_values === 'string' ? JSON.parse(log.new_values) : log.new_values;
    } catch(e) { newVal = log.new_values; }

    let html = `
        <div style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:18px;">
            <div style="font-size:10.5px; font-weight:700; color:#818cf8; text-transform:uppercase; letter-spacing:0.5px;">Activity Summary</div>
            <div style="font-size:13.5px; font-weight:600; color:var(--text-main); margin-top:3px;">${log.description}</div>
        </div>
    `;

    if (typeof oldVal === 'object' && oldVal !== null && typeof newVal === 'object' && newVal !== null) {
        const allKeys = Array.from(new Set([...Object.keys(oldVal), ...Object.keys(newVal)]));
        
        html += `
            <div style="font-size:12.5px; font-weight:700; color:var(--text-main); margin-bottom:10px;">Field-by-Field Modification Comparison:</div>
            <table style="width:100%; border-collapse:collapse; font-size:12px; border:1px solid var(--border-color); border-radius:6px; overflow:hidden;">
                <thead>
                    <tr style="background:var(--bg-input); text-align:left; color:var(--text-dim);">
                        <th style="padding:9px 12px; border:1px solid var(--border-color); width:24%;">Field</th>
                        <th style="padding:9px 12px; border:1px solid var(--border-color); width:38%; color:#f87171; background:rgba(239,68,68,0.06);">Previous Value (Old)</th>
                        <th style="padding:9px 12px; border:1px solid var(--border-color); width:38%; color:#34d399; background:rgba(16,185,129,0.06);">Updated Value (New)</th>
                    </tr>
                </thead>
                <tbody>
        `;

        allKeys.forEach(key => {
            const vOld = oldVal[key] !== undefined ? (typeof oldVal[key] === 'object' ? JSON.stringify(oldVal[key]) : String(oldVal[key])) : '<em style="color:var(--text-dim);">None</em>';
            const vNew = newVal[key] !== undefined ? (typeof newVal[key] === 'object' ? JSON.stringify(newVal[key]) : String(newVal[key])) : '<em style="color:var(--text-dim);">None</em>';
            const isDiff = vOld !== vNew;

            html += `
                <tr style="background:${isDiff ? 'rgba(99,102,241,0.06)' : 'transparent'}; border-bottom:1px solid var(--border-color);">
                    <td style="padding:9px 12px; border:1px solid var(--border-color); font-weight:700; color:var(--text-main);">
                        ${key} ${isDiff ? '<span style="font-size:9.5px; background:rgba(234,179,8,0.18); color:#facc15; border:1px solid rgba(234,179,8,0.3); padding:1px 5px; border-radius:3px; margin-left:4px;">Modified</span>' : ''}
                    </td>
                    <td style="padding:9px 12px; border:1px solid var(--border-color); color:#fca5a5; word-break:break-word; font-family:monospace;">
                        ${escapeHtml(vOld)}
                    </td>
                    <td style="padding:9px 12px; border:1px solid var(--border-color); color:#6ee7b7; word-break:break-word; font-family:monospace; font-weight:${isDiff ? '700' : 'normal'};">
                        ${escapeHtml(vNew)}
                    </td>
                </tr>
            `;
        });

        html += `</tbody></table>`;
    } else {
        html += `
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div style="background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-sm); padding:12px;">
                    <div style="font-weight:700; font-size:11.5px; color:#f87171; margin-bottom:5px;">Previous State (Old):</div>
                    <pre style="font-size:10.5px; white-space:pre-wrap; word-break:break-all; color:#fca5a5; margin:0; font-family:monospace;">${escapeHtml(JSON.stringify(oldVal, null, 2) || 'None')}</pre>
                </div>
                <div style="background:rgba(16,185,129,0.06); border:1px solid rgba(16,185,129,0.2); border-radius:var(--radius-sm); padding:12px;">
                    <div style="font-weight:700; font-size:11.5px; color:#34d399; margin-bottom:5px;">New State (Updated):</div>
                    <pre style="font-size:10.5px; white-space:pre-wrap; word-break:break-all; color:#6ee7b7; margin:0; font-family:monospace;">${escapeHtml(JSON.stringify(newVal, null, 2) || 'None')}</pre>
                </div>
            </div>
        `;
    }

    document.getElementById('diffModalBody').innerHTML = html;
    document.getElementById('diffModal').style.display = 'flex';
}

function closeDiffModal() {
    document.getElementById('diffModal').style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDiffModal();
        closeExportModal();
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
