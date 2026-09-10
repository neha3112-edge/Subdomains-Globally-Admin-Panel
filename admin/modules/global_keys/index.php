<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('global_keys');

$page_title = 'Global Keys';
$page_subtitle = 'Manage dynamic shortcode variables and global text replacements';
$active_page_key = 'global_keys';

$db = get_db_connection();

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $key_code = trim($_POST['key_code'] ?? '');
        $key_value = trim($_POST['key_value'] ?? '');
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
            if ($id) {
                $stmt = $db->prepare("UPDATE global_keys SET key_code = ?, key_value = ?, description = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$key_code, $key_value, $description, $is_active, $id]);
                set_flash_message('Global key updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("INSERT INTO global_keys (key_code, key_value, description, is_active) VALUES (?, ?, ?, ?)");
                $stmt->execute([$key_code, $key_value, $description, $is_active]);
                set_flash_message('Global key created successfully!', 'success');
            }
            // Auto-flush cache on all active subdomains
            sode_bust_all_subdomain_caches($db);
            redirect(BASE_URL . '/modules/global_keys/index.php');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM global_keys WHERE id = ?");
            $stmt->execute([$id]);
            // Auto-flush cache on all active subdomains
            sode_bust_all_subdomain_caches($db);
            set_flash_message('Global key deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/global_keys/index.php');
        }
    }
}

/**
 * Pings each active university subdomain to clear its global keys cache.
 * Fire-and-forget async HTTP calls so admin doesn't wait.
 */
function sode_bust_all_subdomain_caches(PDO $db) {
    try {
        $unis = $db->query("SELECT slug FROM universities WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($unis as $slug) {
            // Derive subdomain URL from slug
            $subdomain_url = 'https://' . $slug . '.distanceeducationschool.com/?sode_flush=sode_flush_2026';
            // Fire async non-blocking request (doesn't wait for response)
            @file_get_contents($subdomain_url, false, stream_context_create([
                'http' => [
                    'timeout'         => 2,
                    'ignore_errors'   => true,
                    'method'          => 'GET'
                ],
                'ssl' => [
                    'verify_peer'     => false,
                    'verify_peer_name' => false,
                ]
            ]));
        }
    } catch (Exception $e) {
        // Non-critical — cache will auto-expire anyway
        error_log('SODE cache bust failed: ' . $e->getMessage());
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

// Fetch all keys
$keys = $db->query("SELECT * FROM global_keys ORDER BY id ASC")->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

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
                    <label class="form-label">Description / Usage Context</label>
                    <input type="text" name="description" class="form-control" value="<?php echo htmlspecialchars($edit_key['description'] ?? ''); ?>" placeholder="Active academic calendar year">
                </div>

                <div class="form-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px;">
                        <input type="checkbox" name="is_active" value="1" <?php echo (!isset($edit_key['is_active']) || !empty($edit_key['is_active'])) ? 'checked' : ''; ?>>
                        Active
                    </label>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo $edit_key ? 'Update Key' : 'Create Key'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Global Keys (<?php echo count($keys); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Key Code</th>
                        <th>Value</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($keys)): ?>
                        <tr><td colspan="5" style="text-align:center; color:var(--text-dim);">No global keys configured yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($keys as $k): ?>
                            <tr>
                                <td>
                                    <code style="font-size:13px; font-weight:700; color:var(--primary);"><?php echo htmlspecialchars($k['key_code']); ?></code>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($k['key_value']); ?></strong>
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
                                        <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php?edit_id=<?php echo $k['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>

                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $k['id']; ?>">
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

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
