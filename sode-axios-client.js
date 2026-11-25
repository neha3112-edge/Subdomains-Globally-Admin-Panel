/**
 * ====================================================================
 * SODE Central Universal Client Engine (Ultra Fast Priority Paint & Full Modals)
 * File: sode-axios-client.js - Version 8.0
 * 
 * 1. Legal Popups: Disclaimer, Privacy Policy, Terms & Conditions popups.
 * 2. URL Key Cleanup: Replaces {DSU_LOWER}, {UNI_LOWER}, {UNIVERSITY_SLUG} across all links.
 * 3. Inner Page Form Popups: Working Counseling, Brochure, Compare modals.
 * 4. Direct CRM Integration: Sends leads to central admin API with 0 PHP plugins needed.
 * 5. Instant Hero Priority Pipeline: Decoupled from slow batch Promise.all.
 * 6. Zero Scroll-Jump Armor: Blocks empty "#" jumps and resets.
 * ====================================================================
 */
(function () {
    'use strict';

    const BASE_URL = 'https://admin.distanceeducationschool.com';
    window.SODE_BASE_URL = BASE_URL;

    // Early Lead Token Preload
    let earlyTokenPromise = fetch(`${BASE_URL}/admin/api/render_component.php?component=get_lead_token`)
        .then(res => res.json())
        .then(res => {
            if (res && res.success) {
                window.sodeSessionToken = res.data;
            }
            return res;
        })
        .catch(() => null);

    // Suppress Browser PWA "Install App" Icon in Address Bar
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        return false;
    });

    // Intercept local WordPress get_lead_token to eliminate 400 Bad Request error when plugin is deactivated
    const _origFetch = window.fetch;
    window.fetch = function (resource, init) {
        const urlStr = typeof resource === 'string' ? resource : (resource && resource.url ? resource.url : '');
        if (urlStr.indexOf('admin-ajax.php') !== -1 && urlStr.indexOf('action=get_lead_token') !== -1) {
            const token = window.sodeSessionToken || ('tok_' + Math.random().toString(36).substring(2) + Date.now().toString(36));
            window.sodeSessionToken = token;
            return Promise.resolve(new Response(JSON.stringify({ success: true, data: token }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            }));
        }
        return _origFetch.apply(this, arguments);
    };

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
    const storagePrefix = 'sode_v9_' + uniSlug + '_';
    const pagePath = window.location.pathname;
    const currentYear = new Date().getFullYear().toString();

    // 1. Dynamic Key Store (Zero hardcoded university/key dictionaries)
    let activeKeys = {
        '$YEAR$': currentYear,
        '{YEAR}': currentYear,
        '[YEAR]': currentYear,
        '{DSU_LOWER}': uniSlug,
        '{UNI_LOWER}': uniSlug,
        '{UNIVERSITY_SLUG}': uniSlug,
        '{UNI_SLUG}': uniSlug
    };

    // Load persisted dynamic keys from storage (0ms instant render on repeat visits)
    try {
        const savedKeys = localStorage.getItem(storagePrefix + 'keys');
        if (savedKeys) {
            const parsed = JSON.parse(savedKeys);
            if (parsed && typeof parsed === 'object') {
                Object.assign(activeKeys, parsed);
            }
        }
    } catch (e) {}

    // 2. Early Dynamic Key Preload in Parallel (Microsecond 0)
    let earlyKeysPromise = fetch(`${BASE_URL}/admin/api/get_global_keys.php?uni=${encodeURIComponent(uniSlug)}`, { priority: 'high' })
        .then(res => res.json())
        .then(data => {
            if (data && (data.keys || data.data)) {
                const fetchedKeys = data.keys || data.data;
                Object.assign(activeKeys, fetchedKeys);
                try {
                    localStorage.setItem(storagePrefix + 'keys', JSON.stringify(activeKeys));
                } catch (e) {}
                replaceDOMKeys(activeKeys);
            }
            return data;
        })
        .catch(() => null);

    // 3. Early Hero Banner Preload in Parallel (Microsecond 0 - Homepage only)
    let earlyBannerHtmlPromise = (pagePath === '/' || pagePath === '')
        ? fetch(`${BASE_URL}/admin/api/render_component.php?component=banner&uni=${encodeURIComponent(uniSlug)}`, { priority: 'high' })
            .then(res => res.text()).catch(() => null)
        : null;

    // 4. Early Modals Preload
    const earlyModalsPromise = fetch(`${BASE_URL}/admin/api/render_component.php?component=popup_modals&uni=${encodeURIComponent(uniSlug)}`)
        .then(res => res.text()).catch(() => null);

    // Comprehensive list of known shortcodes
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
        'legal_popups', 'disclaimer_popup', 'privacy_popup', 'terms_popup',
        'footer', 'universal_footer', 'sode_footer', 'footer_section'
    ];

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

    // 3. Robust Quote-Aware Shortcode Extractor (Handles nested brackets like [UNIVERSITY_NAME] inside quotes)
    function extractShortcodesFromText(text) {
        if (!text || text.indexOf('[') === -1 || text.indexOf(']') === -1) return [];

        const results = [];
        const len = text.length;
        let i = 0;

        while (i < len) {
            const start = text.indexOf('[', i);
            if (start === -1) break;

            let tagEnd = start + 1;
            while (tagEnd < len && /[a-zA-Z0-9_-]/.test(text[tagEnd])) {
                tagEnd++;
            }
            const tag = text.substring(start + 1, tagEnd).toLowerCase();

            if (tag && knownShortcodes.includes(tag)) {
                let inQuotes = false;
                let quoteChar = '';
                let j = tagEnd;
                let foundEnd = -1;

                while (j < len) {
                    const ch = text[j];
                    if ((ch === '"' || ch === "'") && text[j - 1] !== '\\') {
                        if (!inQuotes) {
                            inQuotes = true;
                            quoteChar = ch;
                        } else if (quoteChar === ch) {
                            inQuotes = false;
                            quoteChar = '';
                        }
                    } else if (ch === ']' && !inQuotes) {
                        foundEnd = j;
                        break;
                    }
                    j++;
                }

                if (foundEnd !== -1) {
                    const raw = text.substring(start, foundEnd + 1);
                    results.push({ name: tag, raw: raw });
                    i = foundEnd + 1;
                    continue;
                }
            }
            i = start + 1;
        }

        return results;
    }

    // 4. Parse Shortcode attributes cleanly
    function parseShortcode(raw) {
        if (!raw) return null;
        const cleaned = raw
            .replace(/[\u201C\u201D\u201E\u201F\u2033\u2036]/g, '"')
            .replace(/[\u2018\u2019\u201A\u201B\u2032\u2035]/g, "'")
            .replace(/&quot;/g, '"')
            .replace(/&#8220;|&#8221;/g, '"')
            .replace(/&#8216;|&#8217;/g, "'")
            .replace(/&amp;/g, '&');

        const tagMatch = cleaned.match(/^\[([a-zA-Z0-9_-]+)/);
        if (!tagMatch) return null;

        const tag = tagMatch[1].toLowerCase();
        const rawAttrs = cleaned.substring(tagMatch[0].length, cleaned.length - 1);
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

        // Guaranteed university key replacement in the HTML string directly
        html = html
            .replace(/\{UNIVERSITY_NAME\}|\[UNIVERSITY_NAME\]|\$UNIVERSITY_NAME\$|\{\{UNIVERSITY_NAME\}\}/gi, activeKeys['{UNIVERSITY_NAME}'])
            .replace(/\{MODE\}|\[MODE\]|\$MODE\$|\{\{MODE\}\}/gi, activeKeys['{MODE}'])
            .replace(/\{UNIVERSITY_SHORT_NAME\}|\[UNIVERSITY_SHORT_NAME\]|\$UNIVERSITY_SHORT_NAME\$|\{UNI_SHORT_NAME\}|\[UNI_SHORT_NAME\]|\{UNI\}|\[UNI\]/gi, activeKeys['{UNIVERSITY_SHORT_NAME}'])
            .replace(/\{DSU_LOWER\}|\[DSU_LOWER\]|\$DSU_LOWER\$/gi, uniSlug)
            .replace(/\{UNI_LOWER\}|\[UNI_LOWER\]|\$UNI_LOWER\$/gi, uniSlug)
            .replace(/\{UNIVERSITY_SLUG\}|\[UNIVERSITY_SLUG\]|\$UNIVERSITY_SLUG\$/gi, uniSlug)
            .replace(/\$YEAR\$|\{YEAR\}|\[YEAR\]/gi, currentYear);

        if (activeKeys) {
            Object.entries(activeKeys).forEach(([k, v]) => {
                if (typeof v === 'string' && v.trim() !== '') {
                    html = html.split(k).join(v);
                }
            });
        }

        // Cache this specific component for instant 0ms reload
        try {
            localStorage.setItem(storagePrefix + 'comp_' + item.raw, html);
            if (item.name === 'edu_banner' || item.name === 'banner') {
                localStorage.setItem(storagePrefix + 'hero_banner_' + pagePath, html);
            }
        } catch (e) {}

        if (item.element && item.element.parentNode) {
            const wrapper = document.createElement('div');
            wrapper.className = 'sode-rendered-component sode-' + item.name + ' sode-hydrated';
            wrapper.innerHTML = html;
            item.element.parentNode.replaceChild(wrapper, item.element);
            item.element = wrapper;
            item.node = null;
            runScripts(wrapper);
            return;
        }

        if (item.node && item.node.parentNode) {
            const parent = item.node.parentNode;
            const textVal = item.node.nodeValue;
            const shortcodeContainer = parent.closest ? parent.closest('.elementor-shortcode, .wp-block-shortcode') : null;
            if (shortcodeContainer) shortcodeContainer.classList.add('sode-hydrated');

            if (textVal.trim() === item.raw.trim()) {
                const wrapper = document.createElement('div');
                wrapper.className = 'sode-rendered-component sode-' + item.name + ' sode-hydrated';
                wrapper.innerHTML = html;

                if ((parent.tagName === 'P' || parent.tagName === 'DIV' || parent.classList.contains('elementor-widget-container')) && parent.childNodes.length === 1) {
                    parent.parentNode.replaceChild(wrapper, parent);
                } else {
                    parent.replaceChild(wrapper, item.node);
                }
                item.element = wrapper;
                item.node = null;
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
                    item.element = span;
                    item.node = null;
                    runScripts(span);
                }
            }
        }
    }

    // 5. Collect all shortcodes currently in the DOM
    function collectShortcodes() {
        if (!document.body) return [];

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
                const found = extractShortcodesFromText(val);
                found.forEach(sc => {
                    matchedItems.push({
                        node: node,
                        raw: sc.raw,
                        name: sc.name
                    });
                });
            }
        }

        const elementorWidgets = document.querySelectorAll('.elementor-shortcode, .elementor-widget-shortcode .elementor-widget-container');
        elementorWidgets.forEach(el => {
            if (el.dataset.sodeProcessed) return;
            const txt = el.textContent.trim();
            if (txt.startsWith('[') && txt.endsWith(']')) {
                const found = extractShortcodesFromText(txt);
                if (found.length === 1 && found[0].raw === txt) {
                    matchedItems.push({
                        element: el,
                        raw: txt,
                        name: found[0].name
                    });
                    el.dataset.sodeProcessed = '1';
                }
            }
        });

        return matchedItems;
    }

    // 6. Fast DOM Global Keys Replacement ({UNIVERSITY_NAME}, [UNIVERSITY_NAME], {DSU_LOWER}, {UNI_LOWER})
    function replaceDOMKeys(keys) {
        if (!keys || !Object.keys(keys).length || !document.body) return;
        Object.assign(activeKeys, keys);

        const replacements = {};

        Object.entries(activeKeys).forEach(([code, val]) => {
            const strVal = String(val);
            const raw = code.replace(/^\$|\$$/g, '').replace(/^\{|\}$/g, '').replace(/^\[|\]$/g, '').replace(/^#|#$/g, '').trim();
            if (!raw || raw.length <= 1) return;

            // STRICT: ONLY delimited patterns ({KEY}, [KEY], $KEY$, {{KEY}}) - NEVER bare words!
            [
                `{${raw}}`,
                `[${raw}]`,
                `$${raw}$`,
                `{{${raw}}}`,
                `{${raw.toLowerCase()}}`,
                `[${raw.toLowerCase()}]`,
                `$${raw.toLowerCase()}$`
            ].forEach(p => {
                if (p && !replacements[p]) replacements[p] = strVal;
            });
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

                ['href', 'src', 'title', 'alt', 'placeholder', 'data-title', 'content'].forEach(attr => {
                    if (node.hasAttribute(attr)) {
                        let a = node.getAttribute(attr);
                        let ac = false;
                        patterns.forEach(p => {
                            if (a.indexOf(p) !== -1) {
                                a = a.split(p).join(replacements[p]);
                                ac = true;
                            }
                        });

                        // Extra shield: Clean any {DSU_LOWER}, {UNI_LOWER}, {UNIVERSITY_SLUG} in URLs
                        if (a.indexOf('_LOWER') !== -1 || a.indexOf('_lower') !== -1 || a.indexOf('SLUG') !== -1 || a.indexOf('slug') !== -1) {
                            const cleaned = a
                                .replace(/\{(?:[a-zA-Z0-9_-]+_)?(?:LOWER|lower)\}/g, uniSlug)
                                .replace(/\[(?:[a-zA-Z0-9_-]+_)?(?:LOWER|lower)\]/g, uniSlug)
                                .replace(/\{(?:UNI|UNIVERSITY)?_?SLUG\}/gi, uniSlug)
                                .replace(/\[(?:UNI|UNIVERSITY)?_?SLUG\]/gi, uniSlug)
                                .replace(/\{DSU_LOWER\}|\[DSU_LOWER\]/gi, uniSlug)
                                .replace(/\{UNI_LOWER\}|\[UNI_LOWER\]/gi, uniSlug);
                            if (cleaned !== a) {
                                a = cleaned;
                                ac = true;
                            }
                        }

                        if (ac) node.setAttribute(attr, a);
                    }
                });

                node.childNodes.forEach(walk);
            }
        }

        walk(document.body);
    }

    // 7. Instant 0ms Pre-Hydration from LocalStorage (Isolated per Shortcode & Page)
    function hydrateFromLocalCache(matchedItems) {
        if (!matchedItems || !matchedItems.length) return false;
        let hydratedCount = 0;

        try {
            // Clean legacy domain-wide banner key that caused cross-page text pollution
            localStorage.removeItem(storagePrefix + 'latest_hero_banner');
        } catch (e) {}

        matchedItems.forEach(item => {
            try {
                let cachedHtml = localStorage.getItem(storagePrefix + 'comp_' + item.raw);
                if (!cachedHtml && (item.name === 'edu_banner' || item.name === 'banner')) {
                    cachedHtml = localStorage.getItem(storagePrefix + 'hero_banner_' + pagePath);
                }

                if (cachedHtml) {
                    applyRenderedHTML(item, cachedHtml);
                    hydratedCount++;
                }
            } catch (e) {}
        });

        if (hydratedCount > 0) {
            replaceDOMKeys(activeKeys);
            return true;
        }
        return false;
    }

    // 8. Dedicated Priority Hero Banner Pipeline (Per-page isolated & Instant 0ms Paint)
    const hydratedBanners = new Set();
    function renderBannerPriority(bannerItem) {
        if (!bannerItem || hydratedBanners.has(bannerItem.raw)) return;
        hydratedBanners.add(bannerItem.raw);

        // Step 1: Isolate Cache Strictly by URL pagePath and raw shortcode (NEVER leak homepage banner to inner pages!)
        const pageKey = storagePrefix + 'hero_banner_' + pagePath;
        const compKey = storagePrefix + 'comp_' + bannerItem.raw;
        const cached = localStorage.getItem(compKey) || localStorage.getItem(pageKey);

        if (cached) {
            // Instant 0ms paint from local cache
            applyRenderedHTML(bannerItem, cached);
            replaceDOMKeys(activeKeys);
        } else {
            // Wipe raw shortcode text IMMEDIATELY (0ms) so user NEVER sees [edu_banner...] on screen
            const placeholder = document.createElement('div');
            placeholder.className = 'sode-rendered-component sode-edu_banner sode-hydrated';
            placeholder.style.cssText = 'min-height:480px; width:100%;';
            if (bannerItem.node && bannerItem.node.parentNode) {
                const parent = bannerItem.node.parentNode;
                const shortcodeContainer = parent.closest ? parent.closest('.elementor-shortcode, .wp-block-shortcode') : null;
                if (shortcodeContainer) shortcodeContainer.classList.add('sode-hydrated');
                parent.replaceChild(placeholder, bannerItem.node);
                bannerItem.element = placeholder;
                bannerItem.node = null;
            } else if (bannerItem.element) {
                bannerItem.element.classList.add('sode-hydrated');
            }
        }

        // Step 2: Trigger High-Priority Fetch in Parallel (Stale-While-Revalidate)
        const parsed = parseShortcode(bannerItem.raw) || { tag: 'banner', params: { uni: uniSlug } };

        fetch(`${BASE_URL}/admin/api/render_component.php?component=banner`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(parsed.params),
            priority: 'high'
        })
        .then(res => res.text())
        .then(html => {
            if (html && html.indexOf('edu-banner') !== -1) {
                // If content is new or differed from old cache, update seamlessly
                if (!cached || html !== cached) {
                    applyRenderedHTML(bannerItem, html);
                    replaceDOMKeys(activeKeys);
                }
                try {
                    localStorage.setItem(compKey, html);
                    localStorage.setItem(pageKey, html);
                } catch (e) {}
            }
        })
        .catch(() => {});
    }

    // 9. Modal Management System (Counseling, Brochure, Compare, and Legal Modals)
    function ensureModalsInDOM(callback) {
        if (document.getElementById('counselingFormPopupOverlay') && document.getElementById('mainDisclaimerPopup')) {
            if (callback) callback();
            return;
        }

        earlyModalsPromise.then(modalsHtml => {
            if (modalsHtml && !document.getElementById('counselingFormPopupOverlay')) {
                const modalWrap = document.createElement('div');
                modalWrap.id = 'sode-footer-modals-wrap';
                modalWrap.innerHTML = modalsHtml;
                document.body.appendChild(modalWrap);
                runScripts(modalWrap);
                replaceDOMKeys(activeKeys);
            }
            if (callback) callback();
        });
    }

    function openCounselingModal(courseName) {
        ensureModalsInDOM(() => {
            const overlay = document.getElementById('counselingFormPopupOverlay');
            if (overlay) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
                if (courseName) {
                    const sel = overlay.querySelector('select[name="course"], input[name="course"]');
                    if (sel) sel.value = courseName;
                }
            }
        });
    }

    function openBrochureModal(courseName) {
        ensureModalsInDOM(() => {
            const overlay = document.getElementById('brochureFormPopupOverlay') || document.getElementById('counselingFormPopupOverlay');
            if (overlay) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
                if (courseName) {
                    const sel = overlay.querySelector('select[name="course"], input[name="course"]');
                    if (sel) sel.value = courseName;
                }
            }
        });
    }

    function openCompareModal() {
        ensureModalsInDOM(() => {
            const overlay = document.getElementById('compareFormPopupOverlay') || document.getElementById('counselingFormPopupOverlay');
            if (overlay) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
    }

    function openLegalModal(type) {
        const map = {
            'disclaimer': 'mainDisclaimerPopup',
            'privacy': 'mainPrivacyPopup',
            'privacy-policy': 'mainPrivacyPopup',
            'terms': 'mainTermsPopup',
            'terms-conditions': 'mainTermsPopup'
        };
        const id = map[type] || 'mainDisclaimerPopup';

        ensureModalsInDOM(() => {
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        });
    }

    // Expose Global Helper Functions
    window.openApplyModal = openCounselingModal;
    window.openCounselingModal = openCounselingModal;
    window.openBrochureModal = openBrochureModal;
    window.openCompareModal = openCompareModal;
    window.openMainPopup = function (id) {
        const el = document.getElementById(id);
        if (el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
    };
    window.closeMainPopup = function (id) {
        const el = document.getElementById(id);
        if (el) { el.classList.remove('active'); document.body.style.overflow = ''; }
    };

    // 10. High-Performance Parallel Execution Engine for Remaining Components
    let isBatchExecuting = false;
    async function executeEngine() {
        if (isBatchExecuting) return;
        isBatchExecuting = true;

        const startTime = performance.now();
        const matchedItems = collectShortcodes();

        // 1. Prioritize Hero Banner Immediately (0ms)
        const bannerItem = matchedItems.find(it => it.name === 'edu_banner' || it.name === 'banner');
        if (bannerItem) {
            renderBannerPriority(bannerItem);
        }

        // 2. Instant Local Render for other components
        hydrateFromLocalCache(matchedItems);
        replaceDOMKeys(activeKeys);

        // 3. Prepare Batch Payload for non-banner / unhydrated components
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

        // 4. Parallel Requests for remaining widgets
        const keysPromise = earlyKeysPromise;
        const batchPromise = Object.keys(batchItems).length > 0
            ? fetch(`${BASE_URL}/admin/api/render_component.php?component=batch`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: batchItems, uni: uniSlug })
            }).then(res => res.json()).catch(() => null)
            : Promise.resolve(null);

        try {
            const [keysData, batchData, modalsHtml] = await Promise.all([
                keysPromise,
                batchPromise,
                earlyModalsPromise
            ]);

            // Process Global Keys
            if (keysData && keysData.keys) {
                Object.entries(keysData.keys).forEach(([k, v]) => {
                    if (typeof v === 'string' && v.trim() !== '') {
                        activeKeys[k] = v.trim();
                    }
                });
                try {
                    localStorage.setItem(storagePrefix + 'keys', JSON.stringify(activeKeys));
                } catch (e) {}
            }

            // Render Batch Components (Courses, News, Dates, etc.)
            if (batchData && batchData.results) {
                if (batchData.keys) {
                    Object.entries(batchData.keys).forEach(([k, v]) => {
                        if (typeof v === 'string' && v.trim() !== '') {
                            activeKeys[k] = v.trim();
                        }
                    });
                }

                matchedItems.forEach(item => {
                    const html = batchData.results[item.raw];
                    if (html) {
                        applyRenderedHTML(item, html);
                    }
                });
            }

            // Inject Modals (Counseling, Brochure, Compare, and Legal Popups)
            if (modalsHtml && !document.getElementById('counselingFormPopupOverlay')) {
                const modalWrap = document.createElement('div');
                modalWrap.id = 'sode-footer-modals-wrap';
                modalWrap.innerHTML = modalsHtml;
                document.body.appendChild(modalWrap);
                runScripts(modalWrap);
            }

            // Final Key Sweep on Whole Page
            replaceDOMKeys(activeKeys);

            const duration = Math.round(performance.now() - startTime);
            console.log(`🚀 [SODE ENGINE COMPLETE] Hydrated in ${duration}ms`);
        } catch (err) {
            console.error('❌ [SODE ENGINE ERROR]:', err);
        } finally {
            isBatchExecuting = false;
            replaceDOMKeys(activeKeys);
        }
    }

    // 11. Universal Event Delegation (Legal Popups, Form Triggers, Zero Scroll-Jump Armor)
    document.addEventListener('click', function (e) {
        // A. Legal Popups Triggers
        const legalBtn = e.target.closest('.disclaimer-main-popup, .privacy-main-popup, .term-main-popup, [href*="#disclaimer"], [href*="#privacy"], [href*="#terms"]');
        if (legalBtn) {
            e.preventDefault();
            e.stopPropagation();
            const href = (legalBtn.getAttribute('href') || '').toLowerCase();
            if (legalBtn.classList.contains('disclaimer-main-popup') || href.includes('disclaimer')) {
                openLegalModal('disclaimer');
            } else if (legalBtn.classList.contains('privacy-main-popup') || href.includes('privacy')) {
                openLegalModal('privacy');
            } else if (legalBtn.classList.contains('term-main-popup') || href.includes('term')) {
                openLegalModal('terms');
            }
            return;
        }

        // B. Brochure Download Popups
        const brochureBtn = e.target.closest('.download-brochure, .edu-brochure-btn, [href*="#brochure"], [href="#download-brochure"], [data-action="brochure"]');
        if (brochureBtn) {
            e.preventDefault();
            e.stopPropagation();
            const course = brochureBtn.dataset.course || '';
            openBrochureModal(course);
            return;
        }

        // C. Counseling / Apply Now Popups
        const applyBtn = e.target.closest('.applynow, .sode-apply-btn, .sf-cta-btn, [href="#custom_lead_form"], [href="#apply"], [href="#counseling"], [data-action="apply"]');
        if (applyBtn) {
            e.preventDefault();
            e.stopPropagation();
            const course = applyBtn.dataset.course || '';
            openCounselingModal(course);
            return;
        }

        // D. Compare University Popups / Buttons
        const compareBtn = e.target.closest('[href="#compare-university"], [href="#compare"], .compare-btn');
        if (compareBtn) {
            e.preventDefault();
            e.stopPropagation();
            openCompareModal();
            return;
        }

        // E. Close Any Active Modal
        if (e.target.closest('.sode-modal-close, .sode-modal-overlay, .des-popup-close-btn, .main-popup-overlay')) {
            if (e.target.classList.contains('sode-modal-overlay') || e.target.classList.contains('main-popup-overlay') || e.target.closest('.sode-modal-close') || e.target.closest('.des-popup-close-btn')) {
                document.querySelectorAll('.sode-modal-overlay, .main-popup-overlay').forEach(o => o.classList.remove('active'));
                document.body.style.overflow = '';
            }
        }

        // F. Zero Scroll-Jump Armor for bare '#' links
        const a = e.target.closest('a');
        if (a) {
            const href = (a.getAttribute('href') || '').trim();
            if (href === '#' || href === '' || href === 'javascript:void(0);' || href === 'javascript:;') {
                e.preventDefault();
            }
        }
    }, { capture: true, passive: false });

    // 12. Direct Client-Side Form Submission (Sends to central admin API with zero PHP plugin dependencies)
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('.customLeadForm');
        if (!form) return;

        e.preventDefault();
        e.stopPropagation();

        const btn = form.querySelector('.submitBtn, button[type="submit"]');
        if (btn && btn.disabled) return;

        const phoneInput = form.querySelector('input[type="tel"], input[name="phone"], .phone');
        if (phoneInput) {
            const cleanPhone = phoneInput.value.replace(/[^0-9]/g, '');
            if (cleanPhone.length < 10) {
                alert('Please enter a valid 10-digit mobile number');
                return;
            }
        }

        if (btn) {
            btn.disabled = true;
            btn.innerText = 'Submitting...';
        }

        const formName = form.dataset.formName || form.querySelector('input[name="form_name"]')?.value || 'Lead Form';
        const formData = new FormData(form);
        const countryCode = form.querySelector('.country_code')?.value || '+91';
        const rawPhone = phoneInput ? phoneInput.value.replace(/[^0-9]/g, '') : '';
        formData.set('phone', countryCode + rawPhone);
        formData.append('uni_slug', uniSlug);
        formData.append('form_name', formName);
        formData.append('page_url', window.location.href);

        fetch(`${BASE_URL}/admin/api/render_component.php?component=send_lead`, {
            method: 'POST',
            headers: {
                'X-Lead-Token': window.sodeSessionToken || ''
            },
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data && data.success) {
                if (btn) {
                    btn.innerText = 'Submitted Successfully ✓';
                    btn.style.backgroundColor = '#16a34a';
                }

                // Redirect to Thank You page
                if (formName.includes('Brochure') || form.classList.contains('popup-brochure-form') || form.closest('#brochureFormPopupOverlay')) {
                    window.location.href = '/thank-you?download_brochure=1';
                } else if (formName.includes('Compare')) {
                    window.open('https://distanceeducationschool.com/compare-university/', '_blank');
                    window.location.href = '/thank-you';
                } else {
                    window.location.href = '/thank-you';
                }
            } else {
                alert((data && data.data) ? data.data : 'Something went wrong. Please try again.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerText = 'Submit';
                }
            }
        })
        .catch(err => {
            console.error('Lead error:', err);
            alert('Submission failed. Please check your connection.');
            if (btn) {
                btn.disabled = false;
                btn.innerText = 'Submit';
            }
        });
    }, true);

    // Clean up any empty "#" pushed to URL without jumping scroll
    window.addEventListener('hashchange', function () {
        if (window.location.hash === '#' || window.location.hash === '') {
            try {
                history.replaceState(null, document.title, window.location.pathname + window.location.search);
            } catch (err) {}
        }
    });

    // 13. Instant Hero Mutation Detection
    function scanAndHydrateBanner() {
        if (!document.body && !document.documentElement) return;
        const items = collectShortcodes();
        const bItem = items.find(it => it.name === 'edu_banner' || it.name === 'banner');
        if (bItem && !hydratedBanners.has(bItem.raw)) {
            renderBannerPriority(bItem);
        }
    }

    scanAndHydrateBanner();

    const observer = new MutationObserver(function () {
        if (hydratedBanners.size === 0) {
            scanAndHydrateBanner();
        }
        if (!isBatchExecuting && document.body) {
            const items = collectShortcodes();
            if (items.length > 0) {
                executeEngine();
            }
        }
    });

    if (document.documentElement) {
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            scanAndHydrateBanner();
            executeEngine();
        });
    } else {
        scanAndHydrateBanner();
        executeEngine();
    }

    window.addEventListener('load', () => {
        executeEngine();
        observer.disconnect();
    });
})();
