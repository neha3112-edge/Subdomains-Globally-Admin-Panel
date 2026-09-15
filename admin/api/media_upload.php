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

// Ensure root uploads/ directory exists and is writable
if (!is_dir($uploads_root)) {
    if (!mkdir($uploads_root, 0775, true)) {
        $err = error_get_last();
        echo json_encode(['success' => false, 'message' => 'Cannot create uploads/ directory: ' . ($err['message'] ?? 'Permission denied — set write permission on admin/ folder on server')]);
        exit;
    }
    @chmod($uploads_root, 0775);
}

if (!is_writable($uploads_root)) {
    echo json_encode(['success' => false, 'message' => 'uploads/ directory is not writable. Run: chmod -R 775 ' . $uploads_root . ' on your server via SSH or File Manager.']);
    exit;
}

// Create Year/Month subfolder
$sub_dir    = date('Y') . '/' . date('m');
$target_dir = $uploads_root . '/' . $sub_dir;

if (!is_dir($target_dir)) {
    if (!mkdir($target_dir, 0775, true)) {
        $err = error_get_last();
        echo json_encode(['success' => false, 'message' => 'Cannot create upload subfolder (' . $sub_dir . '): ' . ($err['message'] ?? 'Permission denied') . ' — Run: chmod -R 775 ' . $uploads_root . ' on server']);
        exit;
    }
    @chmod($target_dir, 0775);
}

if (!is_writable($target_dir)) {
    echo json_encode(['success' => false, 'message' => 'Upload subfolder (' . $sub_dir . ') is not writable. Run: chmod -R 775 ' . $uploads_root . ' on server via SSH.']);
    exit;
}

// Clean filename
$clean_name = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
$clean_name = trim($clean_name, '_');
$final_name = $clean_name . '_' . time() . '.' . $extension;
$dest_path  = $target_dir . '/' . $final_name;

// Try move_uploaded_file first (correct way for uploaded files)
if (!move_uploaded_file($tmp_path, $dest_path)) {
    // Fallback: copy + unlink (some server configs restrict move_uploaded_file across mount points)
    if (!copy($tmp_path, $dest_path)) {
        $last_err = error_get_last();
        $err_msg  = !empty($last_err['message']) ? $last_err['message'] : 'Unknown error';
        echo json_encode(['success' => false, 'message' => 'Failed to save file to disk: ' . $err_msg . ' — Check if PHP open_basedir restricts temp dir access on your server.']);
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
