<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

// ── 1. Global Keys (always returned) ──────────────────────────────────
$global_rows = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1")->fetchAll();
$map = [];
foreach ($global_rows as $k) {
    $map[$k['key_code']] = $k['key_value'];
}

// ── 2. University-Specific Keys (returned when ?uni=slug passed) ───────
$uni_slug  = trim($_GET['uni'] ?? '');
$uni       = null;
$all_slugs = [];

if (!empty($uni_slug)) {
    // Case-insensitive slug match
    $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(slug) = LOWER(?) AND is_active = 1 LIMIT 1");
    $stmt->execute([$uni_slug]);
    $uni = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug: get all slugs so we can identify mismatch
    $all_slugs = $db->query("SELECT slug, full_name, is_active FROM universities ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    if ($uni) {
        $uni_keys = [
            // University Name
            '{UNIVERSITY_NAME}'          => $uni['full_name']  ?? '',
            '{university_name}'          => $uni['full_name']  ?? '',
            '$UNIVERSITY_NAME$'          => $uni['full_name']  ?? '',

            // Short Name
            '{UNIVERSITY_SHORT_NAME}'    => $uni['short_name'] ?? '',
            '{university_short_name}'    => $uni['short_name'] ?? '',
            '$UNIVERSITY_SHORT_NAME$'    => $uni['short_name'] ?? '',

            // Slug
            '{UNIVERSITY_SLUG}'          => $uni['slug']       ?? '',
            '$UNIVERSITY_SLUG$'          => $uni['slug']       ?? '',

            // Mode (Online / Distance / ODL)
            '{MODE}'                     => $uni['mode']       ?? '',
            '{mode}'                     => $uni['mode']       ?? '',
            '$MODE$'                     => $uni['mode']       ?? '',

            // Location
            '{UNIVERSITY_LOCATION}'      => $uni['location']   ?? '',
            '{location}'                 => $uni['location']   ?? '',
            '$LOCATION$'                 => $uni['location']   ?? '',

            // URLs & Assets
            '{UNIVERSITY_URL}'           => $uni['official_url']  ?? '',
            '{UNIVERSITY_LOGO}'          => $uni['logo_url']      ?? '',
            '$LOGO_URL$'                 => $uni['logo_url']      ?? '',

            // Dates
            '{ADMISSION_LAST_DATE}'      => $uni['admission_last_date']  ?? '',
            '$ADMISSION_LAST_DATE$'      => $uni['admission_last_date']  ?? '',
            '{ADMISSION_START_DATE}'     => $uni['admission_start_date'] ?? '',
            '$ADMISSION_START_DATE$'     => $uni['admission_start_date'] ?? '',
            '{EXAM_DATE}'                => $uni['exam_date']            ?? '',
            '$EXAM_DATE$'                => $uni['exam_date']            ?? '',
            '{ASSIGNMENT_DATE}'          => $uni['assignment_date']      ?? '',
            '$ASSIGNMENT_DATE$'          => $uni['assignment_date']      ?? '',

            // Rating
            '{UNIVERSITY_RATING}'        => $uni['rating'] ?? '',
            '$RATING$'                   => $uni['rating'] ?? '',
        ];

        // Merge — university keys override global keys if same code
        $map = array_merge($map, $uni_keys);
    }
}

echo json_encode([
    'success'   => true,
    'uni'       => $uni_slug ?: null,
    'uni_found' => !empty($uni),     // debug: was university found in DB?
    'all_slugs' => $all_slugs,       // debug: all slugs available in DB
    'keys'      => $map,
    'data'      => $map,
    'count'     => count($map),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
