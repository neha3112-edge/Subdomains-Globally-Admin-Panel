<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title = 'Lead & API Settings (Global)';
$page_subtitle = 'Configure centralized CRM, Brevo, and Gallabox API keys across all university subdomains';
$active_page_key = 'api_integrations';

$db = get_db_connection();

// Ensure global_settings table exists
$db->exec("
    CREATE TABLE IF NOT EXISTS `global_settings` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` LONGTEXT NULL,
        `setting_group` VARCHAR(50) DEFAULT 'general',
        `description` VARCHAR(255) NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $settings_to_update = [
        'crm_api_url'          => trim($_POST['crm_api_url'] ?? ''),
        'crm_api_key'          => trim($_POST['crm_api_key'] ?? ''),
        'crm_secret'           => trim($_POST['crm_secret'] ?? ''),
        'brevo_api_url'        => trim($_POST['brevo_api_url'] ?? ''),
        'brevo_api_key'        => trim($_POST['brevo_api_key'] ?? ''),
        'gallabox_webhook_url' => trim($_POST['gallabox_webhook_url'] ?? ''),
    ];

    try {
        $stmt = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group)
            VALUES (?, ?, 'integrations')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($settings_to_update as $key => $val) {
            $stmt->execute([$key, $val]);
        }

        set_flash_message('Global API & CRM settings updated successfully!', 'success');
        redirect(BASE_URL . '/modules/settings/api_integrations.php');
    } catch (PDOException $e) {
        set_flash_message('Failed to update settings: ' . $e->getMessage(), 'error');
    }
}

// Fetch all current global settings
$rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group = 'integrations'")->fetchAll(PDO::FETCH_KEY_PAIR);

$crm_api_url          = $rows['crm_api_url'] ?? (getenv('CRM_API_URL') ?: 'https://api.crm.mysode.com/api/lead/apicreated');
$crm_api_key          = $rows['crm_api_key'] ?? (getenv('CRM_API_KEY') ?: '');
$crm_secret           = $rows['crm_secret'] ?? (getenv('CRM_SECRET') ?: '');
$brevo_api_url        = $rows['brevo_api_url'] ?? (getenv('BREVO_API_URL') ?: 'https://api.brevo.com/v3/contacts');
$brevo_api_key        = $rows['brevo_api_key'] ?? (getenv('BREVO_API_KEY') ?: '');
$gallabox_webhook_url = $rows['gallabox_webhook_url'] ?? (getenv('GALLABOX_WEBHOOK_URL') ?: '');

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:4px;">Global Lead Integrations & API Credentials</span>
        <p style="font-size:13px; color:var(--text-dim); margin:0;">These credentials apply globally to all university subdomains and multi-endpoint lead capture forms.</p>
    </div>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 1fr; gap:24px; max-width:980px;">

        <!-- 1. Main SODE CRM Integration -->
        <div class="admin-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#3b82f6;"></div>
                    <span class="card-title">1. Main CRM Settings (mysode.com)</span>
                </div>
                <span class="badge badge-primary" style="font-size:11px;">Primary CRM</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">CRM Lead Creation API Endpoint URL *</label>
                    <input type="url" name="crm_api_url" class="form-control" value="<?php echo htmlspecialchars($crm_api_url); ?>" placeholder="https://api.crm.mysode.com/api/lead/apicreated" required>
                    <span style="font-size:11.5px; color:var(--text-dim);">POST endpoint receiving candidate leads from all subdomains.</span>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label">CRM x-api-key Header *</label>
                        <input type="text" name="crm_api_key" class="form-control" value="<?php echo htmlspecialchars($crm_api_key); ?>" placeholder="a04b4291461f8b0..." required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">CRM secret Header *</label>
                        <input type="text" name="crm_secret" class="form-control" value="<?php echo htmlspecialchars($crm_secret); ?>" placeholder="#QR(w#HW|Fz:ouX]cm..." required>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Brevo (Sendinblue) Integration -->
        <div class="admin-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#10b981;"></div>
                    <span class="card-title">2. Brevo API Settings (Sendinblue)</span>
                </div>
                <span class="badge badge-success" style="font-size:11px;">Email / SMS Marketing</span>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: 1fr 2fr; gap:16px;">
                    <div class="form-group">
                        <label class="form-label">Brevo Contacts API URL *</label>
                        <input type="url" name="brevo_api_url" class="form-control" value="<?php echo htmlspecialchars($brevo_api_url); ?>" placeholder="https://api.brevo.com/v3/contacts" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Brevo API Key (api-key Header) *</label>
                        <input type="text" name="brevo_api_key" class="form-control" value="<?php echo htmlspecialchars($brevo_api_key); ?>" placeholder="Enter Brevo API key..." required>
                    </div>
                </div>
                <span style="font-size:11.5px; color:var(--text-dim);">University-specific Brevo List IDs and Sources can still be set individually in University Manager.</span>
            </div>
        </div>

        <!-- 3. Gallabox WhatsApp Webhook Integration -->
        <div class="admin-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <div style="width:10px; height:10px; border-radius:50%; background:#f59e0b;"></div>
                    <span class="card-title">3. Gallabox WhatsApp Webhook (Global)</span>
                </div>
                <span class="badge badge-warning" style="font-size:11px;">WhatsApp Automation</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Gallabox Central Generic Webhook URL *</label>
                    <input type="url" name="gallabox_webhook_url" class="form-control" value="<?php echo htmlspecialchars($gallabox_webhook_url); ?>" placeholder="https://server.gallabox.com/accounts/.../integrations/genericWebhook/.../webhook" required>
                    <span style="font-size:11.5px; color:var(--text-dim);">Central webhook URL for WhatsApp messaging & chatbot triggers across all university domains.</span>
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:8px;">
            <button type="submit" class="btn-primary" style="padding:12px 32px; font-size:15px; font-weight:600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:6px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                Save Global Credentials
            </button>
        </div>

    </div>
</form>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
