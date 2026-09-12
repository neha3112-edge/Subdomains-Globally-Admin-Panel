<?php
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$course_id = (int)($_GET['course_id'] ?? 0);
$course_slug = strtolower(trim($_GET['course'] ?? ''));

if (!$course_id && !empty($course_slug)) {
    $c_stmt = $db->prepare("SELECT id FROM courses WHERE LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) LIMIT 1");
    $c_stmt->execute([$course_slug, $course_slug]);
    $course_id = (int)$c_stmt->fetchColumn();
}

// 1. Fetch ALL Global Master Specializations
$s_stmt = $db->query("
    SELECT id, specialization_name, sort_order 
    FROM course_specializations_master 
    WHERE is_active = 1 
    ORDER BY specialization_name ASC
");
$all_specializations = $s_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch ALL Global Master Syllabus Subjects
$sub_stmt = $db->query("
    SELECT id, subject_name, sort_order 
    FROM course_syllabus_subjects_master 
    WHERE is_active = 1 
    ORDER BY subject_name ASC
");
$all_subjects = $sub_stmt->fetchAll(PDO::FETCH_ASSOC);

// Also format subjects array of names for easy auto-complete
$subject_names = array_values(array_filter(array_unique(array_column($all_subjects, 'subject_name'))));

echo json_encode([
    'success'         => true,
    'course_id'       => $course_id,
    'specializations' => $all_specializations,
    'subjects'        => $all_subjects,
    'subject_names'   => $subject_names,
    'syllabus'        => []
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
