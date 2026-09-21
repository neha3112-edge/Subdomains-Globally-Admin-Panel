<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$can_edit = user_can('update') || user_can('write');

$page_title = 'Site Favicon (Global)';
$page_subtitle = 'Configure the universal website favicon that automatically applies and overrides across all university subdomains';
$active_page_key = 'favicon';

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

// Self-healing check: Ensure Site Favicon is registered in sidebar_items
try {
    $sb_chk = $db->query("SELECT id FROM sidebar_items WHERE active_page_key = 'favicon' OR page_route LIKE '%favicon.php'")->fetch();
    if (!$sb_chk) {
        $icon_svg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="4"></circle></svg>';
        $stmt_sb = $db->prepare("
            INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1)
        ");
        $stmt_sb->execute(['Site Favicon', 'modules/settings/favicon.php', 18, 'favicon', 'settings', 'SETTINGS', $icon_svg]);
    }
} catch (Exception $e) {}

// Handle Form Submission (Save / Update / Clear Favicon)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$can_edit) {
        set_flash_message('Access Denied: You do not have permission to modify favicon settings.', 'error');
        redirect(BASE_URL . '/modules/settings/favicon.php');
    }

    $action = $_POST['action'] ?? 'save';

    if ($action === 'clear') {
        $favicon_url = '';
    } else {
        $favicon_url = trim($_POST['favicon_url'] ?? '');
    }

    try {
        // 1. Update in global_settings table
        $stmt_setting = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group, description)
            VALUES ('site_favicon_url', ?, 'branding', 'Global Favicon URL for all university subdomains')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $stmt_setting->execute([$favicon_url]);

        // 2. Sync to global_keys table as $FAVICON_URL$ for shortcode and API resolution
        $stmt_key = $db->prepare("
            INSERT INTO global_keys (key_code, key_value, description, is_active)
            VALUES ('$FAVICON_URL$', ?, 'Global Favicon URL for all subdomains', 1)
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), is_active = 1
        ");
        $stmt_key->execute([$favicon_url]);

        // Log Activity
        if (function_exists('log_activity')) {
            log_activity('UPDATE', 'settings', ($favicon_url ? "Updated global favicon URL to: {$favicon_url}" : "Cleared global favicon URL"), [
                'item_type' => 'Setting',
                'item_title' => 'Global Favicon',
                'favicon_url' => $favicon_url
            ]);
        }

        // 3. Flush cache on all active subdomains so changes reflect instantly
        if (function_exists('sode_bust_all_subdomain_caches')) {
            sode_bust_all_subdomain_caches($db);
        }

        if ($action === 'clear') {
            set_flash_message('Global favicon has been cleared successfully.', 'success');
        } else {
            set_flash_message('Global Favicon updated successfully! Changes are pushed globally across all subdomains.', 'success');
        }

        redirect(BASE_URL . '/modules/settings/favicon.php');
    } catch (PDOException $e) {
        set_flash_message('Failed to update favicon: ' . $e->getMessage(), 'error');
    }
}

// Fetch current favicon URL
$current_favicon = '';

// Check global_settings first
try {
    $row = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'site_favicon_url' LIMIT 1")->fetch();
    if ($row && !empty($row['setting_value'])) {
        $current_favicon = $row['setting_value'];
    }
} catch (Exception $e) {}

// Fallback to global_keys
if (empty($current_favicon)) {
    try {
        $row_k = $db->query("SELECT key_value FROM global_keys WHERE key_code IN ('\$FAVICON_URL\$', 'FAVICON_URL', '\$FAVICON\$') AND is_active = 1 LIMIT 1")->fetch();
        if ($row_k && !empty($row_k['key_value'])) {
            $current_favicon = $row_k['key_value'];
        }
    } catch (Exception $e) {}
}

// Fallback to site_logo_url if empty
if (empty($current_favicon)) {
    try {
        $row_l = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'site_logo_url' LIMIT 1")->fetch();
        if ($row_l && !empty($row_l['setting_value'])) {
            $current_favicon = $row_l['setting_value'];
        }
    } catch (Exception $e) {}
}

// Default fallback recommendation
$default_preview = !empty($current_favicon) ? (function_exists('get_asset_url') ? get_asset_url($current_favicon) : $current_favicon) : 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="font-size:20px; font-weight:700; margin:0 0 4px 0; color:var(--text-main, #fff);">Global Website Favicon</h2>
        <p style="font-size:13.5px; color:var(--text-dim, #94a3b8); margin:0;">
            This favicon is globally centralized. It will <strong>automatically overwrite</strong> any favicon configured in WordPress or theme options on every subdomain.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:8px 16px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            <span>All Global Keys</span>
        </a>
    </div>
</div>

<div style="display:grid; grid-template-columns: 1.2fr 0.8fr; gap:24px; align-items:start;">

    <!-- Left Column: Favicon Form & Settings -->
    <div>
        <form method="POST" action="" id="favicon-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" id="form-action" value="save">

            <div class="admin-card" style="padding:24px; border-radius:12px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; border-bottom:1px solid var(--border-color, #334155); padding-bottom:14px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#10b981; box-shadow:0 0 8px rgba(16,185,129,0.5);"></div>
                        <h3 style="font-size:16px; font-weight:600; margin:0; color:var(--text-main, #fff);">Central Favicon Configuration</h3>
                    </div>
                    <?php if (!empty($current_favicon)): ?>
                        <span class="badge badge-success" style="font-size:11px; padding:3px 8px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3); border-radius:6px;">Active Globally</span>
                    <?php else: ?>
                        <span class="badge badge-warning" style="font-size:11px; padding:3px 8px; background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); border-radius:6px;">Not Configured</span>
                    <?php endif; ?>
                </div>

                <!-- Input Field with Media Picker Button -->
                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="font-weight:600; font-size:13.5px; margin-bottom:8px; display:block;">
                        Favicon File URL (.ico, .png, .svg, .webp) *
                    </label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="text" 
                               name="favicon_url" 
                               id="favicon_url" 
                               class="form-control" 
                               value="<?php echo htmlspecialchars($current_favicon); ?>" 
                               placeholder="https://example.com/uploads/favicon.png or select from Media Library" 
                               style="font-family:monospace; font-size:13px; padding:10px 14px;"
                               required>
                        <button type="button" 
                                class="btn-primary media-picker-btn" 
                                data-media-target="favicon_url" 
                                data-media-preview="media-preview-container"
                                data-media-type="image"
                                style="white-space:nowrap; padding:10px 16px; display:inline-flex; align-items:center; gap:6px; font-size:13px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            <span>Browse / Upload</span>
                        </button>
                    </div>
                    <span style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:6px; display:block;">
                        💡 You can select an existing image from the Media Library, upload a new square <strong>PNG / ICO / SVG</strong>, or paste any direct CDN URL.
                    </span>
                </div>

                <!-- Hidden preview container for media_picker.js compatibility -->
                <div id="media-preview-container" style="display:none;"></div>

                <!-- Action Buttons -->
                <div style="display:flex; gap:12px; align-items:center; margin-top:24px; padding-top:16px; border-top:1px solid var(--border-color, #334155);">
                    <button type="submit" class="btn-primary" style="padding:10px 24px; font-weight:600; font-size:13.5px; display:inline-flex; align-items:center; gap:8px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>Save & Push to All Subdomains</span>
                    </button>

                    <?php if (!empty($current_favicon)): ?>
                        <button type="button" class="btn-secondary" onclick="clearFavicon()" style="padding:10px 18px; font-size:13px; color:#ef4444; border-color:rgba(239,68,68,0.3);">
                            <span>Remove / Clear</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- Feature Highlights & Technical Details -->
        <div class="admin-card" style="padding:20px; border-radius:12px; background:rgba(30,41,59,0.5);">
            <h4 style="font-size:14px; font-weight:600; margin:0 0 12px 0; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#38bdf8" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                How Central Favicon Override Works
            </h4>
            <ul style="font-size:12.8px; color:var(--text-dim, #94a3b8); margin:0; padding-left:20px; line-height:1.7;">
                <li><strong>Instant Subdomain Sync:</strong> When updated here, the favicon is immediately pushed to all university subdomains via <code>sode-universal-client.php</code>.</li>
                <li><strong>WordPress Site Icon Override:</strong> Automatically hooks into WordPress <code>get_site_icon_url</code> and <code>site_icon_meta_tags</code> filters to replace default icons.</li>
                <li><strong>Cache-Bypassing Engine:</strong> Bypasses WP Rocket, LiteSpeed, Cloudflare, and browser caching using dynamic client injection.</li>
                <li><strong>Full Resolution Support:</strong> Outputs standard <code>32x32</code>, <code>192x192</code>, Apple Touch Icon, and Windows Tile icons.</li>
            </ul>
        </div>
    </div>

    <!-- Right Column: Live Mockup & Preview Matrix -->
    <div>
        <div class="admin-card" style="padding:22px; border-radius:12px;">
            <h3 style="font-size:15px; font-weight:600; margin:0 0 16px 0; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                Live Browser Tab Mockup
            </h3>

            <!-- Chrome / Browser Tab Mockup -->
            <div style="background:#1e293b; border:1px solid var(--border-color, #334155); border-radius:8px; overflow:hidden; margin-bottom:20px; box-shadow:0 8px 24px rgba(0,0,0,0.3);">
                <!-- Mockup Browser Bar -->
                <div style="background:#0f172a; padding:8px 12px; display:flex; align-items:center; gap:8px; border-bottom:1px solid #1e293b;">
                    <div style="display:flex; gap:6px;">
                        <span style="width:10px; height:10px; border-radius:50%; background:#ef4444; display:inline-block;"></span>
                        <span style="width:10px; height:10px; border-radius:50%; background:#f59e0b; display:inline-block;"></span>
                        <span style="width:10px; height:10px; border-radius:50%; background:#10b981; display:inline-block;"></span>
                    </div>
                    <!-- Tab -->
                    <div style="background:#1e293b; border-radius:6px 6px 0 0; padding:6px 14px; display:flex; align-items:center; gap:8px; max-width:240px; margin-left:12px; border-top:2px solid #3b82f6;">
                        <img id="tab-mockup-icon" 
                             src="<?php echo htmlspecialchars($default_preview); ?>" 
                             alt="Favicon Preview" 
                             style="width:16px; height:16px; object-fit:contain; border-radius:2px;"
                             onerror="this.src='https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png'">
                        <span style="font-size:12px; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:500;">
                            SODE Online & Distance...
                        </span>
                        <span style="font-size:10px; color:#94a3b8; margin-left:auto; cursor:default;">&times;</span>
                    </div>
                </div>
                <!-- Mockup Address Bar -->
                <div style="padding:10px 14px; background:#1e293b; display:flex; align-items:center; gap:8px;">
                    <div style="flex:1; background:#0f172a; border-radius:6px; padding:6px 12px; font-size:11.5px; color:#94a3b8; display:flex; align-items:center; gap:6px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span style="color:#e2e8f0;">https://dsu.distanceeducationschool.com</span>
                    </div>
                </div>
            </div>

            <!-- Resolution Matrix -->
            <h4 style="font-size:13px; font-weight:600; margin:0 0 12px 0; color:var(--text-main, #fff);">Scale & Resolution Check</h4>
            <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; text-align:center;">
                <!-- 16px -->
                <div style="background:#0f172a; border:1px solid var(--border-color, #334155); border-radius:8px; padding:12px 6px;">
                    <div style="height:48px; display:flex; align-items:center; justify-content:center;">
                        <img id="res-16-icon" src="<?php echo htmlspecialchars($default_preview); ?>" alt="16x16" style="width:16px; height:16px; object-fit:contain;">
                    </div>
                    <div style="font-size:11px; color:#94a3b8; margin-top:4px;">16 &times; 16</div>
                    <div style="font-size:9.5px; color:#64748b;">Tab Favicon</div>
                </div>

                <!-- 32px -->
                <div style="background:#0f172a; border:1px solid var(--border-color, #334155); border-radius:8px; padding:12px 6px;">
                    <div style="height:48px; display:flex; align-items:center; justify-content:center;">
                        <img id="res-32-icon" src="<?php echo htmlspecialchars($default_preview); ?>" alt="32x32" style="width:32px; height:32px; object-fit:contain;">
                    </div>
                    <div style="font-size:11px; color:#94a3b8; margin-top:4px;">32 &times; 32</div>
                    <div style="font-size:9.5px; color:#64748b;">Retina Display</div>
                </div>

                <!-- 48px -->
                <div style="background:#0f172a; border:1px solid var(--border-color, #334155); border-radius:8px; padding:12px 6px;">
                    <div style="height:48px; display:flex; align-items:center; justify-content:center;">
                        <img id="res-48-icon" src="<?php echo htmlspecialchars($default_preview); ?>" alt="48x48" style="width:40px; height:40px; object-fit:contain;">
                    </div>
                    <div style="font-size:11px; color:#94a3b8; margin-top:4px;">48 &times; 48</div>
                    <div style="font-size:9.5px; color:#64748b;">Desktop Icon</div>
                </div>

                <!-- 192px (Scaled) -->
                <div style="background:#0f172a; border:1px solid var(--border-color, #334155); border-radius:8px; padding:12px 6px;">
                    <div style="height:48px; display:flex; align-items:center; justify-content:center;">
                        <img id="res-192-icon" src="<?php echo htmlspecialchars($default_preview); ?>" alt="192x192" style="width:44px; height:44px; object-fit:contain; border-radius:8px;">
                    </div>
                    <div style="font-size:11px; color:#94a3b8; margin-top:4px;">192 &times; 192</div>
                    <div style="font-size:9.5px; color:#64748b;">Apple / Android</div>
                </div>
            </div>

            <!-- Best Practice Recommendation -->
            <div style="margin-top:20px; padding:12px; background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:8px;">
                <div style="font-size:12px; color:#93c5fd; font-weight:600; margin-bottom:4px;">⭐ Recommendation for Crisp Display:</div>
                <div style="font-size:11.5px; color:#cbd5e1; line-height:1.5;">
                    Upload a high-resolution <strong>512&times;512 px PNG</strong> with transparent background. The system will automatically serve it for retina screens, mobile bookmarks, and browser tabs.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('favicon_url');
    const tabIcon = document.getElementById('tab-mockup-icon');
    const res16 = document.getElementById('res-16-icon');
    const res32 = document.getElementById('res-32-icon');
    const res48 = document.getElementById('res-48-icon');
    const res192 = document.getElementById('res-192-icon');

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

    function updatePreviews(url) {
        const fallback = 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';
        const finalUrl = resolveAssetUrl(url) || fallback;

        [tabIcon, res16, res32, res48, res192].forEach(img => {
            if (img) {
                img.src = finalUrl;
            }
        });
    }

    if (input) {
        input.addEventListener('input', function() {
            updatePreviews(this.value);
        });
        input.addEventListener('change', function() {
            updatePreviews(this.value);
        });
    }

    // Initial load trigger
    if (input && input.value) {
        updatePreviews(input.value);
    }

    window.clearFavicon = function() {
        if (confirm('Are you sure you want to remove the global favicon?')) {
            document.getElementById('favicon_url').value = '';
            document.getElementById('form-action').value = 'clear';
            document.getElementById('favicon-form').submit();
        }
    };
});
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
