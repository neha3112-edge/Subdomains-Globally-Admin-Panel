<?php
/**
 * API Health & Endpoints Monitor - Server-Side Paginated Registry
 */
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title      = 'API Health & Endpoints Monitor';
$page_subtitle   = 'Live status, latency analytics, and auto-discovery of all 3rd-party integrations & internal REST endpoints';
$active_page_key = 'api_health';

$db = get_db_connection();

// 1. Fetch integration credentials
$rows = [];
try {
    $rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'integrations'")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

$crm_api_url          = $rows['crm_api_url']          ?? 'https://api.crm.mysode.com/api/lead/apicreated';
$crm_api_key          = $rows['crm_api_key']           ?? '';
$crm_secret           = $rows['crm_secret']            ?? '';
$brevo_api_url        = $rows['brevo_api_url']          ?? 'https://api.brevo.com/v3/contacts';
$brevo_api_key        = $rows['brevo_api_key']          ?? '';
$gallabox_webhook_url = $rows['gallabox_webhook_url']   ?? '';

// Safe Base URLs
$parsed_base    = parse_url(BASE_URL);
$scheme         = $parsed_base['scheme'] ?? 'http';
$host           = $parsed_base['host'] ?? 'localhost';
$port           = !empty($parsed_base['port']) ? ':' . $parsed_base['port'] : '';
$path           = $parsed_base['path'] ?? '';
$admin_base_url = rtrim(BASE_URL, '/');
$root_path      = rtrim(preg_replace('/\/admin(\/.*)?$/i', '', $path), '/');
$root_base_url  = $scheme . '://' . $host . $port . $root_path;

function get_api_query_params($filename) {
    if (strpos($filename, 'course') !== false && (strpos($filename, 'uni') !== false || strpos($filename, 'fees') !== false)) {
        return '?uni=test&course=test';
    } elseif (strpos($filename, 'uni') !== false || strpos($filename, 'banner') !== false || strpos($filename, 'table') !== false) {
        return '?uni=test';
    } elseif (strpos($filename, 'course') !== false) {
        return '?course=test';
    } elseif ($filename === 'render_component.php') {
        return '?component=header';
    }
    return '';
}

// 2. Discover all endpoints
$all_discovered_endpoints = [];

// 3rd-Party Integrations
$all_discovered_endpoints[] = [
    'id'          => 'crm',
    'name'        => 'SODE CRM API',
    'description' => 'Primary lead capture and admission CRM pipeline',
    'type'        => 'external',
    'category'    => 'CRM',
    'url'         => $crm_api_url,
    'method'      => 'POST',
    'file_name'   => '3rd-Party Service',
    'configured'  => !empty($crm_api_key) && !empty($crm_secret),
];

$all_discovered_endpoints[] = [
    'id'          => 'brevo',
    'name'        => 'Brevo (Sendinblue) API',
    'description' => 'Email & SMS marketing list sync and automation',
    'type'        => 'external',
    'category'    => 'Email & SMS',
    'url'         => $brevo_api_url,
    'method'      => 'HEAD',
    'file_name'   => '3rd-Party Service',
    'configured'  => !empty($brevo_api_key),
];

$all_discovered_endpoints[] = [
    'id'          => 'gallabox',
    'name'        => 'Gallabox WhatsApp Webhook',
    'description' => 'Central WhatsApp notification & engagement webhook',
    'type'        => 'external',
    'category'    => 'WhatsApp',
    'url'         => $gallabox_webhook_url,
    'method'      => 'POST',
    'file_name'   => '3rd-Party Webhook',
    'configured'  => !empty($gallabox_webhook_url),
];

// Admin REST Feeds (/admin/api)
$admin_api_dir = dirname(__DIR__, 2) . '/admin/api';
if (is_dir($admin_api_dir)) {
    $admin_files = glob($admin_api_dir . '/*.php');
    sort($admin_files);
    foreach ($admin_files as $file_path) {
        $filename = basename($file_path);
        if ($filename === 'check_api_health.php') continue;

        $slug = str_replace(['get_', '.php'], '', $filename);
        $clean_name = ucwords(str_replace('_', ' ', $slug));
        if (!preg_match('/API$/i', $clean_name)) {
            $clean_name .= ' API';
        }

        $desc = "Admin REST Feed: " . $filename;
        $file_content = @file_get_contents($file_path, false, null, 0, 400);
        if ($file_content && preg_match('/\/\*\*\s*\n\s*\*\s*([^\n\*]+)/', $file_content, $m)) {
            $desc = trim($m[1]);
        }

        $method = (strpos($filename, 'upload') !== false || strpos($filename, 'delete') !== false) ? 'POST' : 'GET';
        $test_query = get_api_query_params($filename);

        $all_discovered_endpoints[] = [
            'id'          => 'admin_' . str_replace('.php', '', $filename),
            'name'        => $clean_name,
            'description' => $desc,
            'type'        => 'internal_admin',
            'category'    => 'Admin REST (/admin/api)',
            'file_name'   => 'admin/api/' . $filename,
            'url'         => $admin_base_url . '/api/' . $filename . $test_query,
            'method'      => $method,
            'configured'  => true,
        ];
    }
}

// Public Subdomain Feeds (/api)
$root_api_dir = dirname(__DIR__, 2) . '/api';
if (is_dir($root_api_dir)) {
    $root_files = glob($root_api_dir . '/*.php');
    sort($root_files);
    foreach ($root_files as $file_path) {
        $filename = basename($file_path);
        $slug = str_replace(['get_', '.php'], '', $filename);
        $clean_name = ucwords(str_replace('_', ' ', $slug)) . ' (Public)';
        $desc = "Universal Subdomain Feed: /api/" . $filename;
        $test_query = get_api_query_params($filename);

        $all_discovered_endpoints[] = [
            'id'          => 'root_' . str_replace('.php', '', $filename),
            'name'        => $clean_name,
            'description' => $desc,
            'type'        => 'internal_public',
            'category'    => 'Public Feeds (/api)',
            'file_name'   => 'api/' . $filename,
            'url'         => $root_base_url . '/api/' . $filename . $test_query,
            'method'      => 'GET',
            'configured'  => true,
        ];
    }
}

// 3. Category & Search Filters
$search   = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? 'all');

$filtered_endpoints = array_filter($all_discovered_endpoints, function($api) use ($search, $category) {
    // Category Filter
    if ($category === 'external' && $api['type'] !== 'external') return false;
    if ($category === 'internal_admin' && $api['type'] !== 'internal_admin') return false;
    if ($category === 'internal_public' && $api['type'] !== 'internal_public') return false;

    // Search Query Filter
    if ($search !== '') {
        $haystack = strtolower($api['name'] . ' ' . $api['description'] . ' ' . $api['url'] . ' ' . $api['file_name'] . ' ' . $api['method']);
        if (strpos($haystack, strtolower($search)) === false) {
            return false;
        }
    }
    return true;
});

// Re-index array
$filtered_endpoints = array_values($filtered_endpoints);

// 4. Server-Side Pagination Setup
$pagination = sode_get_pagination_params(10);
$page       = $pagination['page'];
$per_page   = $pagination['per_page'];
$offset     = $pagination['offset'];

$total_endpoints = count($filtered_endpoints);
$paginated_endpoints = array_slice($filtered_endpoints, $offset, $per_page);

// Count breakdown for tabs
$count_all      = count($all_discovered_endpoints);
$count_external = count(array_filter($all_discovered_endpoints, fn($a) => $a['type'] === 'external'));
$count_admin    = count(array_filter($all_discovered_endpoints, fn($a) => $a['type'] === 'internal_admin'));
$count_public   = count(array_filter($all_discovered_endpoints, fn($a) => $a['type'] === 'internal_public'));

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
/* ── Full Width Modern API Health Monitor Styles ─────────────────── */
.health-container {
    width: 100%;
    max-width: 100%;
}

/* 5-Metric Summary Cards */
.health-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 22px;
    width: 100%;
}
@media (max-width: 1200px) {
    .health-summary-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .health-summary-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    .health-summary-grid { grid-template-columns: 1fr; }
}

.health-stat-card {
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.health-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.14);
}
.health-stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stat-icon-total { background: rgba(99,102,241,0.15); color: #818cf8; }
.stat-icon-up    { background: rgba(16,185,129,0.15); color: #10b981; }
.stat-icon-warn  { background: rgba(245,158,11,0.15);  color: #f59e0b; }
.stat-icon-down  { background: rgba(239,68,68,0.15);   color: #ef4444; }
.stat-icon-speed { background: rgba(56,189,248,0.15);  color: #38bdf8; }

.health-stat-info { display: flex; flex-direction: column; min-width: 0; }
.health-stat-val  { font-size: 28px; font-weight: 800; line-height: 1.1; color: var(--text-main); }
.health-stat-lbl  { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

/* Control & Filter Toolbar */
.health-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px 20px;
    margin-bottom: 20px;
    width: 100%;
}
.toolbar-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.toolbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-check-main {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    font-weight: 600;
    font-size: 13.5px;
    border-radius: 8px;
    background: var(--primary);
    color: #fff;
    border: none;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.15s;
}
.btn-check-main:hover { opacity: 0.92; transform: translateY(-1px); }
.btn-check-main:active { transform: translateY(0); }

/* Premium Server-Side Search Form */
.search-form-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}
.search-box-inner {
    position: relative;
    display: flex;
    align-items: center;
    width: 360px;
    max-width: 100%;
    background: var(--bg-sidebar);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    transition: all 0.2s;
}
.search-box-inner:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
}
.search-box-inner input {
    width: 100%;
    padding: 9px 36px 9px 36px;
    border: none;
    background: transparent;
    color: var(--text-main);
    font-size: 13px;
    outline: none;
}
.search-icon-svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    pointer-events: none;
    display: flex;
}
.search-clear-link {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    text-decoration: none;
    font-size: 12px;
    display: flex;
    align-items: center;
}
.search-clear-link:hover { color: #ef4444; }

.search-submit-btn {
    padding: 9px 16px;
    border-radius: 8px;
    background: var(--bg-sidebar);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.search-submit-btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* Category Filter Tabs */
.filter-tabs {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    overflow-x: auto;
    padding-bottom: 4px;
    width: 100%;
}
.filter-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}
.filter-tab-btn:hover {
    color: var(--text-main);
    border-color: var(--text-dim);
}
.filter-tab-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    box-shadow: 0 4px 12px rgba(99,102,241,0.25);
}
.filter-tab-count {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 10px;
    background: rgba(255,255,255,0.2);
}
.filter-tab-btn:not(.active) .filter-tab-count {
    background: var(--border-color);
    color: var(--text-dim);
}

/* ── Full Width API List Table ─────────────────────────────────────── */
.api-list-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    width: 100%;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}

.api-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 13px;
}
.api-table th {
    background: var(--bg-sidebar);
    padding: 13px 20px;
    font-weight: 700;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-dim);
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.api-table td {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.api-row {
    transition: background-color 0.15s;
}
.api-row:hover { background: rgba(255,255,255,0.025); }
.api-row.expanded { background: rgba(99,102,241,0.04); }

/* Method Badges */
.method-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.05em;
    min-width: 44px;
    text-align: center;
    flex-shrink: 0;
}
.method-get  { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.method-post { background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3); }
.method-head { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

/* Status Badges */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.status-pill-up {
    background: rgba(16,185,129,0.12);
    color: #10b981;
    border: 1px solid rgba(16,185,129,0.25);
}
.status-pill-warn {
    background: rgba(245,158,11,0.12);
    color: #f59e0b;
    border: 1px solid rgba(245,158,11,0.25);
}
.status-pill-down {
    background: rgba(239,68,68,0.12);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,0.25);
}
.status-pill-unconf {
    background: rgba(148,163,184,0.12);
    color: #94a3b8;
    border: 1px solid rgba(148,163,184,0.25);
}
.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
.pulse-dot {
    box-shadow: 0 0 0 0 rgba(16,185,129,0.7);
    animation: pulseGlow 2s infinite;
}
@keyframes pulseGlow {
    0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); }
    70% { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
    100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}

/* Category Pill */
.category-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    background: var(--bg-sidebar);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
    white-space: nowrap;
}

/* Latency Pill */
.latency-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-weight: 700;
    font-size: 13px;
    white-space: nowrap;
}
.latency-fast { color: #10b981; }
.latency-med  { color: #f59e0b; }
.latency-slow { color: #ef4444; }

/* Endpoint Info cell */
.endpoint-cell {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.endpoint-title-wrap { min-width: 0; }
.endpoint-title {
    font-weight: 700;
    color: var(--text-main);
    font-size: 13.5px;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.endpoint-desc {
    font-size: 12px;
    color: var(--text-dim);
    margin-bottom: 4px;
}
.endpoint-route {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 11.5px;
    color: var(--text-muted);
    background: rgba(0,0,0,0.18);
    padding: 3px 8px;
    border-radius: 4px;
    display: inline-block;
    max-width: 500px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    border: 1px solid rgba(255,255,255,0.05);
}

/* Action Buttons */
.action-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;
}
.btn-icon-soft {
    width: 32px;
    height: 32px;
    border-radius: 7px;
    border: 1px solid var(--border-color);
    background: var(--bg-sidebar);
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.18s;
    font-size: 13px;
    text-decoration: none;
}
.btn-icon-soft:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    transform: translateY(-1px);
}

/* Expandable Drawer Row */
.drawer-row td {
    padding: 0 !important;
    border-bottom: 1px solid var(--border-color);
}
.drawer-content {
    padding: 18px 24px;
    background: rgba(0,0,0,0.15);
    border-top: 1px dashed var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.drawer-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
}
.drawer-meta-box {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 12px 16px;
}
.drawer-meta-lbl {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--text-dim);
    font-weight: 700;
    margin-bottom: 5px;
    letter-spacing: 0.05em;
}
.drawer-meta-val {
    font-size: 12.5px;
    color: var(--text-main);
    word-break: break-all;
    font-family: monospace;
}

/* Loading Animation */
.spinner-btn { animation: spin 1s linear infinite; }
@keyframes spin { 100% { transform: rotate(360deg); } }

/* Toast */
.copy-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #10b981;
    color: #fff;
    padding: 11px 20px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    display: flex;
    align-items: center;
    gap: 10px;
    z-index: 9999;
    opacity: 0;
    transform: translateY(12px);
    transition: all 0.25s;
    pointer-events: none;
}
.copy-toast.show { opacity: 1; transform: translateY(0); }
</style>

<div class="health-container">

    <!-- Top Summary Metrics Grid -->
    <div class="health-summary-grid">
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-total">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-total"><?= $count_all ?></span>
                <span class="health-stat-lbl">Total Endpoints</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-up">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-up" style="color:#10b981;">--</span>
                <span class="health-stat-lbl">Operational (200 OK)</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-warn">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-warn" style="color:#f59e0b;">--</span>
                <span class="health-stat-lbl">Warnings / Auth</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-down">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-down" style="color:#ef4444;">--</span>
                <span class="health-stat-lbl">Down / Errors</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-speed">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-speed" style="color:#38bdf8;">--</span>
                <span class="health-stat-lbl">Avg Response Speed</span>
            </div>
        </div>
    </div>

    <!-- Control Toolbar (Full Width) -->
    <div class="health-toolbar">
        <div class="toolbar-left">
            <button class="btn-check-main" id="btn-check-now" onclick="runHealthCheck()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="refresh-icon"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                <span>Run Health Check</span>
            </button>
            <div style="display:inline-flex; align-items:center; gap:8px;">
                <label style="font-size:12.5px; color:var(--text-dim); margin-bottom:0; font-weight:600;">Auto-check:</label>
                <select id="auto-refresh-select" class="form-control" style="width:115px; padding:6px 10px; font-size:12.5px; height:auto; border-radius:6px;" onchange="updateAutoRefresh(this.value)">
                    <option value="0">Off</option>
                    <option value="15">Every 15s</option>
                    <option value="30" selected>Every 30s</option>
                    <option value="60">Every 60s</option>
                </select>
            </div>
            <span style="font-size:12.5px; color:var(--text-dim);" id="last-checked-label">Last checked: Initializing...</span>
        </div>
        <div class="toolbar-right">
            <!-- Server-side Search Form -->
            <form method="GET" action="" class="search-form-wrap">
                <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                <?php if (!empty($per_page)): ?>
                    <input type="hidden" name="per_page" value="<?= htmlspecialchars($per_page) ?>">
                <?php endif; ?>
                <div class="search-box-inner">
                    <span class="search-icon-svg">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </span>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search endpoints, routes, methods...">
                    <?php if (!empty($search)): ?>
                        <a href="?category=<?= urlencode($category) ?>&per_page=<?= urlencode($per_page) ?>" class="search-clear-link" title="Clear search">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="search-submit-btn">Search</button>
            </form>
        </div>
    </div>

    <!-- Category Filter Tabs (Server-Side Linked) -->
    <div class="filter-tabs">
        <a href="?category=all<?= !empty($search) ? '&q=' . urlencode($search) : '' ?>&per_page=<?= $per_page ?>" class="filter-tab-btn <?= $category === 'all' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
            All Endpoints <span class="filter-tab-count"><?= $count_all ?></span>
        </a>
        <a href="?category=external<?= !empty($search) ? '&q=' . urlencode($search) : '' ?>&per_page=<?= $per_page ?>" class="filter-tab-btn <?= $category === 'external' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
            3rd-Party Integrations <span class="filter-tab-count"><?= $count_external ?></span>
        </a>
        <a href="?category=internal_admin<?= !empty($search) ? '&q=' . urlencode($search) : '' ?>&per_page=<?= $per_page ?>" class="filter-tab-btn <?= $category === 'internal_admin' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            Admin REST Feeds (/admin/api) <span class="filter-tab-count"><?= $count_admin ?></span>
        </a>
        <a href="?category=internal_public<?= !empty($search) ? '&q=' . urlencode($search) : '' ?>&per_page=<?= $per_page ?>" class="filter-tab-btn <?= $category === 'internal_public' ? 'active' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
            Public Subdomain Feeds (/api) <span class="filter-tab-count"><?= $count_public ?></span>
        </a>
    </div>

    <!-- Full Width API List Table Card with Native Server-Side Pagination -->
    <div class="api-list-card">
        <div class="table-responsive">
            <table class="api-table" id="api-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Status</th>
                        <th>Endpoint & Route</th>
                        <th style="width: 190px;">Scope / Category</th>
                        <th style="width: 110px;">HTTP Code</th>
                        <th style="width: 125px;">Latency</th>
                        <th style="width: 190px;">Message / Diagnostic</th>
                        <th style="width: 110px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="api-list-body">
                    <?php if (empty($paginated_endpoints)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:40px; color:var(--text-dim);">
                                <?= !empty($search) ? 'No endpoints match your search query "' . htmlspecialchars($search) . '".' : 'No endpoints found in this category.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paginated_endpoints as $idx => $api): 
                            $m = strtoupper($api['method'] ?? 'GET');
                            $methodClass = ($m === 'POST') ? 'method-post' : (($m === 'HEAD') ? 'method-head' : 'method-get');
                            $rowId = 'row-' . htmlspecialchars($api['id']);
                            $drawerId = 'drawer-' . htmlspecialchars($api['id']);
                        ?>
                            <tr class="api-row" id="<?= $rowId ?>">
                                <td id="status-cell-<?= htmlspecialchars($api['id']) ?>">
                                    <span class="status-pill status-pill-unconf">
                                        <span class="status-dot"></span> Checking...
                                    </span>
                                </td>
                                <td>
                                    <div class="endpoint-cell">
                                        <span class="method-badge <?= $methodClass ?>"><?= $m ?></span>
                                        <div class="endpoint-title-wrap">
                                            <div class="endpoint-title">
                                                <?= htmlspecialchars($api['name']) ?>
                                                <span id="json-badge-<?= htmlspecialchars($api['id']) ?>" style="display:none; font-size:10.5px; font-weight:700; padding:1px 6px; border-radius:4px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3);">JSON</span>
                                            </div>
                                            <div class="endpoint-desc"><?= htmlspecialchars($api['description']) ?></div>
                                            <div class="endpoint-route" title="<?= htmlspecialchars($api['url']) ?>"><?= htmlspecialchars($api['url']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($api['type'] === 'internal_public'): ?>
                                        <span class="category-pill" style="border-color:rgba(59,130,246,0.3); color:#60a5fa;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                                            Public Feed
                                        </span>
                                    <?php elseif ($api['type'] === 'internal_admin'): ?>
                                        <span class="category-pill" style="border-color:rgba(99,102,241,0.3); color:#818cf8;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                            Admin REST
                                        </span>
                                    <?php else: ?>
                                        <span class="category-pill" style="border-color:rgba(245,158,11,0.3); color:#fbbf24;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path></svg>
                                            3rd Party
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td id="code-cell-<?= htmlspecialchars($api['id']) ?>">
                                    <span style="color:var(--text-dim); font-weight:600;">--</span>
                                </td>
                                <td id="latency-cell-<?= htmlspecialchars($api['id']) ?>">
                                    <span style="color:var(--text-dim);">--</span>
                                </td>
                                <td id="msg-cell-<?= htmlspecialchars($api['id']) ?>">
                                    <div style="font-size:12px; color:var(--text-muted); font-weight:500;">
                                        Pending health check
                                    </div>
                                </td>
                                <td>
                                    <div class="action-btn-group">
                                        <button class="btn-icon-soft" title="Copy Endpoint URL" onclick="copyUrl('<?= rawurlencode($api['url']) ?>')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                        </button>
                                        <a href="<?= htmlspecialchars($api['url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-icon-soft" title="Test / Open in new tab">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                        </a>
                                        <button class="btn-icon-soft" title="View details" onclick="toggleDrawer('<?= $drawerId ?>', '<?= $rowId ?>')">
                                            <span id="arrow-<?= $drawerId ?>" style="display:inline-flex; transition:transform 0.2s;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                            </span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr class="drawer-row" id="<?= $drawerId ?>" style="display:none;">
                                <td colspan="7">
                                    <div class="drawer-content">
                                        <div class="drawer-meta-grid">
                                            <div class="drawer-meta-box">
                                                <div class="drawer-meta-lbl">Full Request URL</div>
                                                <div class="drawer-meta-val"><?= htmlspecialchars($api['url']) ?></div>
                                            </div>
                                            <div class="drawer-meta-box">
                                                <div class="drawer-meta-lbl">File Location</div>
                                                <div class="drawer-meta-val"><?= htmlspecialchars($api['file_name']) ?></div>
                                            </div>
                                            <div class="drawer-meta-box">
                                                <div class="drawer-meta-lbl">HTTP Method</div>
                                                <div class="drawer-meta-val"><?= $m ?> (Standard JSON REST Feed)</div>
                                            </div>
                                            <div class="drawer-meta-box">
                                                <div class="drawer-meta-lbl">Status Diagnostics</div>
                                                <div class="drawer-meta-val" id="diag-val-<?= htmlspecialchars($api['id']) ?>">
                                                    Evaluating in background...
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Server-Side Pagination Bar Component -->
        <?php echo sode_render_pagination($total_endpoints, $page, $per_page); ?>
    </div>

</div>

<!-- Copy Feedback Toast -->
<div class="copy-toast" id="copyToast">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <span>URL copied to clipboard!</span>
</div>

<script>
let autoRefreshTimer = null;

function updateAutoRefresh(seconds) {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
    const sec = parseInt(seconds);
    if (sec > 0) {
        autoRefreshTimer = setInterval(runHealthCheck, sec * 1000);
    }
}

async function runHealthCheck() {
    const refreshIcon = document.getElementById('refresh-icon');
    const btnCheck = document.getElementById('btn-check-now');
    
    if (refreshIcon) refreshIcon.classList.add('spinner-btn');
    if (btnCheck) btnCheck.disabled = true;

    try {
        const res = await fetch('<?= BASE_URL ?>/api/check_api_health.php?t=' + Date.now());
        const data = await res.json();
        
        // Update top summary cards
        if (data.summary) {
            document.getElementById('stat-total').textContent = data.summary.total || <?= $count_all ?>;
            document.getElementById('stat-up').textContent    = data.summary.up || 0;
            document.getElementById('stat-warn').textContent  = data.summary.warn || 0;
            document.getElementById('stat-down').textContent  = data.summary.down || 0;
            document.getElementById('stat-speed').textContent = (data.summary.avg_ms || 0) + ' ms';
        }

        if (data.checked_at) {
            const d = new Date(data.checked_at.replace(' ', 'T'));
            document.getElementById('last-checked-label').textContent = 'Last checked: ' + d.toLocaleTimeString();
        }

        // Update each row currently on screen
        if (Array.isArray(data.apis)) {
            data.apis.forEach(api => {
                const apiId = api.id;
                const statusCell = document.getElementById('status-cell-' + apiId);
                const codeCell   = document.getElementById('code-cell-' + apiId);
                const latCell    = document.getElementById('latency-cell-' + apiId);
                const msgCell    = document.getElementById('msg-cell-' + apiId);
                const diagVal    = document.getElementById('diag-val-' + apiId);
                const jsonBadge  = document.getElementById('json-badge-' + apiId);

                if (!statusCell) return; // Not on current page

                // Status Badge
                let statusBadge = '';
                if (api.status === 'up') {
                    statusBadge = `<span class="status-pill status-pill-up"><span class="status-dot pulse-dot"></span> Operational</span>`;
                } else if (api.status === 'warn') {
                    statusBadge = `<span class="status-pill status-pill-warn"><span class="status-dot"></span> Warning</span>`;
                } else if (api.status === 'down') {
                    statusBadge = `<span class="status-pill status-pill-down"><span class="status-dot"></span> Down</span>`;
                } else {
                    statusBadge = `<span class="status-pill status-pill-unconf"><span class="status-dot"></span> Unconfigured</span>`;
                }
                statusCell.innerHTML = statusBadge;

                // JSON badge
                if (jsonBadge) {
                    jsonBadge.style.display = api.is_json ? 'inline-block' : 'none';
                }

                // HTTP Code
                if (codeCell) {
                    let codeColor = '#ef4444';
                    if (api.http_code >= 200 && api.http_code < 300) codeColor = '#10b981';
                    else if (api.http_code >= 400 && api.http_code < 500) codeColor = '#f59e0b';
                    codeCell.innerHTML = `<span style="font-weight:700; color:${codeColor}; font-family:monospace; font-size:13.5px;">${api.http_code || '--'}</span>`;
                }

                // Latency
                if (latCell) {
                    if (api.response_ms !== null && api.response_ms !== undefined) {
                        let latClass = 'latency-fast';
                        if (api.response_ms > 600) latClass = 'latency-slow';
                        else if (api.response_ms > 250) latClass = 'latency-med';
                        latCell.innerHTML = `<span class="latency-pill ${latClass}"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg> ${api.response_ms} ms</span>`;
                    }
                }

                // Message
                if (msgCell) {
                    const color = (api.status === 'down') ? '#ef4444' : ((api.status === 'warn') ? '#f59e0b' : 'var(--text-muted)');
                    msgCell.innerHTML = `<div style="font-size:12px; color:${color}; font-weight:500;">${api.message || 'Operational'}</div>`;
                }

                // Diagnostics in drawer
                if (diagVal) {
                    const color = (api.status === 'down') ? '#ef4444' : ((api.status === 'warn') ? '#f59e0b' : '#10b981');
                    diagVal.style.color = color;
                    diagVal.textContent = api.message || 'All systems normal';
                }
            });
        }
    } catch (err) {
        console.error("Health check error:", err);
    } finally {
        if (refreshIcon) refreshIcon.classList.remove('spinner-btn');
        if (btnCheck) btnCheck.disabled = false;
    }
}

function toggleDrawer(drawerId, rowId) {
    const drawer = document.getElementById(drawerId);
    const row    = document.getElementById(rowId);
    const arrow  = document.getElementById('arrow-' + drawerId);
    
    if (!drawer) return;
    if (drawer.style.display === 'none' || drawer.style.display === '') {
        drawer.style.display = 'table-row';
        if (row) row.classList.add('expanded');
        if (arrow) arrow.style.transform = 'rotate(180deg)';
    } else {
        drawer.style.display = 'none';
        if (row) row.classList.remove('expanded');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    }
}

function copyUrl(encodedUrl) {
    const url = decodeURIComponent(encodedUrl);
    navigator.clipboard.writeText(url).then(() => {
        const toast = document.getElementById('copyToast');
        if (toast) {
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2200);
        }
    }).catch(err => {
        prompt("Copy URL:", url);
    });
}

// Run health check on page load
document.addEventListener('DOMContentLoaded', () => {
    runHealthCheck();
    updateAutoRefresh(30);
});
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
