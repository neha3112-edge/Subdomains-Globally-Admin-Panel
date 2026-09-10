<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

// 1. Fetch Global API Integrations Settings from DB
$global_rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'integrations'")->fetchAll(PDO::FETCH_KEY_PAIR);

$global_crm_url          = $global_rows['crm_api_url'] ?? (getenv('CRM_API_URL') ?: 'https://api.crm.mysode.com/api/lead/apicreated');
$global_crm_key          = $global_rows['crm_api_key'] ?? (getenv('CRM_API_KEY') ?: '');
$global_crm_secret       = $global_rows['crm_secret'] ?? (getenv('CRM_SECRET') ?: '');
$global_brevo_url        = $global_rows['brevo_api_url'] ?? (getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/contacts');
$global_brevo_key        = $global_rows['brevo_api_key'] ?? (getenv('BREVO_API_KEY') ?: '');
$global_gallabox_webhook = $global_rows['gallabox_webhook_url'] ?? (getenv('GALLABOX_WEBHOOK_URL') ?: '');

$uni_slug = trim($_GET['uni'] ?? '');

if (empty($uni_slug)) {
    // If no slug requested, return global settings
    echo json_encode([
        'success' => true,
        'crm_api_url' => $global_crm_url,
        'crm_api_key' => $global_crm_key,
        'crm_secret' => $global_crm_secret,
        'brevo_api_url' => $global_brevo_url,
        'brevo_api_key' => $global_brevo_key,
        'gallabox_webhook_url' => $global_gallabox_webhook,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$stmt = $db->prepare("
    SELECT ufc.*, u.full_name, u.short_name, u.slug, u.brochure_pdf_url 
    FROM university_form_configs ufc 
    INNER JOIN universities u ON ufc.university_id = u.id 
    WHERE (u.slug = ? OR LOWER(u.short_name) = ?) AND u.is_active = 1
    LIMIT 1
");
$stmt->execute([$uni_slug, strtolower($uni_slug)]);
$cfg = $stmt->fetch();

$response = [
    'success' => true,
    'full_name' => $cfg['full_name'] ?? 'Dayananda Sagar University',
    'short_name' => $cfg['short_name'] ?? 'DSU',
    'slug' => $cfg['slug'] ?? $uni_slug,
    'brochure_pdf_url' => $cfg['brochure_pdf_url'] ?? '',
    'source' => !empty($cfg['source']) ? $cfg['source'] : 'MISC',
    'default_utm_source' => !empty($cfg['default_utm_source']) ? $cfg['default_utm_source'] : 'Organic',
    'default_utm_medium' => !empty($cfg['default_utm_medium']) ? $cfg['default_utm_medium'] : ($uni_slug . '_Organic'),
    'default_utm_campaign' => !empty($cfg['default_utm_campaign']) ? $cfg['default_utm_campaign'] : ($uni_slug . '_Organic'),
    'gallabox_source' => !empty($cfg['gallabox_source']) ? $cfg['gallabox_source'] : 'MISC',
    'gallabox_webhook_url' => $global_gallabox_webhook,
    'brevo_source' => !empty($cfg['brevo_source']) ? $cfg['brevo_source'] : 'MISC',
    'brevo_list_id' => !empty($cfg['brevo_list_id']) ? (int)$cfg['brevo_list_id'] : 124,
    'brevo_api_url' => $global_brevo_url,
    'brevo_api_key' => $global_brevo_key,
    'crm_api_url' => $global_crm_url,
    'crm_api_key' => $global_crm_key,
    'crm_secret' => $global_crm_secret,
    'allowed_courses_json' => !empty($cfg['allowed_courses_json']) ? $cfg['allowed_courses_json'] : 'MBA, MCA, MCOM, MA, MSC, MLIS, BBA, BCA, BCOM, BA, BSC, BLIS, Other'
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

