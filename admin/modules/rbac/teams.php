<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_module_access('teams');

$page_title = 'Team Management';
$page_subtitle = 'Manage user groups, departments, and teams';
$active_page_key = 'teams';

$db = get_db_connection();

$action = $_GET['action'] ?? '';
$team_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $post_action = $_POST['post_action'] ?? 'create';

    if ($post_action === 'create' || $post_action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            set_flash_message('error', 'Team name is required.');
        } else {
            if ($post_action === 'create') {
                $stmt = $db->prepare("INSERT INTO teams (name, description) VALUES (:name, :description)");
                try {
                    $stmt->execute(['name' => $name, 'description' => $description]);
                    set_flash_message('success', 'Team created successfully.');
                    redirect(BASE_URL . '/modules/rbac/teams.php');
                } catch (PDOException $e) {
                    set_flash_message('error', 'Team name already exists.');
                }
            } elseif ($post_action === 'edit' && $team_id > 0) {
                $stmt = $db->prepare("UPDATE teams SET name = :name, description = :description WHERE id = :id");
                try {
                    $stmt->execute(['name' => $name, 'description' => $description, 'id' => $team_id]);
                    set_flash_message('success', 'Team updated successfully.');
                    redirect(BASE_URL . '/modules/rbac/teams.php');
                } catch (PDOException $e) {
                    set_flash_message('error', 'Update failed: ' . $e->getMessage());
                }
            }
        }
    } elseif ($post_action === 'delete') {
        $del_id = (int)($_POST['id'] ?? 0);
        if ($del_id > 0) {
            $stmt = $db->prepare("DELETE FROM teams WHERE id = :id");
            $stmt->execute(['id' => $del_id]);
            set_flash_message('success', 'Team deleted successfully.');
        }
        redirect(BASE_URL . '/modules/rbac/teams.php');
    }
}

// Fetch all teams with member count
$teams = $db->query("
    SELECT t.*, (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS members_count 
    FROM teams t 
    ORDER BY t.id ASC
")->fetchAll();

$edit_team = null;
if ($action === 'edit' && $team_id > 0) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $team_id]);
    $edit_team = $stmt->fetch();
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<div class="split-layout">
    
    <!-- LEFT: Create / Edit Team -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title"><?php echo $edit_team ? 'Edit Team' : 'Create Team'; ?></span>
            <?php if ($edit_team): ?>
                <a href="<?php echo BASE_URL; ?>/modules/rbac/teams.php" class="badge badge-muted" style="text-decoration:none;">Cancel</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="post_action" value="<?php echo $edit_team ? 'edit' : 'create'; ?>">

                <div class="form-group">
                    <label class="form-label">Team Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Sales Team" value="<?php echo htmlspecialchars($edit_team['name'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" placeholder="Brief details about the team"><?php echo htmlspecialchars($edit_team['description'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn-primary" style="margin-top:10px;">
                    <?php echo $edit_team ? 'Update Team' : 'Create Team'; ?>
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT: All Teams Table -->
    <div class="admin-card">
        <div class="card-header">
            <span class="card-title">All Teams (<?php echo count($teams); ?>)</span>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Team</th>
                        <th>Description</th>
                        <th>Members</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teams)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-dim);">No teams found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($teams as $t): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                                <td style="color:var(--text-muted);"><?php echo htmlspecialchars($t['description'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge badge-info"><?php echo $t['members_count']; ?></span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo BASE_URL; ?>/modules/rbac/teams.php?action=edit&id=<?php echo $t['id']; ?>" class="action-btn" title="Edit">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                        </a>
                                        <form method="POST" action="" class="delete-form" style="display:inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="post_action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
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
