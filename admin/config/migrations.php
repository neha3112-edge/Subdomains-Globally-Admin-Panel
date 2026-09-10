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
