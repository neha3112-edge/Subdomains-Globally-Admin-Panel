<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid media ID']);
    exit;
}

$db = get_db_connection();
$stmt = $db->prepare("SELECT * FROM media_library WHERE id = ?");
$stmt->execute([$id]);
$media = $stmt->fetch();

if (!$media) {
    echo json_encode(['success' => false, 'message' => 'File not found in database']);
    exit;
}

// Delete physical file if exists
$physical_path = ADMIN_PATH . '/uploads/' . $media['file_path'];
if (file_exists($physical_path)) {
    @unlink($physical_path);
}

$del_stmt = $db->prepare("DELETE FROM media_library WHERE id = ?");
$del_stmt->execute([$id]);

echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
