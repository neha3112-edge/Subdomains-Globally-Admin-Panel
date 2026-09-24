<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title    = 'API Health & Endpoints Monitor';
$page_subtitle = 'Live status, latency analytics, and auto-discovery of all 3rd-party integrations & internal REST endpoints';
$active_page_key = 'api_health';

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
/* ── Modern API Health List View Styles ────────────────────────────── */
.health-container {
    max-width: 1400px;
    margin: 0 auto;
}

/* 5-Metric Summary Cards */
.health-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
@media (max-width: 1100px) {
    .health-summary-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .health-summary-grid { grid-template-columns: 1fr 1fr; }
}

.health-stat-card {
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.health-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}
.health-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stat-icon-total { background: rgba(99,102,241,0.12); color: #6366f1; }
.stat-icon-up    { background: rgba(16,185,129,0.12); color: #10b981; }
.stat-icon-warn  { background: rgba(245,158,11,0.12);  color: #f59e0b; }
.stat-icon-down  { background: rgba(239,68,68,0.12);   color: #ef4444; }
.stat-icon-speed { background: rgba(56,189,248,0.12);  color: #38bdf8; }

.health-stat-info { display: flex; flex-direction: column; min-width: 0; }
.health-stat-val  { font-size: 26px; font-weight: 800; line-height: 1.1; color: var(--text-main); }
.health-stat-lbl  { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

/* Control & Filter Toolbar */
.health-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px 18px;
    margin-bottom: 20px;
}
.toolbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.toolbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.search-box {
    position: relative;
    min-width: 280px;
}
.search-box input {
    width: 100%;
    padding: 9px 12px 9px 36px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: var(--bg-sidebar);
    color: var(--text-main);
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.search-box input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    pointer-events: none;
}

/* Category Filter Tabs */
.filter-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 18px;
    overflow-x: auto;
    padding-bottom: 4px;
}
.filter-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 600;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}
.filter-tab-btn:hover {
    color: var(--text-main);
    border-color: var(--text-dim);
}
.filter-tab-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}
.filter-tab-count {
    font-size: 11px;
    padding: 1px 7px;
    border-radius: 10px;
    background: rgba(255,255,255,0.2);
}
.filter-tab-btn:not(.active) .filter-tab-count {
    background: var(--border-color);
    color: var(--text-dim);
}

/* ── Modern Endpoint List Table ─────────────────────────────────────── */
.api-list-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
}

.api-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 13px;
}
.api-table th {
    background: var(--bg-sidebar);
    padding: 12px 18px;
    font-weight: 700;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-dim);
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.api-table td {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.api-row {
    transition: background-color 0.15s;
}
.api-row:hover {
    background: rgba(255,255,255,0.02);
}
.api-row.expanded {
    background: rgba(99,102,241,0.03);
}

/* Method Badges */
.method-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.05em;
    min-width: 44px;
    text-align: center;
    flex-shrink: 0;
}
.method-get  { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.method-post { background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3); }
.method-head { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }

/* Status Badges */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}
.status-pill-up {
    background: rgba(16,185,129,0.12);
    color: #10b981;
    border: 1px solid rgba(16,185,129,0.25);
}
.status-pill-warn {
    background: rgba(245,158,11,0.12);
    color: #f59e0b;
    border: 1px solid rgba(245,158,11,0.25);
}
.status-pill-down {
    background: rgba(239,68,68,0.12);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,0.25);
}
.status-pill-unconf {
    background: rgba(148,163,184,0.12);
    color: #94a3b8;
    border: 1px solid rgba(148,163,184,0.25);
}
.status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}
.pulse-dot {
    box-shadow: 0 0 0 0 rgba(16,185,129,0.7);
    animation: pulseGlow 2s infinite;
}
@keyframes pulseGlow {
    0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); }
    70% { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
    100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}

/* Category Pill */
.category-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 600;
    background: var(--bg-sidebar);
    color: var(--text-muted);
    border: 1px solid var(--border-color);
    white-space: nowrap;
}

/* Latency Pill */
.latency-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 700;
    font-size: 12.5px;
    white-space: nowrap;
}
.latency-fast { color: #10b981; }
.latency-med  { color: #f59e0b; }
.latency-slow { color: #ef4444; }

/* Endpoint Info cell */
.endpoint-cell {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.endpoint-title-wrap {
    min-width: 0;
}
.endpoint-title {
    font-weight: 700;
    color: var(--text-main);
    font-size: 13.5px;
    margin-bottom: 3px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.endpoint-desc {
    font-size: 11.5px;
    color: var(--text-dim);
    margin-bottom: 4px;
}
.endpoint-route {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 11px;
    color: var(--text-muted);
    background: rgba(0,0,0,0.15);
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
    max-width: 420px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Action Buttons */
.action-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;
}
.btn-icon-soft {
    width: 30px;
    height: 30px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: var(--bg-sidebar);
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s;
    font-size: 13px;
    text-decoration: none;
}
.btn-icon-soft:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* Expandable Drawer Row */
.drawer-row td {
    padding: 0 !important;
    border-bottom: 1px solid var(--border-color);
}
.drawer-content {
    padding: 16px 20px;
    background: rgba(0,0,0,0.12);
    border-top: 1px dashed var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.drawer-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}
.drawer-meta-box {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 10px 14px;
}
.drawer-meta-lbl {
    font-size: 10.5px;
    text-transform: uppercase;
    color: var(--text-dim);
    font-weight: 700;
    margin-bottom: 4px;
}
.drawer-meta-val {
    font-size: 12px;
    color: var(--text-main);
    word-break: break-all;
    font-family: monospace;
}

/* Loading Animation */
.spinner-btn {
    animation: spin 1s linear infinite;
}
@keyframes spin { 100% { transform: rotate(360deg); } }

/* Toast */
.copy-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #10b981;
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 9999;
    opacity: 0;
    transform: translateY(12px);
    transition: all 0.25s;
    pointer-events: none;
}
.copy-toast.show {
    opacity: 1;
    transform: translateY(0);
}
</style>

<div class="health-container">

    <!-- Top Summary Metrics Grid -->
    <div class="health-summary-grid">
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-total"><i class="fas fa-network-wired fa-lg"></i></div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-total">--</span>
                <span class="health-stat-lbl">Total Endpoints</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-up"><i class="fas fa-check-circle fa-lg"></i></div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-up" style="color:#10b981;">--</span>
                <span class="health-stat-lbl">Operational (200 OK)</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-warn"><i class="fas fa-exclamation-triangle fa-lg"></i></div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-warn" style="color:#f59e0b;">--</span>
                <span class="health-stat-lbl">Warnings / Auth</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-down"><i class="fas fa-times-circle fa-lg"></i></div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-down" style="color:#ef4444;">--</span>
                <span class="health-stat-lbl">Down / Errors</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-speed"><i class="fas fa-bolt fa-lg"></i></div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-speed" style="color:#38bdf8;">--</span>
                <span class="health-stat-lbl">Avg Response Speed</span>
            </div>
        </div>
    </div>

    <!-- Control Toolbar -->
    <div class="health-toolbar">
        <div class="toolbar-left">
            <button class="btn btn-primary" id="btn-check-now" onclick="runHealthCheck()">
                <i class="fas fa-sync-alt" id="refresh-icon"></i> Run Health Check
            </button>
            <div class="form-group mb-0" style="display:inline-flex; align-items:center; gap:8px;">
                <label style="font-size:12px; color:var(--text-dim); margin-bottom:0; font-weight:600;">Auto-check:</label>
                <select id="auto-refresh-select" class="form-control" style="width:110px; padding:6px 10px; font-size:12.5px; height:auto;" onchange="updateAutoRefresh(this.value)">
                    <option value="0">Off</option>
                    <option value="15">Every 15s</option>
                    <option value="30" selected>Every 30s</option>
                    <option value="60">Every 60s</option>
                </select>
            </div>
            <span style="font-size:12px; color:var(--text-dim);" id="last-checked-label">Last checked: Just now</span>
        </div>
        <div class="toolbar-right">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="api-search-input" placeholder="Filter endpoints, routes, methods..." oninput="filterApiList()">
            </div>
            <a href="<?= BASE_URL ?>/modules/settings/integrations.php" class="btn btn-outline-secondary" style="font-size:12.5px; padding:7px 14px;">
                <i class="fas fa-sliders-h"></i> Configure Keys
            </a>
        </div>
    </div>

    <!-- Category Filter Tabs -->
    <div class="filter-tabs">
        <button class="filter-tab-btn active" data-filter="all" onclick="setCategoryFilter('all', this)">
            <i class="fas fa-th-list"></i> All Endpoints <span class="filter-tab-count" id="count-all">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="external" onclick="setCategoryFilter('external', this)">
            <i class="fas fa-plug"></i> 3rd-Party Integrations <span class="filter-tab-count" id="count-external">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="internal_admin" onclick="setCategoryFilter('internal_admin', this)">
            <i class="fas fa-bolt"></i> Admin REST Feeds (/admin/api) <span class="filter-tab-count" id="count-admin">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="internal_public" onclick="setCategoryFilter('internal_public', this)">
            <i class="fas fa-globe"></i> Public Subdomain Feeds (/api) <span class="filter-tab-count" id="count-public">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="issues" onclick="setCategoryFilter('issues', this)">
            <i class="fas fa-exclamation-circle"></i> Issues Only <span class="filter-tab-count" id="count-issues">0</span>
        </button>
    </div>

    <!-- Modern API List Table Card -->
    <div class="api-list-card">
        <div class="table-responsive">
            <table class="api-table" id="api-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Status</th>
                        <th>Endpoint & Route</th>
                        <th style="width: 180px;">Scope / Category</th>
                        <th style="width: 120px;">HTTP Code</th>
                        <th style="width: 130px;">Latency</th>
                        <th style="width: 180px;">Message / Diagnostic</th>
                        <th style="width: 100px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="api-list-body">
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-dim);">
                            <i class="fas fa-circle-notch fa-spin fa-2x" style="color:var(--primary); margin-bottom: 12px;"></i>
                            <div>Discovering and benchmarking all endpoints in parallel...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Copy Feedback Toast -->
<div class="copy-toast" id="copyToast">
    <i class="fas fa-check-circle"></i> URL copied to clipboard!
</div>

<script>
let allApiData = [];
let currentCategory = 'all';
let autoRefreshTimer = null;

function setCategoryFilter(category, btnElement) {
    currentCategory = category;
    document.querySelectorAll('.filter-tab-btn').forEach(b => b.classList.remove('active'));
    if (btnElement) btnElement.classList.add('active');
    renderApiRows();
}

function updateAutoRefresh(seconds) {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
    const sec = parseInt(seconds);
    if (sec > 0) {
        autoRefreshTimer = setInterval(runHealthCheck, sec * 1000);
    }
}

async function runHealthCheck() {
    const refreshIcon = document.getElementById('refresh-icon');
    const btnCheck = document.getElementById('btn-check-now');
    
    if (refreshIcon) refreshIcon.classList.add('spinner-btn');
    if (btnCheck) btnCheck.disabled = true;

    try {
        const res = await fetch('<?= BASE_URL ?>/api/check_api_health.php?t=' + Date.now());
        const data = await res.json();
        
        allApiData = data.apis || [];
        updateSummary(data.summary, data.checked_at);
        renderApiRows();
    } catch (err) {
        console.error("Health check error:", err);
    } finally {
        if (refreshIcon) refreshIcon.classList.remove('spinner-btn');
        if (btnCheck) btnCheck.disabled = false;
    }
}

function updateSummary(summary, checkedAt) {
    if (!summary) return;
    document.getElementById('stat-total').textContent = summary.total || 0;
    document.getElementById('stat-up').textContent    = summary.up || 0;
    document.getElementById('stat-warn').textContent  = summary.warn || 0;
    document.getElementById('stat-down').textContent  = summary.down || 0;
    document.getElementById('stat-speed').textContent = (summary.avg_ms || 0) + ' ms';

    // Update Tab Counters
    document.getElementById('count-all').textContent      = summary.total || 0;
    document.getElementById('count-external').textContent = summary.external_count || 0;
    
    const adminCount  = allApiData.filter(a => a.type === 'internal_admin').length;
    const publicCount = allApiData.filter(a => a.type === 'internal_public').length;
    const issuesCount = allApiData.filter(a => a.status === 'warn' || a.status === 'down').length;

    document.getElementById('count-admin').textContent  = adminCount;
    document.getElementById('count-public').textContent = publicCount;
    document.getElementById('count-issues').textContent = issuesCount;

    if (checkedAt) {
        const d = new Date(checkedAt.replace(' ', 'T'));
        document.getElementById('last-checked-label').textContent = 'Last checked: ' + d.toLocaleTimeString();
    }
}

function filterApiList() {
    renderApiRows();
}

function renderApiRows() {
    const tbody = document.getElementById('api-list-body');
    const searchVal = (document.getElementById('api-search-input').value || '').toLowerCase().trim();

    if (!allApiData || allApiData.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:var(--text-dim);">No endpoints found.</td></tr>`;
        return;
    }

    const filtered = allApiData.filter(api => {
        // Category filter
        if (currentCategory === 'external' && api.type !== 'external') return false;
        if (currentCategory === 'internal_admin' && api.type !== 'internal_admin') return false;
        if (currentCategory === 'internal_public' && api.type !== 'internal_public') return false;
        if (currentCategory === 'issues' && api.status !== 'warn' && api.status !== 'down') return false;

        // Search filter
        if (searchVal) {
            const haystack = `${api.name} ${api.description || ''} ${api.url || ''} ${api.category || ''} ${api.method || ''} ${api.file_name || ''} ${api.status || ''}`.toLowerCase();
            if (!haystack.includes(searchVal)) return false;
        }

        return true;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:36px; color:var(--text-dim);"><i class="fas fa-search" style="margin-bottom:8px; opacity:0.5;"></i><div>No matching endpoints found for current filter.</div></td></tr>`;
        return;
    }

    let html = '';
    filtered.forEach((api, idx) => {
        const rowId = 'api-row-' + idx;
        const drawerId = 'drawer-' + idx;

        // Status Badge
        let statusBadge = '';
        if (api.status === 'up') {
            statusBadge = `<span class="status-pill status-pill-up"><span class="status-dot pulse-dot"></span> Operational</span>`;
        } else if (api.status === 'warn') {
            statusBadge = `<span class="status-pill status-pill-warn"><span class="status-dot"></span> Warning</span>`;
        } else if (api.status === 'down') {
            statusBadge = `<span class="status-pill status-pill-down"><span class="status-dot"></span> Down</span>`;
        } else {
            statusBadge = `<span class="status-pill status-pill-unconf"><span class="status-dot"></span> Unconfigured</span>`;
        }

        // Method Badge
        const m = (api.method || 'GET').toUpperCase();
        let methodClass = 'method-get';
        if (m === 'POST') methodClass = 'method-post';
        else if (m === 'HEAD') methodClass = 'method-head';

        // Latency
        let latencyHtml = `<span style="color:var(--text-dim);">--</span>`;
        if (api.response_ms !== null && api.response_ms !== undefined) {
            let latClass = 'latency-fast';
            let speedText = 'Fast';
            if (api.response_ms > 600) { latClass = 'latency-slow'; speedText = 'Slow'; }
            else if (api.response_ms > 250) { latClass = 'latency-med'; speedText = 'Medium'; }
            latencyHtml = `<span class="latency-pill ${latClass}"><i class="fas fa-bolt" style="font-size:10px;"></i> ${api.response_ms} ms</span>`;
        }

        // HTTP Code
        let codeHtml = `<span style="color:var(--text-dim); font-weight:600;">--</span>`;
        if (api.http_code !== null && api.http_code !== undefined) {
            let codeColor = '#ef4444';
            if (api.http_code >= 200 && api.http_code < 300) codeColor = '#10b981';
            else if (api.http_code >= 400 && api.http_code < 500) codeColor = '#f59e0b';
            codeHtml = `<span style="font-weight:700; color:${codeColor}; font-family:monospace;">${api.http_code}</span>`;
        }

        // Category Tag
        let catPill = `<span class="category-pill"><i class="fas fa-layer-group" style="font-size:10px;"></i> ${api.category || 'Endpoint'}</span>`;
        if (api.type === 'internal_public') {
            catPill = `<span class="category-pill" style="border-color:rgba(59,130,246,0.3); color:#60a5fa;"><i class="fas fa-globe" style="font-size:10px;"></i> Public Feed</span>`;
        } else if (api.type === 'internal_admin') {
            catPill = `<span class="category-pill" style="border-color:rgba(99,102,241,0.3); color:#818cf8;"><i class="fas fa-lock" style="font-size:10px;"></i> Admin REST</span>`;
        } else if (api.type === 'external') {
            catPill = `<span class="category-pill" style="border-color:rgba(245,158,11,0.3); color:#fbbf24;"><i class="fas fa-cloud" style="font-size:10px;"></i> 3rd Party</span>`;
        }

        html += `
            <tr class="api-row" id="${rowId}">
                <td>${statusBadge}</td>
                <td>
                    <div class="endpoint-cell">
                        <span class="method-badge ${methodClass}">${m}</span>
                        <div class="endpoint-title-wrap">
                            <div class="endpoint-title">
                                ${api.name}
                                ${api.is_json ? '<span style="font-size:10.5px; font-weight:700; padding:1px 5px; border-radius:4px; background:rgba(16,185,129,0.15); color:#10b981;">JSON</span>' : ''}
                            </div>
                            <div class="endpoint-desc">${api.description || ''}</div>
                            <div class="endpoint-route" title="${api.url}">${api.url}</div>
                        </div>
                    </div>
                </td>
                <td>${catPill}</td>
                <td>${codeHtml}</td>
                <td>${latencyHtml}</td>
                <td>
                    <div style="font-size:12px; color:${api.status === 'down' ? '#ef4444' : (api.status === 'warn' ? '#f59e0b' : 'var(--text-muted)')}; font-weight:500;">
                        ${api.message || 'Operational'}
                    </div>
                </td>
                <td>
                    <div class="action-btn-group">
                        <button class="btn-icon-soft" title="Copy Endpoint URL" onclick="copyUrl('${encodeURIComponent(api.url)}')">
                            <i class="far fa-copy"></i>
                        </button>
                        <a href="${api.url}" target="_blank" rel="noopener noreferrer" class="btn-icon-soft" title="Test / Open in new tab">
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <button class="btn-icon-soft" title="View details" onclick="toggleDrawer('${drawerId}', '${rowId}')">
                            <i class="fas fa-chevron-down" id="arrow-${drawerId}"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <tr class="drawer-row" id="${drawerId}" style="display:none;">
                <td colspan="7">
                    <div class="drawer-content">
                        <div class="drawer-meta-grid">
                            <div class="drawer-meta-box">
                                <div class="drawer-meta-lbl">Full Request URL</div>
                                <div class="drawer-meta-val">${api.url}</div>
                            </div>
                            <div class="drawer-meta-box">
                                <div class="drawer-meta-lbl">File Location</div>
                                <div class="drawer-meta-val">${api.file_name || 'N/A'}</div>
                            </div>
                            <div class="drawer-meta-box">
                                <div class="drawer-meta-lbl">HTTP Method & Headers</div>
                                <div class="drawer-meta-val">${m} | ${api.headers && api.headers.length ? api.headers.join(', ') : 'Standard JSON / HTTP'}</div>
                            </div>
                            <div class="drawer-meta-box">
                                <div class="drawer-meta-lbl">Status Diagnostics</div>
                                <div class="drawer-meta-val" style="color:${api.status === 'down' ? '#ef4444' : (api.status === 'warn' ? '#f59e0b' : '#10b981')};">
                                    ${api.message || 'All systems normal'}
                                </div>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
}

function toggleDrawer(drawerId, rowId) {
    const drawer = document.getElementById(drawerId);
    const row    = document.getElementById(rowId);
    const arrow  = document.getElementById('arrow-' + drawerId);
    
    if (!drawer) return;
    if (drawer.style.display === 'none' || drawer.style.display === '') {
        drawer.style.display = 'table-row';
        if (row) row.classList.add('expanded');
        if (arrow) arrow.style.transform = 'rotate(180deg)';
    } else {
        drawer.style.display = 'none';
        if (row) row.classList.remove('expanded');
        if (arrow) arrow.style.transform = 'rotate(0deg)';
    }
}

function copyUrl(encodedUrl) {
    const url = decodeURIComponent(encodedUrl);
    navigator.clipboard.writeText(url).then(() => {
        const toast = document.getElementById('copyToast');
        if (toast) {
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2200);
        }
    }).catch(err => {
        prompt("Copy URL:", url);
    });
}

// Auto-run on page load
document.addEventListener('DOMContentLoaded', () => {
    runHealthCheck();
    updateAutoRefresh(30);
});
</script>

<?php require_once ADMIN_PATH . '/includes/footer.php'; ?>
