<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
if (function_exists('require_permission')) {
    require_permission('levels');
}

$can_create = user_can('create') || user_can('write');
$can_edit = user_can('update') || user_can('write');
$can_delete = user_can('delete');

$page_title = 'Degree Levels';
$page_subtitle = 'Manage academic degree levels (UG, PG, Diploma, Doctorate). Used dynamically in Courses Master and Mappings.';
$active_page_key = 'levels';

$db = get_db_connection();

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Single Save / Update
    if ($action === 'save') {
        $level_id = (int)($_POST['level_id'] ?? 0);
        if ($level_id > 0 && !$can_edit) {
            set_flash_message('Access Denied: You do not have permission to edit degree levels.', 'error');
            redirect(BASE_URL . '/modules/settings/levels.php');
        }
        if ($level_id === 0 && !$can_create) {
            set_flash_message('Access Denied: You do not have permission to add degree levels.', 'error');
            redirect(BASE_URL . '/modules/settings/levels.php');
        }

        $name = trim($_POST['level_name'] ?? '');
        $code = trim($_POST['level_code'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            set_flash_message('Level name is required.', 'error');
        } else {
            // Auto generate code if empty
            if (empty($code)) {
                if (preg_match('/\((.*?)\)/', $name, $matches)) {
                    $code = strtoupper(trim($matches[1]));
                } else {
                    $words = preg_split('/\s+/', $name);
                    if (count($words) > 1) {
                        $code = '';
                        foreach ($words as $w) {
                            $code .= strtoupper(substr($w, 0, 1));
                        }
                    } else {
                        $code = strtoupper(substr($name, 0, 8));
                    }
                }
            }

            try {
                if ($level_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE degree_levels_master 
                        SET level_name = ?, level_code = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $code, $sort_order, $is_active, $level_id]);
                    set_flash_message('Degree level updated successfully!', 'success');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO degree_levels_master (level_name, level_code, sort_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$name, $code, $sort_order, $is_active]);
                    set_flash_message('New degree level added successfully!', 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash_message('A degree level with this code or name already exists.', 'error');
                } else {
                    set_flash_message('Database Error: ' . $e->getMessage(), 'error');
                }
            }
        }
        redirect(BASE_URL . '/modules/settings/levels.php');
    }

    // 2. Bulk Add Levels
    if ($action === 'bulk_save') {
        if (!$can_create) {
            set_flash_message('Access Denied: You do not have permission to bulk add degree levels.', 'error');
            redirect(BASE_URL . '/modules/settings/levels.php');
        }

        $raw_text = trim($_POST['bulk_levels'] ?? '');
        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $added = 0;
            $skipped = 0;

            $stmt = $db->prepare("
                INSERT INTO degree_levels_master (level_name, level_code, sort_order, is_active, created_at, updated_at)
                VALUES (?, ?, 0, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE level_name = VALUES(level_name)
            ");

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // Support "Postgraduate (PG) | PG" or just "Doctorate / Ph.D."
                if (strpos($line, '|') !== false) {
                    $parts = explode('|', $line, 2);
                    $lvl_name = trim($parts[0]);
                    $lvl_code = trim($parts[1]);
                } else {
                    $lvl_name = $line;
                    if (preg_match('/\((.*?)\)/', $lvl_name, $m)) {
                        $lvl_code = strtoupper(trim($m[1]));
                    } else {
                        $lvl_code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $lvl_name), 0, 10));
                    }
                }

                if (!empty($lvl_name) && !empty($lvl_code)) {
                    try {
                        $stmt->execute([$lvl_name, $lvl_code]);
                        if ($stmt->rowCount() > 0) {
                            $added++;
                        } else {
                            $skipped++;
                        }
                    } catch (Exception $ex) {
                        $skipped++;
                    }
                }
            }
            set_flash_message("Bulk Add Complete: {$added} levels processed.", 'success');
        } else {
            set_flash_message('Please enter at least one degree level.', 'error');
        }
        redirect(BASE_URL . '/modules/settings/levels.php');
    }

    // 3. Delete Level
    if ($action === 'delete') {
        if (!$can_delete) {
            set_flash_message('Access Denied: You do not have permission to delete degree levels.', 'error');
            redirect(BASE_URL . '/modules/settings/levels.php');
        }

        $level_id = (int)($_POST['level_id'] ?? 0);
        if ($level_id > 0) {
            // Check if any courses are using this level
            $lvl_stmt = $db->prepare("SELECT level_code, level_name FROM degree_levels_master WHERE id = ?");
            $lvl_stmt->execute([$level_id]);
            $lvl_row = $lvl_stmt->fetch();

            if ($lvl_row) {
                $check_courses = $db->prepare("SELECT COUNT(*) FROM courses WHERE level = ? OR level = ?");
                $check_courses->execute([$lvl_row['level_code'], $lvl_row['level_name']]);
                $used_count = (int)$check_courses->fetchColumn();

                if ($used_count > 0) {
                    set_flash_message("Cannot delete '{$lvl_row['level_name']}' because it is currently assigned to {$used_count} course(s).", 'error');
                } else {
                    $title = $lvl_row['level_name'] ?? 'Degree Level';
                    move_to_trash('degree_levels_master', $level_id, $title);
                    set_flash_message('Degree level moved to Trash! You can restore it anytime.', 'success');
                }
            }
        }
        redirect(BASE_URL . '/modules/settings/levels.php');
    }

    // 4. Toggle Status
    if ($action === 'toggle_status') {
        if (!$can_edit) {
            set_flash_message('Access Denied: You do not have permission to update status.', 'error');
            redirect(BASE_URL . '/modules/settings/levels.php');
        }

        $level_id = (int)($_POST['level_id'] ?? 0);
        if ($level_id > 0) {
            $stmt = $db->prepare("UPDATE degree_levels_master SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$level_id]);
            set_flash_message('Degree level status updated.', 'success');
        }
        redirect(BASE_URL . '/modules/settings/levels.php');
    }
}

// Edit Mode
$edit_level = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM degree_levels_master WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_level = $stmt->fetch();
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Fetch All Levels with pagination & search
$search = trim($_GET['q'] ?? '');
$params = [];
$where_sql = "";

if (!empty($search)) {
    $where_sql = "WHERE (level_name LIKE :q1 OR level_code LIKE :q2)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM degree_levels_master $where_sql");
    $count_stmt->execute($params);
    $total_count = (int)$count_stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT dlm.*, 
        (SELECT COUNT(*) FROM courses c WHERE c.level COLLATE utf8mb4_unicode_ci = dlm.level_code COLLATE utf8mb4_unicode_ci OR c.level COLLATE utf8mb4_unicode_ci = dlm.level_name COLLATE utf8mb4_unicode_ci) AS courses_count
        FROM degree_levels_master dlm
        $where_sql 
        ORDER BY dlm.sort_order ASC, dlm.id ASC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $total_count = (int)$db->query("SELECT COUNT(*) FROM degree_levels_master")->fetchColumn();

    $stmt = $db->prepare("
        SELECT dlm.*, 
        (SELECT COUNT(*) FROM courses c WHERE c.level COLLATE utf8mb4_unicode_ci = dlm.level_code COLLATE utf8mb4_unicode_ci OR c.level COLLATE utf8mb4_unicode_ci = dlm.level_name COLLATE utf8mb4_unicode_ci) AS courses_count
        FROM degree_levels_master dlm
        ORDER BY dlm.sort_order ASC, dlm.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$levels = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
.sode-btn-emerald {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.35);
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.sode-btn-emerald:hover {
    background: rgba(16, 185, 129, 0.25);
    color: #6ee7b7;
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
    transition: all 0.2s;
    background: none;
    border: none;
    cursor: pointer;
}
.status-pill.active {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.35);
}
.status-pill.active:hover {
    background: rgba(16, 185, 129, 0.25);
}
.status-pill.inactive {
    background: rgba(239, 68, 68, 0.15);
    color: #f87171;
    border: 1px solid rgba(239, 68, 68, 0.35);
}
.status-pill.inactive:hover {
    background: rgba(239, 68, 68, 0.25);
}
</style>

<!-- Top Header Section -->
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px;">
    <div>
        <h1 class="page-title" style="margin:0 0 6px 0; font-size:24px; font-weight:700; color:var(--text-main);">
            Degree Levels Master
        </h1>
        <p class="page-subtitle" style="margin:0; color:var(--text-muted); font-size:13.5px;">
            Manage academic degree levels (e.g. UG, PG, Diploma, Doctorate). Dynamically selectable when adding or editing Courses.
        </p>
    </div>
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span style="background:rgba(99, 102, 241, 0.12); color:#a5b4fc; padding:7px 14px; border-radius:20px; font-weight:700; font-size:13px; border:1px solid rgba(99, 102, 241, 0.3);">
            <?php echo $total_count; ?> Total Levels
        </span>
        <?php if ($can_create): ?>
        <button type="button" class="sode-btn-emerald" onclick="openBulkModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
            ⚡ Bulk Add
        </button>
        <?php else: ?>
        <button type="button" class="sode-btn-emerald btn-disabled-locked" title="Access Denied: You do not have permission to add degree levels" disabled style="opacity:0.5; cursor:not-allowed;">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            Bulk Add (Locked)
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search degree levels by name or code..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/settings/levels.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_level ? 'Edit Degree Level' : 'Add Degree Level'; ?></span>
            <?php if ($edit_level): ?>
                <a href="<?php echo BASE_URL; ?>/modules/settings/levels.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="level_id" value="<?php echo $edit_level['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Level Name *</label>
                    <input type="text" name="level_name" class="form-control" value="<?php echo htmlspecialchars($edit_level['level_name'] ?? ''); ?>" placeholder="e.g. Postgraduate (PG) or Doctorate" required <?php echo (($edit_level && !$can_edit) || (!$edit_level && !$can_create)) ? 'readonly' : ''; ?>>
                    <small style="font-size:11.5px; color:var(--text-dim); display:block; margin-top:4px;">Full descriptive title shown to students & admins.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Level Code *</label>
                    <input type="text" name="level_code" class="form-control" value="<?php echo htmlspecialchars($edit_level['level_code'] ?? ''); ?>" placeholder="e.g. PG, UG, Diploma, Ph.D." required <?php echo (($edit_level && !$can_edit) || (!$edit_level && !$can_create)) ? 'readonly' : ''; ?>>
                    <small style="font-size:11.5px; color:var(--text-dim); display:block; margin-top:4px;">Short identifier stored with course records.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="<?php echo htmlspecialchars($edit_level['sort_order'] ?? '0'); ?>" <?php echo (($edit_level && !$can_edit) || (!$edit_level && !$can_create)) ? 'readonly' : ''; ?>>
                </div>

                <div class="form-check-group" style="margin-bottom:20px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_active" value="1" <?php echo (!isset($edit_level) || !empty($edit_level['is_active'])) ? 'checked' : ''; ?> <?php echo (($edit_level && !$can_edit) || (!$edit_level && !$can_create)) ? 'disabled' : ''; ?>>
                        <span style="font-size:13px; font-weight:500;">Active Level (selectable in Courses Master)</span>
                    </label>
                </div>

                <?php if (($edit_level && $can_edit) || (!$edit_level && $can_create)): ?>
                <button type="submit" class="btn-primary" style="width:100%;">
                    <?php echo $edit_level ? 'Update Degree Level' : 'Save Degree Level'; ?>
                </button>
                <?php else: ?>
                <button type="button" class="btn-primary btn-disabled-locked" title="Access Denied: You do not have permission" disabled style="width:100%; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    <?php echo $edit_level ? 'Update Degree Level (Locked)' : 'Save Degree Level (Locked)'; ?>
                </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Degree Levels (<?php echo $total_count; ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:60px; text-align:center;">#</th>
                        <th>Level Name</th>
                        <th>Code</th>
                        <th>Linked Courses</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($levels)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:36px; color:var(--text-dim);"><?php echo $search !== '' ? 'No degree levels match your search query "' . htmlspecialchars($search) . '".' : 'No degree levels configured yet.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($levels as $idx => $lvl): ?>
                            <tr>
                                <td style="text-align:center; color:var(--text-dim); font-size:12px;">
                                    <?php echo $offset + $idx + 1; ?>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:var(--text-main);"><?php echo htmlspecialchars($lvl['level_name']); ?></div>
                                    <div style="font-size:11.5px; color:var(--text-dim);">Order: <?php echo $lvl['sort_order']; ?></div>
                                </td>
                                <td>
                                    <code style="font-size:12px; font-weight:700; color:var(--primary); background:rgba(99, 102, 241, 0.1); padding:3px 8px; border-radius:4px; border:1px solid rgba(99, 102, 241, 0.25);">
                                        <?php echo htmlspecialchars($lvl['level_code']); ?>
                                    </code>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php?q=<?php echo urlencode($lvl['level_code']); ?>" class="badge badge-info" style="text-decoration:none;">
                                        <?php echo $lvl['courses_count']; ?> Courses
                                    </a>
                                </td>
                                <td>
                                    <?php if ($can_edit): ?>
                                    <form method="POST" action="" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="level_id" value="<?php echo $lvl['id']; ?>">
                                        <button type="submit" class="status-pill <?php echo !empty($lvl['is_active']) ? 'active' : 'inactive'; ?>" title="Click to toggle status">
                                            <span style="width:6px; height:6px; border-radius:50%; background:currentColor; display:inline-block;"></span>
                                            <?php echo !empty($lvl['is_active']) ? 'Active' : 'Inactive'; ?>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <span class="status-pill <?php echo !empty($lvl['is_active']) ? 'active' : 'inactive'; ?>" style="cursor:not-allowed; opacity:0.7;" title="Access Denied: Read-only access">
                                            <span style="width:6px; height:6px; border-radius:50%; background:currentColor; display:inline-block;"></span>
                                            <?php echo !empty($lvl['is_active']) ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <div class="table-actions" style="justify-content:flex-end;">
                                        <?php if ($can_edit): ?>
                                        <a href="<?php echo BASE_URL; ?>/modules/settings/levels.php?edit_id=<?php echo $lvl['id']; ?>" class="action-btn" title="Edit Level">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <?php else: ?>
                                        <button type="button" class="action-btn disabled" title="Access Denied: You do not have edit permission" disabled style="opacity:0.4; cursor:not-allowed;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </button>
                                        <?php endif; ?>

                                        <?php if ($can_delete): ?>
                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="level_id" value="<?php echo $lvl['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete Level">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <button type="button" class="action-btn disabled" title="Access Denied: You do not have delete permission" disabled style="opacity:0.4; cursor:not-allowed;">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo sode_render_pagination($total_count, $page, $per_page); ?>
    </div>
</div>

<!-- Bulk Add Modal -->
<div id="bulk-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:99999; align-items:center; justify-content:center;">
    <div style="background:var(--bg-card, #0f172a); border:1px solid var(--border-color, #1e2b45); border-radius:12px; width:90%; max-width:520px; padding:24px; box-shadow:var(--shadow-lg);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="margin:0; font-size:16px; font-weight:700; color:var(--text-main);">⚡ Bulk Add Degree Levels</h3>
            <button type="button" onclick="closeBulkModal()" style="background:none; border:none; color:var(--text-dim); font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_save">
            <p style="font-size:12.5px; color:var(--text-muted); margin:0 0 12px;">
                Enter one degree level per line. You can also specify short code using a pipe <code>|</code> separator.
            </p>
            <div style="background:var(--bg-input, #151f32); border:1px solid var(--border-color, #1e2b45); border-radius:8px; padding:10px; font-size:11.5px; color:var(--text-dim); margin-bottom:12px; font-family:monospace;">
                Executive Program | Executive<br>
                Postgraduate Diploma (PGD) | PGD<br>
                Associate Degree | Associate
            </div>
            <textarea name="bulk_levels" class="form-textarea" rows="6" placeholder="Enter levels, one per line..." required style="width:100%; margin-bottom:16px;"></textarea>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeBulkModal()" class="btn-sm action-btn" style="padding:8px 16px;">Cancel</button>
                <button type="submit" class="btn-primary" style="width:auto; padding:8px 20px;">Save All Levels</button>
            </div>
        </form>
    </div>
</div>

<script>
function openBulkModal() {
    document.getElementById('bulk-modal').style.display = 'flex';
}
function closeBulkModal() {
    document.getElementById('bulk-modal').style.display = 'none';
}
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
