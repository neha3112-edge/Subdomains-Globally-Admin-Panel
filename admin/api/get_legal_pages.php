<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$type = trim($_GET['type'] ?? '');

$query = "SELECT page_type, heading, content_html FROM legal_pages";
$params = [];
if (!empty($type)) {
    $query .= " WHERE page_type = ?";
    $params[] = $type;
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$data = [];
foreach ($rows as $r) {
    $data[$r['page_type']] = [
        'heading' => $r['heading'],
        'content_html' => $r['content_html']
    ];
}

echo json_encode([
    'success' => true,
    'pages' => $data
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
