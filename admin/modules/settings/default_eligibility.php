<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$can_edit = user_can('update') || user_can('write');

$page_title = 'Default Eligibility Criteria (Global)';
$page_subtitle = 'Configure global fallback and course-specific eligibility criteria across all university subdomains';
$active_page_key = 'default_eligibility';

$db = get_db_connection();

// Auto-register sidebar item under SETTINGS if not present
try {
    $check_item = $db->query("SELECT id FROM sidebar_items WHERE active_page_key = 'default_eligibility'")->fetch();
    if (!$check_item) {
        $db->exec("
            INSERT INTO sidebar_items (menu_section, display_name, page_route, active_page_key, rbac_module_key, icon_svg, sort_order, is_active, is_superadmin_only)
            VALUES ('SETTINGS', 'Default Eligibility', 'modules/settings/default_eligibility.php', 'default_eligibility', 'settings', '<svg width=\"18\" height=\"18\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\"><path d=\"M22 10v6M2 10l10-5 10 5-10 5z\"></path><path d=\"M6 12v5c0 2 2 3 6 3s6-1 6-3v-5\"></path></svg>', 19, 1, 0)
        ");
        $item_id = (int) $db->lastInsertId();
        if ($item_id) {
            $roles = $db->query("SELECT id FROM roles")->fetchAll();
            $acc_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
            foreach ($roles as $r) {
                $acc_stmt->execute([$r['id'], $item_id]);
            }
        }
    }
} catch (Exception $e) {}

// Ensure courses table has default_eligibility column
try {
    $col_check = $db->query("SHOW COLUMNS FROM courses LIKE 'default_eligibility'")->fetch();
    if (!$col_check) {
        $db->exec("ALTER TABLE courses ADD COLUMN default_eligibility TEXT NULL AFTER description");
    }
} catch (Exception $e) {}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$can_edit) {
        set_flash_message('Access Denied: You do not have permission to modify default eligibility settings.', 'error');
        redirect(BASE_URL . '/modules/settings/default_eligibility.php');
    }

    $raw_ug          = trim($_POST['default_eligibility_ug'] ?? '');
    $raw_pg          = trim($_POST['default_eligibility_pg'] ?? '');
    $raw_diploma     = trim($_POST['default_eligibility_diploma'] ?? '');
    $raw_certificate = trim($_POST['default_eligibility_certificate'] ?? '');
    $raw_fallback    = trim($_POST['default_eligibility_fallback'] ?? '');
    $course_eligs    = $_POST['course_eligibility'] ?? [];

    try {
        $db->beginTransaction();

        // 1. Update global_settings for level-wise defaults
        $stmt_setting = $db->prepare("
            INSERT INTO global_settings (setting_key, setting_value, setting_group, description)
            VALUES (?, ?, 'eligibility', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        $stmt_setting->execute(['default_eligibility_ug', $raw_ug, 'Global default eligibility for Undergraduate / Bachelor programs']);
        $stmt_setting->execute(['default_eligibility_pg', $raw_pg, 'Global default eligibility for Postgraduate / Master programs']);
        $stmt_setting->execute(['default_eligibility_diploma', $raw_diploma, 'Global default eligibility for Diploma programs']);
        $stmt_setting->execute(['default_eligibility_certificate', $raw_certificate, 'Global default eligibility for Certificate programs']);
        $stmt_setting->execute(['default_eligibility_fallback', $raw_fallback, 'Global general fallback eligibility criteria']);

        // 2. Sync to global_keys table for instant shortcode & client API lookup
        $stmt_key = $db->prepare("
            INSERT INTO global_keys (key_code, key_value, description, is_active)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), is_active = 1
        ");

        $stmt_key->execute(['$DEFAULT_ELIGIBILITY_UG$', $raw_ug, 'Default UG Eligibility Criteria']);
        $stmt_key->execute(['$DEFAULT_ELIGIBILITY_PG$', $raw_pg, 'Default PG Eligibility Criteria']);
        $stmt_key->execute(['$DEFAULT_ELIGIBILITY_DIPLOMA$', $raw_diploma, 'Default Diploma Eligibility Criteria']);
        $stmt_key->execute(['$DEFAULT_ELIGIBILITY_CERTIFICATE$', $raw_certificate, 'Default Certificate Eligibility Criteria']);
        $stmt_key->execute(['$DEFAULT_ELIGIBILITY_FALLBACK$', $raw_fallback, 'Default General Fallback Eligibility Criteria']);

        // 3. Update Course Master specific default eligibilities
        if (is_array($course_eligs)) {
            $upd_stmt = $db->prepare("UPDATE courses SET default_eligibility = ? WHERE id = ?");
            foreach ($course_eligs as $cid => $c_elig) {
                $trimmed = trim($c_elig);
                $upd_stmt->execute([$trimmed !== '' ? $trimmed : null, (int)$cid]);
            }
        }

        $db->commit();

        // Log Activity
        if (function_exists('log_activity')) {
            log_activity('UPDATE', 'settings', "Updated Global Default Eligibility Criteria", [
                'item_type' => 'Setting',
                'item_title' => 'Default Eligibility',
                'ug' => $raw_ug,
                'pg' => $raw_pg,
                'fallback' => $raw_fallback
            ]);
        }

        // Flush cache on all active subdomains
        if (function_exists('sode_bust_all_subdomain_caches')) {
            sode_bust_all_subdomain_caches($db);
        }

        set_flash_message('Global Default Eligibility Criteria updated successfully! All course listings with blank eligibility will now reflect these defaults.', 'success');
        redirect(BASE_URL . '/modules/settings/default_eligibility.php');
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        set_flash_message('Failed to update default eligibility: ' . $e->getMessage(), 'error');
    }
}

// Fetch current default eligibilities
$defaults = sode_get_default_eligibility($db);

// Fetch all courses for master course overrides
$all_courses = [];
try {
    $c_stmt = $db->query("SELECT id, short_name, full_name, slug, level, default_eligibility FROM courses ORDER BY level ASC, full_name ASC");
    $all_courses = $c_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
/* Scoped Inputs & Light Theme Polish */
.sode-elig-input {
    width: 100%;
    padding: 10px 14px;
    font-size: 13.5px;
    border-radius: 8px;
    border: 1px solid var(--border-color, #334155);
    background: var(--bg-input, #0f172a);
    color: var(--text-main, #fff);
    line-height: 1.5;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sode-elig-input:focus {
    border-color: var(--primary, #4f46e5);
    outline: none;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
}

.sode-course-row {
    transition: background-color 0.15s ease;
}
.sode-course-row:hover {
    background: rgba(255, 255, 255, 0.02);
}

.badge-level {
    display: inline-block;
    padding: 3px 8px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    letter-spacing: 0.5px;
}
.badge-level-pg {
    background: rgba(147, 51, 234, 0.15);
    color: #c084fc;
    border: 1px solid rgba(168, 85, 247, 0.3);
}
.badge-level-ug {
    background: rgba(59, 130, 246, 0.15);
    color: #60a5fa;
    border: 1px solid rgba(96, 165, 250, 0.3);
}
.badge-level-dip {
    background: rgba(245, 158, 11, 0.15);
    color: #fbbf24;
    border: 1px solid rgba(251, 191, 36, 0.3);
}
.badge-level-other {
    background: rgba(100, 116, 139, 0.15);
    color: #94a3b8;
    border: 1px solid rgba(148, 163, 184, 0.3);
}

/* Light Theme Overrides */
[data-theme="light"] .card-title,
[data-theme="light"] h2 {
    color: #0f172a !important;
}
[data-theme="light"] .form-label {
    color: #1e293b !important;
}
[data-theme="light"] .sode-elig-input {
    background-color: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
[data-theme="light"] .sode-elig-input:focus {
    border-color: #4f46e5 !important;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
}
[data-theme="light"] .card-header {
    border-bottom-color: #e2e8f0 !important;
}
[data-theme="light"] .admin-card {
    background: #ffffff !important;
    border-color: #e2e8f0 !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}
[data-theme="light"] .sode-course-row:hover {
    background: #f8fafc !important;
}
[data-theme="light"] .admin-table th {
    background: #f8fafc !important;
    color: #475569 !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
[data-theme="light"] .admin-table td {
    border-bottom: 1px solid #f1f5f9 !important;
}
[data-theme="light"] .badge-level-pg {
    background: #f3e8ff !important;
    color: #7e22ce !important;
    border-color: #d8b4fe !important;
}
[data-theme="light"] .badge-level-ug {
    background: #eff6ff !important;
    color: #1d4ed8 !important;
    border-color: #bfdbfe !important;
}
[data-theme="light"] .badge-level-dip {
    background: #fffbeb !important;
    color: #b45309 !important;
    border-color: #fde68a !important;
}
[data-theme="light"] .badge-level-other {
    background: #f1f5f9 !important;
    color: #475569 !important;
    border-color: #cbd5e1 !important;
}
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="font-size:20px; font-weight:700; margin:0 0 4px 0; color:var(--text-main, #fff);">Global Default Eligibility Criteria</h2>
        <p style="font-size:13.5px; color:var(--text-dim, #94a3b8); margin:0;">
            Configure system-wide fallback eligibility criteria for <strong>UG, PG, Diploma, and Master Courses</strong>. When an individual university course mapping leaves eligibility empty, these defaults are automatically displayed across alternative universities and course listings.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn btn-secondary" style="font-size:13px;">
            &larr; Back to Universities
        </a>
        <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="btn btn-outline" style="font-size:13px;">
            Course Mappings &rarr;
        </a>
    </div>
</div>

<form method="POST" action="">
    <?php echo csrf_field(); ?>

    <div class="row" style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
        <div style="display:flex; flex-direction:column; gap:24px;">
            
            <!-- Section 1: Degree Level Global Defaults -->
            <div class="admin-card">
                <div class="card-header" style="border-bottom:1px solid var(--border-color, #334155); padding:16px 20px;">
                    <span class="card-title" style="font-size:15px; font-weight:700; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-color, #6366f1)" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"></path></svg>
                        1. Degree Level Global Defaults
                    </span>
                </div>
                <div class="card-body" style="padding:20px;">
                    
                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                            <span>UG / Bachelor Programs Default Eligibility (BA, B.Com, BBA, BCA, B.Sc, etc.)</span>
                            <span class="badge-level badge-level-ug">UG LEVEL</span>
                        </label>
                        <textarea name="default_eligibility_ug" rows="2" class="sode-elig-input" required placeholder="e.g. Passed Higher Secondary (10+2) or an equivalent examination from a recognized board with minimum 45% marks."><?php echo htmlspecialchars($defaults['ug'] ?? ''); ?></textarea>
                        <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                            Applies automatically to all Undergraduate / Bachelor degree programs without custom eligibility text.
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                            <span>PG / Master Programs Default Eligibility (MA, M.Com, MBA, MCA, M.Sc, etc.)</span>
                            <span class="badge-level badge-level-pg">PG LEVEL</span>
                        </label>
                        <textarea name="default_eligibility_pg" rows="2" class="sode-elig-input" required placeholder="e.g. Bachelor's degree in any discipline from a recognized university with minimum 50% aggregate marks."><?php echo htmlspecialchars($defaults['pg'] ?? ''); ?></textarea>
                        <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                            Applies automatically to all Postgraduate / Master degree programs without custom eligibility text.
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                                <span>Diploma Programs Default</span>
                                <span class="badge-level badge-level-dip">DIPLOMA</span>
                            </label>
                            <textarea name="default_eligibility_diploma" rows="2" class="sode-elig-input" placeholder="e.g. Passed 10+2 or equivalent examination from a recognized board."><?php echo htmlspecialchars($defaults['diploma'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                                <span>Certificate Programs Default</span>
                                <span class="badge-level badge-level-other">CERTIFICATE</span>
                            </label>
                            <textarea name="default_eligibility_certificate" rows="2" class="sode-elig-input" placeholder="e.g. Passed 10th standard or 10+2 from a recognized board."><?php echo htmlspecialchars($defaults['certificate'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label" style="font-weight:600; font-size:13px; margin-bottom:6px; display:block;">
                            Universal General Fallback Eligibility (Global Norms)
                        </label>
                        <input type="text" name="default_eligibility_fallback" class="sode-elig-input" 
                            value="<?php echo htmlspecialchars($defaults['fallback'] ?? '10+2 / Graduation as per university norms'); ?>" 
                            required placeholder="e.g. 10+2 / Graduation as per university norms">
                        <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:5px;">
                            Final fallback used when a course has no level assigned or none of the above criteria match.
                        </div>
                    </div>

                </div>
            </div>

            <!-- Section 2: Master Course-Specific Default Eligibility Table -->
            <div class="admin-card">
                <div class="card-header" style="border-bottom:1px solid var(--border-color, #334155); padding:16px 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div>
                        <span class="card-title" style="font-size:15px; font-weight:700; color:var(--text-main, #fff); display:flex; align-items:center; gap:8px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-color, #10b981)" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                            2. Master Course-Specific Default Eligibility (Optional Overrides)
                        </span>
                        <div style="font-size:12px; color:var(--text-dim, #94a3b8); margin-top:3px;">
                            Set specific default eligibility for individual degrees (e.g. MBA, MCA, B.Com, B.Ed). If left blank, the Degree Level default above will be used.
                        </div>
                    </div>
                    <div>
                        <input type="text" id="courseFilterInput" onkeyup="filterCoursesTable()" placeholder="Search degree name..." 
                            style="padding:6px 12px; font-size:12.5px; border-radius:6px; border:1px solid var(--border-color, #334155); background:var(--bg-input, #0f172a); color:var(--text-main, #fff); width:200px;">
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                        <table class="admin-table" id="coursesDefaultTable" style="margin:0; width:100%;">
                            <thead>
                                <tr>
                                    <th style="width: 25%; min-width: 160px;">Course / Degree</th>
                                    <th style="width: 12%; text-align: center;">Level</th>
                                    <th style="width: 63%;">Master Default Eligibility Criteria</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_courses)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; padding:24px; color:var(--text-dim);">No courses found in database.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_courses as $c): ?>
                                        <?php
                                            $lvl = strtoupper(trim($c['level'] ?? ''));
                                            $badge_class = 'badge-level-other';
                                            if ($lvl === 'PG') $badge_class = 'badge-level-pg';
                                            elseif ($lvl === 'UG') $badge_class = 'badge-level-ug';
                                            elseif (stripos($lvl, 'DIP') !== false) $badge_class = 'badge-level-dip';

                                            $placeholder_hint = ($lvl === 'PG') ? 'Will fallback to PG Level default' : (($lvl === 'UG') ? 'Will fallback to UG Level default' : 'Will fallback to general default');
                                        ?>
                                        <tr class="sode-course-row" data-search="<?php echo htmlspecialchars(strtolower($c['short_name'] . ' ' . $c['full_name'] . ' ' . $c['level'])); ?>">
                                            <td style="vertical-align: top; padding: 14px 18px;">
                                                <strong style="color:var(--text-main, #fff); font-size:13.5px; display:block;">
                                                    <?php echo htmlspecialchars($c['short_name']); ?>
                                                </strong>
                                                <span style="font-size:12px; color:var(--text-dim, #94a3b8); display:block; margin-top:2px;">
                                                    <?php echo htmlspecialchars($c['full_name']); ?>
                                                </span>
                                            </td>
                                            <td style="vertical-align: top; text-align: center; padding: 14px 10px;">
                                                <span class="badge-level <?php echo $badge_class; ?>">
                                                    <?php echo htmlspecialchars($lvl ?: 'GEN'); ?>
                                                </span>
                                            </td>
                                            <td style="vertical-align: top; padding: 12px 18px;">
                                                <textarea name="course_eligibility[<?php echo (int)$c['id']; ?>]" rows="2" class="sode-elig-input" style="font-size:12.5px;" placeholder="<?php echo htmlspecialchars($placeholder_hint); ?>"><?php echo htmlspecialchars($c['default_eligibility'] ?? ''); ?></textarea>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($can_edit): ?>
                        <div style="padding:16px 20px; border-top:1px solid var(--border-color, #334155); display:flex; justify-content:space-between; align-items:center; background: rgba(0,0,0,0.02);">
                            <span style="font-size:12px; color:var(--text-dim, #94a3b8);">
                                Changes will be saved across all level fallbacks and individual course defaults simultaneously.
                            </span>
                            <button type="submit" class="btn btn-primary" style="padding:11px 26px; font-weight:600; font-size:14px; background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); border:none; border-radius:8px; color:#fff; cursor:pointer; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                                Save Global Default Eligibility
                            </button>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>

        <!-- Sidebar / Explainer Column -->
        <div>
            
            <div class="admin-card" style="position:sticky; top:20px; margin-bottom:20px;">
                <div class="card-header" style="border-bottom:1px solid var(--border-color, #334155); padding:16px 20px;">
                    <span class="card-title" style="font-size:15px; font-weight:700; color:var(--text-main, #fff);">
                        How Eligibility Resolution Works
                    </span>
                </div>
                <div class="card-body" style="padding:20px; font-size:13px; line-height:1.6; color:var(--text-dim, #94a3b8);">
                    
                    <p style="margin-top:0;">
                        <strong style="color:var(--text-main, #fff); display:block; margin-bottom:2px;">1. University Mapping Override (Priority 1)</strong>
                        When editing a university's course mapping under <em>Course Mappings</em>, if custom eligibility is written, it will <strong>always</strong> override and display on that university's subdomain.
                    </p>

                    <p>
                        <strong style="color:var(--text-main, #fff); display:block; margin-bottom:2px;">2. Course-Specific Default (Priority 2)</strong>
                        If the university course mapping is left blank, the system checks if a specific default was saved in <em>Section 2</em> for that course (e.g. MCA or MBA specific requirements).
                    </p>

                    <p>
                        <strong style="color:var(--text-main, #fff); display:block; margin-bottom:2px;">3. Degree Level Default (Priority 3)</strong>
                        If no course-specific default is set, it falls back to the program's Degree Level default (e.g. <strong>UG Default</strong> or <strong>PG Default</strong>).
                    </p>

                    <p style="margin-bottom:0;">
                        <strong style="color:var(--text-main, #fff); display:block; margin-bottom:2px;">4. Global Norms (Priority 4)</strong>
                        If the degree has no level or matching criteria, the Universal Fallback text (e.g. <em>"10+2 / Graduation as per university norms"</em>) is displayed.
                    </p>

                </div>
            </div>

            <!-- Global Keys Reference Card -->
            <div class="admin-card">
                <div class="card-header" style="border-bottom:1px solid var(--border-color, #334155); padding:16px 20px;">
                    <span class="card-title" style="font-size:14.5px; font-weight:700; color:var(--text-main, #fff);">
                        Shortcodes & Global Keys
                    </span>
                </div>
                <div class="card-body" style="padding:16px 20px; font-size:12.5px; color:var(--text-dim, #94a3b8);">
                    <p style="margin-top:0; margin-bottom:12px;">
                        These dynamic keys are auto-synced and available across all subdomain templates:
                    </p>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <div>
                            <code style="color:#38bdf8; background:rgba(56,189,248,0.1); padding:2px 6px; border-radius:4px;">$DEFAULT_ELIGIBILITY_UG$</code>
                        </div>
                        <div>
                            <code style="color:#38bdf8; background:rgba(56,189,248,0.1); padding:2px 6px; border-radius:4px;">$DEFAULT_ELIGIBILITY_PG$</code>
                        </div>
                        <div>
                            <code style="color:#38bdf8; background:rgba(56,189,248,0.1); padding:2px 6px; border-radius:4px;">$DEFAULT_ELIGIBILITY_DIPLOMA$</code>
                        </div>
                        <div>
                            <code style="color:#38bdf8; background:rgba(56,189,248,0.1); padding:2px 6px; border-radius:4px;">$DEFAULT_ELIGIBILITY_FALLBACK$</code>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</form>

<script>
function filterCoursesTable() {
    const input = document.getElementById('courseFilterInput');
    const filter = input.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#coursesDefaultTable tbody tr.sode-course-row');
    
    rows.forEach(row => {
        const searchText = row.getAttribute('data-search') || '';
        if (searchText.indexOf(filter) > -1) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
