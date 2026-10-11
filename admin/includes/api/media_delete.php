<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!user_can('delete')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete media files.']);
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

// Move to Trash (quarantines physical file into uploads/trash/ and archives metadata)
$moved = move_to_trash('media_library', $id, $media['file_name'] ?? 'Media File');

if ($moved) {
    echo json_encode(['success' => true, 'message' => 'File moved to Trash. You can restore it anytime.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to move file to Trash.']);
}
