<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('settings');

$page_title    = 'API Health Monitor';
$page_subtitle = 'Real-time status of all third-party API integrations';
$active_page_key = 'api_health';

require_once ADMIN_PATH . '/includes/header.php';
?>

<style>
/* ── API Health Monitor Styles ─────────────────────────────────── */
.health-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.health-stat-card {
    border-radius: var(--radius-lg);
    padding: 20px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    transition: transform 0.18s, box-shadow 0.18s;
}
.health-stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.15); }
.health-stat-icon {
    width: 48px; height: 48px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.stat-icon-up    { background: rgba(16,185,129,0.15); color: #10b981; }
.stat-icon-warn  { background: rgba(245,158,11,0.15);  color: #f59e0b; }
.stat-icon-down  { background: rgba(239,68,68,0.15);   color: #ef4444; }
.stat-icon-total { background: rgba(99,102,241,0.15);  color: var(--primary); }
.health-stat-info { display: flex; flex-direction: column; }
.health-stat-val  { font-size: 32px; font-weight: 800; line-height: 1; }
.health-stat-lbl  { font-size: 12px; color: var(--text-dim); margin-top: 4px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }

/* API Cards grid */
.api-cards-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
    margin-bottom: 28px;
}

.api-monitor-card {
    border-radius: var(--radius-lg);
    border: 1px solid var(--border-color);
    background: var(--bg-card);
    overflow: hidden;
    transition: transform 0.18s, box-shadow 0.18s;
    position: relative;
}
.api-monitor-card:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.15); }

/* Status top-border accent */
.api-monitor-card.status-up   { border-top: 3px solid #10b981; }
.api-monitor-card.status-warn { border-top: 3px solid #f59e0b; }
.api-monitor-card.status-down { border-top: 3px solid #ef4444; }
.api-monitor-card.status-unconfigured { border-top: 3px solid #6b7280; }
.api-monitor-card.status-checking { border-top: 3px solid #6366f1; }

.api-card-header {
    display: flex; align-items: center; gap: 14px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border-color);
}
.api-card-icon {
    width: 42px; height: 42px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 18px;
}
.icon-crm      { background: rgba(99,102,241,0.15);  color: #6366f1; }
.icon-email    { background: rgba(16,185,129,0.15);  color: #10b981; }
.icon-whatsapp { background: rgba(37,211,102,0.15);  color: #25d366; }
.icon-internal { background: rgba(14,165,233,0.15);  color: #0ea5e9; }

.api-card-meta { flex: 1; min-width: 0; }
.api-card-name { font-size: 15px; font-weight: 700; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.api-card-desc { font-size: 12px; color: var(--text-dim); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.api-status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 12px; border-radius: 100px;
    font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
    flex-shrink: 0;
}
.badge-up            { background: rgba(16,185,129,0.12); color: #10b981; }
.badge-warn          { background: rgba(245,158,11,0.12);  color: #f59e0b; }
.badge-down          { background: rgba(239,68,68,0.12);   color: #ef4444; }
.badge-unconfigured  { background: rgba(107,114,128,0.12); color: #9ca3af; }
.badge-checking      { background: rgba(99,102,241,0.10);  color: #6366f1; }

.status-dot {
    width: 7px; height: 7px; border-radius: 50%;
    animation: none;
}
.dot-up    { background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,0.25); animation: pulse-up 2s infinite; }
.dot-warn  { background: #f59e0b; }
.dot-down  { background: #ef4444; animation: pulse-down 1.5s infinite; }
.dot-unconfigured { background: #6b7280; }
.dot-checking     { background: #6366f1; animation: pulse-up 1s infinite; }

@keyframes pulse-up   { 0%,100%{ box-shadow: 0 0 0 2px rgba(16,185,129,0.2); } 50%{ box-shadow: 0 0 0 6px rgba(16,185,129,0.05); } }
@keyframes pulse-down { 0%,100%{ box-shadow: 0 0 0 2px rgba(239,68,68,0.2); }  50%{ box-shadow: 0 0 0 6px rgba(239,68,68,0.05); } }

.api-card-body { padding: 16px 20px 18px; }

.api-metric-row {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    gap: 10px; margin-bottom: 14px;
}
.api-metric {
    background: var(--bg-sidebar);
    border-radius: 10px; padding: 10px 12px;
    display: flex; flex-direction: column; gap: 3px;
}
.api-metric-label { font-size: 10.5px; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
.api-metric-value { font-size: 15px; font-weight: 700; color: var(--text-main); }
.metric-val-up   { color: #10b981; }
.metric-val-warn { color: #f59e0b; }
.metric-val-down { color: #ef4444; }

.api-url-row {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg-sidebar); border-radius: 8px;
    padding: 9px 12px; margin-bottom: 12px;
}
.api-url-text {
    font-size: 11.5px; color: var(--text-dim); font-family: 'Courier New', monospace;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1;
}
.api-message-row {
    font-size: 12px; color: var(--text-dim);
    padding: 8px 12px; border-radius: 8px;
    background: var(--bg-sidebar);
    display: flex; align-items: center; gap: 8px;
}
.api-message-row.msg-up   { color: #10b981; background: rgba(16,185,129,0.07); }
.api-message-row.msg-warn { color: #f59e0b; background: rgba(245,158,11,0.07); }
.api-message-row.msg-down { color: #ef4444; background: rgba(239,68,68,0.07); }

/* Speed indicator */
.speed-pill {
    display: inline-block; padding: 2px 8px; border-radius: 100px;
    font-size: 11px; font-weight: 700;
}
.speed-fast   { background: rgba(16,185,129,0.12); color: #10b981; }
.speed-medium { background: rgba(245,158,11,0.12);  color: #f59e0b; }
.speed-slow   { background: rgba(239,68,68,0.12);   color: #ef4444; }

/* Action bar */
.health-action-bar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 22px;
    background: var(--bg-card); border: 1px solid var(--border-color);
    border-radius: var(--radius-lg); padding: 14px 20px;
}
.health-action-bar .bar-left { display: flex; align-items: center; gap: 12px; }
.last-checked-info { font-size: 12.5px; color: var(--text-dim); }
.last-checked-info strong { color: var(--text-main); }

/* Refresh / auto-refresh controls */
.btn-refresh {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 20px; border-radius: 10px; font-size: 13.5px; font-weight: 600;
    background: var(--primary-gradient); color: #fff; border: none; cursor: pointer;
    transition: opacity 0.2s, transform 0.15s; white-space: nowrap;
}
.btn-refresh:hover { opacity: 0.88; transform: translateY(-1px); }
.btn-refresh:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.btn-autorefresh {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px; border-radius: 10px; font-size: 13px; font-weight: 600;
    background: var(--bg-sidebar); color: var(--text-dim);
    border: 1px solid var(--border-color); cursor: pointer;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
}
.btn-autorefresh.active { background: rgba(16,185,129,0.12); color: #10b981; border-color: rgba(16,185,129,0.3); }

/* Skeleton loader */
.skeleton {
    background: linear-gradient(90deg, var(--border-color) 25%, var(--bg-sidebar) 37%, var(--border-color) 63%);
    background-size: 400px 100%;
    animation: skeleton-loading 1.4s ease infinite;
    border-radius: 6px;
    display: inline-block;
}
@keyframes skeleton-loading { 0%{ background-position: 100% 50%; } 100%{ background-position: 0% 50%; } }

.api-monitor-card.loading .api-card-icon,
.api-monitor-card.loading .api-card-name,
.api-monitor-card.loading .api-card-desc,
.api-monitor-card.loading .api-status-badge { opacity: 0.3; }

/* Unconfigured notice */
.unconfigured-notice {
    display: flex; align-items: center; gap: 10px;
    font-size: 12.5px; color: #9ca3af;
    padding: 10px 14px; border-radius: 8px;
    background: rgba(107,114,128,0.08); border: 1px dashed rgba(107,114,128,0.25);
}

/* Responsive */
@media (max-width: 900px) {
    .api-cards-grid { grid-template-columns: 1fr; }
    .health-summary-grid { grid-template-columns: repeat(2,1fr); }
    .api-metric-row { grid-template-columns: 1fr 1fr; }
}
</style>

<!-- ── Action Bar ──────────────────────────────────────────────────────── -->
<div class="health-action-bar">
    <div class="bar-left">
        <button class="btn-refresh" id="btn-refresh" onclick="runHealthCheck()">
            <svg id="refresh-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            Check Now
        </button>
        <button class="btn-autorefresh" id="btn-autorefresh" onclick="toggleAutoRefresh()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Auto (30s)
        </button>
        <span class="last-checked-info" id="last-checked-info">Checking APIs…</span>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
        <a href="<?php echo BASE_URL; ?>/modules/settings/api_integrations.php" class="btn-secondary" style="display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:10px; font-size:13px; font-weight:600; text-decoration:none; white-space:nowrap;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.07 4.93l-1.41 1.41M4.93 19.07l1.41-1.41M4.93 4.93l1.41 1.41M19.07 19.07l-1.41-1.41M12 2v2M12 20v2M2 12h2M20 12h2"></path></svg>
            Configure APIs
        </a>
    </div>
</div>

<!-- ── Summary Stats ────────────────────────────────────────────────────── -->
<div class="health-summary-grid" id="summary-grid">
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-total">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"></path></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" id="stat-total">—</span>
            <span class="health-stat-lbl">Total APIs</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-up">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#10b981;" id="stat-up">—</span>
            <span class="health-stat-lbl">Operational</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-warn">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#f59e0b;" id="stat-warn">—</span>
            <span class="health-stat-lbl">Warnings</span>
        </div>
    </div>
    <div class="health-stat-card">
        <div class="health-stat-icon stat-icon-down">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
        </div>
        <div class="health-stat-info">
            <span class="health-stat-val" style="color:#ef4444;" id="stat-down">—</span>
            <span class="health-stat-lbl">Down / Errors</span>
        </div>
    </div>
</div>

<!-- ── API Cards ──────────────────────────────────────────────────────── -->
<div class="api-cards-grid" id="api-cards-grid">
    <!-- Skeleton placeholders while loading -->
    <?php
    $skeleton_apis = [
        ['id'=>'crm',          'name'=>'SODE CRM API',             'desc'=>'Primary lead capture endpoint', 'icon_class'=>'icon-crm',      'icon_svg'=>'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'],
        ['id'=>'brevo',        'name'=>'Brevo (Sendinblue) API',   'desc'=>'Email & SMS marketing',        'icon_class'=>'icon-email',    'icon_svg'=>'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>'],
        ['id'=>'gallabox',     'name'=>'Gallabox WhatsApp',        'desc'=>'WhatsApp automation webhook',  'icon_class'=>'icon-whatsapp', 'icon_svg'=>'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>'],
        ['id'=>'sode_internal','name'=>'SODE Internal API',        'desc'=>'Internal data feeds for subdomains', 'icon_class'=>'icon-internal', 'icon_svg'=>'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>'],
    ];
    foreach ($skeleton_apis as $s): ?>
    <div class="api-monitor-card status-checking loading" id="card-<?php echo $s['id']; ?>">
        <div class="api-card-header">
            <div class="api-card-icon <?php echo $s['icon_class']; ?>"><?php echo $s['icon_svg']; ?></div>
            <div class="api-card-meta">
                <div class="api-card-name"><?php echo $s['name']; ?></div>
                <div class="api-card-desc"><?php echo $s['desc']; ?></div>
            </div>
            <div class="api-status-badge badge-checking" id="badge-<?php echo $s['id']; ?>">
                <div class="status-dot dot-checking"></div>
                Checking…
            </div>
        </div>
        <div class="api-card-body" id="body-<?php echo $s['id']; ?>">
            <div class="api-metric-row">
                <div class="api-metric"><div class="api-metric-label">Status</div><div class="api-metric-value"><span class="skeleton" style="width:60px;height:14px;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></div></div>
                <div class="api-metric"><div class="api-metric-label">HTTP Code</div><div class="api-metric-value"><span class="skeleton" style="width:40px;height:14px;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></div></div>
                <div class="api-metric"><div class="api-metric-label">Response</div><div class="api-metric-value"><span class="skeleton" style="width:50px;height:14px;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></div></div>
            </div>
            <div class="api-url-row"><div class="api-url-text" style="opacity:0.4;"><span class="skeleton" style="width:220px;height:12px;">&nbsp;</span></div></div>
            <div class="api-message-row"><span class="skeleton" style="width:180px;height:12px;">&nbsp;</span></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Legend ────────────────────────────────────────────────────────── -->
<div class="admin-card" style="padding:16px 20px;">
    <div style="display:flex; align-items:center; gap:24px; flex-wrap:wrap;">
        <span style="font-size:12px; font-weight:700; color:var(--text-dim); text-transform:uppercase; letter-spacing:0.05em;">Legend</span>
        <span style="display:flex;align-items:center;gap:7px;font-size:12.5px;"><div class="status-dot dot-up"></div><strong style="color:#10b981;">Operational</strong> — API is responding normally</span>
        <span style="display:flex;align-items:center;gap:7px;font-size:12.5px;"><div class="status-dot dot-warn"></div><strong style="color:#f59e0b;">Warning</strong> — Reachable but auth/config issue</span>
        <span style="display:flex;align-items:center;gap:7px;font-size:12.5px;"><div class="status-dot dot-down"></div><strong style="color:#ef4444;">Down</strong> — Unreachable or server error</span>
        <span style="display:flex;align-items:center;gap:7px;font-size:12.5px;"><div class="status-dot dot-unconfigured"></div><strong style="color:#9ca3af;">Not Configured</strong> — No credentials set</span>
    </div>
</div>

<script>
// ── Config ───────────────────────────────────────────────────────────────────
const API_ENDPOINT = '<?php echo BASE_URL; ?>/api/check_api_health.php';
let autoRefreshTimer = null;
let isChecking = false;

const CARD_ICONS = {
    crm:          { class: 'icon-crm',      svg: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>` },
    brevo:        { class: 'icon-email',    svg: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>` },
    gallabox:     { class: 'icon-whatsapp', svg: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>` },
    sode_internal:{ class: 'icon-internal', svg: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>` },
};

// ── Core: run health check ────────────────────────────────────────────────────
async function runHealthCheck() {
    if (isChecking) return;
    isChecking = true;

    const btn = document.getElementById('btn-refresh');
    const icon = document.getElementById('refresh-icon');
    btn.disabled = true;
    icon.style.animation = 'spin 0.8s linear infinite';

    // Put cards back to loading state
    ['crm','brevo','gallabox','sode_internal'].forEach(id => setCardLoading(id));

    document.getElementById('last-checked-info').innerHTML = '<em>Checking… please wait</em>';

    try {
        const res  = await fetch(API_ENDPOINT + '?t=' + Date.now());
        const data = await res.json();

        // Update summary
        document.getElementById('stat-total').textContent = data.summary.total;
        document.getElementById('stat-up').textContent    = data.summary.up;
        document.getElementById('stat-warn').textContent  = data.summary.warn;
        document.getElementById('stat-down').textContent  = data.summary.down + (data.summary.unconfigured > 0 ? '+' + data.summary.unconfigured + '⚙' : '');

        // Update each card
        data.apis.forEach(api => renderApiCard(api));

        const t = new Date(data.checked_at.replace(' ','T'));
        const formatted = t.toLocaleTimeString('en-IN', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        document.getElementById('last-checked-info').innerHTML = `Last checked: <strong>${formatted}</strong>`;

    } catch (err) {
        document.getElementById('last-checked-info').innerHTML = `<span style="color:#ef4444;">Check failed — ${err.message}</span>`;
        ['crm','brevo','gallabox','sode_internal'].forEach(id => {
            setCardError(id, 'Request failed — check server/network');
        });
    }

    btn.disabled = false;
    icon.style.animation = '';
    isChecking = false;
}

// ── Render individual card ────────────────────────────────────────────────────
function renderApiCard(api) {
    const card = document.getElementById('card-' + api.id);
    const badge = document.getElementById('badge-' + api.id);
    const body  = document.getElementById('body-' + api.id);
    if (!card) return;

    card.className = 'api-monitor-card status-' + api.status;

    const statusLabels = { up: 'Operational', warn: 'Warning', down: 'Down', unconfigured: 'Not Set', checking: 'Checking…' };
    const dotClass     = { up: 'dot-up', warn: 'dot-warn', down: 'dot-down', unconfigured: 'dot-unconfigured', checking: 'dot-checking' };
    const badgeClass   = { up: 'badge-up', warn: 'badge-warn', down: 'badge-down', unconfigured: 'badge-unconfigured', checking: 'badge-checking' };

    badge.className = 'api-status-badge ' + (badgeClass[api.status] || 'badge-unconfigured');
    badge.innerHTML = `<div class="status-dot ${dotClass[api.status] || ''}"></div>${statusLabels[api.status] || api.status}`;

    const httpCodeDisplay = api.http_code !== null && api.http_code !== undefined ? api.http_code : '—';
    const responseDisplay = api.response_ms !== null && api.response_ms !== undefined ? api.response_ms + ' ms' : '—';

    let speedClass = '';
    let speedLabel = '';
    if (api.response_ms !== null && api.response_ms !== undefined) {
        if (api.response_ms < 400)       { speedClass = 'speed-fast';   speedLabel = '⚡ Fast'; }
        else if (api.response_ms < 1200) { speedClass = 'speed-medium'; speedLabel = '⏱ Medium'; }
        else                             { speedClass = 'speed-slow';   speedLabel = '🐢 Slow'; }
    }

    const metricColorClass = { up: 'metric-val-up', warn: 'metric-val-warn', down: 'metric-val-down', unconfigured: '' }[api.status] || '';
    const msgClass         = { up: 'msg-up', warn: 'msg-warn', down: 'msg-down', unconfigured: '' }[api.status] || '';

    const msgIcon = api.status === 'up'
        ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>`
        : api.status === 'warn'
        ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`
        : api.status === 'down'
        ? `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`
        : `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;

    const urlDisplay = api.url ? escHtml(api.url) : '<em style="opacity:0.5;">No URL configured</em>';

    const configuredInfo = !api.configured
        ? `<div class="unconfigured-notice"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>API credentials not configured — <a href="<?php echo BASE_URL; ?>/modules/settings/api_integrations.php" style="color:var(--primary);text-decoration:underline;">Go to API Settings</a></div>`
        : '';

    body.innerHTML = `
        <div class="api-metric-row">
            <div class="api-metric">
                <div class="api-metric-label">Status</div>
                <div class="api-metric-value ${metricColorClass}">${(statusLabels[api.status] || api.status)}</div>
            </div>
            <div class="api-metric">
                <div class="api-metric-label">HTTP Code</div>
                <div class="api-metric-value">${httpCodeDisplay}</div>
            </div>
            <div class="api-metric">
                <div class="api-metric-label">Response Time</div>
                <div class="api-metric-value">${responseDisplay} ${speedLabel ? `<span class="speed-pill ${speedClass}" style="font-size:10px;">${speedLabel}</span>` : ''}</div>
            </div>
        </div>
        <div class="api-url-row">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;opacity:0.5;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
            <div class="api-url-text" title="${escHtml(api.url || '')}">${urlDisplay}</div>
        </div>
        <div class="api-message-row ${msgClass}">
            ${msgIcon}
            <span>${escHtml(api.message || '—')}</span>
        </div>
        ${configuredInfo ? '<div style="margin-top:10px;">' + configuredInfo + '</div>' : ''}
    `;
}

function setCardLoading(id) {
    const card = document.getElementById('card-' + id);
    const badge = document.getElementById('badge-' + id);
    const body  = document.getElementById('body-' + id);
    if (!card) return;
    card.classList.add('loading');
    card.className = 'api-monitor-card status-checking loading';
    badge.className = 'api-status-badge badge-checking';
    badge.innerHTML = '<div class="status-dot dot-checking"></div>Checking…';
    body.innerHTML = `
        <div class="api-metric-row">
            <div class="api-metric"><div class="api-metric-label">Status</div><div class="api-metric-value"><span class="skeleton" style="width:60px;height:14px;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></div></div>
            <div class="api-metric"><div class="api-metric-label">HTTP Code</div><div class="api-metric-value"><span class="skeleton" style="width:40px;height:14px;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></div></div>
            <div class="api-metric"><div class="api-metric-label">Response</div><div class="api-metric-value"><span class="skeleton" style="width:50px;height:14px;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></div></div>
        </div>
        <div class="api-url-row"><div class="api-url-text" style="opacity:0.4;"><span class="skeleton" style="width:220px;height:12px;">&nbsp;</span></div></div>
        <div class="api-message-row"><span class="skeleton" style="width:180px;height:12px;">&nbsp;</span></div>
    `;
}

function setCardError(id, message) {
    const card = document.getElementById('card-' + id);
    const badge = document.getElementById('badge-' + id);
    const body  = document.getElementById('body-' + id);
    if (!card) return;
    card.className = 'api-monitor-card status-down';
    badge.className = 'api-status-badge badge-down';
    badge.innerHTML = '<div class="status-dot dot-down"></div>Error';
    body.innerHTML = `<div class="api-message-row msg-down"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg><span>${escHtml(message)}</span></div>`;
}

// ── Auto-refresh toggle ────────────────────────────────────────────────────────
function toggleAutoRefresh() {
    const btn = document.getElementById('btn-autorefresh');
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
        btn.classList.remove('active');
        btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Auto (30s)`;
    } else {
        autoRefreshTimer = setInterval(() => runHealthCheck(), 30000);
        btn.classList.add('active');
        btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> Auto: ON`;
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// CSS spin animation for refresh button
const style = document.createElement('style');
style.textContent = '@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }';
document.head.appendChild(style);

// ── Auto-run on page load ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => runHealthCheck());
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
