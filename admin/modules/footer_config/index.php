<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('footer_config');

$page_title    = 'Footer & AI Tools';
$page_subtitle = 'Configure the universal subdomain footer, CTA bar, AI tool widgets, and copyright';
$active_page_key = 'footer_config';

$db = get_db_connection();

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $fields = [
        'cta_heading'          => trim($_POST['cta_heading']          ?? 'Having Doubts ? Talk to Experts'),
        'cta_subtext'          => trim($_POST['cta_subtext']          ?? ''),
        'cta_btn_text'         => trim($_POST['cta_btn_text']         ?? 'Book Free 1:1 Counseling'),
        'cta_btn_link'         => trim($_POST['cta_btn_link']         ?? '#'),
        'cta_btn_phone'        => trim($_POST['cta_btn_phone']        ?? ''),
        'ai_tools_heading'     => trim($_POST['ai_tools_heading']     ?? 'Explore AI Powered Tools'),
        'ai_tools_subtext'     => trim($_POST['ai_tools_subtext']     ?? ''),
        'ai_tools_cards_json'  => trim($_POST['ai_tools_cards_json']  ?? '[]'),
        'about_logo_url'       => trim($_POST['about_logo_url']       ?? ''),
        'about_title'          => trim($_POST['about_title']          ?? 'About SODE™'),
        'about_subtitle'       => trim($_POST['about_subtitle']       ?? ''),
        'about_sode_text'      => trim($_POST['about_sode_text']      ?? ''),
        'legal_notice_heading' => trim($_POST['legal_notice_heading'] ?? 'Legal Notice'),
        'legal_notice_text'    => trim($_POST['legal_notice_text']    ?? ''),
        'footer_links_json'    => trim($_POST['footer_links_json']    ?? '[]'),
        'copyright_text'       => trim($_POST['copyright_text']       ?? '© ' . date('Y') . ' SODE™ Counselling Services LLP'),
    ];

    $json_err = false;
    foreach (['ai_tools_cards_json', 'footer_links_json'] as $jf) {
        if (!empty($fields[$jf]) && json_decode($fields[$jf]) === null) {
            set_flash_message(ucwords(str_replace('_', ' ', $jf)) . ' has invalid JSON format.', 'error');
            $json_err = true;
            break;
        }
    }

    if (!$json_err) {
        $db->exec("CREATE TABLE IF NOT EXISTS footer_config (id INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $set_parts = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $set_parts[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = 1;
        $db->prepare("INSERT INTO footer_config (id) VALUES(1) ON DUPLICATE KEY UPDATE id=1")->execute();
        $db->prepare("UPDATE footer_config SET " . implode(', ', $set_parts) . " WHERE id = 1")->execute($vals);
        set_flash_message('Footer configuration updated successfully!', 'success');
        redirect(BASE_URL . '/modules/footer_config/index.php');
    }
}

// Fetch config
$config = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];

$tab = $_GET['tab'] ?? 'cta';
if (!in_array($tab, ['cta', 'ai_tools', 'about', 'legal', 'links'])) $tab = 'cta';

// Defaults
$d = [
    'cta_heading'          => 'Having Doubts ? Talk to Experts',
    'cta_subtext'          => 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
    'cta_btn_text'         => 'Book Free 1:1 Counseling',
    'cta_btn_link'         => '#',
    'cta_btn_phone'        => '',
    'ai_tools_heading'     => 'Explore AI Powered Tools',
    'ai_tools_subtext'     => 'Make smarter education decisions with AI-powered tools',
    'ai_tools_cards_json'  => '[]',
    'about_logo_url'       => '',
    'about_title'          => 'About SODE™',
    'about_subtitle'       => '(School of Online and Distance Education)',
    'about_sode_text'      => '',
    'legal_notice_heading' => 'Legal Notice',
    'legal_notice_text'    => '',
    'footer_links_json'    => json_encode([
        ['label' => 'About Us',          'url' => '/about-us/',        'class' => ''],
        ['label' => 'Contact Us',        'url' => '/contact-us/',      'class' => ''],
        ['label' => 'Disclaimer',        'url' => '#disclaimer-popup', 'class' => 'disclaimer-main-popup'],
        ['label' => 'Privacy Policy',    'url' => '#privacy-popup',    'class' => 'privacy-main-popup'],
        ['label' => 'Terms & Conditions','url' => '#terms-popup',      'class' => 'term-main-popup'],
    ], JSON_UNESCAPED_SLASHES),
    'copyright_text'       => '© ' . date('Y') . ' SODE™ Counselling Services LLP',
];
foreach ($d as $k => $v) {
    if (!isset($config[$k]) || $config[$k] === '') $config[$k] = $v;
}

function fc_val($config, $key) { return htmlspecialchars($config[$key] ?? ''); }

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Tab Nav -->
<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:24px;">
    <?php
    $tabs_list = ['cta' => '📣 CTA Bar', 'ai_tools' => '🤖 AI Tools', 'about' => 'ℹ️ About SODE', 'legal' => '⚖️ Legal Notice', 'links' => '🔗 Footer Links'];
    foreach ($tabs_list as $tk => $tl):
    ?>
        <a href="<?php echo BASE_URL; ?>/modules/footer_config/index.php?tab=<?php echo $tk; ?>"
           class="action-card" style="padding:10px 18px; <?php echo ($tab === $tk) ? 'border-color:var(--primary); background:var(--bg-card-hover);' : ''; ?>">
            <span class="action-title"><?php echo $tl; ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <!-- TAB: CTA Bar -->
    <?php if ($tab === 'cta'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">CTA Banner — "Having Doubts? Talk to Experts"</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div class="form-group">
                <label class="form-label">Heading *</label>
                <input type="text" name="cta_heading" class="form-control" value="<?php echo fc_val($config,'cta_heading'); ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Sub-text</label>
                <input type="text" name="cta_subtext" class="form-control" value="<?php echo fc_val($config,'cta_subtext'); ?>">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label class="form-label">Button Text *</label>
                    <input type="text" name="cta_btn_text" class="form-control" value="<?php echo fc_val($config,'cta_btn_text'); ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Button Link</label>
                    <input type="text" name="cta_btn_link" class="form-control" value="<?php echo fc_val($config,'cta_btn_link'); ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Phone Number (optional, for tel: link)</label>
                <input type="text" name="cta_btn_phone" class="form-control" value="<?php echo fc_val($config,'cta_btn_phone'); ?>" placeholder="+91XXXXXXXXXX">
            </div>
        </div>
    </div>

    <!-- TAB: AI Tools -->
    <?php elseif ($tab === 'ai_tools'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">AI Powered Tools Section</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label class="form-label">Section Heading</label>
                    <input type="text" name="ai_tools_heading" class="form-control" value="<?php echo fc_val($config,'ai_tools_heading'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Section Sub-text</label>
                    <input type="text" name="ai_tools_subtext" class="form-control" value="<?php echo fc_val($config,'ai_tools_subtext'); ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">AI Tool Cards (JSON Array) *</label>
                <p style="font-size:12px; color:var(--text-dim); margin-bottom:8px;">
                    Each card: <code>{"icon_svg":"...","title":"...","desc":"...","btn_text":"...","btn_link":"..."}</code>
                    &nbsp;|&nbsp; Desktop: &gt;4 cards → slider. Mobile: &gt;1 → slider.
                </p>
                <textarea name="ai_tools_cards_json" class="form-textarea" style="min-height:280px; font-family:monospace; font-size:12.5px; line-height:1.5;"><?php echo fc_val($config,'ai_tools_cards_json'); ?></textarea>
            </div>
        </div>
    </div>

    <!-- TAB: About SODE -->
    <?php elseif ($tab === 'about'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">About SODE™ Section</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div class="form-group">
                <label class="form-label">Logo URL (leave blank to use default)</label>
                <input type="text" name="about_logo_url" class="form-control" value="<?php echo fc_val($config,'about_logo_url'); ?>" placeholder="/assets/images/sode-logo.png">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label class="form-label">Title</label>
                    <input type="text" name="about_title" class="form-control" value="<?php echo fc_val($config,'about_title'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Subtitle</label>
                    <input type="text" name="about_subtitle" class="form-control" value="<?php echo fc_val($config,'about_subtitle'); ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Description Paragraph</label>
                <textarea name="about_sode_text" class="form-textarea" rows="5"><?php echo fc_val($config,'about_sode_text'); ?></textarea>
            </div>
        </div>
    </div>

    <!-- TAB: Legal Notice -->
    <?php elseif ($tab === 'legal'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">Legal Notice Section (Dark Bottom Bar)</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div class="form-group">
                <label class="form-label">Section Heading</label>
                <input type="text" name="legal_notice_heading" class="form-control" value="<?php echo fc_val($config,'legal_notice_heading'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Legal Notice Body Text</label>
                <textarea name="legal_notice_text" class="form-textarea" rows="6"><?php echo fc_val($config,'legal_notice_text'); ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Copyright Text</label>
                <input type="text" name="copyright_text" class="form-control" value="<?php echo fc_val($config,'copyright_text'); ?>">
            </div>
        </div>
    </div>

    <!-- TAB: Footer Links -->
    <?php elseif ($tab === 'links'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">Footer Navigation Links (JSON Array)</span></div>
        <div class="card-body">
            <p style="font-size:12px; color:var(--text-dim); margin-bottom:12px;">
                Format: <code>[{"label":"About Us","url":"/about-us/","class":""},{"label":"Disclaimer","url":"#disclaimer-popup","class":"disclaimer-main-popup"}]</code>
            </p>
            <div class="form-group">
                <textarea name="footer_links_json" class="form-textarea" style="min-height:200px; font-family:monospace; font-size:12.5px;"><?php echo fc_val($config,'footer_links_json'); ?></textarea>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hidden fields to preserve other tabs' data on save -->
    <?php
    $all_tabs_fields = ['cta_heading','cta_subtext','cta_btn_text','cta_btn_link','cta_btn_phone',
                        'ai_tools_heading','ai_tools_subtext','ai_tools_cards_json',
                        'about_logo_url','about_title','about_subtitle','about_sode_text',
                        'legal_notice_heading','legal_notice_text','footer_links_json','copyright_text'];
    $current_tab_fields = [
        'cta'      => ['cta_heading','cta_subtext','cta_btn_text','cta_btn_link','cta_btn_phone'],
        'ai_tools' => ['ai_tools_heading','ai_tools_subtext','ai_tools_cards_json'],
        'about'    => ['about_logo_url','about_title','about_subtitle','about_sode_text'],
        'legal'    => ['legal_notice_heading','legal_notice_text','copyright_text'],
        'links'    => ['footer_links_json'],
    ];
    $active_fields = $current_tab_fields[$tab] ?? [];
    foreach ($all_tabs_fields as $f) {
        if (!in_array($f, $active_fields)) {
            echo '<input type="hidden" name="' . $f . '" value="' . fc_val($config, $f) . '">';
        }
    }
    ?>

    <div style="margin-top:20px;">
        <button type="submit" class="btn-primary" style="width:auto; padding:12px 28px;">
            Save <?php echo $tabs_list[$tab] ?? 'Configuration'; ?>
        </button>
    </div>
</form>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
