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

    // AI Tools: build JSON from structured POST fields (preserving sort_order)
    $ai_cards = [];
    $tool_titles    = $_POST['tool_title']    ?? [];
    $tool_descs     = $_POST['tool_desc']     ?? [];
    $tool_btn_texts = $_POST['tool_btn_text'] ?? [];
    $tool_btn_links = $_POST['tool_btn_link'] ?? [];
    $tool_svgs      = $_POST['tool_svg']      ?? [];
    $tool_orders    = $_POST['tool_order']    ?? [];
    foreach ($tool_titles as $i => $title) {
        $title = trim($title);
        if ($title === '') continue;
        $ai_cards[] = [
            'sort_order' => (int)($tool_orders[$i] ?? ($i + 1)),
            'icon_svg'   => trim($tool_svgs[$i]      ?? ''),
            'title'      => $title,
            'desc'       => trim($tool_descs[$i]     ?? ''),
            'btn_text'   => trim($tool_btn_texts[$i] ?? ''),
            'btn_link'   => trim($tool_btn_links[$i] ?? ''),
        ];
    }
    usort($ai_cards, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

    // Footer Links: build JSON
    $footer_links = [];
    $link_labels   = $_POST['link_label']   ?? [];
    $link_urls     = $_POST['link_url']     ?? [];
    $link_classes  = $_POST['link_class']   ?? [];
    $link_newtabs  = $_POST['link_newtab']  ?? [];
    $link_orders   = $_POST['link_order']   ?? [];
    foreach ($link_labels as $i => $label) {
        $label = trim($label);
        if ($label === '') continue;
        $footer_links[] = [
            'sort_order' => (int)($link_orders[$i] ?? ($i + 1)),
            'label'      => $label,
            'url'        => trim($link_urls[$i]    ?? '#'),
            'class'      => trim($link_classes[$i] ?? ''),
            'new_tab'    => isset($link_newtabs[$i]) ? 1 : 0,
        ];
    }
    usort($footer_links, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

    $fields = [
        'cta_heading'          => trim($_POST['cta_heading']          ?? 'Having Doubts ? Talk to Experts'),
        'cta_subtext'          => trim($_POST['cta_subtext']          ?? ''),
        'cta_btn_text'         => trim($_POST['cta_btn_text']         ?? 'Book Free 1:1 Counseling'),
        'cta_btn_link'         => trim($_POST['cta_btn_link']         ?? '#'),
        'cta_btn_phone'        => trim($_POST['cta_btn_phone']        ?? ''),
        'ai_tools_heading'     => trim($_POST['ai_tools_heading']     ?? 'Explore AI Powered Tools'),
        'ai_tools_subtext'     => trim($_POST['ai_tools_subtext']     ?? ''),
        'ai_tools_cards_json'  => json_encode($ai_cards,      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'about_logo_url'       => get_relative_asset_path(trim($_POST['about_logo_url'] ?? '')),
        'about_title'          => trim($_POST['about_title']          ?? 'About SODE™'),
        'about_subtitle'       => trim($_POST['about_subtitle']       ?? ''),
        'about_sode_text'      => trim($_POST['about_sode_text']      ?? ''),
        'legal_notice_heading' => trim($_POST['legal_notice_heading'] ?? 'Legal Notice'),
        'legal_notice_text'    => trim($_POST['legal_notice_text']    ?? ''),
        'footer_links_json'    => json_encode($footer_links,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'copyright_text'       => trim($_POST['copyright_text']       ?? '© ' . date('Y') . ' SODE™ Counselling Services LLP'),
    ];

    $db->exec("CREATE TABLE IF NOT EXISTS footer_config (id INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $set_parts = []; $vals = [];
    foreach ($fields as $k => $v) { $set_parts[] = "`$k` = ?"; $vals[] = $v; }
    $db->prepare("INSERT INTO footer_config (id) VALUES(1) ON DUPLICATE KEY UPDATE id=1")->execute();
    $db->prepare("UPDATE footer_config SET " . implode(', ', $set_parts) . " WHERE id = 1")->execute($vals);
    set_flash_message('Footer configuration updated successfully!', 'success');
    redirect(BASE_URL . '/modules/footer_config/index.php?tab=' . ($_POST['active_tab'] ?? 'cta'));
}

// Fetch config
$config = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// Parse JSON
$ai_tools     = [];
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
.fc-hint { font-size:11.5px; color:var(--text-dim); margin-bottom:14px; line-height:1.5; padding:8px 12px; background:rgba(99,102,241,.06); border-radius:7px; border-left:3px solid var(--primary); }
.fc-hint code { background:rgba(255,255,255,.08); padding:1px 5px; border-radius:4px; font-size:11px; }

/* ===== AI TOOL CARDS ===== */
.fc-tool-list { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media(max-width:900px) { .fc-tool-list { grid-template-columns:1fr; } }
.fc-tool-card {
    background: var(--bg-card, #161f35);
    border: 1.5px solid var(--border-subtle, #2a3550);
    border-radius: 12px;
    padding: 16px;
    position: relative;
    transition: border-color .2s;
}
.fc-tool-card:hover { border-color: var(--primary, #6366f1); }
.fc-tool-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    gap: 8px;
}
.fc-card-left { display:flex; align-items:center; gap:10px; }
.fc-order-input {
    width: 48px;
    text-align: center;
    padding: 4px 6px;
    border-radius: 6px;
    border: 1.5px solid var(--border-subtle, #2a3550);
    background: var(--bg-input, #0d1526);
    color: var(--text-main, #e0e8f0);
    font-size: 12px;
    font-weight: 700;
}
.fc-card-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--primary, #6366f1);
    text-transform: uppercase;
    letter-spacing: .5px;
}
.fc-remove-btn {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.2);
    color: #ef4444;
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s;
    flex-shrink: 0;
}
.fc-remove-btn:hover { background: rgba(239,68,68,.2); }
.fc-field-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.fc-field-full { grid-column:1/-1; }
.fc-add-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: transparent;
    border: 1.5px dashed var(--primary, #6366f1);
    color: var(--primary, #6366f1);
    border-radius: 10px; padding: 10px 20px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    transition: background .15s; width:100%; justify-content:center; margin-top:10px;
}
.fc-add-btn:hover { background: rgba(99,102,241,.08); }

/* ===== LINK LIST — compact rows ===== */
.fc-link-row {
    display: grid;
    grid-template-columns: 48px 1fr 1fr 1fr 80px 36px;
    gap: 8px;
    align-items: center;
    background: var(--bg-card, #161f35);
    border: 1px solid var(--border-subtle, #2a3550);
    border-radius: 8px;
    padding: 8px 10px;
    margin-bottom: 6px;
}
.fc-link-row:hover { border-color: var(--primary, #6366f1); }
.fc-link-col-label { font-size:10px; color:var(--text-dim); font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
.fc-link-col input { width:100%; }
.fc-newtab-wrap { display:flex; flex-direction:column; align-items:center; gap:3px; }
.fc-newtab-wrap label { font-size:10px; color:var(--text-dim); }
.fc-link-rows-header {
    display: grid;
    grid-template-columns: 48px 1fr 1fr 1fr 80px 36px;
    gap: 8px;
    padding: 4px 10px;
    margin-bottom: 4px;
}
.fc-link-rows-header span { font-size:10px; color:var(--text-dim); font-weight:700; text-transform:uppercase; letter-spacing:.4px; }
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

<form method="POST" action="" id="fc-form" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="active_tab" value="<?php echo $tab; ?>">

    <?php
    // Preserve other tabs' scalar fields
    $all_scalar = ['cta_heading','cta_subtext','cta_btn_text','cta_btn_link','cta_btn_phone',
                   'ai_tools_heading','ai_tools_subtext',
                   'about_logo_url','about_title','about_subtitle','about_sode_text',
                   'legal_notice_heading','legal_notice_text','copyright_text'];
    $tab_scalars = [
        'cta'      => ['cta_heading','cta_subtext','cta_btn_text','cta_btn_link','cta_btn_phone'],
        'ai_tools' => ['ai_tools_heading','ai_tools_subtext'],
        'about'    => ['about_logo_url','about_title','about_subtitle','about_sode_text'],
        'legal'    => ['legal_notice_heading','legal_notice_text','copyright_text'],
        'links'    => [],
    ];
    foreach ($all_scalar as $f) {
        if (!in_array($f, $tab_scalars[$tab] ?? [])) {
            echo '<input type="hidden" name="'.$f.'" value="'.fc_val($config,$f).'">';
        }
    }
    // Preserve AI tools when not on ai_tools tab
    if ($tab !== 'ai_tools') {
        foreach ($ai_tools as $i => $t) {
            echo '<input type="hidden" name="tool_order['.$i.']" value="'.htmlspecialchars($t['sort_order']??($i+1)).'">';
            echo '<input type="hidden" name="tool_title['.$i.']" value="'.htmlspecialchars($t['title']??'').'">';
            echo '<input type="hidden" name="tool_desc['.$i.']" value="'.htmlspecialchars($t['desc']??'').'">';
            echo '<input type="hidden" name="tool_btn_text['.$i.']" value="'.htmlspecialchars($t['btn_text']??'').'">';
            echo '<input type="hidden" name="tool_btn_link['.$i.']" value="'.htmlspecialchars($t['btn_link']??'').'">';
            echo '<input type="hidden" name="tool_svg['.$i.']" value="'.htmlspecialchars($t['icon_svg']??'').'">';
        }
    }
    // Preserve links when not on links tab
    if ($tab !== 'links') {
        foreach ($footer_links as $i => $l) {
            echo '<input type="hidden" name="link_order['.$i.']" value="'.htmlspecialchars($l['sort_order']??($i+1)).'">';
            echo '<input type="hidden" name="link_label['.$i.']" value="'.htmlspecialchars($l['label']??'').'">';
            echo '<input type="hidden" name="link_url['.$i.']" value="'.htmlspecialchars($l['url']??'').'">';
            echo '<input type="hidden" name="link_class['.$i.']" value="'.htmlspecialchars($l['class']??'').'">';
            if (!empty($l['new_tab'])) echo '<input type="hidden" name="link_newtab['.$i.']" value="1">';
        }
    }
    ?>

    <!-- ===== CTA BAR ===== -->
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
                <label class="form-label">Phone Number <span style="color:var(--text-dim); font-weight:400;">(optional — overrides Button Link with tel:)</span></label>
                <input type="text" name="cta_btn_phone" class="form-control" value="<?php echo fc_val($config,'cta_btn_phone'); ?>" placeholder="+91XXXXXXXXXX">
            </div>
        </div>
    </div>

    <!-- ===== AI TOOLS ===== -->
    <?php elseif ($tab === 'ai_tools'): ?>
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">AI Powered Tools Section</span>
        </div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:18px;">
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
                    <label class="form-label" style="margin:0;">Tool Cards
                        <span style="color:var(--text-dim); font-weight:400; font-size:11px;">
                            — Desktop: &gt;4 cards → slider &nbsp;|&nbsp; Mobile: &gt;1 → slider
                        </span>
                    </label>
                </div>
                <p class="fc-hint">
                    💡 <strong>Order #</strong> — change the number to reorder cards.
                    &nbsp;|&nbsp; <strong>Icon SVG</strong> — paste raw <code>&lt;svg&gt;...&lt;/svg&gt;</code>, or leave blank for placeholder.
                </p>

                <div class="fc-tool-list" id="ai-tools-list">
                    <?php foreach ($ai_tools as $i => $tool): ?>
                    <div class="fc-tool-card">
                        <div class="fc-tool-card-header">
                            <div class="fc-card-left">
                                <input type="number" name="tool_order[]" class="fc-order-input" value="<?php echo (int)($tool['sort_order'] ?? $i + 1); ?>" min="1" title="Order / Position">
                                <span class="fc-card-label">Card</span>
                                <?php if (!empty($tool['icon_svg'])): ?>
                                <div style="width:28px;height:28px;flex-shrink:0;"><?php echo $tool['icon_svg']; ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="fc-remove-btn" onclick="this.closest('.fc-tool-card').remove()">✕ Remove</button>
                        </div>
                        <div class="fc-field-row">
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:11.5px;">Title *</label>
                                <input type="text" name="tool_title[]" class="form-control" value="<?php echo htmlspecialchars($tool['title']??''); ?>" placeholder="Suggest University" required>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:11.5px;">Button Text</label>
                                <input type="text" name="tool_btn_text[]" class="form-control" value="<?php echo htmlspecialchars($tool['btn_text']??''); ?>" placeholder="Suggest Me A University →">
                            </div>
                            <div class="form-group fc-field-full" style="margin:0;">
                                <label class="form-label" style="font-size:11.5px;">Description</label>
                                <input type="text" name="tool_desc[]" class="form-control" value="<?php echo htmlspecialchars($tool['desc']??''); ?>" placeholder="Short one-line description...">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:11.5px;">Button Link</label>
                                <input type="text" name="tool_btn_link[]" class="form-control" value="<?php echo htmlspecialchars($tool['btn_link']??''); ?>" placeholder="#suggest-university">
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label class="form-label" style="font-size:11.5px;">Icon SVG <span style="color:var(--text-dim); font-weight:400;">(optional)</span></label>
                                <input type="text" name="tool_svg[]" class="form-control" value="<?php echo htmlspecialchars($tool['icon_svg']??''); ?>" placeholder='<svg viewBox="0 0 64 64">...</svg>'>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="fc-add-btn" onclick="addToolCard()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add AI Tool Card
                </button>
            </div>
        </div>
    </div>

    <!-- ===== ABOUT SODE ===== -->
    <?php elseif ($tab === 'about'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">About SODE™ Section</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <div class="form-group">
                <label class="form-label">About SODE Logo</label>
                <div class="media-input-group">
                    <input type="text" name="about_logo_url" id="field_about_logo" class="form-control"
                        value="<?php echo fc_val($config,'about_logo_url'); ?>" placeholder="/assets/images/sode-logo.png">
                    <button type="button" class="btn-media-choose media-picker-btn"
                        data-target="field_about_logo" data-preview="preview_about_logo" data-type="image">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                        Choose / Upload
                    </button>
                </div>
                <div class="media-preview-inline" id="preview_about_logo"
                    style="margin-top:6px; <?php echo empty($config['about_logo_url']) ? 'display:none;' : ''; ?>">
                    <?php if (!empty($config['about_logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars(get_asset_url($config['about_logo_url'])); ?>" alt="Logo preview"
                            style="height:48px; width:auto; object-fit:contain; background:#fff; border-radius:6px; padding:4px; border:1px solid var(--border-color);">
                    <?php endif; ?>
                </div>
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

    <!-- ===== LEGAL NOTICE ===== -->
    <?php elseif ($tab === 'legal'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">Legal Notice Section (Dark Bottom Bar)</span></div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:16px;">
            <p class="fc-hint">
                💡 In the Legal Notice text, use <code>{official_url}</code> as a placeholder — it will automatically be replaced with each university's Official Portal URL (linked) when rendered on the subdomain.
                <br>Example: <em>...please visit <code>{official_url}</code> for more info.</em>
            </p>
            <div class="form-group">
                <label class="form-label">Section Heading <span style="color:var(--text-dim); font-weight:400;">(shown in yellow)</span></label>
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

    <!-- ===== FOOTER LINKS ===== -->
    <?php elseif ($tab === 'links'): ?>
    <div class="admin-card">
        <div class="card-header"><span class="card-title">Footer Navigation Links</span></div>
        <div class="card-body">
            <p class="fc-hint">
                💡 <strong>Order #</strong> — change to reorder links. &nbsp;|&nbsp;
                <strong>CSS Class</strong> (optional): <code>disclaimer-main-popup</code> | <code>privacy-main-popup</code> | <code>term-main-popup</code>. &nbsp;|&nbsp;
                <strong>New Tab</strong> — check to open in <code>target="_blank"</code>.
            </p>

            <!-- Column headers -->
            <div class="fc-link-rows-header">
                <span>Order</span>
                <span>Label</span>
                <span>URL</span>
                <span>CSS Class</span>
                <span style="text-align:center;">New Tab</span>
                <span></span>
            </div>

            <div id="links-list">
                <?php foreach ($footer_links as $i => $lnk): ?>
                <div class="fc-link-row">
                    <input type="number" name="link_order[]" class="form-control fc-order-input" value="<?php echo (int)($lnk['sort_order'] ?? $i+1); ?>" min="1" title="Order">
                    <input type="text" name="link_label[]" class="form-control" value="<?php echo htmlspecialchars($lnk['label']??''); ?>" placeholder="Label" required>
                    <input type="text" name="link_url[]" class="form-control" value="<?php echo htmlspecialchars($lnk['url']??'#'); ?>" placeholder="/about-us/">
                    <input type="text" name="link_class[]" class="form-control" value="<?php echo htmlspecialchars($lnk['class']??''); ?>" placeholder="css-class (optional)">
                    <div class="fc-newtab-wrap">
                        <label style="font-size:10px; color:var(--text-dim);">New Tab</label>
                        <input type="checkbox" name="link_newtab[<?php echo $i; ?>]" value="1" <?php echo !empty($lnk['new_tab']) ? 'checked' : ''; ?> style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary);">
                    </div>
                    <button type="button" class="fc-remove-btn" onclick="this.closest('.fc-link-row').remove()" style="padding:5px 8px;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="fc-add-btn" onclick="addFooterLink()" style="margin-top:8px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
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
var toolCount = <?php echo count($ai_tools); ?>;
var linkCount = <?php echo count($footer_links); ?>;

function addToolCard() {
    toolCount++;
    var list = document.getElementById('ai-tools-list');
    var div = document.createElement('div');
    div.className = 'fc-tool-card';
    div.innerHTML = `
        <div class="fc-tool-card-header">
            <div class="fc-card-left">
                <input type="number" name="tool_order[]" class="fc-order-input" value="${toolCount}" min="1" title="Order">
                <span class="fc-card-label">Card</span>
            </div>
            <button type="button" class="fc-remove-btn" onclick="this.closest('.fc-tool-card').remove()">✕ Remove</button>
        </div>
        <div class="fc-field-row">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11.5px;">Title *</label>
                <input type="text" name="tool_title[]" class="form-control" placeholder="e.g. Suggest University" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11.5px;">Button Text</label>
                <input type="text" name="tool_btn_text[]" class="form-control" placeholder="e.g. Suggest Me A University →">
            </div>
            <div class="form-group fc-field-full" style="margin:0;">
                <label class="form-label" style="font-size:11.5px;">Description</label>
                <input type="text" name="tool_desc[]" class="form-control" placeholder="Short one-line description...">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11.5px;">Button Link</label>
                <input type="text" name="tool_btn_link[]" class="form-control" placeholder="#suggest-university">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11.5px;">Icon SVG <span style="color:var(--text-dim); font-weight:400;">(optional)</span></label>
                <input type="text" name="tool_svg[]" class="form-control" placeholder='&lt;svg viewBox="0 0 64 64"&gt;...&lt;/svg&gt;'>
            </div>
        </div>`;
    list.appendChild(div);
    div.querySelector('input[name="tool_title[]"]').focus();
}

function addFooterLink() {
    linkCount++;
    var list = document.getElementById('links-list');
    var div = document.createElement('div');
    div.className = 'fc-link-row';
    div.innerHTML = `
        <input type="number" name="link_order[]" class="form-control fc-order-input" value="${linkCount}" min="1">
        <input type="text" name="link_label[]" class="form-control" placeholder="Label" required>
        <input type="text" name="link_url[]" class="form-control" placeholder="/about-us/">
        <input type="text" name="link_class[]" class="form-control" placeholder="css-class (optional)">
        <div class="fc-newtab-wrap">
            <label style="font-size:10px; color:var(--text-dim);">New Tab</label>
            <input type="checkbox" name="link_newtab[new_${linkCount}]" value="1" style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary);">
        </div>
        <button type="button" class="fc-remove-btn" onclick="this.closest('.fc-link-row').remove()" style="padding:5px 8px;">✕</button>`;
    list.appendChild(div);
    div.querySelector('input[name="link_label[]"]').focus();
}
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
