<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universal_news');

$page_title = 'Universal News';
$page_subtitle = 'Manage global announcements and news that display on all university subdomains';
$active_page_key = 'universal_news';

$db = get_db_connection();
sode_run_auto_migrations($db);

// Handle Form Submissions (Save, Update, Delete, Toggle Active)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $news_text = trim($_POST['news_text'] ?? '');
        $news_link = trim($_POST['news_link'] ?? '');
        $has_badge = isset($_POST['has_badge']) ? 1 : 0;
        $badge_text = trim($_POST['badge_text'] ?? 'New');
        if (empty($badge_text)) $badge_text = 'New';
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($news_text)) {
            set_flash_message('Announcement text is required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE news_items 
                    SET news_text = ?, news_link = ?, has_badge = ?, badge_text = ?, sort_order = ?, is_active = ?, is_global = 1, university_id = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$news_text, $news_link, $has_badge, $badge_text, $sort_order, $is_active, $id]);
                set_flash_message('Universal announcement updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO news_items (is_global, university_id, news_text, news_link, has_badge, badge_text, sort_order, is_active)
                    VALUES (1, NULL, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$news_text, $news_link, $has_badge, $badge_text, $sort_order, $is_active]);
                set_flash_message('Universal announcement created successfully!', 'success');
            }

            // Auto-bust subdomain caches
            sode_bust_all_subdomain_caches($db);
            redirect(BASE_URL . '/modules/universal_news/index.php');
        }
    } elseif ($action === 'delete') {
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

// Edit Mode
$edit_news = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM news_items WHERE id = ? AND is_global = 1");
    $stmt->execute([$edit_id]);
    $edit_news = $stmt->fetch();
}

// Fetch all Universal News
$news_list = $db->query("
    SELECT * FROM news_items 
    WHERE is_global = 1 
    ORDER BY sort_order ASC, id DESC
")->fetchAll();

// Active news for Live Preview
$preview_items = array_filter($news_list, function($n) { return !empty($n['is_active']); });

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    <!-- Left Column: Add / Edit Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_news ? 'Edit Universal Announcement' : 'Add Universal Announcement'; ?></span>
            <?php if ($edit_news): ?>
                <a href="<?php echo BASE_URL; ?>/modules/universal_news/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_news['id'] ?? ''; ?>">

                <div style="background: rgba(59, 130, 246, 0.08); border-left: 3px solid var(--accent-color); padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 12.5px; color: var(--text-color);">
                    🌐 <strong>Universal Scope:</strong> Announcements added here will automatically scroll on <strong>all university subdomains</strong>.
                </div>

                <!-- News Text -->
                <div class="form-group">
                    <label class="form-label">Announcement Text *</label>
                    <textarea name="news_text" id="field_news_text" class="form-textarea" rows="4" placeholder="e.g. For the latest notifications regarding student support, access the {UNIVERSITY_SHORT_NAME} Student Support Page." required><?php echo htmlspecialchars($edit_news['news_text'] ?? ''); ?></textarea>
                    
                    <!-- Quick Placeholders Helper -->
                    <div style="margin-top: 6px; font-size: 12px; color: var(--text-dim);">
                        <span>Insert dynamic variables:</span>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;">
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('{UNIVERSITY_NAME}')">{UNIVERSITY_NAME}</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('{UNIVERSITY_SHORT_NAME}')">{UNIVERSITY_SHORT_NAME}</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('{MODE}')">{MODE}</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('$YEAR$')">$YEAR$</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('$session$')">$session$</button>
                        </div>
                    </div>
                </div>

                <!-- Link URL -->
                <div class="form-group">
                    <label class="form-label">Link URL (Optional, opens in new tab)</label>
                    <input type="text" name="news_link" class="form-control" value="<?php echo htmlspecialchars($edit_news['news_link'] ?? ''); ?>" placeholder="https://.../support or #">
                </div>

                <!-- Badge Controls -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Show Badge?</label>
                        <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px; cursor: pointer;">
                            <input type="checkbox" name="has_badge" value="1" <?php echo (!$edit_news || !empty($edit_news['has_badge'])) ? 'checked' : ''; ?>>
                            <span style="font-size: 13.5px;">Show "NEW" Badge</span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Badge Text</label>
                        <input type="text" name="badge_text" class="form-control" value="<?php echo htmlspecialchars($edit_news['badge_text'] ?? 'New'); ?>" placeholder="New">
                    </div>
                </div>

                <!-- Sort Order & Active -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?php echo htmlspecialchars($edit_news['sort_order'] ?? 0); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" <?php echo (!$edit_news || !empty($edit_news['is_active'])) ? 'checked' : ''; ?>>
                            <span style="font-size: 13.5px;">Active & Published</span>
                        </label>
                    </div>
                </div>

                <div style="margin-top: 16px; display: flex; gap: 10px;">
                    <button type="submit" class="btn-primary">
                        <?php echo $edit_news ? 'Update Announcement' : 'Add Universal Announcement'; ?>
                    </button>
                    <?php if ($edit_news): ?>
                        <a href="<?php echo BASE_URL; ?>/modules/universal_news/index.php" class="btn-secondary" style="text-decoration:none;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Column: Live Interactive Marquee Preview & Table -->
    <div>
        <!-- Live UI Preview Widget -->
        <div class="admin-card" style="margin-bottom: 20px;">
            <div class="card-header" style="border-bottom: 1px dashed var(--border-color);">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    <span class="card-title">Live Frontend Widget Preview</span>
                </div>
                <span class="badge badge-success" style="font-size: 11px;">Vertical Marquee</span>
            </div>
            <div class="card-body" style="display: flex; justify-content: center; padding: 24px;">
                <!-- Exact Card UI from user screenshot -->
                <div style="width: 100%; max-width: 360px; background: #e1edfe; border-radius: 20px; padding: 24px 20px; box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.15); font-family: inherit; color: #1e3a8a;">
                    <h3 style="margin: 0 0 14px 0; text-align: center; font-size: 22px; font-weight: 700; color: #0f3b82; letter-spacing: -0.3px;">
                        Latest News
                    </h3>
                    <div style="height: 1px; background: rgba(15, 59, 130, 0.18); margin-bottom: 18px;"></div>
                    
                    <!-- Scrolling Marquee Container -->
                    <div style="height: 220px; overflow: hidden; position: relative;">
                        <div class="sode-preview-marquee-track">
                            <?php 
                            $render_preview = !empty($preview_items) ? $preview_items : [
                                ['news_text' => 'For the latest notifications regarding student support, access the DSU Student Support Page.', 'has_badge' => 1, 'badge_text' => 'New'],
                                ['news_text' => 'Get notifications for the latest placement drives at Dayananda Sagar University Online 2026', 'has_badge' => 1, 'badge_text' => 'New'],
                                ['news_text' => 'Upcoming News & Events at Dayananda Sagar University 2026', 'has_badge' => 1, 'badge_text' => 'New']
                            ];
                            // Render list twice for smooth endless loop
                            for ($loop = 0; $loop < 2; $loop++):
                                foreach ($render_preview as $pi): 
                                    $p_text = $pi['news_text'];
                                    $p_text = str_replace(['{UNIVERSITY_NAME}', '{UNIVERSITY_SHORT_NAME}', '$YEAR$'], ['Dayananda Sagar University Online', 'DSU', '2026'], $p_text);
                            ?>
                                <div style="margin-bottom: 18px; font-size: 14px; line-height: 1.5; color: #283e78; font-weight: 500;">
                                    <span><?php echo htmlspecialchars($p_text); ?></span>
                                    <?php if (!empty($pi['has_badge'])): ?>
                                        <span style="display: inline-block; background: #f3b23e; color: #fff; font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 4px; margin-left: 4px; vertical-align: middle; box-shadow: 0 2px 4px rgba(243, 178, 62, 0.3);">
                                            <?php echo htmlspecialchars($pi['badge_text'] ?: 'New'); ?>
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
            <div style="padding: 8px 20px; font-size: 11.5px; color: var(--text-dim); text-align: center; border-top: 1px dashed var(--border-color);">
                💡 <em>Shortcode to use in WordPress / Elementor:</em> <strong><code>[latest_news]</code></strong>
            </div>
        </div>

        <!-- Universal Announcements Table -->
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title">Universal Announcements (<?php echo count($news_list); ?>)</span>
            </div>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Announcement Content</th>
                            <th>Badge</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($news_list)): ?>
                            <tr><td colspan="5" style="text-align:center; color:var(--text-dim); padding:24px;">No universal announcements added yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($news_list as $item): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 500; font-size: 13.5px;">
                                            <?php echo htmlspecialchars($item['news_text']); ?>
                                        </div>
                                        <?php if (!empty($item['news_link'])): ?>
                                            <div style="font-size: 11px; color: var(--accent-color); margin-top: 2px;">
                                                <a href="<?php echo htmlspecialchars($item['news_link']); ?>" target="_blank" style="color: inherit; text-decoration: underline;">
                                                    <?php echo htmlspecialchars(substr($item['news_link'], 0, 45)); ?><?php echo strlen($item['news_link']) > 45 ? '...' : ''; ?> ↗
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['has_badge'])): ?>
                                            <span style="background: #f3b23e; color: #fff; font-size: 10.5px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
                                                <?php echo htmlspecialchars($item['badge_text'] ?: 'New'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-dim); font-size: 11px;">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: var(--bg-input); color: var(--text-color); font-size: 11px;">
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
                                                    <span class="badge badge-success" style="cursor:pointer;" title="Click to disable">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger" style="cursor:pointer;" title="Click to activate">Inactive</span>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="<?php echo BASE_URL; ?>/modules/universal_news/index.php?edit_id=<?php echo $item['id']; ?>" class="action-btn" title="Edit">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            </a>

                                            <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="action-btn delete-btn" title="Delete">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
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
    </div>
</div>

<style>
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
function insertTag(tag) {
    var textarea = document.getElementById('field_news_text');
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var text = textarea.value;
    textarea.value = text.substring(0, start) + tag + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + tag.length;
}
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
