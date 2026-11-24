/**
 * ====================================================================
 * SODE Central Universal Client Engine (Axios Fast Async Loader)
 * File: sode-axios-client.js
 * 
 * Drop-in JavaScript engine for subdomains.
 * Bypasses PHP server roundtrips, replaces keys in DOM, caches in SessionStorage.
 * ====================================================================
 */
(function () {
    'use strict';

    const BASE_URL = 'https://admin.distanceeducationschool.com';

    // 1. Auto-detect University Slug from Subdomain
    function getUniSlug() {
        const host = window.location.hostname.toLowerCase();
        const parts = host.split('.');
        if (parts.length >= 3 && !['www', 'mail', 'admin', 'cpanel', 'webmail'].includes(parts[0])) {
            return parts[0];
        }
        return 'dsu'; // Fallback university
    }

    const uniSlug = getUniSlug();
    const cacheKey = 'sode_keys_' + uniSlug;
    console.log('🚀 [SODE ENGINE] Initialized for subdomain:', uniSlug);

    // 2. DOM Key Replacement Engine
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
            if (node.nodeType === 3) { // Text Node
                let t = node.textContent;
                let changed = false;

                patterns.forEach(p => {
                    if (t.indexOf(p) !== -1) {
                        t = t.split(p).join(replacements[p]);
                        changed = true;
                    }
                });

                if (changed) {
                    // Agar replacement me HTML tag hai (jaise #LEAD_FORM#)
                    if (t.indexOf('<') !== -1 && t.indexOf('>') !== -1) {
                        const span = document.createElement('span');
                        span.innerHTML = t;
                        while (span.firstChild) {
                            node.parentNode.insertBefore(span.firstChild, node);
                        }
                        node.parentNode.removeChild(node);
                    } else {
                        node.textContent = t;
                    }
                }
            } else if (node.nodeType === 1) { // Element Node
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

        // Observer for Elementor dynamic content
        if (window.MutationObserver && !window._sodeObserverAttached) {
            window._sodeObserverAttached = true;
            const observer = new MutationObserver(mutations => {
                mutations.forEach(m => {
                    m.addedNodes.forEach(walk);
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    // 3. Fast Axios API Fetch
    async function loadGlobalKeys() {
        // Step A: Check SessionStorage Cache (0ms Instant Load)
        try {
            const cached = sessionStorage.getItem(cacheKey);
            if (cached) {
                const parsed = JSON.parse(cached);
                console.log('⚡ [SODE CACHE HIT] Keys loaded from SessionStorage in 0ms');
                replaceDOMKeys(parsed);
                return;
            }
        } catch (e) {}

        // Step B: Remote Call via Axios
        const apiUrl = `${BASE_URL}/api/get_global_keys.php`;
        console.log('🔄 [SODE AXIOS] Fetching fresh keys from Central API for:', uniSlug);

        // Ensure Axios is loaded; fallback to fetch if not available
        if (typeof axios !== 'undefined') {
            try {
                const res = await axios.get(apiUrl, {
                    params: { uni: uniSlug },
                    timeout: 5000
                });

                if (res.data && res.data.keys) {
                    console.log('✅ [SODE SUCCESS] Global Keys Loaded Successfully:');
                    console.log(res.data.keys);

                    try {
                        sessionStorage.setItem(cacheKey, JSON.stringify(res.data.keys));
                    } catch (e) {}

                    replaceDOMKeys(res.data.keys);
                }
            } catch (err) {
                console.error('❌ [SODE AXIOS ERROR] Failed to fetch keys:', err.message);
            }
        } else {
            // Native Fetch Fallback
            fetch(`${apiUrl}?uni=${encodeURIComponent(uniSlug)}`)
                .then(r => r.json())
                .then(data => {
                    if (data && data.keys) {
                        try { sessionStorage.setItem(cacheKey, JSON.stringify(data.keys)); } catch (e) {}
                        replaceDOMKeys(data.keys);
                    }
                })
                .catch(err => console.error('❌ [SODE FETCH ERROR]:', err));
        }
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadGlobalKeys);
    } else {
        loadGlobalKeys();
    }
})();
