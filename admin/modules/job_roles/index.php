<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('job_roles');

$page_title = 'Job Roles & Salary';
$page_subtitle = 'Manage global career job roles, salary ranges, and descriptions course-wise';
$active_page_key = 'job_roles';

$db = get_db_connection();

// 1. Fetch all available courses from course_job_roles
$all_course_records = $db->query("
    SELECT id, course_slug, course_name, heading, description, roles_json, updated_at 
    FROM course_job_roles 
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// If table is empty, run migration
if (empty($all_course_records)) {
    require_once dirname(__DIR__, 2) . '/config/migrations.php';
    sode_run_auto_migrations($db);
    $all_course_records = $db->query("
        SELECT id, course_slug, course_name, heading, description, roles_json, updated_at 
        FROM course_job_roles 
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// 2. Active selected course slug
$selected_slug = strtolower(trim($_GET['course'] ?? ($_POST['course_slug'] ?? 'mba')));
$current_course_data = null;
foreach ($all_course_records as $c_rec) {
    if (strtolower($c_rec['course_slug']) === $selected_slug) {
        $current_course_data = $c_rec;
        break;
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

// 3. Handle Form Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_course_roles') {
        $course_slug = strtolower(trim($_POST['course_slug'] ?? ''));
        $heading = trim($_POST['heading'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $role_names = $_POST['role_name'] ?? [];
        $role_links = $_POST['role_link'] ?? [];
        $role_salaries = $_POST['role_salary'] ?? [];
        $role_descriptions = $_POST['role_description'] ?? [];

        $formatted_roles = [];
        if (is_array($role_names)) {
            foreach ($role_names as $i => $rname) {
                $rname = trim($rname);
                if (!empty($rname)) {
                    $formatted_roles[] = [
                        'role'        => $rname,
                        'link'        => trim($role_links[$i] ?? ''),
                        'salary'      => trim($role_salaries[$i] ?? ''),
                        'description' => trim($role_descriptions[$i] ?? '')
                    ];
                }
            }
        }

        $roles_json = json_encode($formatted_roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $u_stmt = $db->prepare("
                UPDATE course_job_roles 
                SET heading = ?, description = ?, roles_json = ?, updated_at = NOW() 
                WHERE LOWER(course_slug) = LOWER(?)
            ");
            $u_stmt->execute([$heading, $description, $roles_json, $course_slug]);

            set_flash_message('Job roles for ' . strtoupper($course_slug) . ' saved successfully!', 'success');
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
                    These career roles and salaries are global and reflect on all university subdomains.
                </p>
            </div>
            <span class="badge badge-info" style="font-size:12px; padding:6px 12px;">
                Total Courses: <?php echo count($all_course_records); ?>
            </span>
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
            ?>
                <a href="<?php echo BASE_URL; ?>/modules/job_roles/index.php?course=<?php echo urlencode($slug); ?>" 
                   style="display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:8px; font-size:12.5px; font-weight:700; text-decoration:none; transition:all 0.2s ease; <?php echo $is_cur ? 'background:var(--primary, #4f46e5); color:#fff; box-shadow:0 2px 8px rgba(79,70,229,0.35);' : 'background:var(--bg-input, #151f32); color:var(--text-main, #f8fafc); border:1px solid var(--border-color, #1e2b45);'; ?>">
                    <span><?php echo htmlspecialchars(strtoupper($c_item['course_slug'])); ?></span>
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
                            <span class="card-title">1. Table Heading & Overview (<?php echo htmlspecialchars(strtoupper($selected_slug)); ?>)</span>
                            <p style="font-size:12px; color:var(--text-dim); margin:2px 0 0;">Displayed above the job roles table on client subdomains. You can use <code>$YEAR$</code> for dynamic year.</p>
                        </div>
                        <span class="badge badge-success" style="font-weight:700; font-size:12px; padding:5px 10px;">
                            <?php echo htmlspecialchars($current_course_data['course_name'] ?? strtoupper($selected_slug)); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label">Table Section Heading</label>
                            <input type="text" name="heading" class="form-control" 
                                   value="<?php echo htmlspecialchars($current_course_data['heading'] ?? ('Job Roles and Salary After an Online & Distance ' . strtoupper($selected_slug) . ' Degree')); ?>" 
                                   placeholder="e.g. Job Roles and Salary After an Online & Distance MBA Degree">
                        </div>

                        <div class="form-group" style="margin-bottom:0;">
                            <label class="form-label">Table Section Description</label>
                            <textarea name="description" class="form-textarea" rows="3" 
                                      placeholder="Brief overview of career opportunities..."><?php echo htmlspecialchars($current_course_data['description'] ?? ''); ?></textarea>
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
                                    <input type="url" name="role_link[]" class="form-control" placeholder="https://... (optional)">
                                    <input type="text" name="role_salary[]" class="form-control" placeholder="e.g. ₹6–15 LPA" required>
                                    <input type="text" name="role_description[]" class="form-control" placeholder="e.g. Plans marketing strategies">
                                    <button type="button" class="action-btn delete-btn remove-role-btn" title="Remove">&times;</button>
                                </div>
                            <?php else: ?>
                                <?php foreach ($current_roles as $r): ?>
                                    <div class="role-row sode-role-row">
                                        <input type="text" name="role_name[]" class="form-control" value="<?php echo htmlspecialchars($r['role'] ?? ''); ?>" placeholder="Job Role Title" required>
                                        <input type="url" name="role_link[]" class="form-control" value="<?php echo htmlspecialchars($r['link'] ?? ''); ?>" placeholder="https://... (optional link)">
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
    align-items: center;
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
        <input type="url" name="role_link[]" class="form-control" placeholder="https://... (optional link)">
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
