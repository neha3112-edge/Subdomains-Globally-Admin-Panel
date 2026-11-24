/**
 * ====================================================================
 * SODE Central Universal Client Engine (Pure JavaScript & Axios)
 * File: sode-axios-client.js
 * 
 * 100% Client-Side Engine — ZERO PHP plugins required on subdomains.
 * - Auto-detects University from Subdomain URL.
 * - Replaces all Global Keys ($KEY$, {KEY}, #LEAD_FORM#, etc.).
 * - Detects and Server-Side Renders (SSR) all WordPress Shortcodes:
 *   [edu_banner], [latest_news], [university_courses],
 *   [university_courses_list], [university_eligibility_table], etc.
 * - Injects Counseling Modals, Popups, and WhatsApp Widgets.
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
    console.log('🚀 [SODE AXIOS ENGINE] Active for university:', uniSlug);

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

    // 2. Parse Shortcode string into { tag, params }
    function parseShortcode(raw) {
        const match = raw.match(/^\[([a-zA-Z0-9_-]+)([\s\S]*?)\]$/);
        if (!match) return null;
        const tag = match[1];
        const rawAttrs = match[2];
        const params = { uni: uniSlug, university: uniSlug };
        const attrRegex = /([a-zA-Z0-9_-]+)=(?:"([^"]*)"|'([^']*)'|([^\s"'>]+))/g;
        let m;
        while ((m = attrRegex.exec(rawAttrs)) !== null) {
            const key = m[1];
            const val = m[2] !== undefined ? m[2] : (m[3] !== undefined ? m[3] : m[4]);
            params[key] = val;
        }
        return { tag, params };
    }

    // 3. Scan DOM for Raw Shortcodes & Batch Render
    const knownShortcodes = [
        'edu_banner', 'banner',
        'latest_news', 'news', 'news_marquee', 'universal_news',
        'university_courses', 'courses', 'sode_courses_table', 'uni_courses',
        'university_courses_list', 'courses_list', 'uni_courses_list',
        'university_eligibility_table', 'eligibility_table', 'uni_eligibility_table',
        'university_eligibility_text', 'eligibility_text',
        'announcements_list', 'recent_announcements', 'announcements',
        'custom_lead_form', 'lead_form',
        'sample_degree', 'admission_process'
    ];

    async function processShortcodes() {
        if (!document.body) return;

        // Find all text nodes that look like [shortcode ...]
        const shortcodeRegex = /\[([a-zA-Z0-9_-]+)([\s\S]*?)\]/g;
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
        const matchedNodes = [];
        let node;

        while ((node = walker.nextNode())) {
            const tag = node.parentNode ? node.parentNode.tagName : '';
            if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEXTAREA') continue;

            const val = node.nodeValue;
            if (val && val.indexOf('[') !== -1 && val.indexOf(']') !== -1) {
                let match;
                shortcodeRegex.lastIndex = 0;
                while ((match = shortcodeRegex.exec(val)) !== null) {
                    const shortcodeName = match[1].toLowerCase();
                    if (knownShortcodes.includes(shortcodeName)) {
                        matchedNodes.push({
                            node: node,
                            raw: match[0],
                            name: shortcodeName
                        });
                    }
                }
            }
        }

        if (!matchedNodes.length) return;

        console.log(`🔎 [SODE] Found ${matchedNodes.length} shortcode(s) in DOM to render:`, matchedNodes.map(m => m.name));

        // Prepare batch request items
        const batchItems = {};
        matchedNodes.forEach(item => {
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

        // Send Batch Render API Call
        try {
            const batchUrl = `${BASE_URL}/admin/api/render_component.php?component=batch`;
            let responseData = null;

            if (typeof axios !== 'undefined') {
                const res = await axios.post(batchUrl, {
                    items: batchItems,
                    uni: uniSlug
                });
                responseData = res.data;
            } else {
                const res = await fetch(batchUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ items: batchItems, uni: uniSlug })
                });
                responseData = await res.json();
            }

            if (responseData && responseData.results) {
                console.log('✅ [SODE BATCH SUCCESS] Rendered Components Received:', Object.keys(responseData.results));

                matchedNodes.forEach(item => {
                    const html = responseData.results[item.raw];
                    if (html && item.node && item.node.parentNode) {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'sode-rendered-component sode-' + item.name;
                        wrapper.innerHTML = html;

                        // If text node is alone inside a <p> or <div>, replace the whole parent block cleanly
                        const parent = item.node.parentNode;
                        if (parent && (parent.tagName === 'P' || parent.tagName === 'DIV') && parent.childNodes.length === 1) {
                            parent.parentNode.replaceChild(wrapper, parent);
                        } else {
                            parent.replaceChild(wrapper, item.node);
                        }

                        runScripts(wrapper);
                    }
                });
            }
        } catch (err) {
            console.error('❌ [SODE BATCH ERROR] Failed to render shortcodes:', err);
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
                `[${raw}]`,
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
    async function initSodeUniversalEngine() {
        console.log('⚡ [SODE] Starting Full Universal Page Rendering...');

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

        // Step 2: Replace text keys in DOM
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

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSodeUniversalEngine);
    } else {
        initSodeUniversalEngine();
    }
})();
