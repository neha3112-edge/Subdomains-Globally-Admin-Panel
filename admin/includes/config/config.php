<?php
/**
 * Global Admin Panel Configuration
 */

// Timezone Setup (Indian Standard Time - IST)
date_default_timezone_set('Asia/Kolkata');

// Enhanced Session & Cookie Security Configuration (7200 seconds / 2 hours)
ini_set('session.gc_maxlifetime', 7200);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);

$is_secure_conn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

ini_set('session.cookie_secure', $is_secure_conn ? 1 : 0);

// Identify public anonymous endpoints (SSR components & public feeds called by subdomains)
// Subdomain servers call these without session cookies, so we avoid creating empty session files/locks
$is_public_stateless = (
    !empty($_SERVER['REQUEST_URI']) && (
        strpos($_SERVER['REQUEST_URI'], 'render_component.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_global_keys.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_course_fees.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_course_syllabus.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_course_specializations.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_university_fees_table.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_admission_steps.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_alternate_universities.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_courses.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_latest_news.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_footer_config.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_legal_pages.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_master_course_data.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_university_banner.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_university_programmes_table.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_job_roles.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_form_config.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_course_data.php') !== false ||
        strpos($_SERVER['REQUEST_URI'], '/api/get_course_universities.php') !== false
    )
);

// Skip session only for CLI or anonymous requests to public stateless APIs
$skip_session = (
    php_sapi_name() === 'cli' ||
    (empty($_COOKIE[session_name()]) && $is_public_stateless)
);

if (!$skip_session && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 7200,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_secure_conn,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
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
if (empty($admin_root))
    $admin_root = '';

define('BASE_URL', $protocol . $host . $admin_root);
define('ADMIN_PATH', dirname(__DIR__));

require_once ADMIN_PATH . '/config/database.php';
require_once ADMIN_PATH . '/redis/Sode_Redis.php';
require_once ADMIN_PATH . '/includes/helpers.php';
require_once ADMIN_PATH . '/includes/auth.php';
require_once ADMIN_PATH . '/includes/rbac.php';
require_once ADMIN_PATH . '/includes/trash.php';
require_once ADMIN_PATH . '/includes/activity_log.php';
require_once ADMIN_PATH . '/includes/login_log.php';

