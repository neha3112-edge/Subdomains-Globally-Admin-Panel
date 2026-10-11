<?php
/**
 * API Endpoint: Get University Programmes Table
 * Returns JSON of programmes for the university
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$root_dir = dirname(__DIR__, 2);
require_once dirname(__DIR__) . '/config/config.php';
require_once $root_dir . '/university-programmes-table-universal.php';

try {
    $uni_input = trim($_GET['uni'] ?? ($_POST['uni'] ?? ($_GET['university'] ?? ($_POST['university'] ?? ''))));
    $cache_key = 'api:university_programmes:' . md5($uni_input);

    $cached_response = Sode_Redis::get($cache_key);
    if ($cached_response !== null) {
        header('X-Cache: HIT (Redis)');
        echo json_encode($cached_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $data = get_university_programmes_table_data($uni_input);

    if (empty($data) || empty($data['programmes'])) {
        echo json_encode(['success' => false, 'message' => 'No programmes found for this university']);
        exit;
    }

    Sode_Redis::set($cache_key, $data, 86400);
    header('X-Cache: MISS');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
