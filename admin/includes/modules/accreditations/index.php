<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('accreditations');

$page_title = 'Accreditations';
$page_subtitle = 'Manage UGC-DEB, AICTE, NAAC, NIRF, and other approvals';
$active_page_key = 'accreditations';

$db = get_db_connection();

// Handle Save / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        if ($id && !user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update accreditations.', 'error');
            redirect(BASE_URL . '/modules/accreditations/index.php');
        } elseif (!$id && !user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create accreditations.', 'error');
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
        $title = trim($_POST['title'] ?? '');
        $image_url = get_relative_asset_path(trim($_POST['image_url'] ?? ''));
        $official_link = trim($_POST['official_link'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($title)) {
            set_flash_message('Accreditation Title is required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("UPDATE accreditations SET title = ?, image_url = ?, official_link = ?, description = ? WHERE id = ?");
                $stmt->execute([$title, $image_url, $official_link, $description, $id]);
                set_flash_message('Accreditation updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("INSERT INTO accreditations (title, image_url, official_link, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$title, $image_url, $official_link, $description]);
                set_flash_message('Accreditation added successfully!', 'success');
            }
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
    } elseif ($action === 'delete') {
        if (!user_can('delete')) {
            set_flash_message('Access Denied: You do not have permission to delete accreditations.', 'error');
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $ok = move_to_trash('accreditations', $id);
            if ($ok) {
                set_flash_message('Accreditation moved to Trash! You can restore it anytime.', 'success');
            } else {
                set_flash_message('Failed to move accreditation to Trash.', 'error');
            }
            redirect(BASE_URL . '/modules/accreditations/index.php');
        }
    }
}

// Edit Mode
$edit_acc = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM accreditations WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_acc = $stmt->fetch();
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (a.title LIKE :q1 OR a.description LIKE :q2)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM accreditations a $where_sql");
    $count_stmt->execute($params);
    $total_accreditations = (int)$count_stmt->fetchColumn();
} else {
    $total_accreditations = (int)$db->query("SELECT COUNT(*) FROM accreditations")->fetchColumn();
}

// Fetch paginated accreditations
$stmt = $db->prepare("
    SELECT a.*,
           (SELECT COUNT(*) FROM university_accreditations WHERE accreditation_id = a.id) AS universities_count
    FROM accreditations a 
    $where_sql
    ORDER BY a.id ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$accreditations = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search accreditations by title or description..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_acc ? 'Edit Accreditation' : 'Add Accreditation'; ?></span>
            <?php if ($edit_acc): ?>
                <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $edit_acc['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Title * (e.g. UGC-DEB Approved / NAAC A++)</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($edit_acc['title'] ?? ''); ?>" placeholder="UGC-DEB Approved" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Badge / Logo Image URL</label>
                    <div class="media-input-group">
                        <input type="text" name="image_url" id="field_acc_logo" class="form-control" value="<?php echo htmlspecialchars($edit_acc['image_url'] ?? ''); ?>" placeholder="https://.../ugc-logo.png">
                        <button type="button" class="btn-media-choose media-picker-btn" data-target="field_acc_logo" data-preview="preview_acc_logo" data-type="image">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            Choose
                        </button>
                    </div>
                    <div class="media-preview-container" id="preview_acc_logo">
                        <?php if (!empty($edit_acc['image_url'])): ?>
                            <div class="media-preview-item">
                                <img src="<?php echo htmlspecialchars(get_asset_url($edit_acc['image_url'])); ?>" alt="Preview" style="max-height:80px;">
                                <span class="media-preview-remove" onclick="removeMediaPreview('field_acc_logo', 'preview_acc_logo')">&times;</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Official Link</label>
                    <input type="url" name="official_link" class="form-control" value="<?php echo htmlspecialchars($edit_acc['official_link'] ?? ''); ?>" placeholder="https://deb.ugc.ac.in">
                </div>

                <div class="form-group">
                    <label class="form-label">Description / Importance Note</label>
                    <textarea name="description" class="form-textarea" placeholder="Why this accreditation matters..."><?php echo htmlspecialchars($edit_acc['description'] ?? ''); ?></textarea>
                </div>

                <div style="margin-top:24px; display:flex; gap:10px;">
                    <?php if ($edit_acc): ?>
                        <?php if (can_update()): ?>
                            <button type="submit" class="btn-primary" style="flex:1;">Update Accreditation</button>
                        <?php else: ?>
                            <button type="button" class="btn-secondary btn-disabled-locked" disabled style="flex:1;" title="Access Denied: You do not have permission to update">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                Update Accreditation (Locked)
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (can_create()): ?>
                            <button type="submit" class="btn-primary" style="flex:1;">Save Accreditation</button>
                        <?php else: ?>
                            <button type="button" class="btn-secondary btn-disabled-locked" disabled style="flex:1;" title="Access Denied: You do not have permission to create">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                Save Accreditation (Locked)
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($edit_acc): ?>
                        <a href="<?php echo BASE_URL; ?>/modules/accreditations/index.php" class="btn-sm action-btn" style="text-decoration:none; padding:10px 16px; font-weight:600;">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Accreditations Master (<?php echo $total_accreditations; ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Badge</th>
                        <th>Title</th>
                        <th>Universities</th>
                        <th>Link</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($accreditations)): ?>
                        <tr><td colspan="5" style="text-align:center; padding:32px; color:var(--text-dim);"><?php echo $search !== '' ? 'No accreditations match your search query "' . htmlspecialchars($search) . '".' : 'No accreditations added yet.'; ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($accreditations as $a): ?>
                            <tr>
                                <td style="width:40px;">
                                    <?php if (!empty($a['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars(get_asset_url($a['image_url'])); ?>" alt="logo" style="width:32px; height:32px; object-fit:contain; border-radius:4px; background:#fff; padding:2px;" loading="lazy">
                                    <?php else: ?>
                                        <div style="width:32px; height:32px; border-radius:4px; background:var(--bg-input); display:flex; align-items:center; justify-content:center; color:var(--text-dim); font-size:10px; font-weight:700;">
                                            DOC
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($a['title']); ?></strong>
                                    <div style="font-size:11.5px; color:var(--text-dim);"><?php echo htmlspecialchars($a['description'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <button type="button" 
                                            onclick="openAccUniversitiesModal(<?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title'])) ?>')" 
                                            class="badge sode-acc-uni-btn" 
                                            id="acc-badge-btn-<?= (int)$a['id'] ?>"
                                            title="Click to view, assign, or remove universities for <?= htmlspecialchars($a['title']) ?>">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                        <span id="acc-count-text-<?= (int)$a['id'] ?>"><?= (int)$a['universities_count'] ?> <?= ((int)$a['universities_count'] === 1 ? 'University' : 'Universities') ?></span>
                                        <span style="opacity:0.75; font-size:10px;">&rarr;</span>
                                    </button>
                                </td>
                                <td>
                                    <?php if (!empty($a['official_link'])): ?>
                                        <a href="<?php echo htmlspecialchars($a['official_link']); ?>" target="_blank" class="action-btn" title="Visit Link">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                        </a>
                                    <?php else: ?>
                                        <span style="color:var(--text-dim);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <?php echo rbac_render_edit_button(BASE_URL . '/modules/accreditations/index.php?edit_id=' . $a['id'], 'Edit'); ?>

                                        <?php echo rbac_render_delete_button($a['id'], 'Delete'); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo sode_render_pagination($total_accreditations, $page, $per_page); ?>
    </div>
</div>

<!-- Modal: Accreditation Universities Manager -->
<div id="accUniversitiesModal" class="uni-courses-modal-backdrop" style="display: none;" onclick="handleAccBackdropClick(event)">
    <div class="uni-courses-modal-dialog" onclick="event.stopPropagation()">
        <!-- Modal Header -->
        <div class="uni-courses-modal-header">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <div id="modalAccIconWrap" style="width: 40px; height: 40px; border-radius: 10px; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.35); display: flex; align-items: center; justify-content: center; color: #fbbf24; flex-shrink: 0; overflow: hidden;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                </div>
                <div style="min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 9px; flex-wrap: wrap;">
                        <h3 id="modalAccTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: #fff; line-height: 1.3;">Accreditation Universities</h3>
                        <span id="modalAccCountBadge" class="badge badge-warning" style="font-size: 11px;">0 Universities</span>
                    </div>
                    <div id="modalAccMeta" style="font-size: 12px; color: var(--text-dim, #94a3b8); margin-top: 3px;">
                        Manage universities assigned to this accreditation
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                <a href="<?= BASE_URL ?>/modules/universities/create.php" target="_blank" class="uni-modal-add-btn" title="Create new university">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>New University</span>
                </a>
                <button type="button" onclick="closeAccUniversitiesModal()" class="uni-modal-close-btn" title="Close (Esc)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>

        <!-- Add University Box -->
        <div class="acc-assign-box">
            <div class="acc-assign-label-wrap">
                <div class="acc-assign-label-title">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.2"><path d="M12 2L2 7l10 5 10-5-10-5z"></path><path d="M2 17l10 5 10-5"></path><path d="M2 12l10 5 10-5"></path></svg>
                    <span>Assign University</span>
                </div>
                <span class="acc-assign-label-sub">Link university to this accreditation</span>
            </div>
            
            <div class="acc-assign-inputs-wrap">
                <!-- Searchable University Select Component -->
                <div class="acc-searchable-select" id="accSearchableSelectWrap">
                    <input type="hidden" id="modalAccUniSelect" value="">
                    
                    <button type="button" class="acc-ss-trigger" id="accSsTrigger" onclick="toggleAccSsDropdown(event)" aria-haspopup="listbox" aria-expanded="false">
                        <div class="acc-ss-trigger-content">
                            <svg class="acc-ss-leading-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <span class="acc-ss-trigger-text" id="accSsTriggerText">
                                <span style="color: var(--text-dim, #94a3b8);">-- Choose University to Assign --</span>
                            </span>
                        </div>
                        <div class="acc-ss-trigger-right">
                            <span class="acc-ss-clear-btn" id="accSsClearSelectionBtn" onclick="clearAccSsSelection(event)" title="Clear selection" style="display: none;">&times;</span>
                            <svg class="acc-ss-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"></polyline></svg>
                        </div>
                    </button>

                    <!-- Dropdown Menu with Live Search -->
                    <div class="acc-ss-menu" id="accSsMenu" style="display: none;">
                        <div class="acc-ss-search-wrap">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#64748b; flex-shrink:0;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" id="accSsSearchInput" class="acc-ss-search-input" placeholder="Type university name or slug to search..." autocomplete="off" oninput="filterAccSsOptions(this.value)">
                            <button type="button" id="accSsSearchClear" class="acc-ss-search-clear" onclick="clearAccSsSearchInput(event)" style="display: none;">&times;</button>
                        </div>
                        <div class="acc-ss-options-list" id="accSsOptionsList" role="listbox">
                            <!-- Populated dynamically via JS -->
                        </div>
                        <div class="acc-ss-menu-footer">
                            <span>Showing <strong id="accSsShownCount" style="color:#fff;">0</strong> available</span>
                            <span style="font-size:10.5px; color:#64748b;">Press &crarr; to select</span>
                        </div>
                    </div>
                </div>

                <!-- Action Button (Sleek, width auto, no double plus) -->
                <button type="button" id="modalAccAddBtn" onclick="assignSelectedUniversity()" class="acc-btn-assign">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Assign University</span>
                </button>
            </div>
        </div>

        <!-- Alert Notification Bar -->
        <div id="modalAccAlert" style="display: none; margin: 10px 24px 0; padding: 10px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600;"></div>

        <!-- Filter & Search Toolbar -->
        <div class="uni-courses-toolbar">
            <div class="uni-courses-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="modalAccSearchInput" placeholder="Filter assigned universities by name..." oninput="handleAccSearchInput()">
                <button type="button" id="modalAccClearSearchBtn" onclick="clearAccSearch()" style="display: none; background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 0 4px; font-size: 16px; line-height: 1;">&times;</button>
            </div>
            <div class="uni-courses-filter-pills" id="modalAccFilterPills">
                <button type="button" class="filter-pill active" data-filter="ALL" onclick="setAccFilter('ALL', this)">All (<span id="countAccAll">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Active" onclick="setAccFilter('Active', this)">Active (<span id="countAccActive">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Inactive" onclick="setAccFilter('Inactive', this)">Inactive (<span id="countAccInactive">0</span>)</button>
            </div>
        </div>

        <!-- Modal Body Content -->
        <div class="uni-courses-modal-body">
            <!-- Loading State -->
            <div id="modalAccLoadingState" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; gap: 14px;">
                <div class="uni-modal-spinner"></div>
                <div style="color: var(--text-dim, #94a3b8); font-size: 13px;">Fetching assigned universities...</div>
            </div>

            <!-- Error State -->
            <div id="modalAccErrorState" style="display: none; text-align: center; padding: 50px 20px;">
                <div style="color: #ef4444; font-size: 14px; margin-bottom: 12px;" id="modalAccErrorMessage">Failed to load universities.</div>
                <button type="button" onclick="retryLoadAccUniversities()" class="btn-primary btn-sm">Retry</button>
            </div>

            <!-- Empty State -->
            <div id="modalAccEmptyState" style="display: none; text-align: center; padding: 50px 20px;">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(245, 158, 11, 0.12); display: inline-flex; align-items: center; justify-content: center; color: #fbbf24; margin-bottom: 14px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </div>
                <h4 style="margin: 0 0 6px 0; color: #fff; font-size: 16px;">No Universities Assigned</h4>
                <p style="margin: 0 0 18px 0; color: var(--text-dim, #94a3b8); font-size: 13px; max-width: 420px; margin-inline: auto;">
                    No universities are currently linked to this accreditation. Use the dropdown above to assign universities directly.
                </p>
            </div>

            <!-- Universities Table -->
            <div id="modalAccTableWrap" style="display: none;">
                <div style="overflow-x: auto;">
                    <table class="admin-table modal-courses-table" style="width: 100%; margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">#</th>
                                <th>University Name</th>
                                <th style="width: 180px;">Slug / Identifier</th>
                                <th style="width: 100px; text-align: center;">Status</th>
                                <th style="width: 180px; text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="modalAccTbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
                <div id="modalAccNoMatches" style="display: none; text-align: center; padding: 40px 20px; color: var(--text-dim, #94a3b8); font-size: 13px;">
                    No assigned universities match your search query.
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="uni-courses-modal-footer">
            <div style="font-size: 12px; color: var(--text-dim, #94a3b8);">
                Showing <strong id="modalAccShownCount" style="color: #fff;">0</strong> of <strong id="modalAccTotalCount" style="color: #fff;">0</strong> assigned universities
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="uni-modal-footer-close-btn" onclick="closeAccUniversitiesModal()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <span>Close</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Accreditation Universities Modal & Button Styling */
.sode-acc-uni-btn {
    background: rgba(245, 158, 11, 0.15) !important;
    color: #fbbf24 !important;
    border: 1px solid rgba(245, 158, 11, 0.35) !important;
    font-weight: 700 !important;
    font-size: 11.5px !important;
    padding: 4px 11px !important;
    border-radius: 20px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    text-decoration: none !important;
}
.sode-acc-uni-btn:hover {
    background: rgba(245, 158, 11, 0.28) !important;
    border-color: #f59e0b !important;
    color: #fff !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
}

.acc-assign-box {
    padding: 14px 24px;
    background: #10182b;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 80;
}

.acc-assign-label-wrap {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 150px;
    flex-shrink: 0;
}

.acc-assign-label-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.2px;
}

.acc-assign-label-sub {
    font-size: 11.5px;
    color: var(--text-dim, #94a3b8);
}

.acc-assign-inputs-wrap {
    display: flex;
    flex: 1;
    align-items: center;
    gap: 12px;
    min-width: 0;
    position: relative;
}

/* Searchable Select Component */
.acc-searchable-select {
    position: relative;
    flex: 1 1 auto;
    min-width: 0;
    width: 100%;
}

.acc-ss-trigger {
    width: 100%;
    height: 40px;
    background: #141f36;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 9px;
    padding: 0 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    color: #fff;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    box-sizing: border-box;
}
.acc-ss-trigger:hover,
.acc-searchable-select.open .acc-ss-trigger {
    background: #182542;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.22);
}

.acc-ss-trigger-content {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
    flex: 1;
}

.acc-ss-leading-icon {
    color: #64748b;
    flex-shrink: 0;
    transition: color 0.2s ease;
}
.acc-searchable-select.open .acc-ss-leading-icon,
.acc-ss-trigger:hover .acc-ss-leading-icon {
    color: #818cf8;
}

.acc-ss-trigger-text {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    font-weight: 500;
    font-size: 13px;
    color: #e2e8f0;
}

.acc-ss-trigger-right {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.acc-ss-clear-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
    color: #94a3b8;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
    transition: all 0.15s ease;
}
.acc-ss-clear-btn:hover {
    background: rgba(239, 68, 68, 0.25);
    color: #f87171;
}

.acc-ss-arrow {
    color: #94a3b8;
    transition: transform 0.2s ease;
}
.acc-searchable-select.open .acc-ss-arrow {
    transform: rotate(180deg);
    color: #818cf8;
}

.acc-ss-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    width: 100%;
    min-width: 100%;
    background: #0f172a;
    border: 1px solid rgba(99, 102, 241, 0.35);
    border-radius: 10px;
    box-shadow: 0 22px 50px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(255, 255, 255, 0.06);
    z-index: 99999;
    overflow: hidden;
    animation: accSsFadeIn 0.15s ease-out;
}
@keyframes accSsFadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.acc-ss-search-wrap {
    padding: 8px 12px;
    background: #141f38;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}

.acc-ss-search-input {
    background: #0b1120 !important;
    border: 1px solid #2e3d5b !important;
    color: #fff !important;
    font-size: 12.5px !important;
    border-radius: 6px !important;
    height: 34px !important;
    padding: 0 28px 0 10px !important;
    flex: 1 !important;
    outline: none !important;
    box-sizing: border-box !important;
}
.acc-ss-search-input:focus {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25) !important;
}

.acc-ss-search-clear {
    position: absolute;
    right: 20px;
    background: none;
    border: none;
    color: #94a3b8;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
}
.acc-ss-search-clear:hover {
    color: #fff;
}

.acc-ss-options-list {
    max-height: 240px;
    overflow-y: auto;
    padding: 6px;
}
.acc-ss-options-list::-webkit-scrollbar {
    width: 6px;
}
.acc-ss-options-list::-webkit-scrollbar-thumb {
    background: #2e3d5b;
    border-radius: 4px;
}

.acc-ss-option {
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    transition: background 0.15s, color 0.15s;
    user-select: none;
}
.acc-ss-option:hover,
.acc-ss-option.focused {
    background: #1e293b;
}
.acc-ss-option.selected {
    background: rgba(99, 102, 241, 0.22);
    border: 1px solid rgba(99, 102, 241, 0.35);
}

.acc-ss-option-name {
    font-weight: 600;
    color: #fff;
    font-size: 13px;
}
.acc-ss-option-sub {
    font-size: 11.5px;
    color: #94a3b8;
    margin-top: 1px;
}

.acc-ss-empty {
    padding: 24px 14px;
    text-align: center;
    color: #94a3b8;
    font-size: 12.5px;
}

.acc-ss-menu-footer {
    padding: 6px 12px;
    background: #0b1120;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 11px;
    color: #64748b;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* Dedicated Assign University Action Button */
.acc-btn-assign {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    height: 40px !important;
    padding: 0 20px !important;
    width: auto !important;
    min-width: 155px !important;
    flex-shrink: 0 !important;
    border-radius: 9px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    letter-spacing: 0.2px !important;
    white-space: nowrap !important;
    cursor: pointer !important;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1) !important;
    border: 1px solid rgba(16, 185, 129, 0.45) !important;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
    color: #ffffff !important;
    box-shadow: 0 3px 12px rgba(16, 185, 129, 0.32), inset 0 1px 0 rgba(255, 255, 255, 0.2) !important;
    box-sizing: border-box !important;
}
.acc-btn-assign:hover:not(:disabled) {
    transform: translateY(-1.5px) !important;
    background: linear-gradient(135deg, #34d399 0%, #059669 100%) !important;
    border-color: #34d399 !important;
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.45) !important;
}
.acc-btn-assign:active:not(:disabled) {
    transform: translateY(0) !important;
}
.acc-btn-assign:disabled {
    opacity: 0.65 !important;
    cursor: not-allowed !important;
    transform: none !important;
    box-shadow: none !important;
}

.acc-btn-spinner {
    width: 13px;
    height: 13px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: accSpin 0.65s linear infinite;
    display: inline-block;
}

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
    animation: accModalBgFade 0.2s ease-out;
}
@keyframes accModalBgFade {
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
    box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(245, 158, 11, 0.15);
    overflow: hidden;
    animation: accModalZoom 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes accModalZoom {
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
}
.uni-modal-close-btn:hover {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #f87171 !important;
    border-color: rgba(239, 68, 68, 0.4) !important;
    transform: rotate(90deg) scale(1.05) !important;
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
    max-height: calc(88vh - 240px);
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

.uni-modal-footer-close-btn {
    display: inline-flex !important;
    align-items: center !important;
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
    transition: all 0.2s ease !important;
}
.uni-modal-footer-close-btn:hover {
    background: rgba(239, 68, 68, 0.16) !important;
    color: #f87171 !important;
}

.uni-modal-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid rgba(245, 158, 11, 0.2);
    border-top-color: #fbbf24;
    border-radius: 50%;
    animation: accSpin 0.75s linear infinite;
}
@keyframes accSpin {
    to { transform: rotate(360deg); }
}

.acc-btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 11px;
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 6px;
    text-decoration: none;
    background: rgba(99, 102, 241, 0.14);
    color: #a5b4fc;
    border: 1px solid rgba(99, 102, 241, 0.3);
    transition: all 0.2s ease;
}
.acc-btn-edit:hover {
    background: #4f46e5;
    color: #fff;
    border-color: #4f46e5;
    transform: translateY(-1px);
}

.acc-btn-remove {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 11px;
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 6px;
    border: 1px solid rgba(239, 68, 68, 0.35);
    background: rgba(239, 68, 68, 0.12);
    color: #fca5a5;
    cursor: pointer;
    transition: all 0.2s ease;
}
.acc-btn-remove:hover {
    background: #ef4444;
    color: #fff;
    border-color: #ef4444;
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(239, 68, 68, 0.35);
}
</style>

<script>
(function() {
    const ADMIN_BASE_URL = '<?= BASE_URL ?>';
    let currentAccId = 0;
    let currentAccTitle = '';
    let allAssignedUnis = [];
    let allAvailableUnis = [];
    let currentFilter = 'ALL';
    let currentSearch = '';
    let selectedUniToAssign = null;
    let currentSsQuery = '';

    window.openAccUniversitiesModal = function(accId, accTitleHint) {
        currentAccId = accId;
        currentAccTitle = accTitleHint;
        currentFilter = 'ALL';
        currentSearch = '';
        allAssignedUnis = [];
        allAvailableUnis = [];
        selectedUniToAssign = null;
        currentSsQuery = '';

        const modal = document.getElementById('accUniversitiesModal');
        const modalTitle = document.getElementById('modalAccTitle');
        const countBadge = document.getElementById('modalAccCountBadge');
        const searchInput = document.getElementById('modalAccSearchInput');
        const clearBtn = document.getElementById('modalAccClearSearchBtn');
        const alertBox = document.getElementById('modalAccAlert');

        alertBox.style.display = 'none';
        modalTitle.textContent = (accTitleHint ? 'Accreditation: ' + accTitleHint : 'Accreditation Universities');
        countBadge.textContent = '...';
        searchInput.value = '';
        clearBtn.style.display = 'none';

        clearAccSsSelection();
        closeAccSsDropdown();

        // Reset filter pills
        document.querySelectorAll('#modalAccFilterPills .filter-pill').forEach(btn => {
            if (btn.getAttribute('data-filter') === 'ALL') {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        showAccState('loading');
        fetchAccUniversities(accId);
    };

    window.closeAccUniversitiesModal = function() {
        closeAccSsDropdown();
        const modal = document.getElementById('accUniversitiesModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.handleAccBackdropClick = function(event) {
        if (event.target.id === 'accUniversitiesModal') {
            closeAccUniversitiesModal();
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('accUniversitiesModal');
            if (modal && modal.style.display !== 'none') {
                closeAccUniversitiesModal();
            }
        }
    });

    window.retryLoadAccUniversities = function() {
        if (currentAccId > 0) {
            showAccState('loading');
            fetchAccUniversities(currentAccId);
        }
    };

    function showAccState(state) {
        document.getElementById('modalAccLoadingState').style.display = state === 'loading' ? 'flex' : 'none';
        document.getElementById('modalAccErrorState').style.display = state === 'error' ? 'block' : 'none';
        document.getElementById('modalAccEmptyState').style.display = state === 'empty' ? 'block' : 'none';
        document.getElementById('modalAccTableWrap').style.display = state === 'table' ? 'block' : 'none';
    }

    function showAlert(msg, isSuccess) {
        const el = document.getElementById('modalAccAlert');
        el.style.display = 'block';
        el.style.background = isSuccess ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
        el.style.border = isSuccess ? '1px solid rgba(16, 185, 129, 0.4)' : '1px solid rgba(239, 68, 68, 0.4)';
        el.style.color = isSuccess ? '#34d399' : '#f87171';
        el.innerHTML = msg;
        setTimeout(() => { el.style.display = 'none'; }, 4000);
    }

    function fetchAccUniversities(accId) {
        const url = ADMIN_BASE_URL + '/api/manage_accreditation_universities.php?acc_id=' + encodeURIComponent(accId);

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Error fetching universities');
                }

                if (data.accreditation) {
                    currentAccTitle = data.accreditation.title;
                    document.getElementById('modalAccTitle').textContent = 'Accreditation: ' + data.accreditation.title;
                    if (data.accreditation.image_url) {
                        document.getElementById('modalAccIconWrap').innerHTML = `<img src="${escapeHtml(data.accreditation.image_url)}" alt="Logo" style="width:100%; height:100%; object-fit:contain; padding:3px; background:#fff;">`;
                    }
                }

                allAssignedUnis = Array.isArray(data.assigned_universities) ? data.assigned_universities : [];
                allAvailableUnis = Array.isArray(data.available_universities) ? data.available_universities : [];

                updateDropdownOptions();
                updateCounts();

                if (allAssignedUnis.length === 0) {
                    showAccState('empty');
                } else {
                    showAccState('table');
                    renderTable();
                }
            })
            .catch(err => {
                console.error('Error fetching accreditation universities:', err);
                document.getElementById('modalAccErrorMessage').textContent = 'Failed to load universities: ' + err.message;
                showAccState('error');
            });
    }

    function updateDropdownOptions() {
        clearAccSsSelection();
        renderSsOptions('');
        updateSsTriggerText();
    }

    function updateSsTriggerText() {
        const textEl = document.getElementById('accSsTriggerText');
        const clearBtn = document.getElementById('accSsClearSelectionBtn');
        if (!textEl) return;

        if (selectedUniToAssign) {
            textEl.innerHTML = `<span style="font-weight: 600; color: #fff;">${escapeHtml(selectedUniToAssign.short_name || selectedUniToAssign.full_name)}</span>` +
                (selectedUniToAssign.full_name && selectedUniToAssign.full_name !== selectedUniToAssign.short_name 
                    ? ` <span style="font-size: 11.5px; color: #94a3b8;">(${escapeHtml(selectedUniToAssign.full_name)})</span>` 
                    : '');
            if (clearBtn) clearBtn.style.display = 'inline-flex';
        } else {
            const count = allAvailableUnis.length;
            textEl.innerHTML = `<span style="color: var(--text-dim, #94a3b8);">-- Choose University to Assign (${count} available) --</span>`;
            if (clearBtn) clearBtn.style.display = 'none';
        }
    }

    function renderSsOptions(query) {
        const listEl = document.getElementById('accSsOptionsList');
        const countEl = document.getElementById('accSsShownCount');
        if (!listEl) return;

        query = (query || '').trim().toLowerCase();
        const filtered = allAvailableUnis.filter(u => {
            if (!query) return true;
            const haystack = ((u.short_name || '') + ' ' + (u.full_name || '') + ' ' + (u.slug || '')).toLowerCase();
            return haystack.indexOf(query) !== -1;
        });

        if (countEl) countEl.textContent = filtered.length;

        if (filtered.length === 0) {
            listEl.innerHTML = `<div class="acc-ss-empty">${query ? 'No universities match "' + escapeHtml(query) + '"' : 'No universities available to assign'}</div>`;
            return;
        }

        let html = '';
        filtered.forEach(u => {
            const isSelected = selectedUniToAssign && selectedUniToAssign.id === u.id;
            const displayName = escapeHtml(u.short_name || u.full_name);
            const fullName = escapeHtml(u.full_name || '');
            const isInactive = Number(u.is_active) !== 1;

            html += `
                <div class="acc-ss-option ${isSelected ? 'selected' : ''}" onclick="selectAccSsUniversity(${u.id})" role="option" aria-selected="${isSelected}">
                    <div style="min-width: 0; flex: 1;">
                        <div class="acc-ss-option-name">${displayName}</div>
                        ${fullName && fullName !== displayName ? `<div class="acc-ss-option-sub">${fullName}</div>` : ''}
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                        ${isInactive ? '<span style="font-size: 10px; color: #fca5a5; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239,68,68,0.3); padding: 1px 6px; border-radius: 10px;">Inactive</span>' : ''}
                        ${isSelected ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>' : ''}
                    </div>
                </div>
            `;
        });
        listEl.innerHTML = html;
    }

    window.toggleAccSsDropdown = function(e) {
        if (e) e.stopPropagation();
        const wrap = document.getElementById('accSearchableSelectWrap');
        const menu = document.getElementById('accSsMenu');
        const input = document.getElementById('accSsSearchInput');
        if (!wrap || !menu) return;

        const isOpen = wrap.classList.contains('open');
        if (isOpen) {
            closeAccSsDropdown();
        } else {
            wrap.classList.add('open');
            menu.style.display = 'block';
            if (input) {
                input.value = '';
                document.getElementById('accSsSearchClear').style.display = 'none';
                renderSsOptions('');
                setTimeout(() => input.focus(), 40);
            }
        }
    };

    window.closeAccSsDropdown = function() {
        const wrap = document.getElementById('accSearchableSelectWrap');
        const menu = document.getElementById('accSsMenu');
        if (wrap) wrap.classList.remove('open');
        if (menu) menu.style.display = 'none';
    };

    window.filterAccSsOptions = function(val) {
        currentSsQuery = val || '';
        const clearBtn = document.getElementById('accSsSearchClear');
        if (clearBtn) clearBtn.style.display = currentSsQuery ? 'block' : 'none';
        renderSsOptions(currentSsQuery);
    };

    window.clearAccSsSearchInput = function(e) {
        if (e) e.stopPropagation();
        const input = document.getElementById('accSsSearchInput');
        if (input) {
            input.value = '';
            input.focus();
        }
        filterAccSsOptions('');
    };

    window.selectAccSsUniversity = function(uniId) {
        const found = allAvailableUnis.find(u => u.id === uniId);
        if (!found) return;

        selectedUniToAssign = found;
        const hiddenInput = document.getElementById('modalAccUniSelect');
        if (hiddenInput) hiddenInput.value = found.id;

        updateSsTriggerText();
        closeAccSsDropdown();
    };

    window.clearAccSsSelection = function(e) {
        if (e) e.stopPropagation();
        selectedUniToAssign = null;
        const hiddenInput = document.getElementById('modalAccUniSelect');
        if (hiddenInput) hiddenInput.value = '';
        updateSsTriggerText();
        renderSsOptions(currentSsQuery);
    };

    // Close on click outside
    document.addEventListener('click', function(e) {
        const wrap = document.getElementById('accSearchableSelectWrap');
        if (wrap && wrap.classList.contains('open')) {
            if (!wrap.contains(e.target)) {
                closeAccSsDropdown();
            }
        }
    });

    // Keyboard support: Enter selects first match in dropdown, Esc closes
    document.addEventListener('keydown', function(e) {
        const wrap = document.getElementById('accSearchableSelectWrap');
        if (wrap && wrap.classList.contains('open')) {
            if (e.key === 'Escape') {
                closeAccSsDropdown();
            } else if (e.key === 'Enter' && e.target.id === 'accSsSearchInput') {
                e.preventDefault();
                const firstOpt = document.querySelector('#accSsOptionsList .acc-ss-option');
                if (firstOpt) firstOpt.click();
            }
        }
    });

    function updateCounts() {
        const total = allAssignedUnis.length;
        const activeCount = allAssignedUnis.filter(u => Number(u.is_active) === 1).length;
        const inactiveCount = total - activeCount;

        document.getElementById('modalAccCountBadge').textContent = total + (total === 1 ? ' University' : ' Universities');
        document.getElementById('countAccAll').textContent = total;
        document.getElementById('countAccActive').textContent = activeCount;
        document.getElementById('countAccInactive').textContent = inactiveCount;
        document.getElementById('modalAccTotalCount').textContent = total;

        // Also update the badge on the main page table if present!
        const mainBadgeText = document.getElementById('acc-count-text-' + currentAccId);
        if (mainBadgeText) {
            mainBadgeText.textContent = total + (total === 1 ? ' University' : ' Universities');
        }
    }

    window.assignSelectedUniversity = function() {
        const select = document.getElementById('modalAccUniSelect');
        const uniId = parseInt(select.value, 10);
        if (!uniId || uniId <= 0) {
            showAlert('Please choose a university from the dropdown first.', false);
            const wrap = document.getElementById('accSearchableSelectWrap');
            if (wrap && !wrap.classList.contains('open')) {
                toggleAccSsDropdown();
            }
            return;
        }

        const btn = document.getElementById('modalAccAddBtn');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="acc-btn-spinner"></span> <span>Assigning...</span>';

        const url = ADMIN_BASE_URL + '/api/manage_accreditation_universities.php';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'add',
                acc_id: currentAccId,
                university_id: uniId
            })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = origText;
            if (!data.success) {
                showAlert(data.message || 'Failed to assign university.', false);
                return;
            }

            showAlert(data.message || 'University assigned successfully!', true);
            allAssignedUnis = Array.isArray(data.assigned_universities) ? data.assigned_universities : [];
            allAvailableUnis = Array.isArray(data.available_universities) ? data.available_universities : [];

            updateDropdownOptions();
            updateCounts();
            showAccState('table');
            renderTable();
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = origText;
            showAlert('Request failed: ' + err.message, false);
        });
    };

    window.removeUniversityFromAcc = function(uniId) {
        const targetUni = allAssignedUnis.find(x => x.id === uniId);
        const uniName = targetUni ? (targetUni.short_name || targetUni.full_name) : 'this university';
        if (!confirm('Are you sure you want to remove "' + uniName + '" from ' + currentAccTitle + '?')) {
            return;
        }

        const url = ADMIN_BASE_URL + '/api/manage_accreditation_universities.php';
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'remove',
                acc_id: currentAccId,
                university_id: uniId
            })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showAlert(data.message || 'Failed to remove university.', false);
                return;
            }

            showAlert(data.message || 'University removed successfully!', true);
            allAssignedUnis = Array.isArray(data.assigned_universities) ? data.assigned_universities : [];
            allAvailableUnis = Array.isArray(data.available_universities) ? data.available_universities : [];

            updateDropdownOptions();
            updateCounts();

            if (allAssignedUnis.length === 0) {
                showAccState('empty');
            } else {
                showAccState('table');
                renderTable();
            }
        })
        .catch(err => {
            showAlert('Request failed: ' + err.message, false);
        });
    };

    window.setAccFilter = function(filter, btn) {
        currentFilter = filter;
        document.querySelectorAll('#modalAccFilterPills .filter-pill').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        renderTable();
    };

    window.handleAccSearchInput = function() {
        const val = document.getElementById('modalAccSearchInput').value.trim();
        currentSearch = val.toLowerCase();
        document.getElementById('modalAccClearSearchBtn').style.display = val ? 'inline' : 'none';
        renderTable();
    };

    window.clearAccSearch = function() {
        document.getElementById('modalAccSearchInput').value = '';
        currentSearch = '';
        document.getElementById('modalAccClearSearchBtn').style.display = 'none';
        renderTable();
    };

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function renderTable() {
        const tbody = document.getElementById('modalAccTbody');
        const noMatches = document.getElementById('modalAccNoMatches');
        const shownCount = document.getElementById('modalAccShownCount');

        let filtered = allAssignedUnis.filter(u => {
            if (currentFilter === 'Active' && Number(u.is_active) !== 1) return false;
            if (currentFilter === 'Inactive' && Number(u.is_active) === 1) return false;

            if (currentSearch) {
                const haystack = ((u.short_name || '') + ' ' + (u.full_name || '') + ' ' + (u.slug || '')).toLowerCase();
                if (haystack.indexOf(currentSearch) === -1) return false;
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
        filtered.forEach((u, idx) => {
            const editUrl = ADMIN_BASE_URL + '/modules/universities/edit.php?id=' + u.id;
            const displayName = escapeHtml(u.short_name || u.full_name);
            const fullName = escapeHtml(u.full_name || '');
            const isActive = Number(u.is_active) === 1;
            const statusBadge = isActive 
                ? '<span class="status-pill active" style="font-size:11px;"><span style="width:6px; height:6px; border-radius:50%; background:#34d399;"></span> Active</span>'
                : '<span class="status-pill inactive" style="font-size:11px;"><span style="width:6px; height:6px; border-radius:50%; background:#f87171;"></span> Inactive</span>';

            const logoHtml = u.logo_url 
                ? `<img src="${escapeHtml(u.logo_url)}" alt="logo" style="width:30px; height:30px; object-fit:contain; border-radius:5px; background:#fff; padding:2px; flex-shrink:0;">`
                : `<div style="width:30px; height:30px; border-radius:5px; background:#19243d; border:1px solid rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:10px; font-weight:700; flex-shrink:0;">UNI</div>`;

            html += `
                <tr>
                    <td style="text-align:center; color:var(--text-dim, #64748b); font-weight:600; font-size:11.5px;">${idx + 1}</td>
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            ${logoHtml}
                            <div style="min-width:0;">
                                <div style="font-weight:700; color:#fff; font-size:13px;">${displayName}</div>
                                ${fullName && fullName !== displayName ? `<div style="font-size:11.5px; color:var(--text-dim, #94a3b8); margin-top:1px;">${fullName}</div>` : ''}
                            </div>
                        </div>
                    </td>
                    <td>
                        <code style="background:rgba(255,255,255,0.06); color:#a5b4fc; padding:2px 6px; border-radius:4px; font-size:11.5px;">${escapeHtml(u.slug || '—')}</code>
                    </td>
                    <td style="text-align:center;">${statusBadge}</td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex; gap:8px; align-items:center; justify-content:flex-end;">
                            <a href="${editUrl}" target="_blank" class="acc-btn-edit" title="Edit University details in new tab">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                <span>Edit</span>
                            </a>
                            <button type="button" onclick="removeUniversityFromAcc(${u.id})" class="acc-btn-remove" title="Remove from this accreditation">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                <span>Remove</span>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }
})();
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
