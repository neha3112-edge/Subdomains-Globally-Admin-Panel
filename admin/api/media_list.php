<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = get_db_connection();

$type = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');
$date_filter = trim($_GET['date_filter'] ?? ($_GET['date'] ?? 'all'));
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

// 1. File Type Filter
if (!empty($type) && $type !== 'all') {
    $where[] = "file_type = ?";
    $params[] = $type;
}

// 2. Search Keyword Filter
if (!empty($search)) {
    $where[] = "file_name LIKE ?";
    $params[] = '%' . $search . '%';
}

// 3. Date Filter
if (!empty($date_filter) && $date_filter !== 'all') {
    if ($date_filter === 'today') {
        $where[] = "created_at >= CURDATE()";
    } elseif ($date_filter === 'yesterday') {
        $where[] = "created_at >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND created_at < CURDATE()";
    } elseif ($date_filter === '7days') {
        $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($date_filter === '30days') {
        $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($date_filter === 'this_month') {
        $where[] = "created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')";
    } elseif ($date_filter === 'last_month') {
        $where[] = "created_at >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00'), INTERVAL 1 MONTH) AND created_at < DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')";
    } elseif (preg_match('/^\d{4}-\d{2}$/', $date_filter)) {
        // Specific Month (e.g. 2026-09)
        $start_dt = $date_filter . '-01 00:00:00';
        $end_dt = date('Y-m-t 23:59:59', strtotime($start_dt));
        $where[] = "created_at >= ? AND created_at <= ?";
        $params[] = $start_dt;
        $params[] = $end_dt;
    } elseif ($date_filter === 'custom' && (!empty($date_from) || !empty($date_to))) {
        if (!empty($date_from) && !empty($date_to)) {
            $where[] = "created_at >= ? AND created_at <= ?";
            $params[] = $date_from . ' 00:00:00';
            $params[] = $date_to . ' 23:59:59';
        } elseif (!empty($date_from)) {
            $where[] = "created_at >= ?";
            $params[] = $date_from . ' 00:00:00';
        } elseif (!empty($date_to)) {
            $where[] = "created_at <= ?";
            $params[] = $date_to . ' 23:59:59';
        }
    }
} elseif (!empty($date_from) || !empty($date_to)) {
    // Custom date range passed without date_filter flag
    if (!empty($date_from) && !empty($date_to)) {
        $where[] = "created_at >= ? AND created_at <= ?";
        $params[] = $date_from . ' 00:00:00';
        $params[] = $date_to . ' 23:59:59';
    } elseif (!empty($date_from)) {
        $where[] = "created_at >= ?";
        $params[] = $date_from . ' 00:00:00';
    } elseif (!empty($date_to)) {
        $where[] = "created_at <= ?";
        $params[] = $date_to . ' 23:59:59';
    }
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count Total matching records
$count_stmt = $db->prepare("SELECT COUNT(*) FROM media_library $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

// Fetch Page records
$stmt = $db->prepare("SELECT * FROM media_library $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Fetch available distinct months for UI dropdown
$months_stmt = $db->query("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') as ym, DATE_FORMAT(created_at, '%M %Y') as ym_label FROM media_library WHERE created_at IS NOT NULL ORDER BY ym DESC");
$available_months = $months_stmt ? $months_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

if (!function_exists('format_bytes')) {
    function format_bytes($bytes, $precision = 1) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

$items = [];
foreach ($rows as $r) {
    $path = !empty($r['file_path']) ? $r['file_path'] : $r['file_url'];
    $clean_path = get_relative_asset_path($path);
    $items[] = [
        'id' => (int)$r['id'],
        'file_name' => $r['file_name'],
        'file_path' => $clean_path,
        'file_url' => $clean_path,
        'display_url' => get_asset_url($clean_path),
        'file_type' => $r['file_type'],
        'mime_type' => $r['mime_type'],
        'file_size' => (int)$r['file_size'],
        'formatted_size' => format_bytes($r['file_size']),
        'created_at' => date('d M Y, h:i A', strtotime($r['created_at'])),
        'raw_created_at' => $r['created_at']
    ];
}

echo json_encode([
    'success' => true,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'total_pages' => ceil($total / $limit),
    'items' => $items,
    'available_months' => $available_months
], JSON_UNESCAPED_SLASHES);
