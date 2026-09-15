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

// Search setup
$search = trim($_GET['q'] ?? '');
$where_sql = "";
$params = [];
if ($search !== '') {
    $where_sql = "WHERE (t.name LIKE :q1 OR t.description LIKE :q2)";
    $params[':q1'] = '%' . $search . '%';
    $params[':q2'] = '%' . $search . '%';
}

// Pagination setup
$pagination = sode_get_pagination_params(10);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];

// Total count
if ($search !== '') {
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM teams t $where_sql");
    $count_stmt->execute($params);
    $total_teams = (int)$count_stmt->fetchColumn();
} else {
    $total_teams = (int)$db->query("SELECT COUNT(*) FROM teams")->fetchColumn();
}

// Fetch all teams with member count
$stmt = $db->prepare("
    SELECT t.*, (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS members_count 
    FROM teams t 
    $where_sql
    ORDER BY t.id ASC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$teams = $stmt->fetchAll();

$edit_team = null;
if ($action === 'edit' && $team_id > 0) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $team_id]);
    $edit_team = $stmt->fetch();
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Full Width Search Bar -->
<div class="search-section-card">
    <form method="GET" action="" class="search-section-form">
        <div class="search-input-wrap">
            <span class="search-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search teams by name or description..." class="form-control">
        </div>
        <button type="submit" class="search-btn-theme">Search</button>
        <?php if (!empty($search)): ?>
            <a href="teams.php" class="search-btn-clear">Clear</a>
        <?php endif; ?>
    </form>
</div>

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
                    <textarea name="description" class="form-textarea" placeholder="Brief notes about team responsibilities..."><?php echo htmlspecialchars($edit_team['description'] ?? ''); ?></textarea>
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
            <span class="card-title">All Teams (<?php echo $total_teams; ?>)</span>
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
                        <tr><td colspan="4" style="text-align:center; padding:32px; color:var(--text-dim);"><?php echo $search !== '' ? 'No teams match your search query "' . htmlspecialchars($search) . '".' : 'No teams found.'; ?></td></tr>
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
        <?php echo sode_render_pagination($total_teams, $page, $per_page); ?>
    </div>

</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
