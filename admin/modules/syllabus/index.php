<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$page_title = 'Syllabus Subjects Master';
$page_subtitle = 'Manage global master list of syllabus subjects. Selectable across semesters in Course Mappings.';
$active_page_key = 'syllabus';

$db = get_db_connection();

// Handle POST Actions (Save / Bulk Add / Edit / Delete / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Single Save / Update
    if ($action === 'save') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($subject_name)) {
            set_flash_message('Subject name is required.', 'error');
        } else {
            try {
                if ($subject_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE course_syllabus_subjects_master 
                        SET subject_name = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$subject_name, $sort_order, $is_active, $subject_id]);
                    set_flash_message('Subject updated successfully!', 'success');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO course_syllabus_subjects_master (subject_name, sort_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$subject_name, $sort_order, $is_active]);
                    set_flash_message('New subject added to master library!', 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash_message('A subject with this name already exists in the master library.', 'error');
                } else {
                    set_flash_message('Database Error: ' . $e->getMessage(), 'error');
                }
            }
        }
        redirect(BASE_URL . '/modules/syllabus/index.php');
    }

    // 2. Bulk Add Subjects
    if ($action === 'bulk_save') {
        $raw_text = trim($_POST['bulk_subjects'] ?? '');
        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $added = 0;
            $skipped = 0;

            $stmt = $db->prepare("
                INSERT IGNORE INTO course_syllabus_subjects_master (subject_name, sort_order, is_active, created_at, updated_at)
                VALUES (?, 0, 1, NOW(), NOW())
            ");

            foreach ($lines as $line) {
                $sub = trim($line);
                $sub = preg_replace('/^(\d+[\.\)\-]?|\-|\*)\s*/', '', $sub);
                $sub = trim($sub);
                if (!empty($sub)) {
                    $stmt->execute([$sub]);
                    if ($stmt->rowCount() > 0) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
            }
            set_flash_message("Bulk Add Complete: {$added} subjects added" . ($skipped > 0 ? " ({$skipped} duplicates skipped)." : "."), 'success');
        } else {
            set_flash_message('Please enter at least one subject.', 'error');
        }
        redirect(BASE_URL . '/modules/syllabus/index.php');
    }

    // 3. Delete Subject
    if ($action === 'delete') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if ($subject_id > 0) {
            $stmt = $db->prepare("DELETE FROM course_syllabus_subjects_master WHERE id = ?");
            $stmt->execute([$subject_id]);
            set_flash_message('Subject deleted from master library.', 'success');
        }
        redirect(BASE_URL . '/modules/syllabus/index.php');
    }

    // 4. Toggle Status
    if ($action === 'toggle_status') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        if ($subject_id > 0) {
            $stmt = $db->prepare("UPDATE course_syllabus_subjects_master SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$subject_id]);
            set_flash_message('Subject status updated.', 'success');
        }
        redirect(BASE_URL . '/modules/syllabus/index.php');
    }
}

// Fetch All Global Syllabus Subjects
$search = trim($_GET['q'] ?? '');
if (!empty($search)) {
    $stmt = $db->prepare("
        SELECT * FROM course_syllabus_subjects_master 
        WHERE subject_name LIKE ? 
        ORDER BY subject_name ASC
    ");
    $stmt->execute(['%' . $search . '%']);
} else {
    $stmt = $db->query("
        SELECT * FROM course_syllabus_subjects_master 
        ORDER BY subject_name ASC
    ");
}
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_count = count($subjects);

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

.sode-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    line-height: 1.2;
    border: none;
    font-family: inherit;
}

.sode-btn-primary {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: #ffffff;
    box-shadow: 0 2px 10px rgba(79, 70, 229, 0.35);
}
.sode-btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(79, 70, 229, 0.5);
    color: #ffffff;
}

.sode-btn-emerald {
    background: rgba(16, 185, 129, 0.15);
    color: #34d399;
    border: 1px solid rgba(16, 185, 129, 0.35);
}
.sode-btn-emerald:hover {
    background: rgba(16, 185, 129, 0.25);
    color: #6ee7b7;
    border-color: rgba(16, 185, 129, 0.6);
    transform: translateY(-1px);
}

.sode-btn-secondary {
    background: var(--bg-input, #151f32);
    color: var(--text-main, #f8fafc);
    border: 1px solid var(--border-color, #1e2b45);
}
.sode-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.08);
    border-color: rgba(99, 102, 241, 0.5);
}

/* Bordered Table */
.bordered-table-card {
    background: var(--bg-card, #0f172a);
    border: 1px solid var(--border-color, #1e2b45);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
}

.bordered-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
    text-align: left;
}

.bordered-table th {
    background: var(--bg-input, #151f32);
    color: var(--text-dim, #94a3b8);
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    padding: 13px 18px;
    border: 1px solid var(--border-color, #1e2b45);
}

.bordered-table td {
    padding: 13px 18px;
    border: 1px solid var(--border-color, #1e2b45);
    color: var(--text-main, #f8fafc);
    vertical-align: middle;
}

.bordered-table tbody tr {
    transition: background 0.15s ease;
}

.bordered-table tbody tr:hover {
    background: rgba(99, 102, 241, 0.04);
}

/* Action Buttons */
.table-btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(99, 102, 241, 0.12);
    color: #a5b4fc;
    border: 1px solid rgba(99, 102, 241, 0.3);
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
}
.table-btn-edit:hover {
    background: rgba(99, 102, 241, 0.25);
    color: #ffffff;
    border-color: #6366f1;
    transform: translateY(-1px);
}

.table-btn-delete {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(239, 68, 68, 0.12);
    color: #fca5a5;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-family: inherit;
}
.table-btn-delete:hover {
    background: rgba(239, 68, 68, 0.25);
    color: #ffffff;
    border-color: #ef4444;
    transform: translateY(-1px);
}

.status-badge-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    font-family: inherit;
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

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
    <div>
        <h1 class="page-title" style="margin: 0 0 6px 0; font-size: 24px; font-weight: 700; color: var(--text-primary);">
            Syllabus Subjects Master
        </h1>
        <p class="page-subtitle" style="margin: 0; color: var(--text-secondary); font-size: 14px;">
            Manage global master library of syllabus subjects. Selectable across semesters in Course Mappings.
        </p>
    </div>
    <div class="master-header-actions">
        <span style="background: rgba(16, 185, 129, 0.12); color: #6ee7b7; padding: 7px 14px; border-radius: 20px; font-weight: 700; font-size: 13px; border: 1px solid rgba(16, 185, 129, 0.3);">
            <?= $total_count ?> Total Subjects
        </span>
        <button type="button" class="sode-btn sode-btn-emerald" onclick="openBulkModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
            ⚡ Bulk Add
        </button>
        <button type="button" class="sode-btn sode-btn-primary" onclick="openSubjectModal()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            + Add Subject
        </button>
    </div>
</div>

<!-- Search Bar -->
<div class="admin-card" style="padding: 16px; margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 250px; position: relative;">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search syllabus subjects by name..." class="form-control" style="padding-left: 38px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        </div>
        <button type="submit" class="sode-btn sode-btn-secondary" style="padding: 10px 20px;">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?= BASE_URL ?>/modules/syllabus/index.php" class="sode-btn sode-btn-secondary" style="color: var(--text-dim);">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Bordered Table Container -->
<div class="bordered-table-card">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-card);">
        <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path><line x1="9" y1="7" x2="15" y2="7"></line><line x1="9" y1="11" x2="15" y2="11"></line></svg>
            Master Syllabus Subjects Library
        </h3>
        <span style="font-size: 12.5px; color: var(--text-dim); font-weight: 600;">Showing <?= count($subjects) ?> subjects</span>
    </div>

    <div class="table-responsive">
        <table class="bordered-table">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">#</th>
                    <th>Subject Name</th>
                    <th style="width: 130px; text-align: center;">Status</th>
                    <th style="width: 170px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subjects)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 48px 20px; color: var(--text-dim);">
                            <div style="font-size: 36px; margin-bottom: 10px;">📖</div>
                            <div style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">No subjects found</div>
                            <div style="font-size: 13px; margin-bottom: 16px;">Add master syllabus subjects so they can be selected in Course Mappings.</div>
                            <button type="button" class="sode-btn sode-btn-primary" onclick="openSubjectModal()">+ Add Subject</button>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $idx => $sub): ?>
                        <tr>
                            <td style="text-align: center; color: var(--text-dim); font-size: 13px; font-weight: 600;">
                                <?= $idx + 1 ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 14px; color: var(--text-main);">
                                    <?= htmlspecialchars($sub['subject_name']) ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <form method="POST" action="" style="display: inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">
                                    <button type="submit" class="status-badge-btn" title="Click to toggle active status">
                                        <?php if ($sub['is_active']): ?>
                                            <span class="status-pill active">
                                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399;"></span> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="status-pill inactive">
                                                <span style="width: 6px; height: 6px; border-radius: 50%; background: #f87171;"></span> Inactive
                                            </span>
                                        <?php endif; ?>
                                    </button>
                                </form>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 8px; justify-content: center;">
                                    <button type="button" class="table-btn-edit" onclick='editSubject(<?= json_encode($sub, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        Edit
                                    </button>
                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this subject from master library?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="subject_id" value="<?= $sub['id'] ?>">
                                        <button type="submit" class="table-btn-delete">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            Delete
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

<!-- Add / Edit Single Modal -->
<div id="subjectModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(5px); justify-content: center; align-items: center; padding: 20px;">
    <div style="background: #1e2433; border: 1px solid var(--border-color, #2d3748); border-radius: 12px; max-width: 480px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); overflow: hidden; animation: fadeIn 0.2s ease;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-color, #2d3748); display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-primary);">+ Add Syllabus Subject</h3>
            <button type="button" onclick="closeSubjectModal()" style="background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 22px; line-height: 1; padding: 0;">&times;</button>
        </div>
        <form method="POST" action="" id="subjectForm" style="padding: 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="subject_id" id="subject_id" value="0">

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 7px;">
                    Subject Name <span style="color: #ef4444;">*</span>
                </label>
                <input type="text" name="subject_name" id="subject_name" class="form-control" required placeholder="e.g. Marketing Management, Data Structures, Accounting" style="width: 100%; font-size: 14px;">
                <small style="color: var(--text-dim); font-size: 12px; margin-top: 5px; display: block;">This subject will be suggested across all semesters in Course Mappings.</small>
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: var(--text-primary); cursor: pointer;">
                    <input type="checkbox" name="is_active" id="is_active" value="1" checked style="width: 16px; height: 16px; accent-color: #10b981;">
                    Active in Library
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="sode-btn sode-btn-secondary" onclick="closeSubjectModal()">Cancel</button>
                <button type="submit" class="sode-btn sode-btn-primary" id="modalSubmitBtn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">Save Subject</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Add Modal -->
<div id="bulkModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(5px); justify-content: center; align-items: center; padding: 20px;">
    <div style="background: #1e2433; border: 1px solid var(--border-color, #2d3748); border-radius: 12px; max-width: 520px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); overflow: hidden; animation: fadeIn 0.2s ease;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-color, #2d3748); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                <span>⚡</span> Bulk Add Syllabus Subjects
            </h3>
            <button type="button" onclick="closeBulkModal()" style="background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 22px; line-height: 1; padding: 0;">&times;</button>
        </div>
        <form method="POST" action="" style="padding: 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_save">

            <div class="form-group" style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 7px;">
                    Paste Subjects (One per line)
                </label>
                <textarea name="bulk_subjects" class="form-control" rows="8" required placeholder="Accounting for Managers&#10;Marketing Management&#10;Organisational Behaviour&#10;Information Systems&#10;Business Economics" style="width: 100%; font-family: monospace; font-size: 13px; line-height: 1.5;"></textarea>
                <small style="color: var(--text-dim); font-size: 12px; margin-top: 6px; display: block;">Duplicates already in the library will be automatically skipped.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="sode-btn sode-btn-secondary" onclick="closeBulkModal()">Cancel</button>
                <button type="submit" class="sode-btn sode-btn-primary" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">Add All Subjects</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSubjectModal() {
    document.getElementById('modalTitle').textContent = '+ Add Syllabus Subject';
    document.getElementById('modalSubmitBtn').textContent = 'Save Subject';
    document.getElementById('subject_id').value = '0';
    document.getElementById('subject_name').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('subjectModal').style.display = 'flex';
    document.getElementById('subject_name').focus();
}

function editSubject(sub) {
    document.getElementById('modalTitle').textContent = 'Edit Syllabus Subject';
    document.getElementById('modalSubmitBtn').textContent = 'Update Subject';
    document.getElementById('subject_id').value = sub.id;
    document.getElementById('subject_name').value = sub.subject_name;
    document.getElementById('is_active').checked = parseInt(sub.is_active) === 1;
    document.getElementById('subjectModal').style.display = 'flex';
    document.getElementById('subject_name').focus();
}

function closeSubjectModal() {
    document.getElementById('subjectModal').style.display = 'none';
}

function openBulkModal() {
    document.getElementById('bulkModal').style.display = 'flex';
}

function closeBulkModal() {
    document.getElementById('bulkModal').style.display = 'none';
}

window.onclick = function(event) {
    var subModal = document.getElementById('subjectModal');
    var bModal = document.getElementById('bulkModal');
    if (event.target === subModal) closeSubjectModal();
    if (event.target === bModal) closeBulkModal();
}
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
