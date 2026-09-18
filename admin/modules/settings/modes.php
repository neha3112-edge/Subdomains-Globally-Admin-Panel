<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('modes');

$page_title = 'Education Modes';
$page_subtitle = 'Manage global list of education & delivery modes. Selectable in Universities and Course Mappings.';
$active_page_key = 'modes';

$db = get_db_connection();

// Handle POST Actions (Save / Bulk Save / Edit / Delete / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Single Save / Update
    if ($action === 'save') {
        $mode_id = (int)($_POST['mode_id'] ?? 0);
        $name = trim($_POST['mode_name'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            set_flash_message('Mode name is required.', 'error');
        } else {
            try {
                if ($mode_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE education_modes_master 
                        SET mode_name = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $sort_order, $is_active, $mode_id]);
                    set_flash_message('Education mode updated successfully!', 'success');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO education_modes_master (mode_name, sort_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$name, $sort_order, $is_active]);
                    set_flash_message('New education mode added successfully!', 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash_message('An education mode with this name already exists.', 'error');
                } else {
                    set_flash_message('Database Error: ' . $e->getMessage(), 'error');
                }
            }
        }
        redirect(BASE_URL . '/modules/settings/modes.php');
    }

    // 2. Bulk Add Modes
    if ($action === 'bulk_save') {
        $raw_text = trim($_POST['bulk_modes'] ?? '');
        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $added = 0;
            $skipped = 0;

            $stmt = $db->prepare("
                INSERT IGNORE INTO education_modes_master (mode_name, sort_order, is_active, created_at, updated_at)
                VALUES (?, 0, 1, NOW(), NOW())
            ");

            foreach ($lines as $line) {
                $m = trim($line);
                // Strip numbers/bullets like "1.", "-", "*"
                $m = preg_replace('/^(\d+[\.\)\-]?|\-|\*)\s*/', '', $m);
                $m = trim($m);
                if (!empty($m)) {
                    $stmt->execute([$m]);
                    if ($stmt->rowCount() > 0) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
            }
            set_flash_message("Bulk Add Complete: {$added} modes added" . ($skipped > 0 ? " ({$skipped} duplicates skipped)." : "."), 'success');
        } else {
            set_flash_message('Please enter at least one mode name.', 'error');
        }
        redirect(BASE_URL . '/modules/settings/modes.php');
    }

    // 3. Delete Mode
    if ($action === 'delete') {
        $mode_id = (int)($_POST['mode_id'] ?? 0);
        if ($mode_id > 0) {
            $m_info = $db->query("SELECT mode_name FROM education_modes_master WHERE id = $mode_id")->fetch();
            $title = $m_info['mode_name'] ?? 'Education Mode';
            move_to_trash('education_modes_master', $mode_id, $title);
            set_flash_message('Education mode moved to Trash! You can restore it anytime.', 'success');
        }
        redirect(BASE_URL . '/modules/settings/modes.php');
    }

    // 4. Toggle Status
    if ($action === 'toggle_status') {
        $mode_id = (int)($_POST['mode_id'] ?? 0);
        if ($mode_id > 0) {
            $stmt = $db->prepare("UPDATE education_modes_master SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$mode_id]);
            set_flash_message('Education mode status updated.', 'success');
        }
        redirect(BASE_URL . '/modules/settings/modes.php');
    }
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Fetch All Modes with pagination
$search = trim($_GET['q'] ?? '');
if (!empty($search)) {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM education_modes_master WHERE mode_name LIKE :q");
    $count_stmt->execute([':q' => '%' . $search . '%']);
    $total_count = (int)$count_stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT * FROM education_modes_master 
        WHERE mode_name LIKE :q 
        ORDER BY sort_order ASC, id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':q', '%' . $search . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $total_count = (int)$db->query("SELECT COUNT(*) FROM education_modes_master")->fetchColumn();

    $stmt = $db->prepare("
        SELECT * FROM education_modes_master 
        ORDER BY sort_order ASC, id ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$modes = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
.bordered-table {
    border: 1px solid var(--border-color, #1e2b45) !important;
    border-collapse: collapse !important;
    border-radius: 8px;
    overflow: hidden;
}
.bordered-table th, 
.bordered-table td {
    border: 1px solid var(--border-color, #1e2b45) !important;
    padding: 12px 14px !important;
}
.bordered-table th {
    background: rgba(15, 23, 42, 0.75) !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    color: var(--text-dim, #64748b) !important;
}
.bordered-table tr:hover td {
    background: rgba(99, 102, 241, 0.04) !important;
}

.master-header-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

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
    max-width: 480px;
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
        <h2 style="font-size:20px; font-weight:800; color:var(--text-main, #f8fafc); margin:0 0 4px;">Education Modes</h2>
        <p style="font-size:12.5px; color:var(--text-dim, #64748b); margin:0;">
            Global library of education delivery modes (e.g. Online, Distance, Hybrid). Selectable in Universities and Course Mappings.
        </p>
    </div>
    <div class="master-header-actions">
        <button type="button" class="btn-secondary-glow" onclick="openBulkModal()">
            <span>⚡</span> Bulk Add Modes
        </button>
        <button type="button" class="btn-primary-glow" onclick="openAddModal()">
            <span>+</span> Add Mode
        </button>
    </div>
</div>

<!-- Search & Table Card -->
<div class="admin-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <form method="GET" action="" style="display:flex; gap:10px; align-items:center; max-width:360px; width:100%;">
            <input type="text" name="q" class="form-control" placeholder="Search mode..." value="<?php echo htmlspecialchars($search); ?>" style="font-size:13px;">
            <?php if (!empty($search)): ?>
                <a href="<?php echo BASE_URL; ?>/modules/settings/modes.php" class="btn-sm action-btn" style="text-decoration:none; padding:8px 12px;">Clear</a>
            <?php endif; ?>
        </form>
        <span style="font-size:12px; color:var(--text-dim, #64748b); font-weight:600;">
            Total: <strong><?php echo $total_count; ?></strong> Modes
        </span>
    </div>

    <div class="card-body" style="padding:0;">
        <?php if (empty($modes)): ?>
            <div style="padding:40px 20px; text-align:center; color:var(--text-dim);">
                <div style="font-size:32px; margin-bottom:10px;">🎓</div>
                <p style="font-size:14px; font-weight:600; margin:0 0 6px;">No education modes found</p>
                <p style="font-size:12px; color:var(--text-dim); margin:0 0 16px;">Add modes to make them selectable across Universities and Course Mappings.</p>
                <button type="button" class="btn-primary-glow" onclick="openAddModal()">+ Add First Mode</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table bordered-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">#</th>
                            <th>Mode Name</th>
                            <th style="width:120px; text-align:center;">Sort Order</th>
                            <th style="width:110px; text-align:center;">Status</th>
                            <th style="width:120px; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($modes as $idx => $m): ?>
                            <tr>
                                <td style="text-align:center; color:var(--text-dim); font-size:12px; font-weight:600;">
                                    <?php echo $offset + $idx + 1; ?>
                                </td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span style="color:#a5b4fc; font-size:14px;">🎓</span>
                                        <strong style="color:var(--text-main, #f8fafc); font-size:13.5px;">
                                            <?php echo htmlspecialchars($m['mode_name']); ?>
                                        </strong>
                                    </div>
                                </td>
                                <td style="text-align:center; color:var(--text-dim); font-weight:600; font-size:12px;">
                                    <?php echo htmlspecialchars($m['sort_order']); ?>
                                </td>
                                <td style="text-align:center;">
                                    <form method="POST" action="" style="margin:0; display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="mode_id" value="<?php echo $m['id']; ?>">
                                        <button type="submit" style="background:none; border:none; padding:0; cursor:pointer;" title="Click to toggle active state">
                                            <?php if ($m['is_active']): ?>
                                                <span class="badge badge-success" style="cursor:pointer;">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger" style="cursor:pointer;">Inactive</span>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px; justify-content:flex-end;">
                                        <button type="button" class="action-btn edit-btn" title="Edit Mode" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($m)); ?>)">
                                            ✏️
                                        </button>
                                        <form method="POST" action="" style="margin:0; display:inline;" onsubmit="return confirm('Delete this mode from master library?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="mode_id" value="<?php echo $m['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete Mode">
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

<!-- Modal: Add / Edit Mode -->
<div id="modeModal" class="sode-modal-overlay">
    <div class="sode-modal">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="mode_id" id="modal_mode_id" value="0">

            <div class="sode-modal-header">
                <span class="sode-modal-title" id="modal_title">Add New Education Mode</span>
                <button type="button" class="sode-modal-close" onclick="closeModal('modeModal')">&times;</button>
            </div>
            <div class="sode-modal-body" style="display:flex; flex-direction:column; gap:14px;">
                <div class="form-group">
                    <label class="form-label">Mode Name *</label>
                    <input type="text" name="mode_name" id="modal_mode_name" class="form-control" placeholder="e.g. Online & Distance, Hybrid, Regular" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="modal_mode_sort" class="form-control" value="0">
                </div>
                <div class="form-group" style="margin-top:4px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; color:var(--text-main);">
                        <input type="checkbox" name="is_active" id="modal_mode_active" value="1" checked>
                        <span>Active (Selectable in Universities & Course Mappings)</span>
                    </label>
                </div>
            </div>
            <div class="sode-modal-footer">
                <button type="button" class="btn-secondary-glow" onclick="closeModal('modeModal')">Cancel</button>
                <button type="submit" class="btn-primary-glow" id="modal_submit_btn">Save Mode</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Bulk Add Modes -->
<div id="bulkModal" class="sode-modal-overlay">
    <div class="sode-modal">
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_save">

            <div class="sode-modal-header">
                <span class="sode-modal-title">⚡ Bulk Add Education Modes</span>
                <button type="button" class="sode-modal-close" onclick="closeModal('bulkModal')">&times;</button>
            </div>
            <div class="sode-modal-body">
                <p style="font-size:12.5px; color:var(--text-dim); margin:0 0 10px;">
                    Paste multiple mode names below (one mode per line). Numbered/bulleted lists will be cleaned automatically.
                </p>
                <div class="form-group">
                    <textarea name="bulk_modes" rows="8" class="form-control" placeholder="Online & Distance&#10;Online&#10;Distance&#10;Hybrid&#10;Regular&#10;Part-Time" required style="font-size:13px; line-height:1.5;"></textarea>
                </div>
            </div>
            <div class="sode-modal-footer">
                <button type="button" class="btn-secondary-glow" onclick="closeModal('bulkModal')">Cancel</button>
                <button type="submit" class="btn-primary-glow">⚡ Add All Modes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modal_mode_id').value = '0';
    document.getElementById('modal_mode_name').value = '';
    document.getElementById('modal_mode_sort').value = '0';
    document.getElementById('modal_mode_active').checked = true;
    document.getElementById('modal_title').textContent = 'Add New Education Mode';
    document.getElementById('modal_submit_btn').textContent = 'Save Mode';
    document.getElementById('modeModal').style.display = 'flex';
    document.getElementById('modal_mode_name').focus();
}

function openEditModal(m) {
    document.getElementById('modal_mode_id').value = m.id;
    document.getElementById('modal_mode_name').value = m.mode_name || '';
    document.getElementById('modal_mode_sort').value = m.sort_order || 0;
    document.getElementById('modal_mode_active').checked = (parseInt(m.is_active) === 1);
    document.getElementById('modal_title').textContent = 'Edit Mode: ' + (m.mode_name || '');
    document.getElementById('modal_submit_btn').textContent = 'Update Mode';
    document.getElementById('modeModal').style.display = 'flex';
    document.getElementById('modal_mode_name').focus();
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
