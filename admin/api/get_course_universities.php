<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$course_slug = strtolower(trim($_GET['course'] ?? ''));
$all_param = isset($_GET['all']) ? (int)$_GET['all'] : 0;

try {
    if (!empty($course_slug) && !$all_param) {
        // Query specific course
        $stmt = $db->prepare("
            SELECT id, course_slug, course_name, heading, description, columns_json, universities_json, updated_at 
            FROM course_universities_table 
            WHERE LOWER(course_slug) = LOWER(?) OR LOWER(course_name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$course_slug, $course_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode([
                'success' => false,
                'message' => 'Course universities table not found for: ' . htmlspecialchars($course_slug),
                'universities' => []
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $unis = [];
        if (!empty($row['universities_json'])) {
            $decoded = json_decode($row['universities_json'], true);
            if (is_array($decoded)) {
                $unis = $decoded;
            }
        }

        $cols = ["University Name", $row['course_name'] . " Fee (Per Semester)", "Location", "Approvals & Accreditation", "Advantage"];
        if (!empty($row['columns_json'])) {
            $dec_cols = json_decode($row['columns_json'], true);
            if (is_array($dec_cols)) $cols = $dec_cols;
        }

        echo json_encode([
            'success'            => true,
            'course_slug'        => $row['course_slug'],
            'course_name'        => $row['course_name'],
            'heading'            => $row['heading'],
            'description'        => $row['description'],
            'columns'            => $cols,
            'total_universities' => count($unis),
            'universities'       => $unis
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Query all courses
    $stmt = $db->query("
        SELECT id, course_slug, course_name, heading, description, columns_json, universities_json, updated_at 
        FROM course_universities_table 
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $courses_data = [];
    foreach ($rows as $row) {
        $cslug = strtolower($row['course_slug']);
        $unis = [];
        if (!empty($row['universities_json'])) {
            $decoded = json_decode($row['universities_json'], true);
            if (is_array($decoded)) {
                $unis = $decoded;
            }
        }
        $cols = ["University Name", $row['course_name'] . " Fee (Per Semester)", "Location", "Approvals & Accreditation", "Advantage"];
        if (!empty($row['columns_json'])) {
            $dec_cols = json_decode($row['columns_json'], true);
            if (is_array($dec_cols)) $cols = $dec_cols;
        }

        $courses_data[$cslug] = [
            'course_slug'        => $row['course_slug'],
            'course_name'        => $row['course_name'],
            'heading'            => $row['heading'],
            'description'        => $row['description'],
            'columns'            => $cols,
            'total_universities' => count($unis),
            'universities'       => $unis
        ];
    }

    echo json_encode([
        'success' => true,
        'total'   => count($courses_data),
        'data'    => $courses_data
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
