<?php
/**
 * ==========================================================
 * JOB ROLES & SALARY TABLE - UNIVERSAL FILE
 * ==========================================================
 * YE FILE SIRF "dusol" SUBDOMAIN PAR RAKHNI HAI:
 *   dynamic-data-files/job-roles-table-universal.php
 * (JSON files ke sath wahi folder)
 *
 * Baaki SAARE subdomains isko apni functions.php me copy-paste
 * NAHI karenge - wo bas ek chhota "loader" file use karenge jo
 * is file ko seedha yahin se require karega (dekho: loader file).
 *
 * Isliye ab HTML/CSS/JS sirf EK jagah hai - jo bhi change karna
 * ho (design, columns, "View More" text, wagera) - sirf isi file
 * me karo, sab subdomains par apne aap reflect ho jaayega.
 * ==========================================================
 */

// Ye file "dynamic-data-files" folder me hai jo public URL se bhi
// accessible hai (JSON files ke liye). Agar isko seedha browser me
// khola jaye (WordPress load kiye bina) to WP functions maujood
// nahi honge - is case me clean 403 de kar turant ruk jao, taaki
// raw PHP error (jo file path leak kar sakta hai) na dikhe.
if ( ! function_exists( 'add_shortcode' ) ) {
    http_response_code( 403 );
    exit;
}

// Ek hi baar load ho - agar kabhi galti se dobara require ho jaye
// to fatal error (function already declared) na aaye.
if ( defined( 'JOB_ROLES_TABLE_UNIVERSAL_LOADED' ) ) {
    return;
}
define( 'JOB_ROLES_TABLE_UNIVERSAL_LOADED', true );

// ---------- SETTINGS: Universal JSON URL ----------
if ( ! defined( 'JOB_ROLES_TABLE_JSON_URL' ) ) {
    define( 'JOB_ROLES_TABLE_JSON_URL', 'https://dusol.distanceeducationschool.com/dynamic-data-files/subdomain_job_roles_table_data.json' );
}

// Initially kitni rows dikhani hain, baaki "View More" ke peeche chhupi rahengi
if ( ! defined( 'JOB_ROLES_TABLE_VISIBLE_ROWS' ) ) {
    define( 'JOB_ROLES_TABLE_VISIBLE_ROWS', 10 );
}


/**
 * JSON se poora data fetch karo
 * (NO CACHING - har page load pe fresh data aayega, JSON change turant reflect hoga)
 */
function get_job_roles_table_data_all() {

    $response = wp_remote_get( JOB_ROLES_TABLE_JSON_URL, array(
        'timeout' => 10,
        'headers' => array(
            'Cache-Control' => 'no-cache',
        ),
    ) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return false;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        return false;
    }

    return $data;
}


/**
 * Ek specific course ka JOB ROLES data nikalo (jaise "mba", "mca")
 */
function get_job_roles_table_data( $course_key ) {
    $all_data = get_job_roles_table_data_all();

    if ( empty( $all_data ) || ! isset( $all_data[ $course_key ] ) ) {
        return false;
    }

    return $all_data[ $course_key ];
}


/**
 * Ek job-role row ke <td> print karta hai
 */
function render_job_roles_table_row_cells( $role ) {
    ?>
    <td><strong><?php echo esc_html( $role['role'] ); ?></strong></td>
    <td><?php echo esc_html( $role['description'] ); ?></td>
    <td><?php echo esc_html( $role['salary'] ); ?></td>
    <?php
}


/**
 * SHORTCODE: [job_roles_table course="mba"]
 * Isko kisi bhi subdomain ke kisi bhi page/Elementor widget me use kar sakte ho
 */
function job_roles_table_shortcode( $atts ) {

    $atts = shortcode_atts( array(
        'course' => '',
    ), $atts );

    $course_key = strtolower( trim( $atts['course'] ) );

    if ( empty( $course_key ) ) {
        return '<p><em>Job roles table: course attribute missing.</em></p>';
    }

    $course_data = get_job_roles_table_data( $course_key );

    if ( empty( $course_data ) || empty( $course_data['roles'] ) ) {
        return '<p><em>Job roles data currently unavailable. Please refresh shortly.</em></p>';
    }

    // Heading aur Description JSON se aayenge, agar maujood hon
    $table_heading     = isset( $course_data['heading'] ) ? $course_data['heading'] : '';
    $table_description = isset( $course_data['description'] ) ? $course_data['description'] : '';

    // $YEAR$ placeholder ko actual year se replace karo
    $current_year = function_exists( 'get_site_year' ) ? get_site_year() : date( 'Y' );
    $table_heading     = str_replace( '$YEAR$', $current_year, $table_heading );
    $table_description = str_replace( '$YEAR$', $current_year, $table_description );

    $columns      = isset( $course_data['columns'] ) ? $course_data['columns'] : array();
    $roles        = $course_data['roles'];
    $total_rows   = count( $roles );
    $visible_rows = JOB_ROLES_TABLE_VISIBLE_ROWS;
    $has_more     = $total_rows > $visible_rows;

    // Har table instance ka unique ID (agar ek hi page pe 2+ tables ho to conflict na ho)
    static $table_instance = 0;
    $table_instance++;
    $unique_id = 'job-roles-table-' . $course_key . '-' . $table_instance . '-' . wp_rand( 100, 999 );

    ob_start();
    ?>
    <div class="job-roles-table-wrapper">

        <?php if ( ! empty( $table_heading ) ) : ?>
            <h2 class="job-roles-table-heading"><?php echo esc_html( $table_heading ); ?></h2>
        <?php endif; ?>

        <?php if ( ! empty( $table_description ) ) : ?>
            <p class="job-roles-table-description"><?php echo esc_html( $table_description ); ?></p>
        <?php endif; ?>

        <div class="job-roles-table-scroll">
            <table class="job-roles-fees-table" id="<?php echo esc_attr( $unique_id ); ?>">
                <thead>
                    <tr>
                        <?php foreach ( $columns as $col_label ) : ?>
                            <th><?php echo esc_html( strtoupper( $col_label ) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $roles as $index => $role ) :
                        if ( $index >= $visible_rows ) { break; }
                        ?>
                        <tr>
                            <?php render_job_roles_table_row_cells( $role ); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ( $has_more ) : ?>
                <tbody class="job-roles-table-extra-rows" style="display:none;">
                    <?php foreach ( $roles as $index => $role ) :
                        if ( $index < $visible_rows ) { continue; }
                        ?>
                        <tr>
                            <?php render_job_roles_table_row_cells( $role ); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endif; ?>
            </table>
        </div>

        <?php if ( $has_more ) : ?>
            <div class="job-roles-table-btn-wrap">
                <button type="button"
                    class="job-roles-table-toggle-btn"
                    data-target="<?php echo esc_attr( $unique_id ); ?>">
                    View More
                </button>
            </div>
        <?php endif; ?>

    </div>
    <?php

    // Style + Script sirf ek hi baar page pe print ho (baar baar nahi, chahe kitni bhi tables ho)
    if ( ! did_action( 'job_roles_table_assets_printed' ) ) {
        do_action( 'job_roles_table_assets_printed' );
        ?>
        <style>
        .job-roles-table-heading {
            font-size: 25px;
            font-weight: 600;
            line-height: 1.2;
            margin: 0 0 10px 0;
            color: #000;
        }
        .job-roles-table-description {
            font-size: 13px;
            line-height: 1.46;
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
        }
        .job-roles-fees-table {
            width: 100%;
            min-width: 650px;
            border-collapse: collapse;
            font-size: 13px;
            margin: 0px;
        }
        .job-roles-fees-table thead th {
            background-color: #dbeafe;
            text-align: left;
            padding: 14px 16px;
            font-weight: 700;
            white-space: nowrap;
            border-bottom: 1px solid #e5e7eb;
            border-right: 1px solid #c7d9f5;
        }
        .job-roles-fees-table thead th:last-child {
            border-right: none;
        }
        .job-roles-fees-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #eef0f3;
            border-right: 1px solid #eef0f3;
            vertical-align: middle;
        }
        .job-roles-fees-table tbody td:last-child {
            border-right: none;
        }
        .job-roles-fees-table tbody tr:hover {
            background-color: #f9fafb;
        }
        .job-roles-table-btn-wrap {
            text-align: center;
            margin-top: 18px;
        }
        .job-roles-table-toggle-btn {
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
        .job-roles-table-toggle-btn:hover {
            background-color: #1d4ed8;
        }
        @media (max-width: 600px) {
            .job-roles-fees-table {
                font-size: 14px;
            }
            .job-roles-fees-table thead th,
            .job-roles-fees-table tbody td {
                padding: 12px;
            }
        }
        </style>
        <script>
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('job-roles-table-toggle-btn')) {
                return;
            }
            var btn = e.target;
            var table = document.getElementById(btn.getAttribute('data-target'));
            if (!table) { return; }

            var extraTbody = table.querySelector('.job-roles-table-extra-rows');
            if (!extraTbody) { return; }

            var isHidden = extraTbody.style.display === 'none';
            extraTbody.style.display = isHidden ? 'table-row-group' : 'none';
            btn.textContent = isHidden ? 'View Less' : 'View More';
        });
        </script>
        <?php
    }

    return ob_get_clean();
}
add_shortcode( 'job_roles_table', 'job_roles_table_shortcode' );
