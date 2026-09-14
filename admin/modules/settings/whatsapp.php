<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title = 'WhatsApp Settings (Global)';
$page_subtitle = 'Configure centralized WhatsApp phone number, default brochure intent, and button behavior across all university subdomains';
$active_page_key = 'whatsapp_settings';

$db = get_db_connection();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $raw_phone = trim($_POST['whatsapp_number'] ?? '');
    // Clean phone number: allow + and digits
    $clean_phone = preg_replace('/[^0-9+]/', '', $raw_phone);
    if (!empty($clean_phone) && strpos($clean_phone, '+') !== 0 && strpos($clean_phone, '91') !== 0 && strlen($clean_phone) === 10) {
        $clean_phone = '+91' . $clean_phone;
    }

    $whatsapp_default_intent = trim($_POST['whatsapp_default_intent'] ?? '');
    if (empty($whatsapp_default_intent)) {
        $whatsapp_default_intent = 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure';
    }

    $whatsapp_btn_enabled = isset($_POST['whatsapp_btn_enabled']) ? '1' : '0';

    try {
        // 1. Update global_settings
        $stmt_settings = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group, description)
            VALUES (?, ?, 'whatsapp', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        $stmt_settings->execute(['whatsapp_number', $clean_phone, 'Central WhatsApp phone number for brochure & inquiries']);
        $stmt_settings->execute(['whatsapp_default_intent', $whatsapp_default_intent, 'Global default WhatsApp message intent template']);
        $stmt_settings->execute(['whatsapp_btn_enabled', $whatsapp_btn_enabled, 'Global enable/disable toggle for WhatsApp brochure button']);

        // 2. Sync to global_keys table for instant client and API lookup
        $stmt_keys = $db->prepare("
            INSERT INTO global_keys (key_code, key_value, description, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), is_active = 1
        ");

        $stmt_keys->execute(['whatsapp_number', $clean_phone, 'Global WhatsApp Number for buttons and links']);
        $stmt_keys->execute(['whatsapp_default_intent', $whatsapp_default_intent, 'Global default WhatsApp brochure intent']);
        $stmt_keys->execute(['whatsapp_btn_enabled', $whatsapp_btn_enabled, 'Global WhatsApp button toggle']);

        set_flash_message('Global WhatsApp settings saved and synchronized successfully!', 'success');
        redirect(BASE_URL . '/modules/settings/whatsapp.php');
    } catch (PDOException $e) {
        set_flash_message('Failed to save settings: ' . $e->getMessage(), 'error');
    }
}

// Fetch current settings from global_settings with fallback to global_keys
$settings_rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'whatsapp'")->fetchAll(PDO::FETCH_KEY_PAIR);
$keys_rows = $db->query("SELECT key_code, key_value FROM global_keys WHERE key_code LIKE 'whatsapp%'")->fetchAll(PDO::FETCH_KEY_PAIR);

$whatsapp_number = $settings_rows['whatsapp_number'] ?? $keys_rows['whatsapp_number'] ?? '+917065777755';
$whatsapp_default_intent = $settings_rows['whatsapp_default_intent'] ?? $keys_rows['whatsapp_default_intent'] ?? 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure';
$whatsapp_btn_enabled = $settings_rows['whatsapp_btn_enabled'] ?? $keys_rows['whatsapp_btn_enabled'] ?? '1';

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
            <h2 style="font-size:22px; font-weight:700; margin:0; color:var(--text-white);">Centralized WhatsApp Settings</h2>
        </div>
        <p style="font-size:13.5px; color:var(--text-dim); margin:0;">
            Manage the global WhatsApp phone number, default brochure message intent, and live preview for all university subdomains.
        </p>
    </div>
</div>

<form method="POST" action="" id="whatsappSettingsForm">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 1.25fr 0.95fr; gap:24px; align-items:start;">

        <!-- Left Column: Settings Configuration -->
        <div style="display:flex; flex-direction:column; gap:24px;">

            <!-- 1. WhatsApp Number Card -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#25D366; box-shadow:0 0 8px #25D366;"></div>
                        <span class="card-title">1. Global WhatsApp Contact Number</span>
                    </div>
                    <span class="badge" style="background:rgba(37, 211, 102, 0.15); color:#25D366; font-size:11px; border:1px solid rgba(37, 211, 102, 0.3);">All Subdomains</span>
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
                            Include country code (e.g. <code>+917065777755</code> or <code>917065777755</code>). All university brochure downloads and WhatsApp chat triggers will redirect to this number.
                        </span>
                    </div>
                </div>
            </div>

            <!-- 2. WhatsApp Default Intent Card -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#3b82f6;"></div>
                        <span class="card-title">2. Default WhatsApp Intent / Message Template</span>
                    </div>
                    <span class="badge badge-primary" style="font-size:11px;">Dynamic Placeholders</span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" style="font-weight:600;">Fallback Intent Message</label>
                        <textarea name="whatsapp_default_intent" 
                                  id="inputWhatsAppIntent" 
                                  class="form-control" 
                                  rows="3" 
                                  style="font-size:14px; line-height:1.5;"><?php echo htmlspecialchars($whatsapp_default_intent); ?></textarea>
                        
                        <div style="margin-top:10px;">
                            <span style="font-size:12px; color:var(--text-dim); margin-right:8px;">Click to insert dynamic placeholder:</span>
                            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:6px;">
                                <button type="button" class="tag-chip" onclick="insertTag('{UNIVERSITY_NAME}')" title="e.g. Dayananda Sagar University">{UNIVERSITY_NAME}</button>
                                <button type="button" class="tag-chip" onclick="insertTag('{UNI}')" title="e.g. DSU">{UNI}</button>
                                <button type="button" class="tag-chip" onclick="insertTag('{MODE}')" title="e.g. Online & Distance">{MODE}</button>
                            </div>
                        </div>

                        <span style="font-size:12px; color:var(--text-dim); display:block; margin-top:10px;">
                            This message template is used automatically whenever a university has no custom message intent configured in its individual University Manager profile.
                        </span>
                    </div>
                </div>
            </div>

            <!-- 3. WhatsApp Brochure Button Toggle -->
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:10px; height:10px; border-radius:50%; background:#a855f7;"></div>
                        <span class="card-title">3. Global Button Visibility</span>
                    </div>
                </div>
                <div class="card-body">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <div>
                            <span style="font-weight:600; color:var(--text-white); font-size:14px; display:block;">Enable "Download Brochure" WhatsApp Button</span>
                            <span style="font-size:12px; color:var(--text-dim); display:block; margin-top:2px;">
                                Master switch to show or hide the green WhatsApp brochure button across hero banners and mobile views on all subdomains.
                            </span>
                        </div>
                        <label class="switch-toggle" style="margin-left:16px;">
                            <input type="checkbox" name="whatsapp_btn_enabled" id="toggleBtnEnabled" value="1" <?php echo ($whatsapp_btn_enabled === '1') ? 'checked' : ''; ?>>
                            <span class="slider-round"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Save Action Button -->
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="submit" class="btn-primary" style="padding:12px 36px; font-size:15px; font-weight:600; background:linear-gradient(135deg, #10b981 0%, #059669 100%); border:none; box-shadow:0 4px 14px rgba(16, 185, 129, 0.4);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:8px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    Save WhatsApp Settings
                </button>
            </div>

        </div>

        <!-- Right Column: Interactive Live Preview & Testing -->
        <div style="display:flex; flex-direction:column; gap:20px; position:sticky; top:20px;">
            
            <div class="admin-card" style="border:1px solid rgba(37, 211, 102, 0.35); background:radial-gradient(circle at top right, rgba(37, 211, 102, 0.08), rgba(15, 23, 42, 0.95));">
                <div class="card-header" style="border-bottom:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polygon points="10 8 16 12 10 16 10 8"></polygon>
                        </svg>
                        <span class="card-title" style="font-size:14px; color:var(--text-white);">Live Banner Button Preview</span>
                    </div>
                    <span id="previewStatusBadge" class="badge" style="background:rgba(37, 211, 102, 0.2); color:#25D366; font-size:11px;">Active</span>
                </div>
                <div class="card-body">
                    <p style="font-size:12.5px; color:var(--text-dim); margin-top:0; margin-bottom:16px;">
                        Below is a simulated hero banner preview using <strong>Dayananda Sagar University (DSU)</strong> as sample context:
                    </p>

                    <!-- Mock Banner Box -->
                    <div style="background:rgba(2, 6, 23, 0.8); border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:18px; text-align:center;">
                        <div style="font-size:13px; font-weight:700; color:#fff; margin-bottom:4px;">Dayananda Sagar University Online</div>
                        <div style="font-size:11.5px; color:var(--text-dim); margin-bottom:14px;">Online Education Courses, Fees & Admissions 2026</div>

                        <!-- Rendered WhatsApp Button Simulation -->
                        <div id="mockButtonWrap" style="display:inline-block; transition:all 0.2s ease;">
                            <a id="previewWaLink" 
                               href="#" 
                               target="_blank" 
                               rel="noopener noreferrer" 
                               style="display:inline-flex; align-items:center; gap:8px; background:#25D366; color:#ffffff; padding:10px 22px; font-size:13.5px; font-weight:700; border-radius:6px; text-decoration:none; box-shadow:0 4px 14px rgba(37, 211, 102, 0.35); transition:transform 0.15s ease;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.699c.963.54 1.802.83 2.796.83 3.18 0 5.767-2.586 5.768-5.766 0-3.18-2.587-5.766-5.768-5.766zm3.376 8.21c-.14.394-.809.734-1.127.781-.309.046-.708.064-2.18-.544-1.878-.775-3.085-2.704-3.178-2.828-.094-.125-.762-1.013-.762-1.932 0-.918.481-1.37.653-1.557.172-.187.375-.234.5-.234.125 0 .25 0 .36.006.118.006.273-.044.426.326.157.382.534 1.304.582 1.4.047.096.078.209.016.333-.063.125-.094.203-.187.312-.094.11-.198.245-.282.33-.094.093-.192.195-.083.382.11.188.487.804 1.045 1.301.718.64 1.323.839 1.51.932.188.094.298.078.407-.047.11-.125.469-.547.594-.734.125-.188.25-.156.422-.094.172.063 1.094.516 1.282.609.188.094.313.141.36.219.046.078.046.453-.094.847z"/>
                                </svg>
                                <span>Download Brochure</span>
                            </a>
                        </div>
                        <div id="mockDisabledMsg" style="display:none; color:#ef4444; font-size:12px; font-weight:600; padding:10px; background:rgba(239, 68, 68, 0.1); border-radius:6px; margin-top:8px;">
                            Button Hidden Globally (Turned OFF)
                        </div>
                    </div>

                    <!-- URL Inspector -->
                    <div style="margin-top:16px;">
                        <span style="font-size:12px; color:var(--text-dim); display:block; margin-bottom:6px; font-weight:600;">Generated WhatsApp URL:</span>
                        <div id="previewUrlBox" style="background:#090d16; border:1px solid rgba(255,255,255,0.08); border-radius:6px; padding:10px; font-family:monospace; font-size:11.5px; color:#38bdf8; word-break:break-all; max-height:80px; overflow-y:auto;">
                            <!-- Auto updated via JS -->
                        </div>
                    </div>

                    <!-- Direct Test Button -->
                    <div style="margin-top:16px;">
                        <button type="button" 
                                id="btnTestWhatsApp" 
                                onclick="openTestWhatsApp()" 
                                class="btn-secondary" 
                                style="width:100%; justify-content:center; padding:10px; font-size:13.5px; font-weight:600; color:#25D366; border-color:rgba(37, 211, 102, 0.4);">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                <polyline points="15 3 21 3 21 9"></polyline>
                                <line x1="10" y1="14" x2="21" y2="3"></line>
                            </svg>
                            Test Open WhatsApp in New Tab
                        </button>
                    </div>

                </div>
            </div>

            <!-- Info Card -->
            <div class="admin-card" style="background:rgba(255,255,255,0.02);">
                <div class="card-body" style="padding:16px;">
                    <div style="font-size:13px; font-weight:600; color:var(--text-white); margin-bottom:6px;">💡 How this works globally:</div>
                    <ul style="margin:0; padding-left:18px; font-size:12px; color:var(--text-dim); line-height:1.6;">
                        <li>If a university has no custom phone number, this <strong>Global WhatsApp Number</strong> is used.</li>
                        <li>If a university has no custom message intent, this <strong>Default Intent</strong> template is rendered with its university name and mode.</li>
                        <li>Any change saved here takes effect immediately on both local and remote subdomains via central API synchronization.</li>
                    </ul>
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
function insertTag(tag) {
    const textarea = document.getElementById('inputWhatsAppIntent');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + tag + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + tag.length;
    updateLivePreview();
}

function updateLivePreview() {
    const rawPhone = document.getElementById('inputWhatsAppNumber').value.trim();
    const cleanPhone = rawPhone.replace(/[^0-9+]/g, '');
    
    let intentTemplate = document.getElementById('inputWhatsAppIntent').value.trim();
    if (!intentTemplate) {
        intentTemplate = 'I want to Download {UNIVERSITY_NAME} {MODE} Brochure';
    }

    // Sample substitution for DSU
    const sampleText = intentTemplate
        .replace(/{UNIVERSITY_NAME}/g, 'Dayananda Sagar University')
        .replace(/{UNI}/g, 'DSU')
        .replace(/{MODE}/g, 'Online');

    const waUrl = 'https://api.whatsapp.com/send/?phone=' + encodeURIComponent(cleanPhone) + '&text=' + encodeURIComponent(sampleText);

    document.getElementById('previewUrlBox').textContent = waUrl;
    document.getElementById('previewWaLink').setAttribute('href', waUrl);

    const isEnabled = document.getElementById('toggleBtnEnabled').checked;
    const btnWrap = document.getElementById('mockButtonWrap');
    const disabledMsg = document.getElementById('mockDisabledMsg');
    const badge = document.getElementById('previewStatusBadge');

    if (isEnabled) {
        btnWrap.style.opacity = '1';
        btnWrap.style.pointerEvents = 'auto';
        disabledMsg.style.display = 'none';
        badge.textContent = 'Active';
        badge.style.background = 'rgba(37, 211, 102, 0.2)';
        badge.style.color = '#25D366';
    } else {
        btnWrap.style.opacity = '0.35';
        btnWrap.style.pointerEvents = 'none';
        disabledMsg.style.display = 'block';
        badge.textContent = 'Disabled';
        badge.style.background = 'rgba(239, 68, 68, 0.2)';
        badge.style.color = '#ef4444';
    }
}

function openTestWhatsApp() {
    const waUrl = document.getElementById('previewWaLink').getAttribute('href');
    if (waUrl && waUrl !== '#') {
        window.open(waUrl, '_blank');
    }
}

document.getElementById('inputWhatsAppNumber').addEventListener('input', updateLivePreview);
document.getElementById('inputWhatsAppIntent').addEventListener('input', updateLivePreview);
document.getElementById('toggleBtnEnabled').addEventListener('change', updateLivePreview);

// Initial update on page load
document.addEventListener('DOMContentLoaded', updateLivePreview);
updateLivePreview();
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
