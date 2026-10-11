<?php
require_once __DIR__ . '/config/config.php';

if (!is_logged_in()) {
    http_response_code(403);
    die('{"error":"Access denied. Please login to admin panel first."}');
}

header('Content-Type: application/json; charset=utf-8');

$uploads_root = ADMIN_PATH . '/uploads';
$year_dir     = $uploads_root . '/' . date('Y');
$month_dir    = $year_dir    . '/' . date('m');

$log = [];

// --- Diagnostics ---
$php_user = '';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw = posix_getpwuid(posix_geteuid());
    $php_user = is_array($pw) ? $pw['name'] : 'uid:' . posix_geteuid();
} else {
    $php_user = get_current_user();
}

$log[] = 'PHP user       : ' . $php_user;
$log[] = 'open_basedir   : ' . (ini_get('open_basedir') ?: 'none (no restriction)');
$log[] = 'upload_tmp_dir : ' . (ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
$log[] = 'ADMIN_PATH     : ' . ADMIN_PATH;
$log[] = '---';

// --- Delete empty wrong-permission subdirs ---
foreach (array($month_dir, $year_dir) as $dir) {
    if (is_dir($dir)) {
        $items = array_diff(scandir($dir), array('.', '..'));
        if (empty($items)) {
            $ok = @rmdir($dir);
            $log[] = ($ok ? 'Removed' : 'Could not remove') . ' empty dir: ' . $dir;
        } else {
            $log[] = 'Dir not empty, keeping: ' . $dir . ' (' . count($items) . ' items)';
        }
    }
}

// --- Recreate dirs with umask(0) ---
$old_umask = umask(0);
foreach (array($uploads_root, $year_dir, $month_dir) as $dir) {
    if (!is_dir($dir)) {
        $ok = mkdir($dir, 0777, true);
        $err = error_get_last();
        $log[] = 'mkdir ' . $dir . ' : ' . ($ok ? 'OK' : 'FAILED - ' . ($err ? $err['message'] : 'unknown'));
    } else {
        $ok = chmod($dir, 0777);
        $log[] = 'chmod 0777 ' . $dir . ' : ' . ($ok ? 'OK' : 'FAILED (different owner - need SSH)');
    }
}
umask($old_umask);
$log[] = '---';

// --- Write test ---
$all_ok = true;
foreach (array($uploads_root, $year_dir, $month_dir) as $dir) {
    $perms = is_dir($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A';
    $test  = $dir . '/.wtest_' . uniqid();
    $fh    = @fopen($test, 'w');
    if ($fh) {
        fwrite($fh, 'ok');
        fclose($fh);
        @unlink($test);
        $log[] = 'WRITABLE   : ' . $dir . ' (perms:' . $perms . ')';
    } else {
        $e     = error_get_last();
        $log[] = 'NOT WRITABLE: ' . $dir . ' (perms:' . $perms . ') - ' . ($e ? $e['message'] : 'Permission denied');
        $all_ok = false;
    }
}

echo json_encode(array(
    'fixed'   => $all_ok,
    'message' => $all_ok ? 'SUCCESS - All dirs writable! Try uploading again.' : 'FAILED - Run fix on server: chmod -R 777 ' . $uploads_root,
    'log'     => $log,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
