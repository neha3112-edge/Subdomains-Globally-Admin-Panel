<?php
/**
 * Self-healing Permissions & Diagnostics for Uploads
 */
require_once __DIR__ . '/config/config.php';

if (!is_logged_in() || !has_role('superadmin')) {
    die("Access denied. Please login as superadmin.");
}

$uploads_root = ADMIN_PATH . '/uploads';
$year_dir = $uploads_root . '/' . date('Y');
$month_dir = $year_dir . '/' . date('m');

$dirs = [$uploads_root, $year_dir, $month_dir];
$results = [];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        $created = @mkdir($dir, 0777, true);
    }
    @chmod($dir, 0777);
    $is_writable = is_writable($dir);
    $perms = substr(sprintf('%o', fileperms($dir)), -4);
    $results[] = [
        'path' => $dir,
        'exists' => is_dir($dir),
        'writable' => $is_writable,
        'permissions' => $perms
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => 'Uploads permissions checked and fixed',
    'dirs' => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
