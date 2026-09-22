<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$can_edit = user_can('update') || user_can('write');

$page_title = 'Site & Brand Logo (Global)';
$page_subtitle = 'Manage global website brand logos for headers/footers across subdomains and custom logo for Admin Panel';
$active_page_key = 'logo_settings';

$db = get_db_connection();

// Ensure global_settings table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS global_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value LONGTEXT NULL,
            setting_group VARCHAR(50) DEFAULT 'general',
            description VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

// Self-healing check: Ensure Site Logo is registered in sidebar_items
try {
    $sb_chk = $db->query("SELECT id FROM sidebar_items WHERE active_page_key = 'logo_settings' OR page_route LIKE '%settings/logo.php%'")->fetch();
    if (!$sb_chk) {
        $icon_svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>';
        $stmt_sb = $db->prepare("
            INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)
        ");
        $stmt_sb->execute(['Site & Brand Logo', 'modules/settings/logo.php', 17, 'logo_settings', 'settings', 'SETTINGS', $icon_svg]);
    }
} catch (Exception $e) {}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$can_edit) {
        set_flash_message('Access Denied: You do not have permission to modify logo settings.', 'error');
        redirect(BASE_URL . '/modules/settings/logo.php');
    }

    $site_logo_url      = trim($_POST['site_logo_url'] ?? '');
    $site_logo_dark_url = trim($_POST['site_logo_dark_url'] ?? '');
    $admin_logo_url     = trim($_POST['admin_logo_url'] ?? '');
    $site_logo_alt      = trim($_POST['site_logo_alt'] ?? 'SODE - School of Online & Distance Education');

    try {
        // 1. Update in global_settings table
        $stmt_setting = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group, description)
            VALUES (?, ?, 'branding', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        $stmt_setting->execute(['site_logo_url', $site_logo_url, 'Global Primary Website Logo']);
        $stmt_setting->execute(['site_logo_dark_url', $site_logo_dark_url, 'Global Alternate / Dark Mode Website Logo']);
        $stmt_setting->execute(['admin_logo_url', $admin_logo_url, 'Admin Panel Custom Brand Logo']);
        $stmt_setting->execute(['site_logo_alt', $site_logo_alt, 'Default Alt text for Site Logo']);

        // 2. Sync to global_keys table for instant shortcode & client API lookup
        $stmt_key = $db->prepare("
            INSERT INTO global_keys (key_code, key_value, description, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), is_active = 1
        ");

        $stmt_key->execute(['$SITE_LOGO$', $site_logo_url, 'Global Primary Website Logo URL']);
        $stmt_key->execute(['$LOGO_URL$', $site_logo_url, 'Global Primary Logo URL (Alternative)']);
        $stmt_key->execute(['$SITE_LOGO_DARK$', $site_logo_dark_url, 'Global Dark / Alternate Logo URL']);

        // Log Activity
        if (function_exists('log_activity')) {
            log_activity('UPDATE', 'settings', "Updated Global Site & Brand Logos", [
                'item_type' => 'Setting',
                'item_title' => 'Global Logos',
                'site_logo_url' => $site_logo_url,
                'admin_logo_url' => $admin_logo_url
            ]);
        }

        // 3. Flush cache on all active subdomains so logo reflects immediately
        if (function_exists('sode_bust_all_subdomain_caches')) {
            sode_bust_all_subdomain_caches($db);
        }

        set_flash_message('Site & Brand Logo updated successfully! Changes are pushed globally across all subdomains.', 'success');
        redirect(BASE_URL . '/modules/settings/logo.php');
    } catch (PDOException $e) {
        set_flash_message('Failed to update logo settings: ' . $e->getMessage(), 'error');
    }
}

// Fetch current settings
$branding = get_site_branding();
$site_logo_url      = $branding['site_logo_url'];
$site_logo_dark_url = $branding['site_logo_dark_url'];
$admin_logo_url     = $branding['admin_logo_url'];

// Also fetch alt text
$site_logo_alt = 'SODE - School of Online & Distance Education';
try {
    $alt_row = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'site_logo_alt' LIMIT 1")->fetch();
    if ($alt_row && !empty($alt_row['setting_value'])) {
        $site_logo_alt = $alt_row['setting_value'];
    }
} catch (Exception $e) {}

$default_site_preview = !empty($site_logo_url) ? (function_exists('get_asset_url') ? get_asset_url($site_logo_url) : $site_logo_url) : 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';
$default_dark_preview = !empty($site_logo_dark_url) ? (function_exists('get_asset_url') ? get_asset_url($site_logo_dark_url) : $site_logo_dark_url) : $default_site_preview;
$default_admin_preview = !empty($admin_logo_url) ? (function_exists('get_asset_url') ? get_asset_url($admin_logo_url) : $admin_logo_url) : $default_site_preview;

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="font-size:20px; font-weight:700; margin:0 0 4px 0; color:var(--text-main, #fff);">Global Website & Admin Logo</h2>
        <p style="font-size:13.5px; color:var(--text-dim, #94a3b8); margin:0;">
            Manage the centralized brand logos used across all university subdomains and the Admin Panel.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:8px 16px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            <span>All Global Keys</span>
        </a>
    </div>
</div>

<form method="POST" action="" id="logo-settings-form">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 1.2fr 0.8fr; gap:24px; align-items:start;">

        <!-- Left Column: Inputs & Form Controls -->
        <div>
            <!-- 1. Universal Primary Website Logo -->
            <div class="admin-card" style="padding:24px; border-radius:12px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid var(--border-color, #334155); padding-bottom:14px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#3b82f6; box-shadow:0 0 8px rgba(59,130,246,0.5);"></div>
                        <h3 style="font-size:16px; font-weight:600; margin:0; color:var(--text-main, #fff);">1. Universal Website Primary Logo</h3>
                    </div>
                    <?php if (!empty($site_logo_url)): ?>
                        <span class="badge badge-success" style="font-size:11px; padding:3px 8px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); border-radius:6px;">Active</span>
                    <?php else: ?>
                        <span class="badge badge-warning" style="font-size:11px; padding:3px 8px; background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); border-radius:6px;">Not Set</span>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="font-weight:600; font-size:13.5px; margin-bottom:8px; display:block;">
                        Primary Logo URL (PNG / SVG / WebP) *
                    </label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" 
                               name="site_logo_url" 
                               id="site_logo_url" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($site_logo_url); ?>" 
                               placeholder="https://example.com/uploads/logo.png or select from Media Library" 
                               style="font-family:monospace; font-size:13px; padding:10px 14px;"
                               required>
                        <button type="button" 
                                class="btn-primary media-picker-btn" 
                                data-media-target="site_logo_url" 
                                data-media-preview="site_logo_preview_hidden"
                                data-media-type="image"
                                style="white-space:nowrap; padding:10px 16px; display:inline-flex; align-items:center; gap:6px; font-size:13px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            <span>Browse / Upload</span>
                        </button>
                    </div>
                    <span style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:6px; display:block;">
                        Available as <code>$SITE_LOGO$</code> and <code>$LOGO_URL$</code> across all subdomains. Transparent PNG or SVG recommended.
                    </span>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                        Default Logo Alt Text
                    </label>
                    <input type="text" 
                           name="site_logo_alt" 
                           id="site_logo_alt" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($site_logo_alt); ?>" 
                           placeholder="SODE - School of Online & Distance Education" 
                           style="font-size:13px;">
                </div>
            </div>

            <!-- 2. Dark / Alternate Logo -->
            <div class="admin-card" style="padding:24px; border-radius:12px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid var(--border-color, #334155); padding-bottom:14px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#8b5cf6;"></div>
                        <h3 style="font-size:16px; font-weight:600; margin:0; color:var(--text-main, #fff);">2. Dark / Inverted Website Logo (Optional)</h3>
                    </div>
                    <span style="font-size:11.5px; color:var(--text-dim, #94a3b8);">For Dark Backgrounds & Banners</span>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-weight:600; font-size:13.5px; margin-bottom:8px; display:block;">
                        Dark Logo URL
                    </label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" 
                               name="site_logo_dark_url" 
                               id="site_logo_dark_url" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($site_logo_dark_url); ?>" 
                               placeholder="Optional white/bright version for dark headers" 
                               style="font-family:monospace; font-size:13px; padding:10px 14px;">
                        <button type="button" 
                                class="btn-primary media-picker-btn" 
                                data-media-target="site_logo_dark_url" 
                                data-media-preview="site_logo_dark_preview_hidden"
                                data-media-type="image"
                                style="white-space:nowrap; padding:10px 16px; display:inline-flex; align-items:center; gap:6px; font-size:13px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            <span>Browse</span>
                        </button>
                    </div>
                    <span style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:6px; display:block;">
                        Available as <code>$SITE_LOGO_DARK$</code> in templates and shortcodes.
                    </span>
                </div>
            </div>

            <!-- 3. Admin Panel Brand Logo -->
            <div class="admin-card" style="padding:24px; border-radius:12px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid var(--border-color, #334155); padding-bottom:14px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#ec4899;"></div>
                        <h3 style="font-size:16px; font-weight:600; margin:0; color:var(--text-main, #fff);">3. Admin Panel Brand Logo</h3>
                    </div>
                    <span style="font-size:11.5px; color:var(--text-dim, #94a3b8);">Sidebar & Login Screen</span>
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label" style="font-weight:600; font-size:13.5px; margin-bottom:8px; display:block;">
                        Admin Logo URL (Overrides Default Cap Icon)
                    </label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" 
                               name="admin_logo_url" 
                               id="admin_logo_url" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($admin_logo_url); ?>" 
                               placeholder="Leave empty to use Primary Website Logo" 
                               style="font-family:monospace; font-size:13px; padding:10px 14px;">
                        <button type="button" 
                                class="btn-primary media-picker-btn" 
                                data-media-target="admin_logo_url" 
                                data-media-preview="admin_logo_preview_hidden"
                                data-media-type="image"
                                style="white-space:nowrap; padding:10px 16px; display:inline-flex; align-items:center; gap:6px; font-size:13px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            <span>Browse</span>
                        </button>
                    </div>
                    <span style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:6px; display:block;">
                        Displays in the Admin Panel left sidebar header and on the login authentication screen.
                    </span>
                </div>
            </div>

            <!-- Hidden preview helper elements for media_picker.js -->
            <div id="site_logo_preview_hidden" style="display:none;"></div>
            <div id="site_logo_dark_preview_hidden" style="display:none;"></div>
            <div id="admin_logo_preview_hidden" style="display:none;"></div>

            <!-- Save Action Button -->
            <div style="display:flex; gap:12px; align-items:center; margin-top:10px;">
                <button type="submit" class="btn-primary" style="padding:12px 28px; font-weight:600; font-size:14px; display:inline-flex; align-items:center; gap:8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    <span>Save & Update Globally</span>
                </button>
            </div>
        </div>

        <!-- Right Column: Live Previews -->
        <div>
            <!-- Live Website Header Mockup Preview -->
            <div class="admin-card" style="padding:22px; border-radius:12px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <h3 style="font-size:15px; font-weight:600; margin:0; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        Website Header Live Preview
                    </h3>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn-sm action-btn active" id="preview-bg-light" style="padding:3px 8px; font-size:11px;">Light</button>
                        <button type="button" class="btn-sm action-btn" id="preview-bg-dark" style="padding:3px 8px; font-size:11px;">Dark</button>
                    </div>
                </div>

                <!-- Preview Canvas -->
                <div id="website-header-preview-box" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; min-height:80px; box-shadow:0 4px 14px rgba(0,0,0,0.1); transition:background 0.2s;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <img id="live-site-logo-img" 
                             src="<?php echo htmlspecialchars($default_site_preview); ?>" 
                             alt="Site Logo Preview" 
                             style="max-height:48px; max-width:180px; object-fit:contain;"
                             onerror="this.src='https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png'">
                    </div>
                    <div style="display:flex; gap:14px; font-size:12.5px; font-weight:500; color:#334155;" id="mock-nav-links">
                        <span>Home</span>
                        <span>Courses</span>
                        <span>Fee Structure</span>
                        <span style="background:#2563eb; color:#fff; padding:4px 10px; border-radius:4px; font-size:11.5px;">Apply Now</span>
                    </div>
                </div>
                <div style="font-size:11.5px; color:var(--text-dim, #94a3b8); margin-top:8px; text-align:center;">
                    Live appearance on Subdomain Navigation Bars
                </div>
            </div>

            <!-- Live Admin Sidebar Brand Preview -->
            <div class="admin-card" style="padding:22px; border-radius:12px; margin-bottom:20px;">
                <h3 style="font-size:15px; font-weight:600; margin:0 0 14px 0; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h18v18H3zM9 3v18"></path></svg>
                    Admin Panel Sidebar Brand Preview
                </h3>

                <div style="background:#0f172a; border:1px solid #334155; border-radius:8px; padding:14px 18px; display:flex; align-items:center; gap:12px;">
                    <div id="admin-sidebar-preview-logo" style="display:flex; align-items:center;">
                        <img id="live-admin-logo-img" 
                             src="<?php echo htmlspecialchars($default_admin_preview); ?>" 
                             alt="Admin Logo Preview" 
                             style="max-height:36px; max-width:140px; object-fit:contain;"
                             onerror="this.src='https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png'">
                    </div>
                    <span style="font-size:14px; font-weight:700; color:#fff; letter-spacing:0.5px;"><?php echo htmlspecialchars(APP_NAME); ?></span>
                </div>
                <div style="font-size:11.5px; color:var(--text-dim, #94a3b8); margin-top:8px; text-align:center;">
                    Live appearance on the Admin Panel Left Sidebar Header
                </div>
            </div>

            <!-- Best Practice Guidelines -->
            <div class="admin-card" style="padding:20px; border-radius:12px; background:rgba(59,130,246,0.06); border:1px solid rgba(59,130,246,0.2);">
                <div style="font-size:13px; color:#93c5fd; font-weight:600; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    Best Practices for Crystal Clear Logos:
                </div>
                <ul style="font-size:12px; color:#cbd5e1; margin:0; padding-left:18px; line-height:1.6;">
                    <li>Use <strong>SVG</strong> format for razor-sharp vector clarity on 4K & mobile screens.</li>
                    <li>If using <strong>PNG</strong>, export at <strong>2x / 3x resolution</strong> (e.g., 600&times;160 px) with a transparent background.</li>
                    <li>Ensure adequate contrast for both light and dark header themes.</li>
                </ul>
            </div>
        </div>

    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const siteLogoInput = document.getElementById('site_logo_url');
    const siteDarkLogoInput = document.getElementById('site_logo_dark_url');
    const adminLogoInput = document.getElementById('admin_logo_url');

    const liveSiteImg = document.getElementById('live-site-logo-img');
    const liveAdminImg = document.getElementById('live-admin-logo-img');

    const btnLight = document.getElementById('preview-bg-light');
    const btnDark = document.getElementById('preview-bg-dark');
    const headerBox = document.getElementById('website-header-preview-box');
    const navLinks = document.getElementById('mock-nav-links');

    let currentMode = 'light';

    function resolveAssetUrl(url) {
        if (!url) return '';
        url = url.trim();
        if (/^(https?:|\/\/|data:)/i.test(url)) {
            return url;
        }
        const appBase = window.BASE_URL || (window.location.origin + window.location.pathname.replace(/\/modules\/.*|\/dashboard\.php|\/login\.php|\/index\.php/, ''));
        const cleanBase = appBase.replace(/\/+$/, '');
        const cleanUrl = url.replace(/^\/+/, '');
        return cleanBase + '/' + cleanUrl;
    }

    function refreshPreviews() {
        const rawSite = siteLogoInput ? siteLogoInput.value : '';
        const rawDark = siteDarkLogoInput ? siteDarkLogoInput.value : '';
        const rawAdmin = adminLogoInput ? adminLogoInput.value : '';

        const siteUrl = resolveAssetUrl(rawSite);
        const darkUrl = resolveAssetUrl(rawDark);
        const adminUrl = resolveAssetUrl(rawAdmin);
        const fallback = 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';

        // Site Preview
        if (currentMode === 'dark' && darkUrl) {
            liveSiteImg.src = darkUrl;
        } else {
            liveSiteImg.src = siteUrl || fallback;
        }

        // Admin Preview
        liveAdminImg.src = adminUrl || siteUrl || fallback;
    }

    [siteLogoInput, siteDarkLogoInput, adminLogoInput].forEach(inp => {
        if (inp) {
            inp.addEventListener('input', refreshPreviews);
            inp.addEventListener('change', refreshPreviews);
        }
    });

    if (btnLight && btnDark && headerBox) {
        btnLight.addEventListener('click', function() {
            currentMode = 'light';
            btnLight.classList.add('active');
            btnDark.classList.remove('active');
            headerBox.style.background = '#ffffff';
            headerBox.style.borderColor = '#cbd5e1';
            if (navLinks) navLinks.style.color = '#334155';
            refreshPreviews();
        });

        btnDark.addEventListener('click', function() {
            currentMode = 'dark';
            btnDark.classList.add('active');
            btnLight.classList.remove('active');
            headerBox.style.background = '#0f172a';
            headerBox.style.borderColor = '#334155';
            if (navLinks) navLinks.style.color = '#e2e8f0';
            refreshPreviews();
        });
    }

    // Initial trigger
    refreshPreviews();
});
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
