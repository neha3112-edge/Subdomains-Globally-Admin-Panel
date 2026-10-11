<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!user_can('create') && !user_can('write')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to upload media.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$uploaded_file = $_FILES['media_file'] ?? ($_FILES['file'] ?? null);

if (!$uploaded_file || $uploaded_file['error'] !== UPLOAD_ERR_OK) {
    $err_code = $uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'message' => 'Upload failed. Error code: ' . $err_code]);
    exit;
}

$original_name = basename($uploaded_file['name']);
$tmp_path = $uploaded_file['tmp_name'];
$file_size = (int)$uploaded_file['size'];

// Max size check: 50MB
if ($file_size > 50 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds maximum limit of 50MB.']);
    exit;
}

$extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

// Block dangerous extensions
$forbidden_exts = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'exe', 'sh', 'bat', 'cmd', 'js', 'html', 'htm'];
if (in_array($extension, $forbidden_exts)) {
    echo json_encode(['success' => false, 'message' => 'File format not allowed for security reasons.']);
    exit;
}

// Determine File Type
$file_type = 'other';
$image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico'];
$audio_exts = ['mp3', 'wav', 'm4a', 'ogg', 'aac'];
$video_exts = ['mp4', 'webm', 'mov', 'mkv'];
$pdf_exts   = ['pdf'];
$doc_exts   = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt'];

if (in_array($extension, $image_exts)) {
    $file_type = 'image';
} elseif (in_array($extension, $audio_exts)) {
    $file_type = 'audio';
} elseif (in_array($extension, $video_exts)) {
    $file_type = 'video';
} elseif (in_array($extension, $pdf_exts)) {
    $file_type = 'pdf';
} elseif (in_array($extension, $doc_exts)) {
    $file_type = 'document';
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $tmp_path);
finfo_close($finfo);

// Year/Month Folder Structure
$uploads_root = ADMIN_PATH . '/uploads';
$sub_dir      = date('Y') . '/' . date('m');
$target_dir   = $uploads_root . '/' . $sub_dir;

// Create directories with umask(0) so permissions are exactly 0777 regardless of server umask
if (!is_dir($target_dir)) {
    $old_umask = umask(0);
    mkdir($target_dir, 0777, true);
    umask($old_umask);
}
// Always try to chmod (fixes dirs previously created with wrong perms)
@chmod($uploads_root, 0777);
@chmod($target_dir, 0777);

// Clean filename
$clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
$clean_name = trim($clean_name, '_');
$final_name = $clean_name . '_' . time() . '.' . $extension;
$dest_path  = $target_dir . '/' . $final_name;

// Attempt upload — move_uploaded_file has special OS privileges for PHP uploads
if (!move_uploaded_file($tmp_path, $dest_path)) {
    // Fallback: try copy
    if (!copy($tmp_path, $dest_path)) {
        $last_err = error_get_last();
        $err_msg  = $last_err['message'] ?? 'Permission denied';
        echo json_encode([
            'success' => false,
            'message' => 'Upload failed: ' . $err_msg
                . ' | PHP user: ' . get_current_user()
                . ' | open_basedir: ' . (ini_get('open_basedir') ?: 'none')
                . ' | Run fix: /admin/fix_permissions.php on browser, or chmod -R 777 ' . $uploads_root . ' via SSH'
        ]);
        exit;
    }
    @unlink($tmp_path);
}
@chmod($dest_path, 0644);



// Generate Path & Dynamic URL

$relative_path = 'uploads/' . $sub_dir . '/' . $final_name;
$display_url = get_asset_url($relative_path);

$db = get_db_connection();

$user_id = $_SESSION['user_id'] ?? null;
$stmt = $db->prepare("
    INSERT INTO media_library (file_name, file_path, file_url, file_type, mime_type, file_size, uploaded_by) 
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([$original_name, $relative_path, $relative_path, $file_type, $mime_type, $file_size, $user_id]);
$media_id = $db->lastInsertId();

echo json_encode([
    'success' => true,
    'id' => (int)$media_id,
    'file_name' => $original_name,
    'file_path' => $relative_path,
    'file_url' => $relative_path,
    'display_url' => $display_url,
    'file_type' => $file_type,
    'mime_type' => $mime_type,
    'file_size' => $file_size,
    'created_at' => date('d M Y, h:i A')
], JSON_UNESCAPED_SLASHES);
