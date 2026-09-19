<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once dirname(__DIR__) . '/config/config.php';

$db = get_db_connection();

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



// ── 2. University-Specific Keys (returned when ?uni=slug passed) ───────
$uni_slug  = trim($_GET['uni'] ?? '');
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

        $uni_keys = [
            // University Name
            '{UNIVERSITY_NAME}'          => $uni['full_name']  ?? '',
            '{university_name}'          => $uni['full_name']  ?? '',
            '$UNIVERSITY_NAME$'          => $uni['full_name']  ?? '',

            // Short Name
            '{UNIVERSITY_SHORT_NAME}'    => $uni['short_name'] ?? '',
            '{university_short_name}'    => $uni['short_name'] ?? '',
            '$UNIVERSITY_SHORT_NAME$'    => $uni['short_name'] ?? '',

            // Slug
            '{UNIVERSITY_SLUG}'          => $uni['slug']       ?? '',
            '$UNIVERSITY_SLUG$'          => $uni['slug']       ?? '',

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

            // Dates
            '{ADMISSION_LAST_DATE}'      => $uni['admission_last_date']  ?? '',
            '$ADMISSION_LAST_DATE$'      => $uni['admission_last_date']  ?? '',
            '{ADMISSION_START_DATE}'     => $uni['admission_start_date'] ?? '',
            '$ADMISSION_START_DATE$'     => $uni['admission_start_date'] ?? '',
            '{EXAM_DATE}'                => $uni['exam_date']            ?? '',
            '$EXAM_DATE$'                => $uni['exam_date']            ?? '',
            '{ASSIGNMENT_DATE}'          => $uni['assignment_date']      ?? '',
            '$ASSIGNMENT_DATE$'          => $uni['assignment_date']      ?? '',
            '{EXTENDED_EXAM_DATE}'       => $uni['extended_exam_date']   ?? '',
            '$EXTENDED_EXAM_DATE$'       => $uni['extended_exam_date']   ?? '',

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
                ucm.per_semester_fee,
                ucm.total_program_fee,
                ucm.tuition_fee,
                ucm.examination_fee,
                ucm.one_time_processing_fee,
                ucm.eligibility_text
            FROM university_course_mappings ucm
            INNER JOIN courses c ON ucm.course_id = c.id
            WHERE ucm.university_id = ?
        ");
        $course_stmt->execute([$uni['id']]);
        $courses = $course_stmt->fetchAll(PDO::FETCH_ASSOC);

        $fee_keys = [];
        $all_elig_lines = [];

        foreach ($courses as $c) {
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

            $c_disp_name = ($c['short_name'] === 'B.Com') ? 'BCom' : $c['short_name'];
            $all_elig_lines[] = "• " . $c_disp_name . ": " . $elig_text;

            // Default fee (per semester if available, else total)
            $main_fee = $per_sem !== '' ? $per_sem : $total_fee;

            // Generate name variants (e.g. MBA, BCOM, B_COM, MCA, etc.)
            $slug_clean  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['slug'] ?? ''));
            $short_clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c['short_name'] ?? ''));
            $short_und   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', trim($c['short_name'] ?? '', '.')));

            $prefixes = array_unique(array_filter([$slug_clean, $short_clean, $short_und]));

            foreach ($prefixes as $pfx) {
                // Main / Per Semester Fee
                $fee_keys['$' . $pfx . '_FEE$']                  = $main_fee;
                $fee_keys['$' . $pfx . '_FEES$']                 = $main_fee;
                $fee_keys['{' . $pfx . '_FEE}']                  = $main_fee;
                $fee_keys['{' . $pfx . '_FEES}']                 = $main_fee;

                $fee_keys['$' . $pfx . '_PER_SEMESTER_FEE$']     = $per_sem;
                $fee_keys['$' . $pfx . '_PER_SEMESTER_FEES$']    = $per_sem;
                $fee_keys['{' . $pfx . '_PER_SEMESTER_FEE}']     = $per_sem;
                $fee_keys['{' . $pfx . '_PER_SEMESTER_FEES}']    = $per_sem;

                $fee_keys['$' . $pfx . '_SEMESTER_FEE$']         = $per_sem;
                $fee_keys['$' . $pfx . '_SEMESTER_FEES$']        = $per_sem;
                $fee_keys['{' . $pfx . '_SEMESTER_FEE}']         = $per_sem;
                $fee_keys['{' . $pfx . '_SEMESTER_FEES}']        = $per_sem;

                // Total Program Fee
                $fee_keys['$' . $pfx . '_TOTAL_FEE$']            = $total_fee;
                $fee_keys['$' . $pfx . '_TOTAL_FEES$']           = $total_fee;
                $fee_keys['{' . $pfx . '_TOTAL_FEE}']            = $total_fee;
                $fee_keys['{' . $pfx . '_TOTAL_FEES}']           = $total_fee;

                $fee_keys['$' . $pfx . '_TOTAL_PROGRAM_FEE$']    = $total_fee;
                $fee_keys['$' . $pfx . '_TOTAL_PROGRAM_FEES$']   = $total_fee;
                $fee_keys['{' . $pfx . '_TOTAL_PROGRAM_FEE}']    = $total_fee;
                $fee_keys['{' . $pfx . '_TOTAL_PROGRAM_FEES}']   = $total_fee;

                // Tuition Fee
                if ($tuition !== '') {
                    $fee_keys['$' . $pfx . '_TUITION_FEE$']      = $tuition;
                    $fee_keys['{' . $pfx . '_TUITION_FEE}']      = $tuition;
                }

                // Examination Fee
                if ($exam !== '') {
                    $fee_keys['$' . $pfx . '_EXAM_FEE$']         = $exam;
                    $fee_keys['{' . $pfx . '_EXAM_FEE}']         = $exam;
                    $fee_keys['$' . $pfx . '_EXAMINATION_FEE$']  = $exam;
                    $fee_keys['{' . $pfx . '_EXAMINATION_FEE}']  = $exam;
                }

                // One-time registration / processing fee
                if ($reg_fee !== '') {
                    $fee_keys['$' . $pfx . '_REGISTRATION_FEE$'] = $reg_fee;
                    $fee_keys['{' . $pfx . '_REGISTRATION_FEE}'] = $reg_fee;
                }

                // Course Eligibility Text
                $fee_keys['$' . $pfx . '_ELIGIBILITY$']          = $elig_text;
                $fee_keys['{' . $pfx . '_ELIGIBILITY}']          = $elig_text;
                $fee_keys['$' . $pfx . '_ELIGIBILITY_TEXT$']     = $elig_text;
                $fee_keys['{' . $pfx . '_ELIGIBILITY_TEXT}']     = $elig_text;
            }
        }

        // Combined eligibility keys
        $all_elig_str = implode("\n", $all_elig_lines);
        $fee_keys['$ALL_COURSES_ELIGIBILITY$']      = $all_elig_str;
        $fee_keys['{ALL_COURSES_ELIGIBILITY}']      = $all_elig_str;
        $fee_keys['$UNIVERSITY_ELIGIBILITY_TEXT$']  = $all_elig_str;
        $fee_keys['{UNIVERSITY_ELIGIBILITY_TEXT}']  = $all_elig_str;

        // Merge — university keys and course fees override global keys
        $map = array_merge($map, $uni_keys, $fee_keys);
    }
}

echo json_encode([
    'success'   => true,
    'uni'       => $uni_slug ?: null,
    'uni_found' => !empty($uni),     // debug: was university found in DB?
    'all_slugs' => $all_slugs,       // debug: all slugs available in DB
    'keys'      => $map,
    'data'      => $map,
    'count'     => count($map),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
