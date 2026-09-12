<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();

$page_title = 'Universities Table';
$page_subtitle = 'Manage global course-wise top universities table, fees, accreditations, and advantages';
$active_page_key = 'course_universities';

$db = get_db_connection();

// 1. Fetch all available courses from courses table joined with course_universities_table
$all_course_records = $db->query("
    SELECT c.id AS course_id, c.full_name, c.short_name, c.slug AS course_slug,
           cut.id AS table_record_id,
           cut.course_name AS saved_course_name,
           cut.heading,
           cut.description,
           cut.columns_json,
           cut.universities_json,
           cut.updated_at
    FROM courses c
    LEFT JOIN course_universities_table cut ON (LOWER(cut.course_slug) = LOWER(c.slug) OR LOWER(cut.course_slug) = LOWER(c.short_name))
    ORDER BY c.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// If table is empty or migration needed
if (empty($all_course_records)) {
    require_once dirname(__DIR__, 2) . '/config/migrations.php';
    sode_run_auto_migrations($db);
    $all_course_records = $db->query("
        SELECT c.id AS course_id, c.full_name, c.short_name, c.slug AS course_slug,
               cut.id AS table_record_id,
               cut.course_name AS saved_course_name,
               cut.heading,
               cut.description,
               cut.columns_json,
               cut.universities_json,
               cut.updated_at
        FROM courses c
        LEFT JOIN course_universities_table cut ON (LOWER(cut.course_slug) = LOWER(c.slug) OR LOWER(cut.course_slug) = LOWER(c.short_name))
        ORDER BY c.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Active selected course (support course_id or course slug)
$selected_course_id = (int)($_GET['course_id'] ?? 0);
$selected_slug = strtolower(trim($_GET['course'] ?? ($_POST['course_slug'] ?? 'mba')));

$current_course_data = null;
if ($selected_course_id > 0) {
    foreach ($all_course_records as $c_rec) {
        if ((int)$c_rec['course_id'] === $selected_course_id) {
            $current_course_data = $c_rec;
            $selected_slug = strtolower($c_rec['course_slug']);
            break;
        }
    }
}
if (!$current_course_data && !empty($selected_slug)) {
    foreach ($all_course_records as $c_rec) {
        if (strtolower($c_rec['course_slug']) === $selected_slug || strtolower($c_rec['short_name']) === $selected_slug) {
            $current_course_data = $c_rec;
            $selected_slug = strtolower($c_rec['course_slug']);
            break;
        }
    }
}
if (!$current_course_data && !empty($all_course_records)) {
    $current_course_data = $all_course_records[0];
    $selected_slug = strtolower($current_course_data['course_slug']);
}

// Parse current course universities
$current_unis = [];
if (!empty($current_course_data['universities_json'])) {
    $dec = json_decode($current_course_data['universities_json'], true);
    if (is_array($dec)) {
        $current_unis = $dec;
    }
}

// Default labels for new courses
$course_display_title = $current_course_data['short_name'] ?: ($current_course_data['full_name'] ?? strtoupper($selected_slug));
$default_heading = 'Top 10 Online & Distance ' . $course_display_title . ' Universities in India $YEAR$';
$default_description = 'Choosing the right university is an important step when pursuing an Online & Distance ' . $course_display_title . '. The best choice depends on factors such as fees, recognition, course options, and career support. Here are some of the top Online & Distance ' . $course_display_title . ' universities in India for $YEAR$.';

// 3. Handle Form Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_course_universities') {
        $course_slug = strtolower(trim($_POST['course_slug'] ?? ''));
        $heading = trim($_POST['heading'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Resolve friendly course name from courses master
        $course_name = strtoupper($course_slug);
        foreach ($all_course_records as $cr) {
            if (strtolower($cr['course_slug']) === $course_slug) {
                $course_name = $cr['short_name'] ?: $cr['full_name'];
                break;
            }
        }

        $uni_names = $_POST['uni_name'] ?? [];
        $uni_links = $_POST['uni_link'] ?? [];
        $uni_fees = $_POST['uni_fees'] ?? [];
        $uni_locations = $_POST['uni_location'] ?? [];
        $uni_accreditations = $_POST['uni_accreditation'] ?? [];
        $uni_advantages = $_POST['uni_advantage'] ?? [];

        $formatted_unis = [];
        if (is_array($uni_names)) {
            foreach ($uni_names as $i => $uname) {
                $uname = trim($uname);
                if (!empty($uname)) {
                    $formatted_unis[] = [
                        'name'          => $uname,
                        'fees'          => trim($uni_fees[$i] ?? ''),
                        'location'      => trim($uni_locations[$i] ?? ''),
                        'accreditation' => trim($uni_accreditations[$i] ?? ''),
                        'advantage'     => trim($uni_advantages[$i] ?? ''),
                        'link'          => trim($uni_links[$i] ?? '')
                    ];
                }
            }
        }

        $columns = ["University Name", $course_name . " Fee (Per Semester)", "Location", "Approvals & Accreditation", "Advantage"];
        $columns_json = json_encode($columns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $universities_json = json_encode($formatted_unis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $u_stmt = $db->prepare("
                INSERT INTO course_universities_table (course_slug, course_name, heading, description, columns_json, universities_json, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    course_name = VALUES(course_name),
                    heading = VALUES(heading),
                    description = VALUES(description),
                    columns_json = VALUES(columns_json),
                    universities_json = VALUES(universities_json),
                    updated_at = NOW()
            ");
            $u_stmt->execute([$course_slug, $course_name, $heading, $description, $columns_json, $universities_json]);

            set_flash_message('Universities table for ' . htmlspecialchars($course_name) . ' saved successfully!', 'success');
            redirect(BASE_URL . '/modules/course_universities/index.php?course=' . urlencode($course_slug));
        } catch (PDOException $e) {
            set_flash_message('Database Error: ' . $e->getMessage(), 'error');
        }
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; flex-direction:column; gap:20px;">

    <!-- Course Switcher Pills -->
    <div class="admin-card" style="padding:16px 20px;">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
            <div>
                <span class="card-title" style="font-size:15px;">Select Course to Manage Universities Table</span>
                <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">
                    Courses are synced directly from Courses Master. This table reflects globally across client subdomains.
                </p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="btn-sm action-btn" style="text-decoration:none; font-size:12px; padding:5px 10px; display:inline-flex; align-items:center; gap:5px;">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"></path></svg>
                    Courses Master
                </a>
                <span class="badge badge-info" style="font-size:12px; padding:6px 12px;">
                    Total Courses: <?php echo count($all_course_records); ?>
                </span>
            </div>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <?php foreach ($all_course_records as $c_item): 
                $slug = strtolower($c_item['course_slug']);
                $is_cur = ($slug === $selected_slug);
                $cnt = 0;
                if (!empty($c_item['universities_json'])) {
                    $d = json_decode($c_item['universities_json'], true);
                    if (is_array($d)) $cnt = count($d);
                }
                $pill_label = $c_item['short_name'] ?: strtoupper($c_item['course_slug']);
            ?>
                <a href="<?php echo BASE_URL; ?>/modules/course_universities/index.php?course=<?php echo urlencode($slug); ?>" 
                   style="display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:12.5px; font-weight:700; text-decoration:none; transition:all 0.2s ease; <?php echo $is_cur ? 'background:var(--primary, #4f46e5); color:#fff; box-shadow:0 2px 8px rgba(79,70,229,0.35);' : 'background:var(--bg-input, #151f32); color:var(--text-main, #f8fafc); border:1px solid var(--border-color, #1e2b45);'; ?>">
                    <span><?php echo htmlspecialchars($pill_label); ?></span>
                    <span style="background:<?php echo $is_cur ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.08)'; ?>; padding:2px 7px; border-radius:10px; font-size:11px;">
                        <?php echo $cnt; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Course Universities Form -->
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_course_universities">
        <input type="hidden" name="course_slug" value="<?php echo htmlspecialchars($selected_slug); ?>">

        <div style="display:grid; grid-template-columns: 2.3fr 1fr; gap:24px; align-items:start;">
            
            <div style="display:flex; flex-direction:column; gap:20px;">
                
                <!-- 1. Header & Description -->
                <div class="admin-card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <span class="card-title">1. Table Heading & Overview (<?php echo htmlspecialchars($course_display_title); ?>)</span>
                            <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">Displayed above the universities fees table on client subdomains. You can use <code>$YEAR$</code> for dynamic year.</p>
                        </div>
                        <span class="badge badge-success" style="font-weight:700; font-size:12px; padding:5px 10px;">
                            <?php echo htmlspecialchars($course_display_title); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Table Section Heading</label>
                            <input type="text" name="heading" class="form-control" 
                                   value="<?php echo htmlspecialchars(!empty($current_course_data['heading']) ? $current_course_data['heading'] : $default_heading); ?>" 
                                   placeholder="e.g. Top 10 Online & Distance <?php echo htmlspecialchars($course_display_title); ?> Universities in India $YEAR$">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Table Section Description</label>
                            <textarea name="description" class="form-textarea" rows="3" 
                                      placeholder="Brief overview of course and selection criteria..."><?php echo htmlspecialchars(!empty($current_course_data['description']) ? $current_course_data['description'] : $default_description); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 2. Universities Repeater -->
                <div class="admin-card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <div>
                            <span class="card-title">2. Universities List</span>
                            <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">
                                Listed universities offering this course with semester fees, location, accreditations, and unique advantage.
                            </p>
                        </div>
                        <button type="button" id="btn-add-uni" class="btn btn-primary" style="padding:6px 14px; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            Add University
                        </button>
                    </div>

                    <div class="card-body" style="padding:16px;">
                        <div id="unis-container" style="display:flex; flex-direction:column; gap:14px;">
                            <?php if (!empty($current_unis)): ?>
                                <?php foreach ($current_unis as $idx => $uni): ?>
                                    <div class="uni-row-card" style="background:var(--bg-input, #151f32); border:1px solid var(--border-color, #1e2b45); border-radius:10px; padding:16px; position:relative;">
                                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px dashed var(--border-color, #1e2b45); padding-bottom:8px;">
                                            <span style="font-size:13px; font-weight:700; color:var(--primary, #4f46e5); display:inline-flex; align-items:center; gap:6px;">
                                                <span class="uni-index-badge" style="background:rgba(79,70,229,0.2); color:#818cf8; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px;">
                                                    <?php echo $idx + 1; ?>
                                                </span>
                                                <span class="uni-title-preview"><?php echo htmlspecialchars($uni['name'] ?: 'University #' . ($idx + 1)); ?></span>
                                            </span>
                                            <button type="button" class="btn-remove-uni" title="Remove University" style="background:transparent; border:none; color:#ef4444; cursor:pointer; padding:4px 8px; border-radius:4px;">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            </button>
                                        </div>

                                        <div style="display:grid; grid-template-columns: 1.5fr 1.5fr 1fr; gap:12px; margin-bottom:12px;">
                                            <div>
                                                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">University Name *</label>
                                                <input type="text" name="uni_name[]" class="form-control uni-name-input" value="<?php echo htmlspecialchars($uni['name']); ?>" placeholder="e.g. Amity University" required>
                                            </div>
                                            <div>
                                                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">URL Link (Optional)</label>
                                                <input type="text" name="uni_link[]" class="form-control" value="<?php echo htmlspecialchars($uni['link'] ?? ''); ?>" placeholder="https://distanceeducationschool.com/...">
                                            </div>
                                            <div>
                                                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Fee (Per Semester)</label>
                                                <input type="text" name="uni_fees[]" class="form-control" value="<?php echo htmlspecialchars($uni['fees']); ?>" placeholder="e.g. ₹56,300">
                                            </div>
                                        </div>

                                        <div style="display:grid; grid-template-columns: 1.2fr 1.2fr 1.6fr; gap:12px;">
                                            <div>
                                                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Location</label>
                                                <input type="text" name="uni_location[]" class="form-control" value="<?php echo htmlspecialchars($uni['location'] ?? ''); ?>" placeholder="e.g. Noida, Uttar Pradesh">
                                            </div>
                                            <div>
                                                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Approvals & Accreditation</label>
                                                <input type="text" name="uni_accreditation[]" class="form-control" value="<?php echo htmlspecialchars($uni['accreditation'] ?? ''); ?>" placeholder="e.g. UGC, NAAC A+">
                                            </div>
                                            <div>
                                                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Advantage / Key Highlights</label>
                                                <input type="text" name="uni_advantage[]" class="form-control" value="<?php echo htmlspecialchars($uni['advantage'] ?? ''); ?>" placeholder="e.g. Global recognition">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div id="empty-state-notice" style="text-align:center; padding:35px 20px; color:var(--text-dim);">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:10px; opacity:0.5;"><rect x="3" y="3" width="18" height="18" rx="2"></rect><path d="M3 9h18M3 15h18M9 3v18M15 3v18"></path></svg>
                                    <p style="margin:0 0 10px; font-size:14px;">No universities added yet for <?php echo htmlspecialchars($course_display_title); ?>.</p>
                                    <button type="button" class="btn btn-outline" onclick="document.getElementById('btn-add-uni').click();" style="font-size:12.5px;">+ Add First University</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Sidebar: Controls & Info -->
            <div style="display:flex; flex-direction:column; gap:20px; position:sticky; top:20px;">
                
                <div class="admin-card">
                    <div class="card-header">
                        <span class="card-title">Save Changes</span>
                    </div>
                    <div class="card-body" style="display:flex; flex-direction:column; gap:14px;">
                        <p style="font-size:12.5px; color:var(--text-dim); margin:0;">
                            Updates will immediately apply to all university subdomains rendering <code>[course_table course="<?php echo htmlspecialchars($selected_slug); ?>"]</code>.
                        </p>
                        <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:11px; font-size:14px; font-weight:700;">
                            Save Universities Table
                        </button>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="card-header">
                        <span class="card-title">Shortcodes</span>
                    </div>
                    <div class="card-body" style="display:flex; flex-direction:column; gap:12px; font-size:12px;">
                        <div>
                            <span style="color:var(--text-dim); display:block; margin-bottom:4px;">Primary Shortcode:</span>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" class="form-control" readonly value='[course_table course="<?php echo htmlspecialchars($selected_slug); ?>"]' style="font-family:monospace; font-size:11.5px; padding:6px 10px;">
                                <button type="button" class="btn-sm action-btn copy-btn" onclick="navigator.clipboard.writeText('[course_table course=&quot;<?php echo htmlspecialchars($selected_slug); ?>&quot;]'); this.innerText='Copied!';" title="Copy">Copy</button>
                            </div>
                        </div>
                        <div>
                            <span style="color:var(--text-dim); display:block; margin-bottom:4px;">Alias Shortcodes:</span>
                            <code style="display:block; background:rgba(255,255,255,0.05); padding:6px 8px; border-radius:6px; margin-bottom:4px;">[universities_table course="<?php echo htmlspecialchars($selected_slug); ?>"]</code>
                            <code style="display:block; background:rgba(255,255,255,0.05); padding:6px 8px; border-radius:6px;">[top_universities course="<?php echo htmlspecialchars($selected_slug); ?>"]</code>
                        </div>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="card-header">
                        <span class="card-title">Table Features</span>
                    </div>
                    <div class="card-body" style="font-size:12px; color:var(--text-dim); line-height:1.5; display:flex; flex-direction:column; gap:8px;">
                        <div>• <strong>Initial 10 Rows:</strong> The first 10 universities are shown by default. Remaining rows are revealed via the <em>View More</em> button.</div>
                        <div>• <strong>Interactive Compare:</strong> Includes <em>+ Compare</em> button for each university, launching the floating compare bar and compare modal.</div>
                        <div>• <strong>Dynamic $YEAR$:</strong> Automatically gets replaced by the current year (e.g. <?php echo date('Y'); ?>).</div>
                    </div>
                </div>

            </div>

        </div>
    </form>

</div>

<template id="uni-row-template">
    <div class="uni-row-card" style="background:var(--bg-input, #151f32); border:1px solid var(--border-color, #1e2b45); border-radius:10px; padding:16px; position:relative;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px dashed var(--border-color, #1e2b45); padding-bottom:8px;">
            <span style="font-size:13px; font-weight:700; color:var(--primary, #4f46e5); display:inline-flex; align-items:center; gap:6px;">
                <span class="uni-index-badge" style="background:rgba(79,70,229,0.2); color:#818cf8; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:11px;">#</span>
                <span class="uni-title-preview">New University</span>
            </span>
            <button type="button" class="btn-remove-uni" title="Remove University" style="background:transparent; border:none; color:#ef4444; cursor:pointer; padding:4px 8px; border-radius:4px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            </button>
        </div>

        <div style="display:grid; grid-template-columns: 1.5fr 1.5fr 1fr; gap:12px; margin-bottom:12px;">
            <div>
                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">University Name *</label>
                <input type="text" name="uni_name[]" class="form-control uni-name-input" value="" placeholder="e.g. Amity University" required>
            </div>
            <div>
                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">URL Link (Optional)</label>
                <input type="text" name="uni_link[]" class="form-control" value="" placeholder="https://distanceeducationschool.com/...">
            </div>
            <div>
                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Fee (Per Semester)</label>
                <input type="text" name="uni_fees[]" class="form-control" value="" placeholder="e.g. ₹50,000">
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1.2fr 1.2fr 1.6fr; gap:12px;">
            <div>
                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Location</label>
                <input type="text" name="uni_location[]" class="form-control" value="" placeholder="e.g. Noida, Uttar Pradesh">
            </div>
            <div>
                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Approvals & Accreditation</label>
                <input type="text" name="uni_accreditation[]" class="form-control" value="" placeholder="e.g. UGC, NAAC A+">
            </div>
            <div>
                <label style="font-size:11.5px; font-weight:600; color:var(--text-dim); display:block; margin-bottom:4px;">Advantage / Key Highlights</label>
                <input type="text" name="uni_advantage[]" class="form-control" value="" placeholder="e.g. Global recognition">
            </div>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('unis-container');
    var btnAdd = document.getElementById('btn-add-uni');
    var template = document.getElementById('uni-row-template');

    function updateIndices() {
        var rows = container.querySelectorAll('.uni-row-card');
        rows.forEach(function (row, idx) {
            var badge = row.querySelector('.uni-index-badge');
            if (badge) badge.textContent = (idx + 1);
        });
    }

    if (btnAdd && template) {
        btnAdd.addEventListener('click', function () {
            var emptyNotice = document.getElementById('empty-state-notice');
            if (emptyNotice) emptyNotice.remove();

            var clone = template.content.cloneNode(true);
            container.appendChild(clone);
            updateIndices();

            // Focus on new row
            var allRows = container.querySelectorAll('.uni-row-card');
            var lastRow = allRows[allRows.length - 1];
            if (lastRow) {
                var firstInput = lastRow.querySelector('input');
                if (firstInput) firstInput.focus();
            }
        });
    }

    // Remove row delegation
    container.addEventListener('click', function (e) {
        var removeBtn = e.target.closest('.btn-remove-uni');
        if (removeBtn) {
            var row = removeBtn.closest('.uni-row-card');
            if (row && confirm('Are you sure you want to remove this university from this course table?')) {
                row.remove();
                updateIndices();
            }
        }
    });

    // Update title preview on input
    container.addEventListener('input', function (e) {
        if (e.target.classList.contains('uni-name-input')) {
            var row = e.target.closest('.uni-row-card');
            if (row) {
                var preview = row.querySelector('.uni-title-preview');
                if (preview) {
                    preview.textContent = e.target.value.trim() || 'Untitled University';
                }
            }
        }
    });
});
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
