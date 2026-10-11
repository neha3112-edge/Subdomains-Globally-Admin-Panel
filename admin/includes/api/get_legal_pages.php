<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$type = trim($_GET['type'] ?? '');
$cache_key = 'api:legal_pages:' . md5($type);

$cached_response = Sode_Redis::get($cache_key);
if ($cached_response !== null) {
    header('X-Cache: HIT (Redis)');
    echo json_encode($cached_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

$response_data = [
    'success' => true,
    'pages' => $data
];

Sode_Redis::set($cache_key, $response_data, 86400);
header('X-Cache: MISS');
echo json_encode($response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
