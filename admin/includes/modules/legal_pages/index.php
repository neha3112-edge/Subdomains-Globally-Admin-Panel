<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('legal_pages');

$can_edit = user_can('update') || user_can('write');

$page_title = 'Legal Popups';
$page_subtitle = 'Manage Disclaimer, Privacy Policy, and Terms & Conditions popup modals';
$active_page_key = 'legal_pages';

$db = get_db_connection();

$tab = $_GET['tab'] ?? 'disclaimer';
if (!in_array($tab, ['disclaimer', 'privacy_policy', 'terms_conditions'])) {
    $tab = 'disclaimer';
}

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$can_edit) {
        set_flash_message('Access Denied: You do not have permission to modify legal pages.', 'error');
        redirect(BASE_URL . '/modules/legal_pages/index.php?tab=' . $tab);
    }

    $heading = trim($_POST['heading'] ?? '');
    $content_html = trim($_POST['content_html'] ?? '');

    if (empty($heading) || empty($content_html)) {
        set_flash_message('Heading and HTML Content are required.', 'error');
    } else {
        $stmt = $db->prepare("
            INSERT INTO legal_pages (page_type, heading, content_html) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE heading = VALUES(heading), content_html = VALUES(content_html)
        ");
        $stmt->execute([$tab, $heading, $content_html]);
        set_flash_message(ucwords(str_replace('_', ' ', $tab)) . ' updated successfully!', 'success');
        redirect(BASE_URL . '/modules/legal_pages/index.php?tab=' . $tab);
    }
}

// Fetch content
$stmt = $db->prepare("SELECT * FROM legal_pages WHERE page_type = ?");
$stmt->execute([$tab]);
$page_data = $stmt->fetch();

$default_headings = [
    'disclaimer' => 'Disclaimer & Clarification',
    'privacy_policy' => 'Privacy Policy & Data Protection',
    'terms_conditions' => 'Terms of Use & Conditions'
];

$heading_val = $page_data['heading'] ?? ($default_headings[$tab] ?? '');
$content_val = $page_data['content_html'] ?? '';

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Tab Switcher -->
<div style="display:flex; gap:12px; margin-bottom:24px;">
    <a href="<?php echo BASE_URL; ?>/modules/legal_pages/index.php?tab=disclaimer" class="action-card" style="padding:12px 20px; <?php echo ($tab === 'disclaimer') ? 'border-color:var(--primary); background:var(--bg-card-hover);' : ''; ?>">
        <span class="action-title">Disclaimer</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/modules/legal_pages/index.php?tab=privacy_policy" class="action-card" style="padding:12px 20px; <?php echo ($tab === 'privacy_policy') ? 'border-color:var(--primary); background:var(--bg-card-hover);' : ''; ?>">
        <span class="action-title">Privacy Policy</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/modules/legal_pages/index.php?tab=terms_conditions" class="action-card" style="padding:12px 20px; <?php echo ($tab === 'terms_conditions') ? 'border-color:var(--primary); background:var(--bg-card-hover);' : ''; ?>">
        <span class="action-title">Terms & Conditions</span>
    </a>
</div>

<div class="admin-card">
    <div class="card-header">
        <span class="card-title">Edit <?php echo ucwords(str_replace('_', ' ', $tab)); ?> Popup Content</span>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label class="form-label">Modal Heading *</label>
                <input type="text" name="heading" class="form-control" value="<?php echo htmlspecialchars($heading_val); ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">HTML Content Body *</label>
                <textarea name="content_html" class="form-textarea" style="min-height:350px; font-family:monospace; font-size:13px; line-height:1.6;" placeholder="<p>Enter formatted HTML content...</p>" required><?php echo htmlspecialchars($content_val); ?></textarea>
            </div>

            <div style="display:flex; justify-content:flex-end;">
                <?php if ($can_edit): ?>
                <button type="submit" class="btn-primary" style="width:auto; padding:12px 28px;">
                    Save <?php echo ucwords(str_replace('_', ' ', $tab)); ?>
                </button>
                <?php else: ?>
                <button type="button" class="btn-primary btn-disabled-locked" title="Access Denied: You do not have permission to modify legal pages" disabled style="width:auto; padding:12px 28px; display:inline-flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    Save <?php echo ucwords(str_replace('_', ' ', $tab)); ?> (Locked)
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
