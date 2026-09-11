<?php
/**
 * Universal Helper Functions
 */

function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die("CSRF validation failed.");
    }
}

function set_flash_message($arg1, $arg2 = 'success') {
    if (in_array($arg1, ['success', 'error', 'info', 'warning'])) {
        $type = $arg1;
        $message = $arg2;
    } else {
        $message = $arg1;
        $type = $arg2;
    }
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function display_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        $class = $flash['type'] === 'error' ? 'alert-error' : ($flash['type'] === 'success' ? 'alert-success' : 'alert-info');
        return '<div class="admin-alert ' . $class . '">' . htmlspecialchars($flash['message']) . '<button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button></div>';
    }
    return '';
}

function get_user_initials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
    if (isset($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
    return !empty($initials) ? $initials : 'U';
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Dynamic Asset URL Resolver
 * Automatically resolves relative paths (e.g., uploads/2026/09/image.png)
 * to current environment base URL (localhost or live domain)
 */
function get_asset_url($path) {
    if (empty($path)) return '';
    $path = trim((string)$path);
    
    // External URLs not on localhost or admin domain
    if (preg_match('#^https?://#i', $path) && strpos($path, 'localhost') === false && strpos($path, 'admin.distanceeducationschool.com') === false) {
        return $path;
    }
    
    // Normalize to relative uploads path
    $clean = preg_replace('#^https?://[^/]+(?:/[^/]+)*/(?:admin/)?uploads/#i', 'uploads/', $path);
    $clean = ltrim($clean, '/');
    if (strpos($clean, 'uploads/') !== 0 && strpos($clean, 'assets/') !== 0) {
        if (strpos($clean, '202') === 0) {
            $clean = 'uploads/' . $clean;
        }
    }
    
    $base = defined('BASE_URL') ? BASE_URL : 'https://admin.distanceeducationschool.com/admin';
    return rtrim($base, '/') . '/' . ltrim($clean, '/');
}

/**
 * Convert any full URL or mixed path to clean relative path for database storage
 */
function get_relative_asset_path($path) {
    if (empty($path)) return '';
    $path = trim((string)$path);
    if (preg_match('#^https?://#i', $path) && strpos($path, 'localhost') === false && strpos($path, 'admin.distanceeducationschool.com') === false) {
        return $path; // preserve external CDN URLs
    }
    $clean = preg_replace('#^https?://[^/]+(?:/[^/]+)*/(?:admin/)?uploads/#i', 'uploads/', $path);
    $clean = ltrim($clean, '/');
    if (strpos($clean, 'uploads/') !== 0 && strpos($clean, 'assets/') !== 0) {
        if (strpos($clean, '202') === 0) {
            $clean = 'uploads/' . $clean;
        }
    }
    return $clean;
}

/**
 * Pings each active university subdomain to clear WP Rocket + Redis + Elementor cache.
 * TRUE fire-and-forget — admin save page does NOT wait for responses.
 */
if (!function_exists('sode_bust_all_subdomain_caches')) {
    function sode_bust_all_subdomain_caches(PDO $db) {
        try {
            $unis = $db->query("SELECT slug FROM universities WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($unis as $slug) {
                $url = 'https://' . $slug . '.distanceeducationschool.com/?sode_flush=sode_flush_2026';
                sode_fire_and_forget($url);
            }
        } catch (Exception $e) {
            error_log('SODE cache bust failed: ' . $e->getMessage());
        }
    }
}

/**
 * Truly non-blocking HTTP GET — sends request, does NOT wait for response.
 * Admin page loads instantly regardless of subdomain response time.
 */
if (!function_exists('sode_fire_and_forget')) {
    function sode_fire_and_forget($url) {
        // Method 1: Background shell process (fastest, zero wait)
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            @exec("curl -k -s --max-time 5 '" . addslashes($url) . "' > /dev/null 2>&1 &");
            return;
        }

        // Method 2: cURL with 100ms timeout (effectively fire-and-forget)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => 100,   // 100ms max wait — then move on
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY         => true,   // Don't download body
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            @curl_exec($ch);
            curl_close($ch);
            return;
        }

        // Method 3: fsockopen (non-blocking socket, no wait for response)
        $parts = parse_url($url);
        $host  = $parts['host'] ?? '';
        $path  = ($parts['path'] ?? '/') . '?' . ($parts['query'] ?? '');
        $fp = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 1);
        if ($fp) {
            @fwrite($fp, "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
            @fclose($fp); // Close immediately — don't wait for response
        }
    }
}

