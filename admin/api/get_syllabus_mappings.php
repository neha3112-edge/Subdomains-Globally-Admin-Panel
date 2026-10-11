<?php
/**
 * Admin API: Get Course Mappings for a Specific Master Syllabus Subject
 * File: admin/api/get_syllabus_mappings.php
 * Query Params: ?subject_id=1 OR ?subject_name=20th Century British Drama
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

$subject_id = (int)($_GET['subject_id'] ?? 0);
$subject_name = trim($_GET['subject_name'] ?? '');

if ($subject_id <= 0 && empty($subject_name)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Subject ID or Name', 'mappings' => []]);
    exit;
}

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Fetch subject master record
    if ($subject_id > 0) {
        $stmtSub = $db->prepare("SELECT id, subject_name, is_active FROM course_syllabus_subjects_master WHERE id = ? LIMIT 1");
        $stmtSub->execute([$subject_id]);
    } else {
        $stmtSub = $db->prepare("SELECT id, subject_name, is_active FROM course_syllabus_subjects_master WHERE subject_name = ? LIMIT 1");
        $stmtSub->execute([$subject_name]);
    }
    $subject = $stmtSub->fetch(PDO::FETCH_ASSOC);

    if (!$subject) {
        echo json_encode(['success' => false, 'message' => 'Subject not found', 'mappings' => []]);
        exit;
    }

    $target_name = $subject['subject_name'];

    // Fetch mappings with syllabus_json
    $stmt = $db->prepare("
        SELECT 
            ucm.id AS mapping_id,
            ucm.university_id,
            ucm.course_id,
            ucm.mode,
            ucm.syllabus_json,
            u.full_name AS uni_name,
            u.short_name AS uni_short,
            u.is_active AS uni_active,
            c.full_name AS course_name,
            c.short_name AS course_short,
            c.level AS course_level
        FROM university_course_mappings ucm
        INNER JOIN universities u ON ucm.university_id = u.id
        INNER JOIN courses c ON ucm.course_id = c.id
        WHERE ucm.syllabus_json IS NOT NULL AND ucm.syllabus_json != ''
        ORDER BY 
            u.is_active DESC,
            u.short_name ASC,
            c.short_name ASC,
            ucm.id ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted = [];
    foreach ($rows as $r) {
        $data = json_decode($r['syllabus_json'], true);
        if (!is_array($data)) continue;

        $matched_semesters = [];
        foreach ($data as $sem_idx => $sem) {
            $sem_title = trim($sem['semester_title'] ?? ('Semester ' . ($sem_idx + 1)));
            if (!empty($sem['subjects']) && is_array($sem['subjects'])) {
                foreach ($sem['subjects'] as $sub_item) {
                    $sname = trim($sub_item['name'] ?? '');
                    if ($sname === $target_name) {
                        $matched_semesters[] = $sem_title;
                        break;
                    }
                }
            }
        }

        if (!empty($matched_semesters)) {
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
                'semesters' => implode(', ', $matched_semesters)
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'subject' => [
            'id' => (int)$subject['id'],
            'name' => $subject['subject_name'],
            'is_active' => (int)$subject['is_active']
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
