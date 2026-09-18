<?php
/**
 * Universal Admin Trash & Restore Engine
 * Centralized soft-delete, file quarantine, restore, and purge mechanisms
 */

/**
 * Ensure the admin_trash table and uploads/trash directory exist
 */
function sode_ensure_trash_system($db = null) {
    if (!$db) {
        $db = get_db_connection();
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `admin_trash` (
              `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              `item_type` VARCHAR(50) NOT NULL,
              `item_title` VARCHAR(255) NOT NULL,
              `source_table` VARCHAR(64) NOT NULL,
              `original_id` INT UNSIGNED NOT NULL,
              `data_payload` LONGTEXT NOT NULL,
              `file_path` VARCHAR(500) NULL DEFAULT NULL,
              `trash_file_path` VARCHAR(500) NULL DEFAULT NULL,
              `deleted_by_user_id` INT UNSIGNED NULL DEFAULT NULL,
              `deleted_by_user_name` VARCHAR(100) NULL DEFAULT NULL,
              `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              INDEX `idx_item_type` (`item_type`),
              INDEX `idx_deleted_at` (`deleted_at`),
              INDEX `idx_source` (`source_table`, `original_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {
        error_log("Failed to ensure admin_trash table: " . $e->getMessage());
    }

    // Ensure uploads/trash directory exists
    $trash_dir = ADMIN_PATH . '/uploads/trash';
    if (!is_dir($trash_dir)) {
        @mkdir($trash_dir, 0755, true);
    }

    // Auto-register Trash in sidebar_items if missing
    try {
        $chk = $db->query("SELECT id FROM sidebar_items WHERE page_route = 'modules/trash/index.php'")->fetch();
        if (!$chk) {
            $db->exec("
                INSERT INTO `sidebar_items` 
                (`display_name`, `page_route`, `sort_order`, `active_page_key`, `rbac_module_key`, `menu_section`, `icon_svg`, `is_superadmin_only`, `is_active`)
                VALUES 
                ('Trash', 'modules/trash/index.php', 99, 'trash', 'trash', 'SYSTEM', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><polyline points=\"3 6 5 6 21 6\"></polyline><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"></path><line x1=\"10\" y1=\"11\" x2=\"10\" y2=\"17\"></line><line x1=\"14\" y1=\"11\" x2=\"14\" y2=\"17\"></line></svg>', 0, 1)
            ");
            $trash_sidebar_id = (int)$db->lastInsertId();
            if ($trash_sidebar_id) {
                // Grant access to all existing roles by default
                $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($roles)) {
                    $rsa_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                    foreach ($roles as $r_id) {
                        $rsa_stmt->execute([$r_id, $trash_sidebar_id]);
                    }
                }
            }
        }
    } catch (Exception $e) {}
}

/**
 * Move any item and its cascaded dependencies or media files to Trash
 *
 * @param string $source_table Table name
 * @param int $original_id Primary key ID
 * @param string $item_title Human-readable label (e.g. university name, course name)
 * @param array $extra Optional custom payload additions or configurations
 * @return bool True on success
 */
function move_to_trash(string $source_table, int $original_id, string $item_title = '', array $extra = []): bool {
    $db = get_db_connection();
    sode_ensure_trash_system($db);

    // 1. Fetch main record
    $stmt = $db->prepare("SELECT * FROM `{$source_table}` WHERE id = ?");
    $stmt->execute([$original_id]);
    $main_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$main_row) {
        return false;
    }

    // Determine clean human-readable title if empty
    if (empty($item_title)) {
        $item_title = $main_row['short_name'] 
            ?? $main_row['full_name'] 
            ?? $main_row['title'] 
            ?? $main_row['name'] 
            ?? $main_row['file_name'] 
            ?? $main_row['key_code'] 
            ?? $main_row['subject_name'] 
            ?? ("Item #" . $original_id);
    }

    // Determine normalized item_type
    $type_map = [
        'universities' => 'university',
        'courses' => 'course',
        'university_course_mappings' => 'mapping',
        'media_library' => 'media',
        'global_keys' => 'global_key',
        'news_items' => 'news',
        'accreditations' => 'accreditation',
        'admission_process_steps' => 'admission_step',
        'course_specializations_master' => 'specialization',
        'course_syllabus_subjects_master' => 'syllabus',
        'course_durations_master' => 'duration',
        'education_modes_master' => 'mode',
        'degree_levels_master' => 'level',
        'roles' => 'role',
        'teams' => 'team',
        'users' => 'user',
        'footer_configurations' => 'footer'
    ];
    $item_type = $type_map[$source_table] ?? rtrim($source_table, 's');

    // 2. Fetch and backup relational children (cascading support)
    $children = [];
    if ($source_table === 'universities') {
        // Mappings
        $cStmt = $db->prepare("SELECT * FROM university_course_mappings WHERE university_id = ?");
        $cStmt->execute([$original_id]);
        $children['mappings'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        // Accreditations
        $aStmt = $db->prepare("SELECT * FROM university_accreditations WHERE university_id = ?");
        $aStmt->execute([$original_id]);
        $children['accreditations'] = $aStmt->fetchAll(PDO::FETCH_ASSOC);

        // News items
        $nStmt = $db->prepare("SELECT * FROM news_items WHERE university_id = ?");
        $nStmt->execute([$original_id]);
        $children['news'] = $nStmt->fetchAll(PDO::FETCH_ASSOC);

        // Admission steps overrides
        $sStmt = $db->prepare("SELECT * FROM admission_process_steps WHERE university_id = ?");
        $sStmt->execute([$original_id]);
        $children['admission_steps'] = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($source_table === 'courses') {
        // Mappings attached to this course
        $cStmt = $db->prepare("SELECT * FROM university_course_mappings WHERE course_id = ?");
        $cStmt->execute([$original_id]);
        $children['mappings'] = $cStmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($source_table === 'university_course_mappings') {
        // Specializations attached to this mapping
        try {
            $sStmt = $db->prepare("SELECT * FROM course_specializations WHERE mapping_id = ?");
            $sStmt->execute([$original_id]);
            $children['specializations'] = $sStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}
    }

    // 3. Media Quarantine (Move physical file to uploads/trash/)
    $orig_file_path = null;
    $trash_file_path = null;

    if ($source_table === 'media_library') {
        $clean_rel = ltrim($main_row['file_path'] ?? '', '/');
        $orig_file_path = $clean_rel;
        
        $abs_source = (strpos($clean_rel, 'uploads/') === 0) 
            ? ADMIN_PATH . '/' . $clean_rel 
            : ADMIN_PATH . '/uploads/' . $clean_rel;

        if (file_exists($abs_source)) {
            $trash_dir = ADMIN_PATH . '/uploads/trash';
            if (!is_dir($trash_dir)) {
                @mkdir($trash_dir, 0755, true);
            }
            $ext = pathinfo($abs_source, PATHINFO_EXTENSION);
            $trash_filename = 'trash_' . $original_id . '_' . time() . '.' . $ext;
            $abs_target = $trash_dir . '/' . $trash_filename;

            if (@rename($abs_source, $abs_target)) {
                $trash_file_path = 'uploads/trash/' . $trash_filename;
            }
        }
    }

    // 4. Serialize payload
    $payload_arr = [
        'main' => $main_row,
        'children' => $children,
        'extra' => $extra
    ];
    $data_payload = json_encode($payload_arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Current user context
    $current_user = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
    $user_id = $current_user['id'] ?? null;
    $user_name = $current_user['name'] ?? 'Admin';

    // 5. Insert into admin_trash
    $ins = $db->prepare("
        INSERT INTO `admin_trash` 
        (`item_type`, `item_title`, `source_table`, `original_id`, `data_payload`, `file_path`, `trash_file_path`, `deleted_by_user_id`, `deleted_by_user_name`, `deleted_at`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $ins->execute([
        $item_type,
        $item_title,
        $source_table,
        $original_id,
        $data_payload,
        $orig_file_path,
        $trash_file_path,
        $user_id,
        $user_name
    ]);

    // 6. Delete from live table (and cascaded children if applicable)
    if ($source_table === 'universities') {
        $db->prepare("DELETE FROM university_course_mappings WHERE university_id = ?")->execute([$original_id]);
        $db->prepare("DELETE FROM university_accreditations WHERE university_id = ?")->execute([$original_id]);
        $db->prepare("DELETE FROM news_items WHERE university_id = ?")->execute([$original_id]);
        $db->prepare("DELETE FROM admission_process_steps WHERE university_id = ?")->execute([$original_id]);
    } elseif ($source_table === 'courses') {
        $db->prepare("DELETE FROM university_course_mappings WHERE course_id = ?")->execute([$original_id]);
    } elseif ($source_table === 'university_course_mappings') {
        try {
            $db->prepare("DELETE FROM course_specializations WHERE mapping_id = ?")->execute([$original_id]);
        } catch (Exception $e) {}
    }

    $del = $db->prepare("DELETE FROM `{$source_table}` WHERE id = ?");
    $del->execute([$original_id]);

    // 7. Flush cache
    if (function_exists('sode_bust_all_subdomain_caches')) {
        sode_bust_all_subdomain_caches($db);
    }

    return true;
}

/**
 * Restore an item from trash back to its live source table
 *
 * @param int $trash_id ID in admin_trash
 * @return array ['success' => bool, 'message' => string]
 */
function restore_from_trash(int $trash_id): array {
    $db = get_db_connection();
    sode_ensure_trash_system($db);

    $stmt = $db->prepare("SELECT * FROM admin_trash WHERE id = ?");
    $stmt->execute([$trash_id]);
    $trash_item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trash_item) {
        return ['success' => false, 'message' => 'Trash record not found.'];
    }

    $payload = json_decode($trash_item['data_payload'], true);
    if (!$payload || empty($payload['main'])) {
        return ['success' => false, 'message' => 'Corrupt trash payload. Cannot restore.'];
    }

    $source_table = $trash_item['source_table'];
    $main_row = $payload['main'];
    $children = $payload['children'] ?? [];

    // 1. Restore Media file if applicable
    if ($source_table === 'media_library' && !empty($trash_item['trash_file_path'])) {
        $abs_trash = ADMIN_PATH . '/' . ltrim($trash_item['trash_file_path'], '/');
        $orig_rel = ltrim($trash_item['file_path'], '/');
        $abs_dest = (strpos($orig_rel, 'uploads/') === 0) 
            ? ADMIN_PATH . '/' . $orig_rel 
            : ADMIN_PATH . '/uploads/' . $orig_rel;

        if (file_exists($abs_trash)) {
            $dest_dir = dirname($abs_dest);
            if (!is_dir($dest_dir)) {
                @mkdir($dest_dir, 0755, true);
            }
            @rename($abs_trash, $abs_dest);
        }
    }

    // 2. Re-insert main row
    $columns = array_keys($main_row);
    $placeholders = array_map(function($col) { return ":$col"; }, $columns);
    $update_parts = array_map(function($col) { return "`$col` = VALUES(`$col`)"; }, $columns);

    $sql = "INSERT INTO `{$source_table}` (" . implode(', ', array_map(function($c) { return "`$c`"; }, $columns)) . ")
            VALUES (" . implode(', ', $placeholders) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts);

    $ins_stmt = $db->prepare($sql);
    foreach ($main_row as $col => $val) {
        $ins_stmt->bindValue(":$col", $val);
    }
    $ins_stmt->execute();

    // 3. Re-insert relational children if any
    if (!empty($children['mappings'])) {
        foreach ($children['mappings'] as $m) {
            $m_cols = array_keys($m);
            $m_sql = "INSERT INTO `university_course_mappings` (" . implode(', ', array_map(function($c) { return "`$c`"; }, $m_cols)) . ")
                      VALUES (" . implode(', ', array_map(function($c) { return ":$c"; }, $m_cols)) . ")
                      ON DUPLICATE KEY UPDATE id=id";
            $m_stmt = $db->prepare($m_sql);
            foreach ($m as $k => $v) { $m_stmt->bindValue(":$k", $v); }
            $m_stmt->execute();
        }
    }

    if (!empty($children['accreditations'])) {
        foreach ($children['accreditations'] as $a) {
            $a_cols = array_keys($a);
            $a_sql = "INSERT INTO `university_accreditations` (" . implode(', ', array_map(function($c) { return "`$c`"; }, $a_cols)) . ")
                      VALUES (" . implode(', ', array_map(function($c) { return ":$c"; }, $a_cols)) . ")
                      ON DUPLICATE KEY UPDATE id=id";
            $a_stmt = $db->prepare($a_sql);
            foreach ($a as $k => $v) { $a_stmt->bindValue(":$k", $v); }
            $a_stmt->execute();
        }
    }

    if (!empty($children['news'])) {
        foreach ($children['news'] as $n) {
            $n_cols = array_keys($n);
            $n_sql = "INSERT INTO `news_items` (" . implode(', ', array_map(function($c) { return "`$c`"; }, $n_cols)) . ")
                      VALUES (" . implode(', ', array_map(function($c) { return ":$c"; }, $n_cols)) . ")
                      ON DUPLICATE KEY UPDATE id=id";
            $n_stmt = $db->prepare($n_sql);
            foreach ($n as $k => $v) { $n_stmt->bindValue(":$k", $v); }
            $n_stmt->execute();
        }
    }

    if (!empty($children['admission_steps'])) {
        foreach ($children['admission_steps'] as $s) {
            $s_cols = array_keys($s);
            $s_sql = "INSERT INTO `admission_process_steps` (" . implode(', ', array_map(function($c) { return "`$c`"; }, $s_cols)) . ")
                      VALUES (" . implode(', ', array_map(function($c) { return ":$c"; }, $s_cols)) . ")
                      ON DUPLICATE KEY UPDATE id=id";
            $s_stmt = $db->prepare($s_sql);
            foreach ($s as $k => $v) { $s_stmt->bindValue(":$k", $v); }
            $s_stmt->execute();
        }
    }

    if (!empty($children['specializations'])) {
        foreach ($children['specializations'] as $sp) {
            $sp_cols = array_keys($sp);
            $sp_sql = "INSERT INTO `course_specializations` (" . implode(', ', array_map(function($c) { return "`$c`"; }, $sp_cols)) . ")
                       VALUES (" . implode(', ', array_map(function($c) { return ":$c"; }, $sp_cols)) . ")
                       ON DUPLICATE KEY UPDATE id=id";
            $sp_stmt = $db->prepare($sp_sql);
            foreach ($sp as $k => $v) { $sp_stmt->bindValue(":$k", $v); }
            $sp_stmt->execute();
        }
    }

    // 4. Remove from admin_trash
    $db->prepare("DELETE FROM admin_trash WHERE id = ?")->execute([$trash_id]);

    // 5. Bust caches
    if (function_exists('sode_bust_all_subdomain_caches')) {
        sode_bust_all_subdomain_caches($db);
    }

    return [
        'success' => true, 
        'message' => 'Item "' . htmlspecialchars($trash_item['item_title']) . '" restored successfully!'
    ];
}

/**
 * Permanently delete an item from trash (purging record and physical file)
 *
 * @param int $trash_id ID in admin_trash
 * @return array ['success' => bool, 'message' => string]
 */
function permanent_delete_from_trash(int $trash_id): array {
    $db = get_db_connection();
    sode_ensure_trash_system($db);

    $stmt = $db->prepare("SELECT * FROM admin_trash WHERE id = ?");
    $stmt->execute([$trash_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        return ['success' => false, 'message' => 'Trash item not found.'];
    }

    // Unlink physical file if quarantined
    if (!empty($item['trash_file_path'])) {
        $abs_path = ADMIN_PATH . '/' . ltrim($item['trash_file_path'], '/');
        if (file_exists($abs_path)) {
            @unlink($abs_path);
        }
    }

    $db->prepare("DELETE FROM admin_trash WHERE id = ?")->execute([$trash_id]);

    return [
        'success' => true,
        'message' => 'Item "' . htmlspecialchars($item['item_title']) . '" permanently deleted.'
    ];
}

/**
 * Bulk restore multiple trash IDs
 *
 * @param array $trash_ids
 * @return int Count restored
 */
function bulk_trash_restore(array $trash_ids): int {
    $count = 0;
    foreach ($trash_ids as $id) {
        $res = restore_from_trash((int)$id);
        if ($res['success']) {
            $count++;
        }
    }
    return $count;
}

/**
 * Bulk permanent delete multiple trash IDs
 *
 * @param array $trash_ids
 * @return int Count deleted
 */
function bulk_trash_permanent_delete(array $trash_ids): int {
    $count = 0;
    foreach ($trash_ids as $id) {
        $res = permanent_delete_from_trash((int)$id);
        if ($res['success']) {
            $count++;
        }
    }
    return $count;
}

/**
 * Empty all trash or empty trash by item type
 *
 * @param string|null $item_type Optional filter by item_type
 * @return int Count purged
 */
function empty_all_trash(?string $item_type = null): int {
    $db = get_db_connection();
    sode_ensure_trash_system($db);

    $where = "";
    $params = [];
    if (!empty($item_type) && $item_type !== 'all') {
        $where = "WHERE item_type = ?";
        $params[] = $item_type;
    }

    $stmt = $db->prepare("SELECT * FROM admin_trash $where");
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $purged = 0;
    foreach ($items as $it) {
        if (!empty($it['trash_file_path'])) {
            $abs_path = ADMIN_PATH . '/' . ltrim($it['trash_file_path'], '/');
            if (file_exists($abs_path)) {
                @unlink($abs_path);
            }
        }
        $purged++;
    }

    $del_stmt = $db->prepare("DELETE FROM admin_trash $where");
    $del_stmt->execute($params);

    return $purged;
}

/**
 * Get total count of items in trash
 *
 * @param string|null $item_type Optional filter
 * @return int
 */
function get_trash_count(?string $item_type = null): int {
    $db = get_db_connection();
    sode_ensure_trash_system($db);

    try {
        if (!empty($item_type) && $item_type !== 'all') {
            $stmt = $db->prepare("SELECT COUNT(*) FROM admin_trash WHERE item_type = ?");
            $stmt->execute([$item_type]);
            return (int)$stmt->fetchColumn();
        }
        return (int)$db->query("SELECT COUNT(*) FROM admin_trash")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
