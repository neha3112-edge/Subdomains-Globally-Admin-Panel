<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

// Detect requested university slug
$uni_param = trim($_GET['uni'] ?? ($_GET['university'] ?? ($_POST['uni'] ?? '')));
if (empty($uni_param) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref_host = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
    if ($ref_host) {
        $parts = explode('.', strtolower($ref_host));
        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'admin', 'mail', 'cpanel'])) {
            $uni_param = $parts[0];
        }
    }
}
if (empty($uni_param) && !empty($_SERVER['HTTP_HOST'])) {
    $parts = explode('.', strtolower($_SERVER['HTTP_HOST']));
    if (count($parts) >= 3 && !in_array($parts[0], ['www', 'admin', 'mail', 'cpanel'])) {
        $uni_param = $parts[0];
    }
}
if (empty($uni_param)) {
    $uni_param = 'dsu';
}

// Fetch official_url for this university
$official_url = '';
if ($db && $uni_param) {
    try {
        $u_stmt = $db->prepare("SELECT official_url FROM universities WHERE (LOWER(slug) = LOWER(?) OR LOWER(short_name) = LOWER(?) OR LOWER(full_name) = LOWER(?)) AND is_active = 1 LIMIT 1");
        $u_stmt->execute([$uni_param, $uni_param, $uni_param]);
        $official_url = $u_stmt->fetchColumn() ?: '';
    } catch (Exception $e) {}
}

if (empty($official_url) && ($uni_param === 'dsu' || strpos($uni_param, 'dayananda') !== false)) {
    $official_url = 'https://dsuonline.com/';
}

$row = $db ? ($db->query("SELECT * FROM footer_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: []) : [];

$ai_tools = [];
if (!empty($row['ai_tools_cards_json'])) {
    $decoded = json_decode($row['ai_tools_cards_json'], true);
    if (is_array($decoded)) $ai_tools = $decoded;
}

$footer_links = [];
if (!empty($row['footer_links_json'])) {
    $decoded = json_decode($row['footer_links_json'], true);
    if (is_array($decoded)) $footer_links = $decoded;
}

// Resolve {official_url} placeholder in legal notice
$legal_text = $row['legal_notice_text'] ?? '';
if (!empty($official_url) && strpos($legal_text, '{official_url}') !== false) {
    $domain = preg_replace('#^https?://#', '', rtrim($official_url, '/'));
    $linked = '<a href="' . htmlspecialchars($official_url) . '" target="_blank" rel="nofollow" style="color:#F5C518;text-decoration:underline;">' . htmlspecialchars($domain) . '</a>';
    $legal_text = str_replace('{official_url}', $linked, $legal_text);
}

// Fetch Global Keys for Contact Information (Phone, Email, Address)
$contact_phone = '+91 70657 777 55';
$contact_phone_link = 'tel:+917065777755';
$contact_email = 'support@distanceeducationschool.com';
$contact_email_link = 'mailto:support@distanceeducationschool.com';
$contact_address = 'Unit No. 1, 3rd Floor Vardhman Trade Centre, Nehru Place, New Delhi - 110019';
$contact_address_link = '';

if ($db) {
    try {
        $gk_stmt = $db->query("SELECT key_code, key_value, link_url FROM global_keys WHERE is_active = 1");
        $gk_rows = $gk_stmt ? $gk_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($gk_rows as $gk) {
            $code = strtoupper(trim($gk['key_code'], '$ '));
            $val  = trim($gk['key_value']);
            $url  = trim($gk['link_url'] ?? '');

            if (in_array($code, ['PHONE', 'PHONE_NUMBER', 'MOBILE', 'CONTACT_NUMBER'])) {
                if (!empty($val)) $contact_phone = $val;
                $contact_phone_link = !empty($url) ? $url : ('tel:' . preg_replace('/[^0-9+]/', '', $val));
            }
            if (in_array($code, ['EMAIL', 'EMAIL_ADDRESS', 'SUPPORT_EMAIL', 'CONTACT_EMAIL'])) {
                if (!empty($val)) $contact_email = $val;
                $contact_email_link = !empty($url) ? $url : ('mailto:' . $val);
            }
            if (in_array($code, ['ADDRESS', 'OFFICE_ADDRESS', 'LOCATION', 'CONTACT_ADDRESS'])) {
                if (!empty($val)) $contact_address = $val;
                if (!empty($url)) $contact_address_link = $url;
            }
        }
    } catch (Exception $e) {}
}

echo json_encode([
    'success'              => true,
    'university_slug'      => $uni_param,
    'official_url'         => $official_url,
    'cta_heading'          => $row['cta_heading']   ?? 'Having Doubts ? Talk to Experts',
    'cta_subtext'          => $row['cta_subtext']   ?? 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
    'cta_btn_text'         => $row['cta_btn_text']  ?? 'Book Free 1:1 Counseling',
    'cta_btn_link'         => $row['cta_btn_link']  ?? '#',
    'cta_btn_phone'        => $row['cta_btn_phone'] ?? '',
    'cta_btn_class'        => $row['cta_btn_class'] ?? '',
    'cta_btn_newtab'       => (int)($row['cta_btn_newtab'] ?? 0),
    'ai_tools_heading'     => $row['ai_tools_heading'] ?? 'Explore AI Powered Tools',
    'ai_tools_subtext'     => $row['ai_tools_subtext'] ?? 'Make smarter education decisions with AI-powered tools',
    'ai_tools'             => $ai_tools,
    'about_logo_url'       => $row['about_logo_url'] ?? '',
    'about_title'          => $row['about_title']     ?? 'About SODE™',
    'about_subtitle'       => $row['about_subtitle']  ?? '(School of Online and Distance Education)',
    'about_sode'           => $row['about_sode_text'] ?? '',
    'legal_notice_heading' => $row['legal_notice_heading'] ?? 'Legal Notice',
    'legal_notice'         => $legal_text,
    'footer_links'         => $footer_links,
    'contact_phone'        => $contact_phone,
    'contact_phone_link'   => $contact_phone_link,
    'contact_email'        => $contact_email,
    'contact_email_link'   => $contact_email_link,
    'contact_address'      => $contact_address,
    'contact_address_link' => $contact_address_link,
    'copyright'            => $row['copyright_text'] ?? '© ' . date('Y') . ' SODE™ Counselling Services LLP',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


