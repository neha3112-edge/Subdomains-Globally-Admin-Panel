<?php
/**
 * Central REST API Endpoint: Get University Mapped Courses
 * File: admin/api/get_courses.php
 * 
 * Returns JSON array of courses mapped to a specific university.
 * Query Params: ?uni=dsu OR ?uni_id=1
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    $uni_param = trim($_GET['uni'] ?? ($_POST['uni'] ?? ''));
    $uni_id    = (int)($_GET['uni_id'] ?? ($_POST['uni_id'] ?? 0));

    // Resolve university
    $uni = null;
    if ($uni_id > 0) {
        $stmt = $db->prepare("SELECT * FROM universities WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$uni_id]);
        $uni = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif (!empty($uni_param)) {
        $clean_slug = strtolower(trim($uni_param));
        $stmt = $db->prepare("SELECT * FROM universities WHERE (LOWER(slug) = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$clean_slug, $clean_slug, $clean_slug]);
        $uni = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$uni) {
        // Fallback: pick first active university if not matched
        $uni = $db->query("SELECT * FROM universities WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    }

    if (!$uni) {
        echo json_encode(['success' => false, 'message' => 'No active university found', 'courses' => []]);
        exit;
    }

    $uni_id = (int)$uni['id'];

    // Fetch mapped courses
    $stmt = $db->prepare("
        SELECT 
            ucm.id AS mapping_id,
            ucm.university_id,
            ucm.course_id,
            ucm.mode,
            ucm.course_description,
            ucm.course_link,
            ucm.eligibility_text,
            ucm.per_semester_fee,
            ucm.total_program_fee,
            c.full_name AS course_name,
            c.short_name AS course_short,
            c.slug AS course_slug,
            c.level,
            c.description AS default_description,
            (SELECT COUNT(*) FROM course_specializations WHERE mapping_id = ucm.id) AS specializations_count
        FROM university_course_mappings ucm
        INNER JOIN courses c ON ucm.course_id = c.id
        WHERE ucm.university_id = ?
        ORDER BY 
            CASE 
                WHEN ucm.mode = 'Online' THEN 1 
                ELSE 2 
            END ASC,
            CASE 
                WHEN c.level = 'PG' THEN 1 
                WHEN c.level = 'UG' THEN 2 
                ELSE 3 
            END ASC,
            ucm.id ASC
    ");
    $stmt->execute([$uni_id]);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $courses_data = [];
    $has_online = false;
    $has_distance = false;

    foreach ($mappings as $m) {
        $course_mode = (!empty($m['mode']) && strtolower($m['mode']) === 'distance') ? 'Distance' : 'Online';
        if ($course_mode === 'Online') $has_online = true;
        if ($course_mode === 'Distance') $has_distance = true;

        $level_raw = strtoupper(trim($m['level'] ?? 'UG'));
        $tab_category = ($level_raw === 'PG' || stripos($m['course_name'], 'Master') !== false) ? 'Master' : 'Bachelor';
        $duration = ($tab_category === 'Master') ? '2 Year' : '3 Year';

        $desc = !empty($m['course_description']) ? trim($m['course_description']) : (!empty($m['default_description']) ? trim($m['default_description']) : '');
        $link = !empty($m['course_link']) ? trim($m['course_link']) : '#';

        $courses_data[] = [
            'id'             => (int)$m['mapping_id'],
            'course_id'      => (int)$m['course_id'],
            'short_name'     => $m['course_short'],
            'full_name'      => $m['course_name'],
            'slug'           => $m['course_slug'],
            'mode'           => $course_mode,
            'level'          => $m['level'],
            'tab'            => $tab_category,
            'duration'       => $duration,
            'description'    => $desc,
            'link'           => $link,
            'per_sem_fee'    => $m['per_semester_fee'],
            'total_fee'      => $m['total_program_fee'],
            'specs_count'    => (int)$m['specializations_count']
        ];
    }

    echo json_encode([
        'success'      => true,
        'university'   => [
            'id'         => (int)$uni['id'],
            'slug'       => $uni['slug'],
            'short_name' => $uni['short_name'],
            'full_name'  => $uni['full_name'],
        ],
        'has_online'   => $has_online,
        'has_distance' => $has_distance,
        'total'        => count($courses_data),
        'courses'      => $courses_data,
        'short_names'  => array_column($courses_data, 'short_name'),
        'comma_list'   => implode(', ', array_column($courses_data, 'short_name'))
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'courses' => []
    ]);
}
