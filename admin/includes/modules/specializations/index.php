<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('specializations');

$page_title = 'Specializations Master';
$page_subtitle = 'Manage global master list of specializations. Selectable in all course mappings.';
$active_page_key = 'specializations';

$db = get_db_connection();

// Handle POST Actions (Save / Bulk Save / Edit / Delete / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Single Save / Update
    if ($action === 'save') {
        $spec_id = (int)($_POST['spec_id'] ?? 0);
        if ($spec_id > 0 && !user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update specializations.', 'error');
            redirect(BASE_URL . '/modules/specializations/index.php');
        } elseif (!$spec_id && !user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create specializations.', 'error');
            redirect(BASE_URL . '/modules/specializations/index.php');
        }
        $name = trim($_POST['specialization_name'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name)) {
            set_flash_message('Specialization name is required.', 'error');
        } else {
            try {
                if ($spec_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE course_specializations_master 
                        SET specialization_name = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $sort_order, $is_active, $spec_id]);
                    set_flash_message('Specialization updated successfully!', 'success');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO course_specializations_master (specialization_name, sort_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$name, $sort_order, $is_active]);
                    set_flash_message('New specialization added successfully!', 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash_message('A specialization with this name already exists in the master library.', 'error');
                } else {
                    set_flash_message('Database Error: ' . $e->getMessage(), 'error');
                }
            }
        }
        redirect(BASE_URL . '/modules/specializations/index.php');
    }

    // 2. Bulk Add Specializations
    if ($action === 'bulk_save') {
        if (!user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create specializations.', 'error');
            redirect(BASE_URL . '/modules/specializations/index.php');
        }
        $raw_text = trim($_POST['bulk_specializations'] ?? '');
        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $added = 0;
            $skipped = 0;

            $stmt = $db->prepare("
                INSERT IGNORE INTO course_specializations_master (specialization_name, sort_order, is_active, created_at, updated_at)
                VALUES (?, 0, 1, NOW(), NOW())
            ");

            foreach ($lines as $line) {
                $sp = trim($line);
                // Strip numbers/bullets
                $sp = preg_replace('/^(\d+[\.\)\-]?|\-|\*)\s*/', '', $sp);
                $sp = trim($sp);
                if (!empty($sp)) {
                    $stmt->execute([$sp]);
                    if ($stmt->rowCount() > 0) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                }
            }
            set_flash_message("Bulk Add Complete: {$added} specializations added" . ($skipped > 0 ? " ({$skipped} duplicates skipped)." : "."), 'success');
        } else {
            set_flash_message('Please enter at least one specialization.', 'error');
        }
        redirect(BASE_URL . '/modules/specializations/index.php');
    }

    // 3. Delete Specialization
    if ($action === 'delete') {
        if (!user_can('delete')) {
            set_flash_message('Access Denied: You do not have permission to delete specializations.', 'error');
            redirect(BASE_URL . '/modules/specializations/index.php');
        }
        $spec_id = (int)($_POST['spec_id'] ?? 0);
        if ($spec_id > 0) {
            $ok = move_to_trash('course_specializations_master', $spec_id);
            if ($ok) {
                set_flash_message('Specialization moved to Trash! You can restore it anytime.', 'success');
            } else {
                set_flash_message('Failed to move specialization to Trash.', 'error');
            }
        }
        redirect(BASE_URL . '/modules/specializations/index.php');
    }

    // 4. Toggle Status
    if ($action === 'toggle_status') {
        if (!user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update specializations.', 'error');
            redirect(BASE_URL . '/modules/specializations/index.php');
        }
        $spec_id = (int)($_POST['spec_id'] ?? 0);
        if ($spec_id > 0) {
            $stmt = $db->prepare("UPDATE course_specializations_master SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$spec_id]);
            set_flash_message('Specialization status updated.', 'success');
        }
        redirect(BASE_URL . '/modules/specializations/index.php');
    }
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Fetch All Global Specializations with pagination
$search = trim($_GET['q'] ?? '');
if (!empty($search)) {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM course_specializations_master WHERE specialization_name LIKE :q");
    $count_stmt->execute([':q' => '%' . $search . '%']);
    $total_count = (int)$count_stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT csm.*,
            (SELECT COUNT(DISTINCT cs.mapping_id) 
             FROM course_specializations cs 
             WHERE cs.specialization_name = csm.specialization_name COLLATE utf8mb4_unicode_ci
            ) AS mapped_count
        FROM course_specializations_master csm 
        WHERE csm.specialization_name LIKE :q 
        ORDER BY csm.specialization_name ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':q', '%' . $search . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $total_count = (int)$db->query("SELECT COUNT(*) FROM course_specializations_master")->fetchColumn();

    $stmt = $db->prepare("
        SELECT csm.*,
            (SELECT COUNT(DISTINCT cs.mapping_id) 
             FROM course_specializations cs 
             WHERE cs.specialization_name = csm.specialization_name COLLATE utf8mb4_unicode_ci
            ) AS mapped_count
        FROM course_specializations_master csm 
        ORDER BY csm.specialization_name ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}
$specializations = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            Specializations Master
        </h1>
        <p class="page-subtitle" style="margin: 0; color: var(--text-secondary); font-size: 14px;">
            Manage global master specializations library. Admins can select these in Course Mappings.
        </p>
    </div>
    <div class="master-header-actions">
        <span style="background: rgba(99, 102, 241, 0.12); color: #a5b4fc; padding: 7px 14px; border-radius: 20px; font-weight: 700; font-size: 13px; border: 1px solid rgba(99, 102, 241, 0.3);">
            <?= $total_count ?> Total Specializations
        </span>
        <?php if (can_create()): ?>
            <button type="button" class="sode-btn sode-btn-emerald" onclick="openBulkModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                ⚡ Bulk Add
            </button>
            <button type="button" class="sode-btn sode-btn-primary" onclick="openSpecModal()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                + Add Specialization
            </button>
        <?php else: ?>
            <button type="button" class="sode-btn btn-disabled-locked" disabled title="Access Denied: You do not have Create permission">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Bulk Add (Locked)
            </button>
            <button type="button" class="sode-btn btn-disabled-locked" disabled title="Access Denied: You do not have Create permission">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Add (Locked)
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search specializations by name..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?= BASE_URL ?>/modules/specializations/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Bordered Table Container -->
<div class="bordered-table-card">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; background: var(--bg-card);">
        <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
            Master Specializations Library
        </h3>
        <span style="font-size: 12.5px; color: var(--text-dim); font-weight: 600;">Showing <?= count($specializations) ?> records</span>
    </div>

    <div class="table-responsive">
        <table class="bordered-table">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">#</th>
                    <th>Specialization Name</th>
                    <th style="width: 160px; text-align: center;">Course Mappings</th>
                    <th style="width: 130px; text-align: center;">Status</th>
                    <th style="width: 170px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($specializations)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 48px 20px; color: var(--text-dim);">
                            <div style="font-size: 36px; margin-bottom: 10px;">⭐</div>
                            <div style="font-size: 15px; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">No specializations found</div>
                            <div style="font-size: 13px; margin-bottom: 16px;">Add master specializations so they can be selected in Course Mappings.</div>
                            <?php if (can_create()): ?>
                                <button type="button" class="sode-btn sode-btn-primary" onclick="openSpecModal()">+ Add Specialization</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($specializations as $idx => $spec): ?>
                        <tr>
                            <td style="text-align: center; color: var(--text-dim); font-size: 13px; font-weight: 600;">
                                <?= $offset + $idx + 1 ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 14px; color: var(--text-main);">
                                    <?= htmlspecialchars($spec['specialization_name']) ?>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <?php $mapped_cnt = (int)($spec['mapped_count'] ?? 0); ?>
                                <?php if ($mapped_cnt > 0): ?>
                                    <button type="button" 
                                            onclick="openMappedSpecModal(<?= (int)$spec['id'] ?>, '<?= htmlspecialchars(addslashes($spec['specialization_name'])) ?>')" 
                                            class="badge sode-spec-badge" 
                                            style="background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.35); font-weight: 700; font-size: 11.5px; padding: 4px 11px; border-radius: 20px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; transition: all 0.2s;" 
                                            title="Click to view all mapped courses for <?= htmlspecialchars($spec['specialization_name']) ?>">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                                        <?= $mapped_cnt ?> <?= $mapped_cnt === 1 ? 'Mapping' : 'Mappings' ?> &rarr;
                                    </button>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(148, 163, 184, 0.08); color: var(--text-dim, #64748b); border: 1px solid rgba(148, 163, 184, 0.15); font-weight: 600; font-size: 11px; padding: 4px 9px; border-radius: 20px;">
                                        0 Mappings
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if (can_update()): ?>
                                    <form method="POST" action="" style="display: inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="spec_id" value="<?= $spec['id'] ?>">
                                        <button type="submit" class="status-badge-btn" title="Click to toggle active status">
                                            <?php if ($spec['is_active']): ?>
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
                                <?php else: ?>
                                    <?php if ($spec['is_active']): ?>
                                         <span class="status-pill active">
                                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399;"></span> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill inactive">
                                            <span style="width: 6px; height: 6px; border-radius: 50%; background: #f87171;"></span> Inactive
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div style="display: inline-flex; gap: 8px; justify-content: center;">
                                    <?php if (can_update()): ?>
                                        <button type="button" class="table-btn-edit" onclick='editSpec(<?= json_encode($spec, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                            Edit
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="table-btn-edit btn-disabled-locked" disabled title="Access Denied: You do not have Edit permission">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                            Edit
                                        </button>
                                    <?php endif; ?>

                                    <?php if (can_delete()): ?>
                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this specialization from master library?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="spec_id" value="<?= $spec['id'] ?>">
                                            <button type="submit" class="table-btn-delete">
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                                Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="table-btn-delete btn-disabled-locked" disabled title="Access Denied: You do not have Delete permission">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                            Delete
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
    <?= sode_render_pagination($total_count, $page, $per_page) ?>
</div>

<!-- Add / Edit Single Modal -->
<div id="specModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(5px); justify-content: center; align-items: center; padding: 20px;">
    <div style="background: #1e2433; border: 1px solid var(--border-color, #2d3748); border-radius: 12px; max-width: 480px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); overflow: hidden; animation: fadeIn 0.2s ease;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-color, #2d3748); display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-primary);">+ Add Specialization</h3>
            <button type="button" onclick="closeSpecModal()" style="background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 22px; line-height: 1; padding: 0;">&times;</button>
        </div>
        <form method="POST" action="" id="specForm" style="padding: 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="spec_id" id="spec_id" value="0">

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 7px;">
                    Specialization Name <span style="color: #ef4444;">*</span>
                </label>
                <input type="text" name="specialization_name" id="specialization_name" class="form-control" required placeholder="e.g. Financial Management, Marketing, Data Science" style="width: 100%; font-size: 14px;">
                <small style="color: var(--text-dim); font-size: 12px; margin-top: 5px; display: block;">This specialization will appear in dropdowns across all Course Mappings.</small>
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 500; color: var(--text-primary); cursor: pointer;">
                    <input type="checkbox" name="is_active" id="is_active" value="1" checked style="width: 16px; height: 16px; accent-color: #6366f1;">
                    Active in Library
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="sode-btn sode-btn-secondary" onclick="closeSpecModal()">Cancel</button>
                <button type="submit" class="sode-btn sode-btn-primary" id="modalSubmitBtn">Save Specialization</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Add Modal -->
<div id="bulkModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(5px); justify-content: center; align-items: center; padding: 20px;">
    <div style="background: #1e2433; border: 1px solid var(--border-color, #2d3748); border-radius: 12px; max-width: 520px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); overflow: hidden; animation: fadeIn 0.2s ease;">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-color, #2d3748); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                <span>⚡</span> Bulk Add Specializations
            </h3>
            <button type="button" onclick="closeBulkModal()" style="background: none; border: none; color: var(--text-dim); cursor: pointer; font-size: 22px; line-height: 1; padding: 0;">&times;</button>
        </div>
        <form method="POST" action="" style="padding: 22px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_save">

            <div class="form-group" style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-primary); margin-bottom: 7px;">
                    Paste Specializations (One per line)
                </label>
                <textarea name="bulk_specializations" class="form-control" rows="8" required placeholder="Financial Management&#10;Marketing Management&#10;Human Resource Management&#10;Data Science & Analytics&#10;Supply Chain Management" style="width: 100%; font-family: monospace; font-size: 13px; line-height: 1.5;"></textarea>
                <small style="color: var(--text-dim); font-size: 12px; margin-top: 6px; display: block;">Duplicates already in the master library will be skipped automatically.</small>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="sode-btn sode-btn-secondary" onclick="closeBulkModal()">Cancel</button>
                <button type="submit" class="sode-btn sode-btn-primary" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">Add All Specializations</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSpecModal() {
    document.getElementById('modalTitle').textContent = '+ Add Master Specialization';
    document.getElementById('modalSubmitBtn').textContent = 'Save Specialization';
    document.getElementById('spec_id').value = '0';
    document.getElementById('specialization_name').value = '';
    document.getElementById('is_active').checked = true;
    document.getElementById('specModal').style.display = 'flex';
    document.getElementById('specialization_name').focus();
}

function editSpec(spec) {
    document.getElementById('modalTitle').textContent = 'Edit Master Specialization';
    document.getElementById('modalSubmitBtn').textContent = 'Update Specialization';
    document.getElementById('spec_id').value = spec.id;
    document.getElementById('specialization_name').value = spec.specialization_name;
    document.getElementById('is_active').checked = parseInt(spec.is_active) === 1;
    document.getElementById('specModal').style.display = 'flex';
    document.getElementById('specialization_name').focus();
}

function closeSpecModal() {
    document.getElementById('specModal').style.display = 'none';
}

function openBulkModal() {
    document.getElementById('bulkModal').style.display = 'flex';
}

function closeBulkModal() {
    document.getElementById('bulkModal').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('specModal');
    var bModal = document.getElementById('bulkModal');
    var mapModal = document.getElementById('mappedSpecModal');
    if (event.target === modal) closeSpecModal();
    if (event.target === bModal) closeBulkModal();
    if (event.target === mapModal) closeMappedSpecModal();
}
</script>

<!-- Mapped Course Mappings Modal for Specialization -->
<div id="mappedSpecModal" class="uni-courses-modal-backdrop" style="display: none;" onclick="handleSpecBackdropClick(event)">
    <div class="uni-courses-modal-dialog" onclick="event.stopPropagation()">
        <!-- Modal Header -->
        <div class="uni-courses-modal-header">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; color: #818cf8; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                </div>
                <div style="min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 9px; flex-wrap: wrap;">
                        <h3 id="modalSpecTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: #fff; line-height: 1.3;">Specialization Mappings</h3>
                        <span id="modalSpecCountBadge" class="badge badge-warning" style="font-size: 11px;">0 Mappings</span>
                    </div>
                    <div id="modalSpecSub" style="font-size: 12px; color: var(--text-dim, #94a3b8); margin-top: 3px;">
                        Loading mapped courses...
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                <a href="<?= BASE_URL ?>/modules/mappings/create.php" target="_blank" class="uni-modal-add-btn" title="Create new course mapping">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>New Mapping</span>
                </a>
                <button type="button" onclick="closeMappedSpecModal()" class="uni-modal-close-btn" title="Close (Esc)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="uni-courses-toolbar">
            <div class="uni-courses-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="modalSpecSearchInput" placeholder="Filter by university, course name, mode..." oninput="handleSpecSearchInput()">
                <button type="button" id="modalSpecClearSearchBtn" onclick="clearSpecSearch()" style="display: none; background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 0 4px; font-size: 16px; line-height: 1;">&times;</button>
            </div>
            <div class="uni-courses-filter-pills" id="modalSpecFilterPills">
                <button type="button" class="filter-pill active" data-filter="ALL" onclick="setSpecFilter('ALL', this)">All (<span id="countSpecAll">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Online" onclick="setSpecFilter('Online', this)">Online (<span id="countSpecOnline">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Distance" onclick="setSpecFilter('Distance', this)">Distance (<span id="countSpecDistance">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Active" onclick="setSpecFilter('Active', this)">Active (<span id="countSpecActive">0</span>)</button>
            </div>
        </div>

        <!-- Modal Body Content -->
        <div class="uni-courses-modal-body">
            <!-- Loading State -->
            <div id="modalSpecLoadingState" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; gap: 14px;">
                <div class="uni-modal-spinner"></div>
                <div style="color: var(--text-dim, #94a3b8); font-size: 13px;">Fetching mapped courses...</div>
            </div>

            <!-- Error State -->
            <div id="modalSpecErrorState" style="display: none; text-align: center; padding: 50px 20px;">
                <div style="color: #ef4444; font-size: 14px; margin-bottom: 12px;" id="modalSpecErrorMessage">Failed to load mapped courses.</div>
                <button type="button" onclick="retryLoadMappedSpecs()" class="btn-primary btn-sm">Retry</button>
            </div>

            <!-- Empty State -->
            <div id="modalSpecEmptyState" style="display: none; text-align: center; padding: 60px 20px;">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(99, 102, 241, 0.12); display: inline-flex; align-items: center; justify-content: center; color: #818cf8; margin-bottom: 14px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                </div>
                <h4 style="margin: 0 0 6px 0; color: #fff; font-size: 16px;">No Course Mappings Found</h4>
                <p style="margin: 0 0 18px 0; color: var(--text-dim, #94a3b8); font-size: 13px; max-width: 380px; margin-inline: auto;">
                    This specialization is not currently attached to any course mapping.
                </p>
                <a href="<?= BASE_URL ?>/modules/mappings/create.php" target="_blank" class="uni-modal-add-btn" style="display: inline-flex; margin-inline: auto;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Create Course Mapping</span>
                </a>
            </div>

            <!-- Mappings Table -->
            <div id="modalSpecTableWrap" style="display: none;">
                <div style="overflow-x: auto;">
                    <table class="admin-table modal-courses-table" style="width: 100%; margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">#</th>
                                <th>University</th>
                                <th>Course Program</th>
                                <th style="width: 100px; text-align: center;">Mode</th>
                                <th style="width: 130px;">Fee / Sem</th>
                                <th style="width: 110px;">Duration</th>
                                <th style="width: 100px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="modalSpecTbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
                <div id="modalSpecNoMatches" style="display: none; text-align: center; padding: 40px 20px; color: var(--text-dim, #94a3b8); font-size: 13px;">
                    No mapped courses found matching your search / filter.
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="uni-courses-modal-footer">
            <div style="font-size: 12px; color: var(--text-dim, #94a3b8);">
                Showing <strong id="modalSpecShownCount" style="color: #fff;">0</strong> of <strong id="modalSpecTotalCount" style="color: #fff;">0</strong> mapped courses
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="uni-modal-footer-close-btn" onclick="closeMappedSpecModal()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <span>Close</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Specialization Mappings Modal Styling */
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

.sode-spec-badge:hover {
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
    let currentSpecId = 0;
    let currentSpecName = '';
    let allSpecMappings = [];
    let currentSpecFilter = 'ALL';
    let currentSpecSearch = '';

    window.openMappedSpecModal = function(specId, specNameHint) {
        currentSpecId = specId;
        currentSpecName = specNameHint;
        currentSpecFilter = 'ALL';
        currentSpecSearch = '';
        allSpecMappings = [];

        const modal = document.getElementById('mappedSpecModal');
        const modalTitle = document.getElementById('modalSpecTitle');
        const modalSub = document.getElementById('modalSpecSub');
        const countBadge = document.getElementById('modalSpecCountBadge');
        const searchInput = document.getElementById('modalSpecSearchInput');
        const clearBtn = document.getElementById('modalSpecClearSearchBtn');

        modalTitle.textContent = (specNameHint ? specNameHint : 'Specialization Mappings');
        modalSub.textContent = 'Loading mapped courses...';
        countBadge.textContent = '...';
        searchInput.value = '';
        clearBtn.style.display = 'none';

        // Reset filter pills
        document.querySelectorAll('#modalSpecFilterPills .filter-pill').forEach(btn => {
            if (btn.getAttribute('data-filter') === 'ALL') {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        showSpecState('loading');
        fetchMappedSpecs(specId, specNameHint);
    };

    window.closeMappedSpecModal = function() {
        const modal = document.getElementById('mappedSpecModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.handleSpecBackdropClick = function(event) {
        if (event.target.id === 'mappedSpecModal') {
            closeMappedSpecModal();
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('mappedSpecModal');
            if (modal && modal.style.display !== 'none') {
                closeMappedSpecModal();
            }
        }
    });

    window.retryLoadMappedSpecs = function() {
        if (currentSpecId > 0 || currentSpecName) {
            showSpecState('loading');
            fetchMappedSpecs(currentSpecId, currentSpecName);
        }
    };

    function showSpecState(state) {
        document.getElementById('modalSpecLoadingState').style.display = state === 'loading' ? 'flex' : 'none';
        document.getElementById('modalSpecErrorState').style.display = state === 'error' ? 'block' : 'none';
        document.getElementById('modalSpecEmptyState').style.display = state === 'empty' ? 'block' : 'none';
        document.getElementById('modalSpecTableWrap').style.display = state === 'table' ? 'block' : 'none';
    }

    function fetchMappedSpecs(specId, specName) {
        const url = ADMIN_BASE_URL + '/api/get_specialization_mappings.php?spec_id=' + encodeURIComponent(specId) + '&spec_name=' + encodeURIComponent(specName || '');

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Error fetching mappings');
                }

                if (data.specialization) {
                    const sp = data.specialization;
                    document.getElementById('modalSpecTitle').textContent = sp.name;
                    document.getElementById('modalSpecSub').textContent = 
                        'Master Specialization • Status: ' + (Number(sp.is_active) === 1 ? 'Active' : 'Inactive');
                }

                allSpecMappings = Array.isArray(data.mappings) ? data.mappings : [];
                updateSpecCounts();

                if (allSpecMappings.length === 0) {
                    showSpecState('empty');
                } else {
                    showSpecState('table');
                    renderSpecTable();
                }
            })
            .catch(err => {
                console.error('Error fetching specialization mappings:', err);
                document.getElementById('modalSpecErrorMessage').textContent = 'Failed to load mapped courses: ' + err.message;
                showSpecState('error');
            });
    }

    function updateSpecCounts() {
        const total = allSpecMappings.length;
        const onlineCount = allSpecMappings.filter(m => (m.mode || '').toLowerCase() === 'online').length;
        const distanceCount = allSpecMappings.filter(m => (m.mode || '').toLowerCase() === 'distance').length;
        const activeCount = allSpecMappings.filter(m => Number(m.uni_active) === 1).length;

        document.getElementById('modalSpecCountBadge').textContent = total + (total === 1 ? ' Mapping' : ' Mappings');
        document.getElementById('countSpecAll').textContent = total;
        document.getElementById('countSpecOnline').textContent = onlineCount;
        document.getElementById('countSpecDistance').textContent = distanceCount;
        document.getElementById('countSpecActive').textContent = activeCount;
        document.getElementById('modalSpecTotalCount').textContent = total;
    }

    window.setSpecFilter = function(filter, btn) {
        currentSpecFilter = filter;
        document.querySelectorAll('#modalSpecFilterPills .filter-pill').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        renderSpecTable();
    };

    window.handleSpecSearchInput = function() {
        const val = document.getElementById('modalSpecSearchInput').value.trim();
        currentSpecSearch = val.toLowerCase();
        document.getElementById('modalSpecClearSearchBtn').style.display = val ? 'inline' : 'none';
        renderSpecTable();
    };

    window.clearSpecSearch = function() {
        document.getElementById('modalSpecSearchInput').value = '';
        currentSpecSearch = '';
        document.getElementById('modalSpecClearSearchBtn').style.display = 'none';
        renderSpecTable();
    };

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function formatFee(fee) {
        if (!fee) return '<span style="color:var(--text-dim, #64748b);">—</span>';
        const num = parseFloat(String(fee).replace(/[^0-9.]/g, ''));
        if (isNaN(num) || num <= 0) return '<span style="color:var(--text-dim, #64748b);">—</span>';
        return '<span style="font-weight:700; color:#10b981;">₹' + num.toLocaleString('en-IN') + '</span>';
    }

    function renderSpecTable() {
        const tbody = document.getElementById('modalSpecTbody');
        const noMatches = document.getElementById('modalSpecNoMatches');
        const shownCount = document.getElementById('modalSpecShownCount');

        let filtered = allSpecMappings.filter(item => {
            if (currentSpecFilter === 'Online' && (item.mode || '').toLowerCase() !== 'online') return false;
            if (currentSpecFilter === 'Distance' && (item.mode || '').toLowerCase() !== 'distance') return false;
            if (currentSpecFilter === 'Active' && Number(item.uni_active) !== 1) return false;

            if (currentSpecSearch) {
                const haystack = (
                    (item.uni_short || '') + ' ' + 
                    (item.uni_name || '') + ' ' + 
                    (item.course_short || '') + ' ' + 
                    (item.course_name || '') + ' ' + 
                    (item.mode || '')
                ).toLowerCase();
                if (haystack.indexOf(currentSpecSearch) === -1) return false;
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
                    <td>${formatFee(m.spec_fee)}</td>
                    <td><span style="color:var(--text-secondary, #cbd5e1); font-size:12px;">${escapeHtml(m.spec_duration || '—')}</span></td>
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
