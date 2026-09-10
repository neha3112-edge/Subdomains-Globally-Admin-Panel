<?php
/**
 * Global Admin Panel Configuration
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_NAME', 'SODE Admin');
define('APP_SUBTITLE', 'Universal Subdomains Management Portal');
define('APP_VERSION', '2.0.0');

// Base URL detection
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));

// Find root admin path
$admin_root = rtrim(preg_replace('/\/modules.*|\/api.*/', '', $script_dir), '/');
if (empty($admin_root)) $admin_root = '';

define('BASE_URL', $protocol . $host . $admin_root);
define('ADMIN_PATH', dirname(__DIR__));

require_once ADMIN_PATH . '/config/database.php';
require_once ADMIN_PATH . '/includes/helpers.php';
require_once ADMIN_PATH . '/includes/auth.php';
require_once ADMIN_PATH . '/includes/rbac.php';
