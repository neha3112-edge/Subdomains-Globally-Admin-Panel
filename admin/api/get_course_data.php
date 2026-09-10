<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$uni_slug = trim($_GET['uni'] ?? '');
$course_slug = trim($_GET['course'] ?? '');

$query = "
    SELECT 
        ucm.id AS mapping_id,
        u.id AS uni_id,
        u.full_name AS university_full_name,
        u.short_name AS university_short_name,
        u.slug AS university_slug,
        u.mode AS university_mode,
        u.location AS university_location,
        u.official_url AS university_official_url,
        u.advantage_text AS university_advantage_text,
        u.logo_url AS university_logo_url,
        u.desktop_banner_bg,
        u.mobile_banner_bg,
        u.campus_mobile_img,
        u.brochure_pdf_url,
        u.podcast_audio_url,
        u.youtube_video_url,
        u.exam_date,
        u.extended_exam_date,
        u.admission_last_date,
        u.admission_start_date,
        u.assignment_date,
        u.rating AS university_rating,
        c.id AS course_id,
        c.full_name AS course_full_name,
        c.short_name AS course_short_name,
        c.slug AS course_slug,
        c.level AS course_level,
        c.description AS course_master_description,
        ucm.course_description AS mapping_course_description,
        ucm.course_link,
        ucm.eligibility_text,
        ucm.one_time_processing_fee,
        ucm.tuition_fee,
        ucm.examination_fee,
        ucm.per_semester_fee,
        ucm.total_program_fee
    FROM university_course_mappings ucm
    INNER JOIN universities u ON ucm.university_id = u.id
    INNER JOIN courses c ON ucm.course_id = c.id
    WHERE u.is_active = 1
";

$params = [];
if (!empty($uni_slug)) {
    $query .= " AND u.slug = ?";
    $params[] = $uni_slug;
}
if (!empty($course_slug)) {
    $query .= " AND c.slug = ?";
    $params[] = $course_slug;
}

$query .= " ORDER BY u.short_name ASC, c.level DESC, c.full_name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Fetch specializations for mappings
$mapping_ids = array_column($rows, 'mapping_id');
$specs_by_mapping = [];

if (!empty($mapping_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($mapping_ids), '?'));
    $spec_stmt = $db->prepare("SELECT * FROM course_specializations WHERE mapping_id IN ($in_placeholders) ORDER BY id ASC");
    $spec_stmt->execute($mapping_ids);
    $specs_all = $spec_stmt->fetchAll();

    foreach ($specs_all as $s) {
        $specs_by_mapping[$s['mapping_id']][] = [
            'name' => $s['specialization_name'],
            'fees_per_sem' => $s['fees_per_sem'],
            'duration' => $s['duration']
        ];
    }
}

// Fetch accreditations for universities
$uni_ids = array_unique(array_column($rows, 'uni_id'));
$accs_by_uni = [];

if (!empty($uni_ids)) {
    $in_placeholders = implode(',', array_fill(0, count($uni_ids), '?'));
    $acc_stmt = $db->prepare("
        SELECT ua.university_id, a.title, a.image_url, a.official_link, a.description 
        FROM university_accreditations ua
        INNER JOIN accreditations a ON ua.accreditation_id = a.id
        WHERE ua.university_id IN ($in_placeholders)
        ORDER BY a.id ASC
    ");
    $acc_stmt->execute(array_values($uni_ids));
    $accs_all = $acc_stmt->fetchAll();

    foreach ($accs_all as $a) {
        $accs_by_uni[$a['university_id']][] = [
            'title' => $a['title'],
            'image_url' => $a['image_url'],
            'official_link' => $a['official_link'],
            'description' => $a['description']
        ];
    }
}

// Format JSON response
$data = [];
foreach ($rows as $r) {
    $data[] = [
        'mapping_id' => (int)$r['mapping_id'],
        'university' => [
            'id' => (int)$r['uni_id'],
            'full_name' => $r['university_full_name'],
            'short_name' => $r['university_short_name'],
            'slug' => $r['university_slug'],
            'mode' => $r['university_mode'],
            'location' => $r['university_location'],
            'official_url' => $r['university_official_url'],
            'advantage_text' => $r['university_advantage_text'],
            'logo_url' => $r['university_logo_url'],
            'desktop_banner_bg' => $r['desktop_banner_bg'],
            'mobile_banner_bg' => $r['mobile_banner_bg'],
            'campus_mobile_img' => $r['campus_mobile_img'],
            'brochure_pdf_url' => $r['brochure_pdf_url'],
            'podcast_audio_url' => $r['podcast_audio_url'],
            'youtube_video_url' => $r['youtube_video_url'],
            'exam_date' => $r['exam_date'],
            'extended_exam_date' => $r['extended_exam_date'],
            'admission_last_date' => $r['admission_last_date'],
            'admission_start_date' => $r['admission_start_date'],
            'assignment_date' => $r['assignment_date'],
            'rating' => (float)$r['university_rating'],
            'accreditations' => $accs_by_uni[$r['uni_id']] ?? []
        ],
        'course' => [
            'id' => (int)$r['course_id'],
            'full_name' => $r['course_full_name'],
            'short_name' => $r['course_short_name'],
            'slug' => $r['course_slug'],
            'level' => $r['course_level'],
            'description' => $r['mapping_course_description'] ?: $r['course_master_description'],
            'link' => $r['course_link']
        ],
        'fees' => [
            'eligibility' => $r['eligibility_text'],
            'one_time_processing_fee' => $r['one_time_processing_fee'],
            'tuition_fee' => $r['tuition_fee'],
            'examination_fee' => $r['examination_fee'],
            'per_semester_fee' => $r['per_semester_fee'],
            'total_program_fee' => $r['total_program_fee']
        ],
        'specializations' => $specs_by_mapping[$r['mapping_id']] ?? []
    ];
}

echo json_encode([
    'success' => true,
    'total' => count($data),
    'data' => $data
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
