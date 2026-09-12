<?php
/**
 * ==========================================================
 * JOB ROLES & SALARY TABLE - UNIVERSAL COMPONENT
 * File: job-roles-table-universal.php
 * ==========================================================
 * Course-wise global career job roles and salary table.
 * Data is managed dynamically via Admin Panel and stored in
 * the 'course_job_roles' database table.
 * 
 * Shortcode:
 *   [job_roles_table course="mba"]
 *   [job_roles course="mba"]
 *   [course_job_roles course="mba"]
 *   [job_roles_salary course="mba"]
 * ==========================================================
 */

// Ek hi baar load ho - prevent duplicate definition
if (defined('JOB_ROLES_TABLE_UNIVERSAL_LOADED')) {
    return;
}
define('JOB_ROLES_TABLE_UNIVERSAL_LOADED', true);

// Fallback Polyfills for standalone / SSR execution
if (!function_exists('shortcode_atts')) {
    function shortcode_atts($pairs, $atts, $shortcode = '')
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($pairs as $name => $default) {
            if (array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            } else {
                $out[$name] = $default;
            }
        }
        return $out;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 999999)
    {
        return mt_rand($min, $max);
    }
}

// Settings
if (!defined('JOB_ROLES_TABLE_VISIBLE_ROWS')) {
    define('JOB_ROLES_TABLE_VISIBLE_ROWS', 10);
}
if (!defined('JOB_ROLES_CENTRAL_API_URL')) {
    define('JOB_ROLES_CENTRAL_API_URL', 'https://admin.distanceeducationschool.com/admin/api/get_job_roles.php');
}

/**
 * Fetch course job roles data from Database or Central API
 */
function get_job_roles_table_data($course_key)
{
    static $cache = [];
    $course_key = strtolower(trim($course_key));

    if (empty($course_key)) {
        $course_key = 'mba';
    }

    if (isset($cache[$course_key])) {
        return $cache[$course_key];
    }

    // 1. Try Local Database Connection
    if (!function_exists('get_db_connection')) {
        $possible_configs = [
            __DIR__ . '/admin/config/config.php',
            dirname(__DIR__) . '/admin/config/config.php',
            dirname(__DIR__, 2) . '/admin/config/config.php',
        ];
        foreach ($possible_configs as $cfg_file) {
            if (file_exists($cfg_file)) {
                require_once $cfg_file;
                break;
            }
        }
    }

    if (function_exists('get_db_connection')) {
        try {
            $db = get_db_connection();
            if ($db) {
                $stmt = $db->prepare("
                    SELECT id, course_slug, course_name, heading, description, roles_json 
                    FROM course_job_roles 
                    WHERE LOWER(course_slug) = LOWER(?) OR LOWER(course_name) = LOWER(?)
                    LIMIT 1
                ");
                $stmt->execute([$course_key, $course_key]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $roles = [];
                    if (!empty($row['roles_json'])) {
                        $decoded = json_decode($row['roles_json'], true);
                        if (is_array($decoded)) {
                            $roles = $decoded;
                        }
                    }

                    $data = [
                        'course_slug' => $row['course_slug'],
                        'course_name' => $row['course_name'],
                        'heading' => $row['heading'],
                        'description' => $row['description'],
                        'columns' => ["Job Role", "Role Description", "Salary Range in India"],
                        'roles' => $roles
                    ];
                    $cache[$course_key] = $data;
                    return $data;
                }
            }
        } catch (Exception $e) {
            // Error connecting to DB, proceed to API fallback
        }
    }

    // 2. Central API Fallback (for remote WordPress subdomains without direct DB access)
    $api_url = JOB_ROLES_CENTRAL_API_URL . '?course=' . urlencode($course_key);
    $json_content = null;

    if (function_exists('wp_remote_get')) {
        $response = wp_remote_get($api_url, [
            'timeout' => 8,
            'headers' => ['Cache-Control' => 'no-cache']
        ]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $json_content = wp_remote_retrieve_body($response);
        }
    }

    if (!$json_content && function_exists('file_get_contents')) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 5]
        ]);
        $json_content = @file_get_contents($api_url, false, $ctx);
    }

    if ($json_content) {
        $api_res = json_decode($json_content, true);
        if (!empty($api_res['success']) && !empty($api_res['roles'])) {
            $data = [
                'course_slug' => $api_res['course_slug'] ?? $course_key,
                'course_name' => $api_res['course_name'] ?? strtoupper($course_key),
                'heading' => $api_res['heading'] ?? '',
                'description' => $api_res['description'] ?? '',
                'columns' => $api_res['columns'] ?? ["Job Role", "Role Description", "Salary Range in India"],
                'roles' => $api_res['roles']
            ];
            $cache[$course_key] = $data;
            return $data;
        }
    }

    return false;
}

/**
 * Render single row cells
 */
function render_job_roles_table_row_cells($role)
{
    ?>
    <td class="job-roles-td-title">
        <?php if (!empty($role['link'])): ?>
            <a href="<?php echo esc_url($role['link']); ?>" target="_blank" rel="noopener noreferrer"
                class="job-roles-table-role-link">
                <?php echo esc_html($role['role']); ?>
            </a>
        <?php else: ?>
            <strong><?php echo esc_html($role['role']); ?></strong>
        <?php endif; ?>
    </td>
    <td class="job-roles-td-desc"><?php echo esc_html($role['description']); ?></td>
    <td class="job-roles-td-salary"><?php echo esc_html($role['salary']); ?></td>
    <?php
}

/**
 * Main Shortcode & SSR Renderer: [job_roles_table course="mba"]
 */
function sode_job_roles_table_render($atts)
{
    $atts = shortcode_atts([
        'course' => 'mba',
    ], $atts);

    $course_key = strtolower(trim($atts['course']));
    if (empty($course_key)) {
        $course_key = 'mba';
    }

    $course_data = get_job_roles_table_data($course_key);

    if (empty($course_data) || empty($course_data['roles'])) {
        return '<div class="job-roles-table-empty" style="padding:15px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; color:#6b7280; font-size:14px;"><em>Job roles data is currently being updated. Please check back shortly.</em></div>';
    }

    // Heading and Description
    $table_heading = isset($course_data['heading']) ? $course_data['heading'] : '';
    $table_description = isset($course_data['description']) ? $course_data['description'] : '';

    // Replace $YEAR$, {YEAR}, etc.
    $current_year = function_exists('get_site_year') ? get_site_year() : date('Y');
    $table_heading = str_ireplace(['$YEAR$', '{YEAR}', '{{YEAR}}'], $current_year, $table_heading);
    $table_description = str_ireplace(['$YEAR$', '{YEAR}', '{{YEAR}}'], $current_year, $table_description);

    $columns = !empty($course_data['columns']) ? $course_data['columns'] : ["Job Role", "Role Description", "Salary Range in India"];
    $roles = $course_data['roles'];
    $total_rows = count($roles);
    $visible_rows = JOB_ROLES_TABLE_VISIBLE_ROWS;
    $has_more = $total_rows > $visible_rows;

    static $table_instance = 0;
    $table_instance++;
    $unique_id = 'job-roles-table-' . preg_replace('/[^a-z0-9]/', '', $course_key) . '-' . $table_instance . '-' . wp_rand(100, 999);

    ob_start();
    ?>
    <div class="job-roles-table-wrapper" style="margin: 25px 0;">

        <?php if (!empty($table_heading)): ?>
            <h2 class="job-roles-table-heading"><?php echo esc_html($table_heading); ?></h2>
        <?php endif; ?>

        <?php if (!empty($table_description)): ?>
            <p class="job-roles-table-description"><?php echo esc_html($table_description); ?></p>
        <?php endif; ?>

        <div class="job-roles-table-scroll">
            <table class="job-roles-fees-table" id="<?php echo esc_attr($unique_id); ?>">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col_label): ?>
                            <th><?php echo esc_html(strtoupper($col_label)); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $index => $role):
                        if ($index >= $visible_rows) {
                            break;
                        }
                        ?>
                        <tr>
                            <?php render_job_roles_table_row_cells($role); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($has_more): ?>
                    <tbody class="job-roles-table-extra-rows" style="display:none;">
                        <?php foreach ($roles as $index => $role):
                            if ($index < $visible_rows) {
                                continue;
                            }
                            ?>
                            <tr>
                                <?php render_job_roles_table_row_cells($role); ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($has_more): ?>
            <div class="job-roles-table-btn-wrap">
                <button type="button" class="job-roles-table-toggle-btn" data-target="<?php echo esc_attr($unique_id); ?>">
                    Read More
                </button>
            </div>
        <?php endif; ?>

    </div>
    <?php

    // Print CSS and JS once per page load
    static $job_roles_assets_rendered = false;
    if (!$job_roles_assets_rendered) {
        $job_roles_assets_rendered = true;
        ?>
        <style>
            .job-roles-table-wrapper {
                width: 100%;
                box-sizing: border-box;
                font-family: inherit;
            }

            .job-roles-table-heading {
                font-size: 24px;
                font-weight: 600;
                line-height: 1.3;
                margin: 0 0 10px 0;
                color: #111827;
            }

            .job-roles-table-description {
                font-size: 13px;
                line-height: 19px;
                color: #000;
                margin: 0 0 18px 0;
            }

            .job-roles-table-scroll {
                width: 100%;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                background: #ffffff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }

            .job-roles-fees-table {
                width: 100%;
                min-width: 650px;
                border-collapse: collapse;
                font-size: 13.5px;
                margin: 0;
                background-color: #ffffff;
            }

            .job-roles-fees-table thead th {
                background-color: #ebf3fc;
                color: #000;
                text-align: left;
                padding: 14px 16px;
                font-weight: 700;
                white-space: nowrap;
                border-bottom: 1px solid #bfdbfe;
                border-right: 1px solid #c7d9f5;
                font-size: 13px;
                letter-spacing: 0.02em;
            }

            .job-roles-fees-table thead th:last-child {
                border-right: none;
            }

            .job-roles-fees-table tbody td {
                padding: 12px 16px;
                border-bottom: 1px solid #eef0f3;
                border-right: 1px solid #eef0f3;
                vertical-align: middle;
                color: #1f2937;
                line-height: 1.45;
            }

            .job-roles-fees-table tbody td:last-child {
                border-right: none;
            }

            .job-roles-fees-table tbody tr:last-child td {
                border-bottom: none;
            }

            .job-roles-fees-table tbody tr:hover {
                background-color: #f8fafc;
            }

            .job-roles-td-title {
                font-weight: 700;
                color: #0f172a;
            }

            .job-roles-td-salary {
                font-weight: 600;
                color: #047857;
                white-space: nowrap;
            }

            .job-roles-table-role-link {
                color: #2563eb;
                text-decoration: none;
                font-weight: 700;
                transition: color 0.15s ease;
            }

            .job-roles-table-role-link:hover {
                color: #1d4ed8;
                text-decoration: underline;
            }

            .job-roles-table-btn-wrap {
                text-align: center;
                margin-top: 18px;
            }

            .job-roles-table-toggle-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 10px 28px;
                background-color: #2563eb;
                color: #ffffff;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 700;
                font-size: 14px;
                line-height: 1;
                transition: all 0.2s ease;
                box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
            }

            .job-roles-table-toggle-btn:hover {
                background-color: #1d4ed8;
                box-shadow: 0 4px 8px rgba(37, 99, 235, 0.3);
                transform: translateY(-1px);
            }

            .job-roles-table-toggle-btn:active {
                transform: translateY(0);
            }

            @media (max-width: 600px) {
                .job-roles-table-heading {
                    font-size: 20px;
                }

                .job-roles-fees-table {
                    font-size: 13px;
                }

                .job-roles-fees-table thead th,
                .job-roles-fees-table tbody td {
                    padding: 10px 12px;
                }
            }
        </style>
        <script>
            (function () {
                if (window.sodeJobRolesTableInitialized) return;
                window.sodeJobRolesTableInitialized = true;

                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.job-roles-table-toggle-btn');
                    if (!btn) return;

                    var tableId = btn.getAttribute('data-target');
                    var table = document.getElementById(tableId);
                    if (!table) return;

                    var extraTbody = table.querySelector('.job-roles-table-extra-rows');
                    if (!extraTbody) return;

                    var isHidden = extraTbody.style.display === 'none' || getComputedStyle(extraTbody).display === 'none';
                    if (isHidden) {
                        extraTbody.style.display = 'table-row-group';
                        btn.textContent = 'Read Less';
                    } else {
                        extraTbody.style.display = 'none';
                        btn.textContent = 'Read More';
                    }
                });
            })();
        </script>
        <?php
    }

    return ob_get_clean();
}

/**
 * Backward compatibility function for shortcode
 */
function job_roles_table_shortcode($atts)
{
    return sode_job_roles_table_render($atts);
}

// Register shortcodes if WordPress environment is active
if (function_exists('add_shortcode')) {
    add_shortcode('job_roles_table', 'sode_job_roles_table_render');
    add_shortcode('job_roles', 'sode_job_roles_table_render');
    add_shortcode('course_job_roles', 'sode_job_roles_table_render');
    add_shortcode('job_roles_salary', 'sode_job_roles_table_render');
}
