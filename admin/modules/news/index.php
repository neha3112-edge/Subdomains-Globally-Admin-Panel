<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('news');

$page_title = 'Latest News & Marquee';
$page_subtitle = 'Manage global announcements and university-specific scrolling marquee news';
$active_page_key = 'news';

$db = get_db_connection();

// Auto-run migrations to ensure table exists
sode_run_auto_migrations($db);

// Fetch all universities for dropdowns & filters
$universities = $db->query("SELECT id, full_name, short_name, slug FROM universities WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();

// Handle Form Submissions (Save, Update, Delete, Toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $scope = trim($_POST['scope'] ?? 'global'); // 'global' or 'university'
        $university_id = ($scope === 'university' && !empty($_POST['university_id'])) ? (int)$_POST['university_id'] : null;
        $is_global = ($scope === 'global' || empty($university_id)) ? 1 : 0;
        $news_text = trim($_POST['news_text'] ?? '');
        $news_link = trim($_POST['news_link'] ?? '');
        $has_badge = isset($_POST['has_badge']) ? 1 : 0;
        $badge_text = trim($_POST['badge_text'] ?? 'New');
        if (empty($badge_text)) $badge_text = 'New';
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($news_text)) {
            set_flash_message('News text is required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE news_items 
                    SET is_global = ?, university_id = ?, news_text = ?, news_link = ?, has_badge = ?, badge_text = ?, sort_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$is_global, $university_id, $news_text, $news_link, $has_badge, $badge_text, $sort_order, $is_active, $id]);
                set_flash_message('News item updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO news_items (is_global, university_id, news_text, news_link, has_badge, badge_text, sort_order, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$is_global, $university_id, $news_text, $news_link, $has_badge, $badge_text, $sort_order, $is_active]);
                set_flash_message('News item added successfully!', 'success');
            }

            // Auto-bust subdomain caches for real-time reflection
            sode_bust_all_subdomain_caches($db);

            redirect(BASE_URL . '/modules/news/index.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM news_items WHERE id = ?");
            $stmt->execute([$id]);
            sode_bust_all_subdomain_caches($db);
            set_flash_message('News item deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/news/index.php');
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("UPDATE news_items SET is_active = IF(is_active=1, 0, 1) WHERE id = ?");
            $stmt->execute([$id]);
            sode_bust_all_subdomain_caches($db);
            set_flash_message('News status updated!', 'success');
            redirect(BASE_URL . '/modules/news/index.php');
        }
    }
}

// Edit Mode
$edit_news = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM news_items WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_news = $stmt->fetch();
}

// Filter Options
$filter_scope = $_GET['filter_scope'] ?? 'all';
$filter_uni   = isset($_GET['filter_uni']) ? (int)$_GET['filter_uni'] : 0;

$query = "
    SELECT n.*, u.full_name AS university_name, u.short_name AS university_short_name, u.slug AS university_slug
    FROM news_items n
    LEFT JOIN universities u ON n.university_id = u.id
    WHERE 1=1
";
$params = [];

if ($filter_scope === 'global') {
    $query .= " AND n.is_global = 1";
} elseif ($filter_scope === 'university') {
    $query .= " AND n.is_global = 0";
    if ($filter_uni > 0) {
        $query .= " AND n.university_id = ?";
        $params[] = $filter_uni;
    }
} elseif ($filter_uni > 0) {
    $query .= " AND (n.is_global = 1 OR n.university_id = ?)";
    $params[] = $filter_uni;
}

$query .= " ORDER BY n.sort_order ASC, n.id DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$news_list = $stmt->fetchAll();

// Fetch sample active news for the live preview widget
$preview_uni_id = $filter_uni > 0 ? $filter_uni : ($universities[0]['id'] ?? 0);
$preview_news_stmt = $db->prepare("
    SELECT * FROM news_items 
    WHERE is_active = 1 AND (is_global = 1 OR university_id = ?)
    ORDER BY sort_order ASC, id DESC
");
$preview_news_stmt->execute([$preview_uni_id]);
$preview_items = $preview_news_stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Filter & Quick Action Bar -->
<div class="admin-card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between;">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                <span style="font-weight: 600; color: var(--text-color); font-size: 13.5px;">Filter Scope:</span>
                
                <a href="<?php echo BASE_URL; ?>/modules/news/index.php?filter_scope=all" 
                   class="btn-sm <?php echo ($filter_scope === 'all' && empty($filter_uni)) ? 'btn-primary' : 'btn-secondary'; ?>" 
                   style="border-radius: 20px; padding: 6px 14px; text-decoration: none;">
                    All News (<?php echo count($news_list); ?>)
                </a>

                <a href="<?php echo BASE_URL; ?>/modules/news/index.php?filter_scope=global" 
                   class="btn-sm <?php echo ($filter_scope === 'global') ? 'btn-primary' : 'btn-secondary'; ?>" 
                   style="border-radius: 20px; padding: 6px 14px; text-decoration: none;">
                    🌐 Universal (All Domains)
                </a>

                <a href="<?php echo BASE_URL; ?>/modules/news/index.php?filter_scope=university" 
                   class="btn-sm <?php echo ($filter_scope === 'university') ? 'btn-primary' : 'btn-secondary'; ?>" 
                   style="border-radius: 20px; padding: 6px 14px; text-decoration: none;">
                    🏛️ University-Specific
                </a>
            </div>

            <div style="display: flex; gap: 10px; align-items: center;">
                <label style="font-size: 13px; color: var(--text-dim);">By University:</label>
                <select name="filter_uni" class="form-select" style="width: auto; padding: 6px 12px; font-size: 13px;" onchange="this.form.submit()">
                    <option value="0">-- All Universities --</option>
                    <?php foreach ($universities as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo ($filter_uni === (int)$u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?> (<?php echo htmlspecialchars($u['short_name']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="split-layout">
    <!-- Left Column: Add / Edit Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_news ? 'Edit News Item' : 'Add New Announcement'; ?></span>
            <?php if ($edit_news): ?>
                <a href="<?php echo BASE_URL; ?>/modules/news/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_news['id'] ?? ''; ?>">

                <!-- Scope Selection -->
                <div class="form-group">
                    <label class="form-label">News Target Scope *</label>
                    <?php 
                        $is_uni_scope = ($edit_news && $edit_news['is_global'] == 0 && !empty($edit_news['university_id']));
                    ?>
                    <div style="display: flex; gap: 16px; margin-bottom: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; cursor: pointer;">
                            <input type="radio" name="scope" value="global" id="scope_global" <?php echo !$is_uni_scope ? 'checked' : ''; ?> onchange="toggleScopeSelect()">
                            <span>🌐 <strong>Universal (All Domains)</strong></span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13.5px; cursor: pointer;">
                            <input type="radio" name="scope" value="university" id="scope_uni" <?php echo $is_uni_scope ? 'checked' : ''; ?> onchange="toggleScopeSelect()">
                            <span>🏛️ <strong>Specific University</strong></span>
                        </label>
                    </div>
                </div>

                <div class="form-group" id="uni_select_group" style="<?php echo $is_uni_scope ? '' : 'display:none;'; ?>">
                    <label class="form-label">Select University *</label>
                    <select name="university_id" id="field_university_id" class="form-select">
                        <option value="">-- Choose Target University --</option>
                        <?php foreach ($universities as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo ($edit_news && (int)$edit_news['university_id'] === (int)$u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['full_name']); ?> (<?php echo htmlspecialchars($u['short_name']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- News Text -->
                <div class="form-group">
                    <label class="form-label">News / Notification Text *</label>
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
                    <input type="text" name="news_link" class="form-control" value="<?php echo htmlspecialchars($edit_news['news_link'] ?? ''); ?>" placeholder="https://dsu.distanceeducationschool.com/support or #">
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

                <div style="margin-top: 14px; display: flex; gap: 10px;">
                    <button type="submit" class="btn-primary">
                        <?php echo $edit_news ? 'Update News' : 'Add News Announcement'; ?>
                    </button>
                    <?php if ($edit_news): ?>
                        <a href="<?php echo BASE_URL; ?>/modules/news/index.php" class="btn-secondary" style="text-decoration:none;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Column: Live Interactive Marquee Preview & News Table -->
    <div>
        <!-- Live UI Preview Widget -->
        <div class="admin-card" style="margin-bottom: 20px; background: linear-gradient(145deg, var(--bg-card), var(--bg-body));">
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
                                ['news_text' => 'For the latest notifications regarding student support, access the DSU Student Support Page.', 'has_badge' => 1, 'badge_text' => 'New', 'news_link' => '#'],
                                ['news_text' => 'Get notifications for the latest placement drives at Dayananda Sagar University Online 2026', 'has_badge' => 1, 'badge_text' => 'New', 'news_link' => '#'],
                                ['news_text' => 'Upcoming News & Events at Dayananda Sagar University 2026', 'has_badge' => 1, 'badge_text' => 'New', 'news_link' => '#']
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

        <!-- News Items Master Table -->
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title">Announcements List (<?php echo count($news_list); ?>)</span>
            </div>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Scope</th>
                            <th>News Content</th>
                            <th>Badge</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($news_list)): ?>
                            <tr><td colspan="6" style="text-align:center; color:var(--text-dim); padding:24px;">No announcements found matching filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($news_list as $item): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($item['is_global'])): ?>
                                            <span class="badge badge-success" style="font-size: 11px; white-space: nowrap;">🌐 Universal</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning" style="font-size: 11px; white-space: nowrap;">
                                                🏛️ <?php echo htmlspecialchars($item['university_short_name'] ?: 'University'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; font-size: 13.5px; max-width: 380px;">
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
                                            <a href="<?php echo BASE_URL; ?>/modules/news/index.php?edit_id=<?php echo $item['id']; ?>" class="action-btn" title="Edit">
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
/* Smooth Endless Marquee Animation in Admin Preview */
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
function toggleScopeSelect() {
    var isUni = document.getElementById('scope_uni').checked;
    var group = document.getElementById('uni_select_group');
    if (isUni) {
        group.style.display = 'block';
    } else {
        group.style.display = 'none';
    }
}

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
