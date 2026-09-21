<?php
/**
 * Universal Helper Functions
 */

function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die("CSRF validation failed.");
    }
}

function set_flash_message($arg1, $arg2 = 'success') {
    if (in_array($arg1, ['success', 'error', 'info', 'warning'])) {
        $type = $arg1;
        $message = $arg2;
    } else {
        $message = $arg1;
        $type = $arg2;
    }
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function display_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        $class = $flash['type'] === 'error' ? 'alert-error' : ($flash['type'] === 'success' ? 'alert-success' : 'alert-info');
        return '<div class="admin-alert ' . $class . '">' . htmlspecialchars($flash['message']) . '<button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button></div>';
    }
    return '';
}

function get_user_initials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    if (!empty($parts[0])) $initials .= strtoupper(substr($parts[0], 0, 1));
    if (isset($parts[1])) $initials .= strtoupper(substr($parts[1], 0, 1));
    return !empty($initials) ? $initials : 'U';
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Dynamic Asset URL Resolver
 * Automatically resolves relative paths (e.g., uploads/2026/09/image.png)
 * to current environment base URL (localhost or live domain)
 */
function get_asset_url($path) {
    if (empty($path)) return '';
    $path = trim((string)$path);
    
    // External URLs not on localhost or admin domain (e.g., WordPress CDN, S3, main site)
    if (preg_match('#^https?://#i', $path)) {
        if (strpos($path, 'distanceeducationschool.com') !== false && strpos($path, 'admin.distanceeducationschool.com') === false) {
            return str_replace(' ', '%20', $path);
        }
        if (strpos($path, 'localhost') === false && strpos($path, 'admin.distanceeducationschool.com') === false) {
            return str_replace(' ', '%20', $path);
        }
    }
    
    // Strip domain and leading paths to extract clean subpath under uploads/
    $clean = preg_replace('#^https?://[^/]+(?:/[^/]+)*/(?:admin/)?uploads/#i', '', $path);
    $clean = preg_replace('#^(?:admin/)?uploads/#i', '', $clean);
    $clean = ltrim($clean, '/');
    
    // Determine admin base URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'admin.distanceeducationschool.com';
    
    if (strpos($host, 'localhost') !== false) {
        $base = defined('BASE_URL') ? BASE_URL : ($protocol . $host . '/subdomain_universal_codes/admin');
        if (strpos($base, '/admin') === false) {
            $base = rtrim($base, '/') . '/admin';
        }
    } else {
        $base = 'https://admin.distanceeducationschool.com/admin';
    }
    
    if (strpos($clean, 'assets/') === 0) {
        $final_url = rtrim($base, '/') . '/' . $clean;
    } else {
        $final_url = rtrim($base, '/') . '/uploads/' . $clean;
    }
    
    return str_replace(' ', '%20', $final_url);
}

/**
 * Convert any full URL or mixed path to clean relative path for database storage
 */
function get_relative_asset_path($path) {
    if (empty($path)) return '';
    $path = trim((string)$path);
    if (preg_match('#^https?://#i', $path)) {
        if (strpos($path, 'distanceeducationschool.com') !== false && strpos($path, 'admin.distanceeducationschool.com') === false) {
            return $path; // preserve external CDN URLs
        }
        if (strpos($path, 'localhost') === false && strpos($path, 'admin.distanceeducationschool.com') === false) {
            return $path; // preserve external CDN URLs
        }
    }
    $clean = preg_replace('#^https?://[^/]+(?:/[^/]+)*/(?:admin/)?uploads/#i', '', $path);
    $clean = preg_replace('#^(?:admin/)?uploads/#i', '', $clean);
    $clean = ltrim($clean, '/');
    if (strpos($clean, 'assets/') === 0) {
        return $clean;
    }
    return 'uploads/' . $clean;
}

/**
 * Ensures university_course_mappings has composite UNIQUE key (university_id, course_id, mode)
 * allowing the same course to be mapped in multiple modes (e.g., Online, Distance)
 */
if (!function_exists('sode_ensure_mapping_mode_unique_index')) {
    function sode_ensure_mapping_mode_unique_index(PDO $db) {
        static $migrated = false;
        if ($migrated) return;
        try {
            // 1. Ensure 'mode' column exists in university_course_mappings
            $mode_col = $db->query("SHOW COLUMNS FROM university_course_mappings LIKE 'mode'")->fetch();
            if (!$mode_col) {
                $db->exec("ALTER TABLE university_course_mappings ADD COLUMN mode VARCHAR(50) DEFAULT 'Online' AFTER course_id");
            }

            // 2. Check and fix indexes
            $indexes = $db->query("SHOW INDEX FROM university_course_mappings WHERE Key_name = 'uniq_uni_course'")->fetchAll();
            if ($indexes) {
                $has_mode = false;
                foreach ($indexes as $idx) {
                    if (($idx['Column_name'] ?? '') === 'mode') {
                        $has_mode = true;
                        break;
                    }
                }
                if (!$has_mode) {
                    $db->exec("ALTER TABLE university_course_mappings DROP INDEX uniq_uni_course");
                    $db->exec("ALTER TABLE university_course_mappings ADD UNIQUE KEY uniq_uni_course_mode (university_id, course_id, mode)");
                }
            } else {
                $has_composite = $db->query("SHOW INDEX FROM university_course_mappings WHERE Key_name = 'uniq_uni_course_mode'")->fetch();
                if (!$has_composite) {
                    $db->exec("ALTER TABLE university_course_mappings ADD UNIQUE KEY uniq_uni_course_mode (university_id, course_id, mode)");
                }
            }
            $migrated = true;
        } catch (Exception $e) {
            // Log silently or ignore if permissions restricted
        }
    }
}

/**
 * Pings each active university subdomain to clear WP Rocket + Redis + Elementor cache.
 * TRUE fire-and-forget — admin save page does NOT wait for responses.
 */
if (!function_exists('sode_bust_all_subdomain_caches')) {
    function sode_bust_all_subdomain_caches(PDO $db) {
        try {
            $unis = $db->query("SELECT slug FROM universities WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($unis as $slug) {
                $url = 'https://' . $slug . '.distanceeducationschool.com/?sode_flush=sode_flush_2026';
                sode_fire_and_forget($url);
            }
        } catch (Exception $e) {
            error_log('SODE cache bust failed: ' . $e->getMessage());
        }
    }
}

/**
 * Truly non-blocking HTTP GET — sends request, does NOT wait for response.
 * Admin page loads instantly regardless of subdomain response time.
 */
if (!function_exists('sode_fire_and_forget')) {
    function sode_fire_and_forget($url) {
        // Method 1: Background shell process (fastest, zero wait)
        if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                @pclose(@popen("start /B curl -k -s --max-time 5 \"" . addslashes($url) . "\" > NUL 2>&1", "r"));
            } else {
                @exec("curl -k -s --max-time 5 '" . addslashes($url) . "' > /dev/null 2>&1 &");
            }
            return;
        }

        // Method 2: cURL with 100ms timeout (effectively fire-and-forget)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => 100,   // 100ms max wait — then move on
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY         => true,   // Don't download body
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            @curl_exec($ch);
            curl_close($ch);
            return;
        }

        // Method 3: fsockopen (non-blocking socket, no wait for response)
        $parts = parse_url($url);
        $host  = $parts['host'] ?? '';
        $path  = ($parts['path'] ?? '/') . '?' . ($parts['query'] ?? '');
        $fp = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 1);
        if ($fp) {
            @fwrite($fp, "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
            @fclose($fp); // Close immediately — don't wait for response
        }
    }
}

/**
 * Universal Server-Side Pagination Helpers
 */
if (!function_exists('sode_get_pagination_params')) {
    function sode_get_pagination_params($default_per_page = 10, $allowed = [10, 25, 50, 100], $page_key = 'page', $per_page_key = 'per_page') {
        $page = isset($_GET[$page_key]) ? (int)$_GET[$page_key] : 1;
        if ($page < 1) $page = 1;

        $per_page = isset($_GET[$per_page_key]) ? (int)$_GET[$per_page_key] : $default_per_page;
        if (!in_array($per_page, $allowed)) {
            $per_page = $default_per_page;
        }

        $offset = ($page - 1) * $per_page;

        return [
            'page'     => $page,
            'per_page' => $per_page,
            'offset'   => $offset
        ];
    }
}

if (!function_exists('sode_render_pagination')) {
    function sode_render_pagination($total_records, $current_page, $per_page, $allowed = [10, 25, 50, 100], $page_key = 'page', $per_page_key = 'per_page') {
        $total_records = max(0, (int)$total_records);
        $per_page = max(1, (int)$per_page);
        $total_pages = max(1, (int)ceil($total_records / $per_page));
        $current_page = max(1, min((int)$current_page, $total_pages));

        // Build URL generator preserving current query params
        $queryParams = $_GET ?? [];

        $buildUrl = function($p, $pp = null) use ($queryParams, $page_key, $per_page_key, $per_page) {
            $params = $queryParams;
            $params[$page_key] = $p;
            $params[$per_page_key] = ($pp !== null) ? $pp : $per_page;
            return '?' . http_build_query($params);
        };

        // Determine page numbers range with ellipsis
        $pages = [];
        if ($total_pages <= 7) {
            for ($i = 1; $i <= $total_pages; $i++) {
                $pages[] = $i;
            }
        } else {
            if ($current_page <= 4) {
                $pages = [1, 2, 3, 4, 5, '...', $total_pages];
            } elseif ($current_page >= $total_pages - 3) {
                $pages = [1, '...', $total_pages - 4, $total_pages - 3, $total_pages - 2, $total_pages - 1, $total_pages];
            } else {
                $pages = [1, '...', $current_page - 1, $current_page, $current_page + 1, '...', $total_pages];
            }
        }

        ob_start();
        ?>
        <div class="sode-pagination-bar">
            <div class="sode-pagination-left">
                <span class="sode-pagination-total">Total Records: <?php echo number_format($total_records); ?></span>
            </div>
            <div class="sode-pagination-right">
                <!-- Previous Button -->
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo htmlspecialchars($buildUrl($current_page - 1)); ?>" class="sode-page-link sode-page-nav" title="Previous Page">&lt;</a>
                <?php else: ?>
                    <span class="sode-page-link sode-page-nav disabled">&lt;</span>
                <?php endif; ?>

                <!-- Page numbers & ellipsis -->
                <?php foreach ($pages as $p): ?>
                    <?php if ($p === '...'): ?>
                        <span class="sode-page-ellipsis">&hellip;</span>
                    <?php elseif ($p == $current_page): ?>
                        <span class="sode-page-link active"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars($buildUrl($p)); ?>" class="sode-page-link"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <!-- Next Button -->
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo htmlspecialchars($buildUrl($current_page + 1)); ?>" class="sode-page-link sode-page-nav" title="Next Page">&gt;</a>
                <?php else: ?>
                    <span class="sode-page-link sode-page-nav disabled">&gt;</span>
                <?php endif; ?>

                <!-- Per Page Select Dropdown -->
                <div class="sode-per-page-wrap">
                    <select class="sode-per-page-select" onchange="window.location.href=this.value;" title="Records per page">
                        <?php foreach ($allowed as $cnt): ?>
                            <option value="<?php echo htmlspecialchars($buildUrl(1, $cnt)); ?>" <?php echo ($cnt == $per_page) ? 'selected' : ''; ?>>
                                <?php echo $cnt; ?> / page
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('get_site_branding')) {
    function get_site_branding() {
        static $branding = null;
        if ($branding !== null) return $branding;

        $branding = [
            'site_logo_url'       => '',
            'site_logo_dark_url'  => '',
            'admin_logo_url'      => '',
            'favicon_url'         => '',
            'site_name'           => defined('APP_NAME') ? APP_NAME : 'SODE Admin'
        ];

        try {
            $db = get_db_connection();
            $rows = $db->query("SELECT setting_key, setting_value FROM global_settings WHERE setting_group IN ('branding', 'general') OR setting_key LIKE '%logo%' OR setting_key LIKE '%favicon%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            if ($rows) {
                if (!empty($rows['site_logo_url'])) $branding['site_logo_url'] = $rows['site_logo_url'];
                if (!empty($rows['site_logo_dark_url'])) $branding['site_logo_dark_url'] = $rows['site_logo_dark_url'];
                if (!empty($rows['admin_logo_url'])) $branding['admin_logo_url'] = $rows['admin_logo_url'];
                if (!empty($rows['site_favicon_url'])) $branding['favicon_url'] = $rows['site_favicon_url'];
            }
        } catch (Exception $e) {}

        // Fallback to global_keys if empty
        if (empty($branding['site_logo_url'])) {
            try {
                $db = get_db_connection();
                $k = $db->query("SELECT key_value FROM global_keys WHERE key_code IN ('\$SITE_LOGO\$', '\$LOGO_URL\$', 'LOGO_URL') AND is_active = 1 LIMIT 1")->fetch();
                if ($k && !empty($k['key_value'])) {
                    $branding['site_logo_url'] = $k['key_value'];
                }
            } catch (Exception $e) {}
        }

        return $branding;
    }
}
