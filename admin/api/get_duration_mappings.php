<?php
/**
 * Admin API: Get Course Mappings for a Specific Master Duration
 * File: admin/api/get_duration_mappings.php
 * Query Params: ?dur_id=1 OR ?duration_title=2 Years
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

$dur_id = (int)($_GET['dur_id'] ?? 0);
$duration_title = trim($_GET['duration_title'] ?? '');

if ($dur_id <= 0 && empty($duration_title)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Duration ID or Title', 'mappings' => []]);
    exit;
}

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Fetch duration master record
    if ($dur_id > 0) {
        $stmtDur = $db->prepare("SELECT id, duration_title, is_active FROM course_durations_master WHERE id = ? LIMIT 1");
        $stmtDur->execute([$dur_id]);
    } else {
        $stmtDur = $db->prepare("SELECT id, duration_title, is_active FROM course_durations_master WHERE duration_title = ? LIMIT 1");
        $stmtDur->execute([$duration_title]);
    }
    $duration = $stmtDur->fetch(PDO::FETCH_ASSOC);

    if (!$duration) {
        echo json_encode(['success' => false, 'message' => 'Duration not found', 'mappings' => []]);
        exit;
    }

    $target_title = $duration['duration_title'];

    // Fetch mapped courses having specializations with this duration
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
            GROUP_CONCAT(DISTINCT cs.specialization_name ORDER BY cs.specialization_name ASC SEPARATOR ', ') AS specializations,
            COUNT(DISTINCT cs.id) AS spec_count,
            MIN(NULLIF(cs.fees_per_sem, '')) AS min_spec_fee
        FROM course_specializations cs
        INNER JOIN university_course_mappings ucm ON cs.mapping_id = ucm.id
        INNER JOIN universities u ON ucm.university_id = u.id
        INNER JOIN courses c ON ucm.course_id = c.id
        WHERE cs.duration = ?
        GROUP BY ucm.id
        ORDER BY 
            u.is_active DESC,
            u.short_name ASC,
            c.short_name ASC,
            ucm.id ASC
    ");
    $stmt->execute([$target_title]);
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
            'fee' => $r['min_spec_fee'] ?: ($r['per_semester_fee'] ?: '—'),
            'specializations' => $r['specializations'] ?: '',
            'spec_count' => (int)$r['spec_count']
        ];
    }

    echo json_encode([
        'success' => true,
        'duration' => [
            'id' => (int)$duration['id'],
            'title' => $duration['duration_title'],
            'is_active' => (int)$duration['is_active']
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
