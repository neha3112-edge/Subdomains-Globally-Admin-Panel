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
    $mode = in_array($_POST['mode'] ?? '', ['Online', 'Distance']) ? $_POST['mode'] : 'Online';
    $course_description = trim($_POST['course_description'] ?? '');
    $course_link = trim($_POST['course_link'] ?? '');
    $eligibility_text = trim($_POST['eligibility_text'] ?? '');
    $one_time_processing_fee = trim($_POST['one_time_processing_fee'] ?? '');
    $tuition_fee = trim($_POST['tuition_fee'] ?? '');
    $examination_fee = trim($_POST['examination_fee'] ?? '');
    $per_semester_fee = trim($_POST['per_semester_fee'] ?? '');
    $total_program_fee = trim($_POST['total_program_fee'] ?? '');

    $raw_syllabus = trim($_POST['syllabus_json'] ?? '');
    $syllabus_json = '[]';
    if (!empty($raw_syllabus)) {
        $decoded = json_decode($raw_syllabus, true);
        if (is_array($decoded)) {
            $syllabus_json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    $spec_names = $_POST['spec_name'] ?? [];
    $spec_fees = $_POST['spec_fee'] ?? [];
    $spec_durations = $_POST['spec_duration'] ?? [];

    if (!$university_id || !$course_id) {
        set_flash_message('Please select both University and Course.', 'error');
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE university_course_mappings SET
                    university_id = ?, course_id = ?, mode = ?, course_description = ?, course_link = ?, eligibility_text = ?,
                    one_time_processing_fee = ?, tuition_fee = ?, examination_fee = ?, per_semester_fee = ?, total_program_fee = ?,
                    syllabus_json = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $university_id, $course_id, $mode, $course_description, $course_link, $eligibility_text,
                $one_time_processing_fee, $tuition_fee, $examination_fee, $per_semester_fee, $total_program_fee,
                $syllabus_json,
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
                    <div style="display:grid; grid-template-columns: 1.2fr 1.2fr 1fr; gap:16px;">
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

                        <div class="form-group">
                            <label class="form-label">Course Mode *</label>
                            <select name="mode" class="form-select" required>
                                <option value="Online" <?php echo (($mapping['mode'] ?? 'Online') === 'Online') ? 'selected' : ''; ?>>Online</option>
                                <option value="Distance" <?php echo (($mapping['mode'] ?? '') === 'Distance') ? 'selected' : ''; ?>>Distance</option>
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

            <!-- 4. Course Syllabus Structure (Semester Boxes) -->
            <?php
            $initial_syllabus = [];
            if (!empty($mapping['syllabus_json'])) {
                $dec = json_decode($mapping['syllabus_json'], true);
                if (is_array($dec)) $initial_syllabus = $dec;
            }
            ?>
            <div class="admin-card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div>
                        <span class="card-title" style="font-size:16px;">4. Course Syllabus (Semester-wise Structure)</span>
                        <p style="font-size:12px; color:var(--text-dim); margin:3px 0 0;">Add semester boxes (4 for Masters, 6 for Bachelors). Inside each box, add subjects with optional links.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn-sm" id="preset-4sem-btn" style="background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700;">+ 4 Semesters (Master)</button>
                        <button type="button" class="btn-sm" id="preset-6sem-btn" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700;">+ 6 Semesters (Bachelor)</button>
                        <button type="button" class="btn-primary btn-sm" id="add-sem-box-btn" style="width:auto; padding:6px 14px; font-size:12px; font-weight:700;">+ Add Semester Box</button>
                    </div>
                </div>
                <div class="card-body">
                    <input type="hidden" name="syllabus_json" id="syllabus_json_input" value="">
                    
                    <div id="semesters-container" style="display:flex; flex-direction:column; gap:20px;">
                        <!-- Dynamically populated by JS -->
                    </div>
                </div>
            </div>

        </div>

        <div style="display:flex; flex-direction:column; gap:24px;">
            <div class="admin-card" style="position:sticky; top:20px;">
                <div class="card-header">
                    <span class="card-title">Actions</span>
                </div>
                <div class="card-body">
                    <button type="submit" id="save-mapping-btn" class="btn-primary" style="padding:13px 20px; font-weight:700; width:100%;">
                        Update Mapping & Syllabus
                    </button>
                    <p style="font-size:12px; color:var(--text-dim); margin:12px 0 0; text-align:center;">
                        Changes will reflect globally on all client subdomains.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// --- Specializations Repeater ---
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

// --- Semester & Subject Builder ---
(function() {
    var semContainer = document.getElementById('semesters-container');
    var syllabusInput = document.getElementById('syllabus_json_input');
    var initialData = <?php echo json_encode($initial_syllabus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    var romanNumerals = ['FIRST', 'SECOND', 'THIRD', 'FOURTH', 'FIFTH', 'SIXTH', 'SEVENTH', 'EIGHTH', 'NINTH', 'TENTH'];

    function createSemesterBox(title, subjects) {
        title = title || '';
        subjects = subjects || [];

        var box = document.createElement('div');
        box.className = 'semester-box';
        box.style.background = '#f8fafc';
        box.style.border = '1px solid #cbd5e1';
        box.style.borderRadius = '10px';
        box.style.padding = '18px';
        box.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';

        var semCount = semContainer.querySelectorAll('.semester-box').length + 1;
        var defaultTitle = (romanNumerals[semCount - 1] || ('SEMESTER ' + semCount)) + ' SEMESTER';
        if (!title) title = defaultTitle;

        box.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:10px; flex:1; max-width:480px;">
                    <span class="badge" style="background:#1e3a8a; color:#fff; font-weight:700; font-size:11px; padding:6px 10px; border-radius:6px; letter-spacing:0.5px;">BOX</span>
                    <input type="text" class="form-control semester-title-input" value="${escapeHtml(title)}" placeholder="e.g. FIRST SEMESTER" style="font-weight:700; font-size:13.5px; text-transform:uppercase; letter-spacing:0.5px;">
                </div>
                <button type="button" class="action-btn delete-btn remove-sem-box-btn" style="width:auto; padding:5px 12px; font-size:12px; display:inline-flex; align-items:center; gap:4px; font-weight:600;" title="Delete this semester box">
                    &times; Delete Semester
                </button>
            </div>

            <div style="margin-bottom:10px;">
                <div style="display:grid; grid-template-columns: 2fr 2fr 40px; gap:10px; font-size:11.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:6px; padding:0 4px;">
                    <span>Subject Name *</span>
                    <span>Link URL (Optional)</span>
                    <span></span>
                </div>
                <div class="subjects-list" style="display:flex; flex-direction:column; gap:8px;">
                </div>
            </div>

            <div style="margin-top:12px; display:flex; justify-content:flex-start;">
                <button type="button" class="btn-sm add-subject-btn" style="background:#fff; border:1px dashed #94a3b8; color:#1e293b; padding:7px 16px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                    <span style="font-size:14px; font-weight:bold;">+</span> Add Subject
                </button>
            </div>
        `;

        var subjectsList = box.querySelector('.subjects-list');
        if (subjects && subjects.length > 0) {
            subjects.forEach(function(sub) {
                subjectsList.appendChild(createSubjectRow(sub.name || '', sub.link || ''));
            });
        } else {
            // Default 1 empty row
            subjectsList.appendChild(createSubjectRow('', ''));
        }

        // Add subject button event
        box.querySelector('.add-subject-btn').addEventListener('click', function() {
            subjectsList.appendChild(createSubjectRow('', ''));
        });

        // Remove semester button event
        box.querySelector('.remove-sem-box-btn').addEventListener('click', function() {
            if (confirm('Delete this semester box and its subjects?')) {
                box.remove();
            }
        });

        return box;
    }

    function createSubjectRow(name, link) {
        var row = document.createElement('div');
        row.className = 'subject-row';
        row.style.display = 'grid';
        row.style.gridTemplateColumns = '2fr 2fr 40px';
        row.style.gap = '10px';
        row.style.alignItems = 'center';

        row.innerHTML = `
            <input type="text" class="form-control subject-name-input" placeholder="e.g. Accounting for Managers" value="${escapeHtml(name)}">
            <input type="url" class="form-control subject-link-input" placeholder="https://... (optional)" value="${escapeHtml(link)}">
            <button type="button" class="action-btn delete-btn remove-subject-btn" title="Remove Subject" style="width:34px; height:34px; font-size:16px;">&times;</button>
        `;

        row.querySelector('.remove-subject-btn').addEventListener('click', function() {
            row.remove();
        });

        return row;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Initialize from DB
    if (initialData && initialData.length > 0) {
        initialData.forEach(function(sem) {
            semContainer.appendChild(createSemesterBox(sem.semester_title, sem.subjects));
        });
    }

    // Add Semester Box Button
    document.getElementById('add-sem-box-btn').addEventListener('click', function() {
        semContainer.appendChild(createSemesterBox('', []));
    });

    // Preset 4 Semesters
    document.getElementById('preset-4sem-btn').addEventListener('click', function() {
        if (semContainer.children.length > 0) {
            if (!confirm('This will append 4 semester boxes. Continue?')) return;
        }
        var count = semContainer.children.length;
        for (var i = 1; i <= 4; i++) {
            var num = count + i;
            var t = (romanNumerals[num - 1] || ('SEMESTER ' + num)) + ' SEMESTER';
            semContainer.appendChild(createSemesterBox(t, []));
        }
    });

    // Preset 6 Semesters
    document.getElementById('preset-6sem-btn').addEventListener('click', function() {
        if (semContainer.children.length > 0) {
            if (!confirm('This will append 6 semester boxes. Continue?')) return;
        }
        var count = semContainer.children.length;
        for (var i = 1; i <= 6; i++) {
            var num = count + i;
            var t = (romanNumerals[num - 1] || ('SEMESTER ' + num)) + ' SEMESTER';
            semContainer.appendChild(createSemesterBox(t, []));
        }
    });

    // Serialize on form submit
    document.querySelector('form').addEventListener('submit', function(e) {
        var syllabusData = [];
        var boxes = semContainer.querySelectorAll('.semester-box');
        boxes.forEach(function(box, bIdx) {
            var titleInput = box.querySelector('.semester-title-input');
            var semTitle = titleInput ? titleInput.value.trim() : '';
            if (!semTitle) {
                semTitle = (romanNumerals[bIdx] || ('SEMESTER ' + (bIdx + 1))) + ' SEMESTER';
            }

            var subjects = [];
            box.querySelectorAll('.subject-row').forEach(function(sRow) {
                var nameInput = sRow.querySelector('.subject-name-input');
                var linkInput = sRow.querySelector('.subject-link-input');
                var sName = nameInput ? nameInput.value.trim() : '';
                var sLink = linkInput ? linkInput.value.trim() : '';
                if (sName) {
                    subjects.push({
                        name: sName,
                        link: sLink
                    });
                }
            });

            syllabusData.push({
                semester_title: semTitle,
                subjects: subjects
            });
        });

        syllabusInput.value = JSON.stringify(syllabusData);
    });
})();
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
