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

                // 3. Register Sidebar Item for Latest News
                $check_sidebar = $db->query("SELECT id FROM sidebar_items WHERE rbac_module_key = 'news' LIMIT 1")->fetch();
                if (!$check_sidebar) {
                    $sidebar_svg = '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z\"/></svg>';
                    $stmt = $db->prepare("
                        INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)
                    ");
                    $stmt->execute(['Universal News', 'modules/news/index.php', 5, 'news', 'news', 'MANAGE', $sidebar_svg]);
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
                    // Find DSU university ID if present
                    $dsu_id = $db->query("SELECT id FROM universities WHERE LOWER(slug) IN ('dsu', 'dayananda-sagar-university') OR LOWER(short_name) = 'dsu' LIMIT 1")->fetchColumn();
                    $dsu_id = $dsu_id ? (int)$dsu_id : null;

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
