<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$uni_slug = trim($_GET['uni'] ?? '');
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

echo json_encode([
    'success' => true,
    'total' => count($formatted),
    'steps' => $formatted
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
