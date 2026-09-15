<?php
/**
 * Upload Self-Healing & Diagnostics Tool
 * Visit: /admin/fix_permissions.php in browser to fix upload permission issues
 */
require_once __DIR__ . '/config/config.php';

if (!is_logged_in()) {
    die(json_encode(['error' => 'Access denied. Please login first.']));
}

header('Content-Type: application/json; charset=utf-8');

$uploads_root = ADMIN_PATH . '/uploads';
$year_dir     = $uploads_root . '/' . date('Y');
$month_dir    = $year_dir . '/' . date('m');

$log = [];

// Step 1: Diagnostics
$log[] = '=== SERVER DIAGNOSTICS ===';
$log[] = 'PHP user: ' . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : get_current_user());
$log[] = 'open_basedir: ' . (ini_get('open_basedir') ?: 'none');
$log[] = 'upload_tmp_dir: ' . (ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
$log[] = 'ADMIN_PATH: ' . ADMIN_PATH;
$log[] = '';

// Step 2: Delete empty wrong-permission subdirs and recreate fresh
$log[] = '=== FIXING DIRECTORIES ===';

foreach ([$month_dir, $year_dir] as $dir) {
    if (is_dir($dir)) {
        $items = array_diff(scandir($dir), ['.', '..']);
        if (empty($items)) {
            $ok = @rmdir($dir);
            $log[] = ($ok ? 'Removed' : 'Could not remove') . ' empty dir: ' . $dir;
        } else {
            $log[] = 'Dir not empty, skipping delete: ' . $dir . ' (' . count($items) . ' items)';
        }
    }
}

// Recreate all dirs fresh with umask(0) → guaranteed 0777
$old_umask = umask(0);
foreach ([$uploads_root, $year_dir, $month_dir] as $dir) {
    if (!is_dir($dir)) {
        $ok = mkdir($dir, 0777, true);
        $log[] = 'mkdir ' . $dir . ' => ' . ($ok ? 'OK' : 'FAILED: ' . (error_get_last()['message'] ?? 'unknown'));
    } else {
        $ok = chmod($dir, 0777);
        $log[] = 'chmod 0777 on existing ' . $dir . ' => ' . ($ok ? 'OK' : 'FAILED (different owner)');
    }
}
umask($old_umask);
$log[] = '';

// Step 3: Actual write test on each dir
$log[] = '=== WRITE TESTS ===';
$all_writable = true;

foreach ([$uploads_root, $year_dir, $month_dir] as $dir) {
    $perms     = is_dir($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A';
    $test_file = $dir . '/.wtest_' . uniqid();
    $handle    = @fopen($test_file, 'w');
    if ($handle) {
        fwrite($handle, 'test');
        fclose($handle);
        @unlink($test_file);
        $log[] = 'WRITABLE: ' . $dir . ' (perms: ' . $perms . ')';
    } else {
        $err       = error_get_last();
        $log[]     = 'NOT WRITABLE: ' . $dir . ' (perms: ' . $perms . ') -- ' . ($err['message'] ?? 'Permission denied');
        $all_writable = false;
    }
}

echo json_encode([
    'fixed'   => $all_writable,
    'message' => $all_writable
        ? 'SUCCESS: All upload directories are writable! Try uploading again.'
        : 'FAILED: Directories still not writable. This is an open_basedir or PHP process user ownership issue — contact your hosting provider.',
    'log'     => $log,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
