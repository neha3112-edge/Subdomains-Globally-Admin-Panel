<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universal_news');
require_action_permission('create', 'universal_news');

$page_title = 'Add Universal Announcement';
$page_subtitle = 'Create a global announcement that displays across ALL university subdomains';
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

// Fetch active global keys from database for tag pills
$db_global_keys = [];
try {
    $db_global_keys = $db->query("
        SELECT key_code, description, key_value 
        FROM global_keys 
        WHERE is_active = 1 AND key_code LIKE '$%'
        ORDER BY key_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $news_text = trim($_POST['news_text'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $published_date = trim($_POST['published_date'] ?? '');
    if (empty($published_date)) {
        $published_date = date('jS M Y');
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
        $stmt = $db->prepare("
            INSERT INTO news_items (is_global, university_id, news_text, description, published_date, news_link, has_badge, badge_text, sort_order, is_active)
            VALUES (1, NULL, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$news_text, $description, $published_date, $news_link, $has_badge, $badge_text, $sort_order, $is_active]);
        
        sode_bust_all_subdomain_caches($db);
        set_flash_message('Universal announcement created successfully!', 'success');
        redirect(BASE_URL . '/modules/universal_news/index.php');
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Header with Back Button -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <a href="<?php echo BASE_URL; ?>/modules/universal_news/index.php" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--accent-color); text-decoration: none; margin-bottom: 6px; font-weight: 600;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
            Back to Universal Announcements
        </a>
        <h2 style="font-size: 22px; font-weight: 800; color: var(--text-color); margin: 0;">Add Universal Announcement</h2>
    </div>
</div>

<div class="admin-card" style="max-width: 900px; margin: 0 auto 30px auto;">
    <div class="card-header">
        <span class="card-title">Announcement Details</span>
        <span class="badge" style="background: rgba(37,99,235,0.15); color: #60a5fa; font-weight: 700;">
            🌐 Universal Scope (All Subdomains)
        </span>
    </div>
    <div class="card-body" style="padding: 24px;">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>

            <div style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--accent-color); padding: 12px 16px; border-radius: 6px; margin-bottom: 22px; font-size: 13px; color: var(--text-color); line-height: 1.5;">
                🌐 <strong>Universal Announcement:</strong> This announcement will display automatically across <strong>ALL university subdomains</strong>. It will appear at the top of the list on both the <strong>Home Page Marquee</strong> (<code>[latest_news]</code>) and the <strong>Inner Page Recent Announcements card</strong> (<code>[recent_announcements]</code>).
            </div>

            <!-- Published Date -->
            <div class="form-group" style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" style="margin-bottom: 0; font-weight: 700;">Published Date *</label>
                    <button type="button" class="btn-xs tag-pill-btn" onclick="document.getElementById('field_published_date').value = '<?php echo date('jS M Y'); ?>'">
                        📅 Set Today's Date (<?php echo date('jS M Y'); ?>)
                    </button>
                </div>
                <input type="text" name="published_date" id="field_published_date" class="form-control" value="<?php echo date('jS M Y'); ?>" placeholder="e.g. 21st Sep 2026" required style="font-size: 14px;">
                <div style="font-size: 12px; color: var(--text-dim); margin-top: 4px;">
                    Format example: <code>21st Sep 2026</code>. This date displays before the title in the Inner Page announcement card (e.g. <em>21st Sep 2026 — Title</em>).
                </div>
            </div>

            <!-- Announcement Title / Headline -->
            <div class="form-group" style="margin-bottom: 22px;">
                <label class="form-label" style="font-weight: 700;">Announcement Title / Headline *</label>
                <textarea name="news_text" id="field_news_text" class="form-textarea" rows="3" placeholder="e.g. Admission Deadline Extended to 15th October 2026 for the latest academic session." required style="font-size: 14px; line-height: 1.5;"></textarea>

                <!-- Prominent Dynamic Keys & Variables Box -->
                <div style="margin-top: 10px; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px 14px;">
                    <div style="font-size: 12px; font-weight: 700; color: #93c5fd; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 2l-2 2m-6 6l7-7m-7 7l-2 2m-2-2l-2 2m2-2l-7 7m7-7l2-2"></path><circle cx="7.5" cy="16.5" r="4.5"></circle></svg>
                        Available Dynamic Keys & Tags (Click to insert into Title):
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                        <!-- System Tags -->
                        <button type="button" class="tag-pill-btn" onclick="insertTag('{UNIVERSITY_NAME}', 'field_news_text')" title="Replaced with university name (e.g. Dayananda Sagar University Online)">
                            {UNIVERSITY_NAME}
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('{UNIVERSITY_SHORT_NAME}', 'field_news_text')" title="Replaced with university short code (e.g. DSU)">
                            {UNIVERSITY_SHORT_NAME}
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('{MODE}', 'field_news_text')" title="Replaced with Online & Distance">
                            {MODE}
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('$YEAR$', 'field_news_text')" title="Current year (e.g. <?php echo date('Y'); ?>)">
                            $YEAR$
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('$session$', 'field_news_text')" title="Admission session (e.g. <?php echo date('Y') . '-' . ((int)date('Y')+1); ?>)">
                            $session$
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('$nextyear$', 'field_news_text')" title="Next year (e.g. <?php echo ((int)date('Y')+1); ?>)">
                            $nextyear$
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('$PHONE$', 'field_news_text')" title="Global Support Phone">
                            $PHONE$
                        </button>
                        <button type="button" class="tag-pill-btn" onclick="insertTag('$EMAIL$', 'field_news_text')" title="Global Support Email">
                            $EMAIL$
                        </button>
                        <?php foreach ($db_global_keys as $gk): ?>
                            <?php if (!in_array($gk['key_code'], ['$YEAR$', '$session$', '$nextyear$', '$PHONE$', '$EMAIL$'])): ?>
                                <button type="button" class="tag-pill-btn" onclick="insertTag('<?php echo htmlspecialchars($gk['key_code']); ?>', 'field_news_text')" title="<?php echo htmlspecialchars($gk['description'] ?: $gk['key_value']); ?>">
                                    <?php echo htmlspecialchars($gk['key_code']); ?>
                                </button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size: 11px; color: var(--text-dim); margin-top: 6px;">
                        💡 <em>Tip: These keys will automatically be replaced with each subdomain's university details when viewed on the website.</em>
                    </div>
                </div>
            </div>

            <!-- Description (Shows on Inner Page "Recent Announcements") -->
            <div class="form-group" style="margin-bottom: 22px;">
                <label class="form-label" style="font-weight: 700;">Description (Displayed on Inner Page "Recent Announcements")</label>
                <textarea name="description" id="field_description" class="form-textarea" rows="4" placeholder="e.g. {UNIVERSITY_NAME} has extended the admission deadline for the academic session. Students can submit the application form before 15th October 2026." style="font-size: 14px; line-height: 1.5;"></textarea>
                
                <!-- Keys for Description -->
                <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                    <span style="font-size: 11.5px; color: var(--text-dim); font-weight: 600;">Insert key into description:</span>
                    <button type="button" class="tag-pill-btn tag-pill-sm" onclick="insertTag('{UNIVERSITY_NAME}', 'field_description')">+{UNIVERSITY_NAME}</button>
                    <button type="button" class="tag-pill-btn tag-pill-sm" onclick="insertTag('{UNIVERSITY_SHORT_NAME}', 'field_description')">+{UNIVERSITY_SHORT_NAME}</button>
                    <button type="button" class="tag-pill-btn tag-pill-sm" onclick="insertTag('$YEAR$', 'field_description')">+$YEAR$</button>
                    <button type="button" class="tag-pill-btn tag-pill-sm" onclick="insertTag('$session$', 'field_description')">+$session$</button>
                    <button type="button" class="tag-pill-btn tag-pill-sm" onclick="insertTag('$PHONE$', 'field_description')">+$PHONE$</button>
                    <button type="button" class="tag-pill-btn tag-pill-sm" onclick="insertTag('$EMAIL$', 'field_description')">+$EMAIL$</button>
                </div>
            </div>

            <!-- Read More / Link URL -->
            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label" style="font-weight: 700;">Read More / Link URL (Optional, opens in new tab)</label>
                <input type="text" name="news_link" class="form-control" value="" placeholder="https://.../admission or #">
                <div style="font-size: 12px; color: var(--text-dim); margin-top: 4px;">
                    When provided, the "Read More" button on the inner page card and the headline will link here.
                </div>
            </div>

            <!-- Badge Controls -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Show Badge?</label>
                    <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px; cursor: pointer;">
                        <input type="checkbox" name="has_badge" value="1" checked>
                        <span style="font-size: 13.5px; font-weight: 500;">Show "NEW" Badge</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Badge Text</label>
                    <input type="text" name="badge_text" class="form-control" value="New" placeholder="New">
                </div>
            </div>

            <!-- Sort Order & Status -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="0">
                    <div style="font-size: 11.5px; color: var(--text-dim); margin-top: 3px;">Lower numbers appear first (0, 1, 2...).</div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">Status</label>
                    <label style="display: flex; align-items: center; gap: 8px; margin-top: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" value="1" checked>
                        <span style="font-size: 13.5px; font-weight: 500;">Active & Published</span>
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: 12px; padding-top: 10px; border-top: 1px solid var(--border-color);">
                <button type="submit" class="btn-primary" style="padding: 11px 26px; font-size: 14px; font-weight: 700;">
                    Save Universal Announcement
                </button>
                <a href="<?php echo BASE_URL; ?>/modules/universal_news/index.php" class="btn-secondary" style="text-decoration: none; padding: 11px 20px; font-size: 14px;">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<style>
/* High contrast, prominent tag pill buttons for dark & light mode */
.tag-pill-btn {
    background: rgba(59, 130, 246, 0.16) !important;
    color: #60a5fa !important;
    border: 1px solid rgba(96, 165, 250, 0.42) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    padding: 4px 11px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    transition: all 0.18s ease !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    line-height: 1.4 !important;
}
.tag-pill-btn:hover {
    background: #2563eb !important;
    color: #ffffff !important;
    border-color: #2563eb !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.35);
}
.tag-pill-sm {
    font-size: 11px !important;
    padding: 2px 8px !important;
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
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
