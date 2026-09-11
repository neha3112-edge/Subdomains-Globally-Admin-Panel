<?php
/**
 * Universal Database Auto-Migration & Schema Sync
 * Automatically runs pending migrations on DB connection
 */

function sode_run_auto_migrations(PDO $pdo) {
    static $already_run = false;
    if ($already_run) {
        return;
    }
    $already_run = true;

    try {
        // 1. Ensure migrations tracking table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration_key VARCHAR(191) NOT NULL UNIQUE,
                batch INT DEFAULT 1,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 2. Fetch already executed migration keys
        $executed = $pdo->query("SELECT migration_key FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
        $executed_map = array_flip($executed);

        // 3. Define all system migrations
        $migrations = [
            '2026_09_08_001_initial_schema' => function(PDO $db) {
                $check = $db->query("SHOW TABLES LIKE 'universities'")->fetch();
                if (!$check) {
                    $schema_path = dirname(__DIR__, 2) . '/schema.sql';
                    if (file_exists($schema_path)) {
                        $sql = file_get_contents($schema_path);
                        $db->exec($sql);
                    }
                }
            },

            '2026_09_10_001_create_admin_settings_table' => function(PDO $db) {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS admin_settings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        setting_key VARCHAR(100) NOT NULL UNIQUE,
                        setting_value LONGTEXT NULL,
                        setting_group VARCHAR(50) DEFAULT 'general',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                // Seed default settings if empty
                $count = (int)$db->query("SELECT COUNT(*) FROM admin_settings")->fetchColumn();
                if ($count === 0) {
                    $stmt = $db->prepare("INSERT IGNORE INTO admin_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)");
                    $defaults = [
                        ['asset_storage_mode', 'relative', 'assets'],
                        ['cdn_base_url', '', 'assets'],
                        ['whatsapp_default_intent', 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure', 'general'],
                    ];
                    foreach ($defaults as $row) {
                        $stmt->execute($row);
                    }
                }
            },

            '2026_09_10_002_add_whatsapp_btn_intent_column' => function(PDO $db) {
                $cols = $db->query("SHOW COLUMNS FROM universities LIKE 'whatsapp_btn_intent'")->fetch();
                if (!$cols) {
                    $db->exec("ALTER TABLE universities ADD COLUMN whatsapp_btn_intent VARCHAR(255) NULL AFTER youtube_video_url");
                }
            },

            '2026_09_10_003_add_accreditations_columns' => function(PDO $db) {
                $has_desc = $db->query("SHOW COLUMNS FROM accreditations LIKE 'description'")->fetch();
                if (!$has_desc) {
                    $db->exec("ALTER TABLE accreditations ADD COLUMN description TEXT NULL AFTER badge_image_url");
                }
            },

            '2026_09_10_004_normalize_localhost_urls_to_relative' => function(PDO $db) {
                // Auto-cleanup any hardcoded full URLs in universities table to relative paths
                $unis = $db->query("SELECT id, logo_url, desktop_banner_bg, mobile_banner_bg, campus_mobile_img, brochure_pdf_url, podcast_audio_url FROM universities")->fetchAll();
                $update = $db->prepare("
                    UPDATE universities SET
                        logo_url = ?, desktop_banner_bg = ?, mobile_banner_bg = ?, campus_mobile_img = ?,
                        brochure_pdf_url = ?, podcast_audio_url = ?
                    WHERE id = ?
                ");

                $to_rel = function($val) {
                    if (empty($val)) return $val;
                    if (preg_match('#(?:https?://[^/]+)?(/assets/.*)$#i', $val, $m)) {
                        return $m[1];
                    }
                    if (preg_match('#(?:https?://[^/]+)?(/uploads/.*)$#i', $val, $m)) {
                        return $m[1];
                    }
                    return $val;
                };

                foreach ($unis as $u) {
                    $new_logo = $to_rel($u['logo_url']);
                    $new_desk = $to_rel($u['desktop_banner_bg']);
                    $new_mob  = $to_rel($u['mobile_banner_bg']);
                    $new_camp = $to_rel($u['campus_mobile_img']);
                    $new_broc = $to_rel($u['brochure_pdf_url']);
                    $new_pod  = $to_rel($u['podcast_audio_url']);

                    if (
                        $new_logo !== $u['logo_url'] || $new_desk !== $u['desktop_banner_bg'] ||
                        $new_mob !== $u['mobile_banner_bg'] || $new_camp !== $u['campus_mobile_img'] ||
                        $new_broc !== $u['brochure_pdf_url'] || $new_pod !== $u['podcast_audio_url']
                    ) {
                        $update->execute([$new_logo, $new_desk, $new_mob, $new_camp, $new_broc, $new_pod, $u['id']]);
                    }
                }
            },

            '2026_09_11_001_create_news_items_table' => function(PDO $db) {
                // 1. Create or ensure news_items table exists
                $db->exec("
                    CREATE TABLE IF NOT EXISTS news_items (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        is_global TINYINT(1) DEFAULT 1,
                        university_id INT UNSIGNED NULL,
                        news_text TEXT NOT NULL,
                        news_link TEXT NULL,
                        has_badge TINYINT(1) DEFAULT 1,
                        badge_text VARCHAR(50) DEFAULT 'New',
                        sort_order INT DEFAULT 0,
                        is_active TINYINT(1) DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_news_global (is_global),
                        INDEX idx_news_uni (university_id),
                        INDEX idx_news_active (is_active)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // 2. Add any missing columns if table existed from old schema
                $existing_cols = $db->query("SHOW COLUMNS FROM news_items")->fetchAll(PDO::FETCH_COLUMN);
                $cols_map = array_flip($existing_cols);

                if (!isset($cols_map['has_badge'])) {
                    $db->exec("ALTER TABLE news_items ADD COLUMN has_badge TINYINT(1) DEFAULT 1 AFTER news_link");
                }
                if (!isset($cols_map['badge_text'])) {
                    $db->exec("ALTER TABLE news_items ADD COLUMN badge_text VARCHAR(50) DEFAULT 'New' AFTER has_badge");
                }
                if (!isset($cols_map['sort_order'])) {
                    $db->exec("ALTER TABLE news_items ADD COLUMN sort_order INT DEFAULT 0 AFTER badge_text");
                }
                if (!isset($cols_map['is_active'])) {
                    $db->exec("ALTER TABLE news_items ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER sort_order");
                }

                // 3. Register Sidebar Item for Universal News in SETTINGS
                $check_sidebar = $db->query("SELECT id FROM sidebar_items WHERE rbac_module_key IN ('news', 'universal_news') LIMIT 1")->fetch();
                $sidebar_svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>';
                
                if ($check_sidebar) {
                    $db->prepare("
                        UPDATE sidebar_items 
                        SET display_name = 'Universal News', page_route = 'modules/universal_news/index.php',
                            active_page_key = 'universal_news', rbac_module_key = 'universal_news', menu_section = 'SETTINGS', sort_order = 13, icon_svg = ?
                        WHERE id = ?
                    ")->execute([$sidebar_svg, $check_sidebar['id']]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)
                    ");
                    $stmt->execute(['Universal News', 'modules/universal_news/index.php', 13, 'universal_news', 'universal_news', 'SETTINGS', $sidebar_svg]);
                    $sidebar_id = $db->lastInsertId();

                    // Grant permission to all existing roles
                    $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
                    $rsa_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                    foreach ($roles as $r_id) {
                        $rsa_stmt->execute([$r_id, $sidebar_id]);
                    }
                }

                // 4. Seed initial news items if table is empty
                $count = (int)$db->query("SELECT COUNT(*) FROM news_items")->fetchColumn();
                if ($count === 0) {
                    $seed_stmt = $db->prepare("
                        INSERT INTO news_items (is_global, university_id, news_text, news_link, has_badge, badge_text, sort_order, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");

                    // Seed Universal items
                    $seed_stmt->execute([
                        1,
                        null,
                        'For the latest notifications regarding student support, access the {UNIVERSITY_SHORT_NAME} Student Support Page.',
                        '#student-support',
                        1,
                        'New',
                        1
                    ]);

                    $seed_stmt->execute([
                        1,
                        null,
                        'Get notifications for the latest placement drives at {UNIVERSITY_NAME} $YEAR$',
                        '#placement-drives',
                        1,
                        'New',
                        2
                    ]);

                    $seed_stmt->execute([
                        1,
                        null,
                        'Upcoming News & Events at {UNIVERSITY_NAME} $YEAR$',
                        '#news-events',
                        1,
                        'New',
                        3
                    ]);
                }
            },

            '2026_09_11_002_move_universal_news_to_settings' => function(PDO $db) {
                // Ensure sidebar item is properly updated to SETTINGS
                $sidebar_svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>';
                
                $check = $db->query("SELECT id FROM sidebar_items WHERE rbac_module_key IN ('news', 'universal_news') LIMIT 1")->fetch();
                if ($check) {
                    $db->prepare("
                        UPDATE sidebar_items 
                        SET display_name = 'Universal News', page_route = 'modules/universal_news/index.php',
                            active_page_key = 'universal_news', rbac_module_key = 'universal_news', menu_section = 'SETTINGS', sort_order = 13, icon_svg = ?
                        WHERE id = ?
                    ")->execute([$sidebar_svg, $check['id']]);
                    $sidebar_id = $check['id'];
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)
                    ");
                    $stmt->execute(['Universal News', 'modules/universal_news/index.php', 13, 'universal_news', 'universal_news', 'SETTINGS', $sidebar_svg]);
                    $sidebar_id = $db->lastInsertId();
                }

                // Ensure all roles have access
                $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
                $rsa_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                foreach ($roles as $r_id) {
                    $rsa_stmt->execute([$r_id, $sidebar_id]);
                }
            },

            '2026_09_11_003_add_mode_to_course_mappings' => function(PDO $db) {
                $has_mode = $db->query("SHOW COLUMNS FROM university_course_mappings LIKE 'mode'")->fetch();
                if (!$has_mode) {
                    $db->exec("ALTER TABLE university_course_mappings ADD COLUMN mode VARCHAR(50) NOT NULL DEFAULT 'Online' AFTER course_id");
                }
                // Update unique index to include mode so same course can be offered in Online and Distance
                try {
                    $indexes = $db->query("SHOW INDEX FROM university_course_mappings WHERE Key_name = 'uniq_uni_course'")->fetchAll();
                    if (!empty($indexes)) {
                        $db->exec("ALTER TABLE university_course_mappings DROP INDEX uniq_uni_course");
                    }
                } catch (Exception $e) {}
                try {
                    $indexes_new = $db->query("SHOW INDEX FROM university_course_mappings WHERE Key_name = 'uniq_uni_course_mode'")->fetchAll();
                    if (empty($indexes_new)) {
                        $db->exec("ALTER TABLE university_course_mappings ADD UNIQUE KEY uniq_uni_course_mode (university_id, course_id, mode)");
                    }
                } catch (Exception $e) {}
            },

            '2026_09_11_004_create_admission_process_steps' => function(PDO $db) {
                // 1. Create table
                $db->exec("
                    CREATE TABLE IF NOT EXISTS admission_process_steps (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        university_id INT UNSIGNED NULL DEFAULT NULL,
                        step_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
                        color_hex VARCHAR(20) NOT NULL DEFAULT '#3B7FD1',
                        title VARCHAR(255) NOT NULL,
                        description TEXT NOT NULL,
                        icon_svg TEXT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_aps_uni_step (university_id, step_number)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // Add FK only if universities table exists
                try {
                    $has_fk = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME='admission_process_steps' AND CONSTRAINT_NAME='fk_aps_university'")->fetchColumn();
                    if (!$has_fk) {
                        $db->exec("ALTER TABLE admission_process_steps ADD CONSTRAINT fk_aps_university FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE CASCADE");
                    }
                } catch (Exception $e) {}

                // 2. Seed 8 default universal steps if table is empty
                $count = (int)$db->query("SELECT COUNT(*) FROM admission_process_steps WHERE university_id IS NULL")->fetchColumn();
                if ($count === 0) {
                    $stmt = $db->prepare("
                        INSERT INTO admission_process_steps (university_id, step_number, color_hex, title, description, icon_svg)
                        VALUES (NULL, ?, ?, ?, ?, ?)
                    ");

                    $steps = [
                        [1, '#E23F73', 'Visit the {uni_short} {mode} website',
                            'Go to the official {uni_short} {mode} admission portal to register yourself.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><line x1="3" y1="12" x2="21" y2="12"></line><path d="M12 3a13.7 13.7 0 0 1 3.5 9A13.7 13.7 0 0 1 12 21a13.7 13.7 0 0 1-3.5-9A13.7 13.7 0 0 1 12 3z"></path></svg>'],
                        [2, '#E8622F', 'Verify the Registration',
                            'Confirm the registered email and mobile number via OTP-based secure access.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M19 8v6M22 11h-6"></path></svg>'],
                        [3, '#EFA23C', 'Pay Application Fee',
                            'Complete the one-time, non-refundable application fee through its secure payment gateways.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>'],
                        [4, '#2FB897', 'Fill the Application Form',
                            'Mention personal, academic and professional information accurately.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line><line x1="6" y1="15" x2="10" y2="15"></line></svg>'],
                        [5, '#2FA0B8', 'Upload the Documents',
                            'Submit the scanned copies of the photographs, certificates and identity proofs.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>'],
                        [6, '#3B7FD1', 'Submit Application',
                            'Review all details and submit your application for processing.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>'],
                        [7, '#4A5FC7', 'Document Verification',
                            '{uni} will verify your details and the originality of your documents.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="M9 12l2 2 4-4"></path></svg>'],
                        [8, '#2C3E7A', 'Admission Confirmation',
                            'You will get a confirmation email once your admission is successfully verified.',
                            '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12z"></path><polyline points="9 11 11 13 15 9"></polyline><path d="M8.5 14.5L7 22l5-3 5 3-1.5-7.5"></path></svg>'],
                    ];

                    foreach ($steps as $s) {
                        $stmt->execute($s);
                    }
                }

                // 3. Register Sidebar Item for Admission Process if missing
                $check_sidebar = $db->query("SELECT id FROM sidebar_items WHERE rbac_module_key = 'admission_process' LIMIT 1")->fetch();
                if (!$check_sidebar) {
                    $svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>';
                    $stmt2 = $db->prepare("INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)");
                    $stmt2->execute(['Admission Process', 'modules/admission_process/index.php', 14, 'admission_process', 'admission_process', 'SETTINGS', $svg]);
                    $sidebar_id = $db->lastInsertId();

                    // Grant access to all roles
                    $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
                    $rsa = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                    foreach ($roles as $rid) {
                        $rsa->execute([$rid, $sidebar_id]);
                    }
                }
            }
        ];

        // 4. Run pending migrations in order
        $record_stmt = $pdo->prepare("INSERT INTO schema_migrations (migration_key, batch) VALUES (?, 1)");
        foreach ($migrations as $key => $callback) {
            if (!isset($executed_map[$key])) {
                try {
                    $callback($pdo);
                    $record_stmt->execute([$key]);
                } catch (Exception $mig_err) {
                    error_log("Auto Migration error on [{$key}]: " . $mig_err->getMessage());
                }
            }
        }

    } catch (Exception $e) {
        error_log("Auto Migration Setup error: " . $e->getMessage());
    }
}
