<?php
/**
 * Admin Panel Top Header Navigation
 */
$current_user = get_logged_in_user();
$page_title = $page_title ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? ('Welcome back, ' . htmlspecialchars($current_user['name']));
// Calculate remaining session time (fixed 2 hours = 7200s from login)
$session_expires_at = $_SESSION['session_expires_at'] ?? (($_SESSION['login_time'] ?? time()) + 7200);
$remaining_session_seconds = max(0, $session_expires_at - time());
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title . ' - ' . APP_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
<div class="app-container">
    <?php require_once ADMIN_PATH . '/includes/sidebar.php'; ?>
    
    <div class="app-main">
        <header class="app-header">
            <div class="header-left">
                <button type="button" class="sidebar-toggle-btn" id="sidebar-toggle-btn" aria-label="Toggle Sidebar">
                    &#9776;
                </button>
                <div>
                    <h1 class="header-page-title"><?php echo htmlspecialchars($page_title); ?></h1>
                    <p class="header-page-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></p>
                </div>
            </div>

            <div class="header-right">
                <!-- Live 2-Hour Auto-Logout Timer (Left of Current Date) -->
                <div class="header-session-timer-badge" id="header-session-timer-badge" title="Auto-logout in 2 hours from login (Fixed session)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span><span class="timer-label">Logout in: </span><strong id="session-countdown-timer">--:--:--</strong></span>
                </div>

                <div class="header-date-badge">
                    <?php echo date('D, d M Y'); ?>
                </div>

                <button type="button" class="theme-toggle-btn" id="theme-toggle-btn" title="Toggle Light / Dark Mode">
                    <span id="theme-icon">
                        <!-- Default Moon for Dark -->
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </span>
                </button>
            </div>
        </header>

        <script>
        (function() {
            let remaining = <?php echo (int)$remaining_session_seconds; ?>;
            const timerElem = document.getElementById('session-countdown-timer');
            const badgeElem = document.getElementById('header-session-timer-badge');
            if (!timerElem) return;

            function formatTime(sec) {
                if (sec <= 0) return '00:00:00';
                const h = Math.floor(sec / 3600);
                const m = Math.floor((sec % 3600) / 60);
                const s = sec % 60;
                return (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }

            function tick() {
                if (remaining <= 0) {
                    timerElem.textContent = '00:00:00';
                    if (badgeElem) {
                        badgeElem.classList.remove('warning');
                        badgeElem.classList.add('danger');
                    }
                    window.location.href = '<?php echo BASE_URL; ?>/login.php?session_expired=1';
                    return;
                }

                timerElem.textContent = formatTime(remaining);

                if (badgeElem) {
                    if (remaining <= 300) {
                        badgeElem.classList.remove('warning');
                        badgeElem.classList.add('danger');
                    } else if (remaining <= 900) {
                        badgeElem.classList.add('warning');
                        badgeElem.classList.remove('danger');
                    } else {
                        badgeElem.classList.remove('warning', 'danger');
                    }
                }

                remaining--;
                setTimeout(tick, 1000);
            }

            tick();
        })();
        </script>

        <main class="app-content">
            <?php echo display_flash_message(); ?>
