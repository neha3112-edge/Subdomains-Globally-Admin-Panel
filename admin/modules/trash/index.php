<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('trash');

$page_title = 'Trash / Recycle Bin';
$page_subtitle = 'Safely restore deleted records, courses, media images, or purge them permanently';
$active_page_key = 'trash';

$db = get_db_connection();
sode_ensure_trash_system($db);

// Handle POST actions (Restore, Permanent Delete, Bulk, Empty Trash)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $res = restore_from_trash($id);
            set_flash_message($res['message'], $res['success'] ? 'success' : 'error');
        }
        redirect(BASE_URL . '/modules/trash/index.php');
    }

    if ($action === 'permanent_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $res = permanent_delete_from_trash($id);
            set_flash_message($res['message'], $res['success'] ? 'success' : 'error');
        }
        redirect(BASE_URL . '/modules/trash/index.php');
    }

    if ($action === 'bulk_restore') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $count = bulk_trash_restore($ids);
            set_flash_message("Successfully restored {$count} item(s) from Trash!", 'success');
        } else {
            set_flash_message("No items selected for restore.", 'warning');
        }
        redirect(BASE_URL . '/modules/trash/index.php');
    }

    if ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $count = bulk_trash_permanent_delete($ids);
            set_flash_message("Permanently deleted {$count} item(s) from Trash.", 'info');
        } else {
            set_flash_message("No items selected for deletion.", 'warning');
        }
        redirect(BASE_URL . '/modules/trash/index.php');
    }

    if ($action === 'empty_trash') {
        $filter_type = trim($_POST['item_type'] ?? '');
        $purged = empty_all_trash(!empty($filter_type) ? $filter_type : null);
        set_flash_message("Trash emptied successfully! Permanently purged {$purged} item(s).", 'success');
        redirect(BASE_URL . '/modules/trash/index.php');
    }
}

// Filter setup
$selected_type = trim($_GET['type'] ?? 'all');
$search = trim($_GET['q'] ?? '');

$where_clauses = [];
$params = [];

if ($selected_type !== 'all' && $selected_type !== '') {
    $where_clauses[] = "item_type = :type";
    $params[':type'] = $selected_type;
}

if ($search !== '') {
    $where_clauses[] = "(item_title LIKE :q1 OR source_table LIKE :q2 OR original_id = :q3)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = (int)$search;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM admin_trash $where_sql");
$count_stmt->execute($params);
$total_trashed = (int)$count_stmt->fetchColumn();

// Overall trash count
$total_all_trash = (int)$db->query("SELECT COUNT(*) FROM admin_trash")->fetchColumn();

// Pagination
$pagination = sode_get_pagination_params(15);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Fetch trash items
$query_sql = "
    SELECT * FROM admin_trash 
    $where_sql 
    ORDER BY id DESC 
    LIMIT :limit OFFSET :offset
";
$stmt = $db->prepare($query_sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$trash_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Types summary count for tabs
$type_counts = $db->query("
    SELECT item_type, COUNT(*) as cnt 
    FROM admin_trash 
    GROUP BY item_type
")->fetchAll(PDO::FETCH_KEY_PAIR);

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="dash-hero" style="margin-bottom:20px; padding:20px 24px;">
    <div class="dash-hero-left">
        <div class="dash-hero-avatar" style="background:linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); box-shadow:0 6px 16px rgba(239,68,68,0.35);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"></polyline>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                <line x1="10" y1="11" x2="10" y2="17"></line>
                <line x1="14" y1="11" x2="14" y2="17"></line>
            </svg>
        </div>
        <div>
            <div class="dash-hero-title">
                Trash & Recycle Bin
                <span class="badge badge-warning" style="font-size:11px;"><?php echo $total_all_trash; ?> Items in Trash</span>
            </div>
            <div class="dash-hero-subtitle">
                Deleted data and media files are safely archived here. Restore them anytime or empty trash to purge.
            </div>
        </div>
    </div>
    <div class="dash-hero-right">
        <?php if ($total_all_trash > 0): ?>
            <form method="POST" onsubmit="return confirm('WARNING: Are you sure you want to permanently delete all items in trash? This cannot be undone!');" style="margin:0;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="empty_trash">
                <input type="hidden" name="item_type" value="<?php echo htmlspecialchars($selected_type); ?>">
                <button type="submit" class="btn-sm" style="background:#ef4444; color:#ffffff; border:none; padding:8px 16px; border-radius:var(--radius-md); font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; box-shadow:0 3px 10px rgba(239,68,68,0.3);">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    Empty Trash
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Type Filter Tabs -->
<div class="shortcode-cat-filters" style="margin-bottom:18px;">
    <a href="?type=all<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'all' ? 'active' : ''; ?>">
        All Items (<?php echo $total_all_trash; ?>)
    </a>
    <a href="?type=university<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'university' ? 'active' : ''; ?>">
        Universities (<?php echo $type_counts['university'] ?? 0; ?>)
    </a>
    <a href="?type=course<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'course' ? 'active' : ''; ?>">
        Courses (<?php echo $type_counts['course'] ?? 0; ?>)
    </a>
    <a href="?type=mapping<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'mapping' ? 'active' : ''; ?>">
        Course Mappings (<?php echo $type_counts['mapping'] ?? 0; ?>)
    </a>
    <a href="?type=media<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'media' ? 'active' : ''; ?>">
        Media & Images (<?php echo $type_counts['media'] ?? 0; ?>)
    </a>
    <a href="?type=global_key<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'global_key' ? 'active' : ''; ?>">
        Global Keys (<?php echo $type_counts['global_key'] ?? 0; ?>)
    </a>
    <a href="?type=news<?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="sc-filter-btn <?php echo $selected_type === 'news' ? 'active' : ''; ?>">
        Announcements (<?php echo $type_counts['news'] ?? 0; ?>)
    </a>
</div>

<!-- Search & Bulk Form -->
<div class="admin-card card-overflow-hidden">
    <div class="card-header" style="flex-wrap:wrap; gap:12px;">
        <!-- Left: Search input -->
        <form method="GET" style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($selected_type); ?>">
            <div style="position:relative;">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search trash by title or ID..." class="form-control" style="width:260px; padding:7px 12px 7px 32px; font-size:12.5px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:10px; top:10px; color:var(--text-dim);">
                    <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </div>
            <button type="submit" class="btn-primary btn-sm">Search</button>
            <?php if ($search): ?>
                <a href="?type=<?php echo urlencode($selected_type); ?>" class="btn-sm" style="background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-muted); text-decoration:none;">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Right: Bulk Actions Trigger -->
        <div id="bulkActionsToolbar" style="display:none; align-items:center; gap:8px;">
            <span id="selectedCountBadge" class="badge badge-info" style="font-size:12px;">0 selected</span>
            <button type="button" onclick="submitBulkAction('bulk_restore')" class="btn-sm" style="background:var(--success); color:#fff; border:none; cursor:pointer; font-weight:600; border-radius:var(--radius-sm); display:inline-flex; align-items:center; gap:4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                Restore Selected
            </button>
            <button type="button" onclick="submitBulkAction('bulk_delete')" class="btn-sm" style="background:#ef4444; color:#fff; border:none; cursor:pointer; font-weight:600; border-radius:var(--radius-sm); display:inline-flex; align-items:center; gap:4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                Delete Selected
            </button>
        </div>
    </div>

    <!-- Main Bulk Form enclosing Table -->
    <form id="bulkForm" method="POST" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" id="bulkActionInput" value="">

        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:38px; text-align:center;">
                            <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" style="cursor:pointer; width:15px; height:15px;">
                        </th>
                        <th>Item / Name</th>
                        <th>Type</th>
                        <th>Original Source</th>
                        <th>Deleted By</th>
                        <th>Deleted At</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trash_items)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:50px 20px; color:var(--text-dim);">
                                <div style="display:flex; flex-direction:column; align-items:center; gap:10px;">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.4;">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                    <strong style="font-size:15px; color:var(--text-main);">Trash is Empty</strong>
                                    <span style="font-size:12.5px;">No deleted items found matching the current filter.</span>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($trash_items as $item): ?>
                            <?php 
                            $type_badge = 'badge-info';
                            if ($item['item_type'] === 'university') $type_badge = 'badge-primary';
                            if ($item['item_type'] === 'course') $type_badge = 'badge-warning';
                            if ($item['item_type'] === 'media') $type_badge = 'badge-success';
                            if ($item['item_type'] === 'mapping') $type_badge = 'badge-info';
                            if ($item['item_type'] === 'global_key') $type_badge = 'badge-info';
                            ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>" class="trash-item-checkbox" onclick="updateBulkToolbar()" style="cursor:pointer; width:15px; height:15px;">
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if ($item['item_type'] === 'media' && !empty($item['trash_file_path'])): ?>
                                            <div style="width:34px; height:34px; border-radius:6px; background:var(--bg-input); border:1px solid var(--border-color); overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                                <img src="<?php echo BASE_URL . '/' . htmlspecialchars($item['trash_file_path']); ?>" alt="" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none';">
                                            </div>
                                        <?php else: ?>
                                            <div class="uni-avatar" style="width:32px; height:32px; font-size:11px; flex-shrink:0;">
                                                <?php echo htmlspecialchars(substr($item['item_title'], 0, 2)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <strong style="font-size:13.5px; color:var(--text-main); display:block;">
                                                <?php echo htmlspecialchars($item['item_title']); ?>
                                            </strong>
                                            <span style="font-size:11px; color:var(--text-dim);">
                                                Original ID: #<?php echo (int)$item['original_id']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $type_badge; ?>" style="text-transform:capitalize; font-size:11px;">
                                        <?php echo htmlspecialchars($item['item_type']); ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="font-size:11.5px; color:var(--text-dim); background:rgba(255,255,255,0.05); padding:2px 6px; border-radius:4px;">
                                        <?php echo htmlspecialchars($item['source_table']); ?>
                                    </code>
                                </td>
                                <td>
                                    <span style="font-size:12px; color:var(--text-muted);">
                                        <?php echo htmlspecialchars($item['deleted_by_user_name'] ?? 'Admin'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:12px; color:var(--text-dim);">
                                        <?php echo date('d M Y, h:i A', strtotime($item['deleted_at'])); ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; align-items:center; gap:6px;">
                                        <!-- Single Restore Button Form -->
                                        <button type="button" class="action-btn" title="Restore this item" onclick="submitSingleAction('restore', <?php echo $item['id']; ?>)" style="color:var(--success); border-color:rgba(16,185,129,0.3);">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                                        </button>

                                        <!-- Single Permanent Delete Button Form -->
                                        <button type="button" class="action-btn" title="Delete permanently" onclick="submitSingleAction('permanent_delete', <?php echo $item['id']; ?>)" style="color:#ef4444; border-color:rgba(239,68,68,0.3);">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <!-- Pagination Footer -->
    <?php if ($total_trashed > $per_page): ?>
        <div style="padding:16px 20px; border-top:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; font-size:12.5px; color:var(--text-muted);">
            <div>Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $per_page, $total_trashed); ?> of <?php echo $total_trashed; ?> items</div>
            <div style="display:flex; gap:6px;">
                <?php 
                $total_pages = ceil($total_trashed / $per_page);
                for ($p = 1; $p <= $total_pages; $p++): 
                    $page_url = "?page=" . $p . "&type=" . urlencode($selected_type) . ($search ? '&q=' . urlencode($search) : '');
                ?>
                    <a href="<?php echo $page_url; ?>" class="btn-sm" style="<?php echo $p === $page ? 'background:var(--primary); color:#fff;' : 'background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main);'; ?> text-decoration:none; padding:4px 10px; border-radius:4px;">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Standalone Single Action Form -->
<form id="singleActionForm" method="POST" action="" style="display:none;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" id="singleActionInput" value="">
    <input type="hidden" name="id" id="singleIdInput" value="">
</form>

<script>
function toggleSelectAll(masterCheckbox) {
    var checkboxes = document.querySelectorAll('.trash-item-checkbox');
    checkboxes.forEach(function(cb) {
        cb.checked = masterCheckbox.checked;
    });
    updateBulkToolbar();
}

function updateBulkToolbar() {
    var checked = document.querySelectorAll('.trash-item-checkbox:checked');
    var toolbar = document.getElementById('bulkActionsToolbar');
    var badge = document.getElementById('selectedCountBadge');

    if (checked.length > 0) {
        toolbar.style.display = 'inline-flex';
        badge.textContent = checked.length + ' selected';
    } else {
        toolbar.style.display = 'none';
        var master = document.getElementById('selectAllCheckbox');
        if (master) master.checked = false;
    }
}

function submitBulkAction(actionName) {
    var checked = document.querySelectorAll('.trash-item-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one item.');
        return;
    }

    if (actionName === 'bulk_delete') {
        if (!confirm('Are you sure you want to PERMANENTLY DELETE ' + checked.length + ' selected item(s)? This action cannot be reversed!')) {
            return;
        }
    } else if (actionName === 'bulk_restore') {
        if (!confirm('Restore ' + checked.length + ' selected item(s) back to live tables?')) {
            return;
        }
    }

    document.getElementById('bulkActionInput').value = actionName;
    document.getElementById('bulkForm').submit();
}

function submitSingleAction(actionName, id) {
    if (actionName === 'permanent_delete') {
        if (!confirm('Are you sure you want to PERMANENTLY delete this item? This action cannot be undone!')) {
            return;
        }
    }
    document.getElementById('singleActionInput').value = actionName;
    document.getElementById('singleIdInput').value = id;
    document.getElementById('singleActionForm').submit();
}
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
