<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$can_edit = user_can('update') || user_can('write') || is_superadmin();

$page_title = 'Redis In-Memory Cache Manager';
$page_subtitle = 'Monitor real-time Redis server health, RAM utilization, live key inspection, and instant cache invalidation';
$active_page_key = 'redis_manager';

$db = get_db_connection();

// Auto-register sidebar item if missing
try {
    $sb_chk = $db->query("SELECT id FROM sidebar_items WHERE active_page_key = 'redis_manager' OR page_route LIKE '%settings/redis.php%'")->fetch();
    if (!$sb_chk) {
        $icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path></svg>';
        $stmt = $db->prepare("
            INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active) 
            VALUES ('Redis Cache', 'modules/settings/redis.php', 35, 'redis_manager', 'settings', 'SETTINGS', ?, 0, 1)
        ");
        $stmt->execute([$icon]);
        $new_sb_id = $db->lastInsertId();
        if ($new_sb_id) {
            $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($roles)) {
                $rsa = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
                foreach ($roles as $rid) {
                    $rsa->execute([$rid, $new_sb_id]);
                }
            }
        }
    }
} catch (Exception $e) {
}

// Handle POST / Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'flush_all') {
        if (!$can_edit) {
            set_flash_message('Access Denied: You do not have permission to flush cache.', 'error');
            redirect(BASE_URL . '/modules/settings/redis.php');
        }

        if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
            Sode_Redis::flushAll();
            sode_bust_all_subdomain_caches($db);
            if (function_exists('log_activity')) {
                log_activity('PURGE', 'redis', 'Purged all Redis in-memory cache keys across all subdomains');
            }
            set_flash_message('⚡ All Redis caches flushed successfully! Subdomains will now fetch fresh data.', 'success');
        } else {
            set_flash_message('Redis server is currently offline or unreachable.', 'error');
        }
        redirect(BASE_URL . '/modules/settings/redis.php');
    }

    if ($action === 'flush_pattern') {
        if (!$can_edit) {
            set_flash_message('Permission Denied.', 'error');
            redirect(BASE_URL . '/modules/settings/redis.php');
        }

        $pattern = trim($_POST['pattern'] ?? '*');
        if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
            $deleted = Sode_Redis::flushPattern($pattern);
            if (function_exists('log_activity')) {
                log_activity('PURGE', 'redis', "Flushed Redis cache pattern: '{$pattern}' ({$deleted} keys deleted)");
            }
            set_flash_message("⚡ Successfully deleted {$deleted} keys matching pattern '{$pattern}'.", 'success');
        } else {
            set_flash_message('Redis server is offline.', 'error');
        }
        redirect(BASE_URL . '/modules/settings/redis.php');
    }

    if ($action === 'delete_single_key') {
        if (!$can_edit) {
            set_flash_message('Permission Denied.', 'error');
            redirect(BASE_URL . '/modules/settings/redis.php');
        }

        $key = trim($_POST['key'] ?? '');
        if (!empty($key) && class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
            Sode_Redis::delRaw($key);
            set_flash_message("Key '{$key}' removed from Redis cache.", 'success');
        }
        redirect(BASE_URL . '/modules/settings/redis.php');
    }

    if ($action === 'test_cache') {
        if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
            $t0 = microtime(true);
            $testVal = 'SODE Benchmark ' . date('Y-m-d H:i:s') . ' [' . microtime(true) . ']';
            Sode_Redis::set('test:benchmark', $testVal, 300);
            $readBack = Sode_Redis::get('test:benchmark');
            $roundtrip_ms = round((microtime(true) - $t0) * 1000, 3);

            if ($readBack === $testVal) {
                set_flash_message("✅ Redis Diagnostic PASSED! Socket Write & Read roundtrip took {$roundtrip_ms} ms.", 'success');
            } else {
                set_flash_message("⚠️ Diagnostic Write succeeded but Read returned mismatch.", 'warning');
            }
        } else {
            set_flash_message("❌ Cannot run diagnostic test: Redis server is not running on " . (defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1') . ":" . (defined('REDIS_PORT') ? REDIS_PORT : 6379), 'error');
        }
        redirect(BASE_URL . '/modules/settings/redis.php');
    }
}

// Fetch Diagnostic Stats
$info = class_exists('Sode_Redis') ? Sode_Redis::getInfo() : ['status' => 'offline', 'driver' => 'none'];
$is_connected = ($info['status'] === 'connected');

// Measure Ping Latency
$ping_ms = null;
if ($is_connected) {
    $t_start = microtime(true);
    $ping_ok = Sode_Redis::isAvailable();
    $ping_ms = round((microtime(true) - $t_start) * 1000, 2);
}

// Parse App Keys with details
$keys_detailed = [];
if ($is_connected && !empty($info['app_keys'])) {
    $client = Sode_Redis::client();
    foreach ($info['app_keys'] as $k) {
        $ttl = ($client && method_exists($client, 'ttl')) ? $client->ttl($k) : -1;

        $type = 'STRING';
        if ($client && method_exists($client, 'type')) {
            $t = $client->type($k);
            if ($t === 1 || $t === 'string')
                $type = 'STRING';
            elseif ($t === 2 || $t === 'set')
                $type = 'SET';
            elseif ($t === 3 || $t === 'list')
                $type = 'LIST';
            elseif ($t === 4 || $t === 'zset')
                $type = 'ZSET';
            elseif ($t === 5 || $t === 'hash')
                $type = 'HASH';
        }

        $category = 'General';
        if (strpos($k, ':api:') !== false)
            $category = 'API Endpoint';
        elseif (strpos($k, ':ssr:') !== false)
            $category = 'SSR Shortcode';
        elseif (strpos($k, ':admin:') !== false)
            $category = 'Admin Panel';
        elseif (strpos($k, ':courses_data:') !== false)
            $category = 'Courses Data';
        elseif (strpos($k, ':test:') !== false)
            $category = 'Diagnostics';

        $keys_detailed[] = [
            'name' => $k,
            'ttl' => $ttl,
            'type' => $type,
            'category' => $category,
        ];
    }
}

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
    /* Modern Redis Dashboard Styles (Dark & Light Theme Compatible) */
    .redis-grid-container {
        display: grid;
        grid-template-columns: 1fr 1.35fr;
        gap: 24px;
        margin-top: 24px;
    }

    @media (max-width: 1100px) {
        .redis-grid-container {
            grid-template-columns: 1fr;
        }
    }

    .redis-pulse-live {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        position: relative;
        flex-shrink: 0;
    }

    .redis-pulse-live.online {
        background: #10b981;
        box-shadow: 0 0 10px #10b981;
    }

    .redis-pulse-live.online::after {
        content: '';
        position: absolute;
        inset: -3px;
        border-radius: 50%;
        border: 2px solid #10b981;
        animation: redisPulse 2s infinite ease-out;
    }

    @keyframes redisPulse {
        0% {
            transform: scale(1);
            opacity: 0.8;
        }

        100% {
            transform: scale(2.2);
            opacity: 0;
        }
    }

    .redis-pulse-live.offline {
        background: #ef4444;
        box-shadow: 0 0 8px #ef4444;
    }

    .purge-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--bg-input);
        border: 1px solid var(--border-color);
        color: var(--text-main);
        padding: 8px 14px;
        border-radius: var(--radius-sm);
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
    }

    .purge-pill-btn:hover {
        background: rgba(239, 68, 68, 0.12);
        border-color: #ef4444;
        color: #ef4444;
        transform: translateY(-1px);
    }

    .category-tag {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.3px;
    }

    .cat-api {
        background: rgba(59, 130, 246, 0.15);
        color: #60a5fa;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .cat-ssr {
        background: rgba(139, 92, 246, 0.15);
        color: #a78bfa;
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .cat-admin {
        background: rgba(16, 185, 129, 0.15);
        color: #34d399;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .cat-courses {
        background: rgba(245, 158, 11, 0.15);
        color: #fbbf24;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .cat-general {
        background: rgba(100, 116, 139, 0.15);
        color: #94a3b8;
        border: 1px solid rgba(100, 116, 139, 0.3);
    }

    .guide-box {
        background: linear-gradient(135deg, rgba(79, 70, 229, 0.08), rgba(59, 130, 246, 0.04));
        border: 1px solid rgba(79, 70, 229, 0.25);
        border-radius: var(--radius-md);
        padding: 16px 18px;
        margin-top: 16px;
    }
</style>

<!-- 1. Top 4 Metric Cards (Native Stats Grid Layout) -->
<div class="stats-grid">
    <!-- Card 1: Connection Status -->
    <div class="stat-card <?php echo $is_connected ? 'accent-emerald' : 'accent-rose'; ?>">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Redis Status</div>
                    <div class="stat-number" style="display:flex; align-items:center; gap:10px; font-size: 20px;">
                        <span class="redis-pulse-live <?php echo $is_connected ? 'online' : 'offline'; ?>"></span>
                        <?php echo $is_connected ? 'Connected' : 'Offline'; ?>
                    </div>
                </div>
                <div class="stat-icon-wrap"
                    style="color:<?php echo $is_connected ? '#10b981' : '#ef4444'; ?>; background:<?php echo $is_connected ? 'rgba(16,185,129,0.14)' : 'rgba(239,68,68,0.14)'; ?>;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">
                Driver: <strong><?php echo htmlspecialchars($info['driver'] ?? 'none'); ?></strong>
                <?php if (!empty($info['redis_version']) && $info['redis_version'] !== 'Unknown'): ?>
                    &bull; v<?php echo htmlspecialchars($info['redis_version']); ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">
                <?php echo $is_connected ? 'Auto-Fallback Standby' : 'Fallback to MySQL DB'; ?>
            </span>
            <span style="color:<?php echo $is_connected ? '#10b981' : '#ef4444'; ?>; font-weight:600;">
                <?php echo $is_connected ? 'Active ⚡' : 'Offline ⚠️'; ?>
            </span>
        </div>
    </div>

    <!-- Card 2: Ping Latency -->
    <div class="stat-card accent-indigo">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Ping Latency</div>
                    <div class="stat-number" style="color:#6366f1;">
                        <?php echo $ping_ms !== null ? $ping_ms . ' <span style="font-size:14px; font-weight:500;">ms</span>' : 'N/A'; ?>
                    </div>
                </div>
                <div class="stat-icon-wrap" style="color:#6366f1; background:rgba(99,102,241,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 14 14"></polyline>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">
                Host:
                <code><?php echo htmlspecialchars(defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1'); ?>:<?php echo htmlspecialchars(defined('REDIS_PORT') ? REDIS_PORT : 6379); ?></code>
            </div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Socket RESP Stream</span>
            <span style="color:#6366f1; font-weight:600;">
                <?php echo ($ping_ms !== null && $ping_ms < 2) ? 'Ultra Fast' : 'Localhost'; ?>
            </span>
        </div>
    </div>

    <!-- Card 3: Cached App Keys -->
    <div class="stat-card accent-purple">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Cached App Keys</div>
                    <div class="stat-number" style="color:#a855f7;">
                        <?php echo htmlspecialchars($info['app_keys_count'] ?? 0); ?>
                        <span style="font-size:14px; font-weight:500; color:var(--text-dim);">Keys</span>
                    </div>
                </div>
                <div class="stat-icon-wrap" style="color:#a855f7; background:rgba(168,85,247,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="3" y1="9" x2="21" y2="9"></line>
                        <line x1="9" y1="21" x2="9" y2="9"></line>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">
                Prefix: <code><?php echo htmlspecialchars(defined('REDIS_PREFIX') ? REDIS_PREFIX : 'sode:'); ?></code>
                (Isolated)
            </div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Total DB Keys:
                <?php echo htmlspecialchars($info['total_keys_in_db'] ?? 0); ?></span>
            <span style="color:#a855f7; font-weight:600;">In RAM</span>
        </div>
    </div>

    <!-- Card 4: Memory Usage -->
    <div class="stat-card accent-amber">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">RAM Memory Usage</div>
                    <div class="stat-number" style="color:#f59e0b;">
                        <?php echo htmlspecialchars($info['used_memory_human'] ?? '0 MB'); ?>
                    </div>
                </div>
                <div class="stat-icon-wrap" style="color:#f59e0b; background:rgba(245,158,11,0.14);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">
                Peak: <strong><?php echo htmlspecialchars($info['used_memory_peak_human'] ?? '0 MB'); ?></strong>
            </div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Uptime:
                <?php echo htmlspecialchars($info['uptime_days'] ?? 'N/A'); ?></span>
            <span style="color:#f59e0b; font-weight:600;">High Speed</span>
        </div>
    </div>
</div>

<?php if (!$is_connected): ?>
    <!-- Connection Notice Card if Offline -->
    <div class="admin-card" style="margin-bottom: 24px; border-left: 4px solid #f59e0b;">
        <div class="card-body" style="padding: 20px;">
            <div style="display:flex; align-items:flex-start; gap:16px;">
                <div
                    style="width:40px; height:40px; border-radius:10px; background:rgba(245,158,11,0.14); color:#f59e0b; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <div style="flex:1;">
                    <h4 style="font-size:15px; font-weight:700; color:var(--text-main); margin:0 0 4px;">Redis Server is
                        Currently Offline on Localhost</h4>
                    <p style="font-size:13px; color:var(--text-muted); margin:0 0 12px; line-height:1.5;">
                        Your codebase has full Redis caching built-in across all controllers and APIs. Because Redis is not
                        running locally on port <code>6379</code> right now, the application automatically falls back to
                        MySQL database with <strong>zero downtime</strong>.
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:12px; font-size:12.5px;">
                        <div
                            style="background:var(--bg-input); padding:8px 14px; border-radius:6px; border:1px solid var(--border-color);">
                            <strong>Windows Localhost:</strong> Start Redis via <code>redis-server.exe</code> or WSL /
                            Memurai.
                        </div>
                        <div
                            style="background:var(--bg-input); padding:8px 14px; border-radius:6px; border:1px solid var(--border-color);">
                            <strong>Live Server (VPS / cPanel):</strong> Run <code>sudo systemctl start redis-server</code>
                            on server.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- 2. Main 2-Column Grid -->
<div class="redis-grid-container">
    <!-- Left Column: Operations & Credentials -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        <!-- Card 1: Cache Flush Operations -->
        <div class="admin-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:8px; height:8px; border-radius:50%; background:#ef4444;"></div>
                    <span class="card-title">Cache Operations Center</span>
                </div>
                <span class="badge badge-primary">Instant Invalidation</span>
            </div>
            <div class="card-body">
                <p style="font-size:13px; color:var(--text-muted); line-height:1.5; margin:0 0 16px;">
                    Redis caches all API responses, shortcode HTML, and database metrics. Whenever you edit data in the
                    admin panel, caches are auto-purged. You can also manually flush specific groups below:
                </p>

                <!-- 1-Click Flush All -->
                <form method="POST"
                    onsubmit="return confirm('⚠️ Are you sure you want to PURGE ALL Redis caches across all subdomains and APIs?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="flush_all">
                    <button type="submit" class="btn-primary"
                        style="width:100%; background:linear-gradient(135deg, #ef4444, #dc2626); border-color:#dc2626; padding:11px 18px; font-weight:700; font-size:13.5px; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 12px rgba(220,38,38,0.3);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <polyline points="3 6 5 6 21 6"></polyline>
                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                            </path>
                        </svg>
                        Purge Entire Redis Cache (1-Click)
                    </button>
                </form>

                <div style="margin:20px 0 12px; font-size:12.5px; font-weight:700; color:var(--text-main);">
                    Targeted Category Purge:
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="flush_pattern">
                        <input type="hidden" name="pattern" value="api:*">
                        <button type="submit" class="purge-pill-btn" title="Purge all API cached endpoints">
                            🌐 Flush APIs (api:*)
                        </button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="flush_pattern">
                        <input type="hidden" name="pattern" value="ssr:*">
                        <button type="submit" class="purge-pill-btn" title="Purge shortcode HTML render cache">
                            🖥️ Flush SSR (ssr:*)
                        </button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="flush_pattern">
                        <input type="hidden" name="pattern" value="courses_data:*">
                        <button type="submit" class="purge-pill-btn" title="Purge university courses datasets">
                            🎓 Flush Courses Data
                        </button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="flush_pattern">
                        <input type="hidden" name="pattern" value="admin:*">
                        <button type="submit" class="purge-pill-btn" title="Purge dashboard and metrics cache">
                            📊 Flush Dashboard Stats
                        </button>
                    </form>
                </div>

                <div style="margin-top:16px;">
                    <form method="POST" style="display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="flush_pattern">
                        <input type="text" name="pattern" class="form-control"
                            placeholder="Custom wildcard (e.g. api:global_keys*)"
                            style="font-size:12.5px; padding:9px 14px; margin:0; width:100%; height:40px; box-sizing:border-box;" required>
                        <button type="submit" class="btn-secondary"
                            style="height:40px; padding:0 20px; font-size:12.5px; font-weight:600; white-space:nowrap; width:auto; display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; cursor:pointer;">
                            Flush Pattern
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Card 2: Diagnostic Benchmark Test -->
        <div class="admin-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:8px; height:8px; border-radius:50%; background:#3b82f6;"></div>
                    <span class="card-title">Live Diagnostic & Latency Test</span>
                </div>
            </div>
            <div class="card-body">
                <p style="font-size:12.5px; color:var(--text-muted); margin:0 0 14px;">
                    Executes an automated roundtrip socket read/write payload test with microsecond precision benchmark.
                </p>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="test_cache">
                    <button type="submit" class="btn-primary"
                        style="width:100%; padding:10px 16px; font-size:13px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 14 14"></polyline>
                        </svg>
                        Run Live Read / Write Benchmark
                    </button>
                </form>
            </div>
        </div>

        <!-- Card 3: Environment Credentials (.env) -->
        <div class="admin-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:8px; height:8px; border-radius:50%; background:#10b981;"></div>
                    <span class="card-title">Redis Credentials (.env)</span>
                </div>
                <span class="badge badge-success" style="font-size:11px;">Protected</span>
            </div>
            <div class="card-body">
                <p style="font-size:12px; color:var(--text-dim); margin:0 0 12px;">
                    All Redis variables are parsed securely from your server <code>.env</code> file (Git-ignored).
                </p>
                <div class="table-responsive">
                    <table class="admin-table" style="font-size:12px;">
                        <thead>
                            <tr>
                                <th>Variable</th>
                                <th>Configured Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>REDIS_ENABLED</code></td>
                                <td><span class="badge"
                                        style="background:<?php echo (defined('REDIS_ENABLED') && REDIS_ENABLED) ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'; ?>; color:<?php echo (defined('REDIS_ENABLED') && REDIS_ENABLED) ? '#10b981' : '#ef4444'; ?>; border:1px solid <?php echo (defined('REDIS_ENABLED') && REDIS_ENABLED) ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)'; ?>;"><?php echo (defined('REDIS_ENABLED') && REDIS_ENABLED) ? 'true' : 'false'; ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><code>REDIS_HOST</code></td>
                                <td><code><?php echo htmlspecialchars(defined('REDIS_HOST') ? REDIS_HOST : '127.0.0.1'); ?></code>
                                </td>
                            </tr>
                            <tr>
                                <td><code>REDIS_PORT</code></td>
                                <td><code><?php echo htmlspecialchars(defined('REDIS_PORT') ? REDIS_PORT : 6379); ?></code>
                                </td>
                            </tr>
                            <tr>
                                <td><code>REDIS_PASS</code></td>
                                <td><code><?php echo (defined('REDIS_PASS') && REDIS_PASS) ? '•••••••• (Protected)' : 'None (Localhost)'; ?></code>
                                </td>
                            </tr>
                            <tr>
                                <td><code>REDIS_PREFIX</code></td>
                                <td><code><?php echo htmlspecialchars(defined('REDIS_PREFIX') ? REDIS_PREFIX : 'sode:'); ?></code>
                                </td>
                            </tr>
                            <tr>
                                <td><code>REDIS_DEFAULT_TTL</code></td>
                                <td><code><?php echo htmlspecialchars(defined('REDIS_DEFAULT_TTL') ? REDIS_DEFAULT_TTL : 86400); ?>s (24 Hours)</code>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Live Cached Keys Inspector -->
    <div>
        <div class="admin-card" style="height:100%;">
            <div class="card-header"
                style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:8px; height:8px; border-radius:50%; background:#8b5cf6;"></div>
                    <span class="card-title">Live Cache Keys Inspector</span>
                </div>
                <span class="badge badge-info" id="keyCountBadge"><?php echo count($keys_detailed); ?> Active
                    Keys</span>
            </div>
            <div class="card-body">
                <!-- Search Box -->
                <div style="margin-bottom:16px;">
                    <input type="text" id="redisKeySearch" class="form-control"
                        placeholder="🔍 Search cached keys (e.g. api, courses, global_keys, ssr)..."
                        style="font-size:13px; padding:9px 14px;" onkeyup="filterRedisKeys();">
                </div>

                <?php if (empty($keys_detailed)): ?>
                    <div style="text-align:center; padding:50px 20px; color:var(--text-dim);">
                        <div style="font-size:38px; margin-bottom:12px;">⚡</div>
                        <div style="font-weight:700; font-size:16px; margin-bottom:6px; color:var(--text-main);">No Cached
                            Keys in RAM</div>
                        <div style="font-size:13px; max-width:380px; margin:0 auto; line-height:1.5;">
                            <?php if ($is_connected): ?>
                                Keys will automatically populate into Redis as visitors browse subdomain websites, APIs, and
                                shortcodes.
                            <?php else: ?>
                                Once Redis is started on port <code>6379</code>, cached keys will automatically appear here.
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:580px; overflow-y:auto;">
                        <table class="admin-table" id="redisKeysTable" style="font-size:12.5px;">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Key Name</th>
                                    <th style="width:110px;">TTL Remaining</th>
                                    <th style="width:50px; text-align:center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($keys_detailed as $kd):
                                    $cat_class = 'cat-general';
                                    if ($kd['category'] === 'API Endpoint')
                                        $cat_class = 'cat-api';
                                    elseif ($kd['category'] === 'SSR Shortcode')
                                        $cat_class = 'cat-ssr';
                                    elseif ($kd['category'] === 'Admin Panel')
                                        $cat_class = 'cat-admin';
                                    elseif ($kd['category'] === 'Courses Data')
                                        $cat_class = 'cat-courses';

                                    $ttl_str = 'No Expiry';
                                    if ($kd['ttl'] > 0) {
                                        $hrs = floor($kd['ttl'] / 3600);
                                        $mins = floor(($kd['ttl'] % 3600) / 60);
                                        $secs = $kd['ttl'] % 60;
                                        $ttl_str = ($hrs > 0 ? "{$hrs}h " : "") . ($mins > 0 ? "{$mins}m " : "") . "{$secs}s";
                                    } elseif ($kd['ttl'] === -2) {
                                        $ttl_str = 'Expired';
                                    }
                                    ?>
                                    <tr class="redis-key-row"
                                        data-key="<?php echo htmlspecialchars(strtolower($kd['name'])); ?>">
                                        <td>
                                            <span class="category-tag <?php echo $cat_class; ?>">
                                                <?php echo htmlspecialchars($kd['category']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <code
                                                style="font-weight:600; color:#60a5fa; word-break:break-all;"><?php echo htmlspecialchars($kd['name']); ?></code>
                                        </td>
                                        <td>
                                            <span style="font-size:11.5px; color:var(--text-muted); font-weight:600;">
                                                <?php echo htmlspecialchars($ttl_str); ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <form method="POST" onsubmit="return confirm('Delete this specific cache key?');"
                                                style="display:inline;">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="delete_single_key">
                                                <input type="hidden" name="key"
                                                    value="<?php echo htmlspecialchars($kd['name']); ?>">
                                                <button type="submit" class="action-btn delete-btn" title="Purge key"
                                                    style="border:none; cursor:pointer;">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2">
                                                        <polyline points="3 6 5 6 21 6"></polyline>
                                                        <path
                                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                        </path>
                                                    </svg>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function filterRedisKeys() {
        var input = document.getElementById('redisKeySearch');
        var filter = input.value.toLowerCase().trim();
        var rows = document.querySelectorAll('.redis-key-row');
        var visible = 0;

        rows.forEach(function (row) {
            var key = row.getAttribute('data-key') || '';
            if (key.indexOf(filter) > -1) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        var badge = document.getElementById('keyCountBadge');
        if (badge) {
            badge.innerText = visible + ' Matching Keys';
        }
    }
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>