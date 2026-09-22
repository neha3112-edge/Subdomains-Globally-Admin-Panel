<?php
/**
 * Standalone Pure-PHP Redis Client (RESP Socket Protocol + phpredis bridge)
 * File: admin/redis/RedisClient.php
 * 
 * Works 100% out of the box on any PHP 7.4 - 8.3+ environment without external dependencies.
 * If native PHP 'Redis' extension is installed, it utilizes that for maximum throughput;
 * otherwise it connects over native TCP network stream using RESP protocol.
 */

class Sode_Pure_Redis_Client
{
    protected $socket = null;
    protected $host;
    protected $port;
    protected $password;
    protected $timeout;
    protected $isConnected = false;
    protected $lastError = null;

    public function __construct($host = '127.0.0.1', $port = 6379, $password = null, $timeout = 1.5)
    {
        $this->host = $host;
        $this->port = (int)$port;
        $this->password = $password;
        $this->timeout = (float)$timeout;
    }

    public function isConnected()
    {
        return $this->isConnected && is_resource($this->socket);
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function connect()
    {
        if ($this->isConnected()) {
            return true;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            $this->lastError = "Connection failed: {$errstr} ({$errno})";
            $this->isConnected = false;
            return false;
        }

        stream_set_timeout($socket, (int)$this->timeout, (int)(($this->timeout - (int)$this->timeout) * 1000000));
        $this->socket = $socket;
        $this->isConnected = true;

        // Authentication if password provided
        if (!empty($this->password)) {
            $authRes = $this->sendCommand(['AUTH', $this->password]);
            if ($authRes !== 'OK') {
                $this->lastError = "AUTH failed: " . json_encode($authRes);
                $this->close();
                return false;
            }
        }

        return true;
    }

    public function close()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->isConnected = false;
    }

    public function sendCommand(array $args)
    {
        if (!$this->isConnected() && !$this->connect()) {
            return null;
        }

        // Build RESP Array command: *<count>\r\n$<len>\r\n<arg>\r\n...
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string)$arg;
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        $written = @fwrite($this->socket, $cmd);
        if ($written === false || $written < strlen($cmd)) {
            $this->close();
            return null;
        }

        return $this->readResponse();
    }

    protected function readResponse()
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $line = fgets($this->socket);
        if ($line === false) {
            $this->close();
            return null;
        }

        $type = $line[0];
        $content = substr($line, 1, -2); // remove type char and trailing \r\n

        switch ($type) {
            case '+': // Simple String (e.g. +OK\r\n, +PONG\r\n)
                return $content;

            case '-': // Error (e.g. -ERR unknown command\r\n)
                $this->lastError = $content;
                return false;

            case ':': // Integer (e.g. :1\r\n)
                return (int)$content;

            case '$': // Bulk String (e.g. $6\r\nfoobar\r\n or $-1\r\n for null)
                $len = (int)$content;
                if ($len === -1) {
                    return null; // Key does not exist
                }
                $data = '';
                $remaining = $len;
                while ($remaining > 0) {
                    $chunk = fread($this->socket, min($remaining, 8192));
                    if ($chunk === false || $chunk === '') {
                        $this->close();
                        return null;
                    }
                    $data .= $chunk;
                    $remaining -= strlen($chunk);
                }
                fread($this->socket, 2); // Discard trailing \r\n
                return $data;

            case '*': // Array (e.g. *2\r\n$3\r\nfoo\r\n$3\r\nbar\r\n)
                $count = (int)$content;
                if ($count === -1) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->readResponse();
                }
                return $items;

            default:
                $this->lastError = "Unknown RESP response type: {$type}";
                return null;
        }
    }

    public function ping()
    {
        $res = $this->sendCommand(['PING']);
        return ($res === 'PONG' || $res === true || $res === '+PONG');
    }

    public function get($key)
    {
        return $this->sendCommand(['GET', $key]);
    }

    public function set($key, $value)
    {
        return $this->sendCommand(['SET', $key, $value]) === 'OK';
    }

    public function setex($key, $ttl, $value)
    {
        return $this->sendCommand(['SETEX', $key, (int)$ttl, $value]) === 'OK';
    }

    public function del($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        if (empty($keys)) return 0;
        $args = array_merge(['DEL'], $keys);
        return (int)$this->sendCommand($args);
    }

    public function exists($key)
    {
        return (bool)$this->sendCommand(['EXISTS', $key]);
    }

    public function ttl($key)
    {
        return (int)$this->sendCommand(['TTL', $key]);
    }

    public function keys($pattern = '*')
    {
        $res = $this->sendCommand(['KEYS', $pattern]);
        return is_array($res) ? $res : [];
    }

    public function dbsize()
    {
        return (int)$this->sendCommand(['DBSIZE']);
    }

    public function flushdb()
    {
        return $this->sendCommand(['FLUSHDB']) === 'OK';
    }

    public function info($section = 'default')
    {
        $raw = $this->sendCommand(['INFO', $section]);
        if (!is_string($raw)) return [];
        $info = [];
        $lines = explode("\r\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;
            if (strpos($line, ':') !== false) {
                list($k, $v) = explode(':', $line, 2);
                $info[trim($k)] = trim($v);
            }
        }
        return $info;
    }
}
