<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('activity_logs');

$db = get_db_connection();

// Fetch filter parameters
$user_id_filter  = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$action_filter   = trim($_GET['action_type'] ?? '');
$module_filter   = trim($_GET['module_key'] ?? '');
$date_from       = trim($_GET['date_from'] ?? '');
$date_to         = trim($_GET['date_to'] ?? '');
$date_preset     = trim($_GET['date_preset'] ?? '');
$search          = trim($_GET['q'] ?? '');
$limit_param     = !empty($_GET['export_limit']) ? (int)$_GET['export_limit'] : 100;
$selected_cols   = $_GET['cols'] ?? [];

// Handle Date Presets if date_from/to not explicitly given
if (!empty($date_preset)) {
    if ($date_preset === 'today') {
        $date_from = date('Y-m-d');
        $date_to = date('Y-m-d');
    } elseif ($date_preset === 'yesterday') {
        $date_from = date('Y-m-d', strtotime('-1 day'));
        $date_to = date('Y-m-d', strtotime('-1 day'));
    } elseif ($date_preset === '7days') {
        $date_from = date('Y-m-d', strtotime('-7 days'));
        $date_to = date('Y-m-d');
    } elseif ($date_preset === '30days') {
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
    }
}

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

$limit_clause = "";
if ($limit_param > 0) {
    $limit_clause = " LIMIT " . min(1000, max(1, $limit_param));
} else {
    $limit_clause = " LIMIT 100";
}

$stmt = $db->prepare("
    SELECT id, user_name, user_email, role_name, team_name, action_type, module_key, item_title, description, ip_address, user_agent, created_at 
    FROM activity_logs 
    " . $where_sql . " 
    ORDER BY id DESC 
    " . $limit_clause
);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = 'sode_activity_report_' . date('Y_m_d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Define Available Columns
$all_column_defs = [
    'id'           => 'Log ID',
    'created_at'   => 'Date & Time',
    'user_name'    => 'User Name',
    'user_email'   => 'User Email',
    'role_name'    => 'Role',
    'team_name'    => 'Team',
    'action_type'  => 'Action Type',
    'module_key'   => 'Module',
    'item_title'   => 'Target Item',
    'description'  => 'Activity Summary / Description',
    'ip_address'   => 'IP Address',
    'user_agent'   => 'Device / User-Agent'
];

// Determine which columns to output
$active_columns = [];
if (!empty($selected_cols) && is_array($selected_cols)) {
    foreach ($selected_cols as $col) {
        if (isset($all_column_defs[$col])) {
            $active_columns[$col] = $all_column_defs[$col];
        }
    }
}

if (empty($active_columns)) {
    // Default columns
    $active_columns = $all_column_defs;
}

// Write Header Row
fputcsv($output, array_values($active_columns));

// Write Data Rows
foreach ($logs as $row) {
    $line = [];
    foreach (array_keys($active_columns) as $col_key) {
        if ($col_key === 'module_key') {
            $line[] = ucwords(str_replace('_', ' ', $row['module_key']));
        } elseif ($col_key === 'user_name') {
            $line[] = $row['user_name'] ?: 'System';
        } elseif ($col_key === 'user_email') {
            $line[] = $row['user_email'] ?: 'N/A';
        } elseif ($col_key === 'item_title') {
            $line[] = $row['item_title'] ?: 'N/A';
        } else {
            $line[] = $row[$col_key] ?? '';
        }
    }
    fputcsv($output, $line);
}

fclose($output);
exit;
