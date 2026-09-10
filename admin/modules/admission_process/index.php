<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('admission_process');

$page_title = 'Admission Process';
$page_subtitle = 'Configure the 8-step universal admission route and roadmap';
$active_page_key = 'admission_process';

$db = get_db_connection();

$universities = $db->query("SELECT id, full_name, short_name FROM universities ORDER BY short_name ASC")->fetchAll();
$selected_uni_id = isset($_GET['uni_id']) && $_GET['uni_id'] !== '' ? (int)$_GET['uni_id'] : null;

// Handle Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_step') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $uni_id = !empty($_POST['university_id']) ? (int)$_POST['university_id'] : null;
        $step_number = (int)($_POST['step_number'] ?? 1);
        $color_hex = trim($_POST['color_hex'] ?? '#3B7FD1');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $icon_svg = trim($_POST['icon_svg'] ?? '');

        if (empty($title) || empty($description)) {
            set_flash_message('Title and Description are required.', 'error');
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE admission_process_steps 
                    SET university_id = ?, step_number = ?, color_hex = ?, title = ?, description = ?, icon_svg = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$uni_id, $step_number, $color_hex, $title, $description, $icon_svg, $id]);
                set_flash_message('Step updated successfully!', 'success');
            } else {
                $stmt = $db->prepare("
                    INSERT INTO admission_process_steps (university_id, step_number, color_hex, title, description, icon_svg) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$uni_id, $step_number, $color_hex, $title, $description, $icon_svg]);
                set_flash_message('Step created successfully!', 'success');
            }
            redirect(BASE_URL . '/modules/admission_process/index.php' . ($uni_id ? '?uni_id=' . $uni_id : ''));
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("DELETE FROM admission_process_steps WHERE id = ?");
            $stmt->execute([$id]);
            set_flash_message('Step deleted successfully!', 'success');
            redirect(BASE_URL . '/modules/admission_process/index.php' . ($selected_uni_id ? '?uni_id=' . $selected_uni_id : ''));
        }
    }
}

// Edit Mode
$edit_step = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    $stmt = $db->prepare("SELECT * FROM admission_process_steps WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_step = $stmt->fetch();
}

// Fetch Steps
$query = "SELECT * FROM admission_process_steps WHERE ";
if ($selected_uni_id) {
    $query .= "university_id = " . (int)$selected_uni_id;
} else {
    $query .= "university_id IS NULL";
}
$query .= " ORDER BY step_number ASC, id ASC";
$steps = $db->query($query)->fetchAll();

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">Filter by Scope:</span>
    </div>
    <div>
        <select class="form-select" onchange="location.href='<?php echo BASE_URL; ?>/modules/admission_process/index.php' + (this.value ? '?uni_id=' + this.value : '')" style="padding:7px 14px; font-size:13px;">
            <option value="">-- Universal Default (All Subdomains) --</option>
            <?php foreach ($universities as $u): ?>
                <option value="<?php echo $u['id']; ?>" <?php echo ($selected_uni_id == $u['id']) ? 'selected' : ''; ?>>
                    Override for: <?php echo htmlspecialchars($u['short_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="split-layout">
    <!-- Left: Form -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_step ? 'Edit Admission Step' : 'Add Admission Step'; ?></span>
            <?php if ($edit_step): ?>
                <a href="<?php echo BASE_URL; ?>/modules/admission_process/index.php<?php echo $selected_uni_id ? '?uni_id=' . $selected_uni_id : ''; ?>" class="btn-sm action-btn" title="Cancel edit">&times;</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="save_step">
                <input type="hidden" name="id" value="<?php echo $edit_step['id'] ?? ''; ?>">

                <div class="form-group">
                    <label class="form-label">Scope (University Override)</label>
                    <select name="university_id" class="form-select">
                        <option value="">Universal (Default for all)</option>
                        <?php foreach ($universities as $u): ?>
                            <?php $sel = ($edit_step && $edit_step['university_id'] == $u['id']) || (!$edit_step && $selected_uni_id == $u['id']) ? 'selected' : ''; ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div class="form-group">
                        <label class="form-label">Step Number (1-8) *</label>
                        <input type="number" min="1" max="12" name="step_number" class="form-control" value="<?php echo htmlspecialchars($edit_step['step_number'] ?? (count($steps) + 1)); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Accent Color Hex *</label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="color" id="color-picker" value="<?php echo htmlspecialchars($edit_step['color_hex'] ?? '#E23F73'); ?>" style="width:40px; height:38px; border:none; border-radius:6px; cursor:pointer;" onchange="document.getElementById('color-hex-input').value = this.value;">
                            <input type="text" name="color_hex" id="color-hex-input" class="form-control" value="<?php echo htmlspecialchars($edit_step['color_hex'] ?? '#E23F73'); ?>" required onchange="document.getElementById('color-picker').value = this.value;">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Step Title *</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($edit_step['title'] ?? ''); ?>" placeholder="Visit official portal" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Step Description *</label>
                    <textarea name="description" class="form-textarea" placeholder="Detailed step guidance..." required><?php echo htmlspecialchars($edit_step['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Icon SVG (Raw &lt;svg&gt;...&lt;/svg&gt;)</label>
                    <textarea name="icon_svg" class="form-textarea" rows="3" placeholder="<svg viewBox=&quot;0 0 24 24&quot; ...>"><?php echo htmlspecialchars($edit_step['icon_svg'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-primary">
                    <?php echo $edit_step ? 'Update Step' : 'Add Step'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Right: Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">Admission Steps (<?php echo count($steps); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Step #</th>
                        <th>Color</th>
                        <th>Title & Description</th>
                        <th>Icon</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($steps)): ?>
                        <tr><td colspan="5" style="text-align:center; color:var(--text-dim);">No steps defined for this scope.</td></tr>
                    <?php else: ?>
                        <?php foreach ($steps as $s): ?>
                            <tr>
                                <td>
                                    <div style="width:28px; height:28px; border-radius:50%; background:<?php echo htmlspecialchars($s['color_hex']); ?>; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px;">
                                        <?php echo $s['step_number']; ?>
                                    </div>
                                </td>
                                <td>
                                    <code style="font-size:11px;"><?php echo htmlspecialchars($s['color_hex']); ?></code>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($s['title']); ?></strong>
                                    <div style="font-size:12px; color:var(--text-dim); max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                        <?php echo htmlspecialchars($s['description']); ?>
                                    </div>
                                </td>
                                <td style="width:30px;">
                                    <?php if (!empty($s['icon_svg'])): ?>
                                        <div style="width:24px; height:24px; color:var(--primary);"><?php echo $s['icon_svg']; ?></div>
                                    <?php else: ?>
                                        <span style="color:var(--text-dim);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/admission_process/index.php?edit_id=<?php echo $s['id']; ?><?php echo $selected_uni_id ? '&uni_id=' . $selected_uni_id : ''; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>

                                        <form method="POST" action="" class="confirm-delete" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Delete">
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
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
