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
            },

            '2026_09_11_005_create_legal_pages_table' => function(PDO $db) {
                // 1. Create table
                $db->exec("
                    CREATE TABLE IF NOT EXISTS legal_pages (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        page_type VARCHAR(50) NOT NULL UNIQUE,
                        heading VARCHAR(255) NOT NULL,
                        content_html LONGTEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // 2. Seed default content if table is empty
                $count = (int)$db->query("SELECT COUNT(*) FROM legal_pages")->fetchColumn();
                if ($count === 0) {
                    $stmt = $db->prepare("INSERT INTO legal_pages (page_type, heading, content_html) VALUES (?, ?, ?)");

                    $stmt->execute(['disclaimer', 'Disclaimer', '<p>The information provided on DistanceEducationSchool.com, operated by <strong>SODE&trade; Counselling Services LLP</strong>, registered with the Ministry of Corporate Affairs, is intended solely for educational information, guidance, and counselling purposes. Working as an independent education guidance platform, not a university, regulatory body, degree-awarding institution, or admission authority. University and programme details, including approvals, eligibility, fees, and admissions, are subject to change. It is advisable to verify all such information directly with the respective university\'s official website.</p>
<h3>Essential Points &amp; Guidance Policy</h3>
<ul>
    <li><strong>Independent Entity:</strong> DistanceEducationSchool.com, operated by SODE&trade; Counselling Services LLP, is an independent education information and counselling platform.</li>
    <li><strong>Official University Verification:</strong> Users are advised to verify updated admissions, fees, eligibility, program structure, approvals, and other official information through the respective university\'s official website.</li>
    <li><strong>Trademarks &amp; Copyrights:</strong> All University names, logos, trademarks, and other brand assets displayed on our platform are used solely for identification, informational, educational, and guidance purposes and remain the intellectual property of their respective university owners.</li>
    <li><strong>Free Counselling:</strong> We offer free educational information and counselling to help students understand and compare suitable academic opportunities. We do not charge students any fees for counselling or guidance regarding university applications.</li>
    <li><strong>No Degree Authorization:</strong> We do not issue degrees, certificates, marksheets, or academic credentials, nor do we have the authority to grant admissions on behalf of any university.</li>
    <li><strong>Information Integrity:</strong> We strive to provide accurate, relevant, and up-to-date educational information, career guidance, and student support while maintaining and respecting the credibility and reputation of all higher education institutions.</li>
    <li><strong>Transparency:</strong> Our objective is to provide transparent educational guidance and student support and help learners make informed decisions regarding online and distance education opportunities.</li>
</ul>']);

                    $stmt->execute(['privacy_policy', 'Privacy Policy', '<p>All information on this platform is provided by <strong>DistanceEducationSchool.com</strong>, under the legal name of <strong>SODE&trade; Counselling Services LLP</strong>. We are an educational counselling platform that helps students find trusted distance and online courses from UGC-DEB-approved universities. Our goal is to provide accurate information and personalised support to help you choose the right program.</p>
<h3>1. No Personal Data Collected by Default</h3>
<p>You can freely browse our website without sharing any personal information. We do not collect your name, phone number, or email address unless you choose to fill out a form or contact us directly.</p>
<h3>2. How We Use It</h3>
<p>Your information is used to guide you in choosing the right university or course, provide counselling support, and share admission-related updates. We may send you important updates via WhatsApp and email. You can opt out anytime.</p>
<h3>3. Scope</h3>
<p>This privacy policy applies to visitors who access this specific platform operated under DistanceEducationSchool.com by SODE&trade; Counselling Services LLP. It covers how we collect, use, and protect data when you explore course information, compare universities, or fill out enquiry forms on this platform.</p>
<h3>4. Data Sharing</h3>
<p>We share your details only with trusted university partners, and only for the purpose of counselling or admission. We do not sell or share data with third-party advertisers.</p>
<h3>5. External Links</h3>
<p>Our website may include links to official university portals. We are not responsible for the content or privacy policies of those external sites.</p>
<h3>6. Cookies and Analytics</h3>
<p>Our website uses cookies to improve the user experience. These help us understand how visitors use our site (e.g., most viewed pages, time spent, etc.). These cookies do not identify you personally.</p>']);

                    $stmt->execute(['terms_conditions', 'Terms &amp; Conditions', '<p>This page outlines the terms and conditions that apply when you access or use services provided on this platform, operated by <strong>SODE&trade; Counselling Services LLP</strong> under <strong>DistanceEducationSchool.com</strong>.</p>
<p>We help students and working professionals explore distance and online education options offered by UGC-DEB-approved universities.</p>
<h3>1. Our Role</h3>
<p>We provide information and counselling services only. We are not a university and do not collect any university fees directly. All academic or admission-related payments must be made to the respective university.</p>
<h3>2. Unauthorised Use or Fraud</h3>
<p>If you suspect any unauthorised transaction linked to a service on our platform, report it immediately. We will coordinate with the respective payment partner for further action.</p>
<h3>3. Updates to These Terms</h3>
<p>These terms may be updated as services evolve. Continued use of this platform implies your agreement to the latest version of these terms.</p>
<h3>4. Contact Us</h3>
<p>For support, email us at: <strong>support@distanceeducationschool.com</strong></p>']);
                }

                // 3. Register sidebar item if missing
                $check = $db->query("SELECT id FROM sidebar_items WHERE rbac_module_key = 'legal_pages' LIMIT 1")->fetch();
                if (!$check) {
                    $svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>';
                    $s = $db->prepare("INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)");
                    $s->execute(['Legal Popups', 'modules/legal_pages/index.php', 15, 'legal_pages', 'legal_pages', 'SETTINGS', $svg]);
                    $sid = $db->lastInsertId();
                    $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
                    $rsa = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                    foreach ($roles as $rid) { $rsa->execute([$rid, $sid]); }
                }
            },

            '2026_09_11_006_create_footer_config_table' => function(PDO $db) {
                // 1. Create footer_config table with all fields
                $db->exec("
                    CREATE TABLE IF NOT EXISTS footer_config (
                        id INT UNSIGNED NOT NULL DEFAULT 1,
                        -- CTA Bar
                        cta_heading VARCHAR(255) NOT NULL DEFAULT 'Having Doubts ? Talk to Experts',
                        cta_subtext VARCHAR(255) NOT NULL DEFAULT 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
                        cta_btn_text VARCHAR(100) NOT NULL DEFAULT 'Book Free 1:1 Counseling',
                        cta_btn_link VARCHAR(500) NOT NULL DEFAULT '#',
                        cta_btn_phone VARCHAR(50) NOT NULL DEFAULT '',
                        -- AI Tools
                        ai_tools_heading VARCHAR(255) NOT NULL DEFAULT 'Explore AI Powered Tools',
                        ai_tools_subtext VARCHAR(255) NOT NULL DEFAULT 'Make smarter education decisions with AI-powered tools',
                        ai_tools_cards_json LONGTEXT NOT NULL DEFAULT '[]',
                        -- About SODE
                        about_logo_url VARCHAR(500) NOT NULL DEFAULT '',
                        about_title VARCHAR(255) NOT NULL DEFAULT 'About SODE™',
                        about_subtitle VARCHAR(255) NOT NULL DEFAULT '(School of Online and Distance Education)',
                        about_sode_text TEXT NOT NULL DEFAULT '',
                        -- Legal Notice
                        legal_notice_heading VARCHAR(255) NOT NULL DEFAULT 'Legal Notice',
                        legal_notice_text LONGTEXT NOT NULL DEFAULT '',
                        -- Footer Links & Copyright
                        footer_links_json LONGTEXT NOT NULL DEFAULT '[]',
                        copyright_text VARCHAR(255) NOT NULL DEFAULT '© 2026 SODE™ Counselling Services LLP',
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");

                // Add new columns if table existed from old schema (without CTA fields)
                $existing_cols = $db->query("SHOW COLUMNS FROM footer_config")->fetchAll(PDO::FETCH_COLUMN);
                $cols_map = array_flip($existing_cols);
                $alter_cols = [
                    'cta_heading'          => "VARCHAR(255) NOT NULL DEFAULT 'Having Doubts ? Talk to Experts'",
                    'cta_subtext'          => "VARCHAR(255) NOT NULL DEFAULT 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs'",
                    'cta_btn_text'         => "VARCHAR(100) NOT NULL DEFAULT 'Book Free 1:1 Counseling'",
                    'cta_btn_link'         => "VARCHAR(500) NOT NULL DEFAULT '#'",
                    'cta_btn_phone'        => "VARCHAR(50) NOT NULL DEFAULT ''",
                    'cta_btn_class'        => "VARCHAR(255) NOT NULL DEFAULT ''",
                    'cta_btn_newtab'       => "TINYINT(1) NOT NULL DEFAULT 0",
                    'ai_tools_heading'     => "VARCHAR(255) NOT NULL DEFAULT 'Explore AI Powered Tools'",
                    'ai_tools_subtext'     => "VARCHAR(255) NOT NULL DEFAULT 'Make smarter education decisions with AI-powered tools'",
                    'about_logo_url'       => "VARCHAR(500) NOT NULL DEFAULT ''",
                    'about_title'          => "VARCHAR(255) NOT NULL DEFAULT 'About SODE™'",
                    'about_subtitle'       => "VARCHAR(255) NOT NULL DEFAULT '(School of Online and Distance Education)'",
                    'legal_notice_heading' => "VARCHAR(255) NOT NULL DEFAULT 'Legal Notice'",
                    'footer_links_json'    => "LONGTEXT NOT NULL DEFAULT '[]'",
                ];
                foreach ($alter_cols as $col => $def) {
                    if (!isset($cols_map[$col])) {
                        try {
                            $db->exec("ALTER TABLE footer_config ADD COLUMN `$col` $def");
                        } catch (Exception $e) {}
                    }
                }

                // 2. Seed default row if empty
                $count = (int)$db->query("SELECT COUNT(*) FROM footer_config")->fetchColumn();
                if ($count === 0) {
                    $default_ai_tools = json_encode([
                        [
                            'icon_svg' => '<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" rx="32" fill="#E8F4FD"/><path d="M32 18C24.27 18 18 24.27 18 32s6.27 14 14 14 14-6.27 14-14S39.73 18 32 18zm0 4c2.76 0 5 2.24 5 5s-2.24 5-5 5-5-2.24-5-5 2.24-5 5-5zm0 20c-3.71 0-6.99-1.9-8.94-4.78C23.16 35.19 27.45 34 32 34s8.84 1.19 10.94 3.22C40.99 40.1 37.71 42 34 42h-2z" fill="#1565C0"/></svg>',
                            'title' => 'Suggest University',
                            'desc'  => 'Find universities that match your goals, preferences, and career plans.',
                            'btn_text' => 'Suggest Me A University →',
                            'btn_link' => '#suggest-university'
                        ],
                        [
                            'icon_svg' => '<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" rx="32" fill="#F3E8FD"/><path d="M32 20a12 12 0 1 0 0 24 12 12 0 0 0 0-24zm-2 18l-5-5 2-2 3 3 7-7 2 2-9 9z" fill="#7B1FA2"/><path d="M24 44h16v2H24z" fill="#7B1FA2"/></svg>',
                            'title' => 'Eligibility Checker',
                            'desc'  => 'Instantly check which courses and universities you\'re eligible for.',
                            'btn_text' => 'Check Eligibility →',
                            'btn_link' => '#eligibility-checker'
                        ],
                        [
                            'icon_svg' => '<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" rx="32" fill="#E8F5E9"/><path d="M20 32a12 12 0 1 1 24 0A12 12 0 0 1 20 32zm2 0a10 10 0 1 0 20 0 10 10 0 0 0-20 0z" fill="#2E7D32"/><path d="M29 27l8 5-8 5V27z" fill="#2E7D32"/></svg>',
                            'title' => 'Compare University',
                            'desc'  => 'Compare universities based on fees, accreditation, rankings, placements, and more.',
                            'btn_text' => 'Compare University →',
                            'btn_link' => '#compare-university'
                        ],
                        [
                            'icon_svg' => '<svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="64" height="64" rx="32" fill="#FCE4EC"/><path d="M20 20h24v4H20zm0 8h24v4H20zm0 8h16v4H20z" fill="#C62828"/><path d="M44 38l-8 8-4-4 2-2 2 2 6-6 2 2z" fill="#C62828"/></svg>',
                            'title' => 'Suggest Course',
                            'desc'  => 'Find the right course based on your interests, qualifications, and career goals.',
                            'btn_text' => 'Suggest Course →',
                            'btn_link' => '#suggest-course'
                        ]
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $default_footer_links = json_encode([
                        ['label' => 'About Us',       'url' => '/about-us/',       'class' => ''],
                        ['label' => 'Contact Us',     'url' => '/contact-us/',     'class' => ''],
                        ['label' => 'Disclaimer',     'url' => '#disclaimer-popup','class' => 'disclaimer-main-popup'],
                        ['label' => 'Privacy Policy', 'url' => '#privacy-popup',   'class' => 'privacy-main-popup'],
                        ['label' => 'Terms & Conditions','url' => '#terms-popup',  'class' => 'term-main-popup'],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $default_about = 'SODE™ is India\'s top educational platform, transforming the way learners engage with higher education. We make higher education easier without compromising on the quality. We help students and working professionals find the right online and distance degree programs. We simplify every step with expert guidance and personalised support.';

                    $default_legal = 'This information is provided by DistanceEducationSchool.com, operating under the registered legal entity SODE™ Counselling Services LLP (registered with the Ministry of Corporate Affairs, Government of India), with the primary objective of providing information, guidance, and counselling for UGC-DEB-approved universities and programs. We do not act as a university or an official admission authority. For official updates, direct admissions, and fee payments, please visit {official_url}.';

                    $stmt = $db->prepare("
                        INSERT INTO footer_config 
                            (id, cta_heading, cta_subtext, cta_btn_text, cta_btn_link, cta_btn_phone,
                             ai_tools_heading, ai_tools_subtext, ai_tools_cards_json,
                             about_logo_url, about_title, about_subtitle, about_sode_text,
                             legal_notice_heading, legal_notice_text,
                             footer_links_json, copyright_text)
                        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        'Having Doubts ? Talk to Experts',
                        'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
                        'Book Free 1:1 Counseling',
                        '#',
                        '',
                        'Explore AI Powered Tools',
                        'Make smarter education decisions with AI-powered tools',
                        $default_ai_tools,
                        '',
                        'About SODE™',
                        '(School of Online and Distance Education)',
                        $default_about,
                        'Legal Notice',
                        $default_legal,
                        $default_footer_links,
                        '© ' . date('Y') . ' SODE™ Counselling Services LLP'
                    ]);
                }
            },

            '2026_09_12_001_add_syllabus_json_to_course_mappings' => function(PDO $db) {
                // 1. Add syllabus_json column to university_course_mappings if not present
                $has_col = $db->query("SHOW COLUMNS FROM university_course_mappings LIKE 'syllabus_json'")->fetch();
                if (!$has_col) {
                    $db->exec("ALTER TABLE university_course_mappings ADD COLUMN syllabus_json LONGTEXT NULL AFTER total_program_fee");
                }

                // 2. Pre-seed DSU MBA Online mapping (id=1 or course MBA) with sample semester syllabus
                $sample_syllabus = json_encode([
                    [
                        'semester_title' => 'FIRST SEMESTER',
                        'subjects' => [
                            ['name' => 'Accounting for Managers', 'link' => ''],
                            ['name' => 'Marketing Management', 'link' => ''],
                            ['name' => 'Human Resource Management', 'link' => ''],
                            ['name' => 'Organisational Behaviour', 'link' => ''],
                            ['name' => 'Information Systems', 'link' => ''],
                            ['name' => 'Statistics for Managers', 'link' => ''],
                            ['name' => 'Business Economics and Policy', 'link' => ''],
                            ['name' => 'Business Communication-I', 'link' => ''],
                        ]
                    ],
                    [
                        'semester_title' => 'SECOND SEMESTER',
                        'subjects' => [
                            ['name' => 'Financial Management', 'link' => ''],
                            ['name' => 'Operations Management', 'link' => ''],
                            ['name' => 'International Business', 'link' => ''],
                            ['name' => 'Corporate Governance and Business Law', 'link' => ''],
                            ['name' => 'Essentials of Entrepreneurship', 'link' => ''],
                            ['name' => 'Business Communication-II', 'link' => ''],
                            ['name' => 'Business Research Methods', 'link' => ''],
                            ['name' => 'Introduction to Business Analytics', 'link' => ''],
                        ]
                    ],
                    [
                        'semester_title' => 'THIRD SEMESTER',
                        'subjects' => [
                            ['name' => 'Strategic Management', 'link' => ''],
                            ['name' => 'Major Elective I', 'link' => ''],
                            ['name' => 'Major Elective II', 'link' => ''],
                            ['name' => 'Major Elective III', 'link' => ''],
                            ['name' => 'Major Elective IV', 'link' => ''],
                            ['name' => 'Minor Elective I', 'link' => ''],
                            ['name' => 'Minor Elective II', 'link' => ''],
                        ]
                    ],
                    [
                        'semester_title' => 'FOURTH SEMESTER',
                        'subjects' => [
                            ['name' => 'Major Elective V', 'link' => ''],
                            ['name' => 'Minor Elective III', 'link' => ''],
                            ['name' => 'Internship', 'link' => ''],
                            ['name' => 'Project Work', 'link' => ''],
                        ]
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                // Update mapping ID 1 if syllabus_json is empty
                $check = $db->query("SELECT syllabus_json FROM university_course_mappings WHERE id = 1")->fetchColumn();
                if (empty($check)) {
                    $u_stmt = $db->prepare("UPDATE university_course_mappings SET syllabus_json = ? WHERE id = 1");
                    $u_stmt->execute([$sample_syllabus]);
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
