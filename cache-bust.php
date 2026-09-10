<?php
/**
 * Cache Bust API — called by Admin Panel when global keys are updated
 * Each WordPress subdomain exposes this endpoint via the SODE plugin
 * 
 * Usage: GET /wp-json/sode/v1/flush-cache?token=SODE_SECRET_TOKEN
 * OR:    GET /?sode_flush_cache=SODE_SECRET_TOKEN
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simple secret token to prevent unauthorized cache busting
if (!defined('SODE_CACHE_BUST_TOKEN')) {
    define('SODE_CACHE_BUST_TOKEN', 'sode_flush_2026');
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token !== SODE_CACHE_BUST_TOKEN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Delete the global keys transient (WordPress)
$deleted = false;
if (function_exists('delete_transient')) {
    delete_transient('sode_global_keys_map');
    $deleted = true;
}

echo json_encode([
    'success'  => true,
    'message'  => 'Cache flushed successfully',
    'deleted'  => $deleted,
    'timestamp' => time()
]);
exit;
