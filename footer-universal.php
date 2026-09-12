<?php
/**
 * ====================================================================
 * Universal Footer Component
 * File: footer-universal.php
 *
 * Renders full footer: CTA Bar, AI Tools Section (with slider),
 * About SODE, Legal Notice, Footer Links, Copyright.
 *
 * Data Flow: Direct DB → Remote API → Hardcoded Fallback
 *
 * Shortcodes:
 *   [universal_footer]
 *   [sode_footer]
 * ====================================================================
 */

if (defined('SODE_FOOTER_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_FOOTER_UNIVERSAL_LOADED', true);

if (!function_exists('sode_get_footer_config')) {
    function sode_get_footer_config() {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cfg = [];

        // 1. Direct DB
        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    $row = $db->query("SELECT * FROM footer_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $cfg = $row;
                        if (!empty($cfg['ai_tools_cards_json'])) {
                            $cfg['ai_tools'] = json_decode($cfg['ai_tools_cards_json'], true) ?: [];
                        }
                        if (!empty($cfg['footer_links_json'])) {
                            $cfg['footer_links'] = json_decode($cfg['footer_links_json'], true) ?: [];
                        }
                    }
                }
            } catch (Exception $e) {}
        }

        // 2. Remote API
        if (empty($cfg)) {
            $api_base = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $raw = @file_get_contents($api_base . '/api/get_footer_config.php', false, $ctx);
            if ($raw) {
                $json = json_decode($raw, true);
                if (!empty($json['success'])) {
                    $cfg = [
                        'cta_heading'          => $json['cta_heading']          ?? '',
                        'cta_subtext'          => $json['cta_subtext']          ?? '',
                        'cta_btn_text'         => $json['cta_btn_text']         ?? '',
                        'cta_btn_link'         => $json['cta_btn_link']         ?? '#',
                        'cta_btn_phone'        => $json['cta_btn_phone']        ?? '',
                        'ai_tools_heading'     => $json['ai_tools_heading']     ?? '',
                        'ai_tools_subtext'     => $json['ai_tools_subtext']     ?? '',
                        'ai_tools'             => $json['ai_tools']             ?? [],
                        'about_logo_url'       => $json['about_logo_url']       ?? '',
                        'about_title'          => $json['about_title']          ?? '',
                        'about_subtitle'       => $json['about_subtitle']       ?? '',
                        'about_sode_text'      => $json['about_sode']           ?? '',
                        'legal_notice_heading' => $json['legal_notice_heading'] ?? '',
                        'legal_notice_text'    => $json['legal_notice']         ?? '',
                        'footer_links'         => $json['footer_links']         ?? [],
                        'copyright_text'       => $json['copyright']            ?? '',
                    ];
                }
            }
        }

        // 3. Hardcoded fallback
        if (empty($cfg)) {
            $cfg = sode_footer_fallback_config();
        }

        // Ensure all keys exist using fallback defaults
        $fallback = sode_footer_fallback_config();
        foreach ($fallback as $k => $v) {
            if (!isset($cfg[$k]) || ($cfg[$k] === '' && $k !== 'cta_btn_phone' && $k !== 'about_logo_url')) {
                $cfg[$k] = $v;
            }
        }
        if (!isset($cfg['ai_tools'])) {
            $cfg['ai_tools'] = json_decode($cfg['ai_tools_cards_json'] ?? '[]', true) ?: $fallback['ai_tools'];
        }
        if (!isset($cfg['footer_links'])) {
            $cfg['footer_links'] = json_decode($cfg['footer_links_json'] ?? '[]', true) ?: $fallback['footer_links'];
        }

        $cache = $cfg;
        return $cache;
    }
}

// Helper: get official_url for the current university (from DB via slug constant)
if (!function_exists('sode_footer_get_official_url')) {
    function sode_footer_get_official_url() {
        static $url = null;
        if ($url !== null) return $url;
        $url = '';
        try {
            if (function_exists('get_db_connection')) {
                $db = get_db_connection();
                if ($db) {
                    $slug = null;
                    // 1. Use SODE_UNIVERSITY_SLUG constant (set by WordPress plugin)
                    if (defined('SODE_UNIVERSITY_SLUG') && SODE_UNIVERSITY_SLUG) {
                        $slug = SODE_UNIVERSITY_SLUG;
                    }
                    // 2. Fallback: global_settings table
                    if (!$slug) {
                        $gk = $db->query("SELECT setting_value FROM global_settings WHERE setting_key = 'university_slug' LIMIT 1")->fetchColumn();
                        if ($gk) $slug = $gk;
                    }
                    if ($slug) {
                        $stmt = $db->prepare("SELECT official_url FROM universities WHERE slug = ? LIMIT 1");
                        $stmt->execute([$slug]);
                        $uni = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($uni) $url = $uni['official_url'] ?? '';
                    }
                }
            }
        } catch (Exception $e) {}
        return $url;
    }
}


if (!function_exists('sode_footer_fallback_config')) {
    function sode_footer_fallback_config() {
        return [
            'cta_heading'          => 'Having Doubts ? Talk to Experts',
            'cta_subtext'          => 'Get 100% Free Counseling on Online Degree Courses & Distance Education Programs',
            'cta_btn_text'         => 'Book Free 1:1 Counseling',
            'cta_btn_link'         => '#',
            'cta_btn_phone'        => '',
            'ai_tools_heading'     => 'Explore AI Powered Tools',
            'ai_tools_subtext'     => 'Make smarter education decisions with AI-powered tools',
            'ai_tools'             => [
                ['icon_svg' => '<svg viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="32" fill="#E8F4FD"/><path d="M32 18C24.27 18 18 24.27 18 32s6.27 14 14 14 14-6.27 14-14S39.73 18 32 18zm0 4c2.76 0 5 2.24 5 5s-2.24 5-5 5-5-2.24-5-5 2.24-5 5-5zm0 20c-3.71 0-6.99-1.9-8.94-4.78C23.16 35.19 27.45 34 32 34s8.84 1.19 10.94 3.22C40.99 40.1 37.71 42 34 42h-2z" fill="#1565C0"/></svg>',
                 'title' => 'Suggest University', 'desc' => 'Find universities that match your goals, preferences, and career plans.', 'btn_text' => 'Suggest Me A University →', 'btn_link' => '#suggest-university'],
                ['icon_svg' => '<svg viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="32" fill="#F3E8FD"/><path d="M32 20a12 12 0 1 0 0 24 12 12 0 0 0 0-24zm-2 18l-5-5 2-2 3 3 7-7 2 2-9 9z" fill="#7B1FA2"/><path d="M24 44h16v2H24z" fill="#7B1FA2"/></svg>',
                 'title' => 'Eligibility Checker', 'desc' => 'Instantly check which courses and universities you\'re eligible for.', 'btn_text' => 'Check Eligibility →', 'btn_link' => '#eligibility-checker'],
                ['icon_svg' => '<svg viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="32" fill="#E8F5E9"/><path d="M20 32a12 12 0 1 1 24 0A12 12 0 0 1 20 32zm2 0a10 10 0 1 0 20 0 10 10 0 0 0-20 0z" fill="#2E7D32"/><path d="M29 27l8 5-8 5V27z" fill="#2E7D32"/></svg>',
                 'title' => 'Compare University', 'desc' => 'Compare universities based on fees, accreditation, rankings, placements, and more.', 'btn_text' => 'Compare University →', 'btn_link' => '#compare-university'],
                ['icon_svg' => '<svg viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="32" fill="#FCE4EC"/><path d="M20 20h24v4H20zm0 8h24v4H20zm0 8h16v4H20z" fill="#C62828"/><path d="M44 38l-8 8-4-4 2-2 2 2 6-6 2 2z" fill="#C62828"/></svg>',
                 'title' => 'Suggest Course', 'desc' => 'Find the right course based on your interests, qualifications, and career goals.', 'btn_text' => 'Suggest Course →', 'btn_link' => '#suggest-course'],
            ],
            'about_logo_url'       => '',
            'about_title'          => 'About SODE™',
            'about_subtitle'       => '(School of Online and Distance Education)',
            'about_sode_text'      => 'SODE™ is India\'s top educational platform, transforming the way learners engage with higher education. We make higher education easier without compromising on the quality. We help students and working professionals find the right online and distance degree programs. We simplify every step with expert guidance and personalised support.',
            'legal_notice_heading' => 'Legal Notice',
            'legal_notice_text'    => 'This information is provided by DistanceEducationSchool.com, operating under the registered legal entity SODE™ Counselling Services LLP (registered with the Ministry of Corporate Affairs, Government of India), with the primary objective of providing information, guidance, and counselling for UGC-DEB-approved universities and programs. We do not act as a university or an official admission authority.',
            'footer_links'         => [
                ['label' => 'About Us',          'url' => '/about-us/',        'class' => ''],
                ['label' => 'Contact Us',        'url' => '/contact-us/',      'class' => ''],
                ['label' => 'Disclaimer',        'url' => '#disclaimer-popup', 'class' => 'disclaimer-main-popup'],
                ['label' => 'Privacy Policy',    'url' => '#privacy-popup',    'class' => 'privacy-main-popup'],
                ['label' => 'Terms & Conditions','url' => '#terms-popup',      'class' => 'term-main-popup'],
            ],
            'copyright_text'       => '© ' . date('Y') . ' SODE™ Counselling Services LLP',
        ];
    }
}

if (!function_exists('sode_footer_render')) {
    function sode_footer_render($atts = []) {
        $cfg        = sode_get_footer_config();
        $tools      = $cfg['ai_tools']     ?? [];
        $links      = $cfg['footer_links'] ?? [];

        // Sort by sort_order
        if (!empty($tools)) {
            usort($tools, fn($a, $b) => (int)($a['sort_order']??99) <=> (int)($b['sort_order']??99));
        }
        if (!empty($links)) {
            usort($links, fn($a, $b) => (int)($a['sort_order']??99) <=> (int)($b['sort_order']??99));
        }

        $tool_count         = count($tools);
        $uid                = 'sode_footer_' . substr(md5(uniqid()), 0, 6);
        $use_slider_desktop = $tool_count > 4;
        $use_slider_mobile  = $tool_count > 1;

        // Resolve {official_url} placeholder for legal notice
        $official_url      = sode_footer_get_official_url();
        $legal_text        = $cfg['legal_notice_text'] ?? '';
        if (!empty($official_url) && strpos($legal_text, '{official_url}') !== false) {
            $domain = preg_replace('#^https?://#', '', rtrim($official_url, '/'));
            $linked = '<a href="' . htmlspecialchars($official_url) . '" target="_blank" rel="nofollow" style="color:#F5C518;text-decoration:underline;">' . htmlspecialchars($domain) . '</a>';
            $legal_text = str_replace('{official_url}', $linked, $legal_text);
        } else {
            $legal_text = htmlspecialchars($legal_text);
        }

        ob_start();
        ?>
<div id="<?php echo $uid; ?>" class="sode-footer-wrap">

    <!-- ============================================================
         1. CTA BAR
    ============================================================ -->
    <div class="sf-cta-bar">
        <div class="sf-cta-inner">
            <div class="sf-cta-text">
                <h2 class="sf-cta-heading">
                    <?php
                    $heading = htmlspecialchars($cfg['cta_heading']);
                    // Bold "Talk to Experts" part if present
                    $heading = preg_replace('/(\?.*)/u', '<span class="sf-cta-bold">$1</span>', $heading);
                    echo $heading;
                    ?>
                </h2>
                <?php if (!empty($cfg['cta_subtext'])): ?>
                    <p class="sf-cta-sub"><?php echo htmlspecialchars($cfg['cta_subtext']); ?></p>
                <?php endif; ?>
            </div>
            <?php
            $btn_link = !empty($cfg['cta_btn_phone']) ? 'tel:' . preg_replace('/\s+/', '', $cfg['cta_btn_phone']) : ($cfg['cta_btn_link'] ?: '#');
            ?>
            <a href="<?php echo htmlspecialchars($btn_link); ?>" class="sf-cta-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.36 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.79a16 16 0 0 0 6.29 6.29l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <?php echo htmlspecialchars($cfg['cta_btn_text']); ?>
            </a>
        </div>
    </div>

    <!-- ============================================================
         2. AI TOOLS SECTION
    ============================================================ -->
    <?php if (!empty($tools)): ?>
    <div class="sf-ai-section">
        <div class="sf-ai-head">
            <h2 class="sf-ai-heading"><?php echo htmlspecialchars($cfg['ai_tools_heading']); ?></h2>
            <?php if (!empty($cfg['ai_tools_subtext'])): ?>
                <p class="sf-ai-sub"><?php echo htmlspecialchars($cfg['ai_tools_subtext']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Desktop: grid (≤4) or slider (>4) -->
        <div class="sf-ai-desktop">
            <?php if ($use_slider_desktop): ?>
            <div class="sf-slider-wrap" data-slider-id="<?php echo $uid; ?>_desk">
                <button class="sf-slider-arrow sf-arr-prev" data-target="<?php echo $uid; ?>_desk">&#8249;</button>
                <div class="sf-slider-viewport" id="<?php echo $uid; ?>_desk">
                    <div class="sf-slider-track sf-ai-track">
                        <?php foreach ($tools as $t): ?>
                        <div class="sf-ai-card sf-slide">
                            <div class="sf-ai-icon"><?php echo $t['icon_svg'] ?? ''; ?></div>
                            <p class="sf-ai-card-title"><?php echo htmlspecialchars($t['title'] ?? ''); ?></p>
                            <p class="sf-ai-card-desc"><?php echo htmlspecialchars($t['desc'] ?? ''); ?></p>
                            <?php if (!empty($t['btn_link'])): ?>
                            <a href="<?php echo htmlspecialchars($t['btn_link']); ?>" class="sf-ai-btn"><?php echo htmlspecialchars($t['btn_text'] ?? 'Learn More'); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="sf-slider-arrow sf-arr-next" data-target="<?php echo $uid; ?>_desk">&#8250;</button>
                <div class="sf-slider-dots" id="<?php echo $uid; ?>_desk_dots"></div>
            </div>
            <?php else: ?>
            <div class="sf-ai-grid">
                <?php foreach ($tools as $t): ?>
                <div class="sf-ai-card">
                    <div class="sf-ai-icon"><?php echo $t['icon_svg'] ?? ''; ?></div>
                    <p class="sf-ai-card-title"><?php echo htmlspecialchars($t['title'] ?? ''); ?></p>
                    <p class="sf-ai-card-desc"><?php echo htmlspecialchars($t['desc'] ?? ''); ?></p>
                    <?php if (!empty($t['btn_link'])): ?>
                    <a href="<?php echo htmlspecialchars($t['btn_link']); ?>" class="sf-ai-btn"><?php echo htmlspecialchars($t['btn_text'] ?? 'Learn More'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Mobile: single card (=1) or slider (>1) -->
        <div class="sf-ai-mobile">
            <?php if ($use_slider_mobile): ?>
            <div class="sf-slider-wrap" data-slider-id="<?php echo $uid; ?>_mob">
                <button class="sf-slider-arrow sf-arr-prev" data-target="<?php echo $uid; ?>_mob">&#8249;</button>
                <div class="sf-slider-viewport" id="<?php echo $uid; ?>_mob">
                    <div class="sf-slider-track sf-ai-track">
                        <?php foreach ($tools as $t): ?>
                        <div class="sf-ai-card sf-slide">
                            <div class="sf-ai-icon"><?php echo $t['icon_svg'] ?? ''; ?></div>
                            <p class="sf-ai-card-title"><?php echo htmlspecialchars($t['title'] ?? ''); ?></p>
                            <p class="sf-ai-card-desc"><?php echo htmlspecialchars($t['desc'] ?? ''); ?></p>
                            <?php if (!empty($t['btn_link'])): ?>
                            <a href="<?php echo htmlspecialchars($t['btn_link']); ?>" class="sf-ai-btn"><?php echo htmlspecialchars($t['btn_text'] ?? 'Learn More'); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="sf-slider-arrow sf-arr-next" data-target="<?php echo $uid; ?>_mob">&#8250;</button>
                <div class="sf-slider-dots" id="<?php echo $uid; ?>_mob_dots"></div>
            </div>
            <?php else: ?>
            <div class="sf-ai-grid">
                <?php foreach ($tools as $t): ?>
                <div class="sf-ai-card">
                    <div class="sf-ai-icon"><?php echo $t['icon_svg'] ?? ''; ?></div>
                    <p class="sf-ai-card-title"><?php echo htmlspecialchars($t['title'] ?? ''); ?></p>
                    <p class="sf-ai-card-desc"><?php echo htmlspecialchars($t['desc'] ?? ''); ?></p>
                    <?php if (!empty($t['btn_link'])): ?>
                    <a href="<?php echo htmlspecialchars($t['btn_link']); ?>" class="sf-ai-btn"><?php echo htmlspecialchars($t['btn_text'] ?? 'Learn More'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         3. ABOUT SODE
    ============================================================ -->
    <?php if (!empty($cfg['about_sode_text'])): ?>
    <div class="sf-about-section">
        <div class="sf-about-inner">
            <?php if (!empty($cfg['about_logo_url'])): ?>
            <div class="sf-about-logo-wrap">
                <img src="<?php echo htmlspecialchars($cfg['about_logo_url']); ?>" alt="SODE Logo" class="sf-about-logo">
            </div>
            <?php else: ?>
            <div class="sf-about-logo-wrap">
                <div class="sf-about-logo-placeholder">
                    <svg viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="80" height="80" rx="12" fill="#f0f4ff"/>
                        <path d="M20 55L40 25l20 30H20z" fill="#1E3A8A" opacity=".15"/>
                        <path d="M28 55L40 35l12 20H28z" fill="#1E3A8A" opacity=".4"/>
                        <circle cx="40" cy="22" r="5" fill="#1E3A8A"/>
                        <text x="40" y="72" text-anchor="middle" font-size="9" font-weight="700" fill="#1E3A8A" font-family="Arial">SODE</text>
                    </svg>
                </div>
            </div>
            <?php endif; ?>
            <div class="sf-about-content">
                <h3 class="sf-about-title"><?php echo htmlspecialchars($cfg['about_title']); ?></h3>
                <?php if (!empty($cfg['about_subtitle'])): ?>
                    <p class="sf-about-subtitle"><?php echo htmlspecialchars($cfg['about_subtitle']); ?></p>
                <?php endif; ?>
                <p class="sf-about-text"><?php echo nl2br(htmlspecialchars($cfg['about_sode_text'])); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         4. LEGAL NOTICE + FOOTER LINKS + COPYRIGHT
    ============================================================ -->
    <div class="sf-legal-section">
        <?php if (!empty($cfg['legal_notice_heading'])): ?>
            <p class="sf-legal-heading"><?php echo htmlspecialchars($cfg['legal_notice_heading']); ?></p>
        <?php endif; ?>
        <?php if (!empty($legal_text)): ?>
            <p class="sf-legal-text"><?php echo $legal_text; ?></p>
        <?php endif; ?>

        <?php if (!empty($links)): ?>
        <div class="sf-footer-links">
            <?php foreach ($links as $i => $lnk):
                $label  = htmlspecialchars($lnk['label'] ?? '');
                $href   = htmlspecialchars($lnk['url']   ?? '#');
                $cls    = htmlspecialchars($lnk['class'] ?? '');
                $target = !empty($lnk['new_tab']) ? ' target="_blank" rel="noopener"' : '';
            ?>
                <?php if ($i > 0): ?><span class="sf-link-sep">|</span><?php endif; ?>
                <a href="<?php echo $href; ?>" class="sf-footer-link <?php echo $cls; ?>"<?php echo $target; ?>><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="sf-copyright"><?php echo htmlspecialchars($cfg['copyright_text']); ?></p>
    </div>

</div>

<style>
#<?php echo $uid; ?> .sode-footer-wrap, #<?php echo $uid; ?> { width:100%; }

/* ===== CTA BAR ===== */
#<?php echo $uid; ?> .sf-cta-bar {
    background: #0F1B2E;
    padding: 20px 40px;
}
#<?php echo $uid; ?> .sf-cta-inner {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}
#<?php echo $uid; ?> .sf-cta-heading {
    font-size: clamp(18px, 2.2vw, 26px);
    font-weight: 700;
    color: #fff;
    margin: 0 0 4px;
    line-height: 1.3;
}
#<?php echo $uid; ?> .sf-cta-bold { color: #fff; font-weight: 700; }
#<?php echo $uid; ?> .sf-cta-text .sf-cta-heading > span { color: #fff; }
#<?php echo $uid; ?> .sf-cta-sub {
    font-size: 13px;
    color: rgba(255,255,255,.7);
    margin: 0;
}
#<?php echo $uid; ?> .sf-cta-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #F5C518;
    color: #0F1B2E;
    font-weight: 700;
    font-size: 14px;
    padding: 11px 22px;
    border-radius: 8px;
    text-decoration: none;
    white-space: nowrap;
    transition: background .2s, transform .2s;
    flex-shrink: 0;
}
#<?php echo $uid; ?> .sf-cta-btn:hover { background: #f0b800; transform: translateY(-1px); }

/* ===== AI TOOLS ===== */
#<?php echo $uid; ?> .sf-ai-section {
    background: #fff;
    padding: 56px 40px;
    box-sizing: border-box;
}
#<?php echo $uid; ?> .sf-ai-head {
    text-align: center;
    margin-bottom: 40px;
}
#<?php echo $uid; ?> .sf-ai-heading {
    font-size: clamp(22px, 2.8vw, 34px);
    font-weight: 800;
    color: #10213B;
    margin: 0 0 10px;
}
#<?php echo $uid; ?> .sf-ai-sub {
    font-size: 15px;
    color: #64748B;
    margin: 0;
}
#<?php echo $uid; ?> .sf-ai-mobile { display: none; }
#<?php echo $uid; ?> .sf-ai-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    max-width: 1200px;
    margin: 0 auto;
}
#<?php echo $uid; ?> .sf-ai-card {
    background: #fff;
    border: 1px solid #E8EDF5;
    border-radius: 14px;
    padding: 28px 20px 24px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    box-shadow: 0 2px 12px rgba(16,33,59,.06);
    transition: transform .2s, box-shadow .2s;
    box-sizing: border-box;
}
#<?php echo $uid; ?> .sf-ai-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 28px rgba(16,33,59,.12);
}
#<?php echo $uid; ?> .sf-ai-icon {
    width: 64px;
    height: 64px;
    margin-bottom: 16px;
    flex-shrink: 0;
}
#<?php echo $uid; ?> .sf-ai-icon svg { width: 64px; height: 64px; }
#<?php echo $uid; ?> .sf-ai-card-title {
    font-size: 16px;
    font-weight: 700;
    color: #10213B;
    margin: 0 0 10px;
}
#<?php echo $uid; ?> .sf-ai-card-desc {
    font-size: 13px;
    color: #64748B;
    line-height: 1.6;
    margin: 0 0 18px;
    flex: 1;
}
#<?php echo $uid; ?> .sf-ai-btn {
    display: inline-block;
    background: #10213B;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    padding: 9px 18px;
    border-radius: 8px;
    text-decoration: none;
    transition: background .2s;
    white-space: nowrap;
}
#<?php echo $uid; ?> .sf-ai-btn:hover { background: #1E3A8A; }

/* ===== SLIDER ===== */
#<?php echo $uid; ?> .sf-slider-wrap {
    position: relative;
    max-width: 1200px;
    margin: 0 auto;
}
#<?php echo $uid; ?> .sf-slider-viewport {
    overflow: hidden;
    width: 100%;
}
#<?php echo $uid; ?> .sf-ai-track {
    display: flex;
    gap: 20px;
    transition: transform .4s ease;
    will-change: transform;
    align-items: stretch;
}
#<?php echo $uid; ?> .sf-slide { flex: 0 0 calc(25% - 15px); min-width: 0; }
#<?php echo $uid; ?> .sf-slider-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 5;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #E8EDF5;
    color: #10213B;
    font-size: 22px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.1);
    transition: background .2s, border-color .2s;
}
#<?php echo $uid; ?> .sf-slider-arrow:hover { background: #10213B; color: #fff; border-color: #10213B; }
#<?php echo $uid; ?> .sf-arr-prev { left: -20px; }
#<?php echo $uid; ?> .sf-arr-next { right: -20px; }
#<?php echo $uid; ?> .sf-slider-dots {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 20px;
}
#<?php echo $uid; ?> .sf-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #CBD5E1;
    cursor: pointer;
    transition: background .2s, transform .2s;
    border: none;
    padding: 0;
}
#<?php echo $uid; ?> .sf-dot.active { background: #10213B; transform: scale(1.3); }

/* ===== ABOUT SODE ===== */
#<?php echo $uid; ?> .sf-about-section {
    background: #F3F5F9;
    padding: 40px;
}
#<?php echo $uid; ?> .sf-about-inner {
    max-width: 1000px;
    margin: 0 auto;
    display: flex;
    align-items: flex-start;
    gap: 30px;
}
#<?php echo $uid; ?> .sf-about-logo-wrap {
    flex-shrink: 0;
    width: 110px;
}
#<?php echo $uid; ?> .sf-about-logo { width: 110px; height: auto; border-radius: 12px; }
#<?php echo $uid; ?> .sf-about-logo-placeholder svg { width: 80px; height: 80px; }
#<?php echo $uid; ?> .sf-about-content { flex: 1; }
#<?php echo $uid; ?> .sf-about-title {
    font-size: clamp(20px, 2.2vw, 26px);
    font-weight: 800;
    color: #10213B;
    margin: 0 0 4px;
}
#<?php echo $uid; ?> .sf-about-subtitle {
    font-size: 16px;
    font-weight: 600;
    color: #10213B;
    margin: 0 0 12px;
}
#<?php echo $uid; ?> .sf-about-text {
    font-size: 14px;
    color: #4B6070;
    line-height: 1.75;
    margin: 0;
}

/* ===== LEGAL / FOOTER BOTTOM ===== */
#<?php echo $uid; ?> .sf-legal-section {
    background: #0F1B2E;
    padding: 30px 40px;
    text-align: center;
    box-sizing: border-box;
}
#<?php echo $uid; ?> .sf-legal-heading {
    font-size: 15px;
    font-weight: 700;
    color: #F5C518;
    margin: 0 0 10px;
    letter-spacing: .5px;
}
#<?php echo $uid; ?> .sf-legal-text {
    font-size: 12.5px;
    color: rgba(255,255,255,.7);
    line-height: 1.7;
    max-width: 860px;
    margin: 0 auto 18px;
}
#<?php echo $uid; ?> .sf-footer-links {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    align-items: center;
    gap: 4px;
    margin: 0 0 14px;
}
#<?php echo $uid; ?> .sf-footer-link {
    color: rgba(255,255,255,.8);
    text-decoration: underline;
    font-size: 13px;
    transition: color .2s;
    cursor: pointer;
}
#<?php echo $uid; ?> .sf-footer-link:hover { color: #F5C518; }
#<?php echo $uid; ?> .sf-link-sep { color: rgba(255,255,255,.35); font-size: 13px; }
#<?php echo $uid; ?> .sf-copyright {
    font-size: 13px;
    color: rgba(255,255,255,.55);
    margin: 0;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    #<?php echo $uid; ?> .sf-cta-bar { padding: 18px 20px; }
    #<?php echo $uid; ?> .sf-cta-inner { flex-direction: column; align-items: flex-start; gap: 14px; }
    #<?php echo $uid; ?> .sf-cta-btn { width: 100%; justify-content: center; }
    #<?php echo $uid; ?> .sf-ai-section { padding: 36px 16px; }
    #<?php echo $uid; ?> .sf-ai-desktop { display: none; }
    #<?php echo $uid; ?> .sf-ai-mobile { display: block; }
    #<?php echo $uid; ?> .sf-slide { flex: 0 0 calc(100% - 0px); }
    #<?php echo $uid; ?> .sf-arr-prev { left: -8px; }
    #<?php echo $uid; ?> .sf-arr-next { right: -8px; }
    #<?php echo $uid; ?> .sf-about-section { padding: 30px 20px; }
    #<?php echo $uid; ?> .sf-about-inner { flex-direction: column; align-items: center; text-align: center; gap: 16px; }
    #<?php echo $uid; ?> .sf-legal-section { padding: 24px 20px; }
    #<?php echo $uid; ?> .sf-about-text { text-align: left; }
}
</style>

<script>
(function(){
    var uid = '<?php echo $uid; ?>';
    var sliderIds = <?php echo json_encode(array_filter([
        $use_slider_desktop ? $uid . '_desk' : null,
        $use_slider_mobile  ? $uid . '_mob'  : null,
    ])); ?>;

    sliderIds.forEach(function(sid) {
        if (!sid) return;
        var viewport = document.getElementById(sid);
        if (!viewport) return;
        var track = viewport.querySelector('.sf-ai-track');
        if (!track) return;
        var slides = Array.from(track.querySelectorAll('.sf-slide'));
        var dotsWrap = document.getElementById(sid + '_dots');
        var prevBtn = document.querySelector('[data-target="' + sid + '"].sf-arr-prev');
        var nextBtn = document.querySelector('[data-target="' + sid + '"].sf-arr-next');

        var current = 0;
        var total = slides.length;
        var autoTimer = null;
        var isMob = sid.indexOf('_mob') !== -1;
        var visibleCount = isMob ? 1 : 4;

        function slidesToShow() {
            if (isMob) return 1;
            var vw = viewport.offsetWidth;
            if (vw < 600) return 1;
            if (vw < 900) return 2;
            if (vw < 1100) return 3;
            return 4;
        }

        function getOffset() {
            var vis = slidesToShow();
            var slide = slides[0];
            if (!slide) return 0;
            var gap = parseInt(getComputedStyle(track).gap) || 20;
            return current * (slide.offsetWidth + gap);
        }

        function goTo(idx) {
            var vis = slidesToShow();
            var max = Math.max(0, total - vis);
            current = ((idx % total) + total) % total;
            if (current > max) current = 0;
            track.style.transform = 'translateX(-' + getOffset() + 'px)';
            updateDots();
        }

        function updateDots() {
            if (!dotsWrap) return;
            Array.from(dotsWrap.children).forEach(function(d, i) {
                d.classList.toggle('active', i === current);
            });
        }

        // Build dots
        if (dotsWrap) {
            for (var d = 0; d < total; d++) {
                (function(di) {
                    var dot = document.createElement('button');
                    dot.className = 'sf-dot' + (di === 0 ? ' active' : '');
                    dot.addEventListener('click', function() { goTo(di); resetAuto(); });
                    dotsWrap.appendChild(dot);
                })(d);
            }
        }

        if (prevBtn) prevBtn.addEventListener('click', function() { goTo(current - 1); resetAuto(); });
        if (nextBtn) nextBtn.addEventListener('click', function() { goTo(current + 1); resetAuto(); });

        // Auto-scroll 2.5s
        function startAuto() {
            autoTimer = setInterval(function() { goTo(current + 1); }, 2500);
        }
        function resetAuto() { clearInterval(autoTimer); startAuto(); }

        // Pause on hover
        var wrap = viewport.closest('.sf-slider-wrap');
        if (wrap) {
            wrap.addEventListener('mouseenter', function() { clearInterval(autoTimer); });
            wrap.addEventListener('mouseleave', function() { startAuto(); });
        }

        // Touch swipe
        var touchStartX = 0;
        viewport.addEventListener('touchstart', function(e) { touchStartX = e.touches[0].clientX; clearInterval(autoTimer); }, {passive:true});
        viewport.addEventListener('touchend', function(e) {
            var diff = touchStartX - e.changedTouches[0].clientX;
            if (Math.abs(diff) > 40) goTo(diff > 0 ? current + 1 : current - 1);
            startAuto();
        });

        startAuto();
        goTo(0);
        window.addEventListener('resize', function() { goTo(current); });
    });
}());
</script>
        <?php
        return ob_get_clean();
    }
}

// Shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('universal_footer', 'sode_footer_render');
    add_shortcode('sode_footer',      'sode_footer_render');
    add_shortcode('footer_section',   'sode_footer_render');
}
