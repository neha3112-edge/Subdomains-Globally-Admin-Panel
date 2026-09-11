<?php
/**
 * ====================================================================
 * Universal Legal Pages Component
 * File: legal-pages-universal.php
 *
 * Renders Disclaimer, Privacy Policy, Terms & Conditions popup modals
 * dynamically from the central admin panel DB.
 *
 * Data Flow:
 *   1. Direct DB (when on admin server)
 *   2. Remote API get_legal_pages.php (when on WordPress subdomain)
 *   3. Hardcoded fallback (always works, never empty)
 *
 * Shortcodes:
 *   [legal_popups]
 *   [disclaimer_popup]
 *   [privacy_popup]
 *   [terms_popup]
 * ====================================================================
 */

if (defined('SODE_LEGAL_PAGES_UNIVERSAL_LOADED')) {
    return;
}
define('SODE_LEGAL_PAGES_UNIVERSAL_LOADED', true);

// Safe polyfills
if (!function_exists('esc_attr')) {
    function esc_attr($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Fetch legal pages data: DB -> API -> Hardcoded fallback
 */
if (!function_exists('sode_get_legal_pages')) {
    function sode_get_legal_pages() {
        static $cache = null;
        if ($cache !== null) return $cache;

        $pages = [];

        // 1. Direct DB
        if (function_exists('get_db_connection')) {
            try {
                $db = get_db_connection();
                if ($db) {
                    $rows = $db->query("SELECT page_type, heading, content_html FROM legal_pages")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $pages[$r['page_type']] = ['heading' => $r['heading'], 'content' => $r['content_html']];
                    }
                }
            } catch (Exception $e) {}
        }

        // 2. Remote API
        if (empty($pages)) {
            $api_base = defined('SODE_CENTRAL_ADMIN_URL') ? rtrim(SODE_CENTRAL_ADMIN_URL, '/') : 'https://admin.distanceeducationschool.com';
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $raw = @file_get_contents($api_base . '/api/get_legal_pages.php', false, $ctx);
            if ($raw) {
                $json = json_decode($raw, true);
                if (!empty($json['pages'])) {
                    foreach ($json['pages'] as $type => $d) {
                        $pages[$type] = ['heading' => $d['heading'], 'content' => $d['content_html']];
                    }
                }
            }
        }

        // 3. Hardcoded fallback
        if (empty($pages)) {
            $pages = sode_legal_pages_fallback();
        }

        // Ensure all 3 types exist
        $defaults = sode_legal_pages_fallback();
        foreach (['disclaimer', 'privacy_policy', 'terms_conditions'] as $key) {
            if (empty($pages[$key])) {
                $pages[$key] = $defaults[$key];
            }
        }

        $cache = $pages;
        return $cache;
    }
}

if (!function_exists('sode_legal_pages_fallback')) {
    function sode_legal_pages_fallback() {
        return [
            'disclaimer' => [
                'heading' => 'Disclaimer',
                'content' => '<p>The information provided on DistanceEducationSchool.com, operated by <strong>SODE&trade; Counselling Services LLP</strong>, registered with the Ministry of Corporate Affairs, is intended solely for educational information, guidance, and counselling purposes. Working as an independent education guidance platform, not a university, regulatory body, degree-awarding institution, or admission authority. University and programme details, including approvals, eligibility, fees, and admissions, are subject to change. It is advisable to verify all such information directly with the respective university\'s official website.</p>
                <h3>Essential Points &amp; Guidance Policy</h3>
                <ul>
                    <li><strong>Independent Entity:</strong> DistanceEducationSchool.com, operated by SODE&trade; Counselling Services LLP, is an independent education information and counselling platform.</li>
                    <li><strong>Official University Verification:</strong> Users are advised to verify updated admissions, fees, eligibility, program structure, approvals, and other official information through the respective university\'s official website.</li>
                    <li><strong>Trademarks &amp; Copyrights:</strong> All University names, logos, trademarks, and other brand assets displayed on our platform are used solely for identification, informational, educational, and guidance purposes and remain the intellectual property of their respective university owners.</li>
                    <li><strong>Free Counselling:</strong> We offer free educational information and counselling to help students understand and compare suitable academic opportunities. We do not charge students any fees for counselling or guidance regarding university applications.</li>
                    <li><strong>No Degree Authorization:</strong> We do not issue degrees, certificates, marksheets, or academic credentials, nor do we have the authority to grant admissions on behalf of any university.</li>
                    <li><strong>Information Integrity:</strong> We strive to provide accurate, relevant, and up-to-date educational information, career guidance, and student support.</li>
                    <li><strong>Transparency:</strong> Our objective is to provide transparent educational guidance and student support and help learners make informed decisions regarding online and distance education opportunities.</li>
                </ul>'
            ],
            'privacy_policy' => [
                'heading' => 'Privacy Policy',
                'content' => '<p>All information on this platform is provided by <strong>DistanceEducationSchool.com</strong>, under the legal name of <strong>SODE&trade; Counselling Services LLP</strong>. We are an educational counselling platform that helps students find trusted distance and online courses from UGC-DEB-approved universities.</p>
                <h3>1. No Personal Data Collected by Default</h3>
                <p>You can freely browse our website without sharing any personal information. We do not collect your name, phone number, or email address unless you choose to fill out a form or contact us directly.</p>
                <h3>2. How We Use It</h3>
                <p>Your information is used to guide you in choosing the right university or course, provide counselling support, and share admission-related updates. We may send you important updates via WhatsApp and email. You can opt out anytime.</p>
                <h3>3. Scope</h3>
                <p>This privacy policy applies to visitors who access this specific platform operated under DistanceEducationSchool.com by SODE&trade; Counselling Services LLP.</p>
                <h3>4. Data Sharing</h3>
                <p>We share your details only with trusted university partners, and only for the purpose of counselling or admission. We do not sell or share data with third-party advertisers.</p>
                <h3>5. Cookies and Analytics</h3>
                <p>Our website uses cookies to improve the user experience. These help us understand how visitors use our site. These cookies do not identify you personally.</p>'
            ],
            'terms_conditions' => [
                'heading' => 'Terms &amp; Conditions',
                'content' => '<p>This page outlines the terms and conditions that apply when you access or use services provided on this platform, operated by <strong>SODE&trade; Counselling Services LLP</strong> under <strong>DistanceEducationSchool.com</strong>.</p>
                <h3>1. Our Role</h3>
                <p>We provide information and counselling services only. We are not a university and do not collect any university fees directly. All academic or admission-related payments must be made to the respective university.</p>
                <h3>2. Unauthorised Use or Fraud</h3>
                <p>If you suspect any unauthorised transaction linked to a service on our platform, report it immediately. We will coordinate with the respective payment partner for further action.</p>
                <h3>3. Updates to These Terms</h3>
                <p>These terms may be updated as services evolve. Continued use of this platform implies your agreement to the latest version of these terms.</p>
                <h3>4. Contact Us</h3>
                <p>For support, email us at: <strong>support@distanceeducationschool.com</strong></p>'
            ]
        ];
    }
}

/**
 * Render the 3 popup modals + CSS + JS
 */
if (!function_exists('sode_legal_popups_render')) {
    function sode_legal_popups_render($atts = []) {
        $pages = sode_get_legal_pages();

        $disclaimer = $pages['disclaimer'];
        $privacy    = $pages['privacy_policy'];
        $terms      = $pages['terms_conditions'];

        ob_start();
        ?>
<style>
.main-popup-overlay{display:none;position:fixed !important;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.7);z-index:2147483647 !important;animation:sode-lpFadeIn .3s ease}
.main-popup-overlay.active{display:flex !important;justify-content:center;align-items:center;padding:20px}
.main-popup-container{background:#fff;width:90%;max-width:900px;max-height:85vh;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.3);overflow:hidden;animation:sode-lpSlideUp .3s ease;position:relative;z-index:2147483647 !important;padding:20px}
.main-popup-header{color:#000;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #000;padding-bottom:10px;position:relative}
.main-popup-header h2{font-size:22px;font-weight:600;margin:0;color:#000}
.des-popup-close-btn{color:#000;font-size:28px;border-radius:50%;cursor:pointer;background:none;border:none;position:absolute;right:0;top:0;padding:0;line-height:1;width:30px;height:30px}
.des-popup-close-btn:hover{background:rgba(0,0,0,.1)}
.main-popup-content{padding:20px 10px;overflow-y:auto;max-height:calc(85vh - 80px)}
.main-popup-content h3{color:#1f2937;font-size:18px;margin:20px 0 12px;font-weight:600}
.main-popup-content h3:first-child{margin-top:0}
.main-popup-content p{color:#4b5563;line-height:1.7;margin-bottom:16px;font-size:13px}
.main-popup-content ul{margin:12px 0 16px 20px;color:#4b5563}
.main-popup-content li{margin-bottom:10px;line-height:1.6;font-size:13px}
@keyframes sode-lpFadeIn{from{opacity:0}to{opacity:1}}
@keyframes sode-lpSlideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
.main-popup-content::-webkit-scrollbar{width:8px}
.main-popup-content::-webkit-scrollbar-track{background:#f1f1f1;border-radius:4px}
.main-popup-content::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}
.main-popup-content::-webkit-scrollbar-thumb:hover{background:#94a3b8}
@media(max-width:768px){.main-popup-container{width:95%;max-height:90vh}.main-popup-header h2{font-size:18px}.main-popup-content{padding:20px}}
</style>

<!-- Disclaimer Popup -->
<div id="mainDisclaimerPopup" class="main-popup-overlay">
    <div class="main-popup-container">
        <div class="main-popup-header">
            <h2><?php echo $disclaimer['heading']; ?></h2>
            <button class="des-popup-close-btn" data-popup-close="mainDisclaimerPopup" type="button">&times;</button>
        </div>
        <div class="main-popup-content"><?php echo $disclaimer['content']; ?></div>
    </div>
</div>

<!-- Privacy Policy Popup -->
<div id="mainPrivacyPopup" class="main-popup-overlay">
    <div class="main-popup-container">
        <div class="main-popup-header">
            <h2><?php echo $privacy['heading']; ?></h2>
            <button class="des-popup-close-btn" data-popup-close="mainPrivacyPopup" type="button">&times;</button>
        </div>
        <div class="main-popup-content"><?php echo $privacy['content']; ?></div>
    </div>
</div>

<!-- Terms & Conditions Popup -->
<div id="mainTermsPopup" class="main-popup-overlay">
    <div class="main-popup-container">
        <div class="main-popup-header">
            <h2><?php echo $terms['heading']; ?></h2>
            <button class="des-popup-close-btn" data-popup-close="mainTermsPopup" type="button">&times;</button>
        </div>
        <div class="main-popup-content"><?php echo $terms['content']; ?></div>
    </div>
</div>

<script>
(function(){
    var hashPopupMap = {
        'disclaimer-popup': 'mainDisclaimerPopup',
        'privacy-popup':    'mainPrivacyPopup',
        'terms-popup':      'mainTermsPopup'
    };
    function openMainPopup(id)  { var el=document.getElementById(id); if(el){el.classList.add('active');document.body.style.overflow='hidden';} }
    function closeMainPopup(id) { var el=document.getElementById(id); if(el){el.classList.remove('active'); if(!document.querySelectorAll('.main-popup-overlay.active').length) document.body.style.overflow='';} }

    // Expose globally
    window.openMainPopup  = openMainPopup;
    window.closeMainPopup = closeMainPopup;

    document.addEventListener('click', function(e) {
        if (e.target.closest('.disclaimer-main-popup')) { e.preventDefault(); e.stopPropagation(); openMainPopup('mainDisclaimerPopup'); return; }
        if (e.target.closest('.privacy-main-popup'))    { e.preventDefault(); e.stopPropagation(); openMainPopup('mainPrivacyPopup');    return; }
        if (e.target.closest('.term-main-popup'))       { e.preventDefault(); e.stopPropagation(); openMainPopup('mainTermsPopup');      return; }

        var hashLink = e.target.closest('a[href*="#disclaimer-popup"],a[href*="#privacy-popup"],a[href*="#terms-popup"]');
        if (hashLink) {
            e.preventDefault(); e.stopPropagation();
            var hash = hashLink.getAttribute('href').split('#')[1];
            if (hashPopupMap[hash]) openMainPopup(hashPopupMap[hash]);
            return;
        }
        var closeBtn = e.target.closest('.des-popup-close-btn');
        if (closeBtn) { e.preventDefault(); e.stopPropagation(); closeMainPopup(closeBtn.getAttribute('data-popup-close')); return; }
    }, true);

    document.querySelectorAll('.main-popup-overlay').forEach(function(o) {
        o.addEventListener('click', function(e) { if(e.target===this){e.preventDefault();closeMainPopup(this.id);} }, true);
    });
    document.querySelectorAll('.main-popup-container').forEach(function(c) {
        c.addEventListener('click', function(e){e.stopPropagation();}, true);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key==='Escape') {
            var active=document.querySelectorAll('.main-popup-overlay.active');
            if(active.length){e.preventDefault();closeMainPopup(active[active.length-1].id);}
        }
    }, true);
}());
</script>
        <?php
        return ob_get_clean();
    }
}

// Register shortcodes
if (function_exists('add_shortcode')) {
    add_shortcode('legal_popups',     'sode_legal_popups_render');
    add_shortcode('disclaimer_popup', 'sode_legal_popups_render');
    add_shortcode('privacy_popup',    'sode_legal_popups_render');
    add_shortcode('terms_popup',      'sode_legal_popups_render');
}
