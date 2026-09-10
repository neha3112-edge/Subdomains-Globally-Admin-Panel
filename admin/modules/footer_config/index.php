<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('footer_config');

$page_title = 'Footer & AI Tools';
$page_subtitle = 'Configure the universal subdomain footer, AI tool widgets, and copyright';
$active_page_key = 'footer_config';

$db = get_db_connection();

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $about_sode_text = trim($_POST['about_sode_text'] ?? '');
    $legal_notice_text = trim($_POST['legal_notice_text'] ?? '');
    $copyright_text = trim($_POST['copyright_text'] ?? '© 2026 SODE™ Counseling Services LLP');
    $ai_tools_cards_json = trim($_POST['ai_tools_cards_json'] ?? '[]');

    // Test JSON validity
    if (!empty($ai_tools_cards_json) && json_decode($ai_tools_cards_json) === null) {
        set_flash_message('AI Tools JSON format is invalid.', 'error');
    } else {
        $stmt = $db->prepare("
            INSERT INTO footer_config (id, ai_tools_cards_json, about_sode_text, legal_notice_text, copyright_text) 
            VALUES (1, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                ai_tools_cards_json = VALUES(ai_tools_cards_json), 
                about_sode_text = VALUES(about_sode_text), 
                legal_notice_text = VALUES(legal_notice_text), 
                copyright_text = VALUES(copyright_text)
        ");
        $stmt->execute([$ai_tools_cards_json, $about_sode_text, $legal_notice_text, $copyright_text]);
        set_flash_message('Footer configuration updated successfully!', 'success');
        redirect(BASE_URL . '/modules/footer_config/index.php');
    }
}

// Fetch config
$config = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch();

require_once ADMIN_PATH . '/includes/header.php';
?>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:flex; flex-direction:column; gap:24px; max-width:850px;">
        <!-- General Footer Text -->
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title">1. Universal Footer Text & Copyright</span>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label class="form-label">Copyright Notice</label>
                    <input type="text" name="copyright_text" class="form-control" value="<?php echo htmlspecialchars($config['copyright_text'] ?? '© 2026 SODE™ Counseling Services LLP. All rights reserved.'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">About SODE Summary</label>
                    <textarea name="about_sode_text" class="form-textarea" placeholder="School of Online and Distance Education is an educational counseling partner..."><?php echo htmlspecialchars($config['about_sode_text'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Legal Notice & Trademark Disclaimer</label>
                    <textarea name="legal_notice_text" class="form-textarea" placeholder="SODE Counseling Services LLP is an independent education portal..."><?php echo htmlspecialchars($config['legal_notice_text'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- AI Tools Cards Config -->
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title">2. AI Tool Cards (JSON Schema)</span>
            </div>
            <div class="card-body">
                <p style="font-size:12.5px; color:var(--text-dim); margin-bottom:12px;">
                    Configure cards for AI College Predictor, Career Guidance AI, AI Course Matcher, etc.
                </p>
                <div class="form-group">
                    <textarea name="ai_tools_cards_json" class="form-textarea" style="min-height:220px; font-family:monospace; font-size:12.5px; line-height:1.5;"><?php echo htmlspecialchars($config['ai_tools_cards_json'] ?? '[]'); ?></textarea>
                </div>
            </div>
        </div>

        <div>
            <button type="submit" class="btn-primary" style="width:auto; padding:12px 28px;">
                Save Footer Configurations
            </button>
        </div>
    </div>
</form>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
