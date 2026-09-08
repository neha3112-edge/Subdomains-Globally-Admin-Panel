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
if ( defined( 'COURSE_TABLE_UNIVERSAL_LOADED' ) ) {
    return;
}
define( 'COURSE_TABLE_UNIVERSAL_LOADED', true );

// ---------- SETTINGS: Universal JSON URL ----------
if ( ! defined( 'COURSE_TABLE_JSON_URL' ) ) {
    define( 'COURSE_TABLE_JSON_URL', 'https://dusol.distanceeducationschool.com/dynamic-data-files/subdomain_course_fees_table_date.json' );
}

// Initially kitni rows dikhani hain, baaki "View More" ke peeche chhupi rahengi
if ( ! defined( 'COURSE_TABLE_VISIBLE_ROWS' ) ) {
    define( 'COURSE_TABLE_VISIBLE_ROWS', 10 );
}


/**
 * Universal JSON se poora data fetch karo
 * (NO CACHING - har page load pe fresh data aayega, JSON change turant reflect hoga)
 */
function get_course_table_data_all() {

    $response = wp_remote_get( COURSE_TABLE_JSON_URL, array(
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
 * Ek specific course ka data nikalo (jaise "mba", "mca")
 */
function get_course_table_data( $course_key ) {
    $all_data = get_course_table_data_all();

    if ( empty( $all_data ) || ! isset( $all_data[ $course_key ] ) ) {
        return false;
    }

    return $all_data[ $course_key ];
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
    <td>
        <?php if ( ! empty( $uni['link'] ) ) : ?>
            <a href="<?php echo esc_url( $uni['link'] ); ?>" target="_blank" rel="noopener noreferrer" class="course-table-uni-link">
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
    <td class="course-table-compare-cell">
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
                        <?php foreach ( $columns as $col_label ) : ?>
                            <th><?php echo esc_html( strtoupper( $col_label ) ); ?></th>
                        <?php endforeach; ?>
                        <th class="course-table-th-compare">COMPARE</th>
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
            border-collapse: collapse;
            font-size: 13px;
            margin: 0px;
        }
        .course-fees-table thead th {
            background-color: #dbeafe;
            text-align: left;
            padding: 14px 16px;
            font-weight: 700;
            white-space: nowrap;
            border-bottom: 1px solid #e5e7eb;
            border-right: 1px solid #c7d9f5;
        }
        .course-fees-table thead th:last-child {
            border-right: none;
        }
        .course-fees-table thead th.course-table-th-compare {
            text-align: center;
            width: 120px;
        }
        .course-fees-table tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #eef0f3;
            border-right: 1px solid #eef0f3;
            vertical-align: middle;
        }
        .course-fees-table tbody td:last-child {
            border-right: none;
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
            z-index: 999999;
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
            z-index: 1000000;
            animation: uniToastFade 0.25s ease-out;
        }
        @keyframes uniToastFade {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        @media (max-width: 768px) {
            .uni-compare-dock {
                bottom: 12px;
                padding: 12px 14px;
                width: calc(100% - 20px);
                border-radius: 14px;
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

                if (!dock || !countBadge || !chipsList) return;

                if (selectedUnis.length === 0) {
                    dock.style.display = 'none';
                    return;
                }

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
                    
                    var slugs = selectedUnis.map(function(item) { return item.slug; }).join(',');
                    var course = selectedUnis[0].course || '';
                    var redirectUrl = 'https://distanceeducationschool.com/compare-university?university=' + encodeURIComponent(slugs) + '&course=' + encodeURIComponent(course);
                    
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
add_shortcode( 'course_table', 'course_table_shortcode' );

