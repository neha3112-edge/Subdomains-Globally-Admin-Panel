<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

// Ensure migrations have run
sode_run_auto_migrations($db);

$uni_slug = trim($_GET['uni'] ?? ($_GET['university'] ?? ''));
$uni = null;

if (!empty($uni_slug)) {
    // 1. Try slug match
    $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(slug) = LOWER(?) AND is_active = 1 LIMIT 1");
    $stmt->execute([$uni_slug]);
    $uni = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Try short_name match
    if (!$uni) {
        $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(short_name) = LOWER(?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$uni_slug]);
        $uni = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch Global Keys for placeholder replacement
$global_keys = [];
try {
    $g_rows = $db->query("SELECT key_code, key_value FROM global_keys WHERE is_active = 1")->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($g_rows) {
        $global_keys = $g_rows;
    }
} catch (Exception $e) {}

// Fallback year/session
$y = date('Y');
$rep_map = [
    '$YEAR$'                    => $global_keys['$YEAR$'] ?? $y,
    '$session$'                 => $global_keys['$session$'] ?? ($y . '-' . substr((string)((int)$y + 1), -2)),
    '$nextyear$'                => $global_keys['$nextyear$'] ?? (string)((int)$y + 1),
    '{UNIVERSITY_NAME}'         => $uni['full_name'] ?? 'University',
    '{university_name}'         => $uni['full_name'] ?? 'University',
    '{UNIVERSITY_SHORT_NAME}'   => $uni['short_name'] ?? 'University',
    '{university_short_name}'   => $uni['short_name'] ?? 'University',
    '{MODE}'                    => $uni['mode'] ?? 'Online & Distance',
    '{mode}'                    => $uni['mode'] ?? 'Online & Distance',
    '{UNIVERSITY_LOCATION}'     => $uni['location'] ?? '',
];
foreach ($global_keys as $k => $v) {
    $rep_map[$k] = $v;
}

// Query news items: Global news first, then University-specific news
$uni_id = $uni ? (int)$uni['id'] : 0;
if ($uni_id > 0) {
    $stmt = $db->prepare("
        SELECT id, is_global, university_id, news_text, news_link, has_badge, badge_text, sort_order
        FROM news_items
        WHERE is_active = 1 AND (is_global = 1 OR university_id = ?)
        ORDER BY is_global DESC, sort_order ASC, id ASC
    ");
    $stmt->execute([$uni_id]);
} else {
    $stmt = $db->query("
        SELECT id, is_global, university_id, news_text, news_link, has_badge, badge_text, sort_order
        FROM news_items
        WHERE is_active = 1 AND is_global = 1
        ORDER BY is_global DESC, sort_order ASC, id ASC
    ");
}
$raw_news = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items = [];
foreach ($raw_news as $row) {
    $text = $row['news_text'];
    // Replace all placeholders
    foreach ($rep_map as $search => $replace) {
        $text = str_ireplace($search, $replace, $text);
    }

    $link = $row['news_link'];
    if (!empty($link)) {
        foreach ($rep_map as $search => $replace) {
            $link = str_ireplace($search, $replace, $link);
        }
    }

    $items[] = [
        'id'         => (int)$row['id'],
        'text'       => $text,
        'link'       => $link ?: '',
        'has_badge'  => (bool)$row['has_badge'],
        'badge_text' => $row['badge_text'] ?: 'New',
        'is_global'  => (bool)$row['is_global'],
        'sort_order' => (int)$row['sort_order'],
    ];
}

echo json_encode([
    'success'    => true,
    'uni'        => $uni_slug ?: null,
    'uni_found'  => !empty($uni),
    'university' => $uni ? $uni['full_name'] : null,
    'news'       => $items,
    'count'      => count($items)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
