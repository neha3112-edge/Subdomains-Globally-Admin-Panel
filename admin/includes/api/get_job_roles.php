<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$course_slug = strtolower(trim($_GET['course'] ?? ''));
$all_param = isset($_GET['all']) ? (int)$_GET['all'] : 0;
$cache_key = 'api:job_roles:' . md5($course_slug . '|' . $all_param);

$cached_response = Sode_Redis::get($cache_key);
if ($cached_response !== null) {
    header('X-Cache: HIT (Redis)');
    echo json_encode($cached_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = get_db_connection();

try {
    if (!empty($course_slug) && !$all_param) {
        // Query specific course
        $stmt = $db->prepare("
            SELECT id, course_slug, course_name, heading, description, roles_json, updated_at 
            FROM course_job_roles 
            WHERE LOWER(course_slug) = LOWER(?) OR LOWER(course_name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$course_slug, $course_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode([
                'success' => false,
                'message' => 'Course job roles not found for: ' . htmlspecialchars($course_slug),
                'roles' => []
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $roles = [];
        if (!empty($row['roles_json'])) {
            $decoded = json_decode($row['roles_json'], true);
            if (is_array($decoded)) {
                $roles = $decoded;
            }
        }

        $response_data = [
            'success'     => true,
            'course_slug' => $row['course_slug'],
            'course_name' => $row['course_name'],
            'heading'     => $row['heading'],
            'description' => $row['description'],
            'columns'     => ["Job Role", "Role Description", "Salary Range in India"],
            'total_roles' => count($roles),
            'roles'       => $roles
        ];
        Sode_Redis::set($cache_key, $response_data, 86400);
        header('X-Cache: MISS');
        echo json_encode($response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Query all courses
    $stmt = $db->query("
        SELECT id, course_slug, course_name, heading, description, roles_json, updated_at 
        FROM course_job_roles 
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $courses_data = [];
    foreach ($rows as $row) {
        $cslug = strtolower($row['course_slug']);
        $roles = [];
        if (!empty($row['roles_json'])) {
            $decoded = json_decode($row['roles_json'], true);
            if (is_array($decoded)) {
                $roles = $decoded;
            }
        }
        $courses_data[$cslug] = [
            'course_slug' => $row['course_slug'],
            'course_name' => $row['course_name'],
            'heading'     => $row['heading'],
            'description' => $row['description'],
            'columns'     => ["Job Role", "Role Description", "Salary Range in India"],
            'total_roles' => count($roles),
            'roles'       => $roles
        ];
    }

    $response_all = [
        'success' => true,
        'total'   => count($courses_data),
        'data'    => $courses_data
    ];
    Sode_Redis::set($cache_key, $response_all, 86400);
    header('X-Cache: MISS');
    echo json_encode($response_all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}

