<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universal_news');

$page_title = 'Universal News & Announcements';
$page_subtitle = 'Manage global announcements that display across ALL university subdomains (Home Page & Inner Pages)';
$active_page_key = 'universal_news';

$db = get_db_connection();

// Self-healing database schema: ensure description & published_date columns exist
try {
    $colDesc = $db->query("SHOW COLUMNS FROM news_items LIKE 'description'")->fetch();
    if (!$colDesc) {
        $db->exec("ALTER TABLE news_items ADD COLUMN description TEXT NULL AFTER news_text");
    }
    $colDate = $db->query("SHOW COLUMNS FROM news_items LIKE 'published_date'")->fetch();
    if (!$colDate) {
        $db->exec("ALTER TABLE news_items ADD COLUMN published_date VARCHAR(100) NULL AFTER description");
    }
} catch (Exception $e) {
    // Ignore if columns already exist
}

// Handle Form Submissions (Save, Update, Delete, Toggle Active)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $news_text = trim($_POST['news_text'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $published_date = trim($_POST['published_date'] ?? '');
        if (empty($published_date)) {
            $published_date = date('F j, Y');
        }
        $news_link = trim($_POST['news_link'] ?? '');
        $has_badge = isset($_POST['has_badge']) ? 1 : 0;
        $badge_text = trim($_POST['badge_text'] ?? 'New');
        if (empty($badge_text)) $badge_text = 'New';
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($news_text)) {
            set_flash_message('Announcement title / headline is required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE news_items 
                    SET news_text = ?, description = ?, published_date = ?, news_link = ?, has_badge = ?, badge_text = ?, sort_order = ?, is_active = ?, is_global = 1, university_id = NULL
                    WHERE id = ? AND is_global = 1
                ");
                $stmt->execute([$news_text, $description, $published_date, $news_link, $has_badge, $badge_text, $sort_order, $is_active, $id]);
                set_flash_message('Universal announcement updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO news_items (is_global, university_id, news_text, description, published_date, news_link, has_badge, badge_text, sort_order, is_active)
                    VALUES (1, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$news_text, $description, $published_date, $news_link, $has_badge, $badge_text, $sort_order, $is_active]);
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

// Edit Mode (Only fetch if it's a global news item)
$edit_news = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM news_items WHERE id = ? AND is_global = 1");
    $stmt->execute([$edit_id]);
    $edit_news = $stmt->fetch();
}

// Fetch all Universal News items ONLY
$news_list = $db->query("
    SELECT * FROM news_items 
    WHERE is_global = 1 
    ORDER BY sort_order ASC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// For live preview widgets
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
                    🌐 <strong>Universal Scope:</strong> Announcements created here will automatically display across <strong>ALL university subdomains</strong> (both on the Home Page marquee and Inner Page announcement cards).
                </div>

                <!-- Published Date (Admin editable) -->
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <label class="form-label" style="margin-bottom: 0;">Published Date *</label>
                        <button type="button" class="btn-xs" style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 4px; padding: 2px 8px; font-size: 11px; cursor: pointer;" onclick="document.getElementById('field_published_date').value = '<?php echo date('F j, Y'); ?>'">
                            📅 Set Today's Date
                        </button>
                    </div>
                    <input type="text" name="published_date" id="field_published_date" class="form-control" value="<?php echo htmlspecialchars($edit_news['published_date'] ?? date('F j, Y')); ?>" placeholder="e.g. September 12, 2026" required>
                    <div style="font-size: 11.5px; color: var(--text-dim); margin-top: 3px;">
                        Format example: <code>September 12, 2026</code>. Displayed before title in Inner Page list.
                    </div>
                </div>

                <!-- News Text / Headline -->
                <div class="form-group">
                    <label class="form-label">Announcement Title / Headline *</label>
                    <textarea name="news_text" id="field_news_text" class="form-textarea" rows="3" placeholder="e.g. Admission Deadline Extended to 15th October 2026 for the latest academic session." required><?php echo htmlspecialchars($edit_news['news_text'] ?? ''); ?></textarea>
                    
                    <!-- Quick Placeholders Helper -->
                    <div style="margin-top: 6px; font-size: 12px; color: var(--text-dim);">
                        <span>Insert dynamic variables:</span>
                        <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;">
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('{UNIVERSITY_NAME}', 'field_news_text')">{UNIVERSITY_NAME}</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('{UNIVERSITY_SHORT_NAME}', 'field_news_text')">{UNIVERSITY_SHORT_NAME}</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('$YEAR$', 'field_news_text')">$YEAR$</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('$session$', 'field_news_text')">$session$</button>
                        </div>
                    </div>
                </div>

                <!-- Description (For Inner Page Card) -->
                <div class="form-group">
                    <label class="form-label">Description (Shows on Inner Page "Recent Announcements")</label>
                    <textarea name="description" id="field_description" class="form-textarea" rows="4" placeholder="e.g. {UNIVERSITY_NAME} has extended the admission deadline for the academic session. Students can submit the application form before 15th October 2026."><?php echo htmlspecialchars($edit_news['description'] ?? ''); ?></textarea>
                    <div style="margin-top: 4px; font-size: 11.5px; color: var(--text-dim); display: flex; justify-content: space-between;">
                        <span>Supports <code>{UNIVERSITY_NAME}</code>, <code>{UNIVERSITY_SHORT_NAME}</code>, <code>$YEAR$</code></span>
                        <div>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('{UNIVERSITY_NAME}', 'field_description')">+ {UNIVERSITY_NAME}</button>
                            <button type="button" class="btn-xs" style="background:var(--bg-input); border:1px solid var(--border-color); border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer;" onclick="insertTag('$YEAR$', 'field_description')">+ $YEAR$</button>
                        </div>
                    </div>
                </div>

                <!-- Link URL -->
                <div class="form-group">
                    <label class="form-label">Read More / Link URL (Optional, opens in new tab)</label>
                    <input type="text" name="news_link" class="form-control" value="<?php echo htmlspecialchars($edit_news['news_link'] ?? ''); ?>" placeholder="https://.../admission or #">
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
                        <?php echo $edit_news ? 'Update Universal Announcement' : 'Add Universal Announcement'; ?>
                    </button>
                    <?php if ($edit_news): ?>
                        <a href="<?php echo BASE_URL; ?>/modules/universal_news/index.php" class="btn-secondary" style="text-decoration:none;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Column: Dual Live Previews & Universal Announcements Table -->
    <div>
        <!-- Dual Preview Widget: Inner Page Design & Home Page Marquee -->
        <div class="admin-card" style="margin-bottom: 20px;">
            <div class="card-header" style="border-bottom: 1px dashed var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                    <span class="card-title">Live Preview</span>
                </div>
                <div style="display: flex; gap: 6px;">
                    <button type="button" id="tab-btn-inner" onclick="switchPreviewTab('inner')" class="btn-xs" style="background: #2563eb; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-weight:600;">
                        Inner Page View
                    </button>
                    <button type="button" id="tab-btn-home" onclick="switchPreviewTab('home')" class="btn-xs" style="background: var(--bg-input); color:var(--text-color); border:1px solid var(--border-color); padding:4px 10px; border-radius:4px; cursor:pointer;">
                        Home Page Marquee
                    </button>
                </div>
            </div>

            <!-- Tab 1: Inner Page Announcements UI (Image 2 style) -->
            <div id="preview-tab-inner" class="card-body" style="padding: 20px;">
                <div style="background: #eaf3ff; border: 1px solid #d2e5fc; border-radius: 16px; padding: 24px; color: #0c2340; box-shadow: 0 4px 15px rgba(37,99,235,0.06);">
                    <h3 style="margin: 0 0 16px 0; font-size: 19px; font-weight: 800; color: #0c2340; letter-spacing: -0.2px;">
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
                        <div style="padding: 14px 0; <?php echo ($p_idx < $p_count) ? 'border-bottom: 1px solid #dbeafe;' : ''; ?>">
                            <div style="font-size: 14.5px; font-weight: 700; color: #0a192f; line-height: 1.4; margin-bottom: 6px;">
                                <span><?php echo htmlspecialchars($s_date); ?></span> — <span><?php echo htmlspecialchars($s_title); ?></span>
                            </div>
                            <?php if (!empty($s_desc)): ?>
                                <div style="font-size: 12.5px; color: #334155; line-height: 1.55; margin-bottom: 10px;">
                                    <?php echo htmlspecialchars($s_desc); ?>
                                </div>
                            <?php endif; ?>
                            <button type="button" style="background: #2563eb; color: #ffffff; font-size: 12px; font-weight: 600; padding: 5px 14px; border-radius: 5px; border: none; cursor: default;">
                                Read More
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 14px; padding: 8px 12px; background: rgba(37,99,235,0.06); border-radius: 6px; font-size: 12px; color: var(--text-color); display: flex; justify-content: space-between; align-items: center;">
                    <span>📄 <strong>Inner Page Shortcode:</strong> <code>[recent_announcements]</code> or <code>[university_announcements]</code></span>
                    <button type="button" class="btn-xs" style="padding: 2px 8px;" onclick="navigator.clipboard.writeText('[recent_announcements]'); alert('Copied [recent_announcements]!');">Copy</button>
                </div>
            </div>

            <!-- Tab 2: Home Page Marquee UI (Image 1 style) -->
            <div id="preview-tab-home" class="card-body" style="padding: 20px; display: none;">
                <div style="display: flex; justify-content: center;">
                    <div style="width: 100%; max-width: 350px; background: #e1edfe; border-radius: 20px; padding: 22px 18px; box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.15); color: #1e3a8a;">
                        <h3 style="margin: 0 0 12px 0; text-align: center; font-size: 20px; font-weight: 700; color: #0f3b82;">
                            Latest News
                        </h3>
                        <div style="height: 1px; background: rgba(15, 59, 130, 0.18); margin-bottom: 16px;"></div>
                        
                        <div style="height: 200px; overflow: hidden; position: relative;">
                            <div class="sode-preview-marquee-track">
                                <?php 
                                for ($loop = 0; $loop < 2; $loop++):
                                    foreach ($sample_preview as $pi): 
                                        $p_text = str_replace(['{UNIVERSITY_NAME}', '{UNIVERSITY_SHORT_NAME}', '$YEAR$'], ['Dayananda Sagar University Online', 'DSU', '2026'], $pi['news_text']);
                                ?>
                                    <div style="margin-bottom: 16px; font-size: 13.5px; line-height: 1.45; color: #283e78; font-weight: 500;">
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

                <div style="margin-top: 14px; padding: 8px 12px; background: rgba(37,99,235,0.06); border-radius: 6px; font-size: 12px; color: var(--text-color); display: flex; justify-content: space-between; align-items: center;">
                    <span>🏠 <strong>Home Page Shortcode:</strong> <code>[latest_news]</code></span>
                    <button type="button" class="btn-xs" style="padding: 2px 8px;" onclick="navigator.clipboard.writeText('[latest_news]'); alert('Copied [latest_news]!');">Copy</button>
                </div>
            </div>
        </div>

        <!-- Universal Announcements Table -->
        <div class="admin-card">
            <div class="card-header">
                <span class="card-title">Universal Announcements (<?php echo count($news_list); ?>)</span>
                <span class="badge" style="background: rgba(37,99,235,0.12); color: #2563eb; font-weight: 700;">
                    🌐 Global (All Subdomains)
                </span>
            </div>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width: 130px;">Date</th>
                            <th>Announcement Content</th>
                            <th>Badge</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th style="width: 80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($news_list)): ?>
                            <tr><td colspan="6" style="text-align:center; color:var(--text-dim); padding:24px;">No universal announcements added yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($news_list as $item): ?>
                                <tr>
                                    <td>
                                        <span style="font-size: 12px; color: var(--text-color); font-weight: 500;">
                                            <?php echo htmlspecialchars($item['published_date'] ?: date('M j, Y', strtotime($item['created_at']))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 13px; color: var(--text-color);">
                                            <?php echo htmlspecialchars($item['news_text']); ?>
                                        </div>
                                        <?php if (!empty($item['description'])): ?>
                                            <div style="font-size: 11.5px; color: var(--text-dim); margin-top: 3px; line-height: 1.4;">
                                                <?php echo htmlspecialchars(substr($item['description'], 0, 120)); ?><?php echo strlen($item['description']) > 120 ? '...' : ''; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($item['news_link'])): ?>
                                            <div style="font-size: 11px; margin-top: 2px;">
                                                <a href="<?php echo htmlspecialchars($item['news_link']); ?>" target="_blank" style="color: var(--accent-color); text-decoration: underline;">
                                                    <?php echo htmlspecialchars(substr($item['news_link'], 0, 40)); ?><?php echo strlen($item['news_link']) > 40 ? '...' : ''; ?> ↗
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['has_badge'])): ?>
                                            <span style="background: #f3b23e; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 4px;">
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
function insertTag(tag, elementId) {
    var textarea = document.getElementById(elementId || 'field_news_text');
    if (!textarea) return;
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var text = textarea.value;
    textarea.value = text.substring(0, start) + tag + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + tag.length;
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
