<?php
/**
 * Dynamic DB-Driven Admin Sidebar
 */
$current_user = get_logged_in_user();
$sidebar_items = get_user_sidebar_items();

// Group items by menu_section
$grouped_items = [];
foreach ($sidebar_items as $item) {
    $section = !empty($item['menu_section']) ? strtoupper($item['menu_section']) : 'MANAGE';
    $grouped_items[$section][] = $item;
}

$current_page_key = $active_page_key ?? 'dashboard';
?>
<aside class="app-sidebar" id="app-sidebar">
    <div class="sidebar-header">
        <div class="brand-logo-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
            </svg>
        </div>
        <span class="brand-title"><?php echo htmlspecialchars(APP_NAME); ?></span>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($grouped_items as $section_title => $items): ?>
            <div class="nav-section">
                <div class="nav-section-title"><?php echo htmlspecialchars($section_title); ?></div>
                <ul class="nav-list">
                    <?php foreach ($items as $item): 
                        $is_active = ($current_page_key === $item['active_page_key']);
                        $route = (strpos($item['page_route'], 'http') === 0) ? $item['page_route'] : BASE_URL . '/' . ltrim($item['page_route'], '/');
                    ?>
                        <li class="nav-item <?php echo $is_active ? 'active' : ''; ?>">
                            <a href="<?php echo htmlspecialchars($route); ?>">
                                <?php if (!empty($item['icon_svg'])): ?>
                                    <?php echo $item['icon_svg']; ?>
                                <?php else: ?>
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"></circle></svg>
                                <?php endif; ?>
                                <span><?php echo htmlspecialchars($item['display_name']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-user-footer">
        <div class="user-info-wrap">
            <div class="user-avatar">
                <?php echo htmlspecialchars(get_user_initials($current_user['name'])); ?>
            </div>
            <div class="user-details">
                <span class="user-name"><?php echo htmlspecialchars($current_user['name']); ?></span>
                <span class="user-role-badge"><?php echo htmlspecialchars($current_user['role_name']); ?></span>
            </div>
        </div>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="logout-link" title="Sign out">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </a>
    </div>
</aside>
