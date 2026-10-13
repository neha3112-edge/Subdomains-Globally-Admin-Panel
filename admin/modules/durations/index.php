<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('durations');

$page_title = 'Durations Master';
$page_subtitle = 'Manage global master list of durations. Selectable in all course mappings.';
$active_page_key = 'durations';

$db = get_db_connection();

// Handle POST Actions (Save / Bulk Save / Edit / Delete / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Single Save / Update
    if ($action === 'save') {
        $dur_id = (int)($_POST['dur_id'] ?? 0);
        if ($dur_id > 0 && !user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update durations.', 'error');
            redirect(BASE_URL . '/modules/durations/index.php');
        } elseif (!$dur_id && !user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create durations.', 'error');
            redirect(BASE_URL . '/modules/durations/index.php');
        }
        $title = trim($_POST['duration_title'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($title)) {
            set_flash_message('Duration title is required.', 'error');
        } else {
            try {
                if ($dur_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE course_durations_master 
                        SET duration_title = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$title, $sort_order, $is_active, $dur_id]);
                    set_flash_message('Duration updated successfully!', 'success');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO course_durations_master (duration_title, sort_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$title, $sort_order, $is_active]);
                    set_flash_message('New duration added successfully!', 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash_message('A duration with this title already exists in the master library.', 'error');
                } else {
                    set_flash_message('Database Error: ' . $e->getMessage(), 'error');
                }
            }
        }
        redirect(BASE_URL . '/modules/durations/index.php');
    }

    // 2. Bulk Add Durations
    if ($action === 'bulk_save') {
        if (!user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create durations.', 'error');
            redirect(BASE_URL . '/modules/durations/index.php');
        }
        $raw_text = trim($_POST['bulk_durations'] ?? '');
        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $added = 0;
            $skipped = 0;

            $stmt = $db->prepare("
                INSERT IGNORE INTO course_durations_master (duration_title, sort_order, is_active, created_at, updated_at)
                VALUES (?, 0, 1, NOW(), NOW())
            ");

            foreach ($lines as $line) {
                $dur = trim($line);
                // Strip numbers/bullets like "1.", "-", "*"
                $dur = preg_replace('/^(\d+[\.\)\-]?|\-|\*)\s*/', '', $dur);
                $dur = trim($dur);
                if (!empty($dur)) {
                    $stmt->execute([$dur]);
                    if ($stmt->rowCount() > 0) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
            }
            set_flash_message("Bulk Add Complete: {$added} durations added" . ($skipped > 0 ? " ({$skipped} duplicates skipped)." : "."), 'success');
        } else {
            set_flash_message('Please enter at least one duration.', 'error');
        }
        redirect(BASE_URL . '/modules/durations/index.php');
    }

    // 3. Delete Duration
    if ($action === 'delete') {
        if (!user_can('delete')) {
            set_flash_message('Access Denied: You do not have permission to delete durations.', 'error');
            redirect(BASE_URL . '/modules/durations/index.php');
        }
        $dur_id = (int)($_POST['dur_id'] ?? 0);
        if ($dur_id > 0) {
            $ok = move_to_trash('course_durations_master', $dur_id);
            if ($ok) {
                set_flash_message('Duration moved to Trash! You can restore it anytime.', 'success');
            } else {
                set_flash_message('Failed to move duration to Trash.', 'error');
            }
        }
        redirect(BASE_URL . '/modules/durations/index.php');
    }

    // 4. Toggle Status
    if ($action === 'toggle_status') {
        if (!user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update durations.', 'error');
            redirect(BASE_URL . '/modules/durations/index.php');
        }
        $dur_id = (int)($_POST['dur_id'] ?? 0);
        if ($dur_id > 0) {
            $stmt = $db->prepare("UPDATE course_durations_master SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$dur_id]);
            set_flash_message('Duration status updated.', 'success');
        }
        redirect(BASE_URL . '/modules/durations/index.php');
    }
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Fetch All Durations with pagination
$search = trim($_GET['q'] ?? '');
if (!empty($search)) {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM course_durations_master WHERE duration_title LIKE :q");
    $count_stmt->execute([':q' => '%' . $search . '%']);
    $total_count = (int)$count_stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT cdm.*,
            (SELECT COUNT(DISTINCT cs.mapping_id) 
             FROM course_specializations cs 
             WHERE cs.duration = cdm.duration_title COLLATE utf8mb4_unicode_ci
            ) AS mapped_count
        FROM course_durations_master cdm 
        WHERE cdm.duration_title LIKE :q 
        ORDER BY cdm.sort_order ASC, cdm.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':q', '%' . $search . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $total_count = (int)$db->query("SELECT COUNT(*) FROM course_durations_master")->fetchColumn();

    $stmt = $db->prepare("
        SELECT cdm.*,
            (SELECT COUNT(DISTINCT cs.mapping_id) 
             FROM course_specializations cs 
             WHERE cs.duration = cdm.duration_title COLLATE utf8mb4_unicode_ci
            ) AS mapped_count
        FROM course_durations_master cdm 
        ORDER BY cdm.sort_order ASC, cdm.id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$durations = $stmt->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__, 2) . '/includes/header.php';
?>

<style>
/* Modern Master Styling */
.master-header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

/* Polished Bordered Table */
.data-table.bordered-table {
    border: 1px solid var(--border-color, #1e2b45) !important;
    border-radius: 12px !important;
    overflow: hidden !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    background: var(--bg-card, #0f172a) !important;
}

.data-table.bordered-table thead th {
    background: rgba(15, 23, 42, 0.95) !important;
    border-bottom: 2px solid var(--border-color, #1e2b45) !important;
    border-right: 1px solid rgba(255, 255, 255, 0.05) !important;
    padding: 12px 16px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    color: var(--text-muted, #94a3b8) !important;
}

.data-table.bordered-table thead th:last-child {
    border-right: none !important;
}

.data-table.bordered-table tbody td {
    border-bottom: 1px solid var(--border-color, #1e2b45) !important;
    border-right: 1px solid rgba(255, 255, 255, 0.03) !important;
    padding: 12px 16px !important;
    font-size: 13px !important;
    vertical-align: middle !important;
}

.data-table.bordered-table tbody td:last-child {
    border-right: none !important;
}

.data-table.bordered-table tbody tr:last-child td {
    border-bottom: none !important;
}

.data-table.bordered-table tbody tr:hover td {
    background: rgba(99, 102, 241, 0.04) !important;
}

/* Polished Buttons */
.btn-primary-glow {
    background: var(--primary-gradient, linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)) !important;
    color: #fff !important;
    border: none !important;
    padding: 8px 18px !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    font-weight: 700 !important;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35) !important;
    transition: all 0.2s ease !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-primary-glow:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(79, 70, 229, 0.5) !important;
}

.btn-secondary-glow {
    background: rgba(30, 41, 59, 0.8) !important;
    color: #cbd5e1 !important;
    border: 1px solid var(--border-color, #1e2b45) !important;
    padding: 8px 16px !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    cursor: pointer;
    transition: all 0.2s ease !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-secondary-glow:hover {
    background: #1e293b !important;
    color: #fff !important;
    border-color: rgba(99, 102, 241, 0.4) !important;
}

/* Modal Dark Styles */
.sode-modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.sode-modal {
    background: var(--bg-card, #0f172a);
    border: 1px solid var(--border-color, #1e2b45);
    border-radius: 14px;
    width: 100%;
    max-width: 500px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
    overflow: hidden;
    animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
.sode-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color, #1e2b45);
    background: rgba(255, 255, 255, 0.02);
}
.sode-modal-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--text-main, #f8fafc);
}
.sode-modal-close {
    background: transparent;
    border: none;
    color: var(--text-dim, #64748b);
    font-size: 20px;
    cursor: pointer;
    line-height: 1;
}
.sode-modal-close:hover {
    color: #fff;
}
.sode-modal-body {
    padding: 20px;
}
.sode-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 20px;
    border-top: 1px solid var(--border-color, #1e2b45);
    background: rgba(255, 255, 255, 0.01);
}
</style>

<!-- Top Stats and Actions -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="font-size:20px; font-weight:800; color:var(--text-main, #f8fafc); margin:0 0 4px;">Durations Master</h2>
        <p style="font-size:12.5px; color:var(--text-dim, #64748b); margin:0;">
            Global master library of course durations (e.g. 2 Years, 6 Months). Admin selects these in Course Mappings.
        </p>
    </div>
    <div class="master-header-actions">
        <?php if (can_create()): ?>
            <button type="button" class="btn-secondary-glow" onclick="openBulkModal()">
                <span>⚡</span> Bulk Add Durations
            </button>
            <button type="button" class="btn-primary-glow" onclick="openAddModal()">
                <span>+</span> Add Duration
            </button>
        <?php else: ?>
            <button type="button" class="btn-secondary btn-disabled-locked" disabled title="Access Denied: You do not have Create permission">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Bulk Add (Locked)
            </button>
            <button type="button" class="btn-secondary btn-disabled-locked" disabled title="Access Denied: You do not have Create permission">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Add Duration (Locked)
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Table Card -->
<div class="admin-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <form method="GET" action="" style="display:flex; gap:10px; align-items:center; max-width:360px; width:100%;">
            <input type="text" name="q" class="form-control" placeholder="Search duration..." value="<?php echo htmlspecialchars($search); ?>" style="font-size:13px;">
            <?php if (!empty($search)): ?>
                <a href="<?php echo BASE_URL; ?>/modules/durations/index.php" class="btn-sm action-btn" style="text-decoration:none; padding:8px 12px;">Clear</a>
            <?php endif; ?>
        </form>
        <span style="font-size:12px; color:var(--text-dim, #64748b); font-weight:600;">
            Total: <strong><?php echo $total_count; ?></strong> Durations
        </span>
    </div>

    <div class="card-body" style="padding:0;">
        <?php if (empty($durations)): ?>
            <div style="padding:40px 20px; text-align:center; color:var(--text-dim);">
                <div style="font-size:32px; margin-bottom:10px;">⏱️</div>
                <p style="font-size:14px; font-weight:600; margin:0 0 6px;">No durations found</p>
                <p style="font-size:12px; color:var(--text-dim); margin:0 0 16px;">Add durations to make them selectable in Course Mappings.</p>
                <?php if (can_create()): ?>
                    <button type="button" class="btn-primary-glow" onclick="openAddModal()">+ Add First Duration</button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table bordered-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">#</th>
                            <th>Duration Title</th>
                            <th style="width:160px; text-align:center;">Mapped Courses</th>
                            <th style="width:120px; text-align:center;">Sort Order</th>
                            <th style="width:110px; text-align:center;">Status</th>
                            <th style="width:120px; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($durations as $idx => $d): ?>
                            <tr>
                                <td style="text-align:center; color:var(--text-dim); font-size:12px; font-weight:600;">
                                    <?php echo $offset + $idx + 1; ?>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span style="color:#a5b4fc; font-size:14px;">⏱️</span>
                                        <strong style="color:var(--text-main, #f8fafc); font-size:13.5px;">
                                            <?php echo htmlspecialchars($d['duration_title']); ?>
                                        </strong>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <?php $mapped_cnt = (int)($d['mapped_count'] ?? 0); ?>
                                    <?php if ($mapped_cnt > 0): ?>
                                        <button type="button" 
                                                onclick="openMappedDurModal(<?= (int)$d['id'] ?>, '<?= htmlspecialchars(addslashes($d['duration_title'])) ?>')" 
                                                class="badge sode-dur-badge" 
                                                style="background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.35); font-weight: 700; font-size: 11.5px; padding: 4px 11px; border-radius: 20px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; transition: all 0.2s;" 
                                                title="Click to view all mapped courses with duration <?= htmlspecialchars($d['duration_title']) ?>">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                                            <?= $mapped_cnt ?> <?= $mapped_cnt === 1 ? 'Mapping' : 'Mappings' ?> &rarr;
                                        </button>
                                    <?php else: ?>
                                        <span class="badge" style="background: rgba(148, 163, 184, 0.08); color: var(--text-dim, #64748b); border: 1px solid rgba(148, 163, 184, 0.15); font-weight: 600; font-size: 11px; padding: 4px 9px; border-radius: 20px;">
                                            0 Mappings
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center; color:var(--text-dim); font-weight:600; font-size:12px;">
                                    <?php echo htmlspecialchars($d['sort_order']); ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (can_update()): ?>
                                        <form method="POST" action="" style="margin:0; display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="dur_id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" style="background:none; border:none; padding:0; cursor:pointer;" title="Click to toggle active state">
                                                <?php if ($d['is_active']): ?>
                                                    <span class="badge badge-success" style="cursor:pointer;">Active</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger" style="cursor:pointer;">Inactive</span>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <?php if ($d['is_active']): ?>
                                            <span class="badge badge-success" title="Active">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger" title="Inactive">Inactive</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                                        <?php if (can_update()): ?>
                                            <button type="button" class="action-btn edit-btn" title="Edit Duration" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($d)); ?>)">
                                                ✏️
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="action-btn disabled" disabled title="Access Denied: You do not have Edit permission">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (can_delete()): ?>
                                            <form method="POST" action="" style="margin:0; display:inline;" onsubmit="return confirm('Delete this duration from master library?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="dur_id" value="<?php echo $d['id']; ?>">
                                                <button type="submit" class="action-btn delete-btn" title="Delete Duration">
                                                    🗑️
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="action-btn delete-btn disabled" disabled title="Access Denied: You do not have Delete permission">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php echo sode_render_pagination($total_count, $page, $per_page); ?>
</div>

<!-- Modal: Add / Edit Duration -->
<div id="durationModal" class="sode-modal-overlay">
    <div class="sode-modal">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="dur_id" id="modal_dur_id" value="0">

            <div class="sode-modal-header">
                <span class="sode-modal-title" id="modal_title">Add New Duration</span>
                <button type="button" class="sode-modal-close" onclick="closeModal('durationModal')">&times;</button>
            </div>
            <div class="sode-modal-body" style="display:flex; flex-direction:column; gap:14px;">
                <div class="form-group">
                    <label class="form-label">Duration Title *</label>
                    <input type="text" name="duration_title" id="modal_dur_title" class="form-control" placeholder="e.g. 2 Years or 6 Months" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="modal_dur_sort" class="form-control" value="0">
                </div>
                <div class="form-group" style="margin-top:4px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:var(--text-main);">
                        <input type="checkbox" name="is_active" id="modal_dur_active" value="1" checked>
                        <span>Active (Selectable in Course Mappings)</span>
                    </label>
                </div>
            </div>
            <div class="sode-modal-footer">
                <button type="button" class="btn-secondary-glow" onclick="closeModal('durationModal')">Cancel</button>
                <button type="submit" class="btn-primary-glow" id="modal_submit_btn">Save Duration</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Bulk Add Durations -->
<div id="bulkModal" class="sode-modal-overlay">
    <div class="sode-modal">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_save">

            <div class="sode-modal-header">
                <span class="sode-modal-title">⚡ Bulk Add Durations</span>
                <button type="button" class="sode-modal-close" onclick="closeModal('bulkModal')">&times;</button>
            </div>
            <div class="sode-modal-body">
                <p style="font-size:12.5px; color:var(--text-dim); margin:0 0 10px;">
                    Paste multiple duration titles below (one duration per line). Numbered/bulleted lists will be cleaned automatically.
                </p>
                <div class="form-group">
                    <textarea name="bulk_durations" rows="8" class="form-control" placeholder="6 Months&#10;1 Year&#10;1.5 Years&#10;2 Years&#10;3 Years&#10;4 Years" required style="font-size:13px; line-height:1.5;"></textarea>
                </div>
            </div>
            <div class="sode-modal-footer">
                <button type="button" class="btn-secondary-glow" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" class="btn-primary-glow">⚡ Add All Durations</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modal_dur_id').value = '0';
    document.getElementById('modal_dur_title').value = '';
    document.getElementById('modal_dur_sort').value = '0';
    document.getElementById('modal_dur_active').checked = true;
    document.getElementById('modal_title').textContent = 'Add New Duration';
    document.getElementById('modal_submit_btn').textContent = 'Save Duration';
    document.getElementById('durationModal').style.display = 'flex';
    document.getElementById('modal_dur_title').focus();
}

function openEditModal(d) {
    document.getElementById('modal_dur_id').value = d.id;
    document.getElementById('modal_dur_title').value = d.duration_title || '';
    document.getElementById('modal_dur_sort').value = d.sort_order || 0;
    document.getElementById('modal_dur_active').checked = (parseInt(d.is_active) === 1);
    document.getElementById('modal_title').textContent = 'Edit Duration: ' + (d.duration_title || '');
    document.getElementById('modal_submit_btn').textContent = 'Update Duration';
    document.getElementById('durationModal').style.display = 'flex';
    document.getElementById('modal_dur_title').focus();
}

function openBulkModal() {
    document.getElementById('bulkModal').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal on click outside
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('sode-modal-overlay')) {
        e.target.style.display = 'none';
    }
    if (e.target.id === 'mappedDurModal') {
        closeMappedDurModal();
    }
});
</script>

<!-- Mapped Course Mappings Modal for Duration -->
<div id="mappedDurModal" class="uni-courses-modal-backdrop" style="display: none;" onclick="handleDurBackdropClick(event)">
    <div class="uni-courses-modal-dialog" onclick="event.stopPropagation()">
        <!-- Modal Header -->
        <div class="uni-courses-modal-header">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; color: #818cf8; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div style="min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 9px; flex-wrap: wrap;">
                        <h3 id="modalDurTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: #fff; line-height: 1.3;">Duration Mappings</h3>
                        <span id="modalDurCountBadge" class="badge badge-warning" style="font-size: 11px;">0 Mappings</span>
                    </div>
                    <div id="modalDurMeta" style="font-size: 12px; color: var(--text-dim, #94a3b8); margin-top: 3px;">
                        Loading mapped courses...
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                <a href="<?= BASE_URL ?>/modules/mappings/create.php" target="_blank" class="uni-modal-add-btn" title="Create new course mapping">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>New Mapping</span>
                </a>
                <button type="button" onclick="closeMappedDurModal()" class="uni-modal-close-btn" title="Close (Esc)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="uni-courses-toolbar">
            <div class="uni-courses-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="modalDurSearchInput" placeholder="Filter by university, course name, mode, specializations..." oninput="handleDurSearchInput()">
                <button type="button" id="modalDurClearSearchBtn" onclick="clearDurSearch()" style="display: none; background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 0 4px; font-size: 16px; line-height: 1;">&times;</button>
            </div>
            <div class="uni-courses-filter-pills" id="modalDurFilterPills">
                <button type="button" class="filter-pill active" data-filter="ALL" onclick="setDurFilter('ALL', this)">All (<span id="countDurAll">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Online" onclick="setDurFilter('Online', this)">Online (<span id="countDurOnline">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Distance" onclick="setDurFilter('Distance', this)">Distance (<span id="countDurDistance">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Active" onclick="setDurFilter('Active', this)">Active (<span id="countDurActive">0</span>)</button>
            </div>
        </div>

        <!-- Modal Body Content -->
        <div class="uni-courses-modal-body">
            <!-- Loading State -->
            <div id="modalDurLoadingState" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; gap: 14px;">
                <div class="uni-modal-spinner"></div>
                <div style="color: var(--text-dim, #94a3b8); font-size: 13px;">Fetching mapped courses...</div>
            </div>

            <!-- Error State -->
            <div id="modalDurErrorState" style="display: none; text-align: center; padding: 50px 20px;">
                <div style="color: #ef4444; font-size: 14px; margin-bottom: 12px;" id="modalDurErrorMessage">Failed to load mapped courses.</div>
                <button type="button" onclick="retryLoadMappedDurs()" class="btn-primary btn-sm">Retry</button>
            </div>

            <!-- Empty State -->
            <div id="modalDurEmptyState" style="display: none; text-align: center; padding: 60px 20px;">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(99, 102, 241, 0.12); display: inline-flex; align-items: center; justify-content: center; color: #818cf8; margin-bottom: 14px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <h4 style="margin: 0 0 6px 0; color: #fff; font-size: 16px;">No Course Mappings Found</h4>
                <p style="margin: 0 0 18px 0; color: var(--text-dim, #94a3b8); font-size: 13px; max-width: 380px; margin-inline: auto;">
                    This duration is not currently assigned to any specializations in course mappings.
                </p>
                <a href="<?= BASE_URL ?>/modules/mappings/create.php" target="_blank" class="uni-modal-add-btn" style="display: inline-flex; margin-inline: auto;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Create Course Mapping</span>
                </a>
            </div>

            <!-- Mappings Table -->
            <div id="modalDurTableWrap" style="display: none;">
                <div style="overflow-x: auto;">
                    <table class="admin-table modal-courses-table" style="width: 100%; margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">#</th>
                                <th>University</th>
                                <th>Course Program</th>
                                <th style="width: 100px; text-align: center;">Mode</th>
                                <th>Specialization(s)</th>
                                <th style="width: 120px;">Fee / Sem</th>
                                <th style="width: 90px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="modalDurTbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
                <div id="modalDurNoMatches" style="display: none; text-align: center; padding: 40px 20px; color: var(--text-dim, #94a3b8); font-size: 13px;">
                    No mapped courses found matching your search / filter.
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="uni-courses-modal-footer">
            <div style="font-size: 12px; color: var(--text-dim, #94a3b8);">
                Showing <strong id="modalDurShownCount" style="color: #fff;">0</strong> of <strong id="modalDurTotalCount" style="color: #fff;">0</strong> mapped courses
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="uni-modal-footer-close-btn" onclick="closeMappedDurModal()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <span>Close</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Duration Mappings Modal Styling */
.uni-courses-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(4, 7, 15, 0.78);
    backdrop-filter: blur(6px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: uniModalBgFade 0.2s ease-out;
}
@keyframes uniModalBgFade {
    from { opacity: 0; }
    to { opacity: 1; }
}

.uni-courses-modal-dialog {
    background: #0d1424;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    max-width: 980px;
    width: 100%;
    max-height: 88vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(99, 102, 241, 0.15);
    overflow: hidden;
    animation: uniModalZoom 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes uniModalZoom {
    from { opacity: 0; transform: scale(0.96) translateY(8px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.uni-courses-modal-header {
    padding: 16px 24px;
    background: #141d33;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.uni-modal-add-btn {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    height: 34px !important;
    padding: 0 14px !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
    text-decoration: none !important;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important;
    color: #ffffff !important;
    border: 1px solid rgba(129, 140, 248, 0.4) !important;
    box-shadow: 0 3px 12px rgba(79, 70, 229, 0.35) !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    cursor: pointer !important;
}
.uni-modal-add-btn:hover {
    transform: translateY(-1.5px) !important;
    box-shadow: 0 6px 18px rgba(79, 70, 229, 0.5) !important;
    color: #ffffff !important;
    filter: brightness(1.08) !important;
}

.uni-modal-close-btn {
    width: 34px !important;
    height: 34px !important;
    background: #19243d !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    border-radius: 8px !important;
    color: #94a3b8 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    cursor: pointer !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    padding: 0 !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25) !important;
}
.uni-modal-close-btn:hover {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #f87171 !important;
    border-color: rgba(239, 68, 68, 0.4) !important;
    transform: rotate(90deg) scale(1.05) !important;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3) !important;
}

.uni-modal-edit-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    height: 32px !important;
    padding: 0 14px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    border-radius: 7px !important;
    text-decoration: none !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    background: rgba(99, 102, 241, 0.14) !important;
    color: #a5b4fc !important;
    border: 1px solid rgba(99, 102, 241, 0.32) !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2) !important;
}
.uni-modal-edit-btn:hover {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important;
    color: #ffffff !important;
    border-color: #818cf8 !important;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.45) !important;
    transform: translateY(-1.5px) !important;
}

.uni-modal-footer-close-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    height: 36px !important;
    padding: 0 18px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
    background: #19243d !important;
    color: #cbd5e1 !important;
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    cursor: pointer !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25) !important;
}
.uni-modal-footer-close-btn:hover {
    background: rgba(239, 68, 68, 0.16) !important;
    color: #f87171 !important;
    border-color: rgba(239, 68, 68, 0.4) !important;
    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.25) !important;
    transform: translateY(-1px) !important;
}

.uni-courses-toolbar {
    padding: 12px 24px;
    background: #10182b;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.uni-courses-search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #17223b;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 6px 12px;
    flex: 1;
    min-width: 240px;
    max-width: 420px;
    color: #94a3b8;
}
.uni-courses-search-wrap input {
    background: transparent;
    border: none;
    outline: none;
    color: #fff;
    font-size: 13px;
    width: 100%;
}
.uni-courses-search-wrap input::placeholder {
    color: #64748b;
}

.uni-courses-filter-pills {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.filter-pill {
    background: #17223b;
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #94a3b8;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}
.filter-pill:hover {
    color: #fff;
    border-color: rgba(255, 255, 255, 0.18);
}
.filter-pill.active {
    background: #4f46e5;
    color: #fff;
    border-color: #4f46e5;
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.4);
}

.uni-courses-modal-body {
    padding: 0;
    overflow-y: auto;
    flex: 1;
    max-height: calc(88vh - 190px);
}

.modal-courses-table th {
    background: #141d33 !important;
    position: sticky;
    top: 0;
    z-index: 2;
    font-size: 11.5px;
    padding: 10px 14px;
    color: #94a3b8;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.modal-courses-table td {
    padding: 12px 14px;
    font-size: 13px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}
.modal-courses-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.025);
}

.uni-courses-modal-footer {
    padding: 12px 24px;
    background: #141d33;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.uni-modal-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid rgba(99, 102, 241, 0.2);
    border-top-color: #6366f1;
    border-radius: 50%;
    animation: uniSpin 0.75s linear infinite;
}
@keyframes uniSpin {
    to { transform: rotate(360deg); }
}

.sode-dur-badge:hover {
    background: rgba(99, 102, 241, 0.28) !important;
    border-color: #818cf8 !important;
    color: #fff !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
}
</style>

<script>
(function() {
    const ADMIN_BASE_URL = '<?= BASE_URL ?>';
    let currentDurId = 0;
    let currentDurTitle = '';
    let allDurMappings = [];
    let currentDurFilter = 'ALL';
    let currentDurSearch = '';

    window.openMappedDurModal = function(durId, durTitleHint) {
        currentDurId = durId;
        currentDurTitle = durTitleHint;
        currentDurFilter = 'ALL';
        currentDurSearch = '';
        allDurMappings = [];

        const modal = document.getElementById('mappedDurModal');
        const modalTitle = document.getElementById('modalDurTitle');
        const modalMeta = document.getElementById('modalDurMeta');
        const countBadge = document.getElementById('modalDurCountBadge');
        const searchInput = document.getElementById('modalDurSearchInput');
        const clearBtn = document.getElementById('modalDurClearSearchBtn');

        modalTitle.textContent = (durTitleHint ? 'Duration: ' + durTitleHint : 'Duration Mappings');
        modalMeta.textContent = 'Loading mapped courses...';
        countBadge.textContent = '...';
        searchInput.value = '';
        clearBtn.style.display = 'none';

        // Reset filter pills
        document.querySelectorAll('#modalDurFilterPills .filter-pill').forEach(btn => {
            if (btn.getAttribute('data-filter') === 'ALL') {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        showDurState('loading');
        fetchMappedDurs(durId, durTitleHint);
    };

    window.closeMappedDurModal = function() {
        const modal = document.getElementById('mappedDurModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.handleDurBackdropClick = function(event) {
        if (event.target.id === 'mappedDurModal') {
            closeMappedDurModal();
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('mappedDurModal');
            if (modal && modal.style.display !== 'none') {
                closeMappedDurModal();
            }
        }
    });

    window.retryLoadMappedDurs = function() {
        if (currentDurId > 0 || currentDurTitle) {
            showDurState('loading');
            fetchMappedDurs(currentDurId, currentDurTitle);
        }
    };

    function showDurState(state) {
        document.getElementById('modalDurLoadingState').style.display = state === 'loading' ? 'flex' : 'none';
        document.getElementById('modalDurErrorState').style.display = state === 'error' ? 'block' : 'none';
        document.getElementById('modalDurEmptyState').style.display = state === 'empty' ? 'block' : 'none';
        document.getElementById('modalDurTableWrap').style.display = state === 'table' ? 'block' : 'none';
    }

    function fetchMappedDurs(durId, durTitle) {
        const url = ADMIN_BASE_URL + '/api/get_duration_mappings.php?dur_id=' + encodeURIComponent(durId) + '&duration_title=' + encodeURIComponent(durTitle || '');

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Error fetching mappings');
                }

                if (data.duration) {
                    const dur = data.duration;
                    document.getElementById('modalDurTitle').textContent = 'Duration: ' + dur.title;
                    document.getElementById('modalDurMeta').textContent = 
                        'Master Library Duration • Status: ' + (Number(dur.is_active) === 1 ? 'Active' : 'Inactive');
                }

                allDurMappings = Array.isArray(data.mappings) ? data.mappings : [];
                updateDurCounts();

                if (allDurMappings.length === 0) {
                    showDurState('empty');
                } else {
                    showDurState('table');
                    renderDurTable();
                }
            })
            .catch(err => {
                console.error('Error fetching duration mappings:', err);
                document.getElementById('modalDurErrorMessage').textContent = 'Failed to load mapped courses: ' + err.message;
                showDurState('error');
            });
    }

    function updateDurCounts() {
        const total = allDurMappings.length;
        const onlineCount = allDurMappings.filter(m => (m.mode || '').toLowerCase() === 'online').length;
        const distanceCount = allDurMappings.filter(m => (m.mode || '').toLowerCase() === 'distance').length;
        const activeCount = allDurMappings.filter(m => Number(m.uni_active) === 1).length;

        document.getElementById('modalDurCountBadge').textContent = total + (total === 1 ? ' Mapping' : ' Mappings');
        document.getElementById('countDurAll').textContent = total;
        document.getElementById('countDurOnline').textContent = onlineCount;
        document.getElementById('countDurDistance').textContent = distanceCount;
        document.getElementById('countDurActive').textContent = activeCount;
        document.getElementById('modalDurTotalCount').textContent = total;
    }

    window.setDurFilter = function(filter, btn) {
        currentDurFilter = filter;
        document.querySelectorAll('#modalDurFilterPills .filter-pill').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        renderDurTable();
    };

    window.handleDurSearchInput = function() {
        const val = document.getElementById('modalDurSearchInput').value.trim();
        currentDurSearch = val.toLowerCase();
        document.getElementById('modalDurClearSearchBtn').style.display = val ? 'inline' : 'none';
        renderDurTable();
    };

    window.clearDurSearch = function() {
        document.getElementById('modalDurSearchInput').value = '';
        currentDurSearch = '';
        document.getElementById('modalDurClearSearchBtn').style.display = 'none';
        renderDurTable();
    };

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function renderDurTable() {
        const tbody = document.getElementById('modalDurTbody');
        const noMatches = document.getElementById('modalDurNoMatches');
        const shownCount = document.getElementById('modalDurShownCount');

        let filtered = allDurMappings.filter(item => {
            if (currentDurFilter === 'Online' && (item.mode || '').toLowerCase() !== 'online') return false;
            if (currentDurFilter === 'Distance' && (item.mode || '').toLowerCase() !== 'distance') return false;
            if (currentDurFilter === 'Active' && Number(item.uni_active) !== 1) return false;

            if (currentDurSearch) {
                const haystack = (
                    (item.uni_short || '') + ' ' + 
                    (item.uni_name || '') + ' ' + 
                    (item.course_short || '') + ' ' + 
                    (item.course_name || '') + ' ' + 
                    (item.mode || '') + ' ' + 
                    (item.specializations || '')
                ).toLowerCase();
                if (haystack.indexOf(currentDurSearch) === -1) return false;
            }
            return true;
        });

        shownCount.textContent = filtered.length;

        if (filtered.length === 0) {
            tbody.innerHTML = '';
            noMatches.style.display = 'block';
            return;
        }

        noMatches.style.display = 'none';

        let html = '';
        filtered.forEach((m, idx) => {
            const isOnline = (m.mode || '').toLowerCase() === 'online';
            const modeBadge = isOnline 
                ? '<span class="badge badge-info" style="font-size:10.5px; padding:2px 7px;">Online</span>'
                : '<span class="badge" style="background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); font-size:10.5px; padding:2px 7px;">Distance</span>';

            const editUrl = ADMIN_BASE_URL + '/modules/mappings/edit.php?id=' + m.mapping_id;
            const displayName = escapeHtml(m.uni_short || m.uni_name);
            const inactiveBadge = Number(m.uni_active) !== 1 ? '<span class="badge badge-danger" style="font-size:10px; padding:1px 5px; margin-left:6px;">Inactive</span>' : '';

            // Format specializations pills/text
            let specHtml = '<span style="color:var(--text-dim, #64748b);">—</span>';
            if (m.specializations) {
                const specList = m.specializations.split(', ');
                specHtml = specList.map(s => `<span class="badge" style="background:rgba(99,102,241,0.12); color:#a5b4fc; border:1px solid rgba(99,102,241,0.25); font-size:11px; padding:2px 7px; margin:2px 3px 2px 0; display:inline-block;">${escapeHtml(s)}</span>`).join('');
            }

            html += `
                <tr>
                    <td style="text-align:center; color:var(--text-dim, #64748b); font-weight:600; font-size:11.5px;">${idx + 1}</td>
                    <td>
                        <div style="font-weight:600; color:#fff; display:flex; align-items:center;">
                            <span style="font-size:13px;">${displayName}</span>
                            ${inactiveBadge}
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600; color:var(--text-primary, #f1f5f9); font-size:13px;">
                            ${escapeHtml(m.course_name || m.course_short)}
                        </div>
                        <div style="font-size:11px; color:var(--text-dim, #64748b); margin-top:2px;">
                            ${escapeHtml(m.course_level || '')}
                        </div>
                    </td>
                    <td style="text-align:center;">${modeBadge}</td>
                    <td>
                        <div style="max-width: 280px;">
                            ${specHtml}
                        </div>
                    </td>
                    <td>
                        <span style="font-weight:600; color:#34d399; font-size:12.5px;">
                            ${escapeHtml(m.fee || '—')}
                        </span>
                    </td>
                    <td style="text-align:right;">
                        <a href="${editUrl}" target="_blank" class="uni-modal-edit-btn" title="Edit Course Mapping in new tab">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            <span>Edit</span>
                        </a>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }
})();
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
