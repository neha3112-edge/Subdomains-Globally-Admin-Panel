<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('mappings');

$page_title = 'Map Course to University';
$page_subtitle = 'Set fees structure and specializations';
$active_page_key = 'mappings';

$db = get_db_connection();

$universities = $db->query("SELECT id, full_name, short_name FROM universities ORDER BY short_name ASC")->fetchAll();
$courses = $db->query("SELECT id, full_name, short_name, level FROM courses ORDER BY full_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $university_id = (int)($_POST['university_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $mode = in_array($_POST['mode'] ?? '', ['Online', 'Distance']) ? $_POST['mode'] : 'Online';
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
                INSERT INTO university_course_mappings (
                    university_id, course_id, mode, course_description, course_link, eligibility_text,
                    one_time_processing_fee, tuition_fee, examination_fee, per_semester_fee, total_program_fee
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $university_id, $course_id, $mode, $course_description, $course_link, $eligibility_text,
                $one_time_processing_fee, $tuition_fee, $examination_fee, $per_semester_fee, $total_program_fee
            ]);
            $mapping_id = $db->lastInsertId();

            // Insert Specializations
            if (!empty($spec_names)) {
                $spec_stmt = $db->prepare("INSERT INTO course_specializations (mapping_id, specialization_name, fees_per_sem, duration) VALUES (?, ?, ?, ?)");
                foreach ($spec_names as $i => $sname) {
                    $sname = trim($sname);
                    if (!empty($sname)) {
                        $sfee = trim($spec_fees[$i] ?? '');
                        $sdur = trim($spec_durations[$i] ?? '');
                        $spec_stmt->execute([$mapping_id, $sname, $sfee, $sdur]);
                    }
                }
            }

            set_flash_message('Course mapped successfully!', 'success');
            redirect(BASE_URL . '/modules/mappings/index.php');
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                set_flash_message('This course is already mapped to the selected university.', 'error');
            } else {
                set_flash_message('Database Error: ' . $e->getMessage(), 'error');
            }
        }
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <span class="section-heading-sm" style="margin-bottom:0;">New University-Course Mapping</span>
    <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="btn-sm action-btn" style="width:auto; padding:6px 14px; text-decoration:none;">&larr; Back to Mappings</a>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- Select Uni and Course -->
            <div class="admin-card">
                <div class="card-header">
                    <span class="card-title">1. University & Course Selection</span>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 1.2fr 1.2fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">University *</label>
                            <select name="university_id" class="form-select" required>
                                <option value="">-- Select University --</option>
                                <?php foreach ($universities as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['short_name'] ?: $u['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Course *</label>
                            <select name="course_id" class="form-select" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['full_name'] . ' (' . $c['short_name'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Course Mode *</label>
                            <select name="mode" class="form-select" required>
                                <option value="Online" selected>Online</option>
                                <option value="Distance">Distance</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">Course Official Page Link</label>
                            <input type="url" name="course_link" class="form-control" placeholder="https://...">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Eligibility Criteria</label>
                            <input type="text" name="eligibility_text" class="form-control" placeholder="e.g. 10+2 / Graduation with min 50% marks">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Course Overview / Description</label>
                        <textarea name="course_description" class="form-textarea" placeholder="Specific notes or overview for this course at this university..."></textarea>
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
                            <input type="text" name="per_semester_fee" class="form-control" placeholder="e.g. ₹25,000">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Program Fee</label>
                            <input type="text" name="total_program_fee" class="form-control" placeholder="e.g. ₹1,00,000">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tuition Fee</label>
                            <input type="text" name="tuition_fee" class="form-control" placeholder="e.g. ₹90,000">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                        <div class="form-group">
                            <label class="form-label">One-Time / Registration Fee</label>
                            <input type="text" name="one_time_processing_fee" class="form-control" placeholder="e.g. ₹2,500">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Examination Fee</label>
                            <input type="text" name="examination_fee" class="form-control" placeholder="e.g. ₹3,000 / sem">
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
                        <div class="spec-row" style="display:grid; grid-template-columns: 2fr 1fr 1fr 40px; gap:12px; align-items:center;">
                            <input type="text" name="spec_name[]" class="form-control" placeholder="Specialization (e.g. Data Analytics)">
                            <input type="text" name="spec_fee[]" class="form-control" placeholder="Fee (e.g. ₹25,000/sem)">
                            <input type="text" name="spec_duration[]" class="form-control" placeholder="Duration (e.g. 2 Years)">
                            <button type="button" class="action-btn delete-btn remove-spec-btn" title="Remove">&times;</button>
                        </div>
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
                        Save Mapping
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
