<?php
/**
 * Admin API: Get Course Mappings for a Specific Master Specialization
 * File: admin/includes/api/get_specialization_mappings.php
 * Query Params: ?spec_id=1 OR ?spec_name=Accounting
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__, 2) . '/config/config.php';

// Check admin authentication
if (!function_exists('is_logged_in') || !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$spec_id = (int)($_GET['spec_id'] ?? 0);
$spec_name = trim($_GET['spec_name'] ?? '');

if ($spec_id <= 0 && empty($spec_name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Specialization ID or Name', 'mappings' => []]);
    exit;
}

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Fetch specialization master record
    if ($spec_id > 0) {
        $stmtSpec = $db->prepare("SELECT id, specialization_name, is_active FROM course_specializations_master WHERE id = ? LIMIT 1");
        $stmtSpec->execute([$spec_id]);
    } else {
        $stmtSpec = $db->prepare("SELECT id, specialization_name, is_active FROM course_specializations_master WHERE specialization_name = ? LIMIT 1");
        $stmtSpec->execute([$spec_name]);
    }
    $spec = $stmtSpec->fetch(PDO::FETCH_ASSOC);

    if (!$spec) {
        echo json_encode(['success' => false, 'message' => 'Specialization not found', 'mappings' => []]);
        exit;
    }

    $target_name = $spec['specialization_name'];

    // Fetch mapped courses
    $stmt = $db->prepare("
        SELECT 
            ucm.id AS mapping_id,
            ucm.university_id,
            ucm.course_id,
            ucm.mode,
            ucm.per_semester_fee,
            ucm.total_program_fee,
            u.full_name AS uni_name,
            u.short_name AS uni_short,
            u.is_active AS uni_active,
            c.full_name AS course_name,
            c.short_name AS course_short,
            c.level AS course_level,
            cs.fees_per_sem AS spec_fee,
            cs.duration AS spec_duration,
            cs.specialization_link AS spec_link
        FROM course_specializations cs
        INNER JOIN university_course_mappings ucm ON cs.mapping_id = ucm.id
        INNER JOIN universities u ON ucm.university_id = u.id
        INNER JOIN courses c ON ucm.course_id = c.id
        WHERE cs.specialization_name = ?
        ORDER BY 
            u.is_active DESC,
            u.short_name ASC,
            c.short_name ASC,
            ucm.id ASC
    ");
    $stmt->execute([$target_name]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($rows as $r) {
        $formatted[] = [
            'mapping_id' => (int)$r['mapping_id'],
            'university_id' => (int)$r['university_id'],
            'uni_name' => $r['uni_name'],
            'uni_short' => $r['uni_short'] ?: $r['uni_name'],
            'uni_active' => (int)$r['uni_active'],
            'course_id' => (int)$r['course_id'],
            'course_name' => $r['course_name'],
            'course_short' => $r['course_short'] ?: $r['course_name'],
            'course_level' => strtoupper(trim($r['course_level'] ?? 'UG')),
            'mode' => ucfirst(strtolower(trim($r['mode'] ?? 'Online'))),
            'spec_fee' => $r['spec_fee'] ?: $r['per_semester_fee'],
            'spec_duration' => $r['spec_duration'],
            'spec_link' => $r['spec_link'] ?? ''
        ];
    }

    echo json_encode([
        'success' => true,
        'specialization' => [
            'id' => (int)$spec['id'],
            'name' => $spec['specialization_name'],
            'is_active' => (int)$spec['is_active']
        ],
        'total_count' => count($formatted),
        'mappings' => $formatted
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch mapped courses: ' . $e->getMessage(),
        'mappings' => []
    ]);
}
