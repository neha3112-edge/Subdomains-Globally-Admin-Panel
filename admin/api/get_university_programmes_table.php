<?php
/**
 * API Endpoint: Get University Programmes Table
 * Returns JSON of programmes for the university
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$root_dir = dirname(__DIR__, 2);
require_once dirname(__DIR__) . '/config/config.php';
require_once $root_dir . '/university-programmes-table-universal.php';

try {
    $uni_input = trim($_GET['uni'] ?? ($_POST['uni'] ?? ($_GET['university'] ?? ($_POST['university'] ?? ''))));
    $data = get_university_programmes_table_data($uni_input);

    if (empty($data) || empty($data['programmes'])) {
        echo json_encode(['success' => false, 'message' => 'No programmes found for this university']);
        exit;
    }

    echo json_encode($data);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
