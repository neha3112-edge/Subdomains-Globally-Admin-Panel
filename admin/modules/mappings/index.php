<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('mappings');

$page_title = 'Course Mappings';
$page_subtitle = 'Map universities to courses and configure fees structures';
$active_page_key = 'mappings';

$db = get_db_connection();

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("DELETE FROM university_course_mappings WHERE id = ?");
        $stmt->execute([$id]);
        set_flash_message('Course mapping deleted successfully!', 'success');
        redirect(BASE_URL . '/modules/mappings/index.php');
    }
}

// Fetch all mappings
$mappings = $db->query("
    SELECT ucm.*, 
           u.full_name AS uni_name, u.short_name AS uni_short, u.logo_url, u.mode,
           c.full_name AS course_name, c.short_name AS course_short, c.level,
           (SELECT COUNT(*) FROM course_specializations WHERE mapping_id = ucm.id) AS specs_count
    FROM university_course_mappings ucm
    INNER JOIN universities u ON ucm.university_id = u.id
    INNER JOIN courses c ON ucm.course_id = c.id
    ORDER BY ucm.id DESC
")->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">All University-Course Mappings (<?php echo count($mappings); ?>)</span>
    </div>
    <a href="<?php echo BASE_URL; ?>/modules/mappings/create.php" class="btn-primary btn-sm" style="padding:10px 18px; font-size:13.5px; text-decoration:none;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Map New Course
    </a>
</div>

<div class="admin-card">
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>University</th>
                    <th>Course</th>
                    <th>Level</th>
                    <th>Per Sem Fee</th>
                    <th>Total Fee</th>
                    <th>Specializations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mappings)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:40px; color:var(--text-dim);">
                            No mappings configured. Click "Map New Course" to link a university to a course.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mappings as $m): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($m['uni_short']); ?></strong>
                                <div style="font-size:11.5px; color:var(--text-dim);"><?php echo htmlspecialchars($m['mode']); ?></div>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($m['course_name']); ?></strong>
                                <div style="font-size:11.5px; color:var(--text-muted); font-weight:600;"><?php echo htmlspecialchars($m['course_short']); ?></div>
                            </td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars($m['level']); ?></span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($m['per_semester_fee'] ?? '₹0'); ?></strong>
                            </td>
                            <td>
                                <strong style="color:var(--primary);"><?php echo htmlspecialchars($m['total_program_fee'] ?? '₹0'); ?></strong>
                            </td>
                            <td>
                                <span class="badge badge-warning"><?php echo $m['specs_count']; ?> Specializations</span>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?php echo BASE_URL; ?>/modules/mappings/edit.php?id=<?php echo $m['id']; ?>" class="action-btn" title="Edit Mapping">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>

                                    <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                        <button type="submit" class="action-btn delete-btn" title="Delete Mapping">
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

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
