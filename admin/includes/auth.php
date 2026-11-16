<?php
/**
 * Authentication & Session Management
 */

function is_logged_in() {
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
        return false;
    }

    // Auto-initialize login_time if missing for existing session
    if (!isset($_SESSION['login_time'])) {
        $_SESSION['login_time'] = time();
        $_SESSION['session_expires_at'] = time() + 7200;
    }

    // Strict 2-Hour (7200 seconds) auto-logout from login timestamp
    if ((time() - (int)$_SESSION['login_time']) >= 7200) {
        logout_user('Session Expired (2 Hours Auto-Logout)');
        return false;
    }

    // Session Hijacking Protection: Verify IP and User-Agent signature
    $current_ip = function_exists('get_client_ip_address') ? get_client_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $current_ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (isset($_SESSION['user_ip']) && $_SESSION['user_ip'] !== $current_ip) {
        logout_user('Session Terminated: IP Address Changed (' . htmlspecialchars($current_ip) . ')');
        return false;
    }

    if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $current_ua) {
        logout_user('Session Terminated: Browser/Device Signature Mismatch');
        return false;
    }

    return true;
}

function get_logged_in_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'            => $_SESSION['user_id'],
        'name'          => $_SESSION['user_name'] ?? 'Admin',
        'email'         => $_SESSION['user_email'],
        'username'      => $_SESSION['user_username'] ?? '',
        'role_id'       => $_SESSION['user_role_id'] ?? null,
        'role_name'     => $_SESSION['user_role_name'] ?? 'User',
        'team_id'       => $_SESSION['user_team_id'] ?? null,
        'team_name'     => $_SESSION['user_team_name'] ?? 'No Team',
        'is_superadmin' => !empty($_SESSION['is_superadmin']),
    ];
}

function require_login() {
    if (!is_logged_in()) {
        redirect(BASE_URL . '/login.php');
    }
}

function attempt_login($identifier, $password, $location_data = []) {
    $db = get_db_connection();
    $stmt = $db->prepare("
        SELECT u.*, r.name AS role_name, t.name AS team_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        LEFT JOIN teams t ON u.team_id = t.id 
        WHERE (u.email = ? OR u.username = ?) 
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        if (function_exists('log_login_attempt')) {
            log_login_attempt($identifier, 'FAILED', 'User Not Found (Unknown username/email)', null, null, $location_data);
        }
        return ['success' => false, 'message' => 'Invalid email/username or password.'];
    }

    if (!$user['is_active']) {
        if (function_exists('log_login_attempt')) {
            log_login_attempt($identifier, 'FAILED', 'Account Deactivated', $user, null, $location_data);
        }
        return ['success' => false, 'message' => 'Your account is deactivated. Please contact Superadmin.'];
    }

    if (!password_verify($password, $user['password_hash'])) {
        if (function_exists('log_login_attempt')) {
            log_login_attempt($identifier, 'FAILED', 'Invalid Password', $user, null, $location_data);
        }
        return ['success' => false, 'message' => 'Invalid email/username or password.'];
    }

    // Session Fixation Defense: Regenerate session ID upon authentication
    session_regenerate_id(true);

    // Set session data
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_username'] = $user['username'];
    $_SESSION['user_role_id']  = $user['role_id'];
    $_SESSION['user_role_name']= $user['role_name'] ?? ($user['is_superadmin'] ? 'Superadmin' : 'User');
    $_SESSION['user_team_id']  = $user['team_id'];
    $_SESSION['user_team_name']= $user['team_name'] ?? 'General';
    $_SESSION['is_superadmin'] = (bool)$user['is_superadmin'];

    // Session Hijacking Protection Binding
    $_SESSION['user_ip']       = function_exists('get_client_ip_address') ? get_client_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $_SESSION['user_agent']    = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Fixed 2-Hour Auto-Logout Setup (120 minutes = 7200 seconds)
    $_SESSION['login_time'] = time();
    $_SESSION['session_expires_at'] = time() + 7200;

    // Store location in session
    $lat = $location_data['latitude'] ?? $_POST['latitude'] ?? null;
    $lng = $location_data['longitude'] ?? $_POST['longitude'] ?? null;
    $addr = $location_data['location_address'] ?? $_POST['location_address'] ?? null;
    $acc = $location_data['location_accuracy'] ?? $_POST['location_accuracy'] ?? null;

    if ($lat) $_SESSION['user_latitude'] = $lat;
    if ($lng) $_SESSION['user_longitude'] = $lng;
    if ($addr) $_SESSION['user_location_address'] = $addr;
    if ($acc) $_SESSION['user_location_accuracy'] = $acc;

    // Log Successful Login to Dedicated Login Logs
    if (function_exists('log_login_attempt')) {
        log_login_attempt($identifier, 'SUCCESS', 'Login Successful', $user, null, $location_data);
    }

    return ['success' => true];
}

function logout_user($reason = 'User Logged Out') {
    if (function_exists('log_login_attempt') && !empty($_SESSION['user_id'])) {
        log_login_attempt(
            $_SESSION['user_email'] ?? ($_SESSION['user_username'] ?? 'User'),
            'SUCCESS',
            $reason,
            [
                'id'        => $_SESSION['user_id'] ?? null,
                'name'      => $_SESSION['user_name'] ?? null,
                'email'     => $_SESSION['user_email'] ?? null,
                'role_name' => $_SESSION['user_role_name'] ?? null,
                'team_name' => $_SESSION['user_team_name'] ?? null
            ]
        );
    }

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    $redirect_url = BASE_URL . '/login.php';
    if ($reason !== 'User Logged Out') {
        $redirect_url .= '?session_expired=1';
    }
    redirect($redirect_url);
}

/**
 * Check if the current IP or identifier is locked out due to excessive failed attempts
 * Rate limit rule: Max 3 failed attempts within a 15-minute sliding window.
 * 
 * @param string $identifier Email or Username
 * @param int $max_attempts  Default 3
 * @param int $lockout_mins  Default 15
 * @return array ['locked' => bool, 'remaining_mins' => int, 'attempts' => int]
 */
function check_login_lockout($identifier, $max_attempts = 3, $lockout_mins = 15) {
    $db = get_db_connection();
    if (!$db) {
        return ['locked' => false, 'remaining_mins' => 0, 'attempts' => 0];
    }

    $ip = function_exists('get_client_ip_address') ? get_client_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

    try {
        // Find last successful login time for this IP or identifier to only count failures after it
        $last_success_stmt = $db->prepare("
            SELECT created_at FROM login_logs 
            WHERE (ip_address = ? OR identifier = ?) AND status = 'SUCCESS'
            ORDER BY id DESC LIMIT 1
        ");
        $last_success_stmt->execute([$ip, $identifier]);
        $last_success = $last_success_stmt->fetchColumn();

        $query = "
            SELECT COUNT(*) AS failed_count, MAX(created_at) AS last_failed_at 
            FROM login_logs 
            WHERE (ip_address = :ip OR identifier = :identifier) 
              AND status = 'FAILED' 
              AND created_at >= DATE_SUB(NOW(), INTERVAL :mins MINUTE)
        ";
        if ($last_success) {
            $query .= " AND created_at > :last_success";
        }

        $stmt = $db->prepare($query);
        $params = [
            'ip'         => $ip,
            'identifier' => $identifier,
            'mins'       => $lockout_mins
        ];
        if ($last_success) {
            $params['last_success'] = $last_success;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $failed_count = (int)($row['failed_count'] ?? 0);
        if ($failed_count >= $max_attempts && !empty($row['last_failed_at'])) {
            $last_time = strtotime($row['last_failed_at']);
            $elapsed_secs = time() - $last_time;
            $lockout_secs = $lockout_mins * 60;
            if ($elapsed_secs < $lockout_secs) {
                $remaining_mins = (int)ceil(($lockout_secs - $elapsed_secs) / 60);
                return [
                    'locked'         => true,
                    'remaining_mins' => max(1, $remaining_mins),
                    'attempts'       => $failed_count
                ];
            }
        }
        return ['locked' => false, 'remaining_mins' => 0, 'attempts' => $failed_count];
    } catch (Exception $e) {
        error_log("Lockout check error: " . $e->getMessage());
    }

    return ['locked' => false, 'remaining_mins' => 0, 'attempts' => 0];
}

/**
 * Server-Side Geofencing & Anti-Fake Location Protection Engine
 * Detects real client network IP, blocks high-risk foreign countries,
 * and detects GPS spoofing (e.g. browser DevTools / fake coordinates).
 * 
 * @param float|string $latitude
 * @param float|string $longitude
 * @param string|null  $client_ip
 * @return array ['success' => bool, 'message' => string, 'details' => array]
 */
function verify_server_ip_and_location($latitude, $longitude, $client_ip = null) {
    if (!$client_ip) {
        $client_ip = function_exists('get_client_ip_address') ? get_client_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($longitude, FILTER_VALIDATE_FLOAT);

    // 1. Basic coordinate boundary check
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return [
            'success' => false,
            'message' => 'Invalid or malformed GPS coordinates detected. Access blocked.',
            'details' => ['reason' => 'Invalid Coordinate Range', 'ip' => $client_ip]
        ];
    }

    // 2. Detect local / private network IPs (allow for localhost / intranet testing)
    $is_local_ip = ($client_ip === '127.0.0.1' || $client_ip === '::1' 
        || strpos($client_ip, '192.168.') === 0 
        || strpos($client_ip, '10.') === 0 
        || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $client_ip));

    if ($is_local_ip) {
        return [
            'success' => true,
            'message' => 'Local Network Access Verified',
            'details' => ['ip' => $client_ip, 'country' => 'LOCAL', 'is_local' => true]
        ];
    }

    // 3. Server-side Country & Geolocation Resolution
    $country_code = null;
    $country_name = null;
    $city = null;

    // Check Cloudflare header first
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
        $country_code = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY']));
    }

    // If Cloudflare header is missing or generic (XX / T1), query IP lookup with caching
    if (!$country_code || $country_code === 'XX' || $country_code === 'T1') {
        $cache_key = 'ip_geo:' . md5($client_ip);
        $cached_geo = null;

        if (class_exists('Sode_Redis')) {
            try {
                $cached_geo = Sode_Redis::get($cache_key);
            } catch (Exception $e) {}
        }

        if ($cached_geo && is_array($cached_geo)) {
            $country_code = $cached_geo['country_code'] ?? null;
            $country_name = $cached_geo['country'] ?? null;
            $city = $cached_geo['city'] ?? null;
        } else {
            // Lightweight HTTP lookup (timeout: 1.5 seconds)
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 1.5,
                    'user_agent' => 'SodeSecurityEngine/2.0'
                ]
            ]);
            $geo_json = @file_get_contents("http://ip-api.com/json/{$client_ip}?fields=status,country,countryCode,city,proxy,hosting", false, $ctx);
            if ($geo_json) {
                $geo_data = @json_decode($geo_json, true);
                if (!empty($geo_data) && ($geo_data['status'] ?? '') === 'success') {
                    $country_code = strtoupper($geo_data['countryCode'] ?? '');
                    $country_name = $geo_data['country'] ?? '';
                    $city = $geo_data['city'] ?? '';

                    if (class_exists('Sode_Redis') && $country_code) {
                        try {
                            Sode_Redis::set($cache_key, [
                                'country_code' => $country_code,
                                'country' => $country_name,
                                'city' => $city
                            ], 86400); // 24-hour cache
                        } catch (Exception $e) {}
                    }
                }
            }
        }
    }

    // 4. Geofencing check: Restricted Countries / Allowed Countries
    // Whitelisted country: India (IN)
    $allowed_countries = ['IN'];
    
    // Explicit high-risk blocked country list (Russia, China, North Korea, Belarus, Iran)
    $high_risk_countries = ['RU', 'CN', 'KP', 'IR', 'BY'];

    if ($country_code) {
        if (in_array($country_code, $high_risk_countries, true)) {
            return [
                'success' => false,
                'message' => "Access Blocked: Connections from {$country_code} are strictly blocked due to high-risk location security policies.",
                'details' => ['ip' => $client_ip, 'country' => $country_code, 'reason' => 'High Risk Country Blocked']
            ];
        }

        if (!in_array($country_code, $allowed_countries, true)) {
            return [
                'success' => false,
                'message' => "Access Restricted: Admin panel access is restricted to authorized regions (Current IP Location: {$country_code}).",
                'details' => ['ip' => $client_ip, 'country' => $country_code, 'reason' => 'Non-Whitelisted Country']
            ];
        }
    }

    // 5. Anti-Spoofing: Cross-verify Client GPS with India Boundaries
    // India Approximate Geo Bounds: Lat 6.5°N to 37.5°N, Long 68.0°E to 97.5°E
    if ($country_code === 'IN') {
        $is_in_india_box = ($lat >= 6.0 && $lat <= 38.5 && $lng >= 68.0 && $lng <= 98.0);
        if (!$is_in_india_box) {
            return [
                'success' => false,
                'message' => 'Spoofed GPS Location Detected: Your browser GPS coordinates lie outside India, but your IP network is Indian. Fake coordinates blocked.',
                'details' => ['ip' => $client_ip, 'lat' => $lat, 'lng' => $lng, 'country' => $country_code, 'reason' => 'GPS/IP Boundary Mismatch']
            ];
        }
    }

    return [
        'success' => true,
        'message' => 'Location & Network Verified',
        'details' => [
            'ip' => $client_ip,
            'country_code' => $country_code ?? 'UNKNOWN',
            'city' => $city ?? '',
            'latitude' => $lat,
            'longitude' => $lng
        ]
    ];
}
