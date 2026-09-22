<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$uni_slug = trim($_GET['uni'] ?? '');
$cache_key = 'api:admission_steps:' . md5($uni_slug);

$cached_response = Sode_Redis::get($cache_key);
if ($cached_response !== null) {
    header('X-Cache: HIT (Redis)');
    echo json_encode($cached_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$uni_id = null;

if (!empty($uni_slug)) {
    $stmt = $db->prepare("SELECT id FROM universities WHERE slug = ?");
    $stmt->execute([$uni_slug]);
    $uni_id = $stmt->fetchColumn();
}

$steps = [];
if ($uni_id) {
    // Check if university has specific override steps
    $stmt = $db->prepare("SELECT * FROM admission_process_steps WHERE university_id = ? ORDER BY step_number ASC, id ASC");
    $stmt->execute([$uni_id]);
    $steps = $stmt->fetchAll();
}

// Fallback to universal steps
if (empty($steps)) {
    $steps = $db->query("SELECT * FROM admission_process_steps WHERE university_id IS NULL ORDER BY step_number ASC, id ASC")->fetchAll();
}

$formatted = [];
foreach ($steps as $s) {
    $formatted[] = [
        'num' => (int)$s['step_number'],
        'color' => $s['color_hex'],
        'title' => $s['title'],
        'desc' => $s['description'],
        'icon' => $s['icon_svg']
    ];
}

$response_data = [
    'success' => true,
    'total' => count($formatted),
    'steps' => $formatted
];

Sode_Redis::set($cache_key, $response_data, 86400);
header('X-Cache: MISS');
echo json_encode($response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
