-- ====================================================================
-- UNIVERSAL ADMIN PANEL DATABASE SCHEMA (admin_glob_db)
-- Full relational schema with RBAC, Masters, Mappings, and Content
-- ====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. TEAMS TABLE
CREATE TABLE IF NOT EXISTS `teams` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ROLES TABLE
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. USERS TABLE
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `username` VARCHAR(100) NULL UNIQUE,
  `country_code` VARCHAR(10) DEFAULT '+91',
  `phone` VARCHAR(25) NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `team_id` INT UNSIGNED NULL,
  `role_id` INT UNSIGNED NULL,
  `is_superadmin` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_user_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. SIDEBAR ITEMS TABLE
CREATE TABLE IF NOT EXISTS `sidebar_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `display_name` VARCHAR(100) NOT NULL,
  `page_route` VARCHAR(255) NOT NULL,
  `sort_order` INT DEFAULT 0,
  `active_page_key` VARCHAR(100) NOT NULL,
  `rbac_module_key` VARCHAR(100) NOT NULL,
  `menu_section` VARCHAR(100) DEFAULT 'Manage',
  `icon_svg` TEXT NULL,
  `is_superadmin_only` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ROLE SIDEBAR ACCESS TABLE
CREATE TABLE IF NOT EXISTS `role_sidebar_access` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `sidebar_item_id` INT UNSIGNED NOT NULL,
  UNIQUE KEY `uniq_role_sidebar` (`role_id`, `sidebar_item_id`),
  CONSTRAINT `fk_rsa_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rsa_sidebar` FOREIGN KEY (`sidebar_item_id`) REFERENCES `sidebar_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ROLE PERMISSIONS MATRIX TABLE
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `team_id` INT UNSIGNED NOT NULL,
  `can_read` TINYINT(1) DEFAULT 0,
  `can_create` TINYINT(1) DEFAULT 0,
  `can_update` TINYINT(1) DEFAULT 0,
  `can_delete` TINYINT(1) DEFAULT 0,
  `can_write` TINYINT(1) DEFAULT 0,
  `can_all` TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uniq_role_team` (`role_id`, `team_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. UNIVERSITIES MASTER TABLE
CREATE TABLE IF NOT EXISTS `universities` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(255) NOT NULL,
  `short_name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `mode` VARCHAR(50) DEFAULT 'Online & Distance',
  `location` VARCHAR(255) NULL,
  `official_url` VARCHAR(255) NULL,
  `advantage_text` VARCHAR(255) NULL,
  `logo_url` TEXT NULL,
  `desktop_banner_bg` TEXT NULL,
  `mobile_banner_bg` TEXT NULL,
  `campus_mobile_img` TEXT NULL,
  `brochure_pdf_url` TEXT NULL,
  `podcast_audio_url` TEXT NULL,
  `youtube_video_url` TEXT NULL,
  `exam_date` VARCHAR(100) NULL,
  `extended_exam_date` VARCHAR(100) NULL,
  `admission_last_date` VARCHAR(100) NULL,
  `admission_start_date` VARCHAR(100) NULL,
  `assignment_date` VARCHAR(100) NULL,
  `rating` DECIMAL(2,1) DEFAULT 4.0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. COURSES MASTER TABLE
CREATE TABLE IF NOT EXISTS `courses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(255) NOT NULL,
  `short_name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `level` ENUM('UG', 'PG', 'Diploma', 'Certificate') DEFAULT 'PG',
  `description` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. ACCREDITATIONS MASTER TABLE
CREATE TABLE IF NOT EXISTS `accreditations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(150) NOT NULL,
  `image_url` TEXT NULL,
  `description` TEXT NULL,
  `official_link` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. UNIVERSITY ACCREDITATIONS PIVOT TABLE
CREATE TABLE IF NOT EXISTS `university_accreditations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `university_id` INT UNSIGNED NOT NULL,
  `accreditation_id` INT UNSIGNED NOT NULL,
  UNIQUE KEY `uniq_uni_accred` (`university_id`, `accreditation_id`),
  CONSTRAINT `fk_ua_uni` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ua_accred` FOREIGN KEY (`accreditation_id`) REFERENCES `accreditations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. UNIVERSITY-COURSE MAPPINGS TABLE
CREATE TABLE IF NOT EXISTS `university_course_mappings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `university_id` INT UNSIGNED NOT NULL,
  `course_id` INT UNSIGNED NOT NULL,
  `course_description` TEXT NULL,
  `course_link` TEXT NULL,
  `eligibility_text` TEXT NULL,
  `one_time_processing_fee` VARCHAR(100) NULL,
  `tuition_fee` VARCHAR(100) NULL,
  `examination_fee` VARCHAR(100) NULL,
  `per_semester_fee` VARCHAR(100) NULL,
  `total_program_fee` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_uni_course` (`university_id`, `course_id`),
  CONSTRAINT `fk_ucm_uni` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ucm_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. COURSE SPECIALIZATIONS TABLE
CREATE TABLE IF NOT EXISTS `course_specializations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `mapping_id` INT UNSIGNED NOT NULL,
  `specialization_name` VARCHAR(255) NOT NULL,
  `fees_per_sem` VARCHAR(100) NULL,
  `duration` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cs_mapping` FOREIGN KEY (`mapping_id`) REFERENCES `university_course_mappings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. JOB ROLES & SALARIES TABLE
CREATE TABLE IF NOT EXISTS `job_roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT UNSIGNED NOT NULL,
  `role_name` VARCHAR(255) NOT NULL,
  `role_link` TEXT NULL,
  `role_description` TEXT NULL,
  `salary_range_india` VARCHAR(100) NOT NULL,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_jr_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. UNIVERSITY FORM CONFIGURATIONS TABLE
CREATE TABLE IF NOT EXISTS `university_form_configs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `university_id` INT UNSIGNED NOT NULL UNIQUE,
  `allowed_courses_json` LONGTEXT NULL,
  `source` VARCHAR(100) DEFAULT 'MISC',
  `default_utm_source` VARCHAR(100) DEFAULT 'Organic',
  `default_utm_medium` VARCHAR(100) DEFAULT 'Direct',
  `default_utm_campaign` VARCHAR(100) DEFAULT 'Universal',
  `gallabox_source` VARCHAR(100) DEFAULT 'MISC',
  `brevo_source` VARCHAR(100) DEFAULT 'MISC',
  `brevo_list_id` INT DEFAULT 124,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ufc_uni` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. GLOBAL SETTINGS TABLE (CRM, Brevo, Gallabox Global API Config)
CREATE TABLE IF NOT EXISTS `global_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` LONGTEXT NULL,
  `setting_group` VARCHAR(50) DEFAULT 'general',
  `description` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. GLOBAL KEYS TABLE (Dynamic $KEY$ engine)
CREATE TABLE IF NOT EXISTS `global_keys` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key_code` VARCHAR(100) NOT NULL UNIQUE,
  `key_value` LONGTEXT NOT NULL,
  `description` VARCHAR(255) NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. ADMISSION PROCESS STEPS TABLE
CREATE TABLE IF NOT EXISTS `admission_process_steps` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `university_id` INT UNSIGNED NULL,
  `step_number` TINYINT UNSIGNED NOT NULL,
  `color_hex` VARCHAR(20) NOT NULL DEFAULT '#3B7FD1',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `icon_svg` TEXT NULL,
  CONSTRAINT `fk_aps_uni` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. NEWS ITEMS TABLE
CREATE TABLE IF NOT EXISTS `news_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `is_global` TINYINT(1) DEFAULT 1,
  `university_id` INT UNSIGNED NULL,
  `news_text` TEXT NOT NULL,
  `news_link` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_news_uni` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. LEGAL PAGES TABLE (Disclaimer, Privacy, Terms)
CREATE TABLE IF NOT EXISTS `legal_pages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `page_type` ENUM('disclaimer', 'privacy_policy', 'terms_conditions') NOT NULL UNIQUE,
  `heading` VARCHAR(255) NOT NULL,
  `content_html` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. FOOTER CONFIGURATION TABLE
CREATE TABLE IF NOT EXISTS `footer_config` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ai_tools_cards_json` LONGTEXT NULL,
  `about_sode_text` LONGTEXT NULL,
  `legal_notice_text` LONGTEXT NULL,
  `copyright_text` VARCHAR(255) DEFAULT '© 2026 SODE™ Counseling Services LLP',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. MEDIA LIBRARY TABLE
CREATE TABLE IF NOT EXISTS `media_library` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_url` TEXT NOT NULL,
  `file_type` ENUM('image', 'audio', 'video', 'pdf', 'document', 'other') DEFAULT 'image',
  `mime_type` VARCHAR(100) NULL,
  `file_size` INT UNSIGNED DEFAULT 0,
  `uploaded_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_media_type` (`file_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ====================================================================
-- SEED INITIAL DATA
-- ====================================================================

-- Default Teams
INSERT INTO `teams` (`id`, `name`, `description`) VALUES
(1, 'Development Team', 'Who can view, edit and manage universal technical architecture'),
(2, 'Support Team', 'Who can view and assist student leads and counselling data'),
(3, 'Testing Team', 'Who can view data and test frontend shortcodes')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Default Roles
INSERT INTO `roles` (`id`, `name`) VALUES
(1, 'Superadmin'),
(2, 'Manager'),
(3, 'Editor'),
(4, 'Viewer')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- Default Superadmin User (Password: admin123)
-- Hash generated via password_hash('admin123', PASSWORD_BCRYPT)
INSERT INTO `users` (`id`, `name`, `email`, `username`, `country_code`, `phone`, `password_hash`, `team_id`, `role_id`, `is_superadmin`, `is_active`) VALUES
(1, 'Rachit', 'support@gadgetschnasoft.com', 'admin', '+91', '7065777755', '$2y$10$wT0lGzCsqbE7u6pA1jM5v.KzGqM.8V0P7pB5U8vM8.y8X1lM5yN.G', 1, 1, 1, 1)
ON DUPLICATE KEY UPDATE `email`=VALUES(`email`);

-- Default Sidebar Items
INSERT INTO `sidebar_items` (`id`, `display_name`, `page_route`, `sort_order`, `active_page_key`, `rbac_module_key`, `menu_section`, `icon_svg`, `is_superadmin_only`, `is_active`) VALUES
(1, 'Dashboard', 'dashboard.php', 1, 'dashboard', 'dashboard', 'MAIN', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"></rect><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"></rect><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\"></rect><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"></rect></svg>', 0, 1),
(2, 'Universities', 'modules/universities/index.php', 2, 'universities', 'universities', 'MANAGE', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3\"/></svg>', 0, 1),
(3, 'Courses', 'modules/courses/index.php', 3, 'courses', 'courses', 'MANAGE', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M4 19.5A2.5 2.5 0 0 1 6.5 17H20\"></path><path d=\"M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z\"></path></svg>', 0, 1),
(4, 'Course Mappings', 'modules/mappings/index.php', 4, 'mappings', 'mappings', 'MANAGE', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71\"></path><path d=\"M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71\"></path></svg>', 0, 1),
(5, 'Job Roles & Salary', 'modules/job_roles/index.php', 5, 'job_roles', 'job_roles', 'MANAGE', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"2\" y=\"7\" width=\"20\" height=\"14\" rx=\"2\" ry=\"2\"></rect><path d=\"M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16\"></path></svg>', 0, 1),
(6, 'Media Library', 'modules/media/index.php', 6, 'media', 'media', 'MANAGE', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"3\" y=\"3\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"></rect><circle cx=\"8.5\" cy=\"8.5\" r=\"1.5\"></circle><polyline points=\"21 15 16 10 5 21\"></polyline></svg>', 0, 1),
(7, 'Manage Users', 'modules/rbac/users.php', 7, 'users', 'users', 'ACCESS CONTROL', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"></path><circle cx=\"9\" cy=\"7\" r=\"4\"></circle><path d=\"M23 21v-2a4 4 0 0 0-3-3.87\"></path><path d=\"M16 3.13a4 4 0 0 1 0 7.75\"></path></svg>', 0, 1),
(8, 'Manage Teams', 'modules/rbac/teams.php', 8, 'teams', 'teams', 'ACCESS CONTROL', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"></path><circle cx=\"9\" cy=\"7\" r=\"4\"></circle></svg>', 0, 1),
(9, 'Manage Roles', 'modules/rbac/roles.php', 9, 'roles', 'roles', 'ACCESS CONTROL', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"3\" y=\"11\" width=\"18\" height=\"11\" rx=\"2\" ry=\"2\"></rect><path d=\"M7 11V7a5 5 0 0 1 10 0v4\"></path></svg>', 0, 1),
(10, 'Sidebar Manager', 'modules/rbac/sidebar_manager.php', 10, 'sidebar_manager', 'sidebar_manager', 'ACCESS CONTROL', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><line x1=\"3\" y1=\"12\" x2=\"21\" y2=\"12\"></line><line x1=\"3\" y1=\"6\" x2=\"21\" y2=\"6\"></line><line x1=\"3\" y1=\"18\" x2=\"21\" y2=\"18\"></line></svg>', 1, 1),
(11, 'Global Keys', 'modules/global_keys/index.php', 11, 'global_keys', 'global_keys', 'SETTINGS', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"3\"></circle><path d=\"M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z\"></path></svg>', 0, 1),
(12, 'Accreditations', 'modules/accreditations/index.php', 12, 'accreditations', 'accreditations', 'SETTINGS', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"8\" r=\"7\"></circle><polyline points=\"8.21 13.89 7 23 12 20 17 23 15.79 13.88\"></polyline></svg>', 0, 1),
(13, 'Admission Process', 'modules/admission_process/index.php', 13, 'admission_process', 'admission_process', 'SETTINGS', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><circle cx=\"12\" cy=\"12\" r=\"10\"></circle><polyline points=\"12 6 12 12 14 14\"></polyline></svg>', 0, 1),
(14, 'Legal Popups', 'modules/legal_pages/index.php', 14, 'legal_pages', 'legal_pages', 'SETTINGS', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"></path><polyline points=\"14 2 14 8 20 8\"></polyline></svg>', 0, 1),
(15, 'Footer & AI Tools', 'modules/footer_config/index.php', 15, 'footer_config', 'footer_config', 'SETTINGS', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z\"></path></svg>', 0, 1),
(16, 'Change Password', 'change_password.php', 16, 'change_password', 'change_password', 'SETTINGS', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M21 2l-2 2m-1.5 1.5L10 13l-4 1 1-4 7.5-7.5\"></path><circle cx=\"6\" cy=\"18\" r=\"3\"></circle></svg>', 0, 1)
ON DUPLICATE KEY UPDATE `display_name`=VALUES(`display_name`);

-- Default Courses (16 Courses)
INSERT INTO `courses` (`full_name`, `short_name`, `slug`, `level`) VALUES
('Master of Business Administration', 'MBA', 'mba', 'PG'),
('Master of Computer Applications', 'MCA', 'mca', 'PG'),
('Master of Commerce', 'M.Com', 'mcom', 'PG'),
('Master of Arts', 'MA', 'ma', 'PG'),
('Master of Science', 'M.Sc', 'msc', 'PG'),
('Master of Library & Information Science', 'MLIS', 'mlis', 'PG'),
('Master of Journalism & Mass Communication', 'MJMC', 'mjmc', 'PG'),
('Master of Social Work', 'MSW', 'msw', 'PG'),
('Bachelor of Business Administration', 'BBA', 'bba', 'UG'),
('Bachelor of Computer Applications', 'BCA', 'bca', 'UG'),
('Bachelor of Commerce', 'B.Com', 'bcom', 'UG'),
('Bachelor of Arts', 'BA', 'ba', 'UG'),
('Bachelor of Science', 'B.Sc', 'bsc', 'UG'),
('Bachelor of Journalism & Mass Communication', 'BJMC', 'bjmc', 'UG'),
('Bachelor of Library & Information Science', 'BLIS', 'blis', 'UG'),
('Bachelor of Social Work', 'BSW', 'bsw', 'UG')
ON DUPLICATE KEY UPDATE `full_name`=VALUES(`full_name`);

-- Default Global Keys
INSERT INTO `global_keys` (`key_code`, `key_value`, `description`) VALUES
('$YEAR$', '2026', 'Current active academic year'),
('$session$', 'July 2026-2027', 'Active admission session'),
('$nextyear$', '2027', 'Upcoming academic year'),
('$BANNER_TOP_TEXT$', 'Welcome to SODE™ (School of Online and Distance Education)', 'Universal top banner banner text')
ON DUPLICATE KEY UPDATE `key_value`=VALUES(`key_value`);

SET FOREIGN_KEY_CHECKS = 1;
