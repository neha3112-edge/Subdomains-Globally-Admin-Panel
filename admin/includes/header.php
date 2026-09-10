<?php
/**
 * Admin Panel Top Header Navigation
 */
$current_user = get_logged_in_user();
$page_title = $page_title ?? 'Dashboard';
$page_subtitle = $page_subtitle ?? ('Welcome back, ' . htmlspecialchars($current_user['name']));
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

        <main class="app-content">
            <?php echo display_flash_message(); ?>
