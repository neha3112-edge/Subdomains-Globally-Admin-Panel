<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cache-Control, Pragma, Expires');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

// ── 0. Check Redis Cache FIRST (Zero DB Connections on Cache Hit) ──────
$uni_slug  = strtolower(trim($_GET['uni'] ?? ''));
$cache_key = "api:keys:" . ($uni_slug ?: 'global');

if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
    $cached = Sode_Redis::get($cache_key);
    if ($cached && is_array($cached)) {
        $cached['cached_by'] = 'Sode_Redis (' . Sode_Redis::getDriver() . ')';
        echo json_encode($cached, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ── Cache Miss: Connect to Database (Lazy Loading) ─────────────────────
$db = get_db_connection();
if (!$db) {
    // Graceful fallback if database is busy and cache was missing
    echo json_encode([
        'success' => true,
        'keys' => [
            '$YEAR$' => date('Y'),
            '$session$' => date('Y') . '-' . substr((string)((int)date('Y') + 1), -2),
            '$nextyear$' => (string)((int)date('Y') + 1),
            '$BANNER_TOP_TEXT$' => 'Welcome to SODE™ (School of Online and Distance Education)',
        ],
        'cached_by' => 'Fallback (DB Unavailable)'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── 1. Global Keys (always returned) ──────────────────────────────────
$cols = $db->query("SHOW COLUMNS FROM global_keys LIKE 'link_url'")->fetch();
$select_sql = $cols ? "SELECT key_code, key_value, link_url FROM global_keys WHERE is_active = 1" : "SELECT key_code, key_value, '' AS link_url FROM global_keys WHERE is_active = 1";
$global_rows = $db->query($select_sql)->fetchAll();
$map = [];
foreach ($global_rows as $k) {
    $code = $k['key_code'];
    $val  = (string)$k['key_value'];
    $url  = trim((string)($k['link_url'] ?? ''));

    $raw_code = strtoupper(trim($code, '$ '));

    if ($url !== '') {
        // If URL is set, the primary key automatically outputs as a clickable link!
        $map[$code] = '<a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($val) . '</a>';

        // Also provide plain text and raw URL keys
        $map['$' . $raw_code . '_TEXT$'] = $val;
        $map['{' . $raw_code . '_TEXT}'] = $val;
        $map['$' . $raw_code . '_URL$']  = $url;
        $map['{' . $raw_code . '_URL}']  = $url;
        $map['$' . $raw_code . '_LINK$'] = $url;
        $map['{' . $raw_code . '_LINK}'] = $url;
    } else {
        // Normal plain text key
        $map[$code] = $val;
    }

    // Auto-detect and generate tel/mailto links for Phone and Email keys if not manually set
    if (in_array($raw_code, ['PHONE', 'PHONE_NUMBER', 'MOBILE', 'CONTACT_NUMBER', 'SUPPORT_PHONE'])) {
        $clean_phone = preg_replace('/[^0-9+]/', '', $val);
        if ($url === '') {
            $map['$' . $raw_code . '_LINK$']  = 'tel:' . $clean_phone;
            $map['{' . $raw_code . '_LINK}']  = 'tel:' . $clean_phone;
            $map['$' . $raw_code . '_TEL$']   = 'tel:' . $clean_phone;
            $map['{' . $raw_code . '_TEL}']   = 'tel:' . $clean_phone;
            $map['$' . $raw_code . '_HTML$']  = '<a href="tel:' . $clean_phone . '">' . htmlspecialchars($val) . '</a>';
            $map['{' . $raw_code . '_HTML}']  = '<a href="tel:' . $clean_phone . '">' . htmlspecialchars($val) . '</a>';
        }
    }

    if (in_array($raw_code, ['EMAIL', 'EMAIL_ADDRESS', 'SUPPORT_EMAIL', 'CONTACT_EMAIL'])) {
        $clean_email = filter_var($val, FILTER_SANITIZE_EMAIL);
        if ($url === '') {
            $map['$' . $raw_code . '_LINK$']   = 'mailto:' . $clean_email;
            $map['{' . $raw_code . '_LINK}']   = 'mailto:' . $clean_email;
            $map['$' . $raw_code . '_MAILTO$'] = 'mailto:' . $clean_email;
            $map['{' . $raw_code . '_MAILTO}'] = 'mailto:' . $clean_email;
            $map['$' . $raw_code . '_HTML$']   = '<a href="mailto:' . $clean_email . '">' . htmlspecialchars($val) . '</a>';
            $map['{' . $raw_code . '_HTML}']   = '<a href="mailto:' . $clean_email . '">' . htmlspecialchars($val) . '</a>';
        }
    }
}

// ── 1. Global Site Logo Resolution ─────────────────────────────────────
$site_logo_url = '';
if (!empty($map['$SITE_LOGO$'])) {
    $site_logo_url = $map['$SITE_LOGO$'];
} elseif (!empty($map['$LOGO_URL$'])) {
    $site_logo_url = $map['$LOGO_URL$'];
} elseif (!empty($map['SITE_LOGO'])) {
    $site_logo_url = $map['SITE_LOGO'];
} else {
    try {
        $logo_stmt = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'site_logo_url' LIMIT 1")->fetch();
        if ($logo_stmt && !empty($logo_stmt['setting_value'])) {
            $site_logo_url = trim((string)$logo_stmt['setting_value']);
        }
    } catch (Exception $e) {}
}

if (!empty($site_logo_url)) {
    $site_logo_url = function_exists('get_asset_url') ? get_asset_url($site_logo_url) : $site_logo_url;
    if (strpos($site_logo_url, 'admin.distanceeducationschool.com/uploads/') !== false) {
        $site_logo_url = str_replace('admin.distanceeducationschool.com/uploads/', 'admin.distanceeducationschool.com/admin/uploads/', $site_logo_url);
    }
    $site_logo_url = str_replace(' ', '%20', $site_logo_url);
    $map['$SITE_LOGO$'] = $site_logo_url;
    $map['{SITE_LOGO}'] = $site_logo_url;
    $map['$LOGO_URL$']  = $site_logo_url;
    $map['{LOGO_URL}']  = $site_logo_url;
}

$uni       = null;
$all_slugs = [];

if (!empty($uni_slug)) {
    // Primary: Case-insensitive slug match
    $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(slug) = LOWER(?) AND is_active = 1 LIMIT 1");
    $stmt->execute([$uni_slug]);
    $uni = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: match by short_name (e.g. subdomain='dsu', short_name='DSU')
    if (!$uni) {
        $stmt = $db->prepare("SELECT * FROM universities WHERE LOWER(short_name) = LOWER(?) AND is_active = 1 LIMIT 1");
        $stmt->execute([$uni_slug]);
        $uni = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Debug: get all slugs
    $all_slugs = $db->query("SELECT slug, short_name, full_name, is_active FROM universities ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);


    if ($uni) {
        // Fetch accreditations / approvals for this university (Comma separated)
        $acc_stmt = $db->prepare("
            SELECT a.title 
            FROM university_accreditations ua
            INNER JOIN accreditations a ON ua.accreditation_id = a.id
            WHERE ua.university_id = ?
            ORDER BY a.id ASC
        ");
        $acc_stmt->execute([$uni['id']]);
        $acc_titles = $acc_stmt->fetchAll(PDO::FETCH_COLUMN);
        $approvals_str = !empty($acc_titles) ? implode(', ', $acc_titles) : '';

        $uni_short_raw   = $uni['short_name'] ?? '';
        $uni_short_upper = strtoupper($uni_short_raw);
        $uni_short_lower = strtolower($uni_short_raw);
        $uni_slug_val    = strtolower($uni['slug'] ?? $uni_short_lower);

        $raw_degree_img  = trim((string)($uni['sample_degree_img'] ?? ''));
        $sample_degree_url = '';
        if (!empty($raw_degree_img)) {
            $sample_degree_url = function_exists('get_asset_url') ? get_asset_url($raw_degree_img) : $raw_degree_img;
            if (strpos($sample_degree_url, 'admin.distanceeducationschool.com/uploads/') !== false) {
                $sample_degree_url = str_replace('admin.distanceeducationschool.com/uploads/', 'admin.distanceeducationschool.com/admin/uploads/', $sample_degree_url);
            }
            $sample_degree_url = str_replace(' ', '%20', $sample_degree_url);
        }

        $def_dates = function_exists('sode_get_default_dates') ? sode_get_default_dates($db) : [];
        $adm_last = !empty($uni['admission_last_date']) ? sode_format_admin_date_for_db($uni['admission_last_date']) : ($def_dates['admission_last_date'] ?? '');
        $adm_start = !empty($uni['admission_start_date']) ? sode_format_admin_date_for_db($uni['admission_start_date']) : ($def_dates['admission_start_date'] ?? '');
        $ex_date = !empty($uni['exam_date']) ? sode_format_admin_date_for_db($uni['exam_date']) : ($def_dates['exam_date'] ?? '');
        $ext_ex_date = !empty($uni['extended_exam_date']) ? sode_format_admin_date_for_db($uni['extended_exam_date']) : ($def_dates['extended_exam_date'] ?? '');
        $asg_date = !empty($uni['assignment_date']) ? sode_format_admin_date_for_db($uni['assignment_date']) : ($def_dates['assignment_date'] ?? '');

        $uni_keys = [
            // University Name
            '{UNIVERSITY_NAME}'             => $uni['full_name']  ?? '',
            '{university_name}'             => $uni['full_name']  ?? '',
            '$UNIVERSITY_NAME$'             => $uni['full_name']  ?? '',
            '$university_name$'             => $uni['full_name']  ?? '',
            'UNIVERSITY_NAME'               => $uni['full_name']  ?? '',

            // Default Short Name (Uppercase by default: e.g. "DSU")
            '{UNIVERSITY_SHORT_NAME}'       => $uni_short_upper,
            '$UNIVERSITY_SHORT_NAME$'       => $uni_short_upper,
            'UNIVERSITY_SHORT_NAME'         => $uni_short_upper,
            '{university_short_name}'       => $uni_short_upper,
            '$university_short_name$'       => $uni_short_upper,
            '{UNI_SHORT_NAME}'              => $uni_short_upper,
            '$UNI_SHORT_NAME$'              => $uni_short_upper,
            'UNI_SHORT_NAME'                => $uni_short_upper,
            '{uni_short_name}'              => $uni_short_upper,
            '$uni_short_name$'              => $uni_short_upper,
            '{UNI_SHORT}'                   => $uni_short_upper,
            '$UNI_SHORT$'                   => $uni_short_upper,
            '{uni_short}'                   => $uni_short_upper,
            '$uni_short$'                   => $uni_short_upper,
            '{UNI}'                         => $uni_short_upper,
            '$UNI$'                         => $uni_short_upper,
            '{uni}'                         => $uni_short_upper,
            '$uni$'                         => $uni_short_upper,
            '{UNIVERSITY_SHORT_NAME_UPPER}' => $uni_short_upper,
            '$UNIVERSITY_SHORT_NAME_UPPER$' => $uni_short_upper,
            'UNIVERSITY_SHORT_NAME_UPPER'   => $uni_short_upper,
            '{UNI_UPPER}'                   => $uni_short_upper,
            '$UNI_UPPER$'                   => $uni_short_upper,
            'UNI_UPPER'                     => $uni_short_upper,
            '{uni_upper}'                   => $uni_short_upper,
            '$uni_upper$'                   => $uni_short_upper,

            // Explicit Lowercase Keys ({UNIVERSITY_SHORT_NAME_LOWER}, {UNI_LOWER}, etc.: e.g. "dsu")
            '{UNIVERSITY_SHORT_NAME_LOWER}' => $uni_short_lower,
            '$UNIVERSITY_SHORT_NAME_LOWER$' => $uni_short_lower,
            'UNIVERSITY_SHORT_NAME_LOWER'   => $uni_short_lower,
            '{university_short_name_lower}' => $uni_short_lower,
            '$university_short_name_lower$' => $uni_short_lower,
            '{UNI_SHORT_NAME_LOWER}'        => $uni_short_lower,
            '$UNI_SHORT_NAME_LOWER$'        => $uni_short_lower,
            'UNI_SHORT_NAME_LOWER'          => $uni_short_lower,
            '{uni_short_name_lower}'        => $uni_short_lower,
            '$uni_short_name_lower$'        => $uni_short_lower,
            '{UNI_SHORT_LOWER}'             => $uni_short_lower,
            '$UNI_SHORT_LOWER$'             => $uni_short_lower,
            '{uni_short_lower}'             => $uni_short_lower,
            '$uni_short_lower$'             => $uni_short_lower,
            '{UNI_LOWER}'                   => $uni_short_lower,
            '$UNI_LOWER$'                   => $uni_short_lower,
            'UNI_LOWER'                     => $uni_short_lower,
            '{uni_lower}'                   => $uni_short_lower,
            '$uni_lower$'                   => $uni_short_lower,

            // Slug (Always lowercase)
            '{UNIVERSITY_SLUG}'             => $uni_slug_val,
            '{university_slug}'             => $uni_slug_val,
            '$UNIVERSITY_SLUG$'             => $uni_slug_val,
            '$university_slug$'             => $uni_slug_val,
            'UNIVERSITY_SLUG'               => $uni_slug_val,
            '{SLUG}'                        => $uni_slug_val,
            '{slug}'                        => $uni_slug_val,
            '$SLUG$'                        => $uni_slug_val,
            '$slug$'                        => $uni_slug_val,
            '{UNI_SLUG}'                    => $uni_slug_val,
            '{uni_slug}'                    => $uni_slug_val,
            '$UNI_SLUG$'                    => $uni_slug_val,
            '$uni_slug$'                    => $uni_slug_val,
            'UNI_SLUG'                      => $uni_slug_val,

            // Mode (Online / Distance / ODL)
            '{MODE}'                     => $uni['mode']       ?? '',
            '{mode}'                     => $uni['mode']       ?? '',
            '$MODE$'                     => $uni['mode']       ?? '',

            // Location
            '{UNIVERSITY_LOCATION}'      => $uni['location']   ?? '',
            '{location}'                 => $uni['location']   ?? '',
            '$LOCATION$'                 => $uni['location']   ?? '',

            // URLs & Assets
            '{UNIVERSITY_URL}'           => $uni['official_url']  ?? '',
            '{UNIVERSITY_LOGO}'          => $uni['logo_url']      ?? '',
            '$LOGO_URL$'                 => $uni['logo_url']      ?? '',

            // Sample Degree Image
            '{SAMPLE_DEGREE_URL}'        => $sample_degree_url,
            '{sample_degree_url}'        => $sample_degree_url,
            '$SAMPLE_DEGREE_URL$'        => $sample_degree_url,
            '$sample_degree_url$'        => $sample_degree_url,
            '{SAMPLE_DEGREE_IMG}'        => $sample_degree_url,
            '{sample_degree_img}'        => $sample_degree_url,
            '$SAMPLE_DEGREE_IMG$'        => $sample_degree_url,
            '$sample_degree_img$'        => $sample_degree_url,
            '{SAMPLE_DEGREE}'            => $sample_degree_url,
            '{sample_degree}'            => $sample_degree_url,
            '$SAMPLE_DEGREE$'            => $sample_degree_url,
            '$sample_degree$'            => $sample_degree_url,
            'SAMPLE_DEGREE_URL'          => $sample_degree_url,
            'SAMPLE_DEGREE_IMG'          => $sample_degree_url,
            'SAMPLE_DEGREE'              => $sample_degree_url,

            // Dates with global defaults fallback & 15 Nov 2026 format
            '{ADMISSION_LAST_DATE}'      => $adm_last,
            '$ADMISSION_LAST_DATE$'      => $adm_last,
            '{ADMISSION_START_DATE}'     => $adm_start,
            '$ADMISSION_START_DATE$'     => $adm_start,
            '{EXAM_DATE}'                => $ex_date,
            '$EXAM_DATE$'                => $ex_date,
            '{ASSIGNMENT_DATE}'          => $asg_date,
            '$ASSIGNMENT_DATE$'          => $asg_date,
            '{EXTENDED_EXAM_DATE}'       => $ext_ex_date,
            '$EXTENDED_EXAM_DATE$'       => $ext_ex_date,

            // Approvals & Accreditations (Comma Separated)
            '{APPROVALS}'                => $approvals_str,
            '{approvals}'                => $approvals_str,
            '$APPROVALS$'                => $approvals_str,
            '$approvals$'                => $approvals_str,
            '{ACCREDITATIONS}'           => $approvals_str,
            '{accreditations}'           => $approvals_str,
            '$ACCREDITATIONS$'           => $approvals_str,
            '$accreditations$'           => $approvals_str,
            '{APPROVALS_ACCREDITATIONS}' => $approvals_str,
            '$APPROVALS_ACCREDITATIONS$' => $approvals_str,
            '{COMMA_SEPARATED_APPROVALS}' => $approvals_str,
            '$COMMA_SEPARATED_APPROVALS$' => $approvals_str,

            // Rating
            '{UNIVERSITY_RATING}'        => $uni['rating'] ?? '',
            '$RATING$'                   => $uni['rating'] ?? '',
        ];

        // Fetch all course fee mappings for this university
        $course_stmt = $db->prepare("
            SELECT 
                c.short_name,
                c.slug,
                c.full_name,
                ucm.mode,
                ucm.per_semester_fee,
                ucm.total_program_fee,
                ucm.tuition_fee,
                ucm.examination_fee,
                ucm.one_time_processing_fee,
                ucm.eligibility_text
            FROM university_course_mappings ucm
            INNER JOIN courses c ON ucm.course_id = c.id
            WHERE ucm.university_id = ?
            ORDER BY 
                CASE 
                    WHEN ucm.mode = 'Online' THEN 1 
                    ELSE 2 
                END ASC,
                CASE 
                    WHEN c.level = 'PG' THEN 1 
                    WHEN c.level = 'UG' THEN 2 
                    ELSE 3 
                END ASC,
                ucm.id ASC
        ");
        $course_stmt->execute([$uni['id']]);
        $courses = $course_stmt->fetchAll(PDO::FETCH_ASSOC);

        $fee_keys = [];
        $all_elig_lines = [];
        $course_short_names = [];
        $course_full_names  = [];
        $course_mode_names  = [];
        $seen_short_keys    = [];
        $seen_full_keys     = [];
        $seen_generic_courses = [];

        // Helper to register keys across all casing & bracket syntaxes ($KEY$, {KEY}, lowercase, and raw)
        $register_fee_key = function(&$target_keys, $key_name, $val) {
            if ($val === null || $val === '') {
                return;
            }
            $val = (string)$val;
            $k_upper = strtoupper(trim($key_name, '$ {}'));
            $k_lower = strtolower($k_upper);

            // Upper variants
            $target_keys['$' . $k_upper . '$'] = $val;
            $target_keys['{' . $k_upper . '}'] = $val;
            $target_keys[$k_upper]             = $val;

            // Lower variants
            $target_keys['$' . $k_lower . '$'] = $val;
            $target_keys['{' . $k_lower . '}'] = $val;
        };

        $uni_mode_str = strtolower(trim((string)($uni['mode'] ?? '')));

        foreach ($courses as $c) {
            $raw_mode  = trim((string)($c['mode'] ?? ''));
            $c_mode    = (stripos($raw_mode, 'dist') !== false) ? 'Distance' : ((stripos($raw_mode, 'online') !== false) ? 'Online' : (!empty($raw_mode) ? ucfirst($raw_mode) : 'Online'));
            $c_short   = ($c['short_name'] === 'B.Com') ? 'BCom' : trim($c['short_name']);
            $c_full    = trim($c['full_name']);

            $c_clean_short = strtolower(str_replace(['.', ' ', '-'], '', $c_short));
            $c_clean_full  = strtolower(str_replace(['.', ' ', '-'], '', $c_full));

            if (!empty($c_short) && !in_array($c_short, $course_short_names) && !in_array($c_clean_short, $seen_short_keys)) {
                $course_short_names[] = $c_short;
                $seen_short_keys[] = $c_clean_short;
            }
            if (!empty($c_full) && !in_array($c_full, $course_full_names) && !in_array($c_clean_full, $seen_full_keys)) {
                $course_full_names[] = $c_full;
                $seen_full_keys[] = $c_clean_full;
            }
            $c_mode_label = (stripos($c_short, $c_mode) !== 0) ? $c_mode . ' ' . $c_short : $c_short;
            if (!in_array($c_mode_label, $course_mode_names)) {
                $course_mode_names[] = $c_mode_label;
            }

            $per_sem   = trim((string)($c['per_semester_fee'] ?? ''));
            $total_fee = trim((string)($c['total_program_fee'] ?? ''));
            $tuition   = trim((string)($c['tuition_fee'] ?? ''));
            $exam      = trim((string)($c['examination_fee'] ?? ''));
            $reg_fee   = trim((string)($c['one_time_processing_fee'] ?? ''));
            $elig_text = trim((string)($c['eligibility_text'] ?? ''));

            if ($elig_text === '') {
                $is_master = (stripos($c['full_name'], 'Master') !== false);
                $elig_text = $is_master ? "Bachelor's degree in any discipline from a recognized university. Minimum 50% aggregate marks; 45% for SC/ST/OBC categories." : "10+2 or equivalent qualification from a recognized board. Minimum 45% aggregate marks; 40% for SC/ST/OBC categories.";
            }

            $base_disp_name = ($c['short_name'] === 'B.Com') ? 'BCom' : $c['short_name'];
            if (stripos($base_disp_name, $c_mode) !== 0) {
                $c_disp_name = $c_mode . ' ' . $base_disp_name;
            } else {
                $c_disp_name = $base_disp_name;
            }
            $all_elig_lines[] = "• " . $c_disp_name . ": " . $elig_text;

            // Default fee (per semester if available, else total)
            $main_fee = $per_sem !== '' ? $per_sem : $total_fee;

            // Generate name variants (e.g. MBA, BCOM, B_COM, MCA, etc.)
            $slug_clean  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['slug'] ?? ''));
            $short_clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['short_name'] ?? ''));
            $short_und   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', trim($c['short_name'] ?? '', '.')));
            $mode_slug   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c_mode));

            $prefixes = array_unique(array_filter([$slug_clean, $short_clean, $short_und]));

            foreach ($prefixes as $pfx) {
                // Decide whether this row sets generic keys (e.g. $MBA_FEE$)
                // Priority: match university's own mode; otherwise first seen wins (avoids random overwrite)
                $should_set_generic = false;
                if (!isset($seen_generic_courses[$pfx])) {
                    $should_set_generic = true;
                    $seen_generic_courses[$pfx] = $c_mode;
                } else {
                    $prev_mode = $seen_generic_courses[$pfx];
                    if (strpos($uni_mode_str, strtolower($c_mode)) !== false && strpos($uni_mode_str, strtolower($prev_mode)) === false) {
                        $should_set_generic = true;
                        $seen_generic_courses[$pfx] = $c_mode;
                    }
                }

                if ($should_set_generic) {
                    // Generic Course Fee Keys (no mode in key name)
                    $register_fee_key($fee_keys, $pfx . '_FEE', $main_fee);
                    $register_fee_key($fee_keys, $pfx . '_FEES', $main_fee);
                    $register_fee_key($fee_keys, $pfx . '_PER_SEMESTER_FEE', $per_sem);
                    $register_fee_key($fee_keys, $pfx . '_PER_SEMESTER_FEES', $per_sem);
                    $register_fee_key($fee_keys, $pfx . '_SEMESTER_FEE', $per_sem);
                    $register_fee_key($fee_keys, $pfx . '_SEMESTER_FEES', $per_sem);
                    $register_fee_key($fee_keys, $pfx . '_SEM_FEE', $per_sem);
                    $register_fee_key($fee_keys, $pfx . '_SEM_FEES', $per_sem);

                    $register_fee_key($fee_keys, $pfx . '_TOTAL_FEE', $total_fee);
                    $register_fee_key($fee_keys, $pfx . '_TOTAL_FEES', $total_fee);
                    $register_fee_key($fee_keys, $pfx . '_TOTAL_PROGRAM_FEE', $total_fee);
                    $register_fee_key($fee_keys, $pfx . '_TOTAL_PROGRAM_FEES', $total_fee);
                    $register_fee_key($fee_keys, $pfx . '_FULL_FEE', $total_fee);
                    $register_fee_key($fee_keys, $pfx . '_FULL_FEES', $total_fee);

                    $register_fee_key($fee_keys, $pfx . '_TUITION_FEE', $tuition);
                    $register_fee_key($fee_keys, $pfx . '_EXAM_FEE', $exam);
                    $register_fee_key($fee_keys, $pfx . '_EXAMINATION_FEE', $exam);
                    $register_fee_key($fee_keys, $pfx . '_REGISTRATION_FEE', $reg_fee);
                    $register_fee_key($fee_keys, $pfx . '_ONE_TIME_FEE', $reg_fee);

                    $register_fee_key($fee_keys, $pfx . '_ELIGIBILITY', $elig_text);
                    $register_fee_key($fee_keys, $pfx . '_ELIGIBILITY_TEXT', $elig_text);
                }

                // Explicit Mode Keys: Both prefix (e.g. ONLINE_MBA) and suffix (e.g. MBA_ONLINE)
                $mode_combos = [
                    $mode_slug . '_' . $pfx, // e.g. ONLINE_MBA, DISTANCE_MBA
                    $pfx . '_' . $mode_slug  // e.g. MBA_ONLINE, MBA_DISTANCE
                ];

                foreach ($mode_combos as $comb) {
                    $register_fee_key($fee_keys, $comb . '_FEE', $main_fee);
                    $register_fee_key($fee_keys, $comb . '_FEES', $main_fee);
                    $register_fee_key($fee_keys, $comb . '_PER_SEMESTER_FEE', $per_sem);
                    $register_fee_key($fee_keys, $comb . '_PER_SEMESTER_FEES', $per_sem);
                    $register_fee_key($fee_keys, $comb . '_SEMESTER_FEE', $per_sem);
                    $register_fee_key($fee_keys, $comb . '_SEMESTER_FEES', $per_sem);
                    $register_fee_key($fee_keys, $comb . '_SEM_FEE', $per_sem);
                    $register_fee_key($fee_keys, $comb . '_SEM_FEES', $per_sem);

                    $register_fee_key($fee_keys, $comb . '_TOTAL_FEE', $total_fee);
                    $register_fee_key($fee_keys, $comb . '_TOTAL_FEES', $total_fee);
                    $register_fee_key($fee_keys, $comb . '_TOTAL_PROGRAM_FEE', $total_fee);
                    $register_fee_key($fee_keys, $comb . '_TOTAL_PROGRAM_FEES', $total_fee);
                    $register_fee_key($fee_keys, $comb . '_FULL_FEE', $total_fee);
                    $register_fee_key($fee_keys, $comb . '_FULL_FEES', $total_fee);

                    $register_fee_key($fee_keys, $comb . '_TUITION_FEE', $tuition);
                    $register_fee_key($fee_keys, $comb . '_EXAM_FEE', $exam);
                    $register_fee_key($fee_keys, $comb . '_EXAMINATION_FEE', $exam);
                    $register_fee_key($fee_keys, $comb . '_REGISTRATION_FEE', $reg_fee);
                    $register_fee_key($fee_keys, $comb . '_ONE_TIME_FEE', $reg_fee);

                    $register_fee_key($fee_keys, $comb . '_ELIGIBILITY', $elig_text);
                    $register_fee_key($fee_keys, $comb . '_ELIGIBILITY_TEXT', $elig_text);
                }
            }
        }

        // Combined eligibility keys
        $all_elig_str = implode("\n", $all_elig_lines);
        $fee_keys['$ALL_COURSES_ELIGIBILITY$']      = $all_elig_str;
        $fee_keys['{ALL_COURSES_ELIGIBILITY}']      = $all_elig_str;
        $fee_keys['$UNIVERSITY_ELIGIBILITY_TEXT$']  = $all_elig_str;
        $fee_keys['{UNIVERSITY_ELIGIBILITY_TEXT}']  = $all_elig_str;

        // Dynamic Course List Text Keys & Shortcode Intercepts
        $courses_list_short = implode(', ', $course_short_names);
        $courses_list_full  = implode(', ', $course_full_names);
        $courses_list_mode  = implode(', ', $course_mode_names);

        $course_list_aliases = [
            '{UNIVERSITY_COURSES_LIST}' => $courses_list_short,
            '{university_courses_list}' => $courses_list_short,
            '$UNIVERSITY_COURSES_LIST$' => $courses_list_short,
            '$university_courses_list$' => $courses_list_short,
            '{UNI_COURSES_LIST}'        => $courses_list_short,
            '{uni_courses_list}'        => $courses_list_short,
            '$UNI_COURSES_LIST$'        => $courses_list_short,
            '$uni_courses_list$'        => $courses_list_short,
            '{COURSES_LIST}'            => $courses_list_short,
            '{courses_list}'            => $courses_list_short,
            '$COURSES_LIST$'            => $courses_list_short,
            '$courses_list$'            => $courses_list_short,
            '{COURSES_LIST_FULL}'       => $courses_list_full,
            '{courses_list_full}'       => $courses_list_full,
            '$COURSES_LIST_FULL$'       => $courses_list_full,
            '$courses_list_full$'       => $courses_list_full,
            '{COURSES_LIST_MODE}'       => $courses_list_mode,
            '{COURSES_LIST_ONLINE}'     => $courses_list_mode,
            // Direct Shortcode-syntax key replacement fallbacks (e.g. for Yoast Meta Descriptions & Titles)
            '[university_courses_list]' => $courses_list_short,
            '[uni_courses_list]'        => $courses_list_short,
            '[courses_list]'            => $courses_list_short,
            '[sode_courses_list]'       => $courses_list_short,
            '[uni_courses_text]'        => $courses_list_short,
        ];

        foreach ($course_list_aliases as $ck => $cv) {
            $fee_keys[$ck] = $cv;
        }

        // Merge — university keys and course fees override global keys
        $map = array_merge($map, $uni_keys, $fee_keys);
    }
}

// ── Lead Form Universal Component Key Injection (#LEAD_FORM#) ────────
if (!function_exists('custom_lead_form_shortcode')) {
    $lead_file = dirname(__DIR__, 2) . '/lead-form-universal.php';
    if (file_exists($lead_file)) {
        require_once $lead_file;
    }
}

if (function_exists('custom_lead_form_shortcode')) {
    $lead_form_html = custom_lead_form_shortcode([
        'university' => $uni_slug ?: '',
        'form_name'  => (!empty($uni['short_name']) ? $uni['short_name'] : 'Main') . ' Lead Form'
    ]);

    if (!empty($lead_form_html)) {
        $lead_form_aliases = [
            '#LEAD_FORM#'        => $lead_form_html,
            '#lead_form#'        => $lead_form_html,
            '{#LEAD_FORM#}'      => $lead_form_html,
            '{#lead_form#}'      => $lead_form_html,
            '${#LEAD_FORM#}$'    => $lead_form_html,
            '${#lead_form#}$'    => $lead_form_html,
            '{LEAD_FORM}'        => $lead_form_html,
            '{lead_form}'        => $lead_form_html,
            '$LEAD_FORM$'        => $lead_form_html,
            '$lead_form$'        => $lead_form_html,
            '#MAIN_LEAD_FORM#'   => $lead_form_html,
            '#main_lead_form#'   => $lead_form_html,
            '{MAIN_LEAD_FORM}'   => $lead_form_html,
            '{main_lead_form}'   => $lead_form_html,
            '$MAIN_LEAD_FORM$'   => $lead_form_html,
            '$main_lead_form$'   => $lead_form_html,
            '#COUNSELING_FORM#'  => $lead_form_html,
            '#counseling_form#'  => $lead_form_html,
            '{COUNSELING_FORM}'  => $lead_form_html,
            '{counseling_form}'  => $lead_form_html,
            '$COUNSELING_FORM$'  => $lead_form_html,
            '$counseling_form$'  => $lead_form_html,
            '[custom_lead_form]' => $lead_form_html,
            '[lead_form]'        => $lead_form_html,
        ];
        foreach ($lead_form_aliases as $l_k => $l_v) {
            $map[$l_k] = $l_v;
        }
    }
}

$response = [
    'success'       => true,
    'site_logo_url' => $site_logo_url ?: null,
    'uni'           => $uni_slug ?: null,
    'uni_found'     => !empty($uni),     // debug: was university found in DB?
    'all_slugs'     => $all_slugs,       // debug: all slugs available in DB
    'keys'          => $map,
    'data'          => $map,
    'count'         => count($map),
];

// Cache response in Redis for 24 hours
if (class_exists('Sode_Redis') && Sode_Redis::isAvailable()) {
    Sode_Redis::set($cache_key, $response, 86400);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

