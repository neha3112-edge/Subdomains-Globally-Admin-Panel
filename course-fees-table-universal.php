<?php
/**
 * ==========================================================
 * COURSE FEES TABLE - UNIVERSAL FILE
 * ==========================================================
 * YE FILE SIRF "dusol" SUBDOMAIN PAR RAKHNI HAI:
 *   dynamic-data-files/course-fees-table-universal.php
 * (JSON files ke sath wahi folder)
 *
 * Baaki SAARE subdomains isko apni functions.php me copy-paste
 * NAHI karenge - wo bas ek chhota "loader" file use karenge jo
 * is file ko seedha yahin se require karega (dekho: loader file).
 * ==========================================================
 */

// Ek hi baar load ho - prevent duplicate declaration
if ( defined( 'COURSE_TABLE_UNIVERSAL_LOADED' ) ) {
    return;
}
define( 'COURSE_TABLE_UNIVERSAL_LOADED', true );

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
if (!function_exists('did_action')) {
    function did_action($tag)
    {
        global $sode_did_actions;
        return !empty($sode_did_actions[$tag]);
    }
}
if (!function_exists('do_action')) {
    function do_action($tag)
    {
        global $sode_did_actions;
        $sode_did_actions[$tag] = true;
    }
}

// ---------- SETTINGS ----------

if ( ! defined( 'COURSE_TABLE_VISIBLE_ROWS' ) ) {
    define( 'COURSE_TABLE_VISIBLE_ROWS', 10 );
}
if ( ! defined( 'COURSE_UNIVERSITIES_API_URL' ) ) {
    define( 'COURSE_UNIVERSITIES_API_URL', 'https://admin.distanceeducationschool.com/admin/api/get_course_universities.php' );
}

/**
 * Fetch course universities table data from Database or Central API
 */
function get_course_table_data( $course_key ) {
    static $cache = [];
    $course_key = strtolower( trim( $course_key ) );

    if ( empty( $course_key ) ) {
        $course_key = 'mba';
    }

    if ( isset( $cache[$course_key] ) ) {
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
                    SELECT id, course_slug, course_name, heading, description, columns_json, universities_json 
                    FROM course_universities_table 
                    WHERE LOWER(course_slug) = LOWER(?) OR LOWER(course_name) = LOWER(?)
                    LIMIT 1
                ");
                $stmt->execute([$course_key, $course_key]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $unis = [];
                    if (!empty($row['universities_json'])) {
                        $decoded = json_decode($row['universities_json'], true);
                        if (is_array($decoded)) {
                            $unis = $decoded;
                        }
                    }

                    $cols = ["University Name", $row['course_name'] . " Fee (Per Semester)", "Location", "Approvals & Accreditation", "Advantage"];
                    if (!empty($row['columns_json'])) {
                        $dec_cols = json_decode($row['columns_json'], true);
                        if (is_array($dec_cols)) {
                            $cols = $dec_cols;
                        }
                    }

                    $data = [
                        'course_slug'  => $row['course_slug'],
                        'course_name'  => $row['course_name'],
                        'heading'      => $row['heading'],
                        'description'  => $row['description'],
                        'columns'      => $cols,
                        'universities' => $unis
                    ];
                    $cache[$course_key] = $data;
                    return $data;
                }
            }
        } catch (Exception $e) {
            // Proceed to API fallback
        }
    }

    // 2. Central API Fallback (for remote subdomains)
    $api_url = COURSE_UNIVERSITIES_API_URL . '?course=' . urlencode($course_key);
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
        if (!empty($api_res['success']) && !empty($api_res['universities'])) {
            $data = [
                'course_slug'  => $api_res['course_slug'] ?? $course_key,
                'course_name'  => $api_res['course_name'] ?? strtoupper($course_key),
                'heading'      => $api_res['heading'] ?? '',
                'description'  => $api_res['description'] ?? '',
                'columns'      => $api_res['columns'] ?? ["University Name", "Fee (Per Semester)", "Location", "Approvals & Accreditation", "Advantage"],
                'universities' => $api_res['universities']
            ];
            $cache[$course_key] = $data;
            return $data;
        }
    }

    return false;
}



/**
 * Helper: University name se clean URL slug banata hai
 * Example: "Amity University" -> "amity-university"
 * "Lovely Professional University (LPU)" -> "lovely-professional-university-lpu"
 */
function get_uni_compare_slug( $uni ) {
    $name = isset( $uni['name'] ) ? $uni['name'] : '';
    $slug = strtolower( trim( $name ) );
    $slug = preg_replace( '/[^a-z0-9]+/i', '-', $slug );
    $slug = trim( $slug, '-' );
    return $slug;
}


/**
 * Ek university row ke saare <td> print karta hai.
 * 'advantage' field agar data me maujood hai to uska column bhi print hoga,
 * warna wo column simply skip ho jayega.
 * Last column me Compare Button aayega.
 */
function render_course_table_row_cells( $uni, $course_key = '' ) {
    $uni_name = isset( $uni['name'] ) ? $uni['name'] : '';
    $uni_slug = get_uni_compare_slug( $uni );
    ?>
    <td class="course-table-compare-cell course-table-col-mobile-only">
        <button type="button"
            class="uni-compare-toggle-btn"
            data-uni-name="<?php echo esc_attr( $uni_name ); ?>"
            data-uni-slug="<?php echo esc_attr( $uni_slug ); ?>"
            data-course="<?php echo esc_attr( $course_key ); ?>"
            aria-label="Compare <?php echo esc_attr( $uni_name ); ?>">
            <span class="compare-icon">+</span>
            <span class="compare-text">Compare</span>
        </button>
    </td>
    <td>
        <?php if ( ! empty( $uni['link'] ) ) : 
            $is_new_tab = (!isset($uni['new_tab']) || !empty($uni['new_tab']));
            $target_attr = $is_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        ?>
            <a href="<?php echo esc_url( $uni['link'] ); ?>"<?php echo $target_attr; ?> class="course-table-uni-link">
                <?php echo esc_html( $uni_name ); ?>
            </a>
        <?php else : ?>
            <?php echo esc_html( $uni_name ); ?>
        <?php endif; ?>
    </td>
    <td><?php echo esc_html( isset( $uni['fees'] ) ? $uni['fees'] : '' ); ?></td>
    <td><?php echo esc_html( isset( $uni['location'] ) ? $uni['location'] : '' ); ?></td>
    <td><?php echo esc_html( isset( $uni['accreditation'] ) ? $uni['accreditation'] : '' ); ?></td>
    <?php if ( isset( $uni['advantage'] ) ) : ?>
        <td><?php echo esc_html( $uni['advantage'] ); ?></td>
    <?php endif; ?>
    <td class="course-table-compare-cell course-table-col-desktop-only">
        <button type="button"
            class="uni-compare-toggle-btn"
            data-uni-name="<?php echo esc_attr( $uni_name ); ?>"
            data-uni-slug="<?php echo esc_attr( $uni_slug ); ?>"
            data-course="<?php echo esc_attr( $course_key ); ?>"
            aria-label="Compare <?php echo esc_attr( $uni_name ); ?>">
            <span class="compare-icon">+</span>
            <span class="compare-text">Compare</span>
        </button>
    </td>
    <?php
}


/**
 * SHORTCODE: [course_table course="mba"]
 * Isko kisi bhi subdomain ke kisi bhi page/Elementor widget me use kar sakte ho
 */
function course_table_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'course' => '',
    ), $atts );

    $course_key = strtolower( trim( $atts['course'] ) );

    if ( empty( $course_key ) ) {
        return '<p><em>Course table: course attribute missing.</em></p>';
    }

    $course_data = get_course_table_data( $course_key );

    // Agar data hi nahi mila (JSON down ho ya course exist na kare)
    if ( empty( $course_data ) || empty( $course_data['universities'] ) ) {
        return '<p><em>Table data currently unavailable. Please refresh shortly.</em></p>';
    }

    // Heading aur Description JSON se aayenge, agar maujood hon
    $table_heading     = isset( $course_data['heading'] ) ? $course_data['heading'] : '';
    $table_description = isset( $course_data['description'] ) ? $course_data['description'] : '';

    // $YEAR$ placeholder ko actual year se replace karo
    $current_year = function_exists( 'get_site_year' ) ? get_site_year() : date( 'Y' );
    $table_heading     = str_replace( '$YEAR$', $current_year, $table_heading );
    $table_description = str_replace( '$YEAR$', $current_year, $table_description );

    $columns      = isset( $course_data['columns'] ) ? $course_data['columns'] : array();
    $universities = $course_data['universities'];
    $total_rows   = count( $universities );
    $visible_rows = COURSE_TABLE_VISIBLE_ROWS;
    $has_more     = $total_rows > $visible_rows;

    // Har table instance ka unique ID (agar ek hi page pe 2+ tables ho to conflict na ho)
    static $table_instance = 0;
    $table_instance++;
    $unique_id = 'course-table-' . $course_key . '-' . $table_instance . '-' . wp_rand( 100, 999 );

    ob_start();
    ?>
    <div class="course-table-wrapper" data-course="<?php echo esc_attr( $course_key ); ?>">

        <?php if ( ! empty( $table_heading ) ) : ?>
            <h2 class="course-table-heading"><?php echo esc_html( $table_heading ); ?></h2>
        <?php endif; ?>

        <?php if ( ! empty( $table_description ) ) : ?>
            <p class="course-table-description"><?php echo esc_html( $table_description ); ?></p>
        <?php endif; ?>

        <div class="course-table-scroll">
            <table class="course-fees-table" id="<?php echo esc_attr( $unique_id ); ?>">
                <thead>
                    <tr>
                        <th class="course-table-th-compare course-table-col-mobile-only">COMPARE</th>
                        <?php foreach ( $columns as $col_label ) : ?>
                            <th><?php echo esc_html( strtoupper( $col_label ) ); ?></th>
                        <?php endforeach; ?>
                        <th class="course-table-th-compare course-table-col-desktop-only">COMPARE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $universities as $index => $uni ) :
                        if ( $index >= $visible_rows ) { break; }
                        ?>
                        <tr>
                            <?php render_course_table_row_cells( $uni, $course_key ); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ( $has_more ) : ?>
                <tbody class="course-table-extra-rows" style="display:none;">
                    <?php foreach ( $universities as $index => $uni ) :
                        if ( $index < $visible_rows ) { continue; }
                        ?>
                        <tr>
                            <?php render_course_table_row_cells( $uni, $course_key ); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php if ( $has_more ) : ?>
            <div class="course-table-btn-wrap">
                <button type="button"
                    class="course-table-toggle-btn"
                    data-target="<?php echo esc_attr( $unique_id ); ?>">
                    View More
                </button>
            </div>
        <?php endif; ?>

    </div>
    <?php

    // Style + Script + Floating Compare Dock sirf ek hi baar page pe print ho
    if ( ! did_action( 'course_table_assets_printed' ) ) {
        do_action( 'course_table_assets_printed' );
        ?>
        <!-- FLOATING COMPARE DOCK -->
        <div id="uni-compare-dock" class="uni-compare-dock" style="display:none;" aria-live="polite">
            <div class="uni-compare-dock-container">
                <div class="uni-compare-dock-info">
                    <div class="uni-compare-dock-title-wrap">
                        <span class="uni-compare-dock-title">Compare Universities</span>
                        <span class="uni-compare-dock-badge" id="uni-compare-count-badge">0/3</span>
                    </div>
                    <div class="uni-compare-chips-list" id="uni-compare-chips-list"></div>
                </div>
                <div class="uni-compare-dock-actions">
                    <button type="button" class="uni-compare-clear-btn" id="uni-compare-clear-btn">Clear</button>
                    <button type="button" class="uni-compare-submit-btn" id="uni-compare-submit-btn">
                        <span>Compare Now</span>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- TOAST ALERT FOR MAX LIMIT -->
        <div id="uni-compare-toast" class="uni-compare-toast" style="display:none;"></div>

        <style>
        .course-table-heading {
            font-size: 25px;
            font-weight: 600;
            line-height: 1.2;
            margin: 0 0 10px 0;
            color: #000;
        }
        .course-table-description {
            font-size: 13px;
            line-height: 1.46;
            color: #000;
            margin: 0 0 18px 0;
        }
        .course-table-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }
        .course-fees-table {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse !important;
            font-size: 13px;
            margin: 0px;
        }
        .course-fees-table thead th {
            background-color: #dbeafe !important;
            text-align: left;
            padding: 14px 16px;
            font-weight: 700;
            white-space: nowrap;
            border-bottom: 1px solid #cbd5e1 !important;
            border-right: 1px solid #b8d3f8 !important;
        }
        .course-fees-table thead th:last-child {
            border-right: none !important;
        }
        .course-fees-table thead th.course-table-th-compare {
            text-align: center;
            width: 120px;
        }
        .course-fees-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0 !important;
            border-right: 1px solid #e2e8f0 !important;
            vertical-align: middle;
        }
        .course-fees-table tbody td:last-child {
            border-right: none !important;
        }
        .course-fees-table tbody tr:hover {
            background-color: #f9fafb;
        }
        .course-table-uni-link {
            color: #1ab1f0;
            font-weight: 700;
            text-decoration: none;
        }
        .course-table-uni-link:hover {
            text-decoration: underline;
        }
        .course-table-btn-wrap {
            text-align: center;
            margin-top: 18px;
        }
        .course-table-toggle-btn {
            padding: 10px 26px;
            background-color: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: background-color 0.2s ease;
        }
        .course-table-toggle-btn:hover {
            background-color: #1d4ed8;
        }
        
        /* Column Display Toggle for Desktop vs Mobile */
        .course-table-col-mobile-only {
            display: none !important;
        }
        .course-table-col-desktop-only {
            display: table-cell !important;
        }

        /* COMPARE BUTTON IN ROW */
        .course-table-compare-cell {
            text-align: center;
            white-space: nowrap;
        }
        .uni-compare-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            background-color: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
        }
        .uni-compare-toggle-btn:hover {
            background-color: #dbeafe;
            border-color: #93c5fd;
            transform: translateY(-1px);
        }
        .uni-compare-toggle-btn.is-active {
            background-color: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 3px 10px rgba(37, 99, 235, 0.35);
        }
        .uni-compare-toggle-btn.is-active .compare-icon {
            transform: scale(1.1);
        }

        /* FLOATING COMPARE DOCK */
        .uni-compare-dock {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(0);
            z-index: 2147483647 !important;
            width: calc(100% - 32px);
            max-width: 900px;
            background: rgba(15, 23, 42, 0.94);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.05);
            color: #ffffff;
            padding: 14px 20px;
            animation: uniDockSlideUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            box-sizing: border-box;
            font-family: inherit;
        }
        @keyframes uniDockSlideUp {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        .uni-compare-dock-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .uni-compare-dock-info {
            display: flex;
            align-items: center;
            gap: 14px;
            flex: 1;
            min-width: 280px;
            flex-wrap: wrap;
        }
        .uni-compare-dock-title-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .uni-compare-dock-title {
            font-size: 14px;
            font-weight: 700;
            color: #f8fafc;
            letter-spacing: 0.2px;
        }
        .uni-compare-dock-badge {
            background-color: #3b82f6;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 10px;
            letter-spacing: 0.5px;
        }
        .uni-compare-chips-list {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .uni-compare-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #f1f5f9;
            padding: 4px 10px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 500;
            animation: uniChipPop 0.2s ease-out;
        }
        @keyframes uniChipPop {
            from { transform: scale(0.85); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .uni-compare-chip-remove {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 14px;
            line-height: 1;
            padding: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s;
        }
        .uni-compare-chip-remove:hover {
            color: #ef4444;
        }
        .uni-compare-chip-slot {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border: 1px dashed rgba(255, 255, 255, 0.2);
            border-radius: 14px;
            font-size: 11px;
            color: #64748b;
        }
        .uni-compare-dock-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .uni-compare-clear-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 12px;
            cursor: pointer;
            transition: color 0.15s;
        }
        .uni-compare-clear-btn:hover {
            color: #ffffff;
            text-decoration: underline;
        }
        .uni-compare-submit-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 9px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
            transition: all 0.2s ease;
        }
        .uni-compare-submit-btn:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(37, 99, 235, 0.5);
        }
        .uni-compare-submit-btn:active {
            transform: translateY(0);
        }

        /* TOAST NOTIFICATION */
        .uni-compare-toast {
            position: fixed;
            bottom: 95px;
            left: 50%;
            transform: translateX(-50%);
            background: #ef4444;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.35);
            z-index: 2147483647 !important;
            animation: uniToastFade 0.25s ease-out;
        }
        @keyframes uniToastFade {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        @media (max-width: 768px) {
            /* 1. Mobile pe Compare column First aayega */
            .course-table-col-mobile-only {
                display: table-cell !important;
            }
            .course-table-col-desktop-only {
                display: none !important;
            }

            /* 2. DYNAMICALLY HIDE WHATSAPP & GETBUTTON WIDGETS ON MOBILE ONLY WHEN COMPARE DOCK IS OPEN */
            body.has-uni-compare-dock-open #gb-waw-iframe,
            body.has-uni-compare-dock-open [id*="gb-waw"],
            body.has-uni-compare-dock-open [class*="gb-waw"],
            body.has-uni-compare-dock-open [class*="whatsapp"],
            body.has-uni-compare-dock-open [id*="whatsapp"],
            body.has-uni-compare-dock-open [class*="joinchat"],
            body.has-uni-compare-dock-open [id*="joinchat"],
            body.has-uni-compare-dock-open [class*="ht-ctc"],
            body.has-uni-compare-dock-open [id*="ht-ctc"],
            body.has-uni-compare-dock-open [class*="chaty"],
            body.has-uni-compare-dock-open [id*="chaty"],
            body.has-uni-compare-dock-open [class*="qlwapp"],
            body.has-uni-compare-dock-open [id*="qlwapp"],
            body.has-uni-compare-dock-open [class*="get-help"],
            body.has-uni-compare-dock-open [id*="get-help"] {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
            }

            /* 2. Table Width Compact & Headings in 2 Lines */
            .course-fees-table {
                min-width: 530px;
                font-size: 12px;
            }
            .course-fees-table thead th {
                padding: 10px 8px !important;
                white-space: normal !important;
                word-break: normal !important;
                overflow-wrap: normal !important;
                line-height: 1.3 !important;
                font-size: 11.5px !important;
                vertical-align: middle !important;
                border-bottom: 1px solid #cbd5e1 !important;
                border-right: 1px solid #b8d3f8 !important;
                text-align: left;
            }
            .course-fees-table thead th.course-table-th-compare {
                width: 86px;
                min-width: 86px;
                max-width: 86px;
                text-align: center;
                padding: 10px 4px !important;
            }
            .course-fees-table tbody td {
                padding: 10px 8px !important;
                font-size: 12px !important;
                line-height: 1.35 !important;
                vertical-align: middle !important;
                border-bottom: 1px solid #e2e8f0 !important;
                border-right: 1px solid #e2e8f0 !important;
            }
            /* Hide border on last visible mobile column */
            .course-fees-table thead th:nth-last-child(2),
            .course-fees-table tbody td:nth-last-child(2) {
                border-right: none !important;
            }
            .course-table-compare-cell {
                padding: 8px 4px !important;
                text-align: center;
            }
            .uni-compare-toggle-btn {
                padding: 5px 9px;
                font-size: 11px;
                gap: 3px;
                border-radius: 14px;
            }

            /* 3. Floating Compare Dock - Mobile layout & Max Priority */
            .uni-compare-dock {
                bottom: 12px;
                padding: 12px 14px;
                width: calc(100% - 20px);
                border-radius: 14px;
                z-index: 2147483647 !important;
                box-shadow: 0 15px 35px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.15);
            }
            .uni-compare-dock-container {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .uni-compare-dock-info {
                min-width: 100%;
                justify-content: space-between;
            }
            .uni-compare-dock-actions {
                justify-content: flex-end;
            }
            .uni-compare-submit-btn {
                flex: 1;
                justify-content: center;
                padding: 10px 16px;
            }
            .uni-compare-toast {
                bottom: 135px;
                width: calc(100% - 32px);
                text-align: center;
                box-sizing: border-box;
                z-index: 2147483647 !important;
            }
        }
        </style>

        <script>
        (function() {
            var selectedUnis = []; // Array of { name, slug, course }
            var maxSelections = 3;
            var toastTimer = null;

            function showToast(message) {
                var toast = document.getElementById('uni-compare-toast');
                if (!toast) return;
                toast.textContent = message;
                toast.style.display = 'block';
                if (toastTimer) clearTimeout(toastTimer);
                toastTimer = setTimeout(function() {
                    toast.style.display = 'none';
                }, 2800);
            }

            function updateUI() {
                var dock = document.getElementById('uni-compare-dock');
                var countBadge = document.getElementById('uni-compare-count-badge');
                var chipsList = document.getElementById('uni-compare-chips-list');

                // Update table row button states
                var allBtns = document.querySelectorAll('.uni-compare-toggle-btn');
                allBtns.forEach(function(btn) {
                    var slug = btn.getAttribute('data-uni-slug');
                    var isSelected = selectedUnis.some(function(item) { return item.slug === slug; });
                    if (isSelected) {
                        btn.classList.add('is-active');
                        btn.querySelector('.compare-icon').textContent = '✓';
                        btn.querySelector('.compare-text').textContent = 'Selected';
                    } else {
                        btn.classList.remove('is-active');
                        btn.querySelector('.compare-icon').textContent = '+';
                        btn.querySelector('.compare-text').textContent = 'Compare';
                    }
                });

                function setExternalWidgetVisibility(visible) {
                    var waEl = document.getElementById('gb-waw-iframe');
                    if (waEl) {
                        if (window.innerWidth <= 768) {
                            waEl.style.setProperty('display', visible ? '' : 'none', 'important');
                            waEl.style.setProperty('visibility', visible ? '' : 'hidden', 'important');
                        } else {
                            waEl.style.removeProperty('display');
                            waEl.style.removeProperty('visibility');
                        }
                    }
                }

                if (!dock || !countBadge || !chipsList) return;

                if (selectedUnis.length === 0) {
                    document.body.classList.remove('has-uni-compare-dock-open');
                    setExternalWidgetVisibility(true);
                    dock.style.display = 'none';
                    return;
                }

                document.body.classList.add('has-uni-compare-dock-open');
                setExternalWidgetVisibility(false);
                dock.style.display = 'block';
                countBadge.textContent = selectedUnis.length + '/' + maxSelections;

                // Render selected chips
                var html = '';
                selectedUnis.forEach(function(item, idx) {
                    html += '<div class="uni-compare-chip">' +
                        '<span>' + escapeHTML(item.name) + '</span>' +
                        '<button type="button" class="uni-compare-chip-remove" data-slug="' + escapeHTML(item.slug) + '" aria-label="Remove ' + escapeHTML(item.name) + '">&times;</button>' +
                        '</div>';
                });

                // Render remaining dashed slots
                var remaining = maxSelections - selectedUnis.length;
                for (var i = 0; i < remaining; i++) {
                    html += '<div class="uni-compare-chip-slot">+ Add University</div>';
                }

                chipsList.innerHTML = html;
            }

            function escapeHTML(str) {
                var div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            // Global Click Delegation
            document.addEventListener('click', function(e) {
                // 1. Toggle Button in Table
                var toggleBtn = e.target.closest('.uni-compare-toggle-btn');
                if (toggleBtn) {
                    var slug = toggleBtn.getAttribute('data-uni-slug');
                    var name = toggleBtn.getAttribute('data-uni-name');
                    var course = toggleBtn.getAttribute('data-course');

                    var existingIndex = selectedUnis.findIndex(function(item) { return item.slug === slug; });

                    if (existingIndex > -1) {
                        selectedUnis.splice(existingIndex, 1);
                    } else {
                        if (selectedUnis.length >= maxSelections) {
                            showToast('You can compare a maximum of 3 universities.');
                            return;
                        }
                        selectedUnis.push({ name: name, slug: slug, course: course });
                    }
                    updateUI();
                    return;
                }

                // 2. Remove Chip in Dock
                var removeBtn = e.target.closest('.uni-compare-chip-remove');
                if (removeBtn) {
                    var removeSlug = removeBtn.getAttribute('data-slug');
                    selectedUnis = selectedUnis.filter(function(item) { return item.slug !== removeSlug; });
                    updateUI();
                    return;
                }

                // 3. Clear All Button
                if (e.target.closest('#uni-compare-clear-btn')) {
                    selectedUnis = [];
                    updateUI();
                    return;
                }

                // 4. Submit / Compare Now Button
                if (e.target.closest('#uni-compare-submit-btn')) {
                    if (selectedUnis.length === 0) return;
                    
                    var slugs = selectedUnis.map(function(item) { 
                        return item.slug; 
                    }).join(',');
                    var course = selectedUnis[0].course || '';
                    var redirectUrl = 'https://distanceeducationschool.com/compare-university/?university=' + slugs + '&course=' + encodeURIComponent(course);
                    
                    window.open(redirectUrl, '_blank');
                    return;
                }

                // 5. Table "View More" Toggle
                if (e.target.classList.contains('course-table-toggle-btn')) {
                    var btn = e.target;
                    var table = document.getElementById(btn.getAttribute('data-target'));
                    if (!table) return;

                    var extraTbody = table.querySelector('.course-table-extra-rows');
                    if (!extraTbody) return;

                    var isHidden = extraTbody.style.display === 'none';
                    extraTbody.style.display = isHidden ? 'table-row-group' : 'none';
                    btn.textContent = isHidden ? 'View Less' : 'View More';
                }
            });
        })();
        </script>
        <?php
    }

    return ob_get_clean();
}

function sode_course_universities_table_render( $atts ) {
    return course_table_shortcode( $atts );
}

if ( function_exists( 'add_shortcode' ) ) {
    add_shortcode( 'course_table', 'course_table_shortcode' );
    add_shortcode( 'universities_table', 'course_table_shortcode' );
    add_shortcode( 'course_universities', 'course_table_shortcode' );
    add_shortcode( 'top_universities', 'course_table_shortcode' );
}

