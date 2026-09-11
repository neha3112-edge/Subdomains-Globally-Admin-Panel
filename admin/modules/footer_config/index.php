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

    // AI Tools: build JSON from structured POST fields
    $ai_cards = [];
    $tool_titles    = $_POST['tool_title']    ?? [];
    $tool_descs     = $_POST['tool_desc']     ?? [];
    $tool_btn_texts = $_POST['tool_btn_text'] ?? [];
    $tool_btn_links = $_POST['tool_btn_link'] ?? [];
    $tool_svgs      = $_POST['tool_svg']      ?? [];
    foreach ($tool_titles as $i => $title) {
        $title = trim($title);
        if ($title === '') continue;
        $ai_cards[] = [
            'icon_svg' => trim($tool_svgs[$i]      ?? ''),
            'title'    => $title,
            'desc'     => trim($tool_descs[$i]     ?? ''),
            'btn_text' => trim($tool_btn_texts[$i] ?? ''),
            'btn_link' => trim($tool_btn_links[$i] ?? ''),
        ];
    }

    // Footer Links: build JSON from structured POST fields
    $footer_links = [];
    $link_labels   = $_POST['link_label'] ?? [];
    $link_urls     = $_POST['link_url']   ?? [];
    $link_classes  = $_POST['link_class'] ?? [];
    foreach ($link_labels as $i => $label) {
        $label = trim($label);
        if ($label === '') continue;
        $footer_links[] = [
            'label' => $label,
            'url'   => trim($link_urls[$i]    ?? '#'),
            'class' => trim($link_classes[$i] ?? ''),
        ];
    }

    $fields = [
        'cta_heading'          => trim($_POST['cta_heading']          ?? 'Having Doubts ? Talk to Experts'),
        'cta_subtext'          => trim($_POST['cta_subtext']          ?? ''),
        'cta_btn_text'         => trim($_POST['cta_btn_text']         ?? 'Book Free 1:1 Counseling'),
        'cta_btn_link'         => trim($_POST['cta_btn_link']         ?? '#'),
        'cta_btn_phone'        => trim($_POST['cta_btn_phone']        ?? ''),
        'ai_tools_heading'     => trim($_POST['ai_tools_heading']     ?? 'Explore AI Powered Tools'),
        'ai_tools_subtext'     => trim($_POST['ai_tools_subtext']     ?? ''),
        'ai_tools_cards_json'  => json_encode($ai_cards,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'about_logo_url'       => trim($_POST['about_logo_url']       ?? ''),
        'about_title'          => trim($_POST['about_title']          ?? 'About SODE™'),
        'about_subtitle'       => trim($_POST['about_subtitle']       ?? ''),
        'about_sode_text'      => trim($_POST['about_sode_text']      ?? ''),
        'legal_notice_heading' => trim($_POST['legal_notice_heading'] ?? 'Legal Notice'),
        'legal_notice_text'    => trim($_POST['legal_notice_text']    ?? ''),
        'footer_links_json'    => json_encode($footer_links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'copyright_text'       => trim($_POST['copyright_text']       ?? '© ' . date('Y') . ' SODE™ Counselling Services LLP'),
    ];

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
    redirect(BASE_URL . '/modules/footer_config/index.php?tab=' . ($_POST['active_tab'] ?? 'cta'));
}

// Fetch config
$config = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// Parse JSON arrays
$ai_tools    = [];
$footer_links = [];
if (!empty($config['ai_tools_cards_json'])) {
    $dec = json_decode($config['ai_tools_cards_json'], true);
    if (is_array($dec)) $ai_tools = $dec;
}
if (!empty($config['footer_links_json'])) {
    $dec = json_decode($config['footer_links_json'], true);
    if (is_array($dec)) $footer_links = $dec;
}

// Defaults
$d = [
    'cta_heading'          => 'Having Doubts ? Talk to Experts',
    'cta_subtext'          => 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
    'cta_btn_text'         => 'Book Free 1:1 Counseling',
    'cta_btn_link'         => '#',
    'cta_btn_phone'        => '',
    'ai_tools_heading'     => 'Explore AI Powered Tools',
    'ai_tools_subtext'     => 'Make smarter education decisions with AI-powered tools',
    'about_logo_url'       => '',
    'about_title'          => 'About SODE™',
    'about_subtitle'       => '(School of Online and Distance Education)',
    'about_sode_text'      => '',
    'legal_notice_heading' => 'Legal Notice',
    'legal_notice_text'    => '',
    'copyright_text'       => '© ' . date('Y') . ' SODE™ Counselling Services LLP',
];
foreach ($d as $k => $v) {
    if (!isset($config[$k]) || $config[$k] === '') $config[$k] = $v;
}

function fc_val($config, $key) { return htmlspecialchars($config[$key] ?? ''); }

$tab = $_GET['tab'] ?? 'cta';
if (!in_array($tab, ['cta', 'ai_tools', 'about', 'legal', 'links'])) $tab = 'cta';

$tabs_list = ['cta' => '📣 CTA Bar', 'ai_tools' => '🤖 AI Tools', 'about' => 'ℹ️ About SODE', 'legal' => '⚖️ Legal Notice', 'links' => '🔗 Footer Links'];

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
.fc-tab-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:24px; }
.fc-tab-bar a { text-decoration:none; }

/* Card builder rows */
.fc-item-list { display:flex; flex-direction:column; gap:14px; }
.fc-item-row {
    background: var(--bg-card, #1a2234);
    border: 1px solid var(--border-subtle, #2a3550);
    border-radius: 12px;
    padding: 18px 18px 14px;
    position: relative;
}
.fc-item-row-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.fc-item-row-num {
    font-size: 12px;
    font-weight: 700;
    color: var(--primary, #6366f1);
    text-transform: uppercase;
    letter-spacing: .5px;
}
.fc-remove-btn {
    background: rgba(239,68,68,.12);
    border: 1px solid rgba(239,68,68,.25);
    color: #ef4444;
    border-radius: 6px;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
    line-height: 1.6;
}
.fc-remove-btn:hover { background: rgba(239,68,68,.22); }
.fc-item-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.fc-item-grid.fc-3col { grid-template-columns:1fr 1fr 1fr; }
.fc-item-full { grid-column: 1 / -1; }
.fc-svg-preview {
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: var(--text-dim, #8899aa);
}
.fc-svg-preview .fc-preview-icon { width:36px; height:36px; }
.fc-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-card, #1a2234);
    border: 1.5px dashed var(--primary, #6366f1);
    color: var(--primary, #6366f1);
    border-radius: 10px;
    padding: 10px 20px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
    width: 100%;
    justify-content: center;
    margin-top: 8px;
}
.fc-add-btn:hover { background: rgba(99,102,241,.08); }
.fc-hint {
    font-size: 11.5px;
    color: var(--text-dim, #8899aa);
    margin-bottom: 12px;
    line-height: 1.5;
    padding: 8px 12px;
    background: rgba(99,102,241,.06);
    border-radius: 7px;
    border-left: 3px solid var(--primary, #6366f1);
}
</style>

<!-- Tab Nav -->
<div class="fc-tab-bar">
    <?php foreach ($tabs_list as $tk => $tl): ?>
        <a href="<?php echo BASE_URL; ?>/modules/footer_config/index.php?tab=<?php echo $tk; ?>"
           class="action-card" style="padding:10px 18px; <?php echo ($tab === $tk) ? 'border-color:var(--primary); background:var(--bg-card-hover);' : ''; ?>">
            <span class="action-title"><?php echo $tl; ?></span>
        </a>
    <?php endforeach; ?>
</div>

<form method="POST" action="" id="fc-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="active_tab" value="<?php echo $tab; ?>">

    <?php
    // Hidden fields for other tabs data preservation
    $all_tab_fields = ['cta_heading','cta_subtext','cta_btn_text','cta_btn_link','cta_btn_phone',
                       'ai_tools_heading','ai_tools_subtext',
                       'about_logo_url','about_title','about_subtitle','about_sode_text',
                       'legal_notice_heading','legal_notice_text','copyright_text'];
    $current_tab_fields = [
        'cta'      => ['cta_heading','cta_subtext','cta_btn_text','cta_btn_link','cta_btn_phone'],
        'ai_tools' => ['ai_tools_heading','ai_tools_subtext'],
        'about'    => ['about_logo_url','about_title','about_subtitle','about_sode_text'],
        'legal'    => ['legal_notice_heading','legal_notice_text','copyright_text'],
        'links'    => [],
    ];
    $active_fields = $current_tab_fields[$tab] ?? [];
    foreach ($all_tab_fields as $f) {
        if (!in_array($f, $active_fields)) {
            echo '<input type="hidden" name="' . $f . '" value="' . fc_val($config, $f) . '">';
        }
    }
    // When not on AI Tools tab, preserve ai_tools data as hidden JSON inputs
    if ($tab !== 'ai_tools') {
        foreach ($ai_tools as $i => $t) {
            echo '<input type="hidden" name="tool_title['.$i.']" value="'.htmlspecialchars($t['title']??'').'">';
            echo '<input type="hidden" name="tool_desc['.$i.']" value="'.htmlspecialchars($t['desc']??'').'">';
            echo '<input type="hidden" name="tool_btn_text['.$i.']" value="'.htmlspecialchars($t['btn_text']??'').'">';
            echo '<input type="hidden" name="tool_btn_link['.$i.']" value="'.htmlspecialchars($t['btn_link']??'').'">';
            echo '<input type="hidden" name="tool_svg['.$i.']" value="'.htmlspecialchars($t['icon_svg']??'').'">';
        }
    }
    // When not on Links tab, preserve links data as hidden JSON inputs
    if ($tab !== 'links') {
        foreach ($footer_links as $i => $l) {
            echo '<input type="hidden" name="link_label['.$i.']" value="'.htmlspecialchars($l['label']??'').'">';
            echo '<input type="hidden" name="link_url['.$i.']" value="'.htmlspecialchars($l['url']??'').'">';
            echo '<input type="hidden" name="link_class['.$i.']" value="'.htmlspecialchars($l['class']??'').'">';
        }
    }
    ?>

    <!-- ===== TAB: CTA BAR ===== -->
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
                <label class="form-label">Phone Number (optional — overrides Button Link with tel:)</label>
                <input type="text" name="cta_btn_phone" class="form-control" value="<?php echo fc_val($config,'cta_btn_phone'); ?>" placeholder="+91XXXXXXXXXX">
            </div>
        </div>
    </div>

    <!-- ===== TAB: AI TOOLS ===== -->
    <?php elseif ($tab === 'ai_tools'): ?>
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">AI Powered Tools Section</span>
        </div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:20px;">

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

            <div>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <label class="form-label" style="margin:0;">Tool Cards <span style="color:var(--text-dim); font-weight:400; font-size:12px;">(Desktop: &gt;4 → slider | Mobile: &gt;1 → slider)</span></label>
                </div>

                <div class="fc-item-list" id="ai-tools-list">
                    <?php foreach ($ai_tools as $i => $tool): ?>
                    <div class="fc-item-row" data-type="tool">
                        <div class="fc-item-row-header">
                            <span class="fc-item-row-num">Card #<span class="fc-num"><?php echo $i + 1; ?></span></span>
                            <button type="button" class="fc-remove-btn" onclick="removeRow(this)">✕ Remove</button>
                        </div>
                        <div class="fc-item-grid">
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;">Title *</label>
                                <input type="text" name="tool_title[]" class="form-control" value="<?php echo htmlspecialchars($tool['title']??''); ?>" placeholder="Suggest University" required>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;">Button Text</label>
                                <input type="text" name="tool_btn_text[]" class="form-control" value="<?php echo htmlspecialchars($tool['btn_text']??''); ?>" placeholder="Suggest Me A University →">
                            </div>
                            <div class="form-group fc-item-full" style="margin:0;">
                                <label class="form-label" style="font-size:12px;">Description</label>
                                <input type="text" name="tool_desc[]" class="form-control" value="<?php echo htmlspecialchars($tool['desc']??''); ?>" placeholder="Short description of this tool...">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;">Button Link</label>
                                <input type="text" name="tool_btn_link[]" class="form-control" value="<?php echo htmlspecialchars($tool['btn_link']??''); ?>" placeholder="#suggest-university">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:12px;">Icon SVG <span style="color:var(--text-dim); font-weight:400;">(raw &lt;svg&gt;...&lt;/svg&gt; — leave blank for default)</span></label>
                                <input type="text" name="tool_svg[]" class="form-control fc-svg-input" value="<?php echo htmlspecialchars($tool['icon_svg']??''); ?>" placeholder='<svg viewBox="0 0 64 64">...</svg>'>
                            </div>
                        </div>
                        <?php if (!empty($tool['icon_svg'])): ?>
                        <div class="fc-svg-preview">
                            <div class="fc-preview-icon"><?php echo $tool['icon_svg']; ?></div>
                            <span>Icon preview</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="fc-add-btn" onclick="addToolCard()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add AI Tool Card
                </button>
            </div>
        </div>
    </div>

    <!-- ===== TAB: ABOUT SODE ===== -->
    <?php elseif ($tab === 'about'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">About SODE™ Section</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div class="form-group">
                <label class="form-label">Logo URL <span style="color:var(--text-dim); font-weight:400;">(leave blank to use default SVG placeholder)</span></label>
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

    <!-- ===== TAB: LEGAL NOTICE ===== -->
    <?php elseif ($tab === 'legal'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">Legal Notice Section (Dark Bottom Bar)</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div class="form-group">
                <label class="form-label">Section Heading <span style="color:var(--text-dim); font-weight:400;">(shown in yellow above the text)</span></label>
                <input type="text" name="legal_notice_heading" class="form-control" value="<?php echo fc_val($config,'legal_notice_heading'); ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Legal Notice Body Text</label>
                <textarea name="legal_notice_text" class="form-textarea" rows="6"><?php echo fc_val($config,'legal_notice_text'); ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Copyright Text <span style="color:var(--text-dim); font-weight:400;">(shown at the very bottom)</span></label>
                <input type="text" name="copyright_text" class="form-control" value="<?php echo fc_val($config,'copyright_text'); ?>">
            </div>
        </div>
    </div>

    <!-- ===== TAB: FOOTER LINKS ===== -->
    <?php elseif ($tab === 'links'): ?>
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Footer Navigation Links</span>
        </div>
        <div class="card-body">
            <p class="fc-hint">
                💡 <strong>CSS Class</strong> field: leave blank for normal links. For legal popups use:
                <code>disclaimer-main-popup</code> | <code>privacy-main-popup</code> | <code>term-main-popup</code>
            </p>

            <div class="fc-item-list" id="links-list">
                <?php foreach ($footer_links as $i => $lnk): ?>
                <div class="fc-item-row" data-type="link">
                    <div class="fc-item-row-header">
                        <span class="fc-item-row-num">Link #<span class="fc-num"><?php echo $i + 1; ?></span></span>
                        <button type="button" class="fc-remove-btn" onclick="removeRow(this)">✕ Remove</button>
                    </div>
                    <div class="fc-item-grid fc-3col">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:12px;">Label *</label>
                            <input type="text" name="link_label[]" class="form-control" value="<?php echo htmlspecialchars($lnk['label']??''); ?>" placeholder="About Us" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:12px;">URL</label>
                            <input type="text" name="link_url[]" class="form-control" value="<?php echo htmlspecialchars($lnk['url']??'#'); ?>" placeholder="/about-us/">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:12px;">CSS Class <span style="color:var(--text-dim); font-weight:400;">(optional)</span></label>
                            <input type="text" name="link_class[]" class="form-control" value="<?php echo htmlspecialchars($lnk['class']??''); ?>" placeholder="disclaimer-main-popup">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="fc-add-btn" onclick="addFooterLink()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Footer Link
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:20px;">
        <button type="submit" class="btn-primary" style="width:auto; padding:12px 28px;">
            Save <?php echo $tabs_list[$tab] ?? 'Configuration'; ?>
        </button>
    </div>
</form>

<script>
function renumberRows(list) {
    list.querySelectorAll('.fc-num').forEach(function(el, i) { el.textContent = i + 1; });
}

function removeRow(btn) {
    var row = btn.closest('.fc-item-row');
    var list = row.parentElement;
    row.remove();
    renumberRows(list);
}

function addToolCard() {
    var list = document.getElementById('ai-tools-list');
    var count = list.children.length;
    var row = document.createElement('div');
    row.className = 'fc-item-row';
    row.setAttribute('data-type', 'tool');
    row.innerHTML = `
        <div class="fc-item-row-header">
            <span class="fc-item-row-num">Card #<span class="fc-num">${count + 1}</span></span>
            <button type="button" class="fc-remove-btn" onclick="removeRow(this)">✕ Remove</button>
        </div>
        <div class="fc-item-grid">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">Title *</label>
                <input type="text" name="tool_title[]" class="form-control" placeholder="Suggest University" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">Button Text</label>
                <input type="text" name="tool_btn_text[]" class="form-control" placeholder="Suggest Me A University →">
            </div>
            <div class="form-group fc-item-full" style="margin:0;">
                <label class="form-label" style="font-size:12px;">Description</label>
                <input type="text" name="tool_desc[]" class="form-control" placeholder="Short description of this tool...">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">Button Link</label>
                <input type="text" name="tool_btn_link[]" class="form-control" placeholder="#suggest-university">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">Icon SVG <span style="color:var(--text-dim); font-weight:400;">(raw &lt;svg&gt;...&lt;/svg&gt; — leave blank for default)</span></label>
                <input type="text" name="tool_svg[]" class="form-control" placeholder='<svg viewBox="0 0 64 64">...</svg>'>
            </div>
        </div>`;
    list.appendChild(row);
    row.querySelector('input[name="tool_title[]"]').focus();
}

function addFooterLink() {
    var list = document.getElementById('links-list');
    var count = list.children.length;
    var row = document.createElement('div');
    row.className = 'fc-item-row';
    row.setAttribute('data-type', 'link');
    row.innerHTML = `
        <div class="fc-item-row-header">
            <span class="fc-item-row-num">Link #<span class="fc-num">${count + 1}</span></span>
            <button type="button" class="fc-remove-btn" onclick="removeRow(this)">✕ Remove</button>
        </div>
        <div class="fc-item-grid fc-3col">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">Label *</label>
                <input type="text" name="link_label[]" class="form-control" placeholder="About Us" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">URL</label>
                <input type="text" name="link_url[]" class="form-control" placeholder="/about-us/">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:12px;">CSS Class <span style="color:var(--text-dim); font-weight:400;">(optional)</span></label>
                <input type="text" name="link_class[]" class="form-control" placeholder="disclaimer-main-popup">
            </div>
        </div>`;
    list.appendChild(row);
    row.querySelector('input[name="link_label[]"]').focus();
}
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
