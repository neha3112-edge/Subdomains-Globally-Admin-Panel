<?php
/**
 * Database Configuration (PDO)
 * Universal Education Subdomain Admin Panel
 */

require_once __DIR__ . '/env.php';

if (!defined('DB_HOST'))
    define('DB_HOST', sode_env('DB_HOST', 'localhost'));
if (!defined('DB_PORT'))
    define('DB_PORT', (int) sode_env('DB_PORT', 3306));
if (!defined('DB_NAME'))
    define('DB_NAME', sode_env('DB_NAME', 'admin_glob_db'));
if (!defined('DB_USER'))
    define('DB_USER', sode_env('DB_USER', 'admin_glob_db'));
if (!defined('DB_PASS'))
    define('DB_PASS', sode_env('DB_PASS', ''));
if (!defined('DB_CHARSET'))
    define('DB_CHARSET', sode_env('DB_CHARSET', 'utf8mb4'));

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
        PDO::ATTR_TIMEOUT => 3, // Fast 3-second connection timeout
    ];

    // Smart Wait-Queue & Micro-Retry Configuration
    // When MySQL returns 1040 (Too many connections), pause and retry before failing
    $max_retries = 4; // 1 initial attempt + 3 micro-wait queue retries
    $attempt = 0;
    $last_exception = null;

    while ($attempt < $max_retries) {
        $attempt++;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '+05:30'");

            return $pdo;
        } catch (PDOException $e) {
            $last_exception = $e;
            $err_code = (int) $e->getCode();
            $err_msg = $e->getMessage();

            // Detect transient capacity / connection overload errors
            $is_transient = (
                strpos($err_msg, '1040') !== false ||
                stripos($err_msg, 'Too many connections') !== false ||
                strpos($err_msg, '2002') !== false ||
                strpos($err_msg, '2006') !== false ||
                strpos($err_msg, '1205') !== false ||
                $err_code === 1040 ||
                $err_code === 2002
            );

            if ($is_transient && $attempt < $max_retries) {
                // Micro-wait queue: pause to allow active MySQL queries on server to complete
                // Exponential backoff with random jitter (e.g. 80ms, 170ms, 320ms)
                $sleep_us = (int) (($attempt * 80000) + mt_rand(10000, 40000));
                usleep($sleep_us);
                continue;
            }

            // Non-transient error (e.g. credentials, unknown DB) - exit loop
            break;
        }
    }

    // Log connection failure safely for server debugging
    if ($last_exception) {
        error_log("SODE Database Connection Notice (Attempts: {$attempt}/{$max_retries}): " . $last_exception->getMessage());
    }

    // Never crash public websites / subdomains / APIs with fatal die()
    // Gracefully return null so caller shortcodes and APIs can serve cached/fallback data
    return null;
}
