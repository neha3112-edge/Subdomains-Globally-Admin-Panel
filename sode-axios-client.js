/**
 * ====================================================================
 * SODE Central Universal Client Engine (Pure JavaScript & Fetch)
 * File: sode-axios-client.js
 * 
 * 100% Client-Side Engine — ZERO PHP plugins required on subdomains.
 * - Auto-detects University from Subdomain URL.
 * - Replaces all Global Keys ($KEY$, {KEY}, #LEAD_FORM#, etc.).
 * - Detects and Server-Side Renders (SSR) all WordPress Shortcodes:
 *   [edu_banner], [latest_news], [university_courses],
 *   [university_courses_list], [university_eligibility_table],
 *   [sample_degree], [course_fees], [syllabus], etc.
 * - Injects Counseling Modals, Popups, and WhatsApp Widgets.
 * - Zero delay: Runs instantly on page load without waiting for scroll.
 * - Caches in SessionStorage for 0ms lightning-fast page transitions.
 * ====================================================================
 */
(function () {
    'use strict';

    const BASE_URL = 'https://admin.distanceeducationschool.com';

    // 1. Detect University Slug from Subdomain
    function getUniSlug() {
        const host = window.location.hostname.toLowerCase();
        const parts = host.split('.');
        if (parts.length >= 3 && !['www', 'mail', 'admin', 'cpanel', 'webmail'].includes(parts[0])) {
            return parts[0];
        }
        return 'dsu';
    }

    const uniSlug = getUniSlug();
    const cacheKeyKeys = 'sode_keys_' + uniSlug;
    console.log('🚀 [SODE UNIVERSAL ENGINE] Active for university:', uniSlug);

    // Helper: Execute scripts inside dynamically inserted HTML
    function runScripts(container) {
        if (!container) return;
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    // Comprehensive list of all universal shortcode tags and aliases
    const knownShortcodes = [
        'edu_banner', 'banner',
        'latest_news', 'news', 'news_marquee', 'universal_news',
        'announcements_list', 'recent_announcements', 'announcements', 'university_announcements', 'uni_announcements', 'inner_page_news',
        'university_courses', 'courses', 'sode_courses_table', 'uni_courses', 'university_programs',
        'university_courses_list', 'courses_list', 'uni_courses_list', 'uni_courses_text',
        'university_eligibility_table', 'eligibility_table', 'uni_eligibility_table', 'courses_eligibility_table', 'university_eligibility', 'uni_eligibility',
        'university_eligibility_text', 'uni_eligibility_text', 'courses_eligibility_text', 'eligibility_text', 'course_eligibility', 'course_eligibility_text',
        'subdomain_programmes_table', 'university_programmes_table', 'uni_programmes_table', 'ugc_deb_approved_courses_table', 'university_ugc_courses_table',
        'compare_universities_table', 'universities_comparison_table', 'compare_universities_fees', 'universities_fee_comparison', 'alternate_universities_fees', 'alternate_universities_fees_table', 'subdomain_fees_table', 'subdomain_course_fees_table', 'university_fees_table', 'uni_fees_table', 'programs_table', 'university_programs_table',
        'course_syllabus', 'syllabus', 'uni_syllabus', 'university_course_syllabus', 'uni_course_syllabus',
        'course_fees', 'course_fee_table', 'course_fee', 'fee_table', 'fee_structure', 'university_course_fees', 'uni_course_fees',
        'course_specializations', 'course_specialization_table', 'course_specialization', 'specializations_table', 'university_course_specializations', 'uni_course_specializations',
        'job_roles_table', 'job_roles', 'course_job_roles', 'job_roles_salary',
        'course_table', 'universities_table', 'course_universities', 'top_universities',
        'sample_degree', 'university_sample_degree', 'uni_sample_degree', 'sample_degree_card', 'sample_degree_image', 'sample_degree_button', 'sample_degree_url',
        'custom_lead_form', 'lead_form', 'counseling_lead_form', 'counseling_form', 'lead_form_box', 'sode_lead_form',
        'counseling_button', 'counseling_btn', 'apply_now_button', 'free_counseling_button', 'applynow_button',
        'application_process', 'university_application_process', 'uni_application_process', 'idol_application_process', 'admission_process', 'sode_application_process',
        'university_dates', 'important_dates', 'uni_important_dates', 'admission_dates', 'dates_table', 'university_dates_table', 'admission_last_date',
        'alternate_universities', 'alternative_universities', 'alternatives_universities', 'alternate_university_list', 'alternative_university_list', 'alternatives_list',
        'footer', 'universal_footer', 'sode_footer', 'footer_section'
    ];

    // 2. Parse Shortcode string into { tag, params } with smart quotes normalization
    function parseShortcode(raw) {
        if (!raw) return null;

        // Normalize smart quotes and HTML entities
        const cleaned = raw
            .replace(/[\u201C\u201D\u201E\u201F\u2033\u2036]/g, '"')
            .replace(/[\u2018\u2019\u201A\u201B\u2032\u2035]/g, "'")
            .replace(/&quot;/g, '"')
            .replace(/&#8220;|&#8221;/g, '"')
            .replace(/&#8216;|&#8217;/g, "'")
            .replace(/&amp;/g, '&');

        const match = cleaned.match(/^\[([a-zA-Z0-9_-]+)([\s\S]*?)\]\s*$/);
        if (!match) return null;

        const tag = match[1].toLowerCase();
        const rawAttrs = match[2];
        const params = { uni: uniSlug, university: uniSlug };

        const attrRegex = /([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'`=<>]+))/g;
        let m;
        while ((m = attrRegex.exec(rawAttrs)) !== null) {
            const key = m[1].toLowerCase();
            const val = m[2] !== undefined ? m[2] : (m[3] !== undefined ? m[3] : m[4]);
            params[key] = val;
        }

        return { tag, params };
    }

    // 3. Scan DOM for Raw Shortcodes & Batch Render
    let isProcessing = false;
    async function processShortcodes() {
        if (!document.body || isProcessing) return;
        isProcessing = true;

        try {
            const shortcodeRegex = /\[([a-zA-Z0-9_-]+)([\s\S]*?)\]/g;
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
            const matchedItems = [];
            let node;

            while ((node = walker.nextNode())) {
                const parent = node.parentNode;
                if (!parent) continue;
                const tag = parent.tagName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA' || tag === 'NOSCRIPT') continue;

                const val = node.nodeValue;
                if (val && val.indexOf('[') !== -1 && val.indexOf(']') !== -1) {
                    let match;
                    shortcodeRegex.lastIndex = 0;
                    while ((match = shortcodeRegex.exec(val)) !== null) {
                        const shortcodeName = match[1].toLowerCase();
                        if (knownShortcodes.includes(shortcodeName)) {
                            matchedItems.push({
                                node: node,
                                raw: match[0],
                                name: shortcodeName
                            });
                        }
                    }
                }
            }

            // Also check Elementor shortcode containers directly
            const elementorWidgets = document.querySelectorAll('.elementor-shortcode, .elementor-widget-shortcode .elementor-widget-container');
            elementorWidgets.forEach(el => {
                if (el.dataset.sodeProcessed) return;
                const txt = el.textContent.trim();
                if (txt.startsWith('[') && txt.endsWith(']')) {
                    const tagMatch = txt.match(/^\[([a-zA-Z0-9_-]+)/);
                    if (tagMatch) {
                        const name = tagMatch[1].toLowerCase();
                        if (knownShortcodes.includes(name)) {
                            matchedItems.push({
                                element: el,
                                raw: txt,
                                name: name
                            });
                            el.dataset.sodeProcessed = '1';
                        }
                    }
                }
            });

            if (!matchedItems.length) {
                isProcessing = false;
                return;
            }

            console.log(`🔎 [SODE] Found ${matchedItems.length} shortcode(s) in DOM:`, matchedItems.map(m => m.name));

            // Prepare batch request payload
            const batchItems = {};
            matchedItems.forEach(item => {
                if (!batchItems[item.raw]) {
                    const parsed = parseShortcode(item.raw);
                    if (parsed) {
                        batchItems[item.raw] = {
                            component: parsed.tag,
                            params: parsed.params
                        };
                    }
                }
            });

            if (!Object.keys(batchItems).length) {
                isProcessing = false;
                return;
            }

            // Send Batch Render API Call
            const batchUrl = `${BASE_URL}/admin/api/render_component.php?component=batch`;
            let responseData = null;

            try {
                if (typeof axios !== 'undefined') {
                    const res = await axios.post(batchUrl, { items: batchItems, uni: uniSlug });
                    responseData = res.data;
                } else {
                    const res = await fetch(batchUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ items: batchItems, uni: uniSlug })
                    });
                    responseData = await res.json();
                }
            } catch (netErr) {
                console.error('❌ [SODE BATCH NETWORK ERROR]:', netErr);
            }

            if (responseData && responseData.results) {
                console.log('✅ [SODE BATCH SUCCESS] Rendered Components Received:', Object.keys(responseData.results).length);

                matchedItems.forEach(item => {
                    const html = responseData.results[item.raw];
                    if (!html) return;

                    // If item matched via direct Elementor element container
                    if (item.element && item.element.parentNode) {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'sode-rendered-component sode-' + item.name;
                        wrapper.innerHTML = html;
                        item.element.parentNode.replaceChild(wrapper, item.element);
                        runScripts(wrapper);
                        return;
                    }

                    // If item matched via TextNode
                    if (item.node && item.node.parentNode) {
                        const parent = item.node.parentNode;
                        const textVal = item.node.nodeValue;

                        // Case A: TextNode ONLY contains the shortcode
                        if (textVal.trim() === item.raw.trim()) {
                            const wrapper = document.createElement('div');
                            wrapper.className = 'sode-rendered-component sode-' + item.name;
                            wrapper.innerHTML = html;

                            // If alone inside a wrapper paragraph or div, replace parent cleanly
                            if ((parent.tagName === 'P' || parent.tagName === 'DIV' || parent.classList.contains('elementor-widget-container')) && parent.childNodes.length === 1) {
                                parent.parentNode.replaceChild(wrapper, parent);
                            } else {
                                parent.replaceChild(wrapper, item.node);
                            }
                            runScripts(wrapper);
                        } else {
                            // Case B: Shortcode is INLINE inside surrounding text
                            const idx = textVal.indexOf(item.raw);
                            if (idx !== -1) {
                                const beforeText = textVal.substring(0, idx);
                                const afterText = textVal.substring(idx + item.raw.length);

                                const span = document.createElement('span');
                                span.className = 'sode-rendered-inline sode-' + item.name;
                                span.innerHTML = html;

                                const frag = document.createDocumentFragment();
                                if (beforeText) frag.appendChild(document.createTextNode(beforeText));
                                frag.appendChild(span);
                                if (afterText) frag.appendChild(document.createTextNode(afterText));

                                parent.replaceChild(frag, item.node);
                                runScripts(span);
                            }
                        }
                    }
                });
            }
        } finally {
            isProcessing = false;
        }
    }

    // 4. DOM Global Keys Replacement Engine ($KEY$, {KEY}, #LEAD_FORM#)
    function replaceDOMKeys(keys) {
        if (!keys || !Object.keys(keys).length) return;

        const replacements = {};
        const safeBareKeywords = [
            'UNIVERSITY_SHORT_NAME_LOWER',
            'UNIVERSITY_SHORT_NAME',
            'UNIVERSITY_SLUG',
            'UNI_SHORT_NAME_LOWER',
            'UNI_SHORT_NAME',
            'UNIVERSITY_NAME',
            'UNI_SLUG'
        ];

        Object.entries(keys).forEach(([code, val]) => {
            const strVal = String(val);
            const raw = code.replace(/^\$|\$$/g, '').replace(/^\{|\}$/g, '').replace(/^\[|\]$/g, '').replace(/^#|#$/g, '');

            [
                code,
                `$${raw}$`,
                `{{${raw}}}`,
                `{${raw}}`,
                `#${raw}#`
            ].forEach(p => {
                if (p && !replacements[p]) replacements[p] = strVal;
            });

            const upperRaw = raw.toUpperCase();
            if (safeBareKeywords.includes(upperRaw) && upperRaw === raw) {
                if (!replacements[upperRaw]) replacements[upperRaw] = strVal;
            }
        });

        const patterns = Object.keys(replacements).sort((a, b) => b.length - a.length);

        function walk(node) {
            if (!node) return;
            if (node.nodeType === 3) {
                let t = node.textContent;
                let changed = false;

                patterns.forEach(p => {
                    if (t.indexOf(p) !== -1) {
                        t = t.split(p).join(replacements[p]);
                        changed = true;
                    }
                });

                if (changed) {
                    if (t.indexOf('<') !== -1 && t.indexOf('>') !== -1) {
                        const span = document.createElement('span');
                        span.innerHTML = t;
                        while (span.firstChild) {
                            node.parentNode.insertBefore(span.firstChild, node);
                        }
                        node.parentNode.removeChild(node);
                        runScripts(span);
                    } else {
                        node.textContent = t;
                    }
                }
            } else if (node.nodeType === 1) {
                const tag = node.tagName;
                if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'NOSCRIPT') return;

                ['href', 'title', 'alt', 'placeholder', 'data-title', 'content'].forEach(attr => {
                    if (node.hasAttribute(attr)) {
                        let a = node.getAttribute(attr);
                        let ac = false;
                        patterns.forEach(p => {
                            if (a.indexOf(p) !== -1) {
                                a = a.split(p).join(replacements[p]);
                                ac = true;
                            }
                        });
                        if (ac) node.setAttribute(attr, a);
                    }
                });

                node.childNodes.forEach(walk);
            }
        }

        if (document.body) {
            walk(document.body);
        }
    }

    // 5. Inject Footer Modals & Widgets
    async function injectFooterModals() {
        if (document.getElementById('counselingFormPopupOverlay')) return;

        try {
            const modalsUrl = `${BASE_URL}/admin/api/render_component.php?component=popup_modals&uni=${encodeURIComponent(uniSlug)}`;
            const res = await fetch(modalsUrl);
            const html = await res.text();
            if (html && html.indexOf('sode-modal-overlay') !== -1) {
                const modalWrap = document.createElement('div');
                modalWrap.id = 'sode-footer-modals-wrap';
                modalWrap.innerHTML = html;
                document.body.appendChild(modalWrap);
                runScripts(modalWrap);
                console.log('✅ [SODE] Popup modals injected into DOM');
            }
        } catch (e) {}
    }

    // 6. Main Orchestrator
    let isInitialized = false;
    async function initSodeUniversalEngine() {
        if (isInitialized) {
            // Re-check for any lazy-loaded DOM elements or late shortcodes
            processShortcodes();
            return;
        }
        isInitialized = true;
        console.log('⚡ [SODE] Executing Universal Engine instantly...');

        // Step 1: Check SessionStorage or fetch Global Keys
        let keys = null;
        try {
            const cached = sessionStorage.getItem(cacheKeyKeys);
            if (cached) {
                keys = JSON.parse(cached);
                console.log('⚡ [SODE CACHE HIT] Global keys loaded in 0ms');
            }
        } catch (e) {}

        if (!keys) {
            try {
                const apiUrl = `${BASE_URL}/api/get_global_keys.php?uni=${encodeURIComponent(uniSlug)}`;
                const res = await fetch(apiUrl);
                const data = await res.json();
                if (data && data.keys) {
                    keys = data.keys;
                    try { sessionStorage.setItem(cacheKeyKeys, JSON.stringify(keys)); } catch (e) {}
                    console.log('✅ [SODE SUCCESS] Global Keys Fetched:', Object.keys(keys).length);
                }
            } catch (err) {
                console.error('❌ [SODE KEY ERROR]:', err);
            }
        }

        // Step 2: Replace text keys in DOM ($KEY$, {KEY}, #LEAD_FORM#)
        if (keys) {
            replaceDOMKeys(keys);
        }

        // Step 3: Render all unparsed shortcodes in DOM ([edu_banner], [university_courses], etc.)
        await processShortcodes();

        // Step 4: Inject footer popups
        injectFooterModals();

        // Step 5: Attach Modal Click Triggers
        document.addEventListener('click', function (e) {
            const target = e.target.closest('.applynow, .sode-apply-btn, [href="#custom_lead_form"], [href="#apply"]');
            if (target) {
                e.preventDefault();
                const overlay = document.getElementById('counselingFormPopupOverlay');
                if (overlay) overlay.classList.add('active');
            }
            if (e.target.closest('.sode-modal-close, .sode-modal-overlay')) {
                if (e.target.classList.contains('sode-modal-overlay') || e.target.closest('.sode-modal-close')) {
                    document.querySelectorAll('.sode-modal-overlay').forEach(o => o.classList.remove('active'));
                }
            }
        });

        // Global apply modal helper
        window.openApplyModal = function () {
            const overlay = document.getElementById('counselingFormPopupOverlay');
            if (overlay) overlay.classList.add('active');
        };
    }

    // Execute immediately without delay
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        initSodeUniversalEngine();
    } else {
        document.addEventListener('DOMContentLoaded', initSodeUniversalEngine);
        // Safety: also run on window.onload if Elementor widgets took longer
        window.addEventListener('load', () => {
            initSodeUniversalEngine();
            processShortcodes();
        });
    }
})();
