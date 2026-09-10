<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('mappings');

$page_title = 'Edit Course Mapping';
$page_subtitle = 'Update fees structure and specializations';
$active_page_key = 'mappings';

$db = get_db_connection();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    redirect(BASE_URL . '/modules/mappings/index.php');
}

$stmt = $db->prepare("
    SELECT ucm.*, u.short_name AS uni_short, c.full_name AS course_name, c.short_name AS course_short
    FROM university_course_mappings ucm
    INNER JOIN universities u ON ucm.university_id = u.id
    INNER JOIN courses c ON ucm.course_id = c.id
    WHERE ucm.id = ?
");
$stmt->execute([$id]);
$mapping = $stmt->fetch();

if (!$mapping) {
    set_flash_message('Mapping not found.', 'error');
    redirect(BASE_URL . '/modules/mappings/index.php');
}

// Fetch Universities & Courses
$universities = $db->query("SELECT id, full_name, short_name FROM universities ORDER BY short_name ASC")->fetchAll();
$courses = $db->query("SELECT id, full_name, short_name, level FROM courses ORDER BY full_name ASC")->fetchAll();

// Fetch Specializations
$stmt = $db->prepare("SELECT * FROM course_specializations WHERE mapping_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$specializations = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $university_id = (int)($_POST['university_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $course_description = trim($_POST['course_description'] ?? '');
    $course_link = trim($_POST['course_link'] ?? '');
    $eligibility_text = trim($_POST['eligibility_text'] ?? '');
    $one_time_processing_fee = trim($_POST['one_time_processing_fee'] ?? '');
    $tuition_fee = trim($_POST['tuition_fee'] ?? '');
    $examination_fee = trim($_POST['examination_fee'] ?? '');
    $per_semester_fee = trim($_POST['per_semester_fee'] ?? '');
    $total_program_fee = trim($_POST['total_program_fee'] ?? '');

    $spec_names = $_POST['spec_name'] ?? [];
    $spec_fees = $_POST['spec_fee'] ?? [];
    $spec_durations = $_POST['spec_duration'] ?? [];

    if (!$university_id || !$course_id) {
        set_flash_message('Please select both University and Course.', 'error');
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE university_course_mappings SET
                    university_id = ?, course_id = ?, course_description = ?, course_link = ?, eligibility_text = ?,
                    one_time_processing_fee = ?, tuition_fee = ?, examination_fee = ?, per_semester_fee = ?, total_program_fee = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $university_id, $course_id, $course_description, $course_link, $eligibility_text,
                $one_time_processing_fee, $tuition_fee, $examination_fee, $per_semester_fee, $total_program_fee,
                $id
            ]);

            // Re-sync Specializations
            $db->prepare("DELETE FROM course_specializations WHERE mapping_id = ?")->execute([$id]);
            if (!empty($spec_names)) {
                $spec_stmt = $db->prepare("INSERT INTO course_specializations (mapping_id, specialization_name, fees_per_sem, duration) VALUES (?, ?, ?, ?)");
                foreach ($spec_names as $i => $sname) {
                    $sname = trim($sname);
                    if (!empty($sname)) {
                        $sfee = trim($spec_fees[$i] ?? '');
                        $sdur = trim($spec_durations[$i] ?? '');
                        $spec_stmt->execute([$id, $sname, $sfee, $sdur]);
                    }
                }
            }

            set_flash_message('Mapping updated successfully!', 'success');
            redirect(BASE_URL . '/modules/mappings/edit.php?id=' . $id);
        } catch (PDOException $e) {
            set_flash_message('Database Error: ' . $e->getMessage(), 'error');
        }
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <span class="section-heading-sm" style="margin-bottom:0;">Edit Mapping: <?php echo htmlspecialchars($mapping['uni_short'] . ' - ' . $mapping['course_short']); ?></span>
    <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="btn-sm action-btn" style="width:auto; padding:6px 14px; text-decoration:none;">&larr; Back to Mappings</a>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">1. University & Course Selection</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">University *</label>
                            <select name="university_id" class="form-select" required>
                                <?php foreach ($universities as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo ($mapping['university_id'] == $u['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u['short_name'] ?: $u['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Course *</label>
                            <select name="course_id" class="form-select" required>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($mapping['course_id'] == $c['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['full_name'] . ' (' . $c['short_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Course Official Page Link</label>
                            <input type="url" name="course_link" class="form-control" value="<?php echo htmlspecialchars($mapping['course_link'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Eligibility Criteria</label>
                            <input type="text" name="eligibility_text" class="form-control" value="<?php echo htmlspecialchars($mapping['eligibility_text'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Course Overview / Description</label>
                        <textarea name="course_description" class="form-textarea"><?php echo htmlspecialchars($mapping['course_description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Fee Breakdown Structure -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">2. Fee Structure</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Per Semester Fee</label>
                            <input type="text" name="per_semester_fee" class="form-control" value="<?php echo htmlspecialchars($mapping['per_semester_fee'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Program Fee</label>
                            <input type="text" name="total_program_fee" class="form-control" value="<?php echo htmlspecialchars($mapping['total_program_fee'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tuition Fee</label>
                            <input type="text" name="tuition_fee" class="form-control" value="<?php echo htmlspecialchars($mapping['tuition_fee'] ?? ''); ?>">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">One-Time / Registration Fee</label>
                            <input type="text" name="one_time_processing_fee" class="form-control" value="<?php echo htmlspecialchars($mapping['one_time_processing_fee'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Examination Fee</label>
                            <input type="text" name="examination_fee" class="form-control" value="<?php echo htmlspecialchars($mapping['examination_fee'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Specializations Repeater -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">3. Course Specializations</span>
                    <button type="button" class="btn-primary btn-sm" id="add-spec-btn" style="width:auto; padding:6px 12px;">+ Add Specialization</button>
                </div>
                <div class="card-body">
                    <div id="specs-container" style="display:flex; flex-direction:column; gap:12px;">
                        <?php if (empty($specializations)): ?>
                            <div class="spec-row" style="display:grid; grid-template-columns: 2fr 1fr 1fr 40px; gap:12px; align-items:center;">
                                <input type="text" name="spec_name[]" class="form-control" placeholder="Specialization (e.g. Cyber Security)">
                                <input type="text" name="spec_fee[]" class="form-control" placeholder="Fee">
                                <input type="text" name="spec_duration[]" class="form-control" placeholder="Duration">
                                <button type="button" class="action-btn delete-btn remove-spec-btn" title="Remove">&times;</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($specializations as $s): ?>
                                <div class="spec-row" style="display:grid; grid-template-columns: 2fr 1fr 1fr 40px; gap:12px; align-items:center;">
                                    <input type="text" name="spec_name[]" class="form-control" value="<?php echo htmlspecialchars($s['specialization_name']); ?>">
                                    <input type="text" name="spec_fee[]" class="form-control" value="<?php echo htmlspecialchars($s['fees_per_sem'] ?? ''); ?>">
                                    <input type="text" name="spec_duration[]" class="form-control" value="<?php echo htmlspecialchars($s['duration'] ?? ''); ?>">
                                    <button type="button" class="action-btn delete-btn remove-spec-btn" title="Remove">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">Actions</span>
                </div>
                <div class="card-body">
                    <button type="submit" class="btn-primary" style="padding:13px 20px;">
                        Update Mapping
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.getElementById('add-spec-btn').addEventListener('click', function() {
    const container = document.getElementById('specs-container');
    const div = document.createElement('div');
    div.className = 'spec-row';
    div.style.display = 'grid';
    div.style.gridTemplateColumns = '2fr 1fr 1fr 40px';
    div.style.gap = '12px';
    div.style.alignItems = 'center';
    div.innerHTML = `
        <input type="text" name="spec_name[]" class="form-control" placeholder="Specialization Name">
        <input type="text" name="spec_fee[]" class="form-control" placeholder="Fee">
        <input type="text" name="spec_duration[]" class="form-control" placeholder="Duration">
        <button type="button" class="action-btn delete-btn remove-spec-btn" title="Remove">&times;</button>
    `;
    container.appendChild(div);
});

document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('remove-spec-btn')) {
        const row = e.target.closest('.spec-row');
        if (row) row.remove();
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
