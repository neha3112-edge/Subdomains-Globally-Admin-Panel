<?php
/**
 * API Endpoint: Get Alternate Universities List
 * Returns all active universities marked with show_in_alternate = 1
 * along with their linked accreditations and mapped courses.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__DIR__) . '/config/config.php';

try {
    $db = get_db_connection();

    // Auto-check and add columns if missing
    $alt_col_chk = $db->query("SHOW COLUMNS FROM universities LIKE 'show_in_alternate'")->fetch();
    if (!$alt_col_chk) {
        $db->exec("ALTER TABLE universities 
            ADD COLUMN alt_desktop_img TEXT NULL AFTER campus_mobile_img,
            ADD COLUMN alt_mobile_img TEXT NULL AFTER alt_desktop_img,
            ADD COLUMN sample_degree_img TEXT NULL AFTER alt_mobile_img,
            ADD COLUMN alt_description TEXT NULL AFTER sample_degree_img,
            ADD COLUMN show_in_alternate TINYINT(1) DEFAULT 0 AFTER alt_description");
    }

    $stmt = $db->query("
        SELECT id, full_name, short_name, slug, mode, location, advantage_text,
               logo_url, campus_mobile_img, alt_desktop_img, alt_mobile_img, sample_degree_img,
               alt_description, rating, is_active
        FROM universities
        WHERE show_in_alternate = 1 AND is_active = 1
        ORDER BY id ASC
    ");
    $unis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $acc_stmt = $db->prepare("
        SELECT a.id, a.title, a.image_url
        FROM accreditations a
        JOIN university_accreditations ua ON a.id = ua.accreditation_id
        WHERE ua.university_id = ?
        ORDER BY a.id ASC
    ");

    $course_stmt = $db->prepare("
        SELECT c.id AS course_id, c.short_name, c.full_name, c.level,
               ucm.eligibility_text, ucm.per_semester_fee
        FROM university_course_mappings ucm
        JOIN courses c ON ucm.course_id = c.id
        WHERE ucm.university_id = ?
        ORDER BY ucm.id ASC
    ");

    $result = [];

    foreach ($unis as $u) {
        $acc_stmt->execute([$u['id']]);
        $accs = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);
        $acc_titles = array_column($accs, 'title');

        $course_stmt->execute([$u['id']]);
        $courses = $course_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build list of course names for replacement if shortcode present
        $c_short_names = array_column($courses, 'short_name');
        $c_text_list = '';
        if (!empty($c_short_names)) {
            if (count($c_short_names) === 1) {
                $c_text_list = $c_short_names[0];
            } else {
                $last_c = array_pop($c_short_names);
                $c_text_list = implode(', ', $c_short_names) . ' and ' . $last_c;
            }
        }

        $desc = $u['alt_description'] ?? '';
        if (!empty($desc)) {
            $desc = str_replace(
                ['[university_courses_list and="true"]', '[university_courses_list]', '[courses_list]'],
                $c_text_list,
                $desc
            );
        }

        // Format fees: ensure no raw rupee or trailing slashes, clean string
        foreach ($courses as &$crs) {
            $f = trim($crs['per_semester_fee'] ?? '');
            $f = str_replace(['₹', 'Rs.', 'Rs'], '', $f);
            $crs['per_semester_fee'] = trim($f);
        }
        unset($crs);

        $result[] = [
            'id' => (int) $u['id'],
            'full_name' => $u['full_name'],
            'short_name' => $u['short_name'],
            'slug' => $u['slug'],
            'mode' => $u['mode'],
            'location' => $u['location'] ?? '',
            'advantage_text' => $u['advantage_text'] ?? '',
            'alt_desktop_img' => !empty($u['alt_desktop_img']) ? get_asset_url($u['alt_desktop_img']) : '',
            'alt_mobile_img' => !empty($u['alt_mobile_img']) ? get_asset_url($u['alt_mobile_img']) : '',
            'sample_degree_img' => !empty($u['sample_degree_img']) ? get_asset_url($u['sample_degree_img']) : '',
            'logo_url' => !empty($u['logo_url']) ? get_asset_url($u['logo_url']) : '',
            'alt_description' => $desc,
            'approvals' => $acc_titles,
            'approvals_string' => implode(' | ', $acc_titles),
            'courses' => $courses,
            'courses_count' => count($courses),
        ];
    }

    echo json_encode([
        'status' => 'success',
        'count' => count($result),
        'data' => $result
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
