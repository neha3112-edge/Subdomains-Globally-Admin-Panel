<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

$row = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch();

$ai_tools = [];
if (!empty($row['ai_tools_cards_json'])) {
    $decoded = json_decode($row['ai_tools_cards_json'], true);
    if (is_array($decoded)) {
        $ai_tools = $decoded;
    }
}

echo json_encode([
    'success' => true,
    'about_sode' => $row['about_sode_text'] ?? '',
    'legal_notice' => $row['legal_notice_text'] ?? '',
    'copyright' => $row['copyright_text'] ?? '© 2026 SODE™ Counseling Services LLP',
    'ai_tools' => $ai_tools
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
