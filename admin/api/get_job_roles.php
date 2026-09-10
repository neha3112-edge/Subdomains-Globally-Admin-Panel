<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$course_slug = trim($_GET['course'] ?? '');

$query = "
    SELECT jr.*, c.full_name AS course_full_name, c.short_name AS course_short_name, c.slug AS course_slug
    FROM job_roles jr
    INNER JOIN courses c ON jr.course_id = c.id
";

$params = [];
if (!empty($course_slug)) {
    $query .= " WHERE c.slug = ?";
    $params[] = $course_slug;
}

$query .= " ORDER BY c.short_name ASC, jr.sort_order ASC, jr.id ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$grouped = [];
foreach ($rows as $r) {
    $cslug = $r['course_slug'];
    if (!isset($grouped[$cslug])) {
        $grouped[$cslug] = [
            'course_name' => $r['course_full_name'],
            'course_short' => $r['course_short_name'],
            'course_slug' => $r['course_slug'],
            'roles' => []
        ];
    }
    $grouped[$cslug]['roles'][] = [
        'id' => (int)$r['id'],
        'role_name' => $r['role_name'],
        'role_link' => $r['role_link'],
        'role_description' => $r['role_description'],
        'salary_range_india' => $r['salary_range_india']
    ];
}

echo json_encode([
    'success' => true,
    'total' => count($rows),
    'data' => array_values($grouped)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
