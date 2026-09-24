<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title    = 'API Health & Endpoints Monitor';
$page_subtitle = 'Live monitoring, latency analytics, and auto-discovery of all 3rd-party integrations and internal REST data feeds';
$active_page_key = 'api_health';

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
/* ── Modern API Health Monitor Styles ────────────────────────────── */
.health-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-bottom: 22px;
}
.health-stat-card {
    border-radius: var(--radius-lg);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    transition: transform 0.18s, box-shadow 0.18s;
}
.health-stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.15); }
.health-stat-icon {
    width: 44px; height: 44px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.stat-icon-up    { background: rgba(16,185,129,0.15); color: #10b981; }
.stat-icon-warn  { background: rgba(245,158,11,0.15);  color: #f59e0b; }
.stat-icon-down  { background: rgba(239,68,68,0.15);   color: #ef4444; }
.stat-icon-total { background: rgba(99,102,241,0.15);  color: var(--primary); }
.stat-icon-speed { background: rgba(56,189,248,0.15);  color: #38bdf8; }
.health-stat-info { display: flex; flex-direction: column; min-width: 0; }
.health-stat-val  { font-size: 26px; font-weight: 800; line-height: 1.1; color: var(--text-main); }
.health-stat-lbl  { font-size: 11px; color: var(--text-dim); margin-top: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Control & Filter Bar */
.health-control-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px 18px;
    margin-bottom: 20px;
}
.control-bar-left {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.control-bar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.search-input-wrap {
    position: relative;
    min-width: 260px;
}
.search-input-wrap input {
    width: 100%;
    padding: 8px 12px 8px 34px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: var(--bg-sidebar);
    color: var(--text-main);
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s;
}
.search-input-wrap input:focus {
    border-color: var(--primary);
}
.search-input-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    pointer-events: none;
}

/* Category Filter Tabs */
.api-category-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.api-cat-tab {
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
}
.api-cat-tab:hover {
    color: var(--text-main);
    border-color: rgba(99,102,241,0.4);
}
.api-cat-tab.active {
    background: rgba(99,102,241,0.12);
    color: #818cf8;
    border-color: rgba(99,102,241,0.35);
}
.api-cat-count {
    padding: 1px 6px;
    border-radius: 100px;
    font-size: 10.5px;
    background: var(--bg-sidebar);
    color: var(--text-dim);
    font-weight: 700;
}
.api-cat-tab.active .api-cat-count {
    background: rgba(99,102,241,0.25);
    color: #a5b4fc;
}

/* API Cards grid */
.api-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.api-monitor-card {
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    overflow: hidden;
    transition: transform 0.18s, box-shadow 0.18s, border-color 0.18s;
    position: relative;
    display: flex;
    flex-direction: column;
}
.api-monitor-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.18); }

/* Status top-border accent */
.api-monitor-card.status-up   { border-top: 3px solid #10b981; }
.api-monitor-card.status-warn { border-top: 3px solid #f59e0b; }
.api-monitor-card.status-down { border-top: 3px solid #ef4444; }
.api-monitor-card.status-unconfigured { border-top: 3px solid #6b7280; }
.api-monitor-card.status-checking { border-top: 3px solid #6366f1; }

.api-card-header {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 15px 18px 12px;
    border-bottom: 1px solid var(--border-color);
}
.api-card-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 16px;
}
.icon-crm      { background: rgba(99,102,241,0.15);  color: #6366f1; }
.icon-email    { background: rgba(16,185,129,0.15);  color: #10b981; }
.icon-whatsapp { background: rgba(37,211,102,0.15);  color: #25d366; }
.icon-admin    { background: rgba(14,165,233,0.15);  color: #0ea5e9; }
.icon-public   { background: rgba(168,85,247,0.15);  color: #a855f7; }

.api-card-meta { flex: 1; min-width: 0; }
.api-card-title-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 2px; }
.api-card-name { font-size: 14.5px; font-weight: 700; color: var(--text-main); line-height: 1.2; }
.api-card-desc { font-size: 11.5px; color: var(--text-dim); line-height: 1.35; margin-top: 2px; }

.api-method-badge {
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.method-get  { background: rgba(56,189,248,0.15); color: #38bdf8; border: 1px solid rgba(56,189,248,0.3); }
.method-post { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
.method-head { background: rgba(168,85,247,0.15); color: #c084fc; border: 1px solid rgba(168,85,247,0.3); }

.api-type-tag {
    font-size: 10px;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--bg-sidebar);
    color: var(--text-dim);
    font-weight: 600;
    border: 1px solid var(--border-color);
}

.api-status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 100px;
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    flex-shrink: 0;
}
.badge-up            { background: rgba(16,185,129,0.12); color: #10b981; }
.badge-warn          { background: rgba(245,158,11,0.12);  color: #f59e0b; }
.badge-down          { background: rgba(239,68,68,0.12);   color: #ef4444; }
.badge-unconfigured  { background: rgba(107,114,128,0.12); color: #9ca3af; }
.badge-checking      { background: rgba(99,102,241,0.10);  color: #6366f1; }

.status-dot { width: 7px; height: 7px; border-radius: 50%; }
.dot-up    { background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,0.25); animation: pulse-up 2s infinite; }
.dot-warn  { background: #f59e0b; }
.dot-down  { background: #ef4444; animation: pulse-down 1.5s infinite; }
.dot-unconfigured { background: #6b7280; }
.dot-checking     { background: #6366f1; animation: pulse-up 1s infinite; }

@keyframes pulse-up   { 0%,100%{ box-shadow: 0 0 0 2px rgba(16,185,129,0.2); } 50%{ box-shadow: 0 0 0 6px rgba(16,185,129,0.05); } }
@keyframes pulse-down { 0%,100%{ box-shadow: 0 0 0 2px rgba(239,68,68,0.2); }  50%{ box-shadow: 0 0 0 6px rgba(239,68,68,0.05); } }

.api-card-body { padding: 14px 18px 16px; flex: 1; display: flex; flex-direction: column; justify-content: space-between; }

.api-metric-row {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 8px; margin-bottom: 12px;
}
.api-metric {
    background: var(--bg-sidebar);
    border-radius: 8px; padding: 8px 10px;
    display: flex; flex-direction: column; gap: 2px;
}
.api-metric-label { font-size: 10px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
.api-metric-value { font-size: 13.5px; font-weight: 700; color: var(--text-main); }
.metric-val-up   { color: #10b981; }
.metric-val-warn { color: #f59e0b; }
.metric-val-down { color: #ef4444; }

.api-url-row {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-sidebar); border-radius: 6px;
    padding: 7px 10px; margin-bottom: 10px;
}
.api-url-text {
    font-size: 11px; color: var(--text-dim); font-family: 'Courier New', monospace;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;
}

.api-message-row {
    font-size: 11.5px; color: var(--text-dim);
    padding: 7px 10px; border-radius: 6px;
    background: var(--bg-sidebar);
    display: flex; align-items: center; gap: 7px;
    margin-bottom: 10px;
}
.api-message-row.msg-up   { color: #10b981; background: rgba(16,185,129,0.07); }
.api-message-row.msg-warn { color: #f59e0b; background: rgba(245,158,11,0.07); }
.api-message-row.msg-down { color: #ef4444; background: rgba(239,68,68,0.07); }

.api-card-actions {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding-top: 10px; border-top: 1px solid var(--border-color);
    font-size: 11.5px;
}

.btn-api-action {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 6px;
    background: var(--bg-sidebar); border: 1px solid var(--border-color);
    color: var(--text-muted); text-decoration: none; cursor: pointer;
    font-size: 11px; font-weight: 600;
    transition: all 0.15s;
}
.btn-api-action:hover {
    background: rgba(99,102,241,0.12);
    color: #818cf8;
    border-color: rgba(99,102,241,0.3);
}

/* Speed indicator */
.speed-pill {
    display: inline-block; padding: 1px 6px; border-radius: 100px;
    font-size: 10px; font-weight: 700;
}
.speed-fast   { background: rgba(16,185,129,0.12); color: #10b981; }
.speed-medium { background: rgba(245,158,11,0.12);  color: #f59e0b; }
.speed-slow   { background: rgba(239,68,68,0.12);   color: #ef4444; }

/* Action buttons */
.btn-refresh {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 700;
    background: var(--primary-gradient); color: #fff; border: none; cursor: pointer;
    transition: opacity 0.2s, transform 0.15s; white-space: nowrap;
}
.btn-refresh:hover { opacity: 0.9; transform: translateY(-1px); }
.btn-refresh:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.btn-autorefresh {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px; border-radius: 8px; font-size: 12.5px; font-weight: 600;
    background: var(--bg-sidebar); color: var(--text-dim);
    border: 1px solid var(--border-color); cursor: pointer;
    transition: all 0.2s;
}
.btn-autorefresh.active { background: rgba(16,185,129,0.12); color: #10b981; border-color: rgba(16,185,129,0.3); }

/* Skeleton */
.skeleton {
    background: linear-gradient(90deg, var(--border-color) 25%, var(--bg-sidebar) 37%, var(--border-color) 63%);
    background-size: 400px 100%;
    animation: skeleton-loading 1.4s ease infinite;
    border-radius: 4px;
    display: inline-block;
}
@keyframes skeleton-loading { 0%{ background-position: 100% 50%; } 100%{ background-position: 0% 50%; } }

.empty-search-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 48px 16px;
    color: var(--text-dim);
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px dashed var(--border-color);
}

/* Toast */
.api-toast {
    position: fixed; bottom: 24px; right: 24px;
    background: #10b981; color: #fff; padding: 9px 16px;
    border-radius: 8px; font-size: 12.5px; font-weight: 700;
    box-shadow: 0 10px 25px rgba(0,0,0,0.35); z-index: 99999;
    display: none; animation: fadeIn 0.2s ease;
}

@media (max-width: 1100px) {
    .health-summary-grid { grid-template-columns: repeat(3, 1fr); }
    .api-cards-grid { grid-template-columns: 1fr; }
}
@media (max-width: 650px) {
    .health-summary-grid { grid-template-columns: 1fr 1fr; }
    .health-control-bar { flex-direction: column; align-items: stretch; }
    .search-input-wrap { width: 100%; }
}
</style>

<div class="api-toast" id="apiToast">URL copied to clipboard!</div>

<!-- ── Action & Control Bar ───────────────────────────────────────────── -->
<div class="health-control-bar">
    <div class="control-bar-left">
        <button class="btn-refresh" id="btn-refresh" onclick="runHealthCheck()">
            <svg id="refresh-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            Check All APIs
        </button>
        <button class="btn-autorefresh" id="btn-autorefresh" onclick="toggleAutoRefresh()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Auto (30s)
        </button>
        <span class="last-checked-info" id="last-checked-info" style="font-size:12.5px; color:var(--text-dim);">Auto-discovering & checking endpoints…</span>
    </div>
    
    <div class="control-bar-right">
        <!-- Live Search Input -->
        <div class="search-input-wrap">
            <svg class="search-input-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" id="apiSearchInput" placeholder="Filter endpoints, routes, methods..." oninput="filterApiCards()">
        </div>

        <a href="<?php echo BASE_URL; ?>/modules/settings/api_integrations.php" class="btn-secondary" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-size:12.5px; font-weight:600; text-decoration:none; white-space:nowrap;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.07 4.93l-1.41 1.41M4.93 19.07l1.41-1.41M4.93 4.93l1.41 1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"></path></svg>
            Configure 3rd-Party Keys
        </a>
    </div>
</div>

<!-- ── Summary Stats ────────────────────────────────────────────────────── -->
<div class="health-summary-grid">
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-total">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"></path></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" id="stat-total">—</span>
            <span class="health-stat-lbl">Total Endpoints</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-up">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#10b981;" id="stat-up">—</span>
            <span class="health-stat-lbl">Operational (200 OK)</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-warn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#f59e0b;" id="stat-warn">—</span>
            <span class="health-stat-lbl">Warnings / Auth</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-down">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#ef4444;" id="stat-down">—</span>
            <span class="health-stat-lbl">Down / Errors</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-speed">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#38bdf8;" id="stat-speed">—</span>
            <span class="health-stat-lbl">Avg Response Time</span>
        </div>
    </div>
</div>

<!-- ── Category Filter Tabs ─────────────────────────────────────────── -->
<div class="api-category-tabs">
    <button type="button" class="api-cat-tab active" data-filter="all" onclick="setCategoryFilter('all')">
        <span>🌐 All Endpoints</span>
        <span class="api-cat-count" id="count-all">0</span>
    </button>
    <button type="button" class="api-cat-tab" data-filter="external" onclick="setCategoryFilter('external')">
        <span>🚀 3rd-Party Integrations</span>
        <span class="api-cat-count" id="count-external">0</span>
    </button>
    <button type="button" class="api-cat-tab" data-filter="internal_admin" onclick="setCategoryFilter('internal_admin')">
        <span>⚡ Admin REST Feeds (/admin/api)</span>
        <span class="api-cat-count" id="count-admin">0</span>
    </button>
    <button type="button" class="api-cat-tab" data-filter="internal_public" onclick="setCategoryFilter('internal_public')">
        <span>🔗 Public Subdomain Feeds (/api)</span>
        <span class="api-cat-count" id="count-public">0</span>
    </button>
    <button type="button" class="api-cat-tab" data-filter="issues" onclick="setCategoryFilter('issues')" style="margin-left:auto;">
        <span>⚠️ Issues Only</span>
        <span class="api-cat-count" id="count-issues" style="color:#ef4444;">0</span>
    </button>
</div>

<!-- ── API Cards Grid ────────────────────────────────────────────────── -->
<div class="api-cards-grid" id="api-cards-grid">
    <!-- Skeleton loader placeholders -->
    <div class="api-monitor-card status-checking loading" style="padding:40px 20px; text-align:center; grid-column:1/-1;">
        <div style="display:flex; justify-content:center; gap:8px; margin-bottom:12px;">
            <span class="skeleton" style="width:30px; height:30px; border-radius:50%;"></span>
            <span class="skeleton" style="width:140px; height:30px;"></span>
        </div>
        <div style="font-size:13px; color:var(--text-dim);">Scanning directories & performing high-speed parallel health checks…</div>
    </div>
</div>

<!-- ── Legend & Auto-Discovery Notice ─────────────────────────────────── -->
<div class="admin-card" style="padding:14px 18px; margin-top:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
            <span style="font-size:11px; font-weight:700; color:var(--text-dim); text-transform:uppercase; letter-spacing:0.05em;">Legend</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:12px;"><div class="status-dot dot-up"></div><strong style="color:#10b981;">Operational</strong> — Responding normally</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:12px;"><div class="status-dot dot-warn"></div><strong style="color:#f59e0b;">Warning</strong> — Reachable / Auth issue</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:12px;"><div class="status-dot dot-down"></div><strong style="color:#ef4444;">Down</strong> — Error or Unreachable</span>
            <span style="display:flex;align-items:center;gap:6px;font-size:12px;"><div class="status-dot dot-unconfigured"></div><strong style="color:#9ca3af;">Not Configured</strong></span>
        </div>

        <div style="font-size:11.5px; color:var(--text-dim); display:flex; align-items:center; gap:6px;">
            <span style="color:#38bdf8;">⚡ Auto-Discovery Active:</span> New endpoints added to <code>/api</code> or <code>/admin/api</code> appear here automatically.
        </div>
    </div>
</div>

<script>
// ── State & Config ──────────────────────────────────────────────────────────
const API_ENDPOINT = '<?php echo BASE_URL; ?>/api/check_api_health.php';
let autoRefreshTimer = null;
let isChecking = false;
let allApiData = [];
let currentCategory = 'all';

function showToast(msg) {
    const t = document.getElementById('apiToast');
    t.innerText = msg;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 2200);
}

function getIconForApi(api) {
    if (api.type === 'external') {
        if (api.id === 'crm') return { class: 'icon-crm', svg: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>` };
        if (api.id === 'brevo') return { class: 'icon-email', svg: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>` };
        if (api.id === 'gallabox') return { class: 'icon-whatsapp', svg: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>` };
    }
    if (api.type === 'internal_admin') {
        return { class: 'icon-admin', svg: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>` };
    }
    return { class: 'icon-public', svg: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>` };
}

// ── Main Health Check Runner ────────────────────────────────────────────────
async function runHealthCheck() {
    if (isChecking) return;
    isChecking = true;

    const btn = document.getElementById('btn-refresh');
    const icon = document.getElementById('refresh-icon');
    btn.disabled = true;
    icon.style.animation = 'spin 0.8s linear infinite';

    document.getElementById('last-checked-info').innerHTML = '<em>Checking all APIs in parallel…</em>';

    try {
        const res  = await fetch(API_ENDPOINT + '?t=' + Date.now());
        const data = await res.json();

        allApiData = data.apis || [];

        // Summary metrics
        document.getElementById('stat-total').textContent = data.summary.total;
        document.getElementById('stat-up').textContent    = data.summary.up;
        document.getElementById('stat-warn').textContent  = data.summary.warn;
        document.getElementById('stat-down').textContent  = data.summary.down + (data.summary.unconfigured > 0 ? ' (' + data.summary.unconfigured + ' not set)' : '');
        document.getElementById('stat-speed').textContent = (data.summary.avg_ms || 0) + ' ms';

        // Tab counts
        document.getElementById('count-all').textContent      = allApiData.length;
        document.getElementById('count-external').textContent = allApiData.filter(a => a.type === 'external').length;
        document.getElementById('count-admin').textContent    = allApiData.filter(a => a.type === 'internal_admin').length;
        document.getElementById('count-public').textContent   = allApiData.filter(a => a.type === 'internal_public').length;
        document.getElementById('count-issues').textContent   = allApiData.filter(a => a.status === 'warn' || a.status === 'down').length;

        // Render Cards
        renderFilteredCards();

        const t = new Date(data.checked_at.replace(' ','T'));
        const formatted = t.toLocaleTimeString('en-IN', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        document.getElementById('last-checked-info').innerHTML = `Last check: <strong>${formatted}</strong> (${allApiData.length} endpoints)`;

    } catch (err) {
        document.getElementById('last-checked-info').innerHTML = `<span style="color:#ef4444;">Check failed: ${err.message}</span>`;
    }

    btn.disabled = false;
    icon.style.animation = '';
    isChecking = false;
}

// ── Filter and Render ───────────────────────────────────────────────────────
function setCategoryFilter(cat) {
    currentCategory = cat;
    document.querySelectorAll('.api-cat-tab').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-filter') === cat);
    });
    renderFilteredCards();
}

function filterApiCards() {
    renderFilteredCards();
}

function renderFilteredCards() {
    const grid = document.getElementById('api-cards-grid');
    const query = (document.getElementById('apiSearchInput').value || '').toLowerCase().trim();

    let list = allApiData;

    // Filter by Category Tab
    if (currentCategory === 'external') {
        list = list.filter(a => a.type === 'external');
    } else if (currentCategory === 'internal_admin') {
        list = list.filter(a => a.type === 'internal_admin');
    } else if (currentCategory === 'internal_public') {
        list = list.filter(a => a.type === 'internal_public');
    } else if (currentCategory === 'issues') {
        list = list.filter(a => a.status === 'warn' || a.status === 'down' || a.status === 'unconfigured');
    }

    // Filter by Search Query
    if (query) {
        list = list.filter(a => 
            (a.name && a.name.toLowerCase().includes(query)) ||
            (a.description && a.description.toLowerCase().includes(query)) ||
            (a.url && a.url.toLowerCase().includes(query)) ||
            (a.method && a.method.toLowerCase().includes(query)) ||
            (a.category && a.category.toLowerCase().includes(query)) ||
            (a.file_name && a.file_name.toLowerCase().includes(query))
        );
    }

    if (list.length === 0) {
        grid.innerHTML = `
            <div class="empty-search-state">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="margin:0 auto 10px; display:block; opacity:0.6;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <div style="font-weight:700; font-size:14px; color:var(--text-main);">No endpoints matched your search</div>
                <div style="font-size:12px; margin-top:4px;">Try clearing search keywords or selecting another category tab.</div>
            </div>
        `;
        return;
    }

    grid.innerHTML = list.map(api => buildApiCardHtml(api)).join('');
}

function buildApiCardHtml(api) {
    const icon = getIconForApi(api);
    const method = (api.method || 'GET').toUpperCase();
    const methodClass = method === 'POST' ? 'method-post' : (method === 'HEAD' ? 'method-head' : 'method-get');

    const statusLabels = { up: 'Operational', warn: 'Warning', down: 'Down', unconfigured: 'Not Set', checking: 'Checking…' };
    const dotClass     = { up: 'dot-up', warn: 'dot-warn', down: 'dot-down', unconfigured: 'dot-unconfigured', checking: 'dot-checking' };
    const badgeClass   = { up: 'badge-up', warn: 'badge-warn', down: 'badge-down', unconfigured: 'badge-unconfigured', checking: 'badge-checking' };

    const httpCodeDisplay = api.http_code !== null && api.http_code !== undefined ? api.http_code : '—';
    const responseDisplay = api.response_ms !== null && api.response_ms !== undefined ? api.response_ms + ' ms' : '—';

    let speedClass = '';
    let speedLabel = '';
    if (api.response_ms !== null && api.response_ms !== undefined) {
        if (api.response_ms < 150)      { speedClass = 'speed-fast';   speedLabel = '⚡ Fast'; }
        else if (api.response_ms < 600) { speedClass = 'speed-medium'; speedLabel = '⏱ Good'; }
        else                            { speedClass = 'speed-slow';   speedLabel = '🐢 Slow'; }
    }

    const metricColorClass = { up: 'metric-val-up', warn: 'metric-val-warn', down: 'metric-val-down', unconfigured: '' }[api.status] || '';
    const msgClass         = { up: 'msg-up', warn: 'msg-warn', down: 'msg-down', unconfigured: '' }[api.status] || '';

    const msgIcon = api.status === 'up'
        ? `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>`
        : `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path></svg>`;

    const escapedUrl = escHtml(api.url || '');

    return `
    <div class="api-monitor-card status-${api.status}">
        <div class="api-card-header">
            <div class="api-card-icon ${icon.class}">${icon.svg}</div>
            <div class="api-card-meta">
                <div class="api-card-title-row">
                    <span class="api-method-badge ${methodClass}">${method}</span>
                    <span class="api-card-name">${escHtml(api.name)}</span>
                    <span class="api-type-tag">${escHtml(api.category || '')}</span>
                </div>
                <div class="api-card-desc">${escHtml(api.description || '')}</div>
            </div>
            <div class="api-status-badge ${badgeClass[api.status] || 'badge-unconfigured'}">
                <div class="status-dot ${dotClass[api.status] || ''}"></div>
                ${statusLabels[api.status] || api.status}
            </div>
        </div>

        <div class="api-card-body">
            <div class="api-metric-row">
                <div class="api-metric">
                    <div class="api-metric-label">Status</div>
                    <div class="api-metric-value ${metricColorClass}">${statusLabels[api.status] || api.status}</div>
                </div>
                <div class="api-metric">
                    <div class="api-metric-label">HTTP Code</div>
                    <div class="api-metric-value">${httpCodeDisplay}</div>
                </div>
                <div class="api-metric">
                    <div class="api-metric-label">Response</div>
                    <div class="api-metric-value">${responseDisplay} ${speedLabel ? `<span class="speed-pill ${speedClass}">${speedLabel}</span>` : ''}</div>
                </div>
            </div>

            <div class="api-url-row">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:0.5;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                <div class="api-url-text" title="${escapedUrl}">${escapedUrl || '<em>No URL</em>'}</div>
            </div>

            <div class="api-message-row ${msgClass}">
                ${msgIcon}
                <span>${escHtml(api.message || '—')}</span>
            </div>

            <div class="api-card-actions">
                <div>
                    ${api.file_name ? `<span style="font-family:monospace; color:var(--text-dim); font-size:10.5px;">📁 ${escHtml(api.file_name)}</span>` : ''}
                </div>
                <div style="display:flex; gap:6px;">
                    <button type="button" class="btn-api-action" onclick="navigator.clipboard.writeText('${escapedUrl}'); showToast('API URL copied!');" title="Copy URL">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        Copy
                    </button>
                    ${api.url && api.url.startsWith('http') ? `
                    <a href="${escapedUrl}" target="_blank" rel="noopener noreferrer" class="btn-api-action" title="Open in New Tab to test JSON">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        Test ↗
                    </a>` : ''}
                </div>
            </div>
        </div>
    </div>
    `;
}

// ── Auto-Refresh ────────────────────────────────────────────────────────────
function toggleAutoRefresh() {
    const btn = document.getElementById('btn-autorefresh');
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
        btn.classList.remove('active');
        btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Auto (30s)`;
    } else {
        autoRefreshTimer = setInterval(() => runHealthCheck(), 30000);
        btn.classList.add('active');
        btn.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Auto: ON`;
    }
}

// ── Helpers ─────────────────────────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

const style = document.createElement('style');
style.textContent = '@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }';
document.head.appendChild(style);

// ── Initial run ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => runHealthCheck());
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>

