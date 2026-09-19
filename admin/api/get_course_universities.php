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

        $current_uni = null;
        $uni_slug = strtolower(trim($_GET['uni'] ?? ''));
        if (!empty($uni_slug)) {
            $u_stmt = $db->query("SELECT id, full_name, short_name, slug, location, official_url, advantage_text FROM universities WHERE is_active = 1 ORDER BY id ASC");
            $all_unis = $u_stmt->fetchAll(PDO::FETCH_ASSOC);

            $matched_u = null;
            $term = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', $uni_slug)));
            if ($term !== '') {
                foreach ($all_unis as $u) {
                    $clean_slug = strtolower(str_replace(['-', '_', ' '], '', $u['slug'] ?? ''));
                    $clean_short = strtolower(str_replace(['-', '_', ' ', '.'], '', $u['short_name'] ?? ''));
                    $clean_full = strtolower(str_replace(['-', '_', ' ', '.', ','], '', $u['full_name'] ?? ''));
                    if ($term === $clean_slug || $term === $clean_short || $term === $clean_full) {
                        $matched_u = $u;
                        break;
                    }
                }
                if (!$matched_u) {
                    foreach ($all_unis as $u) {
                        $slug_parts = explode('-', strtolower($u['slug'] ?? ''));
                        $slug_ac = '';
                        foreach ($slug_parts as $sp) {
                            if ($sp !== '') $slug_ac .= $sp[0];
                        }
                        if ($term === $slug_ac) {
                            $matched_u = $u;
                            break;
                        }
                    }
                }
            }

            if ($matched_u) {
                $clean_ck = str_replace('.', '', strtolower($course_slug));
                $c_stmt = $db->prepare("
                    SELECT ucm.per_semester_fee, ucm.course_link, c.short_name as c_short, c.full_name as c_full
                    FROM university_course_mappings ucm
                    JOIN courses c ON ucm.course_id = c.id
                    WHERE ucm.university_id = ? AND (
                        REPLACE(LOWER(c.short_name), '.', '') = ? 
                        OR LOWER(c.short_name) = LOWER(?) 
                        OR LOWER(c.full_name) LIKE LOWER(?)
                    )
                    LIMIT 1
                ");
                $c_stmt->execute([$matched_u['id'], $clean_ck, $course_slug, '%' . $course_slug . '%']);
                $course_map = $c_stmt->fetch(PDO::FETCH_ASSOC);

                // Agar university me ye course mapped hai tabhi current_uni return karo
                if ($course_map) {
                    $fee_display = '₹ --';
                    if (!empty($course_map['per_semester_fee'])) {
                        $raw_fee = trim($course_map['per_semester_fee']);
                        if (strpos($raw_fee, '₹') !== false) {
                            $fee_display = $raw_fee;
                        } else {
                            $clean_num = str_replace([',', ' '], '', $raw_fee);
                            if (is_numeric($clean_num)) {
                                $fee_display = '₹' . number_format((float) $clean_num);
                            } else {
                                $fee_display = '₹' . $raw_fee;
                            }
                        }
                    }

                    $a_stmt = $db->prepare("
                        SELECT a.title 
                        FROM university_accreditations ua 
                        JOIN accreditations a ON ua.accreditation_id = a.id 
                        WHERE ua.university_id = ?
                        ORDER BY ua.id ASC
                    ");
                    $a_stmt->execute([$matched_u['id']]);
                    $acc_titles = $a_stmt->fetchAll(PDO::FETCH_COLUMN);
                    $accreditation_str = !empty($acc_titles) ? implode(', ', $acc_titles) : 'UGC, NAAC A+';

                    $link = !empty($course_map['course_link']) ? $course_map['course_link'] : (!empty($matched_u['official_url']) ? $matched_u['official_url'] : '');
                    $advantage = !empty($matched_u['advantage_text']) ? $matched_u['advantage_text'] : 'Dedicated Career Support';

                    $current_uni = [
                        'name'          => $matched_u['full_name'],
                        'slug'          => $matched_u['slug'],
                        'fees'          => $fee_display,
                        'location'      => $matched_u['location'] ?: 'India',
                        'accreditation' => $accreditation_str,
                        'advantage'     => $advantage,
                        'link'          => $link,
                        'new_tab'       => 1,
                        'is_current'    => true,
                    ];
                }
            }
        }

        echo json_encode([
            'success'            => true,
            'course_slug'        => $row['course_slug'],
            'course_name'        => $row['course_name'],
            'heading'            => $row['heading'],
            'description'        => $row['description'],
            'columns'            => $cols,
            'total_universities' => count($unis),
            'universities'       => $unis,
            'current_university' => $current_uni
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
