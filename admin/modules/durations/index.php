<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

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
        $dur_id = (int)($_POST['dur_id'] ?? 0);
        if ($dur_id > 0) {
            $d_info = $db->query("SELECT duration_text FROM course_durations_master WHERE id = $dur_id")->fetch();
            $title = $d_info['duration_text'] ?? 'Course Duration';
            move_to_trash('course_durations_master', $dur_id, $title);
            set_flash_message('Duration moved to Trash! You can restore it anytime.', 'success');
        }
        redirect(BASE_URL . '/modules/durations/index.php');
    }

    // 4. Toggle Status
    if ($action === 'toggle_status') {
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
        SELECT * FROM course_durations_master 
        WHERE duration_title LIKE :q 
        ORDER BY sort_order ASC, id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':q', '%' . $search . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $total_count = (int)$db->query("SELECT COUNT(*) FROM course_durations_master")->fetchColumn();

    $stmt = $db->prepare("
        SELECT * FROM course_durations_master 
        ORDER BY sort_order ASC, id ASC
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
        <button type="button" class="btn-secondary-glow" onclick="openBulkModal()">
            <span>⚡</span> Bulk Add Durations
        </button>
        <button type="button" class="btn-primary-glow" onclick="openAddModal()">
            <span>+</span> Add Duration
        </button>
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
                <button type="button" class="btn-primary-glow" onclick="openAddModal()">+ Add First Duration</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table bordered-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">#</th>
                            <th>Duration Title</th>
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
                                <td style="text-align:center; color:var(--text-dim); font-weight:600; font-size:12px;">
                                    <?php echo htmlspecialchars($d['sort_order']); ?>
                                </td>
                                <td style="text-align:center;">
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
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                                        <button type="button" class="action-btn edit-btn" title="Edit Duration" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($d)); ?>)">
                                            ✏️
                                        </button>
                                        <form method="POST" action="" style="margin:0; display:inline;" onsubmit="return confirm('Delete this duration from master library?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="dur_id" value="<?php echo $d['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete Duration">
                                                🗑️
                                            </button>
                                        </form>
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
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
