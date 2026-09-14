<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title = 'WhatsApp & Gallabox Settings (Global)';
$page_subtitle = 'Configure centralized WhatsApp phone number, brochure button, and Gallabox floating chat widget across all university subdomains';
$active_page_key = 'whatsapp_settings';

$db = get_db_connection();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // 1. WhatsApp Hero / Direct Settings
    $raw_phone = trim($_POST['whatsapp_number'] ?? '');
    $clean_phone = preg_replace('/[^0-9+]/', '', $raw_phone);
    if (!empty($clean_phone) && strpos($clean_phone, '+') !== 0 && strpos($clean_phone, '91') !== 0 && strlen($clean_phone) === 10) {
        $clean_phone = '+91' . $clean_phone;
    }

    $whatsapp_default_intent = trim($_POST['whatsapp_default_intent'] ?? '');
    if (empty($whatsapp_default_intent)) {
        $whatsapp_default_intent = 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure';
    }

    $whatsapp_btn_enabled = isset($_POST['whatsapp_btn_enabled']) ? '1' : '0';

    // 2. Gallabox Widget Settings
    $gallabox_widget_enabled = isset($_POST['gallabox_widget_enabled']) ? '1' : '0';
    $gallabox_wa_id = trim($_POST['gallabox_wa_id'] ?? '');
    if (empty($gallabox_wa_id)) {
        $gallabox_wa_id = $clean_phone ?: '+917065777755';
    }
    $gallabox_site_name = trim($_POST['gallabox_site_name'] ?? 'SODE ™');
    $gallabox_site_tag = trim($_POST['gallabox_site_tag'] ?? 'School of Online & Distance Education ');
    $gallabox_site_logo = trim($_POST['gallabox_site_logo'] ?? 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png');
    $gallabox_widget_position = in_array($_POST['gallabox_widget_position'] ?? '', ['LEFT', 'RIGHT']) ? $_POST['gallabox_widget_position'] : 'RIGHT';
    $gallabox_trigger_message = trim($_POST['gallabox_trigger_message'] ?? 'Get Help');
    $gallabox_welcome_message = trim($_POST['gallabox_welcome_message'] ?? "Welcome to SODE™ (School of Online & Distance Education) India's Top Reliable Portal for Free Counseling & Suggestion of UGC-DEB Approved Universities and Courses.");
    $gallabox_brand_color = trim($_POST['gallabox_brand_color'] ?? '#25D366');
    $gallabox_default_message_text = trim($_POST['gallabox_default_message_text'] ?? 'Start Your {UNIVERSITY_NAME} {MODE} Counseling with an Expert Now');
    $gallabox_reply_options = trim($_POST['gallabox_reply_options'] ?? 'Yes');
    $gallabox_brand_attribution = trim($_POST['gallabox_brand_attribution'] ?? 'I Love Gallabox');
    $gallabox_script_url = trim($_POST['gallabox_script_url'] ?? 'https://waw.gallabox.com');
    $gallabox_custom_script = trim($_POST['gallabox_custom_script'] ?? '');

    try {
        // 1. Update global_settings for WhatsApp
        $stmt_settings = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group, description)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        // WhatsApp group
        $stmt_settings->execute(['whatsapp_number', $clean_phone, 'whatsapp', 'Central WhatsApp phone number for brochure & inquiries']);
        $stmt_settings->execute(['whatsapp_default_intent', $whatsapp_default_intent, 'whatsapp', 'Global default WhatsApp message intent template']);
        $stmt_settings->execute(['whatsapp_btn_enabled', $whatsapp_btn_enabled, 'whatsapp', 'Global enable/disable toggle for WhatsApp brochure button']);

        // Gallabox group
        $stmt_settings->execute(['gallabox_widget_enabled', $gallabox_widget_enabled, 'gallabox', 'Master switch to show/hide Gallabox WhatsApp widget across subdomains']);
        $stmt_settings->execute(['gallabox_wa_id', $gallabox_wa_id, 'gallabox', 'WhatsApp phone number / waId for Gallabox widget']);
        $stmt_settings->execute(['gallabox_site_name', $gallabox_site_name, 'gallabox', 'Site Name in Gallabox widget header']);
        $stmt_settings->execute(['gallabox_site_tag', $gallabox_site_tag, 'gallabox', 'Site Tagline in Gallabox widget header']);
        $stmt_settings->execute(['gallabox_site_logo', $gallabox_site_logo, 'gallabox', 'Avatar logo URL in Gallabox widget']);
        $stmt_settings->execute(['gallabox_widget_position', $gallabox_widget_position, 'gallabox', 'Widget placement on screen (RIGHT or LEFT)']);
        $stmt_settings->execute(['gallabox_trigger_message', $gallabox_trigger_message, 'gallabox', 'Trigger bubble label']);
        $stmt_settings->execute(['gallabox_welcome_message', $gallabox_welcome_message, 'gallabox', 'Welcome message bubble']);
        $stmt_settings->execute(['gallabox_brand_color', $gallabox_brand_color, 'gallabox', 'Brand accent color for widget bubble and buttons']);
        $stmt_settings->execute(['gallabox_default_message_text', $gallabox_default_message_text, 'gallabox', 'Global fallback messageText template']);
        $stmt_settings->execute(['gallabox_reply_options', $gallabox_reply_options, 'gallabox', 'Quick reply option buttons']);
        $stmt_settings->execute(['gallabox_brand_attribution', $gallabox_brand_attribution, 'gallabox', 'Attribution brand text']);
        $stmt_settings->execute(['gallabox_script_url', $gallabox_script_url, 'gallabox', 'Gallabox CDN base URL']);
        $stmt_settings->execute(['gallabox_custom_script', $gallabox_custom_script, 'gallabox', 'Custom raw Gallabox script override']);

        // 2. Sync to global_keys table for instant client and API lookup
        $stmt_keys = $db->prepare("
            INSERT INTO global_keys (key_code, key_value, description, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), is_active = 1
        ");

        $stmt_keys->execute(['whatsapp_number', $clean_phone, 'Global WhatsApp Number for buttons and links']);
        $stmt_keys->execute(['whatsapp_default_intent', $whatsapp_default_intent, 'Global default WhatsApp brochure intent']);
        $stmt_keys->execute(['whatsapp_btn_enabled', $whatsapp_btn_enabled, 'Global WhatsApp button toggle']);
        $stmt_keys->execute(['gallabox_widget_enabled', $gallabox_widget_enabled, 'Gallabox widget toggle']);
        $stmt_keys->execute(['gallabox_default_message_text', $gallabox_default_message_text, 'Default Gallabox messageText']);

        set_flash_message('Global WhatsApp & Gallabox settings saved and synchronized successfully!', 'success');
        redirect(BASE_URL . '/modules/settings/whatsapp.php');
    } catch (PDOException $e) {
        set_flash_message('Failed to save settings: ' . $e->getMessage(), 'error');
    }
}

// Fetch current settings from global_settings
$wa_rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'whatsapp'")->fetchAll(PDO::FETCH_KEY_PAIR);
$galla_rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'gallabox'")->fetchAll(PDO::FETCH_KEY_PAIR);
$keys_rows = $db->query("SELECT key_code, key_value FROM global_keys WHERE key_code LIKE 'whatsapp%' OR key_code LIKE 'gallabox%'")->fetchAll(PDO::FETCH_KEY_PAIR);

// WhatsApp Hero Settings
$whatsapp_number = $wa_rows['whatsapp_number'] ?? $keys_rows['whatsapp_number'] ?? '+917065777755';
$whatsapp_default_intent = $wa_rows['whatsapp_default_intent'] ?? $keys_rows['whatsapp_default_intent'] ?? 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure';
$whatsapp_btn_enabled = $wa_rows['whatsapp_btn_enabled'] ?? $keys_rows['whatsapp_btn_enabled'] ?? '1';

// Gallabox Widget Settings
$gallabox_widget_enabled = $galla_rows['gallabox_widget_enabled'] ?? $keys_rows['gallabox_widget_enabled'] ?? '1';
$gallabox_wa_id = $galla_rows['gallabox_wa_id'] ?? $whatsapp_number;
$gallabox_site_name = $galla_rows['gallabox_site_name'] ?? 'SODE ™';
$gallabox_site_tag = $galla_rows['gallabox_site_tag'] ?? 'School of Online & Distance Education ';
$gallabox_site_logo = $galla_rows['gallabox_site_logo'] ?? 'https://distanceeducationschool.com/wp-content/uploads/2025/01/sode-white-favicon.png';
$gallabox_widget_position = $galla_rows['gallabox_widget_position'] ?? 'RIGHT';
$gallabox_trigger_message = $galla_rows['gallabox_trigger_message'] ?? 'Get Help';
$gallabox_welcome_message = $galla_rows['gallabox_welcome_message'] ?? "Welcome to SODE™ (School of Online & Distance Education) India's Top Reliable Portal for Free Counseling & Suggestion of UGC-DEB Approved Universities and Courses.";
$gallabox_brand_color = $galla_rows['gallabox_brand_color'] ?? '#25D366';
$gallabox_default_message_text = $galla_rows['gallabox_default_message_text'] ?? $keys_rows['gallabox_default_message_text'] ?? 'Start Your {UNIVERSITY_NAME} {MODE} Counseling with an Expert Now';
$gallabox_reply_options = $galla_rows['gallabox_reply_options'] ?? 'Yes';
$gallabox_brand_attribution = $galla_rows['gallabox_brand_attribution'] ?? 'I Love Gallabox';
$gallabox_script_url = $galla_rows['gallabox_script_url'] ?? 'https://waw.gallabox.com';
$gallabox_custom_script = $galla_rows['gallabox_custom_script'] ?? '';

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
    <div>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
            <div style="width:36px; height:36px; border-radius:10px; background:rgba(37, 211, 102, 0.15); display:flex; align-items:center; justify-content:center; color:#25D366;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                </svg>
            </div>
            <h2 style="font-size:22px; font-weight:700; margin:0; color:var(--text-white);">Centralized WhatsApp & Gallabox Settings</h2>
        </div>
        <p style="font-size:13.5px; color:var(--text-dim); margin:0;">
            Manage the global WhatsApp phone number, brochure buttons, and the floating Gallabox chat widget across all university subdomains.
        </p>
    </div>
</div>

<form method="POST" action="" id="whatsappSettingsForm">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 1.25fr 0.95fr; gap:24px; align-items:start;">

        <!-- Left Column: Settings Configuration -->
        <div style="display:flex; flex-direction:column; gap:24px;">

            <!-- ==========================================
                 PART 1: WHATSAPP HERO & DIRECT SETTINGS
                 ========================================== -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#25D366; box-shadow:0 0 8px #25D366;"></div>
                        <span class="card-title">1. Global WhatsApp Contact & Brochure Button</span>
                    </div>
                    <span class="badge" style="background:rgba(37, 211, 102, 0.15); color:#25D366; font-size:11px; border:1px solid rgba(37, 211, 102, 0.3);">Hero Banners</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" style="font-weight:600;">Central WhatsApp Phone Number *</label>
                        <div style="position:relative;">
                            <input type="text" 
                                   name="whatsapp_number" 
                                   id="inputWhatsAppNumber" 
                                   class="form-control" 
                                   value="<?php echo htmlspecialchars($whatsapp_number); ?>" 
                                   placeholder="+917065777755" 
                                   style="font-family:monospace; font-size:15px; font-weight:600; padding-left:42px;" 
                                   required>
                            <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#25D366; font-size:16px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                                </svg>
                            </span>
                        </div>
                        <span style="font-size:12px; color:var(--text-dim); display:block; margin-top:6px;">
                            Include country code (e.g. <code>+917065777755</code>). Used for brochure downloads and chat triggers.
                        </span>
                    </div>

                    <div class="form-group" style="margin-top:16px;">
                        <label class="form-label" style="font-weight:600;">Default Brochure Button Message Template</label>
                        <textarea name="whatsapp_default_intent" 
                                  id="inputWhatsAppIntent" 
                                  class="form-control" 
                                  rows="2" 
                                  style="font-size:13.5px; line-height:1.5;"><?php echo htmlspecialchars($whatsapp_default_intent); ?></textarea>
                        
                        <div style="margin-top:8px;">
                            <span style="font-size:11.5px; color:var(--text-dim); margin-right:6px;">Click to insert tag:</span>
                            <button type="button" class="tag-chip" onclick="insertTag('inputWhatsAppIntent', '{UNIVERSITY_NAME}')">{UNIVERSITY_NAME}</button>
                            <button type="button" class="tag-chip" onclick="insertTag('inputWhatsAppIntent', '{UNI}')">{UNI}</button>
                            <button type="button" class="tag-chip" onclick="insertTag('inputWhatsAppIntent', '{MODE}')">{MODE}</button>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; margin-top:16px; padding-top:14px; border-top:1px solid rgba(255,255,255,0.06);">
                        <div>
                            <span style="font-weight:600; color:var(--text-white); font-size:13.5px; display:block;">Enable "Download Brochure" WhatsApp Button</span>
                            <span style="font-size:11.5px; color:var(--text-dim); display:block;">Show or hide the green WhatsApp brochure button across hero banners.</span>
                        </div>
                        <label class="switch-toggle" style="margin-left:16px;">
                            <input type="checkbox" name="whatsapp_btn_enabled" id="toggleBtnEnabled" value="1" <?php echo ($whatsapp_btn_enabled === '1') ? 'checked' : ''; ?>>
                            <span class="slider-round"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ==========================================
                 PART 2: GALLABOX WHATSAPP CHAT WIDGET
                 ========================================== -->
            <div class="admin-card" style="border-top:3px solid #25D366;">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#10b981; box-shadow:0 0 8px #10b981;"></div>
                        <span class="card-title">2. Gallabox WhatsApp Chat Widget (Floating)</span>
                    </div>
                    <span class="badge" style="background:rgba(16, 185, 129, 0.15); color:#10b981; font-size:11px;">Subdomain Floating Widget</span>
                </div>
                <div class="card-body">

                    <!-- Master Switch for Gallabox -->
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; padding:12px 16px; background:rgba(37, 211, 102, 0.06); border:1px solid rgba(37, 211, 102, 0.2); border-radius:8px;">
                        <div>
                            <span style="font-weight:700; color:var(--text-white); font-size:14px; display:block;">Enable Gallabox Chat Widget Globally</span>
                            <span style="font-size:12px; color:var(--text-dim); display:block;">
                                Master switch to automatically inject the Gallabox WhatsApp floating widget across all subdomains.
                            </span>
                        </div>
                        <label class="switch-toggle">
                            <input type="checkbox" name="gallabox_widget_enabled" id="toggleGallaboxEnabled" value="1" <?php echo ($gallabox_widget_enabled === '1') ? 'checked' : ''; ?>>
                            <span class="slider-round"></span>
                        </label>
                    </div>

                    <!-- Gallabox waId & Position -->
                    <div style="display:grid; grid-template-columns: 1.5fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">WhatsApp Number / waId *</label>
                            <input type="text" name="gallabox_wa_id" id="inputGallaWaId" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_wa_id); ?>" placeholder="+917065777755" required>
                            <small style="color:var(--text-dim); font-size:11.5px;">Recipient number in international format.</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Widget Position</label>
                            <select name="gallabox_widget_position" id="selectGallaPosition" class="form-control">
                                <option value="RIGHT" <?php echo ($gallabox_widget_position === 'RIGHT') ? 'selected' : ''; ?>>RIGHT (Bottom Right)</option>
                                <option value="LEFT" <?php echo ($gallabox_widget_position === 'LEFT') ? 'selected' : ''; ?>>LEFT (Bottom Left)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Site Name & Tagline -->
                    <div style="display:grid; grid-template-columns: 1fr 1.2fr; gap:16px; margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label">Site Name</label>
                            <input type="text" name="gallabox_site_name" id="inputGallaSiteName" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_site_name); ?>" placeholder="SODE ™">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Site Tagline</label>
                            <input type="text" name="gallabox_site_tag" id="inputGallaSiteTag" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_site_tag); ?>" placeholder="School of Online & Distance Education ">
                        </div>
                    </div>

                    <!-- Avatar Logo URL & Brand Color -->
                    <div style="display:grid; grid-template-columns: 1.8fr 1fr; gap:16px; margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label">Avatar Logo URL</label>
                            <input type="url" name="gallabox_site_logo" id="inputGallaSiteLogo" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_site_logo); ?>" placeholder="https://.../sode-white-favicon.png">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Brand Color</label>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="color" id="pickerGallaColor" value="<?php echo htmlspecialchars($gallabox_brand_color); ?>" 
                                       style="width:38px; height:38px; padding:0; border:none; border-radius:6px; cursor:pointer; background:none;"
                                       oninput="document.getElementById('inputGallaColor').value = this.value; updateGallaboxScriptPreview();">
                                <input type="text" name="gallabox_brand_color" id="inputGallaColor" class="form-control" 
                                       value="<?php echo htmlspecialchars($gallabox_brand_color); ?>" placeholder="#25D366"
                                       oninput="document.getElementById('pickerGallaColor').value = this.value; updateGallaboxScriptPreview();">
                            </div>
                        </div>
                    </div>

                    <!-- Trigger Message & Reply Options -->
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label">Trigger Button Label</label>
                            <input type="text" name="gallabox_trigger_message" id="inputGallaTrigger" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_trigger_message); ?>" placeholder="Get Help">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Quick Reply Options</label>
                            <input type="text" name="gallabox_reply_options" id="inputGallaReplies" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_reply_options); ?>" placeholder="Yes">
                            <small style="color:var(--text-dim); font-size:11.5px;">Comma separated (e.g. Yes, More Info)</small>
                        </div>
                    </div>

                    <!-- Welcome Message -->
                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label">Welcome Message Bubble</label>
                        <textarea name="gallabox_welcome_message" id="inputGallaWelcome" class="form-control" rows="2"><?php echo htmlspecialchars($gallabox_welcome_message); ?></textarea>
                    </div>

                    <!-- Global Fallback messageText Template -->
                    <div class="form-group" style="margin-top:12px;">
                        <label class="form-label" style="font-weight:600;">Global Default "messageText" Template</label>
                        <textarea name="gallabox_default_message_text" id="inputGallaMessageText" class="form-control" rows="2"><?php echo htmlspecialchars($gallabox_default_message_text); ?></textarea>
                        <div style="margin-top:6px;">
                            <span style="font-size:11.5px; color:var(--text-dim); margin-right:6px;">Click to insert tag:</span>
                            <button type="button" class="tag-chip" onclick="insertTag('inputGallaMessageText', '{UNIVERSITY_NAME}')">{UNIVERSITY_NAME}</button>
                            <button type="button" class="tag-chip" onclick="insertTag('inputGallaMessageText', '{UNI}')">{UNI}</button>
                            <button type="button" class="tag-chip" onclick="insertTag('inputGallaMessageText', '{MODE}')">{MODE}</button>
                        </div>
                        <small style="color:var(--text-dim); font-size:11.5px; display:block; margin-top:4px;">
                            Used automatically for universities where no individual custom messageText is specified in University Manager.
                        </small>
                    </div>

                    <!-- Brand Attribution & Script CDN URL -->
                    <div style="display:grid; grid-template-columns: 1fr 1.2fr; gap:16px; margin-top:12px;">
                        <div class="form-group">
                            <label class="form-label">Brand Attribution</label>
                            <input type="text" name="gallabox_brand_attribution" id="inputGallaAttribution" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_brand_attribution); ?>" placeholder="I Love Gallabox">
                        </div>

                        <div class="form-group">
                            <label class="form-label">CDN Base URL</label>
                            <input type="url" name="gallabox_script_url" id="inputGallaCdn" class="form-control" 
                                   value="<?php echo htmlspecialchars($gallabox_script_url); ?>" placeholder="https://waw.gallabox.com">
                        </div>
                    </div>

                    <!-- Raw Custom Script Override (Accordion / Optional) -->
                    <div style="margin-top:16px; padding:12px; background:rgba(255,255,255,0.02); border:1px dashed rgba(255,255,255,0.12); border-radius:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer;" onclick="toggleRawScriptBox()">
                            <span style="font-size:13px; font-weight:600; color:var(--text-muted); display:flex; align-items:center; gap:6px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                                Advanced: Raw Custom Script Override (Optional)
                            </span>
                            <span id="rawScriptToggleIcon" style="font-size:12px; color:var(--text-dim);">▼</span>
                        </div>
                        <div id="rawScriptContainer" style="display:<?php echo !empty($gallabox_custom_script) ? 'block' : 'none'; ?>; margin-top:10px;">
                            <small style="color:var(--text-dim); font-size:11.5px; display:block; margin-bottom:6px;">
                                If filled, this exact raw code will be injected directly instead of the auto-generator.
                            </small>
                            <textarea name="gallabox_custom_script" class="form-control" rows="5" 
                                      style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($gallabox_custom_script); ?></textarea>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Save Action Button -->
            <div style="display:flex; justify-content:flex-end; gap:12px; margin-bottom:30px;">
                <button type="submit" class="btn-primary" style="padding:13px 40px; font-size:15px; font-weight:600; background:linear-gradient(135deg, #10b981 0%, #059669 100%); border:none; box-shadow:0 4px 14px rgba(16, 185, 129, 0.4);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:8px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    Save All Settings
                </button>
            </div>

        </div>

        <!-- Right Column: Interactive Live Preview & Generated Script Inspector -->
        <div style="display:flex; flex-direction:column; gap:20px; position:sticky; top:20px;">
            
            <!-- 1. Hero Button Preview Box -->
            <div class="admin-card" style="border:1px solid rgba(37, 211, 102, 0.35); background:radial-gradient(circle at top right, rgba(37, 211, 102, 0.08), rgba(15, 23, 42, 0.95));">
                <div class="card-header" style="border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="card-title" style="font-size:14px; color:var(--text-white);">Hero Banner Button Preview</span>
                    </div>
                    <span id="previewStatusBadge" class="badge" style="background:rgba(37, 211, 102, 0.2); color:#25D366; font-size:11px;">Active</span>
                </div>
                <div class="card-body">
                    <div style="background:rgba(2, 6, 23, 0.8); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; text-align:center;">
                        <div style="font-size:12.5px; font-weight:700; color:#fff; margin-bottom:2px;">Dayananda Sagar University Online</div>
                        <div style="font-size:11px; color:var(--text-dim); margin-bottom:12px;">Brochure Button WhatsApp Link</div>

                        <div id="mockButtonWrap" style="display:inline-block; transition:all 0.2s ease;">
                            <a id="previewWaLink" href="#" target="_blank" rel="noopener noreferrer" 
                               style="display:inline-flex; align-items:center; gap:8px; background:#25D366; color:#ffffff; padding:9px 20px; font-size:13px; font-weight:700; border-radius:6px; text-decoration:none; box-shadow:0 4px 14px rgba(37, 211, 102, 0.35);">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.699c.963.54 1.802.83 2.796.83 3.18 0 5.767-2.586 5.768-5.766 0-3.18-2.587-5.766-5.768-5.766zm3.376 8.21c-.14.394-.809.734-1.127.781-.309.046-.708.064-2.18-.544-1.878-.775-3.085-2.704-3.178-2.828-.094-.125-.762-1.013-.762-1.932 0-.918.481-1.37.653-1.557.172-.187.375-.234.5-.234.125 0 .25 0 .36.006.118.006.273-.044.426.326.157.382.534 1.304.582 1.4.047.096.078.209.016.333-.063.125-.094.203-.187.312-.094.11-.198.245-.282.33-.094.093-.192.195-.083.382.11.188.487.804 1.045 1.301.718.64 1.323.839 1.51.932.188.094.298.078.407-.047.11-.125.469-.547.594-.734.125-.188.25-.156.422-.094.172.063 1.094.516 1.282.609.188.094.313.141.36.219.046.078.046.453-.094.847z"/>
                                </svg>
                                <span>Download Brochure</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Gallabox Floating Widget Simulated Preview -->
            <div class="admin-card" style="border:1px solid rgba(16, 185, 129, 0.35);">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <span class="card-title" style="font-size:14px;">Gallabox Widget Live Simulation</span>
                    <span id="gallaStatusBadge" class="badge" style="background:rgba(16, 185, 129, 0.2); color:#10b981; font-size:11px;">Enabled</span>
                </div>
                <div class="card-body">
                    <!-- Widget Visual Simulation Bubble -->
                    <div style="background:#090e17; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px;">
                        <!-- Header -->
                        <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                            <img id="simGallaLogo" src="<?php echo htmlspecialchars($gallabox_site_logo); ?>" 
                                 style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:1px solid rgba(255,255,255,0.15);"
                                 onerror="this.style.display='none';">
                            <div>
                                <div id="simGallaSiteName" style="font-size:13px; font-weight:700; color:#fff;"><?php echo htmlspecialchars($gallabox_site_name); ?></div>
                                <div id="simGallaSiteTag" style="font-size:11px; color:var(--text-dim);"><?php echo htmlspecialchars($gallabox_site_tag); ?></div>
                            </div>
                        </div>

                        <!-- Welcome Bubble -->
                        <div style="background:#1e293b; border-radius:8px; padding:10px 12px; margin-bottom:10px; font-size:11.5px; color:#e2e8f0; line-height:1.4;">
                            <span id="simGallaWelcome"><?php echo htmlspecialchars($gallabox_welcome_message); ?></span>
                        </div>

                        <!-- Trigger Button Simulation -->
                        <div style="display:flex; justify-content:flex-end;">
                            <div id="simGallaTriggerBtn" style="display:inline-flex; align-items:center; gap:6px; background:#25D366; color:#fff; padding:7px 14px; border-radius:20px; font-size:12px; font-weight:700; box-shadow:0 3px 10px rgba(37,211,102,0.3);">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.699c.963.54 1.802.83 2.796.83 3.18 0 5.767-2.586 5.768-5.766 0-3.18-2.587-5.766-5.768-5.766zm3.376 8.21c-.14.394-.809.734-1.127.781-.309.046-.708.064-2.18-.544-1.878-.775-3.085-2.704-3.178-2.828-.094-.125-.762-1.013-.762-1.932 0-.918.481-1.37.653-1.557.172-.187.375-.234.5-.234.125 0 .25 0 .36.006.118.006.273-.044.426.326.157.382.534 1.304.582 1.4.047.096.078.209.016.333-.063.125-.094.203-.187.312-.094.11-.198.245-.282.33-.094.093-.192.195-.083.382.11.188.487.804 1.045 1.301.718.64 1.323.839 1.51.932.188.094.298.078.407-.047.11-.125.469-.547.594-.734.125-.188.25-.156.422-.094.172.063 1.094.516 1.282.609.188.094.313.141.36.219.046.078.046.453-.094.847z"/>
                                </svg>
                                <span id="simGallaTriggerText"><?php echo htmlspecialchars($gallabox_trigger_message); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Live Generated Script Box & Copy -->
                    <div style="margin-top:14px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <span style="font-size:12px; font-weight:600; color:var(--text-white);">Auto-Generated Script Output:</span>
                            <button type="button" onclick="copyGallaboxScript()" class="btn-secondary" style="padding:3px 10px; font-size:11px;">
                                Copy Script
                            </button>
                        </div>
                        <pre id="gallaboxLiveScriptBox" style="background:#090d16; border:1px solid rgba(255,255,255,0.08); border-radius:6px; padding:10px; font-family:monospace; font-size:11px; color:#38bdf8; max-height:180px; overflow-y:auto; margin:0; line-height:1.4; white-space:pre-wrap; word-break:break-all;"></pre>
                    </div>

                </div>
            </div>

        </div>

    </div>
</form>

<style>
.tag-chip {
    background: rgba(59, 130, 246, 0.12);
    color: #60a5fa;
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 4px;
    padding: 3px 8px;
    font-size: 11.5px;
    font-family: monospace;
    cursor: pointer;
    transition: all 0.15s ease;
}
.tag-chip:hover {
    background: rgba(59, 130, 246, 0.25);
    border-color: #60a5fa;
    color: #fff;
    transform: translateY(-1px);
}
.switch-toggle {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 26px;
}
.switch-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider-round {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #334155;
    transition: .3s;
    border-radius: 34px;
}
.slider-round:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider-round {
    background-color: #10b981;
}
input:checked + .slider-round:before {
    transform: translateX(22px);
}
</style>

<script>
function insertTag(fieldId, tag) {
    const textarea = document.getElementById(fieldId);
    if (!textarea) return;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + tag + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + tag.length;
    updateAllPreviews();
}

function toggleRawScriptBox() {
    const box = document.getElementById('rawScriptContainer');
    const icon = document.getElementById('rawScriptToggleIcon');
    if (box.style.display === 'none' || !box.style.display) {
        box.style.display = 'block';
        icon.textContent = '▲';
    } else {
        box.style.display = 'none';
        icon.textContent = '▼';
    }
}

function updateAllPreviews() {
    // 1. Hero Preview Update
    const rawPhone = document.getElementById('inputWhatsAppNumber').value.trim();
    const cleanPhone = rawPhone.replace(/[^0-9+]/g, '');
    let intentTemplate = document.getElementById('inputWhatsAppIntent').value.trim();
    if (!intentTemplate) {
        intentTemplate = 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure';
    }
    const sampleText = intentTemplate
        .replace(/{UNIVERSITY_NAME}/g, 'Dayananda Sagar University')
        .replace(/{UNI}/g, 'DSU')
        .replace(/{MODE}/g, 'Online');
    const waUrl = 'https://api.whatsapp.com/send/?phone=' + encodeURIComponent(cleanPhone) + '&text=' + encodeURIComponent(sampleText);
    const previewWaLink = document.getElementById('previewWaLink');
    if (previewWaLink) previewWaLink.setAttribute('href', waUrl);

    // 2. Gallabox Preview Update
    updateGallaboxScriptPreview();
}

function updateGallaboxScriptPreview() {
    const enabled = document.getElementById('toggleGallaboxEnabled').checked;
    const waId = document.getElementById('inputGallaWaId').value.trim() || '+917065777755';
    const siteName = document.getElementById('inputGallaSiteName').value.trim() || 'SODE ™';
    const siteTag = document.getElementById('inputGallaSiteTag').value.trim() || 'School of Online & Distance Education ';
    const siteLogo = document.getElementById('inputGallaSiteLogo').value.trim() || '';
    const position = document.getElementById('selectGallaPosition').value;
    const trigger = document.getElementById('inputGallaTrigger').value.trim() || 'Get Help';
    const welcome = document.getElementById('inputGallaWelcome').value.trim() || '';
    const color = document.getElementById('inputGallaColor').value.trim() || '#25D366';
    const replies = document.getElementById('inputGallaReplies').value.trim().split(',').map(s => s.trim()).filter(Boolean);
    const attribution = document.getElementById('inputGallaAttribution').value.trim() || 'I Love Gallabox';
    const cdnUrl = document.getElementById('inputGallaCdn').value.trim() || 'https://waw.gallabox.com';
    let msgText = document.getElementById('inputGallaMessageText').value.trim() || 'Start Your {UNIVERSITY_NAME} {MODE} Counseling with an Expert Now';

    // Sample substitution for DSU
    const sampleMsg = msgText
        .replace(/{UNIVERSITY_NAME}/g, 'Dayananda Sagar University')
        .replace(/{UNI}/g, 'DSU')
        .replace(/{MODE}/g, 'Online');

    // Update Visual Simulation
    document.getElementById('simGallaSiteName').textContent = siteName;
    document.getElementById('simGallaSiteTag').textContent = siteTag;
    document.getElementById('simGallaWelcome').textContent = welcome;
    document.getElementById('simGallaTriggerText').textContent = trigger;
    const triggerBtn = document.getElementById('simGallaTriggerBtn');
    triggerBtn.style.backgroundColor = color;

    const simLogo = document.getElementById('simGallaLogo');
    if (siteLogo) {
        simLogo.src = siteLogo;
        simLogo.style.display = 'block';
    } else {
        simLogo.style.display = 'none';
    }

    const gallaBadge = document.getElementById('gallaStatusBadge');
    if (enabled) {
        gallaBadge.textContent = 'Enabled';
        gallaBadge.style.background = 'rgba(16, 185, 129, 0.2)';
        gallaBadge.style.color = '#10b981';
    } else {
        gallaBadge.textContent = 'Disabled';
        gallaBadge.style.background = 'rgba(239, 68, 68, 0.2)';
        gallaBadge.style.color = '#ef4444';
    }

    // Build Live Script
    const replyArrStr = JSON.stringify(replies.length ? replies : ['Yes']);
    const generatedScript = `<!-- Gallabox WhatsApp -->
<script>
(function (w, d, s, u) {
w.gbwawc = {
url: u,
options: {
        waId: "${waId}",
        siteName: "${siteName.replace(/"/g, '\\"')}",
        siteTag: "${siteTag.replace(/"/g, '\\"')}",
        siteLogo: "${siteLogo.replace(/"/g, '\\"')}",
        widgetPosition: "${position}",
        triggerMessage: "${trigger.replace(/"/g, '\\"')}",
        welcomeMessage: "${welcome.replace(/"/g, '\\"')}",
        brandColor: "${color}",
        messageText: "${sampleMsg.replace(/"/g, '\\"')}" + getParameterByName('messageText'),
        replyOptions: ${replyArrStr},
        brandAttribution: "${attribution.replace(/"/g, '\\"')}"
    },
};

function getParameterByName(name) {
  name = name.replace(/[\\[]/, "\\\\[").replace(/[\\]]/, "\\\\]");
  var regex = new RegExp("[\\\\?&]" + name + "=([^&#]*)"),
      results = regex.exec(location.search);
  return results === null ? "" : decodeURIComponent(results[1].replace(/\\+/g, " "));
}

var h = d.getElementsByTagName(s)[0],
j = d.createElement(s);
j.async = true;
j.src = u + "/whatsapp-widget.min.js?_=" + Math.random();
h.parentNode.insertBefore(j, h);
})(window, document, "script", "${cdnUrl}");
<\/script>`;

    document.getElementById('gallaboxLiveScriptBox').textContent = generatedScript;
}

function copyGallaboxScript() {
    const text = document.getElementById('gallaboxLiveScriptBox').textContent;
    navigator.clipboard.writeText(text).then(() => {
        alert('Gallabox widget script copied to clipboard!');
    });
}

// Event Listeners
document.querySelectorAll('input, textarea, select').forEach(el => {
    el.addEventListener('input', updateAllPreviews);
    el.addEventListener('change', updateAllPreviews);
});

document.addEventListener('DOMContentLoaded', updateAllPreviews);
updateAllPreviews();
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
