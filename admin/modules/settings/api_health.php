<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title    = 'API Health & Endpoints Monitor';
$page_subtitle = 'Live status, latency analytics, and auto-discovery of all 3rd-party integrations & internal REST endpoints';
$active_page_key = 'api_health';

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- FontAwesome 6 CDN for rich icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />

<style>
/* ── Full Width Modern API Health Monitor Styles ─────────────────── */
.health-container {
    width: 100%;
    max-width: 100%;
}

/* 5-Metric Summary Cards (Full Width Grid) */
.health-summary-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 22px;
    width: 100%;
}
@media (max-width: 1200px) {
    .health-summary-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .health-summary-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    .health-summary-grid { grid-template-columns: 1fr; }
}

.health-stat-card {
    border-radius: var(--radius-lg);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.health-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.14);
}
.health-stat-icon {
    width: 46px;
    height: 46px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 20px;
}
.stat-icon-total { background: rgba(99,102,241,0.15); color: #818cf8; }
.stat-icon-up    { background: rgba(16,185,129,0.15); color: #10b981; }
.stat-icon-warn  { background: rgba(245,158,11,0.15);  color: #f59e0b; }
.stat-icon-down  { background: rgba(239,68,68,0.15);   color: #ef4444; }
.stat-icon-speed { background: rgba(56,189,248,0.15);  color: #38bdf8; }

.health-stat-info { display: flex; flex-direction: column; min-width: 0; }
.health-stat-val  { font-size: 28px; font-weight: 800; line-height: 1.1; color: var(--text-main); }
.health-stat-lbl  { font-size: 11px; color: var(--text-dim); margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

/* Control & Filter Toolbar (Full Width) */
.health-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px 20px;
    margin-bottom: 20px;
    width: 100%;
}
.toolbar-left {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.toolbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-check-main {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    font-weight: 600;
    font-size: 13.5px;
    border-radius: 8px;
    background: var(--primary);
    color: #fff;
    border: none;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.15s;
}
.btn-check-main:hover {
    opacity: 0.92;
    transform: translateY(-1px);
}
.btn-check-main:active {
    transform: translateY(0);
}

.search-box {
    position: relative;
    min-width: 300px;
}
.search-box input {
    width: 100%;
    padding: 9px 12px 9px 38px;
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
.search-box .search-icon-svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    pointer-events: none;
    display: flex;
    align-items: center;
}

/* Category Filter Tabs */
.filter-tabs {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    overflow-x: auto;
    padding-bottom: 4px;
    width: 100%;
}
.filter-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
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
    box-shadow: 0 4px 12px rgba(99,102,241,0.25);
}
.filter-tab-count {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 10px;
    background: rgba(255,255,255,0.2);
}
.filter-tab-btn:not(.active) .filter-tab-count {
    background: var(--border-color);
    color: var(--text-dim);
}

/* ── Full Width API List Table ─────────────────────────────────────── */
.api-list-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    overflow: hidden;
    width: 100%;
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
    padding: 13px 20px;
    font-weight: 700;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-dim);
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.api-table td {
    padding: 15px 20px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.api-row {
    transition: background-color 0.15s;
}
.api-row:hover {
    background: rgba(255,255,255,0.025);
}
.api-row.expanded {
    background: rgba(99,102,241,0.04);
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
    gap: 7px;
    padding: 5px 12px;
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
    flex-shrink: 0;
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
    gap: 6px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12px;
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
    gap: 5px;
    font-weight: 700;
    font-size: 13px;
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
    flex-wrap: wrap;
}
.endpoint-desc {
    font-size: 12px;
    color: var(--text-dim);
    margin-bottom: 4px;
}
.endpoint-route {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 11.5px;
    color: var(--text-muted);
    background: rgba(0,0,0,0.18);
    padding: 3px 8px;
    border-radius: 4px;
    display: inline-block;
    max-width: 500px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    border: 1px solid rgba(255,255,255,0.05);
}

/* Action Buttons */
.action-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;
}
.btn-icon-soft {
    width: 32px;
    height: 32px;
    border-radius: 7px;
    border: 1px solid var(--border-color);
    background: var(--bg-sidebar);
    color: var(--text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.18s;
    font-size: 13px;
    text-decoration: none;
}
.btn-icon-soft:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    transform: translateY(-1px);
}

/* Expandable Drawer Row */
.drawer-row td {
    padding: 0 !important;
    border-bottom: 1px solid var(--border-color);
}
.drawer-content {
    padding: 18px 24px;
    background: rgba(0,0,0,0.15);
    border-top: 1px dashed var(--border-color);
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.drawer-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
}
.drawer-meta-box {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 12px 16px;
}
.drawer-meta-lbl {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--text-dim);
    font-weight: 700;
    margin-bottom: 5px;
    letter-spacing: 0.05em;
}
.drawer-meta-val {
    font-size: 12.5px;
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
    padding: 11px 20px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    display: flex;
    align-items: center;
    gap: 10px;
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
            <div class="health-stat-icon stat-icon-total">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-total">--</span>
                <span class="health-stat-lbl">Total Endpoints</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-up">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-up" style="color:#10b981;">--</span>
                <span class="health-stat-lbl">Operational (200 OK)</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-warn">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-warn" style="color:#f59e0b;">--</span>
                <span class="health-stat-lbl">Warnings / Auth</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-down">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-down" style="color:#ef4444;">--</span>
                <span class="health-stat-lbl">Down / Errors</span>
            </div>
        </div>
        <div class="health-stat-card">
            <div class="health-stat-icon stat-icon-speed">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
            </div>
            <div class="health-stat-info">
                <span class="health-stat-val" id="stat-speed" style="color:#38bdf8;">--</span>
                <span class="health-stat-lbl">Avg Response Speed</span>
            </div>
        </div>
    </div>

    <!-- Control Toolbar (Full Width) -->
    <div class="health-toolbar">
        <div class="toolbar-left">
            <button class="btn-check-main" id="btn-check-now" onclick="runHealthCheck()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="refresh-icon"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                <span>Run Health Check</span>
            </button>
            <div style="display:inline-flex; align-items:center; gap:8px;">
                <label style="font-size:12.5px; color:var(--text-dim); margin-bottom:0; font-weight:600;">Auto-check:</label>
                <select id="auto-refresh-select" class="form-control" style="width:115px; padding:6px 10px; font-size:12.5px; height:auto; border-radius:6px;" onchange="updateAutoRefresh(this.value)">
                    <option value="0">Off</option>
                    <option value="15">Every 15s</option>
                    <option value="30" selected>Every 30s</option>
                    <option value="60">Every 60s</option>
                </select>
            </div>
            <span style="font-size:12.5px; color:var(--text-dim);" id="last-checked-label">Last checked: Just now</span>
        </div>
        <div class="toolbar-right">
            <div class="search-box">
                <span class="search-icon-svg">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </span>
                <input type="text" id="api-search-input" placeholder="Filter endpoints, routes, methods..." oninput="filterApiList()">
            </div>
            <a href="<?= BASE_URL ?>/modules/settings/integrations.php" class="btn btn-outline-secondary" style="font-size:12.5px; padding:8px 14px; display:inline-flex; align-items:center; gap:6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                <span>Configure Keys</span>
            </a>
        </div>
    </div>

    <!-- Category Filter Tabs -->
    <div class="filter-tabs">
        <button class="filter-tab-btn active" data-filter="all" onclick="setCategoryFilter('all', this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
            All Endpoints <span class="filter-tab-count" id="count-all">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="external" onclick="setCategoryFilter('external', this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
            3rd-Party Integrations <span class="filter-tab-count" id="count-external">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="internal_admin" onclick="setCategoryFilter('internal_admin', this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            Admin REST Feeds (/admin/api) <span class="filter-tab-count" id="count-admin">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="internal_public" onclick="setCategoryFilter('internal_public', this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
            Public Subdomain Feeds (/api) <span class="filter-tab-count" id="count-public">0</span>
        </button>
        <button class="filter-tab-btn" data-filter="issues" onclick="setCategoryFilter('issues', this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            Issues Only <span class="filter-tab-count" id="count-issues">0</span>
        </button>
    </div>

    <!-- Full Width API List Table Card -->
    <div class="api-list-card">
        <div class="table-responsive">
            <table class="api-table" id="api-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Status</th>
                        <th>Endpoint & Route</th>
                        <th style="width: 190px;">Scope / Category</th>
                        <th style="width: 110px;">HTTP Code</th>
                        <th style="width: 125px;">Latency</th>
                        <th style="width: 190px;">Message / Diagnostic</th>
                        <th style="width: 110px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="api-list-body">
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 45px; color: var(--text-dim);">
                            <div style="margin-bottom:12px;">
                                <svg class="spinner-btn" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg>
                            </div>
                            <div style="font-size:14px; font-weight:600; color:var(--text-main);">Discovering and benchmarking all endpoints in parallel...</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Copy Feedback Toast -->
<div class="copy-toast" id="copyToast">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <span>URL copied to clipboard!</span>
</div>

<script>
let allApiData = [];
let currentCategory = 'all';
let autoRefreshTimer = null;

// Clean Inline SVG Helpers for reliable rendering
const SVGS = {
    copy: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>`,
    external: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>`,
    chevronDown: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>`,
    bolt: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>`,
    globe: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>`,
    lock: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>`,
    cloud: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path></svg>`,
    layer: `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>`
};

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
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:35px; color:var(--text-dim);">No endpoints found.</td></tr>`;
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
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-dim);"><div style="margin-bottom:6px;">No matching endpoints found for current filter.</div></td></tr>`;
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
            if (api.response_ms > 600) { latClass = 'latency-slow'; }
            else if (api.response_ms > 250) { latClass = 'latency-med'; }
            latencyHtml = `<span class="latency-pill ${latClass}">${SVGS.bolt} ${api.response_ms} ms</span>`;
        }

        // HTTP Code
        let codeHtml = `<span style="color:var(--text-dim); font-weight:600;">--</span>`;
        if (api.http_code !== null && api.http_code !== undefined) {
            let codeColor = '#ef4444';
            if (api.http_code >= 200 && api.http_code < 300) codeColor = '#10b981';
            else if (api.http_code >= 400 && api.http_code < 500) codeColor = '#f59e0b';
            codeHtml = `<span style="font-weight:700; color:${codeColor}; font-family:monospace; font-size:13.5px;">${api.http_code}</span>`;
        }

        // Category Tag
        let catPill = `<span class="category-pill">${SVGS.layer} ${api.category || 'Endpoint'}</span>`;
        if (api.type === 'internal_public') {
            catPill = `<span class="category-pill" style="border-color:rgba(59,130,246,0.3); color:#60a5fa;">${SVGS.globe} Public Feed</span>`;
        } else if (api.type === 'internal_admin') {
            catPill = `<span class="category-pill" style="border-color:rgba(99,102,241,0.3); color:#818cf8;">${SVGS.lock} Admin REST</span>`;
        } else if (api.type === 'external') {
            catPill = `<span class="category-pill" style="border-color:rgba(245,158,11,0.3); color:#fbbf24;">${SVGS.cloud} 3rd Party</span>`;
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
                                ${api.is_json ? '<span style="font-size:10.5px; font-weight:700; padding:1px 6px; border-radius:4px; background:rgba(16,185,129,0.15); color:#10b981; border:1px solid rgba(16,185,129,0.3);">JSON</span>' : ''}
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
                            ${SVGS.copy}
                        </button>
                        <a href="${api.url}" target="_blank" rel="noopener noreferrer" class="btn-icon-soft" title="Test / Open in new tab">
                            ${SVGS.external}
                        </a>
                        <button class="btn-icon-soft" title="View details" onclick="toggleDrawer('${drawerId}', '${rowId}')">
                            <span id="arrow-${drawerId}" style="display:inline-flex; transition:transform 0.2s;">${SVGS.chevronDown}</span>
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
