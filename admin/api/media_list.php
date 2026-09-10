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

// Ensure table exists
$db->exec("
    CREATE TABLE IF NOT EXISTS `media_library` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `file_name` VARCHAR(255) NOT NULL,
      `file_path` VARCHAR(255) NOT NULL,
      `file_url` TEXT NOT NULL,
      `file_type` ENUM('image', 'audio', 'video', 'pdf', 'document', 'other') DEFAULT 'image',
      `mime_type` VARCHAR(100) NULL,
      `file_size` INT UNSIGNED DEFAULT 0,
      `uploaded_by` INT UNSIGNED NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_media_type` (`file_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$type = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if (!empty($type) && $type !== 'all') {
    $where[] = "file_type = ?";
    $params[] = $type;
}

if (!empty($search)) {
    $where[] = "file_name LIKE ?";
    $params[] = '%' . $search . '%';
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$count_stmt = $db->prepare("SELECT COUNT(*) FROM media_library $where_sql");
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM media_library $where_sql ORDER BY id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

function format_bytes($bytes, $precision = 1) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$items = [];
foreach ($rows as $r) {
    $items[] = [
        'id' => (int)$r['id'],
        'file_name' => $r['file_name'],
        'file_url' => $r['file_url'],
        'file_type' => $r['file_type'],
        'mime_type' => $r['mime_type'],
        'file_size' => (int)$r['file_size'],
        'formatted_size' => format_bytes($r['file_size']),
        'created_at' => date('d M Y, h:i A', strtotime($r['created_at']))
    ];
}

echo json_encode([
    'success' => true,
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'total_pages' => ceil($total / $limit),
    'items' => $items
], JSON_UNESCAPED_SLASHES);
