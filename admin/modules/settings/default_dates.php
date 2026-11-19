<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$can_edit = user_can('update') || user_can('write');

$page_title = 'Default Dates & Deadlines (Global)';
$page_subtitle = 'Configure global fallback dates across all university subdomains when individual dates are not specified';
$active_page_key = 'default_dates';

$db = get_db_connection();

// Auto-register sidebar item under SETTINGS if not present
try {
    $check_item = $db->query("SELECT id FROM sidebar_items WHERE active_page_key = 'default_dates'")->fetch();
    if (!$check_item) {
        $db->exec("
            INSERT INTO sidebar_items (menu_section, display_name, page_route, active_page_key, rbac_module_key, icon_svg, sort_order, is_active, is_superadmin_only)
            VALUES ('SETTINGS', 'Default Dates', 'modules/settings/default_dates.php', 'default_dates', 'settings', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><rect x=\"3\" y=\"4\" width=\"18\" height=\"18\" rx=\"2\" ry=\"2\"></rect><line x1=\"16\" y1=\"2\" x2=\"16\" y2=\"6\"></line><line x1=\"8\" y1=\"2\" x2=\"8\" y2=\"6\"></line><line x1=\"3\" y1=\"10\" x2=\"21\" y2=\"10\"></line></svg>', 18, 1, 0)
        ");
        $item_id = (int) $db->lastInsertId();
        if ($item_id) {
            $roles = $db->query("SELECT id FROM roles")->fetchAll();
            $acc_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
            foreach ($roles as $r) {
                $acc_stmt->execute([$r['id'], $item_id]);
            }
        }
    }
} catch (Exception $e) {}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$can_edit) {
        set_flash_message('Access Denied: You do not have permission to modify default date settings.', 'error');
        redirect(BASE_URL . '/modules/settings/default_dates.php');
    }

    $raw_last_date = trim($_POST['default_admission_last_date'] ?? '');
    $raw_exam_date = trim($_POST['default_exam_date'] ?? '');
    $raw_ext_exam  = trim($_POST['default_extended_exam_date'] ?? '');
    $raw_start_date= trim($_POST['default_admission_start_date'] ?? '');
    $raw_asg_date  = trim($_POST['default_assignment_date'] ?? '');

    // Format all dates as "15 Nov 2026"
    $formatted_last_date = sode_format_admin_date_for_db($raw_last_date);
    $formatted_exam_date = sode_format_admin_date_for_db($raw_exam_date);
    $formatted_ext_exam  = sode_format_admin_date_for_db($raw_ext_exam);
    $formatted_start_date= sode_format_admin_date_for_db($raw_start_date);
    $formatted_asg_date  = sode_format_admin_date_for_db($raw_asg_date);

    try {
        // 1. Update global_settings
        $stmt_setting = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group, description)
            VALUES (?, ?, 'dates', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        $stmt_setting->execute(['default_admission_last_date', $formatted_last_date, 'Global default Application / Admission Deadline']);
        $stmt_setting->execute(['default_exam_date', $formatted_exam_date, 'Global default Examination Commencement Date']);
        $stmt_setting->execute(['default_extended_exam_date', $formatted_ext_exam, 'Global default Extended Examination Date']);
        $stmt_setting->execute(['default_admission_start_date', $formatted_start_date, 'Global default Semester Commencement / Admission Start Date']);
        $stmt_setting->execute(['default_assignment_date', $formatted_asg_date, 'Global default Assignment Submission Date']);

        // 2. Sync to global_keys table for instant shortcode & client API lookup
        $stmt_key = $db->prepare("
            INSERT INTO global_keys (key_code, key_value, description, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), is_active = 1
        ");

        $stmt_key->execute(['$DEFAULT_ADMISSION_LAST_DATE$', $formatted_last_date, 'Default Admission Last Date']);
        $stmt_key->execute(['$DEFAULT_EXAM_DATE$', $formatted_exam_date, 'Default Exam Date']);
        $stmt_key->execute(['$DEFAULT_EXTENDED_EXAM_DATE$', $formatted_ext_exam, 'Default Extended Exam Date']);
        $stmt_key->execute(['$DEFAULT_ADMISSION_START_DATE$', $formatted_start_date, 'Default Admission Start Date']);
        $stmt_key->execute(['$DEFAULT_ASSIGNMENT_DATE$', $formatted_asg_date, 'Default Assignment Submission Date']);

        // Log Activity
        if (function_exists('log_activity')) {
            log_activity('UPDATE', 'settings', "Updated Global Default Dates & Deadlines", [
                'item_type' => 'Setting',
                'item_title' => 'Default Dates',
                'admission_last_date' => $formatted_last_date,
                'exam_date' => $formatted_exam_date
            ]);
        }

        // 3. Flush cache on all active subdomains so new default dates reflect immediately
        if (function_exists('sode_bust_all_subdomain_caches')) {
            sode_bust_all_subdomain_caches($db);
        }

        set_flash_message('Global Default Dates & Deadlines updated successfully! All subdomains with unassigned dates will now use these dates.', 'success');
        redirect(BASE_URL . '/modules/settings/default_dates.php');
    } catch (PDOException $e) {
        set_flash_message('Failed to update default dates: ' . $e->getMessage(), 'error');
    }
}

// Fetch current default dates
$defaults = sode_get_default_dates($db);

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="font-size:20px; font-weight:700; margin:0 0 4px 0; color:var(--text-main, #fff);">Global Default Dates & Deadlines</h2>
        <p style="font-size:13.5px; color:var(--text-dim, #94a3b8); margin:0;">
            Configure system-wide fallback dates formatted as <strong>15 Nov 2026</strong>. Whenever an individual university does not have specific dates filled in, these default dates are automatically displayed on the frontend banner, dates table, and dynamic shortcodes.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn btn-secondary" style="font-size:13px;">
            &larr; Back to Universities
        </a>
    </div>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div class="row" style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
        <div style="display:flex; flex-direction:column; gap:24px;">
            
            <div class="admin-card">
                <div class="card-header" style="border-bottom:1px solid var(--border-color, #334155); padding:16px 20px;">
                    <span class="card-title" style="font-size:15px; font-weight:700; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-color, #3b82f6)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        Default Calendar Dates
                    </span>
                </div>
                <div class="card-body" style="padding:20px;">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                        
                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                                Default Admission Last Date (Application Deadline)
                            </label>
                            <input type="date" name="default_admission_last_date" class="form-control" 
                                value="<?php echo htmlspecialchars(sode_format_date_for_input($defaults['admission_last_date'])); ?>" 
                                required style="width:100%; padding:9px 12px; border-radius:6px; background:var(--bg-input, #0f172a); border:1px solid var(--border-color, #334155); color:#fff;">
                            <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                                Current Display Format: <strong style="color:var(--primary-color, #38bdf8);"><?php echo esc_html($defaults['admission_last_date']); ?></strong>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                                Default Admission Start Date (Semester Commencement)
                            </label>
                            <input type="date" name="default_admission_start_date" class="form-control" 
                                value="<?php echo htmlspecialchars(sode_format_date_for_input($defaults['admission_start_date'])); ?>" 
                                required style="width:100%; padding:9px 12px; border-radius:6px; background:var(--bg-input, #0f172a); border:1px solid var(--border-color, #334155); color:#fff;">
                            <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                                Current Display Format: <strong style="color:var(--primary-color, #38bdf8);"><?php echo esc_html($defaults['admission_start_date']); ?></strong>
                            </div>
                        </div>

                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-top:20px;">

                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                                Default Examination Date (Commencement)
                            </label>
                            <input type="date" name="default_exam_date" class="form-control" 
                                value="<?php echo htmlspecialchars(sode_format_date_for_input($defaults['exam_date'])); ?>" 
                                required style="width:100%; padding:9px 12px; border-radius:6px; background:var(--bg-input, #0f172a); border:1px solid var(--border-color, #334155); color:#fff;">
                            <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                                Current Display Format: <strong style="color:var(--primary-color, #38bdf8);"><?php echo esc_html($defaults['exam_date']); ?></strong>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                                Default Extended Exam Date
                            </label>
                            <input type="date" name="default_extended_exam_date" class="form-control" 
                                value="<?php echo htmlspecialchars(sode_format_date_for_input($defaults['extended_exam_date'])); ?>" 
                                required style="width:100%; padding:9px 12px; border-radius:6px; background:var(--bg-input, #0f172a); border:1px solid var(--border-color, #334155); color:#fff;">
                            <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                                Current Display Format: <strong style="color:var(--primary-color, #38bdf8);"><?php echo esc_html($defaults['extended_exam_date']); ?></strong>
                            </div>
                        </div>

                    </div>

                    <div style="display:grid; grid-template-columns: 1fr; gap:20px; margin-top:20px;">

                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                                Default Assignment Submission Date
                            </label>
                            <input type="date" name="default_assignment_date" class="form-control" 
                                value="<?php echo htmlspecialchars(sode_format_date_for_input($defaults['assignment_date'])); ?>" 
                                required style="width:100%; padding:9px 12px; border-radius:6px; background:var(--bg-input, #0f172a); border:1px solid var(--border-color, #334155); color:#fff;">
                            <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                                Current Display Format: <strong style="color:var(--primary-color, #38bdf8);"><?php echo esc_html($defaults['assignment_date']); ?></strong>
                            </div>
                        </div>

                    </div>

                    <?php if ($can_edit): ?>
                        <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--border-color, #334155); display:flex; justify-content:flex-end;">
                            <button type="submit" class="btn btn-primary" style="padding:10px 24px; font-weight:600; font-size:14px; background:var(--primary-color, #3b82f6); border:none; border-radius:6px; color:#fff; cursor:pointer;">
                                Save Global Default Dates
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div>
            <div class="admin-card" style="position:sticky; top:20px;">
                <div class="card-header" style="border-bottom:1px solid var(--border-color, #334155); padding:16px 20px;">
                    <span class="card-title" style="font-size:15px; font-weight:700; color:var(--text-main, #fff);">
                        How Default Dates Work
                    </span>
                </div>
                <div class="card-body" style="padding:20px; font-size:13px; line-height:1.6; color:var(--text-dim, #94a3b8);">
                    <p style="margin-top:0;">
                        <strong style="color:var(--text-main, #fff);">1. Individual Override:</strong><br>
                        When creating or editing a university in the <em>Universities</em> section, you can select specific dates for that university. If set, those specific dates will always be displayed.
                    </p>
                    <p>
                        <strong style="color:var(--text-main, #fff);">2. Fallback to Defaults:</strong><br>
                        If an individual university has any date field left blank, the system automatically uses the corresponding global date configured here.
                    </p>
                    <p style="margin-bottom:0;">
                        <strong style="color:var(--text-main, #fff);">3. Unified Date Format:</strong><br>
                        All calendar selections are automatically converted and displayed in the standard format: <span style="background:rgba(59,130,246,0.15); color:#60a5fa; padding:2px 6px; border-radius:4px; font-family:monospace; font-weight:600;">15 Nov 2026</span>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
