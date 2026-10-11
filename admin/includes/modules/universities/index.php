<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('universities');

$page_title = 'Universities';
$page_subtitle = 'Manage university profiles, accreditations, and configurations';
$active_page_key = 'universities';

$db = get_db_connection();

// Auto-check and add Alternate Universities columns if not yet present
try {
    $alt_col_chk = $db->query("SHOW COLUMNS FROM universities LIKE 'show_in_alternate'")->fetch();
    if (!$alt_col_chk) {
        $db->exec("ALTER TABLE universities 
            ADD COLUMN alt_desktop_img TEXT NULL AFTER campus_mobile_img,
            ADD COLUMN alt_mobile_img TEXT NULL AFTER alt_desktop_img,
            ADD COLUMN sample_degree_img TEXT NULL AFTER alt_mobile_img,
            ADD COLUMN alt_description TEXT NULL AFTER sample_degree_img,
            ADD COLUMN show_in_alternate TINYINT(1) DEFAULT 0 AFTER alt_description");
    }
} catch (Exception $e) {}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    if (!user_can('delete')) {
        set_flash_message('Access Denied: You do not have permission to delete.', 'error');
        redirect(BASE_URL . '/modules/universities/index.php');
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $u_info = $db->query("SELECT short_name, full_name FROM universities WHERE id = $id")->fetch();
        $title = $u_info['short_name'] ?? ($u_info['full_name'] ?? 'University');
        move_to_trash('universities', $id, $title);
        set_flash_message('University moved to Trash! You can restore it anytime.', 'success');
        redirect(BASE_URL . '/modules/universities/index.php');
    }
}

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (u.full_name LIKE :q1 OR u.short_name LIKE :q2 OR u.slug LIKE :q3)";
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
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM universities u $where_sql");
    $count_stmt->execute($params);
    $total_universities = (int)$count_stmt->fetchColumn();
} else {
    $total_universities = (int)$db->query("SELECT COUNT(*) FROM universities")->fetchColumn();
}

// Fetch paginated universities
$stmt = $db->prepare("
    SELECT u.*, 
           (SELECT COUNT(*) FROM university_course_mappings WHERE university_id = u.id) AS mapped_courses_count,
           (SELECT COUNT(*) FROM news_items WHERE university_id = u.id AND is_global = 0) AS news_count
    FROM universities u
    $where_sql
    ORDER BY u.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$universities = $stmt->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">All Universities (<?php echo $total_universities; ?>)</span>
    </div>
    <?php echo rbac_render_add_button(BASE_URL . '/modules/universities/create.php', 'Add New University', 'btn-primary btn-sm', 'padding:10px 18px; font-size:13.5px; text-decoration:none;'); ?>
</div>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search universities by name, short code, or slug..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="admin-card">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Logo</th>
                    <th>University Name</th>
                    <th>Slug</th>
                    <th>Mode</th>
                    <th>Rating</th>
                    <th>Courses Mapped</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($universities)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:40px; color:var(--text-dim);">
                            <?php echo $search !== '' ? 'No universities match your search query "' . htmlspecialchars($search) . '".' : 'No universities found. Click "Add New University" to create your first record.'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($universities as $uni): ?>
                        <tr>
                            <td style="width:50px;">
                                <?php if (!empty($uni['logo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars(get_asset_url($uni['logo_url'])); ?>" alt="logo" style="height:32px; max-width:60px; object-fit:contain; border-radius:4px; background:#fff; padding:2px;" loading="lazy">
                                <?php else: ?>
                                    <div style="width:36px; height:36px; border-radius:6px; background:var(--bg-input); display:flex; align-items:center; justify-content:center; color:var(--text-dim); font-size:11px; font-weight:700;">
                                        <?php echo substr($uni['short_name'], 0, 3); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($uni['full_name']); ?></strong>
                                <?php if (!empty($uni['show_in_alternate'])): ?>
                                    <span class="badge" style="background:rgba(99,102,241,0.15); color:#818cf8; font-size:10.5px; padding:2px 7px; margin-left:6px; font-weight:600;" title="Included in Alternate Universities Listing">Alt List</span>
                                <?php endif; ?>
                                <div style="font-size:12px; color:var(--text-muted);"><?php echo htmlspecialchars($uni['short_name']); ?></div>
                            </td>
                            <td>
                                <code style="font-size:11px; color:var(--text-dim);"><?php echo htmlspecialchars($uni['slug']); ?></code>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($uni['mode'] ?? 'Online'); ?></span>
                            </td>
                            <td>
                                <span style="color:#f59e0b; font-weight:700;">★ <?php echo htmlspecialchars($uni['rating'] ?? '4.0'); ?></span>
                            </td>
                            <td>
                                <button type="button" 
                                        onclick="openMappedCoursesModal(<?php echo (int)$uni['id']; ?>, '<?php echo htmlspecialchars(addslashes($uni['short_name'] ?: $uni['full_name'])); ?>')" 
                                        class="badge badge-warning sode-courses-badge" 
                                        title="Click to view all mapped courses for <?php echo htmlspecialchars($uni['short_name'] ?: $uni['full_name']); ?>">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink:0;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                    <span><?php echo $uni['mapped_courses_count']; ?> Courses</span>
                                </button>
                            </td>
                            <td>
                                <?php if ($uni['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $uni['id']; ?>#uni-news-section" class="action-btn" title="Manage News Announcements (<?php echo $uni['news_count']; ?>)">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                                    </a>

                                    <?php echo rbac_render_edit_button(BASE_URL . '/modules/universities/edit.php?id=' . $uni['id'], 'Edit University'); ?>

                                    <?php echo rbac_render_delete_button($uni['id'], 'Delete University'); ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo sode_render_pagination($total_universities, $page, $per_page); ?>
</div>

<!-- ===================================================================== -->
<!-- MAPPED COURSES POPUP MODAL                                            -->
<!-- ===================================================================== -->
<div id="mappedCoursesModal" class="uni-courses-modal-backdrop" style="display: none;" onclick="handleBackdropClick(event)">
    <div class="uni-courses-modal-dialog" onclick="event.stopPropagation()">
        <!-- Modal Header -->
        <div class="uni-courses-modal-header">
            <div style="display: flex; align-items: center; gap: 14px; min-width: 0;">
                <div id="modalUniLogoWrap" style="width: 44px; height: 44px; border-radius: 8px; background: #fff; padding: 3px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 2px 8px rgba(0,0,0,0.3); overflow: hidden;">
                    <img id="modalUniLogo" src="" alt="logo" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                    <span id="modalUniLogoFallback" style="font-weight: 700; color: #1e293b; font-size: 13px; display: none;">UNI</span>
                </div>
                <div style="min-width: 0;">
                    <div style="display: flex; align-items: center; gap: 9px; flex-wrap: wrap;">
                        <h3 id="modalUniTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: #fff; line-height: 1.3;">University Courses</h3>
                        <span id="modalCoursesCountBadge" class="badge badge-warning" style="font-size: 11px;">0 Courses</span>
                    </div>
                    <div id="modalUniSub" style="font-size: 12px; color: var(--text-dim, #94a3b8); margin-top: 3px;">
                        Loading university course mappings...
                    </div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                <a id="modalAddNewMappingBtn" href="#" target="_blank" class="uni-modal-add-btn" title="Map new course to this university">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Map Course</span>
                </a>
                <button type="button" onclick="closeMappedCoursesModal()" class="uni-modal-close-btn" title="Close (Esc)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="uni-courses-toolbar">
            <div class="uni-courses-search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="modalSearchInput" placeholder="Filter courses by name, code (e.g. MBA), or mode..." oninput="handleModalSearchInput()">
                <button type="button" id="modalClearSearchBtn" onclick="clearModalSearch()" style="display: none; background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 0 4px; font-size: 16px; line-height: 1;">&times;</button>
            </div>
            <div class="uni-courses-filter-pills" id="modalFilterPills">
                <button type="button" class="filter-pill active" data-filter="ALL" onclick="setCourseFilter('ALL', this)">All (<span id="countAll">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Online" onclick="setCourseFilter('Online', this)">Online (<span id="countOnline">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="Distance" onclick="setCourseFilter('Distance', this)">Distance (<span id="countDistance">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="PG" onclick="setCourseFilter('PG', this)">PG (<span id="countPG">0</span>)</button>
                <button type="button" class="filter-pill" data-filter="UG" onclick="setCourseFilter('UG', this)">UG (<span id="countUG">0</span>)</button>
            </div>
        </div>

        <!-- Modal Body Content -->
        <div class="uni-courses-modal-body">
            <!-- Loading State -->
            <div id="modalLoadingState" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; gap: 14px;">
                <div class="uni-modal-spinner"></div>
                <div style="color: var(--text-dim, #94a3b8); font-size: 13px;">Fetching mapped courses...</div>
            </div>

            <!-- Error State -->
            <div id="modalErrorState" style="display: none; text-align: center; padding: 50px 20px;">
                <div style="color: #ef4444; font-size: 14px; margin-bottom: 12px;" id="modalErrorMessage">Failed to load mapped courses.</div>
                <button type="button" onclick="retryLoadMappedCourses()" class="btn-primary btn-sm">Retry</button>
            </div>

            <!-- Empty State -->
            <div id="modalEmptyState" style="display: none; text-align: center; padding: 60px 20px;">
                <div style="width: 56px; height: 56px; border-radius: 50%; background: rgba(245, 158, 11, 0.12); display: inline-flex; align-items: center; justify-content: center; color: #f59e0b; margin-bottom: 14px;">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                </div>
                <h4 style="margin: 0 0 6px 0; color: #fff; font-size: 16px;">No Courses Mapped Yet</h4>
                <p style="margin: 0 0 18px 0; color: var(--text-dim, #94a3b8); font-size: 13px; max-width: 380px; margin-inline: auto;">
                    This university does not have any mapped courses. Click below to map its first course.
                </p>
                <a id="modalEmptyActionBtn" href="#" target="_blank" class="uni-modal-add-btn" style="display: inline-flex; margin-inline: auto;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                    <span>Map First Course</span>
                </a>
            </div>

            <!-- Courses Table -->
            <div id="modalTableWrap" style="display: none;">
                <div style="overflow-x: auto;">
                    <table class="admin-table modal-courses-table" style="width: 100%; margin: 0;">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">#</th>
                                <th>Course Details</th>
                                <th style="width: 95px; text-align: center;">Mode</th>
                                <th style="width: 75px; text-align: center;">Level</th>
                                <th style="width: 140px;">Per Sem Fee</th>
                                <th style="width: 140px;">Total Fee</th>
                                <th style="width: 120px; text-align: center;">Specializations</th>
                                <th style="width: 110px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="modalCoursesTbody">
                            <!-- Populated dynamically via JS -->
                        </tbody>
                    </table>
                </div>
                <div id="modalNoMatches" style="display: none; text-align: center; padding: 40px 20px; color: var(--text-dim, #94a3b8); font-size: 13px;">
                    No mapped courses found matching your search / filter.
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="uni-courses-modal-footer">
            <div style="font-size: 12px; color: var(--text-dim, #94a3b8);">
                Showing <strong id="modalShownCount" style="color: #fff;">0</strong> of <strong id="modalTotalCount" style="color: #fff;">0</strong> mapped courses
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="uni-modal-footer-close-btn" onclick="closeMappedCoursesModal()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    <span>Close</span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Courses Mapped Clickable Badge */
.sode-courses-badge {
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.sode-courses-badge:hover {
    transform: translateY(-1.5px) scale(1.03);
    box-shadow: 0 4px 14px rgba(245, 158, 11, 0.4);
    filter: brightness(1.12);
}
.sode-courses-badge:active {
    transform: translateY(0) scale(0.99);
}

/* Modal Backdrop & Dialog */
.uni-courses-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(4, 7, 13, 0.82);
    backdrop-filter: blur(8px);
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
    max-width: 960px;
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

/* Header Map Course Button */
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

/* Header Top Close (X) Button */
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
.uni-modal-close-btn svg {
    transition: transform 0.2s ease;
}
.uni-modal-close-btn:hover {
    background: rgba(239, 68, 68, 0.2) !important;
    color: #f87171 !important;
    border-color: rgba(239, 68, 68, 0.4) !important;
    transform: rotate(90deg) scale(1.05) !important;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3) !important;
}

/* Row Action Edit Button */
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
.uni-modal-edit-btn svg {
    flex-shrink: 0;
    transition: transform 0.2s ease;
}
.uni-modal-edit-btn:hover {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%) !important;
    color: #ffffff !important;
    border-color: #818cf8 !important;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.45) !important;
    transform: translateY(-1.5px) !important;
}
.uni-modal-edit-btn:hover svg {
    transform: rotate(-10deg) scale(1.15);
}
.uni-modal-edit-btn:active {
    transform: translateY(0) !important;
}

/* Footer Bottom Close Button */
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
.uni-modal-footer-close-btn svg {
    flex-shrink: 0;
    transition: transform 0.2s ease;
}
.uni-modal-footer-close-btn:hover {
    background: rgba(239, 68, 68, 0.16) !important;
    color: #f87171 !important;
    border-color: rgba(239, 68, 68, 0.4) !important;
    box-shadow: 0 4px 14px rgba(239, 68, 68, 0.25) !important;
    transform: translateY(-1px) !important;
}
.uni-modal-footer-close-btn:hover svg {
    transform: rotate(90deg);
}
.uni-modal-footer-close-btn:active {
    transform: translateY(0) !important;
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
</style>

<script>
(function() {
    const ADMIN_BASE_URL = '<?php echo BASE_URL; ?>';
    let currentUniId = 0;
    let allCoursesData = [];
    let currentFilter = 'ALL';
    let currentSearch = '';

    window.openMappedCoursesModal = function(uniId, uniNameHint) {
        currentUniId = uniId;
        currentFilter = 'ALL';
        currentSearch = '';
        allCoursesData = [];

        const modal = document.getElementById('mappedCoursesModal');
        const modalTitle = document.getElementById('modalUniTitle');
        const modalSub = document.getElementById('modalUniSub');
        const modalLogo = document.getElementById('modalUniLogo');
        const modalLogoFallback = document.getElementById('modalUniLogoFallback');
        const countBadge = document.getElementById('modalCoursesCountBadge');
        const searchInput = document.getElementById('modalSearchInput');
        const clearBtn = document.getElementById('modalClearSearchBtn');

        modalTitle.textContent = (uniNameHint ? uniNameHint + ' - ' : '') + 'Mapped Courses';
        modalSub.textContent = 'Loading mapped courses...';
        modalLogo.style.display = 'none';
        modalLogoFallback.style.display = 'block';
        modalLogoFallback.textContent = (uniNameHint || 'UNI').substring(0, 3).toUpperCase();
        countBadge.textContent = '...';
        searchInput.value = '';
        clearBtn.style.display = 'none';

        // Update action links
        const addBtn = document.getElementById('modalAddNewMappingBtn');
        const emptyBtn = document.getElementById('modalEmptyActionBtn');
        const createUrl = ADMIN_BASE_URL + '/modules/mappings/create.php?university_id=' + uniId;
        if (addBtn) addBtn.href = createUrl;
        if (emptyBtn) emptyBtn.href = createUrl;

        // Reset filter pills
        document.querySelectorAll('#modalFilterPills .filter-pill').forEach(btn => {
            if (btn.getAttribute('data-filter') === 'ALL') {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Show modal and loading state
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        showState('loading');
        fetchMappedCourses(uniId);
    };

    window.closeMappedCoursesModal = function() {
        const modal = document.getElementById('mappedCoursesModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.handleBackdropClick = function(event) {
        if (event.target.id === 'mappedCoursesModal') {
            closeMappedCoursesModal();
        }
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('mappedCoursesModal');
            if (modal && modal.style.display !== 'none') {
                closeMappedCoursesModal();
            }
        }
    });

    window.retryLoadMappedCourses = function() {
        if (currentUniId > 0) {
            showState('loading');
            fetchMappedCourses(currentUniId);
        }
    };

    function showState(state) {
        document.getElementById('modalLoadingState').style.display = state === 'loading' ? 'flex' : 'none';
        document.getElementById('modalErrorState').style.display = state === 'error' ? 'block' : 'none';
        document.getElementById('modalEmptyState').style.display = state === 'empty' ? 'block' : 'none';
        document.getElementById('modalTableWrap').style.display = state === 'table' ? 'block' : 'none';
    }

    function fetchMappedCourses(uniId) {
        const url = ADMIN_BASE_URL + '/api/get_university_mapped_courses.php?uni_id=' + encodeURIComponent(uniId);

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Error fetching mapped courses');
                }

                // Update university info in header
                if (data.university) {
                    const u = data.university;
                    document.getElementById('modalUniTitle').textContent = u.full_name || u.short_name;
                    document.getElementById('modalUniSub').textContent = 
                        (u.short_name ? u.short_name + ' • ' : '') + 
                        (u.mode ? u.mode + ' • ' : '') + 
                        'Slug: ' + u.slug;

                    const logoImg = document.getElementById('modalUniLogo');
                    const logoFallback = document.getElementById('modalUniLogoFallback');
                    if (u.logo_url) {
                        logoImg.src = u.logo_url;
                        logoImg.style.display = 'block';
                        logoFallback.style.display = 'none';
                    } else {
                        logoImg.style.display = 'none';
                        logoFallback.style.display = 'block';
                        logoFallback.textContent = (u.short_name || 'UNI').substring(0, 3).toUpperCase();
                    }
                }

                allCoursesData = Array.isArray(data.courses) ? data.courses : [];
                updateCounts();

                if (allCoursesData.length === 0) {
                    showState('empty');
                } else {
                    showState('table');
                    renderCoursesTable();
                }
            })
            .catch(err => {
                console.error('Error fetching university mapped courses:', err);
                document.getElementById('modalErrorMessage').textContent = 'Failed to load mapped courses: ' + err.message;
                showState('error');
            });
    }

    function updateCounts() {
        const total = allCoursesData.length;
        const onlineCount = allCoursesData.filter(c => (c.mode || '').toLowerCase() === 'online').length;
        const distanceCount = allCoursesData.filter(c => (c.mode || '').toLowerCase() === 'distance').length;
        const pgCount = allCoursesData.filter(c => (c.level || '').toUpperCase() === 'PG').length;
        const ugCount = allCoursesData.filter(c => (c.level || '').toUpperCase() === 'UG').length;

        document.getElementById('modalCoursesCountBadge').textContent = total + ' Courses';
        document.getElementById('countAll').textContent = total;
        document.getElementById('countOnline').textContent = onlineCount;
        document.getElementById('countDistance').textContent = distanceCount;
        document.getElementById('countPG').textContent = pgCount;
        document.getElementById('countUG').textContent = ugCount;
        document.getElementById('modalTotalCount').textContent = total;
    }

    window.setCourseFilter = function(filter, btn) {
        currentFilter = filter;
        document.querySelectorAll('#modalFilterPills .filter-pill').forEach(b => b.classList.remove('active'));
        if (btn) btn.classList.add('active');
        renderCoursesTable();
    };

    window.handleModalSearchInput = function() {
        const input = document.getElementById('modalSearchInput');
        const clearBtn = document.getElementById('modalClearSearchBtn');
        currentSearch = (input.value || '').trim().toLowerCase();
        clearBtn.style.display = currentSearch ? 'inline-block' : 'none';
        renderCoursesTable();
    };

    window.clearModalSearch = function() {
        const input = document.getElementById('modalSearchInput');
        input.value = '';
        currentSearch = '';
        document.getElementById('modalClearSearchBtn').style.display = 'none';
        renderCoursesTable();
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatFee(val) {
        if (!val || val === '0' || val === 0) {
            return '<span style="color:var(--text-dim, #64748b); font-size:12px;">—</span>';
        }
        let clean = String(val).replace(/[^0-9.]/g, '');
        let num = parseFloat(clean);
        if (isNaN(num) || num <= 0) {
            return '<span style="color:var(--text-dim, #64748b); font-size:12px;">—</span>';
        }
        return '<span style="color:#10b981; font-weight:600; font-size:12.5px;">₹' + Number(num).toLocaleString('en-IN') + '</span>';
    }

    function renderCoursesTable() {
        const tbody = document.getElementById('modalCoursesTbody');
        const noMatches = document.getElementById('modalNoMatches');
        const shownCount = document.getElementById('modalShownCount');

        let filtered = allCoursesData.filter(item => {
            // Filter by Mode / Level
            if (currentFilter === 'Online' && (item.mode || '').toLowerCase() !== 'online') return false;
            if (currentFilter === 'Distance' && (item.mode || '').toLowerCase() !== 'distance') return false;
            if (currentFilter === 'PG' && (item.level || '').toUpperCase() !== 'PG') return false;
            if (currentFilter === 'UG' && (item.level || '').toUpperCase() !== 'UG') return false;

            // Search query
            if (currentSearch) {
                const haystack = (
                    (item.course_name || '') + ' ' + 
                    (item.course_short || '') + ' ' + 
                    (item.course_slug || '') + ' ' + 
                    (item.mode || '') + ' ' + 
                    (item.level || '')
                ).toLowerCase();
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
        filtered.forEach((m, idx) => {
            const isOnline = (m.mode || '').toLowerCase() === 'online';
            const modeBadge = isOnline 
                ? '<span class="badge badge-info" style="font-size:10.5px; padding:2px 7px;">Online</span>'
                : '<span class="badge" style="background:rgba(245,158,11,0.15); color:#f59e0b; border:1px solid rgba(245,158,11,0.3); font-size:10.5px; padding:2px 7px;">Distance</span>';

            const isPG = (m.level || '').toUpperCase() === 'PG';
            const levelBadge = isPG
                ? '<span class="badge" style="background:rgba(168,85,247,0.15); color:#c084fc; border:1px solid rgba(168,85,247,0.3); font-size:10.5px; padding:2px 7px;">PG</span>'
                : '<span class="badge" style="background:rgba(59,130,246,0.15); color:#60a5fa; border:1px solid rgba(59,130,246,0.3); font-size:10.5px; padding:2px 7px;">UG</span>';

            const specsBadge = m.specs_count > 0
                ? `<span class="badge" style="background:rgba(16,185,129,0.15); color:#34d399; border:1px solid rgba(16,185,129,0.3); font-size:10.5px; padding:2px 7px;" title="${m.specs_count} Specializations">${m.specs_count} Specs</span>`
                : `<span style="color:var(--text-dim, #64748b); font-size:11px;">—</span>`;

            const editUrl = ADMIN_BASE_URL + '/modules/mappings/edit.php?id=' + m.mapping_id;

            html += `
                <tr>
                    <td style="text-align:center; color:var(--text-dim, #64748b); font-weight:600; font-size:11.5px;">${idx + 1}</td>
                    <td>
                        <div style="font-weight:600; color:#fff; display:flex; align-items:center; gap:7px;">
                            <span>${escapeHtml(m.course_name)}</span>
                            <span class="badge" style="background:rgba(255,255,255,0.08); color:#cbd5e1; font-size:10.5px; padding:1px 6px;">${escapeHtml(m.course_short)}</span>
                        </div>
                        <div style="font-size:11px; color:var(--text-dim, #64748b); margin-top:2px; font-family:monospace;">
                            ${escapeHtml(m.course_slug || '')}
                        </div>
                    </td>
                    <td style="text-align:center;">${modeBadge}</td>
                    <td style="text-align:center;">${levelBadge}</td>
                    <td>${formatFee(m.per_semester_fee)}</td>
                    <td>${formatFee(m.total_program_fee)}</td>
                    <td style="text-align:center;">${specsBadge}</td>
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

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>

