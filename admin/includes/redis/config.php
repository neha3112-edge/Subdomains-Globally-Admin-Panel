<?php
/**
 * Redis Configuration File
 * Loads Redis connection credentials and parameters securely from .env
 */

require_once dirname(__DIR__) . '/config/env.php';

// Detect if running on local development environment or production/live domain
$server_host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$is_local_env = (
    empty($server_host) ||
    strpos($server_host, 'localhost') !== false ||
    strpos($server_host, '127.0.0.1') !== false ||
    strpos($server_host, '.local') !== false ||
    (php_sapi_name() === 'cli' && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
);

if (!defined('REDIS_ENABLED')) {
    $r_enabled = sode_env('REDIS_ENABLED', 'true');
    define('REDIS_ENABLED', ($r_enabled === 'true' || $r_enabled === '1' || $r_enabled === true));
}

if (!defined('REDIS_HOST')) {
    if (!$is_local_env && sode_env('REDIS_LIVE_HOST')) {
        define('REDIS_HOST', sode_env('REDIS_LIVE_HOST'));
    } else {
        define('REDIS_HOST', sode_env('REDIS_HOST', '127.0.0.1'));
    }
}

if (!defined('REDIS_PORT')) {
    if (!$is_local_env && sode_env('REDIS_LIVE_PORT')) {
        define('REDIS_PORT', (int)sode_env('REDIS_LIVE_PORT'));
    } else {
        define('REDIS_PORT', (int)sode_env('REDIS_PORT', 6379));
    }
}

if (!defined('REDIS_PASS')) {
    if (!$is_local_env && sode_env('REDIS_LIVE_PASS')) {
        define('REDIS_PASS', sode_env('REDIS_LIVE_PASS'));
    } else {
        $r_pass = sode_env('REDIS_PASS', sode_env('REDIS_PASSWORD', ''));
        define('REDIS_PASS', $r_pass !== '' ? $r_pass : null);
    }
}

if (!defined('REDIS_TIMEOUT')) {
    define('REDIS_TIMEOUT', (float)sode_env('REDIS_TIMEOUT', 1.5));
}

if (!defined('REDIS_PREFIX')) {
    define('REDIS_PREFIX', sode_env('REDIS_PREFIX', 'sode:'));
}

if (!defined('REDIS_DEFAULT_TTL')) {
    define('REDIS_DEFAULT_TTL', (int)sode_env('REDIS_DEFAULT_TTL', 86400)); // 24 hours default
}
