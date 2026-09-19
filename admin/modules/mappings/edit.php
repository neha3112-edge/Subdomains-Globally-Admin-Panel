<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('mappings');
require_action_permission('update', 'mappings');

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

// Fetch All Global Master Specializations
$m_spec_stmt = $db->query("
    SELECT id, specialization_name 
    FROM course_specializations_master 
    WHERE is_active = 1 
    ORDER BY specialization_name ASC
");
$master_specializations = $m_spec_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Global Master Syllabus Subjects
$m_sub_stmt = $db->query("
    SELECT id, subject_name 
    FROM course_syllabus_subjects_master 
    WHERE is_active = 1 
    ORDER BY subject_name ASC
");
$master_subjects = $m_sub_stmt->fetchAll(PDO::FETCH_ASSOC);
$master_subject_names = array_values(array_filter(array_unique(array_column($master_subjects, 'subject_name'))));

// Fetch All Global Master Durations
$m_dur_stmt = $db->query("
    SELECT id, duration_title 
    FROM course_durations_master 
    WHERE is_active = 1 
    ORDER BY sort_order ASC, id ASC
");
$master_durations = $m_dur_stmt ? $m_dur_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$master_duration_titles = array_values(array_filter(array_unique(array_column($master_durations, 'duration_title'))));

// Fetch All Global Master Education Modes
$m_modes_stmt = $db->query("
    SELECT id, mode_name 
    FROM education_modes_master 
    WHERE is_active = 1 
    ORDER BY sort_order ASC, id ASC
");
$master_modes = $m_modes_stmt ? $m_modes_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$master_mode_names = array_values(array_filter(array_unique(array_column($master_modes, 'mode_name'))));
if (empty($master_mode_names)) {
    $master_mode_names = ['Online', 'Distance', 'Online & Distance'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $university_id = (int)($_POST['university_id'] ?? 0);
    $course_id = (int)($_POST['course_id'] ?? 0);
    $mode = trim($_POST['mode'] ?? '');
    if (empty($mode)) {
        $mode = $master_mode_names[0] ?? 'Online';
    }
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
    $spec_name_selects = $_POST['spec_name_select'] ?? [];
    $spec_links = $_POST['spec_link'] ?? [];
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
            if (!empty($spec_name_selects) || !empty($spec_names)) {
                $count_specs = max(count($spec_name_selects), count($spec_names));
                $spec_stmt = $db->prepare("INSERT INTO course_specializations (mapping_id, specialization_name, specialization_link, fees_per_sem, duration) VALUES (?, ?, ?, ?, ?)");
                for ($i = 0; $i < $count_specs; $i++) {
                    $sname = trim($spec_names[$i] ?? '');
                    $sel_val = trim($spec_name_selects[$i] ?? '');
                    if ((empty($sname) || $sname === '__custom__') && !empty($sel_val) && $sel_val !== '__custom__') {
                        $sname = $sel_val;
                    }
                    if (!empty($sname) && $sname !== '__custom__') {
                        $slink = trim($spec_links[$i] ?? '');
                        $sfee = trim($spec_fees[$i] ?? '');
                        $sdur = trim($spec_durations[$i] ?? '');
                        $spec_stmt->execute([$id, $sname, $slink, $sfee, $sdur]);
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
    <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="btn-secondary btn-sm" style="text-decoration:none;">&larr; Back to Mappings</a>
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
                            <select name="university_id" class="form-select searchable-select" required>
                                <?php foreach ($universities as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo ($mapping['university_id'] == $u['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u['short_name'] ?: $u['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Course *</label>
                            <select name="course_id" class="form-select searchable-select" required>
                                <?php foreach ($courses as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo ($mapping['course_id'] == $c['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['full_name'] . ' (' . $c['short_name'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Course Mode *</label>
                            <select name="mode" class="form-select searchable-select" required>
                                <?php 
                                $curr_mode = $mapping['mode'] ?? '';
                                $found_m = false;
                                foreach ($master_mode_names as $m_name): 
                                    $is_sel = ($curr_mode === $m_name);
                                    if ($is_sel) $found_m = true;
                                ?>
                                    <option value="<?php echo htmlspecialchars($m_name); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m_name); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!empty($curr_mode) && !$found_m): ?>
                                    <option value="<?php echo htmlspecialchars($curr_mode); ?>" selected>
                                        <?php echo htmlspecialchars($curr_mode); ?> (Existing)
                                    </option>
                                <?php endif; ?>
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
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div>
                        <span class="card-title">3. Course Specializations</span>
                        <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">Select specializations from the master library. (Add new specializations from sidebar Specializations section).</p>
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" class="btn-primary btn-sm" id="add-spec-btn" style="width:auto; padding:6px 14px; font-weight:700;">+ Add Specialization</button>
                    </div>
                </div>
                <div class="card-body">
                    <div style="display:grid; grid-template-columns: 2.2fr 1.6fr 1fr 1fr 40px; gap:12px; font-size:11.5px; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:8px; padding:0 4px;">
                        <span>Specialization Name *</span>
                        <span>URL / Link (Optional)</span>
                        <span>Fee (1st Sem / Per Sem)</span>
                        <span>Duration</span>
                        <span></span>
                    </div>
                    <div id="specs-container" style="display:flex; flex-direction:column; gap:12px;">
                        <?php if (empty($specializations)): ?>
                            <div class="spec-row" style="display:grid; grid-template-columns: 2.2fr 1.6fr 1fr 1fr 40px; gap:12px; align-items:start;">
                                <div class="spec-name-box">
                                    <select name="spec_name[]" class="form-control spec-select" onchange="onSpecSelectChanged(this)" style="background:var(--bg-card, #0f172a); font-size:13px;">
                                        <option value="">-- Select Specialization --</option>
                                        <?php foreach ($master_specializations as $msp): ?>
                                            <option value="<?php echo htmlspecialchars($msp['specialization_name']); ?>">
                                                <?php echo htmlspecialchars($msp['specialization_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="url" name="spec_link[]" class="form-control" placeholder="https://... (optional link)">
                                <input type="text" name="spec_fee[]" class="form-control" placeholder="Fee (e.g. INR 32,875)">
                                <div class="spec-dur-box">
                                    <select name="spec_duration[]" class="form-control spec-duration-select" style="background:var(--bg-card, #0f172a); font-size:13px;">
                                        <option value="">-- Select Duration --</option>
                                        <?php foreach ($master_duration_titles as $dt): ?>
                                            <option value="<?php echo htmlspecialchars($dt); ?>" <?php echo ($dt === '2 Years') ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="button" class="action-btn delete-btn remove-spec-btn" title="Remove">&times;</button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($specializations as $s): 
                                $s_name = trim($s['specialization_name']);
                                $found_in_master = false;
                                foreach ($master_specializations as $msp) {
                                    if (strcasecmp(trim($msp['specialization_name']), $s_name) === 0) {
                                        $found_in_master = true;
                                        break;
                                    }
                                }

                                $s_dur = trim($s['duration'] ?? '');
                                $found_dur_in_master = false;
                                foreach ($master_duration_titles as $dt) {
                                    if (strcasecmp(trim($dt), $s_dur) === 0) {
                                        $found_dur_in_master = true;
                                        break;
                                    }
                                }
                            ?>
                                <div class="spec-row" style="display:grid; grid-template-columns: 2.2fr 1.6fr 1fr 1fr 40px; gap:12px; align-items:start;">
                                    <div class="spec-name-box">
                                        <select name="spec_name[]" class="form-control spec-select" onchange="onSpecSelectChanged(this)" style="background:var(--bg-card, #0f172a); font-size:13px;">
                                            <option value="">-- Select Specialization --</option>
                                            <?php foreach ($master_specializations as $msp): 
                                                $is_sel = (strcasecmp(trim($msp['specialization_name']), $s_name) === 0);
                                            ?>
                                                <option value="<?php echo htmlspecialchars($msp['specialization_name']); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($msp['specialization_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (!empty($s_name) && !$found_in_master): ?>
                                                <option value="<?php echo htmlspecialchars($s_name); ?>" selected>
                                                    <?php echo htmlspecialchars($s_name); ?> (Existing)
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                    <input type="url" name="spec_link[]" class="form-control" value="<?php echo htmlspecialchars($s['specialization_link'] ?? ''); ?>" placeholder="https://... (optional link)">
                                    <input type="text" name="spec_fee[]" class="form-control" value="<?php echo htmlspecialchars($s['fees_per_sem'] ?? ''); ?>" placeholder="Fee">
                                    <div class="spec-dur-box">
                                        <select name="spec_duration[]" class="form-control spec-duration-select" style="background:var(--bg-card, #0f172a); font-size:13px;">
                                            <option value="">-- Select Duration --</option>
                                            <?php foreach ($master_duration_titles as $dt): 
                                                $is_dur_sel = (strcasecmp(trim($dt), $s_dur) === 0);
                                            ?>
                                                <option value="<?php echo htmlspecialchars($dt); ?>" <?php echo $is_dur_sel ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dt); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (!empty($s_dur) && !$found_dur_in_master): ?>
                                                <option value="<?php echo htmlspecialchars($s_dur); ?>" selected>
                                                    <?php echo htmlspecialchars($s_dur); ?> (Existing)
                                                </option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
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
                        <button type="button" class="btn-sm" id="preset-4sem-btn" style="background:rgba(59, 130, 246, 0.15); color:#93c5fd; border:1px solid rgba(59, 130, 246, 0.35); padding:7px 14px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700;">+ 4 Semesters (Master)</button>
                        <button type="button" class="btn-sm" id="preset-6sem-btn" style="background:rgba(245, 158, 11, 0.15); color:#fcd34d; border:1px solid rgba(245, 158, 11, 0.35); padding:7px 14px; border-radius:8px; cursor:pointer; font-size:12px; font-weight:700;">+ 6 Semesters (Bachelor)</button>
                        <button type="button" class="btn-primary btn-sm" id="add-sem-box-btn" style="background:var(--primary-gradient); color:#fff; border:none; padding:7px 16px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; box-shadow:0 2px 10px rgba(79, 70, 229, 0.35);">+ Add Semester Box</button>
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
                        Saved in database under <code>university_course_mappings.syllabus_json</code>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<style>
.sode-sem-card {
    background: var(--bg-input, #151f32);
    border: 1px solid var(--border-color, #1e2b45);
    border-radius: var(--radius-md, 12px);
    padding: 20px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.sode-sem-card:hover {
    border-color: rgba(99, 102, 241, 0.45);
}
.sode-sem-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-color, #1e2b45);
    flex-wrap: wrap;
    gap: 12px;
}
.sode-sem-badge {
    background: rgba(79, 70, 229, 0.18);
    color: #a5b4fc;
    font-weight: 800;
    font-size: 11px;
    padding: 6px 12px;
    border-radius: 6px;
    letter-spacing: 0.8px;
    border: 1px solid rgba(79, 70, 229, 0.3);
    white-space: nowrap;
}
.sode-sem-title-input {
    background: var(--bg-card, #0f172a) !important;
    border: 1px solid var(--border-color, #1e2b45) !important;
    color: var(--text-main, #f8fafc) !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.6px !important;
    max-width: 360px;
    border-radius: 8px !important;
}
.sode-sem-title-input:focus {
    border-color: var(--primary, #4f46e5) !important;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.25) !important;
}
.sode-del-sem-btn {
    background: rgba(239, 68, 68, 0.12) !important;
    color: #f87171 !important;
    border: 1px solid rgba(239, 68, 68, 0.3) !important;
    padding: 7px 14px !important;
    border-radius: 8px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.sode-del-sem-btn:hover {
    background: rgba(239, 68, 68, 0.25) !important;
    color: #fff !important;
}
.sode-sub-header-cols {
    display: grid;
    grid-template-columns: 2fr 2fr 44px;
    gap: 12px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted, #94a3b8);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    padding: 0 4px;
}
.sode-sub-row {
    display: grid;
    grid-template-columns: 2fr 2fr 44px;
    gap: 12px;
    align-items: center;
}
.sode-sub-row .form-control {
    background: var(--bg-card, #0f172a) !important;
    border: 1px solid var(--border-color, #1e2b45) !important;
    color: var(--text-main, #f8fafc) !important;
    border-radius: 8px !important;
    font-size: 13px !important;
}
.sode-sub-row .form-control:focus {
    border-color: var(--primary, #4f46e5) !important;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.25) !important;
}
.sode-del-sub-btn {
    width: 40px !important;
    height: 40px !important;
    border-radius: 8px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: rgba(239, 68, 68, 0.1) !important;
    color: #f87171 !important;
    border: 1px solid rgba(239, 68, 68, 0.25) !important;
    cursor: pointer;
    font-size: 18px !important;
    transition: all 0.2s ease;
}
.sode-del-sub-btn:hover {
    background: rgba(239, 68, 68, 0.25) !important;
    color: #fff !important;
}
.sode-add-sub-btn {
    background: rgba(79, 70, 229, 0.12) !important;
    border: 1px dashed rgba(79, 70, 229, 0.45) !important;
    color: #a5b4fc !important;
    padding: 8px 18px !important;
    border-radius: 8px !important;
    font-size: 12.5px !important;
    font-weight: 700 !important;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}
.sode-add-sub-btn:hover {
    background: rgba(79, 70, 229, 0.25) !important;
    color: #fff !important;
    border-color: var(--primary, #4f46e5) !important;
}

/* Searchable Select Dropdown Styles */
.admin-card {
    overflow: visible !important;
}
.card-body {
    overflow: visible !important;
}
.sode-ss-wrapper {
    position: relative;
    width: 100%;
}
.sode-ss-wrapper.open {
    z-index: 10005;
}
.spec-row:has(.sode-ss-wrapper.open),
.sode-sub-row:has(.sode-ss-wrapper.open),
.sode-sem-card:has(.sode-ss-wrapper.open) {
    z-index: 9999;
    position: relative;
}
.sode-ss-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    background: var(--bg-card, #0f172a);
    border: 1px solid var(--border-color, #1e2b45);
    border-radius: 8px;
    padding: 8px 12px;
    color: var(--text-main, #f8fafc);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
    min-height: 40px;
    box-sizing: border-box;
}
.sode-ss-trigger:hover,
.sode-ss-wrapper.open .sode-ss-trigger {
    border-color: var(--primary, #4f46e5);
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.25);
}
.sode-ss-label {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    text-align: left;
}
.sode-ss-label.placeholder {
    color: var(--text-muted, #64748b);
}
.sode-ss-arrow {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted, #94a3b8);
    transition: transform 0.2s ease;
    flex-shrink: 0;
    margin-left: 6px;
}
.sode-ss-arrow svg {
    width: 14px;
    height: 14px;
    display: block;
}
.sode-ss-wrapper.open .sode-ss-arrow {
    transform: rotate(180deg);
}
.sode-ss-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: #0f172a;
    border: 1px solid #2e3d5b;
    border-radius: 10px;
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.05);
    z-index: 10010;
    overflow: hidden;
    display: none;
    animation: sodeDropdownFade 0.15s ease-out;
}
.sode-ss-wrapper.open-up .sode-ss-dropdown {
    top: auto;
    bottom: calc(100% + 4px);
    box-shadow: 0 -16px 36px rgba(0, 0, 0, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.05);
    animation: sodeDropdownFadeUp 0.15s ease-out;
}
@keyframes sodeDropdownFade {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes sodeDropdownFadeUp {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}
.sode-ss-search-wrap {
    position: relative;
    padding: 8px 10px;
    background: #131d33;
    border-bottom: 1px solid #1e2b45;
    display: flex;
    align-items: center;
}
.sode-ss-search-input {
    width: 100% !important;
    background: #0b1120 !important;
    border: 1px solid #2e3d5b !important;
    color: #f8fafc !important;
    border-radius: 6px !important;
    padding: 7px 10px 7px 30px !important;
    font-size: 12.5px !important;
    outline: none !important;
    box-sizing: border-box;
}
.sode-ss-search-input:focus {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25) !important;
}
.sode-ss-search-icon {
    position: absolute;
    left: 18px;
    pointer-events: none;
    color: #64748b;
    font-size: 11px;
}
.sode-ss-list {
    max-height: 220px;
    overflow-y: auto;
    padding: 4px;
}
.sode-ss-list::-webkit-scrollbar {
    width: 6px;
}
.sode-ss-list::-webkit-scrollbar-thumb {
    background: #2e3d5b;
    border-radius: 4px;
}
.sode-ss-option {
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 12.5px;
    color: #cbd5e1;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    user-select: none;
    text-align: left;
}
.sode-ss-option:hover,
.sode-ss-option.focused {
    background: rgba(99, 102, 241, 0.15);
    color: #a5b4fc;
}
.sode-ss-option.selected {
    background: #4f46e5;
    color: #ffffff;
    font-weight: 600;
}
.sode-ss-no-results {
    padding: 14px 10px;
    font-size: 12px;
    color: #64748b;
    text-align: center;
    font-style: italic;
}
</style>

<script>
// --- Global Utility Functions ---
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// --- Universal Searchable Select Component ---
function makeSearchableSelect(selectEl, placeholderText) {
    if (!selectEl || selectEl.dataset.sodeSearchable) return;
    selectEl.dataset.sodeSearchable = 'true';
    selectEl.style.display = 'none';

    var wrapper = document.createElement('div');
    wrapper.className = 'sode-ss-wrapper';

    var trigger = document.createElement('div');
    trigger.className = 'sode-ss-trigger';
    trigger.tabIndex = 0;

    var label = document.createElement('span');
    label.className = 'sode-ss-label';

    var arrow = document.createElement('span');
    arrow.className = 'sode-ss-arrow';
    arrow.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><polyline points="6 9 12 15 18 9"></polyline></svg>';

    trigger.appendChild(label);
    trigger.appendChild(arrow);
    wrapper.appendChild(trigger);

    var dropdown = document.createElement('div');
    dropdown.className = 'sode-ss-dropdown';

    var searchWrap = document.createElement('div');
    searchWrap.className = 'sode-ss-search-wrap';

    var searchIcon = document.createElement('span');
    searchIcon.className = 'sode-ss-search-icon';
    searchIcon.innerHTML = '&#128269;';

    var searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'sode-ss-search-input';
    searchInput.placeholder = placeholderText || 'Search...';
    searchInput.autocomplete = 'off';

    searchWrap.appendChild(searchIcon);
    searchWrap.appendChild(searchInput);
    dropdown.appendChild(searchWrap);

    var listWrap = document.createElement('div');
    listWrap.className = 'sode-ss-list';
    dropdown.appendChild(listWrap);

    var noResults = document.createElement('div');
    noResults.className = 'sode-ss-no-results';
    noResults.textContent = 'No matches found';
    noResults.style.display = 'none';
    dropdown.appendChild(noResults);

    wrapper.appendChild(dropdown);
    selectEl.parentNode.insertBefore(wrapper, selectEl.nextSibling);

    function updateOptionsList() {
        listWrap.innerHTML = '';
        var options = Array.from(selectEl.options);
        var currentVal = selectEl.value;
        var hasSelected = false;

        options.forEach(function(opt) {
            var optDiv = document.createElement('div');
            optDiv.className = 'sode-ss-option';
            optDiv.dataset.value = opt.value;
            optDiv.textContent = opt.textContent;

            if (opt.value && opt.value === currentVal) {
                optDiv.classList.add('selected');
                label.textContent = opt.textContent;
                label.classList.remove('placeholder');
                hasSelected = true;
            }

            optDiv.addEventListener('click', function(e) {
                e.stopPropagation();
                selectEl.value = opt.value;
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                closeDropdown();
            });

            listWrap.appendChild(optDiv);
        });

        if (!hasSelected) {
            var first = options[0];
            label.textContent = (first && first.textContent) ? first.textContent : (placeholderText || '-- Select --');
            label.classList.add('placeholder');
        }
    }

    function openDropdown() {
        document.querySelectorAll('.sode-ss-wrapper.open').forEach(function(w) {
            if (w !== wrapper) {
                w.classList.remove('open');
                w.classList.remove('open-up');
                var d = w.querySelector('.sode-ss-dropdown');
                if (d) d.style.display = 'none';
                var pr = w.closest('.spec-row, .sode-sub-row');
                if (pr) pr.style.zIndex = '';
                var pc = w.closest('.sode-sem-card, .admin-card');
                if (pc) pc.style.zIndex = '';
            }
        });

        updateOptionsList();

        // Auto-detect whether to open upwards (dropup) or downwards
        var triggerRect = trigger.getBoundingClientRect();
        var spaceBelow = window.innerHeight - triggerRect.bottom;
        var spaceAbove = triggerRect.top;
        var neededHeight = 260;

        var shouldDropup = (spaceBelow < neededHeight) && (spaceAbove > spaceBelow) && (spaceAbove >= 240);

        if (shouldDropup) {
            wrapper.classList.add('open-up');
            listWrap.style.maxHeight = Math.max(140, Math.min(240, spaceAbove - 60)) + 'px';
        } else {
            wrapper.classList.remove('open-up');
            listWrap.style.maxHeight = Math.max(140, Math.min(240, spaceBelow - 60)) + 'px';
        }

        wrapper.classList.add('open');
        dropdown.style.display = 'block';
        searchInput.value = '';
        filterList('');

        var parentRow = wrapper.closest('.spec-row, .sode-sub-row');
        if (parentRow) parentRow.style.zIndex = '10005';
        if (parentCard) parentCard.style.zIndex = '10005';

        setTimeout(function() { searchInput.focus(); }, 40);

        var selOpt = listWrap.querySelector('.sode-ss-option.selected');
        if (selOpt) {
            selOpt.scrollIntoView({ block: 'nearest' });
        }
    }

    function closeDropdown() {
        wrapper.classList.remove('open');
        wrapper.classList.remove('open-up');
        dropdown.style.display = 'none';

        var parentRow = wrapper.closest('.spec-row, .sode-sub-row');
        if (parentRow) parentRow.style.zIndex = '';
        var parentCard = wrapper.closest('.sode-sem-card, .admin-card');
        if (parentCard) parentCard.style.zIndex = '';
    }

    function filterList(query) {
        query = (query || '').trim().toLowerCase();
        var items = listWrap.querySelectorAll('.sode-ss-option');
        var matched = 0;
        items.forEach(function(item) {
            var text = item.textContent.toLowerCase();
            if (!query || text.indexOf(query) !== -1) {
                item.style.display = 'block';
                matched++;
            } else {
                item.style.display = 'none';
            }
        });
        noResults.style.display = matched === 0 ? 'block' : 'none';
    }

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        if (wrapper.classList.contains('open')) {
            closeDropdown();
        } else {
            openDropdown();
        }
    });

    searchInput.addEventListener('input', function() {
        filterList(this.value);
    });

    searchInput.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeDropdown();
            trigger.focus();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var visible = Array.from(listWrap.querySelectorAll('.sode-ss-option')).filter(function(el) {
                return el.style.display !== 'none';
            });
            if (visible.length > 0) {
                visible[0].click();
            }
        }
    });

    selectEl.addEventListener('change', function() {
        var opt = selectEl.options[selectEl.selectedIndex];
        if (opt) {
            label.textContent = opt.textContent;
            label.classList.toggle('placeholder', !opt.value);
        }
        listWrap.querySelectorAll('.sode-ss-option').forEach(function(item) {
            item.classList.toggle('selected', item.dataset.value === selectEl.value);
        });
    });

    updateOptionsList();
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.sode-ss-wrapper')) {
        document.querySelectorAll('.sode-ss-wrapper.open').forEach(function(w) {
            w.classList.remove('open');
            var d = w.querySelector('.sode-ss-dropdown');
            if (d) d.style.display = 'none';
            var pr = w.closest('.spec-row, .sode-sub-row');
            if (pr) pr.style.zIndex = '';
            var pc = w.closest('.sode-sem-card');
            if (pc) pc.style.zIndex = '';
        });
    }
});

// --- Specializations Master Data & Logic ---
var masterSpecsData = <?php echo json_encode($master_specializations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var masterDurationsData = <?php echo json_encode($master_duration_titles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function onSpecSelectChanged(select) {
    var row = select.closest('.spec-row');
    var durationSelect = row.querySelector('.spec-duration-select');
    if (durationSelect && !durationSelect.value && select.value) {
        if (masterDurationsData.includes('2 Years')) {
            durationSelect.value = '2 Years';
        } else if (durationSelect.options.length > 1) {
            durationSelect.selectedIndex = 1;
        }
        durationSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function createSpecRowHtml(name, link, fee, duration) {
    name = name || '';
    link = link || '';
    fee = fee || '';
    duration = duration || '2 Years';

    var div = document.createElement('div');
    div.className = 'spec-row';
    div.style.display = 'grid';
    div.style.gridTemplateColumns = '2.2fr 1.6fr 1fr 1fr 40px';
    div.style.gap = '12px';
    div.style.alignItems = 'start';

    var foundInMaster = false;
    var optionsHtml = '<option value="">-- Select Specialization --</option>';
    if (masterSpecsData && masterSpecsData.length > 0) {
        masterSpecsData.forEach(function(msp) {
            var isSel = (msp.specialization_name.trim().toLowerCase() === name.trim().toLowerCase());
            if (isSel) {
                foundInMaster = true;
            }
            optionsHtml += `<option value="${escapeHtml(msp.specialization_name)}" ${isSel ? 'selected' : ''}>${escapeHtml(msp.specialization_name)}</option>`;
        });
    }

    if (name && !foundInMaster) {
        optionsHtml += `<option value="${escapeHtml(name)}" selected>${escapeHtml(name)} (Existing)</option>`;
    }

    var durFound = false;
    var durOptionsHtml = '<option value="">-- Select Duration --</option>';
    if (masterDurationsData && masterDurationsData.length > 0) {
        masterDurationsData.forEach(function(dt) {
            var isDurSel = (duration && dt.trim().toLowerCase() === duration.trim().toLowerCase());
            if (isDurSel) durFound = true;
            durOptionsHtml += `<option value="${escapeHtml(dt)}" ${isDurSel ? 'selected' : ''}>${escapeHtml(dt)}</option>`;
        });
    }
    if (duration && !durFound) {
        durOptionsHtml += `<option value="${escapeHtml(duration)}" selected>${escapeHtml(duration)} (Existing)</option>`;
    }

    div.innerHTML = `
        <div class="spec-name-box">
            <select name="spec_name[]" class="form-control spec-select" onchange="onSpecSelectChanged(this)" style="background:var(--bg-card, #0f172a); font-size:13px;">
                ${optionsHtml}
            </select>
        </div>
        <input type="url" name="spec_link[]" class="form-control" value="${escapeHtml(link)}" placeholder="https://... (optional link)">
        <input type="text" name="spec_fee[]" class="form-control" value="${escapeHtml(fee)}" placeholder="Fee">
        <div class="spec-dur-box">
            <select name="spec_duration[]" class="form-control spec-duration-select" style="background:var(--bg-card, #0f172a); font-size:13px;">
                ${durOptionsHtml}
            </select>
        </div>
        <button type="button" class="action-btn delete-btn remove-spec-btn" title="Remove">&times;</button>
    `;

    var specSel = div.querySelector('.spec-select');
    if (specSel) {
        makeSearchableSelect(specSel, 'Search specialization...');
    }
    var durSel = div.querySelector('.spec-duration-select');
    if (durSel) {
        makeSearchableSelect(durSel, 'Search duration...');
    }

    return div;
}

// Initialize existing spec rows on page load
document.querySelectorAll('.spec-select').forEach(function(s) {
    makeSearchableSelect(s, 'Search specialization...');
});
document.querySelectorAll('.spec-duration-select').forEach(function(s) {
    makeSearchableSelect(s, 'Search duration...');
});

// Add single specialization
var addSpecBtn = document.getElementById('add-spec-btn');
if (addSpecBtn) {
    addSpecBtn.addEventListener('click', function(e) {
        e.preventDefault();
        var container = document.getElementById('specs-container');
        if (container) {
            container.appendChild(createSpecRowHtml('', '', '', '2 Years'));
        }
    });
}

// Add all master specializations
var btnAddAllSpecs = document.getElementById('btn-add-all-master-specs');
if (btnAddAllSpecs) {
    btnAddAllSpecs.addEventListener('click', function() {
        if (!masterSpecsData || masterSpecsData.length === 0) {
            alert('No master specializations available for this course yet.');
            return;
        }
        var container = document.getElementById('specs-container');
        var existingRows = container.querySelectorAll('.spec-row');
        if (existingRows.length === 1) {
            var firstSel = existingRows[0].querySelector('.spec-select');
            if (!firstSel || !firstSel.value) {
                container.innerHTML = '';
            }
        }

        var addedCount = 0;
        masterSpecsData.forEach(function(ms) {
            var already = false;
            container.querySelectorAll('.spec-select').forEach(function(sel) {
                if (sel.value.trim().toLowerCase() === ms.specialization_name.trim().toLowerCase()) {
                    already = true;
                }
            });
            if (!already) {
                container.appendChild(createSpecRowHtml(ms.specialization_name, '', '', ms.default_duration || '2 Years'));
                addedCount++;
            }
        });

        if (addedCount === 0) {
            alert('All master specializations are already present in the list.');
        }
    });
}

document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('remove-spec-btn')) {
        const row = e.target.closest('.spec-row');
        if (row) row.remove();
    }
});

// Dynamic course switcher listener
var courseSelect = document.querySelector('select[name="course_id"]');
if (courseSelect) {
    courseSelect.addEventListener('change', function() {
        var cid = this.value;
        if (!cid) return;
        fetch('<?php echo BASE_URL; ?>/api/get_master_course_data.php?course_id=' + cid)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    masterSpecsData = data.specializations || [];
                    masterSyllabus = {};
                    (data.syllabus || []).forEach(function(sem) {
                        masterSyllabus[sem.semester_number] = (sem.subjects || []).map(function(s) { return s.name; });
                    });
                    if (btnAddAllSpecs) {
                        btnAddAllSpecs.textContent = '⚡ Add All Master Specializations (' + masterSpecsData.length + ')';
                        btnAddAllSpecs.style.display = masterSpecsData.length > 0 ? 'inline-block' : 'none';
                    }
                }
            })
            .catch(function(err) { console.error('Error fetching course master data:', err); });
    });
}

// --- Semester & Subject Builder ---
(function() {
    var semContainer = document.getElementById('semesters-container');
    var syllabusInput = document.getElementById('syllabus_json_input');
    var initialData = <?php echo json_encode($initial_syllabus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var masterSubjectNames = <?php echo json_encode($master_subject_names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    var romanNumerals = ['FIRST', 'SECOND', 'THIRD', 'FOURTH', 'FIFTH', 'SIXTH', 'SEVENTH', 'EIGHTH', 'NINTH', 'TENTH'];

    function createSemesterBox(title, subjects) {
        title = title || '';
        subjects = subjects || [];

        var box = document.createElement('div');
        box.className = 'sode-sem-card semester-box';

        var semCount = semContainer.querySelectorAll('.semester-box').length + 1;
        var defaultTitle = (romanNumerals[semCount - 1] || ('SEMESTER ' + semCount)) + ' SEMESTER';
        if (!title) title = defaultTitle;

        var masterOptionsHtml = masterSubjectNames.map(function(s) {
            return `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`;
        }).join('');

        box.innerHTML = `
            <div class="sode-sem-header">
                <div style="display:flex; align-items:center; gap:10px; flex:1; max-width:480px;">
                    <span class="sode-sem-badge">SEM ${semCount}</span>
                    <input type="text" class="form-control sode-sem-title-input semester-title-input" value="${escapeHtml(title)}" placeholder="e.g. FIRST SEMESTER">
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <button type="button" class="sode-del-sem-btn remove-sem-box-btn" title="Delete this semester box">
                        &times; Delete Semester
                    </button>
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <div class="sode-sub-header-cols">
                    <span>Subject Name *</span>
                    <span>Link URL (Optional)</span>
                    <span></span>
                </div>
                <div class="subjects-list" style="display:flex; flex-direction:column; gap:10px;">
                </div>
            </div>

            <div style="margin-top:14px; display:flex; justify-content:flex-start; align-items:center; gap:12px; flex-wrap:wrap;">
                <button type="button" class="sode-add-sub-btn add-subject-btn">
                    <span style="font-size:15px; font-weight:bold;">+</span> Add Subject Row
                </button>
            </div>
        `;

        var subjectsList = box.querySelector('.subjects-list');
        if (subjects && subjects.length > 0) {
            subjects.forEach(function(sub) {
                subjectsList.appendChild(createSubjectRow(sub.name || '', sub.link || ''));
            });
        } else {
            subjectsList.appendChild(createSubjectRow('', ''));
        }

        // Add subject button event
        box.querySelector('.add-subject-btn').addEventListener('click', function() {
            subjectsList.appendChild(createSubjectRow('', ''));
        });

        // Remove semester button event
        box.querySelector('.remove-sem-box-btn').addEventListener('click', function() {
            if (confirm('Delete this semester box and all its subjects?')) {
                box.remove();
                renumberBadges();
            }
        });

        return box;
    }

    function renumberBadges() {
        var boxes = semContainer.querySelectorAll('.semester-box');
        boxes.forEach(function(b, idx) {
            var badge = b.querySelector('.sode-sem-badge');
            if (badge) badge.textContent = 'SEM ' + (idx + 1);
        });
    }

    function createSubjectRow(name, link) {
        var row = document.createElement('div');
        row.className = 'sode-sub-row subject-row';

        var foundSubject = false;
        var subjectOptionsHtml = '<option value="">-- Select Subject Name --</option>';
        if (masterSubjectNames && masterSubjectNames.length > 0) {
            masterSubjectNames.forEach(function(sName) {
                var isSel = (sName.trim().toLowerCase() === (name || '').trim().toLowerCase());
                if (isSel) foundSubject = true;
                subjectOptionsHtml += `<option value="${escapeHtml(sName)}" ${isSel ? 'selected' : ''}>${escapeHtml(sName)}</option>`;
            });
        }
        if (name && !foundSubject) {
            subjectOptionsHtml += `<option value="${escapeHtml(name)}" selected>${escapeHtml(name)} (Existing)</option>`;
        }

        row.innerHTML = `
            <div class="subject-select-col">
                <select class="form-control subject-name-select" style="background:var(--bg-card, #0f172a); font-size:13px;">
                    ${subjectOptionsHtml}
                </select>
            </div>
            <input type="url" class="form-control subject-link-input" placeholder="https://... (optional link)" value="${escapeHtml(link)}">
            <button type="button" class="action-btn delete-btn sode-del-sub-btn remove-subject-btn" title="Remove Subject">&times;</button>
        `;

        var subSelect = row.querySelector('.subject-name-select');
        if (subSelect) {
            makeSearchableSelect(subSelect, 'Search subject name...');
        }

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
                var nameSelect = sRow.querySelector('.subject-name-select');
                var linkInput = sRow.querySelector('.subject-link-input');
                var sName = nameSelect ? nameSelect.value.trim() : '';
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
