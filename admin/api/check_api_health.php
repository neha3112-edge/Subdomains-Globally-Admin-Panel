<?php
/**
 * Dynamic API Health Check Engine
 * Auto-discovers all internal REST APIs from /api and /admin/api directories
 * Checks external 3rd-party integrations (CRM, Brevo, WhatsApp)
 * Executes all checks in parallel using curl_multi for ultra-fast latency benchmarking
 */
require_once dirname(__DIR__) . '/config/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$db = get_db_connection();

// Fetch stored API credentials from global_settings
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

$endpoints_to_check = [];

// 1. Third-Party Integration: SODE CRM API
$crm_headers = ['Content-Type: application/json'];
if (!empty($crm_api_key)) $crm_headers[] = 'x-api-key: ' . $crm_api_key;
if (!empty($crm_secret))  $crm_headers[] = 'secret: ' . $crm_secret;

$endpoints_to_check[] = [
    'id'          => 'crm',
    'name'        => 'SODE CRM API',
    'description' => 'Primary lead capture and admission CRM pipeline',
    'type'        => 'external',
    'category'    => 'CRM',
    'url'         => $crm_api_url,
    'method'      => 'POST',
    'headers'     => $crm_headers,
    'body'        => json_encode(['_health_check' => true]),
    'configured'  => !empty($crm_api_key) && !empty($crm_secret),
];

// 2. Third-Party Integration: Brevo (Sendinblue)
$brevo_headers = ['Content-Type: application/json'];
if (!empty($brevo_api_key)) $brevo_headers[] = 'api-key: ' . $brevo_api_key;

$endpoints_to_check[] = [
    'id'          => 'brevo',
    'name'        => 'Brevo (Sendinblue) API',
    'description' => 'Email & SMS marketing list sync and automation',
    'type'        => 'external',
    'category'    => 'Email & SMS',
    'url'         => $brevo_api_url,
    'method'      => 'HEAD',
    'headers'     => $brevo_headers,
    'body'        => null,
    'configured'  => !empty($brevo_api_key),
];

// 3. Third-Party Integration: Gallabox WhatsApp Webhook
$endpoints_to_check[] = [
    'id'          => 'gallabox',
    'name'        => 'Gallabox WhatsApp Webhook',
    'description' => 'Central WhatsApp notification & engagement webhook',
    'type'        => 'external',
    'category'    => 'WhatsApp',
    'url'         => $gallabox_webhook_url,
    'method'      => 'POST',
    'headers'     => ['Content-Type: application/json'],
    'body'        => json_encode(['_health_check' => true]),
    'configured'  => !empty($gallabox_webhook_url),
];

// 4. AUTO-DISCOVERY: Scan /admin/api/ directory for all internal endpoints
$admin_api_dir = __DIR__;
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

        // Friendly description
        $desc = "Admin REST Feed: " . $filename;
        $file_content = file_get_contents($file_path, false, null, 0, 400);
        if (preg_match('/\/\*\*\s*\n\s*\*\s*([^\n\*]+)/', $file_content, $m)) {
            $desc = trim($m[1]);
        }

        $method = (strpos($filename, 'upload') !== false || strpos($filename, 'delete') !== false) ? 'POST' : 'GET';
        $test_query = '';
        if ($filename === 'get_course_data.php') $test_query = '?uni=test&course=test';
        elseif ($filename === 'get_university_banner.php') $test_query = '?uni=test';
        elseif ($filename === 'get_course_fees.php') $test_query = '?uni=test&course=test';

        $endpoints_to_check[] = [
            'id'          => 'admin_' . str_replace('.php', '', $filename),
            'name'        => $clean_name,
            'description' => $desc,
            'type'        => 'internal_admin',
            'category'    => 'Admin REST (/admin/api)',
            'file_name'   => 'admin/api/' . $filename,
            'url'         => BASE_URL . '/api/' . $filename . $test_query,
            'method'      => $method,
            'headers'     => [],
            'body'        => null,
            'configured'  => true,
        ];
    }
}

// 5. AUTO-DISCOVERY: Scan root /api/ directory (Public Subdomain API feeds)
$root_api_dir = dirname(__DIR__, 2) . '/api';
if (is_dir($root_api_dir)) {
    $root_files = glob($root_api_dir . '/*.php');
    sort($root_files);
    $root_base_url = rtrim(preg_replace('/\/admin.*$/i', '', BASE_URL), '/');

    foreach ($root_files as $file_path) {
        $filename = basename($file_path);
        $slug = str_replace(['get_', '.php'], '', $filename);
        $clean_name = ucwords(str_replace('_', ' ', $slug)) . ' (Public)';

        $desc = "Universal Subdomain Feed: /api/" . $filename;
        $test_query = '';
        if ($filename === 'get_course_data.php') $test_query = '?uni=test&course=test';
        elseif ($filename === 'get_university_banner.php') $test_query = '?uni=test';

        $endpoints_to_check[] = [
            'id'          => 'root_' . str_replace('.php', '', $filename),
            'name'        => $clean_name,
            'description' => $desc,
            'type'        => 'internal_public',
            'category'    => 'Public Feeds (/api)',
            'file_name'   => 'api/' . $filename,
            'url'         => $root_base_url . '/api/' . $filename . $test_query,
            'method'      => 'GET',
            'headers'     => [],
            'body'        => null,
            'configured'  => true,
        ];
    }
}

/**
 * High-Speed Parallel cURL Batch Execution
 */
$mh = curl_multi_init();
$curl_handles = [];
$start_times  = [];

foreach ($endpoints_to_check as $idx => $api) {
    if (empty($api['url']) || !$api['configured']) {
        continue;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $api['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => $api['headers'] ?? [],
        CURLOPT_USERAGENT      => 'SODE-Admin-HealthChecker/2.0',
    ]);

    if ($api['method'] === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    } elseif ($api['method'] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($api['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $api['body']);
        }
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    curl_multi_add_handle($mh, $ch);
    $curl_handles[$idx] = $ch;
    $start_times[$idx]  = microtime(true);
}

// Execute all cURL handles in parallel
$running = null;
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.05);
    }
} while ($running > 0 && $status === CURLM_OK);

// Harvest results
$results = [];
$total_ms = 0;
$valid_ms_count = 0;

foreach ($endpoints_to_check as $idx => $api) {
    if (empty($api['url']) || !$api['configured']) {
        $api['status']      = 'unconfigured';
        $api['http_code']   = null;
        $api['response_ms'] = null;
        $api['message']     = 'No URL or credentials configured';
        $api['is_json']     = false;
        $results[] = $api;
        continue;
    }

    $ch = $curl_handles[$idx] ?? null;
    if (!$ch) {
        $api['status']      = 'down';
        $api['http_code']   = 0;
        $api['response_ms'] = null;
        $api['message']     = 'Failed to initialize request';
        $api['is_json']     = false;
        $results[] = $api;
        continue;
    }

    $end_time    = microtime(true);
    $response_ms = round(($end_time - ($start_times[$idx] ?? $end_time)) * 1000);
    $http_code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type= (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error       = curl_error($ch);
    $content     = curl_multi_getcontent($ch);

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);

    $is_json = (stripos($content_type, 'json') !== false) || (is_string($content) && is_array(json_decode($content, true)));

    if ($error) {
        $status_label = 'down';
        $message      = 'Connection error: ' . $error;
    } elseif ($http_code >= 200 && $http_code < 300) {
        $status_label = 'up';
        $message      = 'Operational — HTTP ' . $http_code . ($is_json ? ' (JSON Valid)' : '');
        $total_ms    += $response_ms;
        $valid_ms_count++;
    } elseif ($http_code === 401 || $http_code === 403) {
        $status_label = 'warn';
        $message      = 'Auth required (HTTP ' . $http_code . ')';
    } elseif ($http_code === 405) {
        $status_label = 'up';
        $message      = 'Reachable — HTTP 405 (Method check ok)';
    } elseif ($http_code >= 400 && $http_code < 500) {
        $status_label = 'warn';
        $message      = 'Client response — HTTP ' . $http_code;
    } elseif ($http_code >= 500) {
        $status_label = 'down';
        $message      = 'Server error — HTTP ' . $http_code;
    } elseif ($http_code === 0) {
        $status_label = 'down';
        $message      = 'No response / server unreachable';
    } else {
        $status_label = 'warn';
        $message      = 'HTTP status ' . $http_code;
    }

    $api['status']      = $status_label;
    $api['http_code']   = $http_code;
    $api['response_ms'] = $response_ms;
    $api['message']     = $message;
    $api['is_json']     = $is_json;

    $results[] = $api;
}

curl_multi_close($mh);

$total        = count($results);
$up_count     = count(array_filter($results, fn($r) => $r['status'] === 'up'));
$warn_count   = count(array_filter($results, fn($r) => $r['status'] === 'warn'));
$down_count   = count(array_filter($results, fn($r) => $r['status'] === 'down'));
$unconf_count = count(array_filter($results, fn($r) => $r['status'] === 'unconfigured'));
$avg_ms       = $valid_ms_count > 0 ? round($total_ms / $valid_ms_count) : 0;

$internal_count = count(array_filter($results, fn($r) => ($r['type'] ?? '') !== 'external'));
$external_count = count(array_filter($results, fn($r) => ($r['type'] ?? '') === 'external'));

echo json_encode([
    'checked_at' => date('Y-m-d H:i:s'),
    'summary'    => [
        'total'          => $total,
        'up'             => $up_count,
        'warn'           => $warn_count,
        'down'           => $down_count,
        'unconfigured'   => $unconf_count,
        'avg_ms'         => $avg_ms,
        'internal_count' => $internal_count,
        'external_count' => $external_count,
    ],
    'apis'       => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

