<?php
/**
 * ====================================================================
 * Universal University Admission & Application Process Component
 * File: admission-process-universal.php
 * 
 * Renders dynamic university application route / admission process section
 * with 8 steps, alternating top/bottom desktop cards, and vertical mobile timeline.
 * 
 * Shortcodes:
 *  1. [university_application_process]
 *  2. [idol_application_process]
 *  3. [uni_application_process]
 *  4. [admission_process]
 *  5. [application_process]
 * ====================================================================
 */

if (defined('SODE_ADMISSION_PROCESS_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_ADMISSION_PROCESS_UNIVERSAL_LOADED', true);

// Safe polyfills for WP helpers
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $title), '-'));
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

/**
 * Helper: Resolve University Name & Info dynamically
 */
if (!function_exists('sode_get_university_profile')) {
    function sode_get_university_profile($uni_slug = '')
    {
        if (empty($uni_slug)) {
            if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
                $uni_slug = sanitize_title(SODE_UNIVERSITY_SLUG);
            } else {
                $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
                $parts = explode('.', $host);
                if (count($parts) >= 3 && !in_array($parts[0], ['www', 'mail', 'admin', 'cpanel', 'webmail'])) {
                    $uni_slug = sanitize_title($parts[0]);
                }
            }
        }

        $uni_info = null;

        // 1. Direct Local DB Check
        if (!function_exists('get_db_connection')) {
            $possible_configs = [
                __DIR__ . '/admin/config/config.php',
                dirname(__DIR__) . '/admin/config/config.php',
                dirname(__DIR__, 2) . '/admin/config/config.php',
            ];
            foreach ($possible_configs as $cfg) {
                if (file_exists($cfg)) {
                    require_once $cfg;
                    break;
                }
            }
        }

        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    if (!empty($uni_slug)) {
                        $stmt = $db->prepare("SELECT id, slug, short_name, full_name FROM universities WHERE (LOWER(slug) = ? OR LOWER(short_name) = ? OR LOWER(full_name) = ?) AND is_active = 1 LIMIT 1");
                        $stmt->execute([$uni_slug, $uni_slug, $uni_slug]);
                        $uni_info = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$uni_info) {
                        $uni_info = $db->query("SELECT id, slug, short_name, full_name FROM universities WHERE is_active = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    }
                }
            } catch (Exception $e) {
            }
        }

        // 2. Default name formatting fallback
        if ($uni_info) {
            $full_name = !empty($uni_info['full_name']) ? trim($uni_info['full_name']) : trim($uni_info['short_name']);
            $short_name = !empty($uni_info['short_name']) ? trim($uni_info['short_name']) : $full_name;
            return [
                'slug'       => $uni_info['slug'],
                'short_name' => $short_name,
                'full_name'  => $full_name,
            ];
        }

        $clean_name = !empty($uni_slug) ? ucwords(str_replace(['-', '_'], ' ', $uni_slug)) : 'Dayananda Sagar University';
        return [
            'slug'       => $uni_slug ?: 'dsu',
            'short_name' => strtoupper($uni_slug ?: 'DSU'),
            'full_name'  => $clean_name,
        ];
    }
}

/**
 * Render Universal Application Process Section
 */
if (!function_exists('sode_application_process_render')) {
    function sode_application_process_render($atts = [])
    {
        $atts = shortcode_atts([
            'university' => '',
            'uni'        => '',
            'mode'       => 'Online',
            'fee'        => 'INR 1,000',
            'heading'    => '',
            'class'      => '',
        ], $atts);

        $uni_slug = !empty($atts['university']) ? $atts['university'] : $atts['uni'];
        $uni_profile = sode_get_university_profile($uni_slug);

        $uni_full_name  = $uni_profile['full_name'];
        $uni_short_name = $uni_profile['short_name'];
        $mode_text      = !empty($atts['mode']) ? trim($atts['mode']) : 'Online';
        $fee_text       = !empty($atts['fee']) ? trim($atts['fee']) : 'INR 1,000';

        // ---- Dynamic Steps: Load from DB → API → Hardcoded Fallback ----
        $steps = [];

        // 1. Try direct DB (when running on the admin server itself)
        if (empty($steps) && function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    $uni_id_for_steps = null;
                    if (!empty($uni_profile['slug'])) {
                        $stu = $db->prepare("SELECT id FROM universities WHERE slug = ? LIMIT 1");
                        $stu->execute([$uni_profile['slug']]);
                        $uni_id_for_steps = $stu->fetchColumn() ?: null;
                    }
                    if ($uni_id_for_steps) {
                        $stmts = $db->prepare("SELECT * FROM admission_process_steps WHERE university_id = ? ORDER BY step_number ASC, id ASC");
                        $stmts->execute([$uni_id_for_steps]);
                        $db_rows = $stmts->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($db_rows)) $steps = $db_rows;
                    }
                    if (empty($steps)) {
                        $db_rows = $db->query("SELECT * FROM admission_process_steps WHERE university_id IS NULL ORDER BY step_number ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($db_rows)) $steps = $db_rows;
                    }
                    // Normalize DB rows → component format
                    if (!empty($steps)) {
                        $steps = array_map(function($row) use ($uni_short_name, $uni_full_name, $mode_text, $fee_text) {
                            $title = str_replace(
                                ['{uni}', '{uni_short}', '{mode}', '{fee}', '{university}'],
                                [$uni_full_name, $uni_short_name, $mode_text, $fee_text, $uni_full_name],
                                $row['title']
                            );
                            $desc = str_replace(
                                ['{uni}', '{uni_short}', '{mode}', '{fee}', '{university}'],
                                [$uni_full_name, $uni_short_name, $mode_text, $fee_text, $uni_full_name],
                                $row['description']
                            );
                            return [
                                'num'   => (int)$row['step_number'],
                                'color' => $row['color_hex'],
                                'title' => $title,
                                'desc'  => $desc,
                                'icon'  => $row['icon_svg'] ?? '',
                            ];
                        }, $steps);
                    }
                }
            } catch (Exception $e) {
                $steps = [];
            }
        }

        // 2. Try remote API (when running as WordPress plugin on client subdomain)
        if (empty($steps) && !function_exists('get_db_connection')) {
            $api_base = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $api_url  = $api_base . '/api/get_admission_steps.php?uni=' . urlencode($uni_profile['slug']);
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $raw = @file_get_contents($api_url, false, $ctx);
            if ($raw) {
                $json = json_decode($raw, true);
                if (!empty($json['steps']) && is_array($json['steps'])) {
                    $steps = array_map(function($s) use ($uni_short_name, $uni_full_name, $mode_text, $fee_text) {
                        $title = str_replace(
                            ['{uni}', '{uni_short}', '{mode}', '{fee}', '{university}'],
                            [$uni_full_name, $uni_short_name, $mode_text, $fee_text, $uni_full_name],
                            $s['title']
                        );
                        $desc = str_replace(
                            ['{uni}', '{uni_short}', '{mode}', '{fee}', '{university}'],
                            [$uni_full_name, $uni_short_name, $mode_text, $fee_text, $uni_full_name],
                            $s['desc']
                        );
                        return [
                            'num'   => (int)$s['num'],
                            'color' => $s['color'],
                            'title' => $title,
                            'desc'  => $desc,
                            'icon'  => $s['icon'] ?? '',
                        ];
                    }, $json['steps']);
                }
            }
        }

        // 3. Hardcoded fallback (always correct, never empty)
        if (empty($steps)) {
            $steps = [
                [
                    'num'   => 1,
                    'color' => '#E23F73',
                    'title' => 'Visit the ' . $uni_short_name . ' ' . $mode_text . ' website',
                    'desc'  => 'Go to the official ' . $uni_short_name . ' ' . strtolower($mode_text) . ' admission portal to register yourself.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><line x1="3" y1="12" x2="21" y2="12"></line><path d="M12 3a13.7 13.7 0 0 1 3.5 9A13.7 13.7 0 0 1 12 21a13.7 13.7 0 0 1-3.5-9A13.7 13.7 0 0 1 12 3z"></path></svg>'
                ],
                [
                    'num'   => 2,
                    'color' => '#E8622F',
                    'title' => 'Verify the Registration',
                    'desc'  => 'Confirm the registered email and mobile number via OTP-based secure access.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M19 8v6M22 11h-6"></path></svg>'
                ],
                [
                    'num'   => 3,
                    'color' => '#EFA23C',
                    'title' => 'Pay Application Fee',
                    'desc'  => 'Complete the one-time, non-refundable application fee of ' . $fee_text . ' through its secure payment gateways.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>'
                ],
                [
                    'num'   => 4,
                    'color' => '#2FB897',
                    'title' => 'Fill the Application Form',
                    'desc'  => 'Mention personal, academic and professional information accurately.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line><line x1="6" y1="15" x2="10" y2="15"></line></svg>'
                ],
                [
                    'num'   => 5,
                    'color' => '#2FA0B8',
                    'title' => 'Upload the Documents',
                    'desc'  => 'Submit the scanned copies of the photographs, certificates and identity proofs.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>'
                ],
                [
                    'num'   => 6,
                    'color' => '#3B7FD1',
                    'title' => 'Submit Application',
                    'desc'  => 'Review all details and submit your application for processing.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>'
                ],
                [
                    'num'   => 7,
                    'color' => '#4A5FC7',
                    'title' => 'Document Verification',
                    'desc'  => $uni_full_name . ' will verify your details and the originality of your documents.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="M9 12l2 2 4-4"></path></svg>'
                ],
                [
                    'num'   => 8,
                    'color' => '#2C3E7A',
                    'title' => 'Admission Confirmation',
                    'desc'  => 'You will get a confirmation email once your admission is successfully verified.',
                    'icon'  => '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12z"></path><polyline points="9 11 11 13 15 9"></polyline><path d="M8.5 14.5L7 22l5-3 5 3-1.5-7.5"></path></svg>'
                ]
            ];
        }

        $gradient_stops = [];
        foreach ($steps as $s) {
            $gradient_stops[] = $s['color'];
        }
        $gradient_css = implode(', ', $gradient_stops);
        $unique_id = 'sode_proc_' . substr(md5(uniqid(rand(), true)), 0, 8);

        ob_start();
        ?>
        <section id="<?php echo esc_attr($unique_id); ?>" class="idolx-section <?php echo esc_attr($atts['class']); ?>">

            <div class="idolx-head">
                <?php if (!empty($atts['heading'])): ?>
                    <h2 class="idolx-title"><?php echo esc_html($atts['heading']); ?></h2>
                <?php else: ?>
                    <h2 class="idolx-title"><?php echo esc_html($uni_full_name); ?> <span class="idolx-grad"><?php echo esc_html($mode_text); ?> Application Process</span></h2>
                <?php endif; ?>
            </div>

            <!-- DESKTOP -->
            <div class="idolx-desktop">

                <!-- top card row -->
                <div class="idolx-flex-row idolx-top-row">
                    <?php foreach ($steps as $i => $step): $isOdd = (($i + 1) % 2 === 1); ?>
                        <?php if ($isOdd): ?>
                            <div class="idolx-flex-item idolx-card">
                                <div class="idolx-icon" style="background:<?php echo esc_attr($step['color']); ?>;"><?php echo $step['icon']; ?></div>
                                <p class="idolx-card-title"><?php echo esc_html($step['title']); ?></p>
                                <p class="idolx-card-desc"><?php echo esc_html($step['desc']); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="idolx-flex-item"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <!-- top stems -->
                <div class="idolx-stem-row">
                    <?php foreach ($steps as $i => $step): $isOdd = (($i + 1) % 2 === 1); ?>
                        <div class="idolx-stem-cell">
                            <?php if ($isOdd): ?>
                                <div class="idolx-stem" style="--c:<?php echo esc_attr($step['color']); ?>;"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- track + nodes -->
                <div class="idolx-track-row">
                    <div class="idolx-track-line"></div>
                    <?php foreach ($steps as $step): ?>
                        <div class="idolx-node-cell">
                            <div class="idolx-node" style="background:<?php echo esc_attr($step['color']); ?>;"><?php echo esc_html($step['num']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- bottom stems -->
                <div class="idolx-stem-row">
                    <?php foreach ($steps as $i => $step): $isOdd = (($i + 1) % 2 === 1); ?>
                        <div class="idolx-stem-cell">
                            <?php if (!$isOdd): ?>
                                <div class="idolx-stem" style="--c:<?php echo esc_attr($step['color']); ?>;"></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- bottom card row -->
                <div class="idolx-flex-row idolx-bottom-row">
                    <?php foreach ($steps as $i => $step): $isOdd = (($i + 1) % 2 === 1); ?>
                        <?php if (!$isOdd): ?>
                            <div class="idolx-flex-item idolx-card">
                                <div class="idolx-icon" style="background:<?php echo esc_attr($step['color']); ?>;"><?php echo $step['icon']; ?></div>
                                <p class="idolx-card-title"><?php echo esc_html($step['title']); ?></p>
                                <p class="idolx-card-desc"><?php echo esc_html($step['desc']); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="idolx-flex-item"></div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

            </div>

            <!-- MOBILE -->
            <div class="idolx-mobile">
                <?php foreach ($steps as $step): ?>
                    <div class="idolx-m-item">
                        <div class="idolx-node idolx-m-node" style="background:<?php echo esc_attr($step['color']); ?>;"><?php echo esc_html($step['num']); ?></div>
                        <div class="idolx-card">
                            <div class="idolx-icon" style="background:<?php echo esc_attr($step['color']); ?>;"><?php echo $step['icon']; ?></div>
                            <p class="idolx-card-title"><?php echo esc_html($step['title']); ?></p>
                            <p class="idolx-card-desc"><?php echo esc_html($step['desc']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </section>

        <style>
        #<?php echo esc_attr($unique_id); ?>.idolx-section {
            --ink: #10213B;
            --paper: #F3F5F9;
            --muted: #64748B;
            background: var(--paper);
            padding: 70px 40px;
            box-sizing: border-box;
            width: 100%;
        }

        #<?php echo esc_attr($unique_id); ?> * {
            box-sizing: border-box;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-head {
            margin: 0 auto 56px;
            text-align: center;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-title {
            font-weight: 800;
            font-size: clamp(26px, 3.6vw, 42px);
            color: var(--ink);
            margin: 0;
            line-height: 1.2;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-title .idolx-grad {
            color: #1E3A8A;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-card {
            position: relative;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(16, 33, 59, .07);
            padding: 20px 20px 18px;
            display: flex !important;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 26px rgba(16, 33, 59, .12);
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 8px rgba(0, 0, 0, .18);
            flex-shrink: 0;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-icon svg {
            width: 18px;
            height: 18px;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            margin: 0 0 6px;
            line-height: 1.35;
            text-align: center;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-card-desc {
            font-size: 12px;
            font-weight: 400;
            color: var(--muted);
            line-height: 1.6;
            margin: 0;
            text-align: center;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-node {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: #fff;
            box-shadow: 0 0 0 5px var(--paper);
            position: relative;
            z-index: 5;
            flex-shrink: 0;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-desktop {
            width: 100%;
            max-width: 1560px;
            margin: 0 auto;
            position: relative;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-flex-row {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            width: 94%;
            margin: 0 auto;
            gap: 0;
            position: relative;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-flex-row.idolx-top-row,
        #<?php echo esc_attr($unique_id); ?> .idolx-flex-row.idolx-bottom-row {
            width: 94%;
            margin-left: auto;
            margin-right: auto;
            float: none;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-flex-item {
            min-width: 0;
            visibility: hidden;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-flex-item.idolx-card {
            visibility: visible;
            width: 100%;
            min-width: 0;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-stem-row {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            width: 94%;
            height: 26px;
            margin: 0 auto;
            gap: 0;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-stem-cell {
            min-width: 0;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-stem {
            width: 0;
            height: 26px;
            border-left: 2px dashed var(--c);
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-track-row {
            position: relative;
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            width: 94%;
            height: 40px;
            margin: 0 auto;
            gap: 0;
            align-items: center;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-track-line {
            position: absolute;
            left: 6.25%;
            right: 6.25%;
            top: 50%;
            transform: translateY(-50%);
            height: 3px;
            background: linear-gradient(90deg, <?php echo esc_attr($gradient_css); ?>);
            border-radius: 3px;
            z-index: 1;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-node-cell {
            min-width: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            z-index: 3;
        }

        #<?php echo esc_attr($unique_id); ?> .idolx-mobile {
            display: none;
        }

        @media (max-width: 900px) {
            #<?php echo esc_attr($unique_id); ?>.idolx-section {
                padding: 40px 20px;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-desktop {
                display: none;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-mobile {
                display: block;
                width: 100%;
                max-width: 440px;
                margin: 0 auto;
                position: relative;
                padding-left: 56px;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-mobile::before {
                content: "";
                position: absolute;
                left: 19px;
                top: 6px;
                bottom: 6px;
                width: 3px;
                border-radius: 3px;
                background: linear-gradient(180deg, <?php echo esc_attr($gradient_css); ?>);
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-m-item {
                position: relative;
                margin-bottom: 10px;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-m-item:last-child {
                margin-bottom: 0;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-m-node {
                position: absolute;
                left: -56px;
                top: 23%;
                transform: translateY(-50%);
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-head {
                margin-bottom: 44px;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-card {
                align-items: flex-start;
                text-align: left;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-icon {
                margin-left: 0;
                margin-right: 0;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-card-title {
                text-align: left;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-card-desc {
                text-align: left;
            }
        }

        @media only screen and (min-width: 769px) {
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-row,
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-row.idolx-top-row,
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-row.idolx-bottom-row,
            #<?php echo esc_attr($unique_id); ?> .idolx-stem-row,
            #<?php echo esc_attr($unique_id); ?> .idolx-track-row {
                width: 94%;
                margin-left: auto;
                margin-right: auto;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-row {
                display: grid;
                grid-template-columns: repeat(8, 1fr);
                gap: 0;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-item {
                visibility: hidden;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-item.idolx-card {
                display: flex !important;
                visibility: visible;
                width: 165%;
                max-width: none;
                justify-self: center;
            }
            #<?php echo esc_attr($unique_id); ?> .idolx-flex-row.idolx-bottom-row {
                float: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            #<?php echo esc_attr($unique_id); ?> .idolx-card {
                transition: none;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Register Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('university_application_process', 'sode_application_process_render');
    add_shortcode('idol_application_process', 'sode_application_process_render');
    add_shortcode('uni_application_process', 'sode_application_process_render');
    add_shortcode('admission_process', 'sode_application_process_render');
    add_shortcode('application_process', 'sode_application_process_render');
    add_shortcode('sode_application_process', 'sode_application_process_render');
}
