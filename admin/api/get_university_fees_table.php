<?php
/**
 * API Endpoint: Get University Course Fees Matrix Table
 * Returns the current domain university and all alternate universities
 * with fees mapped strictly for the current domain university's courses.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

try {
    $db = get_db_connection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $uni_input = trim($_GET['uni'] ?? ($_POST['uni'] ?? ($_GET['university'] ?? ($_POST['university'] ?? ''))));
    if (empty($uni_input)) {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $parts = explode('.', $host);
        if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
            $uni_input = preg_replace('/[^a-z0-9-]+/', '-', $parts[0]);
        }
    }
    if (empty($uni_input)) {
        $uni_input = 'dsu';
    }

    // Helper: Dynamically find matching university from database records
    if (!function_exists('sode_find_matching_university')) {
        function sode_find_matching_university($unis, $search_term) {
            if (empty($search_term) || empty($unis)) return null;
            $term = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', (string)$search_term)));
            if ($term === '') return null;

            // 1. Exact match on slug, short_name, or full_name
            foreach ($unis as $u) {
                $clean_slug = strtolower(str_replace(['-', '_', ' '], '', $u['slug'] ?? ''));
                $clean_short = strtolower(str_replace(['-', '_', ' ', '.'], '', $u['short_name'] ?? ''));
                $clean_full = strtolower(str_replace(['-', '_', ' ', '.', ','], '', $u['full_name'] ?? ''));
                if ($term === $clean_slug || $term === $clean_short || $term === $clean_full) {
                    return $u;
                }
            }

            // 2. Dynamic Acronym / Initials match (e.g. cu, dsu, lpu, smu, vgu, muj)
            foreach ($unis as $u) {
                $slug_parts = explode('-', strtolower($u['slug'] ?? ''));
                $slug_ac = '';
                foreach ($slug_parts as $sp) {
                    if ($sp !== '') $slug_ac .= $sp[0];
                }
                if ($term === $slug_ac) {
                    return $u;
                }

                $full_words = preg_split('/[\s,\-\.]+/', strtolower($u['full_name'] ?? ''));
                $full_ac = '';
                foreach ($full_words as $fw) {
                    if ($fw !== '' && !in_array($fw, ['and', 'of', 'for', 'the', 'in'])) {
                        $full_ac .= $fw[0];
                    }
                }
                if ($term === $full_ac) {
                    return $u;
                }
            }

            // 3. Substring / Prefix match on slug or name
            foreach ($unis as $u) {
                $clean_slug = strtolower(str_replace(['-', '_', ' '], '', $u['slug'] ?? ''));
                $clean_full = strtolower(str_replace(['-', '_', ' ', '.', ','], '', $u['full_name'] ?? ''));
                if (strpos($clean_slug, $term) !== false || strpos($clean_full, $term) !== false) {
                    return $u;
                }
            }

            return null;
        }
    }

    // 1. Fetch All Active Universities & Match Dynamically
    $all_active_stmt = $db->query("SELECT id, full_name, short_name, slug, mode, location, official_url FROM universities WHERE is_active = 1 ORDER BY id ASC");
    $all_active_unis = $all_active_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($all_active_unis)) {
        echo json_encode(['success' => false, 'message' => 'No active university found']);
        exit;
    }

    $current_uni = sode_find_matching_university($all_active_unis, $uni_input);
    if (!$current_uni) {
        $current_uni = $all_active_unis[0];
    }

    // 2. Fetch Mapped Courses for Current University
    $c_stmt = $db->prepare("
        SELECT c.id AS course_id, c.short_name, c.full_name, c.level,
               ucm.per_semester_fee, ucm.total_program_fee, ucm.mode
        FROM university_course_mappings ucm
        JOIN courses c ON ucm.course_id = c.id
        WHERE ucm.university_id = ?
        ORDER BY ucm.id ASC
    ");
    $c_stmt->execute([$current_uni['id']]);
    $current_courses = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ranking priority for course display: Masters first, then Bachelors
    $course_ranks = [
        'mba' => 1,
        'mca' => 2,
        'mcom' => 3,
        'm.com' => 3,
        'ma' => 4,
        'msc' => 5,
        'm.sc' => 5,
        'bba' => 10,
        'bcom' => 11,
        'b.com' => 11,
        'bca' => 12,
        'ba' => 13,
        'bsc' => 14,
        'b.sc' => 14
    ];

    usort($current_courses, function($a, $b) use ($course_ranks) {
        $k_a = strtolower(str_replace(['.', ' '], '', $a['short_name']));
        $k_b = strtolower(str_replace(['.', ' '], '', $b['short_name']));
        $r_a = $course_ranks[$k_a] ?? 50;
        $r_b = $course_ranks[$k_b] ?? 50;
        if ($r_a === $r_b) {
            return ($a['course_id'] < $b['course_id']) ? -1 : 1;
        }
        return ($r_a < $r_b) ? -1 : 1;
    });

    // Build target columns
    $columns = [];
    foreach ($current_courses as $c) {
        $clean_name = strtoupper(str_replace(['.', ' '], '', trim($c['short_name'])));
        $columns[] = [
            'course_id'   => (int) $c['course_id'],
            'short_name'  => trim($c['short_name']),
            'clean_name'  => $clean_name,
            'header_text' => $clean_name . ' SEMESTER FEE',
        ];
    }

    // 3. Fetch Alternate Universities
    $alt_stmt = $db->query("
        SELECT id, full_name, short_name, slug, mode, location, official_url
        FROM universities
        WHERE show_in_alternate = 1 AND is_active = 1
        ORDER BY id ASC
    ");
    $alt_unis = $alt_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Combine: Current University as Row 1, followed by Alternate Universities (excluding current)
    $all_unis = [];
    $all_unis[] = array_merge($current_uni, ['is_current' => true]);

    foreach ($alt_unis as $alt) {
        if ((int)$alt['id'] === (int)$current_uni['id']) {
            continue; // Skip duplicate
        }
        $all_unis[] = array_merge($alt, ['is_current' => false]);
    }

    // 5. Pre-fetch fees for all these universities
    $uni_ids = array_column($all_unis, 'id');
    $placeholders = implode(',', array_fill(0, count($uni_ids), '?'));
    
    $mappings_stmt = $db->prepare("
        SELECT ucm.university_id, c.id AS course_id, c.short_name, ucm.per_semester_fee
        FROM university_course_mappings ucm
        JOIN courses c ON ucm.course_id = c.id
        WHERE ucm.university_id IN ($placeholders)
    ");
    $mappings_stmt->execute($uni_ids);
    $all_mappings = $mappings_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group mappings by university_id and course identifiers
    $uni_fees_map = [];
    foreach ($all_mappings as $m) {
        $u_id = (int)$m['university_id'];
        $c_id = (int)$m['course_id'];
        $clean_c = strtolower(str_replace(['.', ' '], '', $m['short_name']));
        
        $uni_fees_map[$u_id]['by_id'][$c_id] = $m['per_semester_fee'];
        $uni_fees_map[$u_id]['by_name'][$clean_c] = $m['per_semester_fee'];
    }

    // 6. Assemble University Rows
    $rows = [];
    foreach ($all_unis as $u) {
        $u_id = (int)$u['id'];
        $is_curr = !empty($u['is_current']);

        // Display name formatting: use full university name consistently
        $disp_name = !empty($u['full_name']) ? $u['full_name'] : $u['short_name'];

        $fees_row = [];
        foreach ($columns as $col) {
            $col_id = $col['course_id'];
            $col_clean = strtolower($col['clean_name']);
            
            $fee_val = null;
            if (isset($uni_fees_map[$u_id]['by_id'][$col_id])) {
                $fee_val = $uni_fees_map[$u_id]['by_id'][$col_id];
            } elseif (isset($uni_fees_map[$u_id]['by_name'][$col_clean])) {
                $fee_val = $uni_fees_map[$u_id]['by_name'][$col_clean];
            }

            // Format fee string
            if ($fee_val !== null && trim($fee_val) !== '' && trim($fee_val) !== '0' && strtolower(trim($fee_val)) !== 'n/a') {
                $clean_fee = trim($fee_val);
                if (strpos($clean_fee, '₹') === false) {
                    if (is_numeric(str_replace([',', ' '], '', $clean_fee))) {
                        $clean_fee = '₹ ' . number_format((float)str_replace([',', ' '], '', $clean_fee));
                    } else {
                        $clean_fee = '₹ ' . $clean_fee;
                    }
                }
                $fees_row[$col['clean_name']] = $clean_fee;
            } else {
                $fees_row[$col['clean_name']] = 'N/A';
            }
        }

        $rows[] = [
            'id'           => $u_id,
            'name'         => $disp_name,
            'short_name'   => $u['short_name'] ?? '',
            'full_name'    => $u['full_name'] ?? '',
            'slug'         => $u['slug'] ?? '',
            'link'         => '',
            'is_current'   => $is_curr,
            'fees'         => $fees_row,
        ];
    }

    echo json_encode([
        'success'            => true,
        'current_university' => [
            'id'           => (int)$current_uni['id'],
            'full_name'    => $current_uni['full_name'],
            'short_name'   => $current_uni['short_name'],
            'slug'         => $current_uni['slug'],
            'display_name' => !empty($current_uni['full_name']) ? $current_uni['full_name'] : $current_uni['short_name'],
        ],
        'columns'            => $columns,
        'universities'       => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
