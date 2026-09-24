<?php
/**
 * Universal User Login & Auth Security Logs Engine
 * Tracks all user logins, failed attempts (invalid password, unknown user, inactive account), and logouts.
 */

/**
 * Ensure login_logs table and sidebar item exist in DB
 */
function sode_ensure_login_log_system($db = null) {
    if (!$db) {
        $db = get_db_connection();
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `login_logs` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NULL DEFAULT NULL,
              `identifier` VARCHAR(191) NOT NULL,
              `user_name` VARCHAR(150) NULL DEFAULT NULL,
              `user_email` VARCHAR(191) NULL DEFAULT NULL,
              `role_name` VARCHAR(100) NULL DEFAULT NULL,
              `team_name` VARCHAR(100) NULL DEFAULT NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'FAILED',
              `reason` VARCHAR(255) NULL DEFAULT NULL,
              `ip_address` VARCHAR(45) NULL DEFAULT NULL,
              `user_agent` VARCHAR(255) NULL DEFAULT NULL,
              `browser` VARCHAR(100) NULL DEFAULT NULL,
              `platform` VARCHAR(100) NULL DEFAULT NULL,
              `latitude` VARCHAR(50) NULL DEFAULT NULL,
              `longitude` VARCHAR(50) NULL DEFAULT NULL,
              `location_address` VARCHAR(255) NULL DEFAULT NULL,
              `location_accuracy` VARCHAR(50) NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_user_id` (`user_id`),
              INDEX `idx_status` (`status`),
              INDEX `idx_created_at` (`created_at`),
              INDEX `idx_ip_address` (`ip_address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Ensure columns exist in already created tables
        $existing_cols = $db->query("SHOW COLUMNS FROM `login_logs`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('latitude', $existing_cols)) {
            $db->exec("ALTER TABLE `login_logs` ADD COLUMN `latitude` VARCHAR(50) NULL DEFAULT NULL AFTER `platform`");
        }
        if (!in_array('longitude', $existing_cols)) {
            $db->exec("ALTER TABLE `login_logs` ADD COLUMN `longitude` VARCHAR(50) NULL DEFAULT NULL AFTER `latitude`");
        }
        if (!in_array('location_address', $existing_cols)) {
            $db->exec("ALTER TABLE `login_logs` ADD COLUMN `location_address` VARCHAR(255) NULL DEFAULT NULL AFTER `longitude`");
        }
        if (!in_array('location_accuracy', $existing_cols)) {
            $db->exec("ALTER TABLE `login_logs` ADD COLUMN `location_accuracy` VARCHAR(50) NULL DEFAULT NULL AFTER `location_address`");
        }
    } catch (Exception $e) {
        error_log("Failed to ensure login_logs table: " . $e->getMessage());
    }

    // Auto-register in sidebar_items if missing
    try {
        $chk = $db->query("SELECT id FROM sidebar_items WHERE page_route = 'modules/login_logs/index.php'")->fetch();
        if (!$chk) {
            $db->exec("
                INSERT INTO `sidebar_items` 
                (`display_name`, `page_route`, `sort_order`, `active_page_key`, `rbac_module_key`, `menu_section`, `icon_svg`, `is_superadmin_only`, `is_active`)
                VALUES 
                ('User Logins', 'modules/login_logs/index.php', 97, 'login_logs', 'login_logs', 'SYSTEM', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4\"></path><polyline points=\"10 17 15 12 10 7\"></polyline><line x1=\"15\" y1=\"12\" x2=\"3\" y2=\"12\"></line></svg>', 0, 1)
            ");
            $sidebar_id = (int)$db->lastInsertId();
            if ($sidebar_id) {
                // Grant access to all existing roles by default
                $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($roles)) {
                    $rsa_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                    foreach ($roles as $r_id) {
                        $rsa_stmt->execute([$r_id, $sidebar_id]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Failed to auto-register login_logs in sidebar: " . $e->getMessage());
    }
}

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
 * @return bool
 */
function log_login_attempt($identifier, $status = 'FAILED', $reason = null, $user = null, $db = null, $location_data = null) {
    if (!$db) {
        $db = get_db_connection();
    }
    if (!$db) {
        return false;
    }

    sode_ensure_login_log_system($db);

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
