<?php
/**
 * Database Configuration (PDO)
 * Universal Education Subdomain Admin Panel
 */

require_once __DIR__ . '/env.php';

if (!defined('DB_HOST'))
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT'))
    define('DB_PORT', (int)(getenv('DB_PORT') ?: 3307));
if (!defined('DB_NAME'))
    define('DB_NAME', getenv('DB_NAME') ?: 'admin_glob_db');
if (!defined('DB_USER'))
    define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS'))
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('DB_CHARSET'))
    define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

function get_db_connection()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // Auto-migrate schema on initial connect if tables missing
        static $checked_schema = false;
        if (!$checked_schema) {
            $checked_schema = true;
            try {
                $check = $pdo->query("SHOW TABLES LIKE 'universities'")->fetch();
                if (!$check) {
                    $schema_path = dirname(ADMIN_PATH) . '/schema.sql';
                    if (file_exists($schema_path)) {
                        $sql = file_get_contents($schema_path);
                        $pdo->exec($sql);
                    }
                }
            } catch (Exception $ex) {
                // Ignore schema auto-check error
            }
        }
        
        return $pdo;
    } catch (PDOException $e) {
        // Show exact error message to debug localhost
        die("<div style='font-family:sans-serif; padding:20px; background:#fff3f3; color:#d32f2f; border:1px solid #ffcdd2; border-radius:8px; max-width:600px; margin:40px auto;'>"
            . "<h3 style='margin-top:0;'>⚠️ Database Connection Error:</h3>"
            . "<p><strong>Details:</strong> " . htmlspecialchars($e->getMessage()) . "</p>"
            . "<p style='color:#555; font-size:13px;'>Make sure: <br>1. XAMPP me MySQL running hai.<br>2. phpMyAdmin me <code>" . htmlspecialchars(DB_NAME) . "</code> naam ka database create kiya hua hai.</p>"
            . "</div>");
    }
}
