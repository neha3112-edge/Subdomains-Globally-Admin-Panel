<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$row = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

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

echo json_encode([
    'success'       => true,
    'cta_heading'   => $row['cta_heading']   ?? 'Having Doubts ? Talk to Experts',
    'cta_subtext'   => $row['cta_subtext']   ?? 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
    'cta_btn_text'  => $row['cta_btn_text']  ?? 'Book Free 1:1 Counseling',
    'cta_btn_link'  => $row['cta_btn_link']  ?? '#',
    'cta_btn_phone' => $row['cta_btn_phone'] ?? '',
    'ai_tools_heading' => $row['ai_tools_heading'] ?? 'Explore AI Powered Tools',
    'ai_tools_subtext' => $row['ai_tools_subtext'] ?? 'Make smarter education decisions with AI-powered tools',
    'ai_tools'      => $ai_tools,
    'about_logo_url'  => $row['about_logo_url']  ?? '',
    'about_title'     => $row['about_title']     ?? 'About SODE™',
    'about_subtitle'  => $row['about_subtitle']  ?? '(School of Online and Distance Education)',
    'about_sode'      => $row['about_sode_text'] ?? '',
    'legal_notice_heading' => $row['legal_notice_heading'] ?? 'Legal Notice',
    'legal_notice'  => $row['legal_notice_text'] ?? '',
    'footer_links'  => $footer_links,
    'copyright'     => $row['copyright_text'] ?? '© ' . date('Y') . ' SODE™ Counselling Services LLP',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

