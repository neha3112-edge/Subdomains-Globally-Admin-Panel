<?php
/**
 * Admin API: Manage Universities for a Specific Accreditation
 * File: admin/api/manage_accreditation_universities.php
 * Supports:
 *   - GET  ?acc_id=X            : Fetch assigned & available universities
 *   - POST (action=add)         : Assign university to accreditation
 *   - POST (action=remove)      : Detach university from accreditation
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/config/config.php';

// Check admin authentication
if (!function_exists('is_logged_in') || !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = get_db_connection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle POST actions (Add / Remove)
if ($method === 'POST') {
    if (!user_can('update')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to update accreditations.']);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $postData = json_decode($rawInput, true);
    if (!is_array($postData)) {
        $postData = $_POST;
    }

    $action = trim($postData['action'] ?? '');
    $acc_id = (int)($postData['acc_id'] ?? 0);
    $university_id = (int)($postData['university_id'] ?? 0);

    if ($acc_id <= 0 || $university_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Accreditation ID and University ID are required.']);
        exit;
    }

    try {
        // Fetch accreditation info
        $stmtAcc = $db->prepare("SELECT id, title, image_url FROM accreditations WHERE id = ? LIMIT 1");
        $stmtAcc->execute([$acc_id]);
        $acc = $stmtAcc->fetch(PDO::FETCH_ASSOC);
        if (!$acc) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Accreditation not found.']);
            exit;
        }

        // Fetch university info
        $stmtUni = $db->prepare("SELECT id, short_name, full_name, slug FROM universities WHERE id = ? LIMIT 1");
        $stmtUni->execute([$university_id]);
        $uni = $stmtUni->fetch(PDO::FETCH_ASSOC);
        if (!$uni) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'University not found.']);
            exit;
        }

        $uniTitle = $uni['short_name'] ?: $uni['full_name'];

        if ($action === 'add') {
            // Check if already mapped
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM university_accreditations WHERE university_id = ? AND accreditation_id = ?");
            $checkStmt->execute([$university_id, $acc_id]);
            if ((int)$checkStmt->fetchColumn() === 0) {
                $ins = $db->prepare("INSERT INTO university_accreditations (university_id, accreditation_id) VALUES (?, ?)");
                $ins->execute([$university_id, $acc_id]);
            }

            // Cache bust & activity log
            if (function_exists('sode_bust_university_cache')) {
                sode_bust_university_cache($university_id, $db);
            }

            if (function_exists('log_activity')) {
                log_activity('UPDATE', 'accreditations', "Assigned accreditation '{$acc['title']}' to university '{$uniTitle}'", [
                    'item_type' => 'Accreditation Assignment',
                    'item_id' => $acc_id,
                    'university_id' => $university_id,
                    'accreditation_title' => $acc['title'],
                    'university_name' => $uniTitle
                ]);
            }

            $message = "Assigned '{$uniTitle}' to {$acc['title']} successfully!";

        } elseif ($action === 'remove') {
            $del = $db->prepare("DELETE FROM university_accreditations WHERE university_id = ? AND accreditation_id = ?");
            $del->execute([$university_id, $acc_id]);

            // Cache bust & activity log
            if (function_exists('sode_bust_university_cache')) {
                sode_bust_university_cache($university_id, $db);
            }

            if (function_exists('log_activity')) {
                log_activity('UPDATE', 'accreditations', "Removed accreditation '{$acc['title']}' from university '{$uniTitle}'", [
                    'item_type' => 'Accreditation Removal',
                    'item_id' => $acc_id,
                    'university_id' => $university_id,
                    'accreditation_title' => $acc['title'],
                    'university_name' => $uniTitle
                ]);
            }

            $message = "Removed '{$uniTitle}' from {$acc['title']} successfully!";
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action.']);
            exit;
        }

        // Return updated data
        $data = fetchAccreditationUniversitiesData($db, $acc_id);
        $data['success'] = true;
        $data['message'] = $message;
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
        exit;
    }
}

// Handle GET request: Fetch lists
$acc_id = (int)($_GET['acc_id'] ?? 0);
if ($acc_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Accreditation ID is required.']);
    exit;
}

try {
    $data = fetchAccreditationUniversitiesData($db, $acc_id);
    if (!$data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Accreditation not found.']);
        exit;
    }
    $data['success'] = true;
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch universities: ' . $e->getMessage()]);
    exit;
}

/**
 * Helper to fetch accreditation info, assigned universities, and unassigned universities
 */
function fetchAccreditationUniversitiesData(PDO $db, int $acc_id): ?array {
    $stmtAcc = $db->prepare("SELECT id, title, image_url, official_link, description FROM accreditations WHERE id = ? LIMIT 1");
    $stmtAcc->execute([$acc_id]);
    $acc = $stmtAcc->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        return null;
    }

    // Assigned Universities
    $stmtAssigned = $db->prepare("
        SELECT u.id, u.short_name, u.full_name, u.slug, u.logo_url, u.is_active
        FROM university_accreditations ua
        INNER JOIN universities u ON u.id = ua.university_id
        WHERE ua.accreditation_id = ?
        ORDER BY u.is_active DESC, COALESCE(NULLIF(u.short_name, ''), u.full_name) ASC
    ");
    $stmtAssigned->execute([$acc_id]);
    $assigned = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC);

    // Format assigned
    $assignedList = [];
    foreach ($assigned as $u) {
        $assignedList[] = [
            'id' => (int)$u['id'],
            'short_name' => $u['short_name'] ?: $u['full_name'],
            'full_name' => $u['full_name'],
            'slug' => $u['slug'],
            'logo_url' => $u['logo_url'] ? get_asset_url($u['logo_url']) : '',
            'is_active' => (int)$u['is_active']
        ];
    }

    // Available Universities (Not currently assigned to this accreditation)
    $stmtAvailable = $db->prepare("
        SELECT u.id, u.short_name, u.full_name, u.is_active
        FROM universities u
        WHERE u.id NOT IN (
            SELECT university_id FROM university_accreditations WHERE accreditation_id = ?
        )
        ORDER BY u.is_active DESC, COALESCE(NULLIF(u.short_name, ''), u.full_name) ASC
    ");
    $stmtAvailable->execute([$acc_id]);
    $available = $stmtAvailable->fetchAll(PDO::FETCH_ASSOC);

    $availableList = [];
    foreach ($available as $u) {
        $availableList[] = [
            'id' => (int)$u['id'],
            'short_name' => $u['short_name'] ?: $u['full_name'],
            'full_name' => $u['full_name'],
            'is_active' => (int)$u['is_active']
        ];
    }

    return [
        'accreditation' => [
            'id' => (int)$acc['id'],
            'title' => $acc['title'],
            'image_url' => $acc['image_url'] ? get_asset_url($acc['image_url']) : '',
            'official_link' => $acc['official_link'] ?? '',
            'description' => $acc['description'] ?? ''
        ],
        'total_count' => count($assignedList),
        'assigned_universities' => $assignedList,
        'available_universities' => $availableList
    ];
}
