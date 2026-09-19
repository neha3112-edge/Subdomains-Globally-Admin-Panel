<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('global_keys');

$page_title = 'Global Keys';
$page_subtitle = 'Manage dynamic shortcode variables and global text replacements';
$active_page_key = 'global_keys';

$db = get_db_connection();

// Ensure link_url column exists in global_keys
try {
    $col_chk = $db->query("SHOW COLUMNS FROM global_keys LIKE 'link_url'")->fetch();
    if (!$col_chk) {
        $db->exec("ALTER TABLE global_keys ADD COLUMN link_url VARCHAR(500) NULL AFTER key_value");
    }
} catch (Exception $e) {}

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        if ($id && !user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update global keys.', 'error');
            redirect(BASE_URL . '/modules/global_keys/index.php');
        } elseif (!$id && !user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create global keys.', 'error');
            redirect(BASE_URL . '/modules/global_keys/index.php');
        }
        $key_code = trim($_POST['key_code'] ?? '');
        $key_value = trim($_POST['key_value'] ?? '');
        $link_url = trim($_POST['link_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Auto wrap with $ if not present
        if (!empty($key_code)) {
            if ($key_code[0] !== '$') $key_code = '$' . $key_code;
            if (substr($key_code, -1) !== '$') $key_code = $key_code . '$';
        }

        if (empty($key_code) || empty($key_value)) {
            set_flash_message('Key Code and Key Value are required.', 'error');
        } else {
            $new_data = [
                'key_code' => $key_code,
                'key_value' => $key_value,
                'link_url' => $link_url,
                'description' => $description,
                'is_active' => $is_active
            ];

            if ($id) {
                $old_data = $db->query("SELECT * FROM global_keys WHERE id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
                $stmt = $db->prepare("UPDATE global_keys SET key_code = ?, key_value = ?, link_url = ?, description = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$key_code, $key_value, $link_url, $description, $is_active, $id]);

                if (function_exists('log_activity')) {
                    log_activity('UPDATE', 'global_keys', "Updated Global Key '{$key_code}'", [
                        'item_type' => 'Global Key',
                        'item_id' => $id,
                        'item_title' => $key_code,
                        'old_values' => $old_data,
                        'new_values' => $new_data
                    ]);
                }

                set_flash_message('Global key updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("INSERT INTO global_keys (key_code, key_value, link_url, description, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$key_code, $key_value, $link_url, $description, $is_active]);
                $new_id = (int)$db->lastInsertId();

                if (function_exists('log_activity')) {
                    log_activity('CREATE', 'global_keys', "Created new Global Key '{$key_code}'", [
                        'item_type' => 'Global Key',
                        'item_id' => $new_id,
                        'item_title' => $key_code,
                        'new_values' => $new_data
                    ]);
                }

                set_flash_message('Global key created successfully!', 'success');
            }
            // Auto-flush cache on all active subdomains
            sode_bust_all_subdomain_caches($db);
            redirect(BASE_URL . '/modules/global_keys/index.php');
        }
    } elseif ($action === 'delete') {
        if (!user_can('delete')) {
            set_flash_message('Access Denied: You do not have permission to delete global keys.', 'error');
            redirect(BASE_URL . '/modules/global_keys/index.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $ok = move_to_trash('global_keys', $id);
            if ($ok) {
                set_flash_message('Global key moved to Trash! You can restore it anytime.', 'success');
            } else {
                set_flash_message('Failed to move global key to Trash.', 'error');
            }
            redirect(BASE_URL . '/modules/global_keys/index.php');
        }
    }
}

// Edit Mode
$edit_key = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM global_keys WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_key = $stmt->fetch();
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (key_code LIKE :q1 OR key_value LIKE :q2 OR description LIKE :q3)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
    $params[':q3'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM global_keys $where_sql");
    $count_stmt->execute($params);
    $total_keys = (int)$count_stmt->fetchColumn();
} else {
    $total_keys = (int)$db->query("SELECT COUNT(*) FROM global_keys")->fetchColumn();
}

// Fetch paginated keys
$stmt = $db->prepare("SELECT * FROM global_keys $where_sql ORDER BY id ASC LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$keys = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search global keys by key code ($KEY$), value, or description..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_key ? 'Edit Global Key' : 'Add New Key'; ?></span>
            <?php if ($edit_key): ?>
                <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_key['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Key Code * (e.g. $YEAR$, $session$)</label>
                    <input type="text" name="key_code" class="form-control" value="<?php echo htmlspecialchars($edit_key['key_code'] ?? ''); ?>" placeholder="$YEAR$" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Replacement Value *</label>
                    <textarea name="key_value" class="form-textarea" placeholder="2026" required><?php echo htmlspecialchars($edit_key['key_value'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Link URL (Optional)</label>
                    <input type="text" name="link_url" class="form-control" value="<?php echo htmlspecialchars($edit_key['link_url'] ?? ''); ?>" placeholder="e.g. tel:+917065777755, mailto:info@example.com, https://...">
                    <small style="color:var(--text-muted); font-size:11.5px; margin-top:4px; display:block;">
                        💡 <strong>URL daloge</strong> toh key automatically clickable link (<code>&lt;a href="..."&gt;text&lt;/a&gt;</code>) ban jayegi. Empty chhodne par sirf normal text fetch hoga.
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Usage Context</label>
                    <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars($edit_key['description'] ?? ''); ?>" placeholder="Active academic calendar year">
                </div>

                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px;">
                        <input type="checkbox" name="is_active" value="1" <?php echo (!isset($edit_key['is_active']) || !empty($edit_key['is_active'])) ? 'checked' : ''; ?>>
                        Active
                    </label>
                </div>

                <?php if ($edit_key): ?>
                    <?php if (can_update()): ?>
                        <button type="submit" class="btn-primary">Update Key</button>
                    <?php else: ?>
                        <button type="button" class="btn-secondary btn-disabled-locked" disabled title="Access Denied: You do not have permission to update">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            Update Key (Locked)
                        </button>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (can_create()): ?>
                        <button type="submit" class="btn-primary">Create Key</button>
                    <?php else: ?>
                        <button type="button" class="btn-secondary btn-disabled-locked" disabled title="Access Denied: You do not have permission to create">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                            Create Key (Locked)
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Global Keys (<?php echo $total_keys; ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Key Code</th>
                        <th>Value</th>
                        <th>Link URL</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($keys)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:32px; color:var(--text-dim);"><?php echo $search !== '' ? 'No global keys match your search query "' . htmlspecialchars($search) . '".' : 'No global keys configured yet.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($keys as $k): ?>
                            <tr>
                                <td>
                                    <code style="font-size:13px; font-weight:700; color:var(--primary);"><?php echo htmlspecialchars($k['key_code']); ?></code>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($k['key_value']); ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($k['link_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($k['link_url']); ?>" target="_blank" style="text-decoration:none;">
                                            <code style="font-size:12px; color:var(--primary); background:rgba(37,99,235,0.08); padding:3px 6px; border-radius:4px;"><?php echo htmlspecialchars($k['link_url']); ?></code>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--text-dim); font-size:13px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--text-dim); font-size:12px;">
                                    <?php echo htmlspecialchars($k['description'] ?? '-'); ?>
                                </td>
                                <td>
                                    <?php if ($k['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <?php echo rbac_render_edit_button(BASE_URL . '/modules/global_keys/index.php?edit_id=' . $k['id'], 'Edit'); ?>

                                        <?php echo rbac_render_delete_button($k['id'], 'Delete'); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo sode_render_pagination($total_keys, $page, $per_page); ?>
    </div>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
