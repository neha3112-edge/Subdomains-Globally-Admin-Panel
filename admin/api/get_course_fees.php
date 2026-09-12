<?php
/**
 * Universal API: Get Course Fees Breakdown Data
 * Endpoint: /admin/api/get_course_fees.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

// 1. Resolve University
$uni_param = trim($_GET['uni'] ?? ($_GET['university'] ?? ($_POST['uni'] ?? ($_POST['university'] ?? ''))));
if (empty($uni_param) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
    if ($ref_host) {
        $parts = explode('.', strtolower($ref_host));
        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'admin', 'mail', 'cpanel'])) {
            $uni_param = $parts[0];
        }
    }
}
if (empty($uni_param) && !empty($_SERVER['HTTP_HOST'])) {
    $parts = explode('.', strtolower($_SERVER['HTTP_HOST']));
    if (count($parts) >= 3 && !in_array($parts[0], ['www', 'admin', 'mail', 'cpanel'])) {
        $uni_param = $parts[0];
    }
}
if (empty($uni_param)) {
    $uni_param = 'dsu';
}

// 2. Resolve Course & Mode
$course_param = trim($_GET['course'] ?? ($_POST['course'] ?? 'mba'));
$mode_param = trim($_GET['mode'] ?? ($_POST['mode'] ?? 'Online'));
if (empty($mode_param)) {
    $mode_param = 'Online';
}
// Normalize mode capitalisation ('Online', 'Distance')
$mode_clean = (stripos($mode_param, 'dist') !== false) ? 'Distance' : 'Online';

$fees = [];
$found_uni = null;
$found_course = null;

if ($db) {
    try {
        // Find University
        $u_stmt = $db->prepare("
            SELECT id, full_name, short_name, slug 
            FROM universities 
            WHERE (LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) OR LOWER(full_name) = LOWER(?)) 
              AND is_active = 1 
            LIMIT 1
        ");
        $u_stmt->execute([$uni_param, $uni_param, $uni_param]);
        $found_uni = $u_stmt->fetch(PDO::FETCH_ASSOC);

        // Find Course
        $c_stmt = $db->prepare("
            SELECT id, full_name, short_name, slug 
            FROM courses 
            WHERE (LOWER(short_name) = LOWER(?) OR LOWER(slug) = LOWER(?) OR LOWER(full_name) = LOWER(?)) 
            LIMIT 1
        ");
        $c_stmt->execute([$course_param, $course_param, $course_param]);
        $found_course = $c_stmt->fetch(PDO::FETCH_ASSOC);

        if ($found_uni && $found_course) {
            // Find Mapping by Uni + Course + Mode
            $m_stmt = $db->prepare("
                SELECT one_time_processing_fee, tuition_fee, examination_fee, per_semester_fee, total_program_fee, mode 
                FROM university_course_mappings 
                WHERE university_id = ? AND course_id = ? AND LOWER(mode) = LOWER(?)
                LIMIT 1
            ");
            $m_stmt->execute([$found_uni['id'], $found_course['id'], $mode_clean]);
            $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);

            // If not found with exact mode, fallback to any mode for this course
            if (!$mapping) {
                $m_stmt = $db->prepare("
                    SELECT one_time_processing_fee, tuition_fee, examination_fee, per_semester_fee, total_program_fee, mode 
                    FROM university_course_mappings 
                    WHERE university_id = ? AND course_id = ?
                    LIMIT 1
                ");
                $m_stmt->execute([$found_uni['id'], $found_course['id']]);
                $mapping = $m_stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($mapping) {
                $fees = [
                    'one_time_processing_fee' => $mapping['one_time_processing_fee'] ?? '',
                    'tuition_fee'             => $mapping['tuition_fee'] ?? '',
                    'examination_fee'         => $mapping['examination_fee'] ?? '',
                    'per_semester_fee'        => $mapping['per_semester_fee'] ?? '',
                    'total_program_fee'       => $mapping['total_program_fee'] ?? '',
                ];
            }
        }
    } catch (Exception $e) {
        error_log("get_course_fees error: " . $e->getMessage());
    }
}

echo json_encode([
    'success'         => !empty($fees) && (!empty(array_filter($fees))),
    'university_slug' => $uni_param,
    'university_name' => $found_uni['short_name'] ?? $uni_param,
    'course_slug'     => $course_param,
    'course_name'     => $found_course['short_name'] ?? $course_param,
    'mode'            => $mode_clean,
    'fees'            => $fees,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
