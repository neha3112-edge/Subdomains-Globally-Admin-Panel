<?php
/**
 * Universal Activity Log & Audit Trail Engine
 * Tracks all user modifications, creations, deletions, restorations, logins, and settings changes.
 */

/**
 * Ensure activity_logs table and sidebar item exist in DB
 */
function sode_ensure_activity_log_system($db = null) {
    if (!$db) {
        $db = get_db_connection();
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `activity_logs` (
              `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `user_id` INT UNSIGNED NULL DEFAULT NULL,
              `user_name` VARCHAR(150) NULL DEFAULT NULL,
              `user_email` VARCHAR(191) NULL DEFAULT NULL,
              `role_name` VARCHAR(100) NULL DEFAULT NULL,
              `team_name` VARCHAR(100) NULL DEFAULT NULL,
              `action_type` VARCHAR(50) NOT NULL,
              `module_key` VARCHAR(100) NOT NULL,
              `item_type` VARCHAR(100) NULL DEFAULT NULL,
              `item_id` VARCHAR(100) NULL DEFAULT NULL,
              `item_title` VARCHAR(255) NULL DEFAULT NULL,
              `description` TEXT NOT NULL,
              `old_values` LONGTEXT NULL DEFAULT NULL,
              `new_values` LONGTEXT NULL DEFAULT NULL,
              `ip_address` VARCHAR(45) NULL DEFAULT NULL,
              `user_agent` VARCHAR(255) NULL DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_user_id` (`user_id`),
              INDEX `idx_action_type` (`action_type`),
              INDEX `idx_module_key` (`module_key`),
              INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        error_log("Failed to ensure activity_logs table: " . $e->getMessage());
    }

    // Auto-register in sidebar_items if missing
    try {
        $chk = $db->query("SELECT id FROM sidebar_items WHERE page_route = 'modules/activity_logs/index.php'")->fetch();
        if (!$chk) {
            $db->exec("
                INSERT INTO `sidebar_items` 
                (`display_name`, `page_route`, `sort_order`, `active_page_key`, `rbac_module_key`, `menu_section`, `icon_svg`, `is_superadmin_only`, `is_active`)
                VALUES 
                ('Activity History', 'modules/activity_logs/index.php', 98, 'activity_logs', 'activity_logs', 'SYSTEM', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"></circle><polyline points=\"12 6 12 12 16 14\"></polyline></svg>', 0, 1)
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
        error_log("Failed to auto-register activity_logs in sidebar: " . $e->getMessage());
    }
}

/**
 * Get Client IP Address
 */
function get_client_ip_address() {
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Log an Activity Event
 * 
 * @param string $action_type e.g. CREATE, UPDATE, DELETE, RESTORE, PURGE, LOGIN, LOGOUT, STATUS_CHANGE, PASSWORD_CHANGE
 * @param string $module_key  e.g. universities, courses, mappings, global_keys, rbac, auth, trash, settings
 * @param string $description Human-friendly description of what was changed
 * @param array  $options     Optional details:
 *                            - item_type (string) e.g. 'University', 'Course'
 *                            - item_id (string|int)
 *                            - item_title (string)
 *                            - old_values (array|string)
 *                            - new_values (array|string)
 *                            - user_id, user_name, user_email, role_name, team_name (overrides session)
 * @return bool
 */
function log_activity($action_type, $module_key, $description, array $options = []) {
    try {
        $db = get_db_connection();
        sode_ensure_activity_log_system($db);

        $current_user = function_exists('get_logged_in_user') ? get_logged_in_user() : null;

        $user_id    = $options['user_id'] ?? ($current_user['id'] ?? ($_SESSION['user_id'] ?? null));
        $user_name  = $options['user_name'] ?? ($current_user['name'] ?? ($_SESSION['user_name'] ?? 'System'));
        $user_email = $options['user_email'] ?? ($current_user['email'] ?? ($_SESSION['user_email'] ?? null));
        $role_name  = $options['role_name'] ?? ($current_user['role_name'] ?? ($_SESSION['user_role_name'] ?? 'Guest'));
        $team_name  = $options['team_name'] ?? ($current_user['team_name'] ?? ($_SESSION['user_team_name'] ?? 'General'));

        $item_type  = $options['item_type'] ?? null;
        $item_id    = isset($options['item_id']) ? (string)$options['item_id'] : null;
        $item_title = $options['item_title'] ?? null;

        // Process Old / New values
        $old_values = $options['old_values'] ?? null;
        $new_values = $options['new_values'] ?? null;

        // Strip passwords or sensitive tokens from logs
        $sensitive_fields = ['password', 'password_hash', 'csrf_token', 'token', 'auth_key'];
        
        if (is_array($old_values)) {
            foreach ($sensitive_fields as $f) {
                if (isset($old_values[$f])) $old_values[$f] = '********';
            }
            $old_values = json_encode($old_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($new_values)) {
            foreach ($sensitive_fields as $f) {
                if (isset($new_values[$f])) $new_values[$f] = '********';
            }
            $new_values = json_encode($new_values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $ip_address = get_client_ip_address();
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 250);

        $stmt = $db->prepare("
            INSERT INTO activity_logs 
            (user_id, user_name, user_email, role_name, team_name, action_type, module_key, item_type, item_id, item_title, description, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $ok = $stmt->execute([
            $user_id,
            $user_name,
            $user_email,
            $role_name,
            $team_name,
            strtoupper($action_type),
            strtolower($module_key),
            $item_type,
            $item_id,
            $item_title,
            $description,
            $old_values,
            $new_values,
            $ip_address,
            $user_agent
        ]);

        if ($ok) {
            // Auto-prune to retain strictly the latest 100 activity logs
            sode_prune_activity_logs($db, 100);

            // Auto-bust all Redis & Subdomain Caches whenever data changes in any module
            $mutating_actions = ['CREATE', 'UPDATE', 'DELETE', 'RESTORE', 'PURGE', 'STATUS_CHANGE', 'SETTINGS_CHANGE', 'UPLOAD', 'IMPORT', 'BATCH_UPDATE'];
            if (in_array(strtoupper($action_type), $mutating_actions)) {
                if (function_exists('sode_bust_all_subdomain_caches')) {
                    sode_bust_all_subdomain_caches($db);
                }
            }
        }

        return $ok;
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Auto-prune older activity logs to keep only the latest N logs (default: 100)
 * Prevents DB bloat by automatically deleting records older than the latest 100 items.
 *
 * @param PDO $db
 * @param int $keep_limit
 * @return int Number of deleted rows
 */
function sode_prune_activity_logs(PDO $db, int $keep_limit = 100): int {
    try {
        $stmt = $db->query("SELECT id FROM activity_logs ORDER BY id DESC LIMIT 1 OFFSET " . ($keep_limit - 1));
        $cutoff_id = $stmt ? $stmt->fetchColumn() : null;

        if ($cutoff_id) {
            $del_stmt = $db->prepare("DELETE FROM activity_logs WHERE id < ?");
            $del_stmt->execute([(int)$cutoff_id]);
            return $del_stmt->rowCount();
        }
    } catch (Exception $e) {
        error_log("Failed to prune activity logs: " . $e->getMessage());
    }
    return 0;
}

/**
 * Fetch Recent Activities
 */
function get_recent_activities($limit = 10, array $filters = []) {
    try {
        $db = get_db_connection();
        sode_ensure_activity_log_system($db);

        $where = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['action_type'])) {
            $where[] = "action_type = ?";
            $params[] = strtoupper($filters['action_type']);
        }
        if (!empty($filters['module_key'])) {
            $where[] = "module_key = ?";
            $params[] = strtolower($filters['module_key']);
        }
        if (!empty($filters['search'])) {
            $s = '%' . trim($filters['search']) . '%';
            $where[] = "(description LIKE ? OR item_title LIKE ? OR user_name LIKE ? OR ip_address LIKE ?)";
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $sql = "SELECT * FROM activity_logs";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to get recent activities: " . $e->getMessage());
        return [];
    }
}
