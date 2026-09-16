<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universal_news');

// Backwards compatibility: redirect edit_id to dedicated edit.php page
if (isset($_GET['edit_id'])) {
    redirect(BASE_URL . '/modules/universal_news/edit.php?id=' . (int)$_GET['edit_id']);
}

$page_title = 'Universal Announcements';
$page_subtitle = 'Manage global announcements that display across ALL university subdomains (Home Page & Inner Pages)';
$active_page_key = 'universal_news';

$db = get_db_connection();

// Ensure columns exist
try {
    $colDesc = $db->query("SHOW COLUMNS FROM news_items LIKE 'description'")->fetch();
    if (!$colDesc) {
        $db->exec("ALTER TABLE news_items ADD COLUMN description TEXT NULL AFTER news_text");
    }
    $colDate = $db->query("SHOW COLUMNS FROM news_items LIKE 'published_date'")->fetch();
    if (!$colDate) {
        $db->exec("ALTER TABLE news_items ADD COLUMN published_date VARCHAR(100) NULL AFTER description");
    }
} catch (Exception $e) {}

// Handle Delete & Toggle Status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM news_items WHERE id = ? AND is_global = 1");
            $stmt->execute([$id]);
            sode_bust_all_subdomain_caches($db);
            set_flash_message('Universal announcement deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/universal_news/index.php');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("UPDATE news_items SET is_active = IF(is_active=1, 0, 1) WHERE id = ? AND is_global = 1");
            $stmt->execute([$id]);
            sode_bust_all_subdomain_caches($db);
            set_flash_message('Announcement status updated!', 'success');
            redirect(BASE_URL . '/modules/universal_news/index.php');
        }
    }
}

// Fetch all Universal News items ONLY
$news_list = $db->query("
    SELECT * FROM news_items 
    WHERE is_global = 1 
    ORDER BY sort_order ASC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_announcements = count($news_list);
$preview_items = array_filter($news_list, function($n) { return !empty($n['is_active']); });

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Top Action Header -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; flex-wrap:wrap; gap:14px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:2px; font-size:18px; font-weight:800; color:var(--text-color);">
            Universal Announcements (<?php echo $total_announcements; ?>)
        </span>
        <div style="font-size:12.5px; color:var(--text-dim);">
            Global announcements displayed across all university subdomains (Universal news appears first, followed by university-specific news)
        </div>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
        <a href="<?php echo BASE_URL; ?>/modules/universal_news/create.php" class="btn-primary" style="padding:10px 20px; font-size:13.5px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:8px; box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add Universal Announcement
        </a>
    </div>
</div>

<!-- Shortcodes Quick Reference Strip -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-bottom: 22px;">
    <!-- Inner Page Shortcode Card -->
    <div style="background: rgba(37, 99, 235, 0.08); border: 1px solid rgba(37, 99, 235, 0.22); border-radius: 10px; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
        <div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #60a5fa; letter-spacing: 0.5px;">
                Inner Page Announcements Card
            </div>
            <div style="font-size: 13.5px; font-weight: 700; color: var(--text-color); margin-top: 3px; font-family: monospace;">
                [recent_announcements]
            </div>
        </div>
        <button type="button" class="btn-xs tag-pill-btn" onclick="navigator.clipboard.writeText('[recent_announcements]'); alert('Copied [recent_announcements] to clipboard!');">
            Copy Shortcode
        </button>
    </div>

    <!-- Home Page Marquee Shortcode Card -->
    <div style="background: rgba(243, 178, 62, 0.08); border: 1px solid rgba(243, 178, 62, 0.22); border-radius: 10px; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
        <div>
            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #f59e0b; letter-spacing: 0.5px;">
                Home Page Marquee Widget
            </div>
            <div style="font-size: 13.5px; font-weight: 700; color: var(--text-color); margin-top: 3px; font-family: monospace;">
                [latest_news]
            </div>
        </div>
        <button type="button" class="btn-xs tag-pill-btn" style="background: rgba(245, 158, 11, 0.15) !important; color: #fbbf24 !important; border-color: rgba(245, 158, 11, 0.35) !important;" onclick="navigator.clipboard.writeText('[latest_news]'); alert('Copied [latest_news] to clipboard!');">
            Copy Shortcode
        </button>
    </div>
</div>

<!-- Live Interactive Preview Drawer (Collapsible) -->
<div class="admin-card" style="margin-bottom: 24px;">
    <div class="card-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center;" onclick="togglePreviewDrawer()">
        <div style="display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
            <span class="card-title">Live Frontend Widget Preview</span>
            <span style="font-size: 11px; color: var(--text-dim);">(Click to expand / collapse)</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <div style="display: flex; gap: 6px;" onclick="event.stopPropagation();">
                <button type="button" id="tab-btn-inner" onclick="switchPreviewTab('inner')" class="btn-xs" style="background: #2563eb; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-weight:600;">
                    Inner Page Card
                </button>
                <button type="button" id="tab-btn-home" onclick="switchPreviewTab('home')" class="btn-xs" style="background: var(--bg-input); color:var(--text-color); border:1px solid var(--border-color); padding:4px 10px; border-radius:4px; cursor:pointer;">
                    Home Page Marquee
                </button>
            </div>
            <span id="preview-drawer-arrow" style="font-size: 14px; color: var(--text-dim); transition: transform 0.2s ease;">▼</span>
        </div>
    </div>

    <div id="preview-drawer-content" style="display: block;">
        <!-- Tab 1: Inner Page Announcements UI (Image 2 style) -->
        <div id="preview-tab-inner" class="card-body" style="padding: 24px;">
            <div style="max-width: 860px; margin: 0 auto; background: #eaf3ff; border: 1px solid #d2e5fc; border-radius: 16px; padding: 28px; color: #0c2340; box-shadow: 0 4px 15px rgba(37,99,235,0.06);">
                <h3 style="margin: 0 0 18px 0; font-size: 21px; font-weight: 800; color: #0c2340; letter-spacing: -0.3px;">
                    Recent Announcements
                </h3>
                
                <?php 
                $sample_preview = !empty($preview_items) ? array_slice($preview_items, 0, 3) : [
                    [
                        'news_text' => 'Admission Deadline Extended to 15th October 2026 for the latest academic session.',
                        'description' => 'Dayananda Sagar University Online has extended the admission deadline for the 2026-27 academic session. Students can submit the application form before 15th October 2026.',
                        'published_date' => 'September 12, 2026'
                    ],
                    [
                        'news_text' => 'New Specialization Launched in Online MBA — Business Analytics',
                        'description' => 'Dayananda Sagar University offers a new online MBA specialization for flexible learners. Online MBA now offers Business Analytics as one of the specializations.',
                        'published_date' => 'September 12, 2026'
                    ]
                ];
                $p_count = count($sample_preview);
                $p_idx = 0;
                foreach ($sample_preview as $si):
                    $p_idx++;
                    $s_title = str_replace(['{UNIVERSITY_NAME}', '{UNIVERSITY_SHORT_NAME}', '$YEAR$'], ['Dayananda Sagar University Online', 'DSU', '2026'], $si['news_text']);
                    $s_desc = str_replace(['{UNIVERSITY_NAME}', '{UNIVERSITY_SHORT_NAME}', '$YEAR$'], ['Dayananda Sagar University', 'DSU', '2026'], $si['description'] ?? '');
                    $s_date = !empty($si['published_date']) ? $si['published_date'] : date('F j, Y');
                ?>
                    <div style="padding: 16px 0; <?php echo ($p_idx < $p_count) ? 'border-bottom: 1px solid #dbeafe;' : ''; ?>">
                        <div style="font-size: 15px; font-weight: 700; color: #0a192f; line-height: 1.45; margin-bottom: 7px;">
                            <span><?php echo htmlspecialchars($s_date); ?></span> — <span><?php echo htmlspecialchars($s_title); ?></span>
                        </div>
                        <?php if (!empty($s_desc)): ?>
                            <div style="font-size: 13px; color: #334155; line-height: 1.6; margin-bottom: 12px;">
                                <?php echo htmlspecialchars($s_desc); ?>
                            </div>
                        <?php endif; ?>
                        <button type="button" style="background: #2563eb; color: #ffffff; font-size: 12.5px; font-weight: 600; padding: 6px 16px; border-radius: 5px; border: none; cursor: default; box-shadow: 0 2px 6px rgba(37,99,235,0.25);">
                            Read More
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tab 2: Home Page Marquee UI (Image 1 style) -->
        <div id="preview-tab-home" class="card-body" style="padding: 24px; display: none;">
            <div style="display: flex; justify-content: center;">
                <div style="width: 100%; max-width: 360px; background: #e1edfe; border-radius: 20px; padding: 24px 20px; box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.15); color: #1e3a8a;">
                    <h3 style="margin: 0 0 14px 0; text-align: center; font-size: 21px; font-weight: 700; color: #0f3b82;">
                        Latest News
                    </h3>
                    <div style="height: 1px; background: rgba(15, 59, 130, 0.18); margin-bottom: 18px;"></div>
                    
                    <div style="height: 220px; overflow: hidden; position: relative;">
                        <div class="sode-preview-marquee-track">
                            <?php 
                            for ($loop = 0; $loop < 2; $loop++):
                                foreach ($sample_preview as $pi): 
                                    $p_text = str_replace(['{UNIVERSITY_NAME}', '{UNIVERSITY_SHORT_NAME}', '$YEAR$'], ['Dayananda Sagar University Online', 'DSU', '2026'], $pi['news_text']);
                            ?>
                                <div style="margin-bottom: 18px; font-size: 13.5px; line-height: 1.45; color: #283e78; font-weight: 500;">
                                    <span><?php echo htmlspecialchars($p_text); ?></span>
                                    <?php if (!empty($pi['has_badge']) || !isset($pi['has_badge'])): ?>
                                        <span style="display: inline-block; background: #f3b23e; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin-left: 4px; vertical-align: middle;">
                                            <?php echo htmlspecialchars($pi['badge_text'] ?? 'New'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php 
                                endforeach; 
                            endfor;
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Announcements Table (Full Width) -->
<div class="admin-card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span class="card-title">All Universal Announcements (<?php echo $total_announcements; ?>)</span>
        <a href="<?php echo BASE_URL; ?>/modules/universal_news/create.php" class="btn-primary btn-sm" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Add New Announcement
        </a>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 140px;">Published Date</th>
                    <th>Announcement Content</th>
                    <th style="width: 100px;">Badge</th>
                    <th style="width: 80px;">Order</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 100px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($news_list)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:var(--text-dim); padding:36px;">
                            No universal announcements added yet.<br>
                            <a href="<?php echo BASE_URL; ?>/modules/universal_news/create.php" style="color: var(--accent-color); font-weight: 600; text-decoration: underline; margin-top: 8px; display: inline-block;">
                                Click here to add the first universal announcement
                            </a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($news_list as $item): ?>
                        <tr>
                            <td>
                                <span style="font-size: 13px; color: var(--text-color); font-weight: 600;">
                                    <?php echo htmlspecialchars($item['published_date'] ?: date('M j, Y', strtotime($item['created_at']))); ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 14px; color: var(--text-color); line-height: 1.45;">
                                    <?php echo htmlspecialchars($item['news_text']); ?>
                                </div>
                                <?php if (!empty($item['description'])): ?>
                                    <div style="font-size: 12px; color: var(--text-dim); margin-top: 4px; line-height: 1.5;">
                                        <?php echo htmlspecialchars(substr($item['description'], 0, 140)); ?><?php echo strlen($item['description']) > 140 ? '...' : ''; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['news_link'])): ?>
                                    <div style="font-size: 11.5px; margin-top: 4px;">
                                        <a href="<?php echo htmlspecialchars($item['news_link']); ?>" target="_blank" style="color: var(--accent-color); text-decoration: underline; font-weight: 500;">
                                            <?php echo htmlspecialchars(substr($item['news_link'], 0, 50)); ?><?php echo strlen($item['news_link']) > 50 ? '...' : ''; ?> ↗
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['has_badge'])): ?>
                                    <span style="background: #f3b23e; color: #fff; font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 4px; display: inline-block;">
                                        <?php echo htmlspecialchars($item['badge_text'] ?: 'New'); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-dim); font-size: 11.5px;">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--bg-input); color: var(--text-color); font-size: 11.5px; font-weight: 600;">
                                    <?php echo (int)$item['sort_order']; ?>
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" style="background:none; border:none; cursor:pointer; padding:0;">
                                        <?php if (!empty($item['is_active'])): ?>
                                            <span class="badge badge-success" style="cursor:pointer;" title="Click to set inactive">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" style="cursor:pointer;" title="Click to activate">Inactive</span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td style="text-align: center;">
                                <div class="table-actions" style="justify-content: center;">
                                    <a href="<?php echo BASE_URL; ?>/modules/universal_news/edit.php?id=<?php echo $item['id']; ?>" class="action-btn" title="Edit Announcement">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>

                                    <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                        <button type="submit" class="action-btn delete-btn" title="Delete Announcement">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.tag-pill-btn {
    background: rgba(59, 130, 246, 0.16) !important;
    color: #60a5fa !important;
    border: 1px solid rgba(96, 165, 250, 0.42) !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    padding: 3px 10px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    transition: all 0.18s ease !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
}
.tag-pill-btn:hover {
    background: #2563eb !important;
    color: #ffffff !important;
    border-color: #2563eb !important;
}
.sode-preview-marquee-track {
    animation: sodeMarqueeUp 14s linear infinite;
}
.sode-preview-marquee-track:hover {
    animation-play-state: paused;
}
@keyframes sodeMarqueeUp {
    0% { transform: translateY(0); }
    100% { transform: translateY(-50%); }
}
</style>

<script>
function togglePreviewDrawer() {
    var content = document.getElementById('preview-drawer-content');
    var arrow = document.getElementById('preview-drawer-arrow');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        arrow.style.transform = 'rotate(0deg)';
    } else {
        content.style.display = 'none';
        arrow.style.transform = 'rotate(-90deg)';
    }
}

function switchPreviewTab(tab) {
    var tabInner = document.getElementById('preview-tab-inner');
    var tabHome = document.getElementById('preview-tab-home');
    var btnInner = document.getElementById('tab-btn-inner');
    var btnHome = document.getElementById('tab-btn-home');

    if (tab === 'inner') {
        tabInner.style.display = 'block';
        tabHome.style.display = 'none';
        btnInner.style.background = '#2563eb';
        btnInner.style.color = '#fff';
        btnInner.style.border = 'none';
        btnInner.style.fontWeight = '600';
        btnHome.style.background = 'var(--bg-input)';
        btnHome.style.color = 'var(--text-color)';
        btnHome.style.border = '1px solid var(--border-color)';
        btnHome.style.fontWeight = 'normal';
    } else {
        tabInner.style.display = 'none';
        tabHome.style.display = 'block';
        btnHome.style.background = '#2563eb';
        btnHome.style.color = '#fff';
        btnHome.style.border = 'none';
        btnHome.style.fontWeight = '600';
        btnInner.style.background = 'var(--bg-input)';
        btnInner.style.color = 'var(--text-color)';
        btnInner.style.border = '1px solid var(--border-color)';
        btnInner.style.fontWeight = 'normal';
    }
}
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
