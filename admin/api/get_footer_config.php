<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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

// Ensure about_logo_url is a full absolute URL
$about_logo = !empty($row['about_logo_url']) ? get_asset_url($row['about_logo_url']) : '';

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
    'about_logo_url'       => $about_logo,
    'about_title'          => $row['about_title']     ?? 'About SODE™',
    'about_subtitle'       => $row['about_subtitle']  ?? '(School of Online and Distance Education)',
    'about_sode'           => $row['about_sode_text'] ?? '',
    'legal_notice_heading' => $row['legal_notice_heading'] ?? 'Legal Notice',
    'legal_notice'         => $legal_text,
    'footer_links'         => $footer_links,
    'copyright'            => $row['copyright_text'] ?? '© ' . date('Y') . ' SODE™ Counselling Services LLP',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

