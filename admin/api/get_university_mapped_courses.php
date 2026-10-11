<?php
/**
 * Admin API: Get University Mapped Courses
 * File: admin/api/get_university_mapped_courses.php
 * Query Params: ?uni_id=1
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

$uni_id = (int)($_GET['uni_id'] ?? 0);
if ($uni_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid University ID', 'courses' => []]);
    exit;
}

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Fetch university info
    $stmtUni = $db->prepare("SELECT id, full_name, short_name, slug, logo_url, mode, rating, is_active FROM universities WHERE id = ? LIMIT 1");
    $stmtUni->execute([$uni_id]);
    $uni = $stmtUni->fetch(PDO::FETCH_ASSOC);

    if (!$uni) {
        echo json_encode(['success' => false, 'message' => 'University not found', 'courses' => []]);
        exit;
    }

    // Fetch mapped courses
    $stmt = $db->prepare("
        SELECT 
            ucm.id AS mapping_id,
            ucm.university_id,
            ucm.course_id,
            ucm.mode,
            ucm.per_semester_fee,
            ucm.total_program_fee,
            ucm.course_link,
            c.full_name AS course_name,
            c.short_name AS course_short,
            c.slug AS course_slug,
            c.level,
            (SELECT COUNT(*) FROM course_specializations WHERE mapping_id = ucm.id) AS specs_count
        FROM university_course_mappings ucm
        INNER JOIN courses c ON ucm.course_id = c.id
        WHERE ucm.university_id = ?
        ORDER BY 
            CASE WHEN ucm.mode = 'Online' THEN 1 ELSE 2 END ASC,
            CASE WHEN c.level = 'PG' THEN 1 WHEN c.level = 'UG' THEN 2 ELSE 3 END ASC,
            c.short_name ASC,
            ucm.id ASC
    ");
    $stmt->execute([$uni_id]);
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_courses = [];
    foreach ($courses as $c) {
        $formatted_courses[] = [
            'mapping_id' => (int)$c['mapping_id'],
            'course_id' => (int)$c['course_id'],
            'course_name' => $c['course_name'],
            'course_short' => $c['course_short'],
            'course_slug' => $c['course_slug'],
            'level' => strtoupper(trim($c['level'] ?? 'UG')),
            'mode' => ucfirst(strtolower(trim($c['mode'] ?? 'Online'))),
            'per_semester_fee' => $c['per_semester_fee'],
            'total_program_fee' => $c['total_program_fee'],
            'specs_count' => (int)$c['specs_count'],
            'course_link' => $c['course_link'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'university' => [
            'id' => (int)$uni['id'],
            'full_name' => $uni['full_name'],
            'short_name' => $uni['short_name'] ?: $uni['full_name'],
            'slug' => $uni['slug'],
            'logo_url' => !empty($uni['logo_url']) ? get_asset_url($uni['logo_url']) : '',
            'mode' => $uni['mode'] ?? 'Online',
            'rating' => $uni['rating'] ?? '4.0',
            'is_active' => (int)$uni['is_active']
        ],
        'total_count' => count($formatted_courses),
        'courses' => $formatted_courses
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'courses' => []]);
}
