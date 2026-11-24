/**
 * ====================================================================
 * SODE Central Universal Client Engine (Ultra Fast Promise.all & 0ms Cache)
 * File: sode-axios-client.js - Version 7.0
 * 
 * - Instant Hero Preload: Initiates fetch in parallel at earliest script execution.
 * - Quote-Aware Shortcode Parser: Handles nested [UNIVERSITY_NAME] and [MODE] brackets.
 * - 0ms Pre-Hydration & Redis/LocalStorage Cache.
 * - Zero Scroll-Jump Armor: Eliminates empty "#" scroll jumps and sticky nav resets.
 * - 100% Guaranteed Key Replacement: Replaces {UNIVERSITY_NAME}, [UNIVERSITY_NAME],
 *   {MODE}, [MODE], $YEAR$, etc.
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
    const storagePrefix = 'sode_v7_' + uniSlug + '_';

    // Known university metadata dictionary for instant 0ms fallback
    const UNIVERSITY_MAP = {
        'dsu':    { name: 'Dayananda Sagar University', short: 'DSU', mode: 'Online' },
        'cu':     { name: 'Chandigarh University', short: 'CU', mode: 'Online' },
        'mu':     { name: 'Manipal University', short: 'MUJ', mode: 'Online' },
        'amity':  { name: 'Amity University', short: 'Amity', mode: 'Online' },
        'lpu':    { name: 'Lovely Professional University', short: 'LPU', mode: 'Online' },
        'jgu':    { name: 'O.P. Jindal Global University', short: 'JGU', mode: 'Online' },
        'sharda': { name: 'Sharda University', short: 'Sharda', mode: 'Online' },
        'srm':    { name: 'SRM University', short: 'SRM', mode: 'Online' },
        'upes':   { name: 'UPES', short: 'UPES', mode: 'Online' },
        'gla':    { name: 'GLA University', short: 'GLA', mode: 'Online' },
        'vgu':    { name: 'Vivekananda Global University', short: 'VGU', mode: 'Online' },
        'uu':     { name: 'Uttaranchal University', short: 'UU', mode: 'Online' },
        'klu':    { name: 'K L University', short: 'KLU', mode: 'Online' }
    };

    const uniInfo = UNIVERSITY_MAP[uniSlug] || {
        name: uniSlug.toUpperCase() + ' University',
        short: uniSlug.toUpperCase(),
        mode: 'Online'
    };

    const currentYear = new Date().getFullYear().toString();

    // Instant Master Key Replacements
    let activeKeys = {
        '{UNIVERSITY_NAME}': uniInfo.name,
        '[UNIVERSITY_NAME]': uniInfo.name,
        '$UNIVERSITY_NAME$': uniInfo.name,
        '{{UNIVERSITY_NAME}}': uniInfo.name,
        'UNIVERSITY_NAME': uniInfo.name,

        '{MODE}': uniInfo.mode,
        '[MODE]': uniInfo.mode,
        '$MODE$': uniInfo.mode,
        '{{MODE}}': uniInfo.mode,
        'MODE': uniInfo.mode,

        '{UNIVERSITY_SHORT_NAME}': uniInfo.short,
        '[UNIVERSITY_SHORT_NAME]': uniInfo.short,
        '$UNIVERSITY_SHORT_NAME$': uniInfo.short,
        '{{UNIVERSITY_SHORT_NAME}}': uniInfo.short,
        'UNIVERSITY_SHORT_NAME': uniInfo.short,
        '{UNI_SHORT_NAME}': uniInfo.short,
        '[UNI_SHORT_NAME]': uniInfo.short,
        '$UNI_SHORT_NAME$': uniInfo.short,
        '{UNI}': uniInfo.short,
        '[UNI]': uniInfo.short,
        '$UNI$': uniInfo.short,

        '{UNIVERSITY_SLUG}': uniSlug,
        '$YEAR$': currentYear,
        '{YEAR}': currentYear,
        '[YEAR]': currentYear
    };

    // Load persisted keys from storage
    try {
        const savedKeys = localStorage.getItem(storagePrefix + 'keys');
        if (savedKeys) {
            const parsed = JSON.parse(savedKeys);
            Object.entries(parsed).forEach(([k, v]) => {
                if (typeof v === 'string' && v.trim() !== '') {
                    activeKeys[k] = v.trim();
                }
            });
        }
    } catch (e) {}

    // 2. Early Background Preload Promises (Started at microsecond 0)
    const earlyBannerPromise = fetch(`${BASE_URL}/admin/api/render_component.php?component=banner&uni=${encodeURIComponent(uniSlug)}`)
        .then(res => res.text()).catch(() => null);

    const earlyKeysPromise = fetch(`${BASE_URL}/api/get_global_keys.php?uni=${encodeURIComponent(uniSlug)}`)
        .then(res => res.json()).catch(() => null);

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
        } catch (e) {}

        if (item.element && item.element.parentNode) {
            const wrapper = document.createElement('div');
            wrapper.className = 'sode-rendered-component sode-' + item.name + ' sode-hydrated';
            wrapper.innerHTML = html;
            item.element.parentNode.replaceChild(wrapper, item.element);
            runScripts(wrapper);
            return;
        }

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

    // 6. Fast DOM Global Keys Replacement ({UNIVERSITY_NAME}, [UNIVERSITY_NAME], {MODE}, [MODE], $YEAR$)
    function replaceDOMKeys(keys) {
        if (!keys || !Object.keys(keys).length || !document.body) return;
        Object.assign(activeKeys, keys);

        const replacements = {};
        const safeBareKeywords = [
            'UNIVERSITY_SHORT_NAME_LOWER',
            'UNIVERSITY_SHORT_NAME',
            'UNIVERSITY_SLUG',
            'UNI_SHORT_NAME_LOWER',
            'UNI_SHORT_NAME',
            'UNIVERSITY_NAME',
            'UNI_SLUG',
            'MODE'
        ];

        Object.entries(activeKeys).forEach(([code, val]) => {
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

        walk(document.body);
    }

    // 7. Instant 0ms Pre-Hydration from LocalStorage
    function hydrateFromLocalCache(matchedItems) {
        if (!matchedItems || !matchedItems.length) return false;
        let hydratedCount = 0;

        matchedItems.forEach(item => {
            try {
                const cachedHtml = localStorage.getItem(storagePrefix + 'comp_' + item.raw);
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

    // 8. High-Performance Parallel Promise.all Execution Engine
    let isExecuting = false;
    async function executeEngine() {
        if (isExecuting) return;
        isExecuting = true;

        const startTime = performance.now();
        const matchedItems = collectShortcodes();

        // Step A: Instant 0ms Local Render
        hydrateFromLocalCache(matchedItems);
        replaceDOMKeys(activeKeys);

        // Step B: Prepare Batch Payload for any unhydrated shortcodes
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

        // Step C: Execute Parallel Network Requests (Reuse early background preloads!)
        const keysPromise = earlyKeysPromise;

        const batchPromise = Object.keys(batchItems).length > 0
            ? fetch(`${BASE_URL}/admin/api/render_component.php?component=batch`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items: batchItems, uni: uniSlug })
            }).then(res => res.json()).catch(() => null)
            : Promise.resolve(null);

        const modalsPromise = !document.getElementById('counselingFormPopupOverlay')
            ? fetch(`${BASE_URL}/admin/api/render_component.php?component=popup_modals&uni=${encodeURIComponent(uniSlug)}`)
                .then(res => res.text()).catch(() => null)
            : Promise.resolve(null);

        try {
            const [keysData, batchData, modalsHtml, earlyBannerHtml] = await Promise.all([
                keysPromise,
                batchPromise,
                modalsPromise,
                earlyBannerPromise
            ]);

            // 1. Process Global Keys (Never overwrite known university defaults with empty strings)
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

            // 2. Render Batch Components (Banner, Courses, News, etc.)
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
            } else if (earlyBannerHtml) {
                // Direct fallback for hero banner if batch was delayed
                matchedItems.forEach(item => {
                    if (item.name === 'edu_banner' || item.name === 'banner') {
                        applyRenderedHTML(item, earlyBannerHtml);
                    }
                });
            }

            // 3. Inject Footer Modals
            if (modalsHtml && modalsHtml.indexOf('sode-modal-overlay') !== -1 && !document.getElementById('counselingFormPopupOverlay')) {
                const modalWrap = document.createElement('div');
                modalWrap.id = 'sode-footer-modals-wrap';
                modalWrap.innerHTML = modalsHtml;
                document.body.appendChild(modalWrap);
                runScripts(modalWrap);
            }

            // 4. Final Key Sweep on Whole Page
            replaceDOMKeys(activeKeys);

            const duration = Math.round(performance.now() - startTime);
            console.log(`🚀 [SODE ENGINE COMPLETE] Hydrated in ${duration}ms`);
        } catch (err) {
            console.error('❌ [SODE ENGINE ERROR]:', err);
        } finally {
            replaceDOMKeys(activeKeys);
        }
    }

    // 9. Zero Scroll-Jump Armor: Prevent jumping to top on halfway scroll or empty "#" clicks
    document.addEventListener('click', function (e) {
        const a = e.target.closest('a');
        if (a) {
            const href = (a.getAttribute('href') || '').trim();
            if (href === '#' || href === '' || href === 'javascript:void(0);' || href === 'javascript:;') {
                e.preventDefault();
            }
        }

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
    }, { capture: true, passive: false });

    // Clean up any empty "#" pushed to URL without jumping scroll
    window.addEventListener('hashchange', function () {
        if (window.location.hash === '#' || window.location.hash === '') {
            try {
                history.replaceState(null, document.title, window.location.pathname + window.location.search);
            } catch (err) {}
        }
    });

    window.openApplyModal = function () {
        const overlay = document.getElementById('counselingFormPopupOverlay');
        if (overlay) overlay.classList.add('active');
    };

    // 10. Instant Observer & Multi-Stage Execution
    // Step 1: Scan immediately if body already exists
    if (document.body) {
        executeEngine();
    }

    // Step 2: MutationObserver catches shortcodes the moment they are inserted by Elementor
    const observer = new MutationObserver(function () {
        if (!isExecuting && document.body) {
            const items = collectShortcodes();
            if (items.length > 0) {
                executeEngine();
            }
        }
    });

    if (document.documentElement) {
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    // Step 3: Event Listeners as rock-solid backstops
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            isExecuting = false;
            executeEngine();
        });
    }
    window.addEventListener('load', () => {
        isExecuting = false;
        executeEngine();
        observer.disconnect();
    });
})();
