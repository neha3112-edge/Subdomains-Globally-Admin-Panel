<?php
require_once __DIR__ . '/config/config.php';
require_login();

$current_user = get_logged_in_user();
$is_super = is_superadmin();

$page_title = 'Dashboard';
$page_subtitle = 'Universal Subdomains Management Portal';
$active_page_key = 'dashboard';

$db = get_db_connection();

// 1. Fetch Stats Counters
$total_unis = (int)$db->query("SELECT COUNT(*) FROM universities")->fetchColumn();
$total_courses = (int)$db->query("SELECT COUNT(*) FROM courses")->fetchColumn();
$total_mappings = (int)$db->query("SELECT COUNT(*) FROM university_course_mappings")->fetchColumn();

// Global Keys count & recent
$total_global_keys = 0;
$recent_global_keys = [];
try {
    $total_global_keys = (int)$db->query("SELECT COUNT(*) FROM global_keys WHERE is_active = 1")->fetchColumn();
    $recent_global_keys = $db->query("
        SELECT id, key_code, key_value, description, is_active 
        FROM global_keys 
        WHERE is_active = 1 
        ORDER BY id DESC LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {}

// Fetch admin users ONLY if Super Admin
$total_users = $is_super ? (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn() : 0;

// 2. Fetch Recent Universities
$recent_unis = $db->query("
    SELECT id, full_name, short_name, rating, mode, created_at, logo_url 
    FROM universities 
    ORDER BY id DESC LIMIT 5
")->fetchAll();

// 3. Fetch Recent Courses
$recent_courses = $db->query("
    SELECT id, full_name, short_name, level, created_at 
    FROM courses 
    ORDER BY id DESC LIMIT 5
")->fetchAll();

// 4. Fetch Recent Mappings
$recent_mappings = $db->query("
    SELECT ucm.id, u.short_name AS uni_name, u.logo_url, c.short_name AS course_name, u.mode, ucm.per_semester_fee, ucm.total_program_fee, ucm.created_at
    FROM university_course_mappings ucm
    INNER JOIN universities u ON ucm.university_id = u.id
    INNER JOIN courses c ON ucm.course_id = c.id
    ORDER BY ucm.id DESC LIMIT 5
")->fetchAll();

// 5. Complete Catalog of Universal Shortcodes (21 Production Modules)
$universal_shortcodes = [
    // 1. Tables & Fees
    [
        'category' => 'tables',
        'badge' => 'Auto-Compare',
        'badge_cls' => 'badge-info',
        'name' => 'Subdomain Fees Table',
        'desc' => 'Auto-selects current subdomain row & dynamically opens compare drawer on 2nd selection.',
        'code' => '[subdomain_fees_table limit=10]',
    ],
    [
        'category' => 'tables',
        'badge' => 'Dynamic 1st Row',
        'badge_cls' => 'badge-warning',
        'name' => 'Course Top-10 Table',
        'desc' => 'Universal top-10 list; prepends current subdomain row if course is offered with auto-compare.',
        'code' => '[course_table course="mba"]',
    ],
    [
        'category' => 'tables',
        'badge' => 'UGC Recognized',
        'badge_cls' => 'badge-success',
        'name' => 'Approved Programmes Table',
        'desc' => 'Complete list of UGC-DEB recognized degree programs, duration, and study modes.',
        'code' => '[subdomain_programmes_table]',
    ],
    [
        'category' => 'tables',
        'badge' => 'Key Dates',
        'badge_cls' => 'badge-info',
        'name' => 'Important Dates Calendar',
        'desc' => 'Dynamic schedule of admission start/last date, examinations and assignment deadlines.',
        'code' => '[university_dates]',
    ],
    [
        'category' => 'tables',
        'badge' => 'Smart Alternatives',
        'badge_cls' => 'badge-info',
        'name' => 'Alternate Universities Grid',
        'desc' => 'Universal comparison cards grid automatically excluding the current subdomain.',
        'code' => '[alternate_universities]',
    ],

    // 2. Academics & Careers
    [
        'category' => 'academics',
        'badge' => 'Curriculum',
        'badge_cls' => 'badge-warning',
        'name' => 'Course Syllabus Breakdown',
        'desc' => 'Detailed semester-wise subject list, syllabus modules & core credit distribution.',
        'code' => '[course_syllabus course="mba"]',
    ],
    [
        'category' => 'academics',
        'badge' => 'Specializations',
        'badge_cls' => 'badge-primary',
        'name' => 'Course Specializations',
        'desc' => 'Comprehensive list of dual/single elective specializations available for the course.',
        'code' => '[course_specializations course="mba"]',
    ],
    [
        'category' => 'academics',
        'badge' => 'Salaries & Roles',
        'badge_cls' => 'badge-success',
        'name' => 'Job Roles & Placements',
        'desc' => 'Top recruitment sectors, designated job roles, and average starting packages (CTC).',
        'code' => '[job_roles_table course="mba"]',
    ],
    [
        'category' => 'academics',
        'badge' => 'Eligibility',
        'badge_cls' => 'badge-info',
        'name' => 'Eligibility Criteria Table',
        'desc' => 'Graduation percentage thresholds, category relaxations & academic pre-requisites.',
        'code' => '[university_eligibility_table]',
    ],

    // 3. Lead Generation & Forms
    [
        'category' => 'forms',
        'badge' => 'Multi-Field',
        'badge_cls' => 'badge-success',
        'name' => 'Universal Lead Form',
        'desc' => 'High-converting enquiry form with dynamic university dropdown & CRM webhook integration.',
        'code' => '[custom_lead_form]',
    ],
    [
        'category' => 'forms',
        'badge' => 'Free Counseling',
        'badge_cls' => 'badge-primary',
        'name' => 'Counseling Lead Box',
        'desc' => 'Compact consultation request box with instant counselor callback alert trigger.',
        'code' => '[counseling_lead_form]',
    ],
    [
        'category' => 'forms',
        'badge' => 'Brochure Gate',
        'badge_cls' => 'badge-warning',
        'name' => 'Brochure Download Form',
        'desc' => 'Lead capture gate allowing prospective students to download official university prospectus.',
        'code' => '[brochure_download_form]',
    ],
    [
        'category' => 'forms',
        'badge' => 'Scholarships',
        'badge_cls' => 'badge-info',
        'name' => 'Scholarship Coupon Form',
        'desc' => 'Coupon redemption module allowing students to check fee waiver & scholarship eligibility.',
        'code' => '[scholarship_coupon_form]',
    ],
    [
        'category' => 'forms',
        'badge' => 'Modal Trigger',
        'badge_cls' => 'badge-danger',
        'name' => 'Apply Now Action Button',
        'desc' => 'Universal animated CTA button trigger that launches the lead capture popup on click.',
        'code' => '[apply_now_button]',
    ],

    // 4. Dynamic Widgets, Tokens & Layout
    [
        'category' => 'widgets',
        'badge' => 'Global Token',
        'badge_cls' => 'badge-info',
        'name' => 'Dynamic Global Key',
        'desc' => 'Renders any active global key variable (e.g. $YEAR$, $session$) dynamically across all subdomains.',
        'code' => '[gkey code="$session$"]',
    ],
    [
        'category' => 'widgets',
        'badge' => '4-Step Flow',
        'badge_cls' => 'badge-success',
        'name' => 'Admission Process Flow',
        'desc' => 'Universal dynamic 4-step timeline with customized university brand color accents.',
        'code' => '[admission_process]',
    ],
    [
        'category' => 'widgets',
        'badge' => 'News Ticker',
        'badge_cls' => 'badge-danger',
        'name' => 'News & Marquee Ticker',
        'desc' => 'Real-time animated scrolling marquee displaying latest session circulars & exam alerts.',
        'code' => '[latest_news]',
    ],
    [
        'category' => 'widgets',
        'badge' => 'Circulars',
        'badge_cls' => 'badge-warning',
        'name' => 'Announcements List',
        'desc' => 'Dedicated responsive feed for official university circulars, notices & notifications.',
        'code' => '[announcements_list]',
    ],
    [
        'category' => 'widgets',
        'badge' => 'Header Hero',
        'badge_cls' => 'badge-info',
        'name' => 'Education Header Banner',
        'desc' => 'Hero banner with dynamic university logo, rating badge, NAAC grade & quick CTAs.',
        'code' => '[edu_banner]',
    ],
    [
        'category' => 'widgets',
        'badge' => 'Site Footer',
        'badge_cls' => 'badge-muted',
        'name' => 'Universal Site Footer',
        'desc' => 'Complete global footer with disclaimer popups, accreditation links & copyright notice.',
        'code' => '[universal_footer]',
    ],
    [
        'category' => 'widgets',
        'badge' => 'Dynamic Year',
        'badge_cls' => 'badge-primary',
        'name' => 'Academic Session / Year',
        'desc' => 'Auto-updating current academic session (e.g. 2026-27) for headings, SEO tags & banners.',
        'code' => '[site_year]',
    ],
];

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- 1. Modern Welcome Hero Banner -->
<div class="dash-hero">
    <div class="dash-hero-left">
        <div class="dash-hero-avatar">
            <?php echo htmlspecialchars(get_user_initials($current_user['name'] ?? 'AD')); ?>
        </div>
        <div>
            <div class="dash-hero-title">
                Welcome back, <?php echo htmlspecialchars($current_user['name'] ?? 'Administrator'); ?>!
                <?php if ($is_super): ?>
                    <span class="dash-hero-role role-super">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        Super Admin
                    </span>
                <?php else: ?>
                    <span class="dash-hero-role role-sub">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <?php echo htmlspecialchars($current_user['role_name'] ?? 'Sub Admin'); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="dash-hero-subtitle">
                Centralized universal subdomains, courses, fees comparison, global keys & shortcodes hub.
            </div>
        </div>
    </div>
    <div class="dash-hero-right">
        <div class="dash-hero-status">
            <span class="pulse-dot"></span>
            Universal Sync Active
        </div>
        <div style="display:flex; gap:8px;">
            <a href="<?php echo BASE_URL; ?>/modules/universities/create.php" class="btn-primary btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Add University
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px; background:var(--bg-input); border:1px solid var(--border-color); color:var(--text-main);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                Global Keys
            </a>
        </div>
    </div>
</div>

<!-- 2. Dynamic Metric Stats Cards -->
<div class="stats-grid">
    <!-- Card 1: Universities -->
    <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="stat-card accent-indigo">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Total Universities</div>
                    <div class="stat-number"><?php echo number_format($total_unis); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#6366f1; background:rgba(99,102,241,0.14);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3"/>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">Active institution profiles</div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Directory listings</span>
            <span class="stat-link-arrow">Manage &rarr;</span>
        </div>
    </a>

    <!-- Card 2: Courses -->
    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="stat-card accent-purple">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Total Courses</div>
                    <div class="stat-number"><?php echo number_format($total_courses); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#a855f7; background:rgba(168,85,247,0.14);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">UG, PG & Diploma programs</div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Curriculum catalog</span>
            <span class="stat-link-arrow">Browse &rarr;</span>
        </div>
    </a>

    <!-- Card 3: Mappings -->
    <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="stat-card accent-emerald">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Course Mappings</div>
                    <div class="stat-number"><?php echo number_format($total_mappings); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#10b981; background:rgba(16,185,129,0.14);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">Fees & compare linkages</div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Universal live links</span>
            <span class="stat-link-arrow">Audit &rarr;</span>
        </div>
    </a>

    <!-- Card 4: Global Keys -->
    <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="stat-card accent-cyan">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Global Keys</div>
                    <div class="stat-number"><?php echo number_format($total_global_keys); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#06b6d4; background:rgba(6,182,212,0.14);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">Dynamic tokens & variables</div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Global replacements</span>
            <span class="stat-link-arrow">Manage &rarr;</span>
        </div>
    </a>

    <!-- Card 5: Admin Users (ONLY VISIBLE TO SUPER ADMIN) -->
    <?php if ($is_super): ?>
    <a href="<?php echo BASE_URL; ?>/modules/users/index.php" class="stat-card accent-amber">
        <div>
            <div class="stat-card-top">
                <div>
                    <div class="stat-label">Admin Users</div>
                    <div class="stat-number"><?php echo number_format($total_users); ?></div>
                </div>
                <div class="stat-icon-wrap" style="color:#f59e0b; background:rgba(245,158,11,0.14);">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
            </div>
            <div class="stat-sub">Privileged team accounts</div>
        </div>
        <div class="stat-card-footer">
            <span style="color:var(--text-dim);">Super Admin Only</span>
            <span class="stat-link-arrow">Manage &rarr;</span>
        </div>
    </a>
    <?php endif; ?>
</div>

<!-- 3. Universal Shortcodes Reference Hub (Interactive Slider) -->
<div class="shortcode-hub-card">
    <div class="shortcode-hub-header">
        <div class="shortcode-hub-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--primary);">
                <polyline points="16 18 22 12 16 6"></polyline>
                <polyline points="8 6 2 12 8 18"></polyline>
            </svg>
            Universal Shortcodes Reference Hub
            <span class="badge badge-success" style="font-size:10.5px;"><?php echo count($universal_shortcodes); ?> Live Modules</span>
        </div>
        
        <div class="shortcode-slider-controls">
            <span class="slider-counter-badge" id="scSliderCounter">Showing 1 - 4 of <?php echo count($universal_shortcodes); ?></span>
            <button type="button" class="slider-nav-btn" onclick="slideShortcodes(-1)" title="Previous Shortcodes" aria-label="Previous">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>
            <button type="button" class="slider-nav-btn" onclick="slideShortcodes(1)" title="Next Shortcodes" aria-label="Next">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
        </div>
    </div>

    <!-- Category Filter Pills -->
    <div class="shortcode-cat-filters">
        <button type="button" class="sc-filter-btn active" onclick="filterShortcodes('all', this)">
            All Modules (<?php echo count($universal_shortcodes); ?>)
        </button>
        <button type="button" class="sc-filter-btn" onclick="filterShortcodes('tables', this)">
            📊 Tables & Comparison (5)
        </button>
        <button type="button" class="sc-filter-btn" onclick="filterShortcodes('academics', this)">
            🎓 Academics & Careers (4)
        </button>
        <button type="button" class="sc-filter-btn" onclick="filterShortcodes('forms', this)">
            📝 Forms & CTAs (5)
        </button>
        <button type="button" class="sc-filter-btn" onclick="filterShortcodes('widgets', this)">
            ⚡ Widgets & Global Tokens (7)
        </button>
    </div>

    <!-- Slider Track -->
    <div class="shortcode-slider-track" id="shortcodeSliderTrack">
        <?php foreach ($universal_shortcodes as $idx => $sc): ?>
            <div class="shortcode-card-slide" data-cat="<?php echo htmlspecialchars($sc['category']); ?>">
                <div>
                    <div class="shortcode-item-top">
                        <span class="shortcode-name"><?php echo htmlspecialchars($sc['name']); ?></span>
                        <span class="badge <?php echo htmlspecialchars($sc['badge_cls']); ?>"><?php echo htmlspecialchars($sc['badge']); ?></span>
                    </div>
                    <div class="shortcode-desc">
                        <?php echo htmlspecialchars($sc['desc']); ?>
                    </div>
                </div>
                <div class="shortcode-code-box">
                    <code><?php echo htmlspecialchars($sc['code']); ?></code>
                    <button type="button" class="shortcode-copy-btn" onclick="copyShortcode(<?php echo htmlspecialchars(json_encode($sc['code'])); ?>, this)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        <span>Copy</span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 4. Quick Actions Grid -->
<div class="section-heading-sm">Quick Management Actions</div>
<div class="quick-actions-grid">
    <a href="<?php echo BASE_URL; ?>/modules/universities/create.php" class="action-card">
        <div class="action-icon" style="color:#6366f1; background:rgba(99,102,241,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
        </div>
        <div>
            <div class="action-title">Add University</div>
            <div class="action-desc">New profile & accreditations</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="action-card">
        <div class="action-icon" style="color:#06b6d4; background:rgba(6,182,212,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11M20 10v11"/></svg>
        </div>
        <div>
            <div class="action-title">All Universities</div>
            <div class="action-desc">Manage profiles & rankings</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="action-card">
        <div class="action-icon" style="color:#a855f7; background:rgba(168,85,247,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        </div>
        <div>
            <div class="action-title">Course Catalog</div>
            <div class="action-desc">UG / PG program definitions</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/mappings/create.php" class="action-card">
        <div class="action-icon" style="color:#10b981; background:rgba(16,185,129,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
        </div>
        <div>
            <div class="action-title">Map New Course</div>
            <div class="action-desc">Link course with fee structure</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="action-card">
        <div class="action-icon" style="color:#3b82f6; background:rgba(59,130,246,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
        </div>
        <div>
            <div class="action-title">All Course Mappings</div>
            <div class="action-desc">Audit fees & duration</div>
        </div>
    </a>

    <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="action-card">
        <div class="action-icon" style="color:#06b6d4; background:rgba(6,182,212,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
        </div>
        <div>
            <div class="action-title">Global Keys</div>
            <div class="action-desc">Dynamic site tokens ($YEAR$, etc.)</div>
        </div>
    </a>

    <?php if ($is_super): ?>
    <a href="<?php echo BASE_URL; ?>/modules/users/index.php" class="action-card">
        <div class="action-icon" style="color:#f59e0b; background:rgba(245,158,11,0.12);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
        </div>
        <div>
            <div class="action-title">Users & RBAC</div>
            <div class="action-desc">Roles, access & permissions</div>
        </div>
    </a>
    <?php endif; ?>
</div>

<!-- 5. Recent Tables Section (Responsive Grid) -->
<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap:24px;">
    
    <!-- Recent Universities -->
    <div class="admin-card card-overflow-hidden">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="card-title">Recent Universities</span>
                <span class="badge badge-info"><?php echo count($recent_unis); ?></span>
            </div>
            <a href="<?php echo BASE_URL; ?>/modules/universities/index.php" class="badge badge-info" style="text-decoration:none; padding:4px 10px;">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>University</th>
                        <th>Rating</th>
                        <th>Mode</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_unis)): ?>
                        <tr><td colspan="4" style="text-align:center; color:var(--text-dim); padding:30px;">No universities added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_unis as $u): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if (!empty($u['logo_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($u['logo_url']); ?>" alt="Logo" class="uni-avatar" style="background:#fff; padding:2px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="uni-avatar" style="display:none;"><?php echo htmlspecialchars(substr($u['short_name'], 0, 2)); ?></div>
                                        <?php else: ?>
                                            <div class="uni-avatar"><?php echo htmlspecialchars(substr($u['short_name'], 0, 2)); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <strong style="font-size:13.5px; color:var(--text-main);"><?php echo htmlspecialchars($u['short_name']); ?></strong>
                                            <div style="font-size:11px; color:var(--text-dim); max-width:130px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?php echo htmlspecialchars($u['full_name']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span style="color:#f59e0b; font-weight:700; font-size:12.5px;">★ <?php echo htmlspecialchars($u['rating'] ?? '4.5'); ?></span>
                                </td>
                                <td>
                                    <?php 
                                    $mode_clean = strtolower(trim($u['mode'] ?? 'online'));
                                    $mode_cls = ($mode_clean === 'online') ? 'mode-online' : (($mode_clean === 'distance') ? 'mode-distance' : 'mode-regular');
                                    ?>
                                    <span class="mode-tag <?php echo $mode_cls; ?>">
                                        <?php echo htmlspecialchars(ucfirst($u['mode'] ?? 'Online')); ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <a href="<?php echo BASE_URL; ?>/modules/universities/edit.php?id=<?php echo $u['id']; ?>" class="action-btn" title="Edit University">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Courses -->
    <div class="admin-card card-overflow-hidden">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="card-title">Recent Courses</span>
                <span class="badge badge-info"><?php echo count($recent_courses); ?></span>
            </div>
            <a href="<?php echo BASE_URL; ?>/modules/courses/index.php" class="badge badge-info" style="text-decoration:none; padding:4px 10px;">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Level</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_courses)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--text-dim); padding:30px;">No courses added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_courses as $c): ?>
                            <tr>
                                <td>
                                    <div>
                                        <strong style="font-size:13.5px; color:var(--text-main);"><?php echo htmlspecialchars($c['full_name']); ?></strong>
                                        <?php if (!empty($c['short_name'])): ?>
                                            <div style="font-size:11px; color:var(--text-dim);"><?php echo htmlspecialchars($c['short_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($c['level'] ?? 'PG'); ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <a href="<?php echo BASE_URL; ?>/modules/courses/index.php?edit_id=<?php echo $c['id']; ?>" class="action-btn" title="Edit Course">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Mappings -->
    <div class="admin-card card-overflow-hidden">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="card-title">Recent Mappings</span>
                <span class="badge badge-info"><?php echo count($recent_mappings); ?></span>
            </div>
            <a href="<?php echo BASE_URL; ?>/modules/mappings/index.php" class="badge badge-info" style="text-decoration:none; padding:4px 10px;">View all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>University & Course</th>
                        <th>Sem Fee</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_mappings)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--text-dim); padding:30px;">No course mappings yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_mappings as $m): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:3px;">
                                        <strong style="color:var(--text-main); font-size:13px;"><?php echo htmlspecialchars($m['uni_name']); ?></strong>
                                        <div>
                                            <span class="badge badge-warning" style="font-size:10px;"><?php echo htmlspecialchars($m['course_name']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $fee_val = $m['per_semester_fee'] ?? '';
                                    if (is_numeric($fee_val)) {
                                        $fee_fmt = '₹' . number_format((float)$fee_val);
                                    } else {
                                        $fee_fmt = !empty($fee_val) ? htmlspecialchars($fee_val) : '—';
                                    }
                                    ?>
                                    <span style="font-weight:700; color:var(--success); font-size:12.5px;"><?php echo $fee_fmt; ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <a href="<?php echo BASE_URL; ?>/modules/mappings/edit.php?id=<?php echo $m['id']; ?>" class="action-btn" title="Edit Mapping">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active Global Keys -->
    <div class="admin-card card-overflow-hidden">
        <div class="card-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="card-title">Global Keys</span>
                <span class="badge badge-info"><?php echo count($recent_global_keys); ?></span>
            </div>
            <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php" class="badge badge-info" style="text-decoration:none; padding:4px 10px;">Manage all &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Key Token</th>
                        <th>Value</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_global_keys)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--text-dim); padding:30px;">No global keys found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_global_keys as $k): ?>
                            <tr>
                                <td>
                                    <span style="font-family:monospace; background:rgba(6,182,212,0.12); color:#06b6d4; padding:3px 7px; border-radius:4px; font-weight:700; font-size:11px; border:1px solid rgba(6,182,212,0.25);">
                                        <?php echo htmlspecialchars($k['key_code']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size:12px; font-weight:600; color:var(--text-main); max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($k['key_value']); ?>">
                                        <?php echo htmlspecialchars($k['key_value']); ?>
                                    </div>
                                </td>
                                <td style="text-align:right;">
                                    <a href="<?php echo BASE_URL; ?>/modules/global_keys/index.php?edit_id=<?php echo $k['id']; ?>" class="action-btn" title="Edit Key">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Interactive Slider & Copy Scripts -->
<script>
// 1. Copy Shortcode to Clipboard with Visual Feedback
function copyShortcode(text, btnElement) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            showCopiedFeedback(btnElement);
        }).catch(function() {
            fallbackCopy(text, btnElement);
        });
    } else {
        fallbackCopy(text, btnElement);
    }
}

function fallbackCopy(text, btnElement) {
    var textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    textArea.style.opacity = "0";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        showCopiedFeedback(btnElement);
    } catch (err) {
        console.error('Fallback copy failed', err);
    }
    document.body.removeChild(textArea);
}

function showCopiedFeedback(btnElement) {
    var originalHTML = btnElement.innerHTML;
    btnElement.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> <span>Copied!</span>';
    btnElement.style.background = 'var(--success)';
    btnElement.style.color = '#ffffff';

    setTimeout(function() {
        btnElement.innerHTML = originalHTML;
        btnElement.style.background = '';
        btnElement.style.color = '';
    }, 2000);
}

// 2. Shortcodes Slider Navigation & Filtering
function slideShortcodes(direction) {
    var track = document.getElementById('shortcodeSliderTrack');
    if (!track) return;
    
    // Find active visible card to calculate scroll width
    var firstCard = track.querySelector('.shortcode-card-slide:not([style*="display: none"])');
    var cardWidth = firstCard ? (firstCard.offsetWidth + 16) : 320;
    
    track.scrollBy({
        left: direction * cardWidth * 1.5,
        behavior: 'smooth'
    });
}

function updateSliderCounter() {
    var track = document.getElementById('shortcodeSliderTrack');
    var counter = document.getElementById('scSliderCounter');
    if (!track || !counter) return;

    var visibleCards = Array.from(track.querySelectorAll('.shortcode-card-slide')).filter(function(el) {
        return el.style.display !== 'none';
    });
    
    var total = visibleCards.length;
    if (total === 0) {
        counter.textContent = '0 items';
        return;
    }

    var trackRect = track.getBoundingClientRect();
    var firstVisibleIndex = 0;
    var visibleCount = 0;

    visibleCards.forEach(function(card, idx) {
        var cardRect = card.getBoundingClientRect();
        if (cardRect.right > trackRect.left + 25 && cardRect.left < trackRect.right - 25) {
            if (visibleCount === 0) firstVisibleIndex = idx + 1;
            visibleCount++;
        }
    });

    if (visibleCount === 0) {
        counter.textContent = total + ' items';
    } else {
        var lastVisible = Math.min(firstVisibleIndex + visibleCount - 1, total);
        counter.textContent = 'Showing ' + firstVisibleIndex + ' - ' + lastVisible + ' of ' + total;
    }
}

function filterShortcodes(category, btnElement) {
    // Update active tab button
    document.querySelectorAll('.sc-filter-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    if (btnElement) btnElement.classList.add('active');

    // Filter cards in track
    var track = document.getElementById('shortcodeSliderTrack');
    if (!track) return;
    
    var cards = track.querySelectorAll('.shortcode-card-slide');
    cards.forEach(function(card) {
        var cardCat = card.getAttribute('data-cat');
        if (category === 'all' || cardCat === category) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });

    // Reset scroll to left
    track.scrollTo({ left: 0, behavior: 'smooth' });
    setTimeout(updateSliderCounter, 300);
}

// Attach scroll listener to update counter dynamically
document.addEventListener('DOMContentLoaded', function() {
    var track = document.getElementById('shortcodeSliderTrack');
    if (track) {
        track.addEventListener('scroll', function() {
            clearTimeout(window._scScrollTimer);
            window._scScrollTimer = setTimeout(updateSliderCounter, 100);
        });
        updateSliderCounter();
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
