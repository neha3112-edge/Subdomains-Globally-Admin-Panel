<?php
/**
 * API Health Check Endpoint
 * Performs real HTTP checks against all configured third-party APIs
 * Returns JSON with status, HTTP code, response time per API
 */
require_once dirname(__DIR__) . '/config/config.php';
require_login();

header('Content-Type: application/json');

$db = get_db_connection();

// Fetch stored API credentials from global_settings
$rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'integrations'")->fetchAll(PDO::FETCH_KEY_PAIR);

$crm_api_url          = $rows['crm_api_url']          ?? 'https://api.crm.mysode.com/api/lead/apicreated';
$crm_api_key          = $rows['crm_api_key']           ?? '';
$crm_secret           = $rows['crm_secret']            ?? '';
$brevo_api_url        = $rows['brevo_api_url']          ?? 'https://api.brevo.com/v3/contacts';
$brevo_api_key        = $rows['brevo_api_key']          ?? '';
$gallabox_webhook_url = $rows['gallabox_webhook_url']   ?? '';

/**
 * Perform a lightweight HTTP check using cURL
 */
function check_endpoint(string $url, array $headers = [], string $method = 'HEAD', ?string $body = null): array {
    if (empty($url)) {
        return ['status' => 'unconfigured', 'http_code' => null, 'response_ms' => null, 'message' => 'No URL configured'];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'SODE-Admin-HealthChecker/1.0',
    ]);

    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $start     = microtime(true);
    $response  = curl_exec($ch);
    $end       = microtime(true);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error     = curl_error($ch);
    curl_close($ch);

    $response_ms = round(($end - $start) * 1000);

    if ($error) {
        return ['status' => 'down', 'http_code' => 0, 'response_ms' => $response_ms, 'message' => 'Connection error: ' . $error];
    }

    if ($http_code >= 200 && $http_code < 300) {
        return ['status' => 'up',   'http_code' => $http_code, 'response_ms' => $response_ms, 'message' => 'Reachable — HTTP ' . $http_code];
    } elseif ($http_code === 401 || $http_code === 403) {
        return ['status' => 'warn', 'http_code' => $http_code, 'response_ms' => $response_ms, 'message' => 'Reachable but auth failed (HTTP ' . $http_code . ') — Check API key'];
    } elseif ($http_code === 405) {
        return ['status' => 'up',   'http_code' => $http_code, 'response_ms' => $response_ms, 'message' => 'Reachable — HTTP 405 (Method Not Allowed, normal for some APIs)'];
    } elseif ($http_code >= 400 && $http_code < 500) {
        return ['status' => 'warn', 'http_code' => $http_code, 'response_ms' => $response_ms, 'message' => 'Client error — HTTP ' . $http_code];
    } elseif ($http_code >= 500) {
        return ['status' => 'down', 'http_code' => $http_code, 'response_ms' => $response_ms, 'message' => 'Server error — HTTP ' . $http_code];
    } elseif ($http_code === 0) {
        return ['status' => 'down', 'http_code' => 0,          'response_ms' => $response_ms, 'message' => 'No response / unreachable'];
    }
    return ['status' => 'warn', 'http_code' => $http_code, 'response_ms' => $response_ms, 'message' => 'Unexpected HTTP ' . $http_code];
}

$results = [];

// 1. SODE CRM API
$crm_headers = ['Content-Type: application/json'];
if (!empty($crm_api_key))  $crm_headers[] = 'x-api-key: ' . $crm_api_key;
if (!empty($crm_secret))   $crm_headers[] = 'secret: ' . $crm_secret;
$crm_check = check_endpoint($crm_api_url, $crm_headers, 'POST', json_encode(['_health_check' => true]));
$results[] = array_merge(['id' => 'crm', 'name' => 'SODE CRM API', 'description' => 'Primary lead capture endpoint for all university subdomains', 'category' => 'CRM', 'url' => $crm_api_url, 'configured' => !empty($crm_api_key) && !empty($crm_secret)], $crm_check);

// 2. Brevo API
$brevo_headers = ['Content-Type: application/json'];
if (!empty($brevo_api_key)) $brevo_headers[] = 'api-key: ' . $brevo_api_key;
$brevo_check = check_endpoint($brevo_api_url, $brevo_headers, 'HEAD');
$results[] = array_merge(['id' => 'brevo', 'name' => 'Brevo (Sendinblue) API', 'description' => 'Email & SMS marketing — contact list sync endpoint', 'category' => 'Email/SMS', 'url' => $brevo_api_url, 'configured' => !empty($brevo_api_key)], $brevo_check);

// 3. Gallabox WhatsApp Webhook
$gallabox_check = check_endpoint($gallabox_webhook_url, ['Content-Type: application/json'], 'POST', json_encode(['_health_check' => true]));
$results[] = array_merge(['id' => 'gallabox', 'name' => 'Gallabox WhatsApp Webhook', 'description' => 'Central WhatsApp automation webhook for all university domains', 'category' => 'WhatsApp', 'url' => $gallabox_webhook_url, 'configured' => !empty($gallabox_webhook_url)], $gallabox_check);

// 4. Internal SODE Admin API (self-check)
$self_check = check_endpoint(BASE_URL . '/api/get_courses.php', [], 'GET');
$results[] = array_merge(['id' => 'sode_internal', 'name' => 'SODE Internal Data API', 'description' => 'Internal admin REST endpoints serving subdomain data feeds', 'category' => 'Internal', 'url' => BASE_URL . '/api/get_courses.php', 'configured' => true], $self_check);

$total        = count($results);
$up_count     = count(array_filter($results, fn($r) => $r['status'] === 'up'));
$warn_count   = count(array_filter($results, fn($r) => $r['status'] === 'warn'));
$down_count   = count(array_filter($results, fn($r) => $r['status'] === 'down'));
$unconf_count = count(array_filter($results, fn($r) => $r['status'] === 'unconfigured'));

echo json_encode([
    'checked_at' => date('Y-m-d H:i:s'),
    'summary'    => ['total' => $total, 'up' => $up_count, 'warn' => $warn_count, 'down' => $down_count, 'unconfigured' => $unconf_count],
    'apis'       => $results,
], JSON_PRETTY_PRINT);
