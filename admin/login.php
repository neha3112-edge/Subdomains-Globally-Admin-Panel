<?php
require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        $error = 'Please enter both email/username and password.';
    } else {
        $res = attempt_login($identifier, $password);
        if ($res['success']) {
            redirect(BASE_URL . '/dashboard.php');
        } else {
            $error = $res['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css?v=<?php echo time(); ?>">
    <style>
        .login-page-wrap {
            min-height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: radial-gradient(circle at top center, rgba(79, 70, 229, 0.18) 0%, rgba(9, 14, 23, 1) 75%);
            position: relative;
            overflow: hidden;
        }

        [data-theme="light"] .login-page-wrap {
            background: radial-gradient(circle at top center, rgba(79, 70, 229, 0.08) 0%, #f4f6fb 75%);
        }

        .login-top-actions {
            position: absolute;
            top: 24px;
            right: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 42px 34px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 5;
            animation: fadeIn 0.4s ease;
        }

        [data-theme="light"] .login-card {
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.08);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-brand-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.45);
        }

        .login-title {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.4px;
        }

        .login-sub {
            font-size: 13px;
            color: var(--text-dim);
            margin-top: 6px;
            font-weight: 400;
        }

        .input-icon-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-dim);
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .form-control-icon {
            padding-left: 42px;
        }

        .password-toggle-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-dim);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }

        .password-toggle-icon:hover {
            color: var(--text-main);
        }
    </style>
</head>
<body>

<div class="login-page-wrap">
    <div class="login-top-actions">
        <button type="button" class="theme-toggle-btn" id="theme-toggle-btn" title="Toggle Light / Dark Mode">
            <span id="theme-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </span>
        </button>
    </div>

    <div class="login-card">
        <div class="login-header">
            <div class="login-brand-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                    <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                </svg>
            </div>
            <h1 class="login-title"><?php echo htmlspecialchars(APP_NAME); ?></h1>
            <p class="login-sub">Sign in to manage universal subdomains & records</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="admin-alert alert-error">
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label class="form-label">Email or Username</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </span>
                    <input type="text" name="identifier" class="form-control form-control-icon" placeholder="admin or name@domain.com" required autofocus>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-icon-wrap">
                    <span class="input-icon">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </span>
                    <input type="password" name="password" id="login-password" class="form-control form-control-icon" placeholder="••••••••" style="padding-right: 44px;" required>
                    <button type="button" class="password-toggle-icon password-toggle" data-target="login-password" title="Show/Hide Password">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 14px; padding: 13px 20px;">
                Sign In to Dashboard
            </button>
        </form>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/admin.js"></script>
</body>
</html>
