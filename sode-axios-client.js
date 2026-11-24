/**
 * ====================================================================
 * SODE Central Universal Client Engine (High Performance & Zero Flash)
 * File: sode-axios-client.js
 * 
 * 100% Client-Side Engine — ZERO PHP plugins required on subdomains.
 * - Auto-detects University from Subdomain URL.
 * - Zero Flash: Synchronously hides unrendered shortcodes before paint.
 * - Single Unified Network Request: Fetches components & keys together.
 * - Redis-backed server caching & Local Storage 0ms hydration.
 * - Replaces all Global Keys ($KEY$, {KEY}, #LEAD_FORM#, etc.).
 * - Renders all WordPress Shortcodes ([edu_banner], [latest_news], etc.).
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
    const cacheKeyComponents = 'sode_comps_' + uniSlug;
    console.log('🚀 [SODE UNIVERSAL ENGINE] Initialized for university:', uniSlug);

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

    // Helper: Safely insert rendered HTML replacing shortcode node or element
    function applyRenderedHTML(item, html) {
        if (!html) return;

        // If item matched via direct Elementor element container
        if (item.element && item.element.parentNode) {
            const wrapper = document.createElement('div');
            wrapper.className = 'sode-rendered-component sode-' + item.name + ' sode-hydrated';
            wrapper.innerHTML = html;
            item.element.parentNode.replaceChild(wrapper, item.element);
            runScripts(wrapper);
            return;
        }

        // If item matched via TextNode
        if (item.node && item.node.parentNode) {
            const parent = item.node.parentNode;
            const textVal = item.node.nodeValue;

            if (textVal.trim() === item.raw.trim()) {
                const wrapper = document.createElement('div');
                wrapper.className = 'sode-rendered-component sode-' + item.name + ' sode-hydrated';
                wrapper.innerHTML = html;

                if ((parent.tagName === 'P' || parent.tagName === 'DIV' || parent.classList.contains('elementor-widget-container')) && parent.childNodes.length === 1) {
                    parent.parentNode.replaceChild(wrapper, parent);
                } else {
                    parent.replaceChild(wrapper, item.node);
                }
                runScripts(wrapper);
            } else {
                const idx = textVal.indexOf(item.raw);
                if (idx !== -1) {
                    const beforeText = textVal.substring(0, idx);
                    const afterText = textVal.substring(idx + item.raw.length);

                    const span = document.createElement('span');
                    span.className = 'sode-rendered-inline sode-' + item.name + ' sode-hydrated';
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
    }

    // 3. Collect all shortcodes currently in the DOM
    function collectShortcodesFromDOM() {
        if (!document.body) return [];

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

        return matchedItems;
    }

    // 4. DOM Global Keys Replacement Engine ($KEY$, {KEY}, #LEAD_FORM#)
    function replaceDOMKeys(keys) {
        if (!keys || !Object.keys(keys).length || !document.body) return;

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

        walk(document.body);
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
            }
        } catch (e) {}
    }

    // 6. Unified High-Performance Orchestrator
    let isInitialized = false;
    async function runUniversalEngine() {
        if (isInitialized) return;
        isInitialized = true;

        // Disconnect prehide observer once engine takes over
        if (window.__sodePrehideObserver) {
            window.__sodePrehideObserver.disconnect();
        }

        const startTime = performance.now();

        // Step A: Instant Local Hydration (0ms from SessionStorage)
        let cachedKeys = null;
        let cachedComps = null;
        try {
            const kStr = sessionStorage.getItem(cacheKeyKeys);
            const cStr = sessionStorage.getItem(cacheKeyComponents);
            if (kStr) cachedKeys = JSON.parse(kStr);
            if (cStr) cachedComps = JSON.parse(cStr);
        } catch (e) {}

        const matchedItems = collectShortcodesFromDOM();

        // If local cache hit, hydrate immediately (0ms instant render!)
        if (cachedComps && matchedItems.length) {
            matchedItems.forEach(item => {
                if (cachedComps[item.raw]) {
                    applyRenderedHTML(item, cachedComps[item.raw]);
                }
            });
            console.log('⚡ [SODE 0MS HIT] Components hydrated instantly from local session cache');
        }

        if (cachedKeys) {
            replaceDOMKeys(cachedKeys);
        }

        // Step B: Prepare unified single API request
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

        // Trigger footer modals in parallel
        injectFooterModals();

        // If no shortcodes and keys are already cached, we are done
        if (!Object.keys(batchItems).length && cachedKeys) {
            return;
        }

        // Single Unified API Call: Fetches batch components AND global keys in 1 roundtrip
        try {
            const batchUrl = `${BASE_URL}/admin/api/render_component.php?component=batch`;
            const res = await fetch(batchUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: batchItems, uni: uniSlug })
            });
            const data = await res.json();

            if (data) {
                // 1. Process and cache Global Keys
                if (data.keys && Object.keys(data.keys).length) {
                    replaceDOMKeys(data.keys);
                    try { sessionStorage.setItem(cacheKeyKeys, JSON.stringify(data.keys)); } catch (e) {}
                }

                // 2. Process and cache Rendered Components
                if (data.results && Object.keys(data.results).length) {
                    matchedItems.forEach(item => {
                        const html = data.results[item.raw];
                        if (html) {
                            applyRenderedHTML(item, html);
                        }
                    });

                    // Update session cache
                    try {
                        const updatedComps = Object.assign({}, cachedComps || {}, data.results);
                        sessionStorage.setItem(cacheKeyComponents, JSON.stringify(updatedComps));
                    } catch (e) {}
                }

                const elapsed = Math.round(performance.now() - startTime);
                console.log(`✅ [SODE COMPLETED] All components & keys rendered in ${elapsed}ms`);
            }
        } catch (err) {
            console.error('❌ [SODE ENGINE ERROR]:', err);
        } finally {
            // Unhide any remaining pending containers
            document.querySelectorAll('[data-sode-pending]').forEach(el => {
                el.style.opacity = '1';
                el.removeAttribute('data-sode-pending');
            });
        }
    }

    // Modal click triggers
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

    window.openApplyModal = function () {
        const overlay = document.getElementById('counselingFormPopupOverlay');
        if (overlay) overlay.classList.add('active');
    };

    // Execute immediately without delay
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        runUniversalEngine();
    } else {
        document.addEventListener('DOMContentLoaded', runUniversalEngine);
        window.addEventListener('load', runUniversalEngine);
    }
})();
