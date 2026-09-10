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

$slug = trim($_GET['slug'] ?? $_GET['uni'] ?? '');
$id = (int)($_GET['id'] ?? 0);

if (empty($slug) && $id <= 0) {
    // If no specific university requested, return list of all active universities
    $stmt = $db->query("SELECT id, full_name, short_name, slug, mode, location, logo_url, desktop_banner_bg, mobile_banner_bg FROM universities WHERE is_active = 1 ORDER BY short_name ASC");
    $all = $stmt->fetchAll();
    echo json_encode([
        'success' => true,
        'count' => count($all),
        'universities' => $all
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Search university by slug, short_name, or ID
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM universities WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare("SELECT * FROM universities WHERE (slug = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?) AND is_active = 1 LIMIT 1");
    $stmt->execute([$slug, strtolower($slug), strtolower($slug)]);
}

$uni = $stmt->fetch();

if (!$uni) {
    echo json_encode([
        'success' => false,
        'message' => 'University not found or inactive for slug: ' . $slug
    ]);
    exit;
}

// Fetch accreditations for this university
$acc_stmt = $db->prepare("
    SELECT a.id, a.title, a.image_url, a.image_url AS badge_image_url, a.description, a.official_link
    FROM university_accreditations ua
    INNER JOIN accreditations a ON ua.accreditation_id = a.id
    WHERE ua.university_id = ?
    ORDER BY a.title ASC
");
$acc_stmt->execute([$uni['id']]);
$accreditations = $acc_stmt->fetchAll();

// Fetch relevant global keys
$keys_stmt = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1");
$global_keys = [];
while ($row = $keys_stmt->fetch()) {
    $global_keys[$row['key_code']] = $row['key_value'];
}

echo json_encode([
    'success' => true,
    'data' => [
        'id' => (int)$uni['id'],
        'full_name' => $uni['full_name'],
        'short_name' => $uni['short_name'],
        'slug' => $uni['slug'],
        'mode' => $uni['mode'] ?? 'Online & Distance',
        'location' => $uni['location'] ?? '',
        'official_url' => $uni['official_url'] ?? '',
        'advantage_text' => $uni['advantage_text'] ?? '',
        'logo_url' => $uni['logo_url'] ?? '',
        'desktop_banner_bg' => $uni['desktop_banner_bg'] ?? '',
        'mobile_banner_bg' => $uni['mobile_banner_bg'] ?? '',
        'campus_mobile_img' => $uni['campus_mobile_img'] ?? '',
        'brochure_pdf_url' => $uni['brochure_pdf_url'] ?? '',
        'podcast_audio_url' => $uni['podcast_audio_url'] ?? '',
        'youtube_video_url' => $uni['youtube_video_url'] ?? '',
        'exam_date' => $uni['exam_date'] ?? '',
        'extended_exam_date' => $uni['extended_exam_date'] ?? '',
        'admission_last_date' => $uni['admission_last_date'] ?? '',
        'admission_start_date' => $uni['admission_start_date'] ?? '',
        'assignment_date' => $uni['assignment_date'] ?? '',
        'rating' => (float)$uni['rating'],
        'accreditations' => $accreditations,
        'global_keys' => $global_keys
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
