<?php
/**
 * Live Database & Environment Diagnostics
 * Access: https://admin.distanceeducationschool.com/test_db.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>SODE Database Diagnostics</title>";
echo "<style>body{font-family:sans-serif; background:#0f172a; color:#f8fafc; padding:30px;} .card{background:#1e293b; padding:20px; border-radius:8px; margin-bottom:20px; border:1px solid #334155;} .badge{padding:4px 10px; border-radius:4px; font-weight:bold;} .success{background:#10b981; color:#fff;} .error{background:#ef4444; color:#fff;} table{width:100%; border-collapse:collapse; margin-top:10px;} th, td{padding:10px; border:1px solid #334155; text-align:left;} th{background:#0f172a;}</style></head><body>";

echo "<h2>🔍 SODE Live Server Diagnostics</h2>";

// 1. Check .env paths
echo "<div class='card'>";
echo "<h3>1. Environment (.env) File Detection</h3>";

$paths = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
    (!empty($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] . '/.env' : null)
];

$found = false;
foreach (array_filter($paths) as $p) {
    if (file_exists($p)) {
        echo "<p>✅ Found .env at: <code>" . htmlspecialchars($p) . "</code></p>";
        $found = true;
        break;
    } else {
        echo "<p>❌ Not found at: <code>" . htmlspecialchars($p) . "</code></p>";
    }
}
if (!$found) {
    echo "<p style='color:#f87171;'>⚠️ No .env file found in checked paths! Make sure .env is uploaded to the root directory.</p>";
}
echo "</div>";

// 2. Load Config & DB
require_once __DIR__ . '/config/config.php';

echo "<div class='card'>";
echo "<h3>2. Database Connection Test</h3>";
echo "<p><strong>Host:</strong> " . htmlspecialchars(DB_HOST) . "</p>";
echo "<p><strong>Port:</strong> " . htmlspecialchars(DB_PORT) . "</p>";
echo "<p><strong>Database:</strong> " . htmlspecialchars(DB_NAME) . "</p>";
echo "<p><strong>User:</strong> " . htmlspecialchars(DB_USER) . "</p>";
echo "<p><strong>Password Set:</strong> " . (strlen(DB_PASS) > 0 ? "YES (" . strlen(DB_PASS) . " chars)" : "NO (Empty)") . "</p>";

try {
    $db = get_db_connection();
    echo "<p><span class='badge success'>CONNECTED SUCCESSFULLY TO MYSQL</span></p>";
    
    // 3. Inspect Tables & Users
    echo "</div><div class='card'>";
    echo "<h3>3. Live Tables & Users Inspection</h3>";
    
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Total Tables Found: <strong>" . count($tables) . "</strong> (" . implode(', ', array_slice($tables, 0, 8)) . "...)</p>";
    
    if (in_array('users', $tables)) {
        $users = $db->query("SELECT id, name, username, email, role_id, is_superadmin, is_active FROM users")->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Users in Database: <strong>" . count($users) . "</strong></p>";
        
        if (!empty($users)) {
            echo "<table><tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Active</th></tr>";
            foreach ($users as $u) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($u['id']) . "</td>";
                echo "<td>" . htmlspecialchars($u['name']) . "</td>";
                echo "<td><strong style='color:#38bdf8;'>" . htmlspecialchars($u['username']) . "</strong></td>";
                echo "<td>" . htmlspecialchars($u['email']) . "</td>";
                echo "<td>" . ($u['is_active'] ? '✅ Active' : '❌ Deactivated') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color:#f87171;'>⚠️ Users table is EMPTY! Run schema.sql or default seed.</p>";
        }
    } else {
        echo "<p style='color:#f87171;'>⚠️ Table 'users' does not exist yet!</p>";
    }
    
} catch (Exception $e) {
    echo "<p><span class='badge error'>CONNECTION FAILED</span></p>";
    echo "<p><strong>Error Message:</strong> <code>" . htmlspecialchars($e->getMessage()) . "</code></p>";
}
echo "</div>";

echo "</body></html>";
