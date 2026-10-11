<?php
/**
 * Admin API: Get Universities Mapped to a Specific Course
 * File: admin/api/get_course_mapped_universities.php
 * Query Params: ?course_id=1
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/config/config.php';

// Check admin authentication
if (!function_exists('is_logged_in') || !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$course_id = (int)($_GET['course_id'] ?? 0);
if ($course_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Course ID', 'universities' => []]);
    exit;
}

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Fetch course info
    $stmtCourse = $db->prepare("SELECT id, full_name, short_name, slug, level FROM courses WHERE id = ? LIMIT 1");
    $stmtCourse->execute([$course_id]);
    $course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

    if (!$course) {
        echo json_encode(['success' => false, 'message' => 'Course not found', 'universities' => []]);
        exit;
    }

    // Fetch mapped universities
    $stmt = $db->prepare("
        SELECT 
            ucm.id AS mapping_id,
            ucm.university_id,
            ucm.course_id,
            ucm.mode,
            ucm.per_semester_fee,
            ucm.total_program_fee,
            ucm.course_link,
            u.full_name AS uni_name,
            u.short_name AS uni_short,
            u.slug AS uni_slug,
            u.logo_url AS uni_logo,
            u.mode AS uni_mode,
            u.rating AS uni_rating,
            u.is_active AS uni_active,
            (SELECT COUNT(*) FROM course_specializations WHERE mapping_id = ucm.id) AS specs_count
        FROM university_course_mappings ucm
        INNER JOIN universities u ON ucm.university_id = u.id
        WHERE ucm.course_id = ?
        ORDER BY 
            u.is_active DESC,
            CASE WHEN ucm.mode = 'Online' THEN 1 ELSE 2 END ASC,
            u.short_name ASC,
            ucm.id ASC
    ");
    $stmt->execute([$course_id]);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_unis = [];
    foreach ($mappings as $m) {
        $formatted_unis[] = [
            'mapping_id' => (int)$m['mapping_id'],
            'university_id' => (int)$m['university_id'],
            'uni_name' => $m['uni_name'],
            'uni_short' => $m['uni_short'] ?: $m['uni_name'],
            'uni_slug' => $m['uni_slug'],
            'uni_logo' => !empty($m['uni_logo']) ? get_asset_url($m['uni_logo']) : '',
            'uni_active' => (int)$m['uni_active'],
            'mode' => ucfirst(strtolower(trim($m['mode'] ?? 'Online'))),
            'rating' => $m['uni_rating'] ?? '4.0',
            'per_semester_fee' => $m['per_semester_fee'],
            'total_program_fee' => $m['total_program_fee'],
            'specs_count' => (int)$m['specs_count'],
            'course_link' => $m['course_link'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'course' => [
            'id' => (int)$course['id'],
            'full_name' => $course['full_name'],
            'short_name' => $course['short_name'] ?: $course['full_name'],
            'slug' => $course['slug'],
            'level' => strtoupper(trim($course['level'] ?? 'UG'))
        ],
        'total_count' => count($formatted_unis),
        'universities' => $formatted_unis
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'universities' => []]);
}
