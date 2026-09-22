<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('form_courses');

$page_title = 'Form Courses Master';
$page_subtitle = 'Manage universal form dropdown courses and CRM keys. Selectable per university.';
$active_page_key = 'form_courses';

$db = get_db_connection();

// Ensure form_courses table exists & ensure display_label is UNIQUE while form_key can be shared
try {
    $db->exec("
    CREATE TABLE IF NOT EXISTS `form_courses` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `display_label` VARCHAR(100) NOT NULL UNIQUE,
      `form_key` VARCHAR(50) NOT NULL,
      `level` VARCHAR(50) DEFAULT 'PG',
      `sort_order` INT DEFAULT 0,
      `is_active` TINYINT(1) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $indexes = $db->query("SHOW INDEX FROM form_courses")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($indexes as $idx) {
        if ($idx['Column_name'] === 'form_key' && $idx['Non_unique'] == 0 && $idx['Key_name'] !== 'PRIMARY') {
            $db->exec("ALTER TABLE form_courses DROP INDEX `{$idx['Key_name']}`");
        }
    }
    $has_label_unique = false;
    foreach ($indexes as $idx) {
        if ($idx['Column_name'] === 'display_label' && $idx['Non_unique'] == 0) {
            $has_label_unique = true;
        }
    }
    if (!$has_label_unique) {
        $db->exec("ALTER TABLE form_courses ADD UNIQUE KEY uniq_display_label (display_label)");
    }
} catch (Exception $e) {}

// Handle POST Actions (Save / Bulk Save / Edit / Delete / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Single Save / Update
    if ($action === 'save') {
        $course_id = (int)($_POST['course_id'] ?? 0);
        if ($course_id > 0 && !user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update form courses.', 'error');
            redirect(BASE_URL . '/modules/form_courses/index.php');
        } elseif (!$course_id && !user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create form courses.', 'error');
            redirect(BASE_URL . '/modules/form_courses/index.php');
        }

        $display_label = trim($_POST['display_label'] ?? '');
        $form_key = strtoupper(trim($_POST['form_key'] ?? ''));
        $level = trim($_POST['level'] ?? 'PG');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($form_key) && !empty($display_label)) {
            $form_key = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $display_label));
        }

        if (empty($display_label) || empty($form_key)) {
            set_flash_message('Display Label and Form/CRM Key are required.', 'error');
        } else {
            try {
                if ($course_id > 0) {
                    $stmt = $db->prepare("
                        UPDATE form_courses 
                        SET display_label = ?, form_key = ?, level = ?, sort_order = ?, is_active = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$display_label, $form_key, $level, $sort_order, $is_active, $course_id]);

                    if (function_exists('log_activity')) {
                        log_activity('UPDATE', 'settings', "Updated Form Course '{$display_label}' ({$form_key})", [
                            'item_type' => 'Form Course',
                            'item_id' => $course_id,
                            'item_title' => $display_label
                        ]);
                    }

                    if (function_exists('sode_bust_all_subdomain_caches')) {
                        sode_bust_all_subdomain_caches($db);
                    }

                    set_flash_message("Form course '{$display_label}' updated successfully!", 'success');
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO form_courses (display_label, form_key, level, sort_order, is_active, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$display_label, $form_key, $level, $sort_order, $is_active]);
                    $new_id = $db->lastInsertId();

                    if (function_exists('log_activity')) {
                        log_activity('CREATE', 'settings', "Added New Form Course '{$display_label}' ({$form_key})", [
                            'item_type' => 'Form Course',
                            'item_id' => $new_id,
                            'item_title' => $display_label
                        ]);
                    }

                    if (function_exists('sode_bust_all_subdomain_caches')) {
                        sode_bust_all_subdomain_caches($db);
                    }

                    set_flash_message("New form course '{$display_label}' added successfully!", 'success');
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash_message("A form course with Display Label '{$display_label}' already exists.", 'error');
                } else {
                    set_flash_message('Database Error: ' . $e->getMessage(), 'error');
                }
            }
        }
        redirect(BASE_URL . '/modules/form_courses/index.php');
    }

    // 2. Bulk Add
    if ($action === 'bulk_save') {
        if (!user_can('create')) {
            set_flash_message('Access Denied: You do not have permission to create form courses.', 'error');
            redirect(BASE_URL . '/modules/form_courses/index.php');
        }

        $raw_text = trim($_POST['bulk_courses'] ?? '');
        $default_level = trim($_POST['bulk_default_level'] ?? 'PG');

        if (!empty($raw_text)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw_text);
            $added = 0;
            $skipped = 0;

            $stmt = $db->prepare("
                INSERT IGNORE INTO form_courses (display_label, form_key, level, sort_order, is_active, created_at, updated_at)
                VALUES (?, ?, ?, 0, 1, NOW(), NOW())
            ");

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // Support "Label | KEY | LEVEL" or "Label, KEY" or single "Label"
                $parts = preg_split('/[|,]/', $line);
                $lbl = trim($parts[0] ?? '');
                $k = isset($parts[1]) ? strtoupper(trim($parts[1])) : '';
                $lvl = isset($parts[2]) ? trim($parts[2]) : $default_level;

                if (empty($lbl)) continue;
                if (empty($k)) {
                    $k = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '', $lbl));
                }

                try {
                    $stmt->execute([$lbl, $k, $lvl]);
                    if ($stmt->rowCount() > 0) {
                        $added++;
                    } else {
                        $skipped++;
                    }
                } catch (Exception $e) {
                    $skipped++;
                }
            }

            if (function_exists('sode_bust_all_subdomain_caches')) {
                sode_bust_all_subdomain_caches($db);
            }

            set_flash_message("Bulk Add Complete: {$added} courses added" . ($skipped > 0 ? " ({$skipped} duplicates skipped)." : "."), 'success');
        } else {
            set_flash_message('Please enter at least one course line.', 'error');
        }
        redirect(BASE_URL . '/modules/form_courses/index.php');
    }

    // 3. Delete Form Course
    if ($action === 'delete') {
        if (!user_can('delete')) {
            set_flash_message('Access Denied: You do not have permission to delete form courses.', 'error');
            redirect(BASE_URL . '/modules/form_courses/index.php');
        }

        $del_id = (int)($_POST['del_id'] ?? 0);
        if ($del_id > 0) {
            try {
                $target = $db->query("SELECT display_label, form_key FROM form_courses WHERE id = {$del_id}")->fetch();
                $stmt = $db->prepare("DELETE FROM form_courses WHERE id = ?");
                $stmt->execute([$del_id]);

                if (function_exists('log_activity')) {
                    log_activity('DELETE', 'settings', "Deleted Form Course '{$target['display_label']}' ({$target['form_key']})", [
                        'item_type' => 'Form Course',
                        'item_id' => $del_id,
                        'item_title' => $target['display_label'] ?? ''
                    ]);
                }

                if (function_exists('sode_bust_all_subdomain_caches')) {
                    sode_bust_all_subdomain_caches($db);
                }

                set_flash_message('Form course deleted successfully!', 'success');
            } catch (PDOException $e) {
                set_flash_message('Failed to delete form course: ' . $e->getMessage(), 'error');
            }
        }
        redirect(BASE_URL . '/modules/form_courses/index.php');
    }

    // 4. Toggle Active Status
    if ($action === 'toggle_status') {
        if (!user_can('update')) {
            set_flash_message('Access Denied: You do not have permission to update form courses.', 'error');
            redirect(BASE_URL . '/modules/form_courses/index.php');
        }

        $course_id = (int)($_POST['course_id'] ?? 0);
        $curr_status = (int)($_POST['current_status'] ?? 0);
        $new_status = $curr_status ? 0 : 1;

        if ($course_id > 0) {
            $stmt = $db->prepare("UPDATE form_courses SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $course_id]);

            if (function_exists('sode_bust_all_subdomain_caches')) {
                sode_bust_all_subdomain_caches($db);
            }

            set_flash_message('Status updated successfully.', 'success');
        }
        redirect(BASE_URL . '/modules/form_courses/index.php');
    }
}

// Fetch all form courses
$courses = $db->query("SELECT * FROM form_courses ORDER BY sort_order ASC, level ASC, display_label ASC")->fetchAll();

// Calculate Stats
$total_count = count($courses);
$active_count = count(array_filter($courses, fn($c) => $c['is_active'] == 1));
$pg_count = count(array_filter($courses, fn($c) => strtoupper($c['level']) === 'PG'));
$ug_count = count(array_filter($courses, fn($c) => strtoupper($c['level']) === 'UG'));
$other_count = $total_count - ($pg_count + $ug_count);

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Top Header & Action Buttons -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
    <div>
        <h2 style="font-size:20px; font-weight:700; margin:0 0 4px 0; color:var(--text-main, #fff);">Form Dropdown Courses Master</h2>
        <p style="font-size:13.5px; color:var(--text-dim, #94a3b8); margin:0;">
            Centralized library of courses for lead form dropdowns across all university subdomains.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <button type="button" class="btn-primary" onclick="openCourseModal()" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:9px 16px; font-weight:600;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            <span>+ Add Form Course</span>
        </button>
        <button type="button" class="btn-secondary" onclick="toggleBulkAdd()" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:9px 16px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
            <span>Bulk Add</span>
        </button>
        <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="btn-secondary" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:9px 16px;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 9 3 12 0v-5"></path></svg>
            <span>Universities</span>
        </a>
    </div>
</div>

<!-- Stats Counter Grid -->
<div class="stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:18px 20px;">
        <div style="font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Total Form Courses</div>
        <div style="font-size:26px; font-weight:800; color:var(--text-main);"><?php echo $total_count; ?></div>
    </div>
    <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:18px 20px;">
        <div style="font-size:12px; font-weight:600; color:#10b981; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Active in Master</div>
        <div style="font-size:26px; font-weight:800; color:#10b981;"><?php echo $active_count; ?></div>
    </div>
    <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:18px 20px;">
        <div style="font-size:12px; font-weight:600; color:#8b5cf6; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Post-Graduate (PG)</div>
        <div style="font-size:26px; font-weight:800; color:#8b5cf6;"><?php echo $pg_count; ?></div>
    </div>
    <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:18px 20px;">
        <div style="font-size:12px; font-weight:600; color:#3b82f6; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Under-Graduate (UG)</div>
        <div style="font-size:26px; font-weight:800; color:#3b82f6;"><?php echo $ug_count; ?></div>
    </div>
    <div class="stat-card" style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:18px 20px;">
        <div style="font-size:12px; font-weight:600; color:#f59e0b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Diploma & Other</div>
        <div style="font-size:26px; font-weight:800; color:#f59e0b;"><?php echo $other_count; ?></div>
    </div>
</div>

<!-- Bulk Add Collapsible Container -->
<div id="bulkAddPanel" style="display:none; margin-bottom:24px;">
    <div class="admin-card" style="border:1px solid rgba(99,102,241,0.3); background:rgba(99,102,241,0.03);">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:8px;">
                <div style="width:8px; height:8px; border-radius:50%; background:#6366f1;"></div>
                <span class="card-title">Bulk Import Form Courses</span>
            </div>
            <button type="button" onclick="toggleBulkAdd()" style="background:none; border:none; color:var(--text-dim); cursor:pointer; font-size:18px;">&times;</button>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="bulk_save">
                <p style="font-size:13px; color:var(--text-muted); margin:0 0 10px;">
                    Enter multiple courses (one per line). Format: <code>Display Label | CRM KEY | LEVEL</code> or simply <code>Display Label</code> (e.g. <code>M.Com | MCOM | PG</code>).
                </p>
                <div style="display:grid; grid-template-columns: 1fr 200px; gap:12px; margin-bottom:12px;">
                    <textarea name="bulk_courses" class="form-control" rows="5" placeholder="M.Com | MCOM | PG&#10;B.Com | BCOM | UG&#10;Diploma in AI | DIPLOMAAI | Diploma" required style="font-family:monospace; font-size:13px;"></textarea>
                    <div>
                        <label class="form-label" style="font-size:12px;">Default Level</label>
                        <select name="bulk_default_level" class="form-control" style="font-size:13px;">
                            <option value="PG">Post Graduate (PG)</option>
                            <option value="UG">Under Graduate (UG)</option>
                            <option value="Diploma">Diploma</option>
                            <option value="Certificate">Certificate</option>
                            <option value="Doctorate">Doctorate</option>
                            <option value="Other">Other</option>
                        </select>
                        <button type="submit" class="btn-primary" style="width:100%; margin-top:16px; padding:10px; font-weight:700; font-size:13px;">
                            Import All Lines
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Main Table Container -->
<div class="admin-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <div style="width:8px; height:8px; border-radius:50%; background:#10b981;"></div>
            <span class="card-title">All Form Courses</span>
            <span class="badge badge-info" id="visibleCountBadge" style="font-size:11.5px;"><?php echo count($courses); ?> Courses</span>
        </div>
        <!-- Search & Filter Controls -->
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <div style="display:flex; gap:4px; background:var(--bg-input); padding:3px; border-radius:8px; border:1px solid var(--border-color);">
                <button type="button" class="filter-tab-btn active" onclick="filterByLevel('ALL', this)">All</button>
                <button type="button" class="filter-tab-btn" onclick="filterByLevel('PG', this)">PG</button>
                <button type="button" class="filter-tab-btn" onclick="filterByLevel('UG', this)">UG</button>
                <button type="button" class="filter-tab-btn" onclick="filterByLevel('Diploma', this)">Diploma</button>
                <button type="button" class="filter-tab-btn" onclick="filterByLevel('Other', this)">Other</button>
            </div>
            <input type="text" id="courseSearchInput" class="form-control" placeholder="🔍 Search label or key..." onkeyup="filterCoursesTable()" style="font-size:13px; padding:7px 12px; width:220px;">
        </div>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($courses)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-dim);">
                <div style="font-size:40px; margin-bottom:12px;">📋</div>
                <div style="font-weight:700; font-size:16px; margin-bottom:6px; color:var(--text-main);">No Form Courses Configured</div>
                <div style="font-size:13px; max-width:400px; margin:0 auto 16px;">
                    Add your first dropdown course above or use Bulk Add to populate options.
                </div>
                <button type="button" class="btn-primary" onclick="openCourseModal()">+ Add Course Now</button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table" id="formCoursesTable" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:60px; text-align:center;">Sort</th>
                            <th>Display Label (Visitor Sees)</th>
                            <th style="width:220px;">Form & CRM Key</th>
                            <th style="width:130px; text-align:center;">Level</th>
                            <th style="width:110px; text-align:center;">Status</th>
                            <th style="width:120px; text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $c): 
                            $lvl_color = '#94a3b8';
                            $lvl_bg = 'rgba(255,255,255,0.06)';
                            $lvl_upper = strtoupper($c['level']);
                            if ($lvl_upper === 'PG') {
                                $lvl_color = '#c084fc';
                                $lvl_bg = 'rgba(168,85,247,0.15)';
                            } elseif ($lvl_upper === 'UG') {
                                $lvl_color = '#60a5fa';
                                $lvl_bg = 'rgba(59,130,246,0.15)';
                            } elseif ($lvl_upper === 'DIPLOMA') {
                                $lvl_color = '#fbbf24';
                                $lvl_bg = 'rgba(245,158,11,0.15)';
                            }
                        ?>
                            <tr class="course-master-row" 
                                data-level="<?php echo htmlspecialchars($c['level']); ?>" 
                                data-search="<?php echo htmlspecialchars(strtolower($c['display_label'] . ' ' . $c['form_key'])); ?>">
                                <td style="text-align:center; color:var(--text-muted); font-size:12px; font-weight:600;">
                                    <?php echo (int)$c['sort_order']; ?>
                                </td>
                                <td>
                                    <div style="font-weight:700; font-size:14px; color:var(--text-main);">
                                        <?php echo htmlspecialchars($c['display_label']); ?>
                                    </div>
                                </td>
                                <td>
                                    <code style="color:#38bdf8; font-weight:700; font-size:13px; background:rgba(56,189,248,0.1); padding:3px 8px; border-radius:4px; border:1px solid rgba(56,189,248,0.2);">
                                        <?php echo htmlspecialchars($c['form_key']); ?>
                                    </code>
                                </td>
                                <td style="text-align:center;">
                                    <span class="badge" style="background:<?php echo $lvl_bg; ?>; color:<?php echo $lvl_color; ?>; font-size:11.5px; padding:4px 10px; border-radius:6px; font-weight:600;">
                                        <?php echo htmlspecialchars($c['level']); ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="course_id" value="<?php echo $c['id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $c['is_active']; ?>">
                                        <button type="submit" class="badge" style="cursor:pointer; border:none; background:<?php echo $c['is_active'] ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'; ?>; color:<?php echo $c['is_active'] ? '#10b981' : '#ef4444'; ?>; border:1px solid <?php echo $c['is_active'] ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'; ?>; font-size:11.5px; padding:4px 10px; font-weight:600;" title="Click to toggle status">
                                            <?php echo $c['is_active'] ? '● Active' : '○ Inactive'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td style="text-align:center;">
                                    <div style="display:inline-flex; gap:6px; align-items:center;">
                                        <button type="button" class="action-btn edit-btn" onclick='editCourse(<?php echo json_encode($c); ?>)' title="Edit Course" style="border:none; cursor:pointer;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </button>
                                        <form method="POST" onsubmit="return confirm('⚠️ Delete form course \'<?php echo addslashes($c['display_label']); ?>\'? This will remove it from future form configurations.');" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="del_id" value="<?php echo $c['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete Course" style="border:none; cursor:pointer;">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Add / Edit Form Course -->
<div id="courseModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; width:100%; max-width:480px; box-shadow:0 20px 40px rgba(0,0,0,0.5); overflow:hidden; margin:20px;">
        <div style="padding:18px 24px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
            <h3 id="modalTitle" style="margin:0; font-size:16px; font-weight:700; color:var(--text-main);">+ Add Form Course</h3>
            <button type="button" onclick="closeCourseModal()" style="background:none; border:none; color:var(--text-dim); font-size:22px; cursor:pointer; line-height:1;">&times;</button>
        </div>
        <form method="POST" id="courseForm" style="padding:24px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="course_id" id="modal_course_id" value="0">

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" style="font-size:13px; font-weight:600; margin-bottom:6px;">Display Label (Visitor Sees) <span style="color:#ef4444;">*</span></label>
                <input type="text" name="display_label" id="modal_display_label" class="form-control" placeholder="e.g. M.Com or MBA Dual Spec." required style="font-size:13.5px;" oninput="autoGenerateKey(this.value)">
                <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Exact name shown in the website lead form dropdown.</span>
            </div>

            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label" style="font-size:13px; font-weight:600; margin-bottom:6px;">Form & CRM Key (All CAPITAL) <span style="color:#ef4444;">*</span></label>
                <input type="text" name="form_key" id="modal_form_key" class="form-control" placeholder="e.g. MCOM" required style="font-size:13.5px; font-weight:700; color:#38bdf8; text-transform:uppercase;" oninput="this.value = this.value.toUpperCase().replace(/[^A-Z0-9_]/g, '')">
                <span style="font-size:11.5px; color:var(--text-dim); margin-top:4px; display:block;">Backend identifier pushed to CRM, Gallabox, and Brevo lead integrations.</span>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:18px;">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:13px; font-weight:600; margin-bottom:6px;">Course Level</label>
                    <select name="level" id="modal_level" class="form-control" style="font-size:13px;">
                        <option value="PG">Post Graduate (PG)</option>
                        <option value="UG">Under Graduate (UG)</option>
                        <option value="Diploma">Diploma</option>
                        <option value="Certificate">Certificate</option>
                        <option value="Doctorate">Doctorate</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" style="font-size:13px; font-weight:600; margin-bottom:6px;">Sort Order</label>
                    <input type="number" name="sort_order" id="modal_sort_order" class="form-control" value="0" style="font-size:13px;">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:24px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13.5px; color:var(--text-main);">
                    <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width:16px; height:16px; cursor:pointer;">
                    <span>Active in Master List</span>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn-secondary" onclick="closeCourseModal()" style="padding:8px 18px; font-size:13px;">Cancel</button>
                <button type="submit" class="btn-primary" style="padding:8px 22px; font-size:13px; font-weight:700;">Save Course</button>
            </div>
        </form>
    </div>
</div>

<style>
.filter-tab-btn {
    background: transparent;
    border: none;
    color: var(--text-dim);
    font-size: 12px;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}
.filter-tab-btn:hover {
    color: var(--text-main);
}
.filter-tab-btn.active {
    background: var(--bg-card);
    color: var(--text-main);
    box-shadow: 0 1px 4px rgba(0,0,0,0.3);
}
</style>

<script>
function toggleBulkAdd() {
    var p = document.getElementById('bulkAddPanel');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
}

function openCourseModal() {
    document.getElementById('modalTitle').textContent = '+ Add Form Course';
    document.getElementById('modal_course_id').value = '0';
    document.getElementById('modal_display_label').value = '';
    document.getElementById('modal_form_key').value = '';
    document.getElementById('modal_level').value = 'PG';
    document.getElementById('modal_sort_order').value = '0';
    document.getElementById('modal_is_active').checked = true;
    document.getElementById('courseModal').style.display = 'flex';
}

function closeCourseModal() {
    document.getElementById('courseModal').style.display = 'none';
}

function editCourse(c) {
    document.getElementById('modalTitle').textContent = 'Edit Form Course: ' + c.display_label;
    document.getElementById('modal_course_id').value = c.id;
    document.getElementById('modal_display_label').value = c.display_label;
    document.getElementById('modal_form_key').value = c.form_key;
    document.getElementById('modal_level').value = c.level || 'PG';
    document.getElementById('modal_sort_order').value = c.sort_order || 0;
    document.getElementById('modal_is_active').checked = (c.is_active == 1);
    document.getElementById('courseModal').style.display = 'flex';
}

function autoGenerateKey(label) {
    var idInput = document.getElementById('modal_course_id');
    if (idInput.value === '0') {
        var keyInput = document.getElementById('modal_form_key');
        keyInput.value = label.toUpperCase().replace(/[^A-Z0-9_]/g, '');
    }
}

var currentLevelFilter = 'ALL';
function filterByLevel(lvl, btn) {
    currentLevelFilter = lvl;
    document.querySelectorAll('.filter-tab-btn').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    filterCoursesTable();
}

function filterCoursesTable() {
    var query = (document.getElementById('courseSearchInput').value || '').toLowerCase().trim();
    var rows = document.querySelectorAll('.course-master-row');
    var visible = 0;

    rows.forEach(function(row) {
        var rowLevel = (row.getAttribute('data-level') || '').toUpperCase();
        var rowSearch = row.getAttribute('data-search') || '';

        var matchLevel = (currentLevelFilter === 'ALL' || rowLevel === currentLevelFilter.toUpperCase());
        var matchQuery = (!query || rowSearch.indexOf(query) !== -1);

        if (matchLevel && matchQuery) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    var badge = document.getElementById('visibleCountBadge');
    if (badge) badge.textContent = visible + ' Courses';
}
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
