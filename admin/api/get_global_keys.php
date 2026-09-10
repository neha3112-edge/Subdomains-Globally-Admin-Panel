<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$keys = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1")->fetchAll();

$map = [];
foreach ($keys as $k) {
    $map[$k['key_code']] = $k['key_value'];
}

echo json_encode([
    'success' => true,
    'keys' => $map,
    'data' => $map
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
