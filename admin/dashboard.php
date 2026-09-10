<?php
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Dashboard';
$page_subtitle = 'Welcome back, ' . htmlspecialchars($current_user['name'] ?? 'Admin');
$active_page_key = 'dashboard';

$db = get_db_connection();

// 1. Fetch Stats Counters
$total_unis = (int)$db->query("SELECT COUNT(*) FROM universities")->fetchColumn();
$total_courses = (int)$db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
$total_mappings = (int)$db->query("SELECT COUNT(*) FROM university_course_mappings")->fetchColumn();
$total_users = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

// 2. Fetch Recent Universities
$recent_unis = $db->query("
    SELECT id, full_name, short_name, rating, mode, created_at, logo_url 
    FROM universities 
    ORDER BY id DESC LIMIT 5
")->fetchAll();

// 3. Fetch Recent Courses
$recent_courses = $db->query("
    SELECT id, full_name, short_name, level, created_at 
    FROM courses 
    ORDER BY id DESC LIMIT 5
")->fetchAll();

// 4. Fetch Recent Mappings
$recent_mappings = $db->query("
    SELECT ucm.id, u.short_name AS uni_name, u.logo_url, c.short_name AS course_name, u.mode, ucm.per_semester_fee, ucm.total_program_fee, ucm.created_at
    FROM university_course_mappings ucm
    INNER JOIN universities u ON ucm.university_id = u.id
    INNER JOIN courses c ON ucm.course_id = c.id
    ORDER BY ucm.id DESC LIMIT 5
")->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- 1. Stats Counter Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div>
            <div class="stat-label">Total Universities</div>
            <div class="stat-number"><?php echo $total_unis; ?></div>
            <div class="stat-sub">Active records</div>
        </div>
        <div class="stat-icon-wrap" style="color:#6366f1; background:rgba(99,102,241,0.12);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/>
            </svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Total Courses</div>
            <div class="stat-number"><?php echo $total_courses; ?></div>
            <div class="stat-sub">Active records</div>
        </div>
        <div class="stat-icon-wrap" style="color:#a855f7; background:rgba(168,85,247,0.12);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
            </svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Course Mappings</div>
            <div class="stat-number"><?php echo $total_mappings; ?></div>
            <div class="stat-sub">University-course links</div>
        </div>
        <div class="stat-icon-wrap" style="color:#10b981; background:rgba(16,185,129,0.12);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
            </svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Admin Users</div>
            <div class="stat-number"><?php echo $total_users; ?></div>
            <div class="stat-sub">Active team members</div>
        </div>
        <div class="stat-icon-wrap" style="color:#f59e0b; background:rgba(245,158,11,0.12);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
        </div>
    </div>
</div>

<!-- 2. Quick Actions -->
<div class="section-heading-sm">Quick Actions</div>
<div class="quick-actions-grid">
    <a href="<?php echo BASE_URL; ?>/modules/universities/create.php" class="action-card">
        <div class="action-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
        </div>
        <div>
            <div class="action-title">Add University</div>
            <div class="action-desc">Create university record</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="action-card">
        <div class="action-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        </div>
        <div>
            <div class="action-title">Add Course</div>
            <div class="action-desc">Create course profile</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/mappings/create.php" class="action-card">
        <div class="action-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/></svg>
        </div>
        <div>
            <div class="action-title">Map Course</div>
            <div class="action-desc">Link course to university</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="action-card">
        <div class="action-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div>
            <div class="action-title">All Universities</div>
            <div class="action-desc">Manage existing profiles</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="action-card">
        <div class="action-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        </div>
        <div>
            <div class="action-title">All Courses</div>
            <div class="action-desc">Manage configurations</div>
        </div>
    </a>
</div>

<!-- 3. Recent Tables Section (3 Columns) -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:24px;">
    
    <!-- Recent Universities -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Recent Universities</span>
            <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="badge badge-info" style="text-decoration:none;">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>University</th>
                        <th>Rating</th>
                        <th>Added</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_unis)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-dim);">No universities added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_unis as $u): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($u['short_name']); ?></strong>
                                </td>
                                <td>
                                    <span style="color:#f59e0b; font-weight:700;">★ <?php echo htmlspecialchars($u['rating'] ?? '4.0'); ?></span>
                                </td>
                                <td style="color:var(--text-dim); font-size:12px;">
                                    <?php echo date('d M', strtotime($u['created_at'])); ?>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $u['id']; ?>" class="action-btn" title="Edit">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Courses -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Recent Courses</span>
            <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="badge badge-info" style="text-decoration:none;">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Level</th>
                        <th>Added</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_courses)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-dim);">No courses added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_courses as $c): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($c['full_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($c['level'] ?? 'PG'); ?></span>
                                </td>
                                <td style="color:var(--text-dim); font-size:12px;">
                                    <?php echo date('d M', strtotime($c['created_at'])); ?>
                                </td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php?edit_id=<?php echo $c['id']; ?>" class="action-btn" title="Edit">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Mappings -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Recent Mappings</span>
            <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="badge badge-info" style="text-decoration:none;">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>University</th>
                        <th>Course</th>
                        <th>Fees</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_mappings)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-dim);">No course mappings yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_mappings as $m): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($m['uni_name']); ?></strong></td>
                                <td><span class="badge badge-warning"><?php echo htmlspecialchars($m['course_name']); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($m['per_semester_fee'] ?? '₹0'); ?></strong></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/modules/mappings/edit.php?id=<?php echo $m['id']; ?>" class="action-btn" title="Edit">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
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
