<?php
/**
 * Central High-Performance Redis Manager Class
 * File: admin/redis/Sode_Redis.php
 * 
 * Provides unified, safe, and lightning-fast Redis caching across all subdomains and admin APIs.
 * Automatically falls back gracefully to MySQL database queries if Redis is offline.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/RedisClient.php';

class Sode_Redis
{
    protected static $client = null;
    protected static $connectionAttempted = false;
    protected static $isAvailable = false;
    protected static $driver = 'none'; // 'phpredis', 'pure_socket', 'none'

    /**
     * Get or initialize the active Redis client instance
     */
    public static function client()
    {
        if (self::$connectionAttempted) {
            return self::$client;
        }

        self::$connectionAttempted = true;

        if (!defined('REDIS_ENABLED') || !REDIS_ENABLED) {
            self::$isAvailable = false;
            self::$client = null;
            return null;
        }

        $host = defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1';
        $port = defined('REDIS_PORT') ? REDIS_PORT : 6379;
        $pass = defined('REDIS_PASS') ? REDIS_PASS : null;
        $timeout = defined('REDIS_TIMEOUT') ? REDIS_TIMEOUT : 1.5;

        // 1. Try PHP Native Extension (phpredis) if available
        if (class_exists('Redis', false)) {
            try {
                $native = new Redis();
                $connected = @$native->connect($host, $port, $timeout);
                if ($connected) {
                    if (!empty($pass)) {
                        @$native->auth($pass);
                    }
                    if (@$native->ping()) {
                        self::$client = $native;
                        self::$isAvailable = true;
                        self::$driver = 'phpredis';
                        return self::$client;
                    }
                }
            } catch (Throwable $e) {
                // Ignore and fall through to socket client
            }
        }

        // 2. Pure PHP Socket Client (Zero Dependencies)
        try {
            $pure = new Sode_Pure_Redis_Client($host, $port, $pass, $timeout);
            if ($pure->connect() && $pure->ping()) {
                self::$client = $pure;
                self::$isAvailable = true;
                self::$driver = 'pure_socket';
                return self::$client;
            }
        } catch (Throwable $e) {
            self::$isAvailable = false;
            self::$client = null;
        }

        return self::$client;
    }

    /**
     * Check if Redis server is connected and usable
     */
    public static function isAvailable()
    {
        self::client();
        return self::$isAvailable;
    }

    /**
     * Get active driver name ('phpredis', 'pure_socket', 'none')
     */
    public static function getDriver()
    {
        self::client();
        return self::$driver;
    }

    /**
     * Format key with application prefix
     */
    public static function formatKey($key)
    {
        $prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : 'sode:';
        if (strpos($key, $prefix) === 0) {
            return $key;
        }
        return $prefix . $key;
    }

    /**
     * Retrieve an item from the cache
     */
    public static function get($key, $default = null)
    {
        $c = self::client();
        if (!$c) return $default;

        try {
            $fKey = self::formatKey($key);
            $val = $c->get($fKey);

            if ($val === null || $val === false) {
                return $default;
            }

            // Attempt JSON decode
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return $val;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /**
     * Store an item in the cache with a TTL (seconds)
     */
    public static function set($key, $value, $ttl = null)
    {
        $c = self::client();
        if (!$c) return false;

        try {
            $fKey = self::formatKey($key);
            $ttl = $ttl !== null ? (int)$ttl : (defined('REDIS_DEFAULT_TTL') ? REDIS_DEFAULT_TTL : 86400);

            $payload = (is_array($value) || is_object($value)) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$value;

            if ($ttl > 0) {
                return $c->setex($fKey, $ttl, $payload);
            } else {
                return $c->set($fKey, $payload);
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Alias for set with explicit TTL
     */
    public static function setex($key, $ttl, $value)
    {
        return self::set($key, $value, $ttl);
    }

    /**
     * Get an item from cache, or execute the given callback and store the result
     */
    public static function remember($key, $ttl, callable $callback)
    {
        if (self::isAvailable()) {
            $cached = self::get($key, '__SODE_CACHE_MISS__');
            if ($cached !== '__SODE_CACHE_MISS__') {
                return $cached;
            }
        }

        // Cache miss or Redis offline -> execute callback
        $fresh = $callback();

        if (self::isAvailable() && $fresh !== null && $fresh !== false) {
            self::set($key, $fresh, $ttl);
        }

        return $fresh;
    }

    /**
     * Delete one or more items from the cache
     */
    public static function del($key)
    {
        $c = self::client();
        if (!$c) return 0;

        try {
            $keys = is_array($key) ? $key : func_get_args();
            $formatted = array_map([self::class, 'formatKey'], $keys);
            return (int)$c->del($formatted);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Delete raw exact key name (without re-prefixing)
     */
    public static function delRaw($rawKey)
    {
        $c = self::client();
        if (!$c) return 0;

        try {
            return (int)$c->del($rawKey);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Check if a key exists
     */
    public static function exists($key)
    {
        $c = self::client();
        if (!$c) return false;

        try {
            return (bool)$c->exists(self::formatKey($key));
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Flush all keys matching a specific wildcard pattern (e.g. 'sode:*' or 'keys:*')
     */
    public static function flushPattern($pattern = '*')
    {
        $c = self::client();
        if (!$c) return 0;

        try {
            $prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : 'sode:';
            $fPattern = (strpos($pattern, $prefix) === 0) ? $pattern : $prefix . ltrim($pattern, '*');
            if (substr($fPattern, -1) !== '*') {
                $fPattern .= '*';
            }

            $keys = $c->keys($fPattern);
            if (!empty($keys) && is_array($keys)) {
                return (int)$c->del($keys);
            }
            return 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Flush all keys in the current Redis database
     */
    public static function flushAll()
    {
        $c = self::client();
        if (!$c) return false;

        try {
            // First try flushing prefixed keys to be safe in shared Redis environments
            $prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : 'sode:';
            self::flushPattern($prefix . '*');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Get diagnostic info & memory usage from Redis server
     */
    public static function getInfo()
    {
        $c = self::client();
        if (!$c) {
            return [
                'status' => 'offline',
                'driver' => 'none',
                'host'   => defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1',
                'port'   => defined('REDIS_PORT') ? REDIS_PORT : 6379,
                'error'  => 'Cannot connect to Redis server',
            ];
        }

        try {
            $info = $c->info();
            $dbsize = 0;
            if (method_exists($c, 'dbsize')) {
                $dbsize = $c->dbsize();
            } elseif (method_exists($c, 'dbSize')) {
                $dbsize = $c->dbSize();
            }

            $prefix = defined('REDIS_PREFIX') ? REDIS_PREFIX : 'sode:';
            $appKeys = $c->keys($prefix . '*');
            $appKeyCount = is_array($appKeys) ? count($appKeys) : 0;

            return [
                'status'          => 'connected',
                'driver'          => self::$driver,
                'host'            => defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1',
                'port'            => defined('REDIS_PORT') ? REDIS_PORT : 6379,
                'redis_version'   => $info['redis_version'] ?? 'Unknown',
                'uptime_days'     => isset($info['uptime_in_days']) ? $info['uptime_in_days'] . ' days' : 'N/A',
                'connected_clients' => $info['connected_clients'] ?? 'N/A',
                'used_memory_human' => $info['used_memory_human'] ?? 'N/A',
                'used_memory_peak_human' => $info['used_memory_peak_human'] ?? 'N/A',
                'total_keys_in_db' => $dbsize,
                'app_keys_count'  => $appKeyCount,
                'app_keys'        => is_array($appKeys) ? array_slice($appKeys, 0, 100) : [],
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'driver' => self::$driver,
                'error'  => $e->getMessage()
            ];
        }
    }
}
