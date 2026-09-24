<?php
/**
 * Universal User Login & Auth Security Logs Engine
 * Tracks all user logins, failed attempts (invalid password, unknown user, inactive account), and logouts.
 */

/**
 * Helper: Detect Browser & Platform from User-Agent
 */
if (!function_exists('sode_detect_browser_platform')) {
    function sode_detect_browser_platform($user_agent) {
        $browser = 'Unknown Browser';
        $platform = 'Unknown OS';

        $ua = (string)$user_agent;

        // Platform detection
        if (preg_match('/windows nt 10/i', $ua)) {
            $platform = 'Windows 10/11';
        } elseif (preg_match('/windows nt 6.3/i', $ua)) {
            $platform = 'Windows 8.1';
        } elseif (preg_match('/windows nt 6.2/i', $ua)) {
            $platform = 'Windows 8';
        } elseif (preg_match('/windows nt 6.1/i', $ua)) {
            $platform = 'Windows 7';
        } elseif (preg_match('/windows/i', $ua)) {
            $platform = 'Windows';
        } elseif (preg_match('/iphone/i', $ua)) {
            $platform = 'iPhone iOS';
        } elseif (preg_match('/ipad/i', $ua)) {
            $platform = 'iPad iOS';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $platform = 'macOS';
        } elseif (preg_match('/android/i', $ua)) {
            $platform = 'Android';
        } elseif (preg_match('/linux/i', $ua)) {
            $platform = 'Linux';
        }

        // Browser detection
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/firefox/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/opr|opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/msie|trident/i', $ua)) {
            $browser = 'Internet Explorer';
        }

        return [
            'browser' => $browser,
            'platform' => $platform
        ];
    }
}

/**
 * Log a User Login / Auth Attempt
 * 
 * @param string      $identifier Email or Username submitted
 * @param string      $status     'SUCCESS' or 'FAILED'
 * @param string|null $reason     e.g. 'Invalid Password', 'User Not Found', 'Account Deactivated', 'Login Successful', 'User Logged Out'
 * @param array|null  $user       User record if found
 * @param PDO|null    $db         Database connection
 * @param array|null  $location_data
 * @return bool
 */
function log_login_attempt($identifier, $status = 'FAILED', $reason = null, $user = null, $db = null, $location_data = null) {
    if (!$db) {
        $db = get_db_connection();
    }
    if (!$db) {
        return false;
    }

    try {
        $user_id = !empty($user['id']) ? (int)$user['id'] : null;
        $user_name = !empty($user['name']) ? $user['name'] : (!empty($user['user_name']) ? $user['user_name'] : null);
        $user_email = !empty($user['email']) ? $user['email'] : null;
        $role_name = !empty($user['role_name']) ? $user['role_name'] : null;
        $team_name = !empty($user['team_name']) ? $user['team_name'] : null;

        // If user is null but identifier looks like an email/username in DB, try quick lookup
        if (!$user_id && !empty($identifier)) {
            try {
                $chk_stmt = $db->prepare("
                    SELECT u.id, u.name, u.email, r.name AS role_name, t.name AS team_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.id 
                    LEFT JOIN teams t ON u.team_id = t.id 
                    WHERE (u.email = ? OR u.username = ?) 
                    LIMIT 1
                ");
                $chk_stmt->execute([$identifier, $identifier]);
                $found = $chk_stmt->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $user_id = (int)$found['id'];
                    $user_name = $found['name'];
                    $user_email = $found['email'];
                    $role_name = $found['role_name'];
                    $team_name = $found['team_name'];
                }
            } catch (Exception $e) {}
        }

        $ip = function_exists('get_client_ip_address') ? get_client_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $device = sode_detect_browser_platform($ua);

        // Location data extraction (from parameter, POST, or session)
        $latitude = !empty($location_data['latitude']) ? $location_data['latitude'] : (!empty($_POST['latitude']) ? trim((string)$_POST['latitude']) : (!empty($_SESSION['user_latitude']) ? $_SESSION['user_latitude'] : null));
        $longitude = !empty($location_data['longitude']) ? $location_data['longitude'] : (!empty($_POST['longitude']) ? trim((string)$_POST['longitude']) : (!empty($_SESSION['user_longitude']) ? $_SESSION['user_longitude'] : null));
        $location_address = !empty($location_data['location_address']) ? $location_data['location_address'] : (!empty($_POST['location_address']) ? trim((string)$_POST['location_address']) : (!empty($_SESSION['user_location_address']) ? $_SESSION['user_location_address'] : null));
        $location_accuracy = !empty($location_data['location_accuracy']) ? $location_data['location_accuracy'] : (!empty($_POST['location_accuracy']) ? trim((string)$_POST['location_accuracy']) : (!empty($_SESSION['user_location_accuracy']) ? $_SESSION['user_location_accuracy'] : null));

        if ($latitude) $latitude = substr((string)$latitude, 0, 50);
        if ($longitude) $longitude = substr((string)$longitude, 0, 50);
        if ($location_address) $location_address = substr((string)$location_address, 0, 255);
        if ($location_accuracy) $location_accuracy = substr((string)$location_accuracy, 0, 50);

        $stmt = $db->prepare("
            INSERT INTO login_logs 
            (user_id, identifier, user_name, user_email, role_name, team_name, status, reason, ip_address, user_agent, browser, platform, latitude, longitude, location_address, location_accuracy, created_at)
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $user_id,
            substr((string)$identifier, 0, 191),
            $user_name,
            $user_email,
            $role_name,
            $team_name,
            strtoupper($status) === 'SUCCESS' ? 'SUCCESS' : 'FAILED',
            $reason ? substr((string)$reason, 0, 255) : ($status === 'SUCCESS' ? 'Login Successful' : 'Failed Attempt'),
            substr($ip, 0, 45),
            $ua,
            $device['browser'],
            $device['platform'],
            $latitude,
            $longitude,
            $location_address,
            $location_accuracy
        ]);

        // Auto-prune to keep latest 500 logs
        sode_prune_login_logs($db, 500);

        return true;
    } catch (Exception $e) {
        error_log("Failed to log login attempt: " . $e->getMessage());
        return false;
    }
}

/**
 * Auto-prune login logs to keep latest N logs
 */
function sode_prune_login_logs(PDO $db, int $keep_limit = 500): int {
    try {
        $stmt = $db->query("SELECT id FROM login_logs ORDER BY id DESC LIMIT 1 OFFSET " . ($keep_limit - 1));
        $cutoff_id = $stmt ? $stmt->fetchColumn() : false;

        if ($cutoff_id) {
            $del_stmt = $db->prepare("DELETE FROM login_logs WHERE id < ?");
            $del_stmt->execute([$cutoff_id]);
            return $del_stmt->rowCount();
        }
    } catch (Exception $e) {
        error_log("Failed to prune login logs: " . $e->getMessage());
    }
    return 0;
}
