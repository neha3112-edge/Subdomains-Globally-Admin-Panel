<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Change Password';
$page_subtitle = 'Update your account security password';
$active_page_key = 'change_password';

$db = get_db_connection();
$current_user = get_logged_in_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd = $_POST['new_password'] ?? '';
    $confirm_pwd = $_POST['confirm_password'] ?? '';

    if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
        set_flash_message('All fields are required.', 'error');
    } elseif (strlen($new_pwd) < 6) {
        set_flash_message('New password must be at least 6 characters long.', 'error');
    } elseif ($new_pwd !== $confirm_pwd) {
        set_flash_message('New password and confirmation do not match.', 'error');
    } else {
        // Verify current password
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$current_user['id']]);
        $stored_hash = $stmt->fetchColumn();

        if (!$stored_hash || !password_verify($current_pwd, $stored_hash)) {
            set_flash_message('Current password is incorrect.', 'error');
        } else {
            $new_hash = password_hash($new_pwd, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_hash, $current_user['id']]);
            set_flash_message('Password changed successfully!', 'success');
            redirect(BASE_URL . '/change_password.php');
        }
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="max-width: 540px; margin: 0 auto;">
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Security & Password</span>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>

                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <div class="password-input-wrap" style="position:relative;">
                        <input type="password" name="current_password" id="cur_pwd" class="form-control" placeholder="••••••••" required>
                        <button type="button" class="password-toggle-icon password-toggle" data-target="cur_pwd" title="Show/Hide Password" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-dim); cursor:pointer;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <div class="password-input-wrap" style="position:relative;">
                        <input type="password" name="new_password" id="new_pwd" class="form-control" placeholder="Minimum 6 characters" required>
                        <button type="button" class="password-toggle-icon password-toggle" data-target="new_pwd" title="Show/Hide Password" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-dim); cursor:pointer;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <div class="password-input-wrap" style="position:relative;">
                        <input type="password" name="confirm_password" id="conf_pwd" class="form-control" placeholder="Re-enter new password" required>
                        <button type="button" class="password-toggle-icon password-toggle" data-target="conf_pwd" title="Show/Hide Password" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--text-dim); cursor:pointer;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:10px;">
                    Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
