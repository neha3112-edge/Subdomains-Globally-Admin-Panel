<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('job_roles');

$page_title = 'Job Roles & Salary';
$page_subtitle = 'Manage global career job roles, salary ranges, and descriptions course-wise';
$active_page_key = 'job_roles';

$db = get_db_connection();

// 1. Fetch all available courses from courses table joined with course_job_roles
$all_course_records = $db->query("
    SELECT c.id AS course_id, c.full_name, c.short_name, c.slug AS course_slug,
           cjr.id AS job_role_record_id,
           cjr.course_name AS saved_course_name,
           cjr.heading,
           cjr.description,
           cjr.roles_json,
           cjr.updated_at
    FROM courses c
    LEFT JOIN course_job_roles cjr ON (LOWER(cjr.course_slug) = LOWER(c.slug) OR LOWER(cjr.course_slug) = LOWER(c.short_name))
    ORDER BY c.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// If courses table is empty or migration needed
if (empty($all_course_records)) {
    require_once dirname(__DIR__, 2) . '/config/migrations.php';
    sode_run_auto_migrations($db);
    $all_course_records = $db->query("
        SELECT c.id AS course_id, c.full_name, c.short_name, c.slug AS course_slug,
               cjr.id AS job_role_record_id,
               cjr.course_name AS saved_course_name,
               cjr.heading,
               cjr.description,
               cjr.roles_json,
               cjr.updated_at
        FROM courses c
        LEFT JOIN course_job_roles cjr ON (LOWER(cjr.course_slug) = LOWER(c.slug) OR LOWER(cjr.course_slug) = LOWER(c.short_name))
        ORDER BY c.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Active selected course (support course_id or course slug)
$selected_course_id = (int)($_GET['course_id'] ?? 0);
$selected_slug = strtolower(trim($_GET['course'] ?? ($_POST['course_slug'] ?? '')));

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

// Parse current course roles
$current_roles = [];
if (!empty($current_course_data['roles_json'])) {
    $dec = json_decode($current_course_data['roles_json'], true);
    if (is_array($dec)) {
        $current_roles = $dec;
    }
}

// Default labels for new or unconfigured courses
$course_display_title = $current_course_data['short_name'] ?: ($current_course_data['full_name'] ?? strtoupper($selected_slug));
$default_heading = 'Job Roles and Salary After an Online & Distance ' . $course_display_title . ' Degree';
$default_description = 'Graduates with an Online & Distance ' . $course_display_title . ' degree can explore career opportunities in various fields. Their salary can differ depending on qualifications, experience, skills, job title, organisation and location. Here are some of the key job roles and their expected salary ranges.';

// 3. Handle Form Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_course_roles') {
        $course_slug = strtolower(trim($_POST['course_slug'] ?? ''));
        $heading = trim($_POST['heading'] ?? '');
        $description = trim($_POST['description'] ?? '');

        // Resolve course title from courses master
        $course_name = strtoupper($course_slug);
        foreach ($all_course_records as $cr) {
            if (strtolower($cr['course_slug']) === $course_slug) {
                $course_name = $cr['short_name'] ?: $cr['full_name'];
                break;
            }
        }

        $role_names = $_POST['role_name'] ?? [];
        $role_links = $_POST['role_link'] ?? [];
        $role_salaries = $_POST['role_salary'] ?? [];
        $role_descriptions = $_POST['role_description'] ?? [];
        $role_newtabs = $_POST['role_newtab_val'] ?? [];

        $formatted_roles = [];
        if (is_array($role_names)) {
            foreach ($role_names as $i => $rname) {
                $rname = trim($rname);
                if (!empty($rname)) {
                    $formatted_roles[] = [
                        'role'        => $rname,
                        'link'        => trim($role_links[$i] ?? ''),
                        'new_tab'     => isset($role_newtabs[$i]) ? (int)$role_newtabs[$i] : 1,
                        'salary'      => trim($role_salaries[$i] ?? ''),
                        'description' => trim($role_descriptions[$i] ?? '')
                    ];
                }
            }
        }

        $roles_json = json_encode($formatted_roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $u_stmt = $db->prepare("
                INSERT INTO course_job_roles (course_slug, course_name, heading, description, roles_json, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    course_name = VALUES(course_name),
                    heading = VALUES(heading),
                    description = VALUES(description),
                    roles_json = VALUES(roles_json),
                    updated_at = NOW()
            ");
            $u_stmt->execute([$course_slug, $course_name, $heading, $description, $roles_json]);

            set_flash_message('Job roles for ' . htmlspecialchars($course_name) . ' saved successfully!', 'success');
            redirect(BASE_URL . '/modules/job_roles/index.php?course=' . urlencode($course_slug));
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
                <span class="card-title" style="font-size:15px;">Select Course to Manage Job Roles</span>
                <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">
                    Courses are synced directly from Courses Master. These career roles reflect on all university subdomains.
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
                if (!empty($c_item['roles_json'])) {
                    $d = json_decode($c_item['roles_json'], true);
                    if (is_array($d)) $cnt = count($d);
                }
                $pill_label = $c_item['short_name'] ?: strtoupper($c_item['course_slug']);
            ?>
                <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php?course=<?php echo urlencode($slug); ?>" 
                   style="display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:12.5px; font-weight:700; text-decoration:none; transition:all 0.2s ease; <?php echo $is_cur ? 'background:var(--primary, #4f46e5); color:#fff; box-shadow:0 2px 8px rgba(79,70,229,0.35);' : 'background:var(--bg-input, #151f32); color:var(--text-main, #f8fafc); border:1px solid var(--border-color, #1e2b45);'; ?>">
                    <span><?php echo htmlspecialchars($pill_label); ?></span>
                    <span style="background:<?php echo $is_cur ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.08)'; ?>; padding:2px 7px; border-radius:10px; font-size:11px;">
                        <?php echo $cnt; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Course Job Roles Form -->
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_course_roles">
        <input type="hidden" name="course_slug" value="<?php echo htmlspecialchars($selected_slug); ?>">

        <div style="display:grid; grid-template-columns: 2.2fr 1fr; gap:24px; align-items:start;">
            
            <div style="display:flex; flex-direction:column; gap:20px;">
                
                <!-- 1. Header & Description -->
                <div class="admin-card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <span class="card-title">1. Table Heading & Overview (<?php echo htmlspecialchars($course_display_title); ?>)</span>
                            <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">Displayed above the job roles table on client subdomains. You can use <code>$YEAR$</code> for dynamic year.</p>
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
                                   placeholder="e.g. Job Roles and Salary After an Online & Distance <?php echo htmlspecialchars($course_display_title); ?> Degree">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Table Section Description</label>
                            <textarea name="description" class="form-textarea" rows="3" 
                                      placeholder="Brief overview of career opportunities..."><?php echo htmlspecialchars(!empty($current_course_data['description']) ? $current_course_data['description'] : $default_description); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 2. Job Roles Repeater -->
                <div class="admin-card">
                    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">

                        <div>
                            <span class="card-title">2. Job Roles & Salary List</span>
                            <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">
                                First 10 rows are shown initially. If more than 10, a "Read More" button automatically allows expanding.
                            </p>
                        </div>
                        <button type="button" class="btn-primary btn-sm" id="add-role-btn" style="width:auto; padding:7px 16px; font-weight:700;">
                            + Add Job Role
                        </button>
                    </div>

                    <div class="card-body">
                        <div class="sode-role-header-grid">
                            <span>Job Role Title *</span>
                            <span>URL / Link (Optional)</span>
                            <span>Salary Range in India *</span>
                            <span>Role Description</span>
                            <span></span>
                        </div>

                        <div id="roles-container" style="display:flex; flex-direction:column; gap:12px;">
                            <?php if (empty($current_roles)): ?>
                                <div class="role-row sode-role-row">
                                    <input type="text" name="role_name[]" class="form-control" placeholder="e.g. Marketing Manager" required>
                                    <div>
                                        <input type="url" name="role_link[]" class="form-control" placeholder="https://... (optional)">
                                        <label style="display:inline-flex; align-items:center; gap:6px; font-size:11px; color:var(--text-dim); margin-top:5px; cursor:pointer; user-select:none;">
                                            <input type="hidden" name="role_newtab_val[]" value="1">
                                            <input type="checkbox" checked onchange="this.previousElementSibling.value = this.checked ? '1' : '0'" style="accent-color:var(--primary, #6366f1); width:13px; height:13px; cursor:pointer;">
                                            <span>Open link in new tab</span>
                                        </label>
                                    </div>
                                    <input type="text" name="role_salary[]" class="form-control" placeholder="e.g. ₹6–15 LPA" required>
                                    <input type="text" name="role_description[]" class="form-control" placeholder="e.g. Plans marketing strategies">
                                    <button type="button" class="action-btn delete-btn remove-role-btn" title="Remove">&times;</button>
                                </div>
                            <?php else: ?>
                                <?php foreach ($current_roles as $r): ?>
                                    <div class="role-row sode-role-row">
                                        <input type="text" name="role_name[]" class="form-control" value="<?php echo htmlspecialchars($r['role'] ?? ''); ?>" placeholder="Job Role Title" required>
                                        <div>
                                            <input type="url" name="role_link[]" class="form-control" value="<?php echo htmlspecialchars($r['link'] ?? ''); ?>" placeholder="https://... (optional link)">
                                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:11px; color:var(--text-dim); margin-top:5px; cursor:pointer; user-select:none;">
                                                <input type="hidden" name="role_newtab_val[]" value="<?php echo (!isset($r['new_tab']) || !empty($r['new_tab'])) ? '1' : '0'; ?>">
                                                <input type="checkbox" <?php echo (!isset($r['new_tab']) || !empty($r['new_tab'])) ? 'checked' : ''; ?> onchange="this.previousElementSibling.value = this.checked ? '1' : '0'" style="accent-color:var(--primary, #6366f1); width:13px; height:13px; cursor:pointer;">
                                                <span>Open link in new tab</span>
                                            </label>
                                        </div>
                                        <input type="text" name="role_salary[]" class="form-control" value="<?php echo htmlspecialchars($r['salary'] ?? ''); ?>" placeholder="Salary (e.g. ₹6–15 LPA)" required>
                                        <input type="text" name="role_description[]" class="form-control" value="<?php echo htmlspecialchars($r['description'] ?? ''); ?>" placeholder="Role Description">
                                        <button type="button" class="action-btn delete-btn remove-role-btn" title="Remove">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Sidebar: Actions & Shortcode -->
            <div style="display:flex; flex-direction:column; gap:20px; position:sticky; top:20px;">
                <div class="admin-card">
                    <div class="card-header">
                        <span class="card-title">Save Changes</span>
                    </div>
                    <div class="card-body">
                        <button type="submit" class="btn-primary" style="width:100%; padding:13px 20px; font-weight:700;">
                            Save <?php echo htmlspecialchars(strtoupper($selected_slug)); ?> Roles & Salary
                        </button>
                        <p style="font-size:12px; color:var(--text-dim); margin:12px 0 0; text-align:center;">
                            Saved to <code>course_job_roles</code> table. Changes reflect globally.
                        </p>
                    </div>
                </div>

                <div class="admin-card">
                    <div class="card-header">
                        <span class="card-title">Shortcode for Page</span>
                    </div>
                    <div class="card-body" style="font-size:13px;">
                        <p style="color:var(--text-muted); margin:0 0 8px;">Use this shortcode in Elementor or WordPress editor:</p>
                        <div style="background:var(--bg-card, #0f172a); border:1px solid var(--border-color, #1e2b45); border-radius:8px; padding:10px 12px; font-family:monospace; font-size:12.5px; color:#a5b4fc; word-break:break-all;">
                            [job_roles_table course="<?php echo htmlspecialchars($selected_slug); ?>"]
                        </div>
                        <p style="color:var(--text-dim); font-size:11.5px; margin:10px 0 0;">
                            Aliases: <code>[job_roles]</code>, <code>[course_job_roles]</code>
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>

<style>
.sode-role-header-grid {
    display: grid;
    grid-template-columns: 1.8fr 1.6fr 1.2fr 2fr 40px;
    gap: 12px;
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted, #94a3b8);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
    padding: 0 4px;
}
.sode-role-row {
    display: grid;
    grid-template-columns: 1.8fr 1.6fr 1.2fr 2fr 40px;
    gap: 12px;
    align-items: start;
}
.sode-role-row .form-control {
    background: var(--bg-card, #0f172a) !important;
    border: 1px solid var(--border-color, #1e2b45) !important;
    color: var(--text-main, #f8fafc) !important;
    border-radius: 8px !important;
    font-size: 13px !important;
}
.sode-role-row .form-control:focus {
    border-color: var(--primary, #4f46e5) !important;
    box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.25) !important;
}
.sode-role-row .delete-btn {
    width: 38px !important;
    height: 38px !important;
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
.sode-role-row .delete-btn:hover {
    background: rgba(239, 68, 68, 0.25) !important;
    color: #fff !important;
}
</style>

<script>
document.getElementById('add-role-btn').addEventListener('click', function() {
    const container = document.getElementById('roles-container');
    const div = document.createElement('div');
    div.className = 'role-row sode-role-row';
    div.innerHTML = `
        <input type="text" name="role_name[]" class="form-control" placeholder="Job Role Title" required>
        <div>
            <input type="url" name="role_link[]" class="form-control" placeholder="https://... (optional link)">
            <label style="display:inline-flex; align-items:center; gap:6px; font-size:11px; color:var(--text-dim); margin-top:5px; cursor:pointer; user-select:none;">
                <input type="hidden" name="role_newtab_val[]" value="1">
                <input type="checkbox" checked onchange="this.previousElementSibling.value = this.checked ? '1' : '0'" style="accent-color:var(--primary, #6366f1); width:13px; height:13px; cursor:pointer;">
                <span>Open link in new tab</span>
            </label>
        </div>
        <input type="text" name="role_salary[]" class="form-control" placeholder="Salary (e.g. ₹6–15 LPA)" required>
        <input type="text" name="role_description[]" class="form-control" placeholder="Role Description">
        <button type="button" class="action-btn delete-btn remove-role-btn" title="Remove">&times;</button>
    `;
    container.appendChild(div);
});

document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('remove-role-btn')) {
        const row = e.target.closest('.role-row');
        if (row) {
            const rows = document.querySelectorAll('.role-row');
            if (rows.length <= 1) {
                alert('At least one job role row must remain.');
                return;
            }
            row.remove();
        }
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
