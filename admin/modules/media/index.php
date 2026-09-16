<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('media');

$db = get_db_connection();

// Self-healing check: Ensure Media Library is registered in sidebar_items
try {
    $sb_chk = $db->query("SELECT id FROM sidebar_items WHERE active_page_key = 'media'")->fetch();
    if (!$sb_chk) {
        $icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>';
        $stmt = $db->prepare("INSERT INTO sidebar_items (display_name, page_route, sort_order, active_page_key, rbac_module_key, menu_section, icon_svg, is_superadmin_only, is_active) VALUES (?, ?, 11, 'media', 'media', 'MANAGE', ?, 0, 1)");
        $stmt->execute(['Media Library', 'modules/media/index.php', $icon]);
        $new_id = $db->lastInsertId();
        
        $roles = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
        $acc_stmt = $db->prepare("INSERT IGNORE INTO role_sidebar_access (role_id, sidebar_item_id) VALUES (?, ?)");
        foreach ($roles as $r_id) {
            $acc_stmt->execute([$r_id, $new_id]);
        }
    }
} catch (Exception $e) {}

$page_title = 'Media Library';
$page_subtitle = 'Directly upload, browse, preview, copy URLs, and manage all your assets';
$active_page_key = 'media';

require_once ADMIN_PATH . '/includes/header.php';
?>

<!-- Custom styles for dedicated Media Library Page -->
<style>
.media-page-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Upload Collapse Dropzone */
.media-upload-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    transition: all 0.25s ease;
}

.media-page-dropzone {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-md);
    background: rgba(255, 255, 255, 0.015);
    text-align: center;
    transition: all 0.2s ease;
    cursor: pointer;
}

.media-page-dropzone:hover,
.media-page-dropzone.drag-over {
    border-color: var(--primary);
    background: rgba(79, 70, 229, 0.07);
    transform: scale(1.002);
}

.media-page-dropzone .dropzone-icon-wrap {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(79, 70, 229, 0.12);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
}

/* Filter & Action Toolbar */
.media-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px 18px;
    box-shadow: var(--shadow-sm);
}

.media-filter-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.filter-pill-btn {
    padding: 7px 15px;
    font-size: 12.5px;
    font-weight: 500;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    background: var(--bg-input);
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.filter-pill-btn:hover {
    background: var(--bg-card-hover);
    color: var(--text-main);
    border-color: var(--border-focus);
}

.filter-pill-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.35);
}

/* Search input wrapper */
.media-search-wrap {
    position: relative;
    min-width: 260px;
}

.media-search-wrap svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-dim);
    pointer-events: none;
}

.media-search-wrap input {
    width: 100%;
    padding: 8px 32px 8px 36px;
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    color: var(--text-main);
    font-size: 13px;
    outline: none;
    transition: all 0.2s ease;
}

.media-search-wrap input:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
}

.media-search-clear {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-dim);
    font-size: 16px;
    cursor: pointer;
    display: none;
    line-height: 1;
}

/* Grid Layout for Cards */
.media-page-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
}

.media-grid-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: all 0.2s ease;
    cursor: pointer;
    position: relative;
}

.media-grid-card:hover {
    border-color: var(--primary);
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
}

.media-card-preview {
    width: 100%;
    height: 130px;
    background: rgba(0, 0, 0, 0.15);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}

.media-card-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.media-grid-card:hover .media-card-preview img {
    transform: scale(1.06);
}

.media-type-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(4px);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Quick Action Overlay on Card */
.media-card-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.media-grid-card:hover .media-card-overlay {
    opacity: 1;
}

.media-overlay-btn {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.media-overlay-btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    transform: scale(1.1);
}

.media-overlay-btn.delete:hover {
    background: var(--danger);
    border-color: var(--danger);
}

.media-card-meta {
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.media-card-filename {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.media-card-subinfo {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 11px;
    color: var(--text-dim);
}

/* Full Page Details Modal / Drawer */
.media-detail-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.65);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.media-detail-dialog {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    width: 100%;
    max-width: 760px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
    animation: modalIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.96) translateY(10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

.media-detail-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.media-detail-body {
    padding: 20px;
    overflow-y: auto;
    display: grid;
    grid-template-columns: 1fr 1.1fr;
    gap: 20px;
}

@media (max-width: 700px) {
    .media-detail-body {
        grid-template-columns: 1fr;
    }
}

.detail-preview-container {
    background: var(--bg-input);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-md);
    padding: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 240px;
}

/* Pagination Bar */
.media-pagination-container {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    padding: 14px 20px;
    margin-top: 10px;
}

.pagination-pages-list {
    display: flex;
    align-items: center;
    gap: 6px;
    list-style: none;
    margin: 0;
    padding: 0;
}

.page-num-btn {
    min-width: 34px;
    height: 34px;
    padding: 0 8px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-color);
    background: var(--bg-input);
    color: var(--text-main);
    font-size: 13px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}

.page-num-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
}

.page-num-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    font-weight: 600;
}

.page-num-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* Toast notification */
.media-copy-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    background: #10b981;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    padding: 10px 18px;
    border-radius: 30px;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    z-index: 99999;
    display: none;
    align-items: center;
    gap: 8px;
    animation: toastSlideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes toastSlideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="media-page-container">

    <!-- Top Action Bar -->
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <span class="section-heading-sm" style="margin-bottom:0;">All Uploaded Assets</span>
            <div style="font-size:12.5px; color:var(--text-dim); margin-top:2px;">
                Direct uploads will be saved to <code style="background:var(--bg-input); padding:2px 6px; border-radius:4px; font-size:12px;">/uploads/YYYY/MM/</code> with instant link generator.
            </div>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <button type="button" class="btn-primary" id="trigger-upload-toggle-btn" style="width:auto; padding:8px 18px; font-size:13px; display:inline-flex; align-items:center; gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span>Upload New Files</span>
            </button>
            <button type="button" class="action-btn" id="refresh-library-btn" title="Refresh library" style="height:36px; padding:0 12px; display:inline-flex; align-items:center; gap:6px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"></path><path d="M1 20v-6h6"></path><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                <span>Refresh</span>
            </button>
        </div>
    </div>

    <!-- Direct Drag & Drop Upload Zone (Card) -->
    <div class="media-upload-card" id="media-upload-box">
        <div class="media-page-dropzone" id="direct-media-dropzone">
            <div class="dropzone-icon-wrap">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="17 8 12 3 7 8"></polyline>
                    <line x1="12" y1="3" x2="12" y2="15"></line>
                </svg>
            </div>
            <div style="font-size:16px; font-weight:700; color:var(--text-main); margin-bottom:4px;">
                Drag & Drop files here, or <span style="color:var(--primary); text-decoration:underline;">Browse from Device</span>
            </div>
            <div style="font-size:12.5px; color:var(--text-dim); max-width:540px; margin-bottom:16px;">
                Upload logos, banners, PDF brochures, podcast audios (MP3/WAV), video clips, and icons up to 50MB. Multiple file uploads supported!
            </div>
            
            <input type="file" id="direct-file-input" multiple style="display:none;" accept="image/*,audio/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
            
            <button type="button" class="btn-primary" onclick="document.getElementById('direct-file-input').click();" style="width:auto; padding:9px 24px; font-size:13px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                <span>Select Files to Upload</span>
            </button>

            <!-- Upload Progress Bar -->
            <div id="direct-upload-progress-wrap" style="display:none; width:100%; max-width:440px; margin-top:20px;">
                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--text-muted); margin-bottom:6px;">
                    <span id="direct-upload-status-text">Uploading files...</span>
                    <span id="direct-upload-pct-text">0%</span>
                </div>
                <div style="width:100%; height:7px; background:var(--bg-input); border-radius:4px; overflow:hidden; border:1px solid var(--border-color);">
                    <div id="direct-upload-progress-bar" style="width:0%; height:100%; background:var(--primary-gradient); transition:width 0.2s ease;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="media-filter-bar">
        <div class="media-filter-group">
            <button type="button" class="filter-pill-btn active" data-type="all">All Media</button>
            <button type="button" class="filter-pill-btn" data-type="image">Images</button>
            <button type="button" class="filter-pill-btn" data-type="pdf">PDF Docs</button>
            <button type="button" class="filter-pill-btn" data-type="audio">Audio</button>
            <button type="button" class="filter-pill-btn" data-type="video">Videos</button>
            <button type="button" class="filter-pill-btn" data-type="document">Documents</button>
        </div>

        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <div class="media-search-wrap">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="direct-media-search" placeholder="Search files by name...">
                <button type="button" class="media-search-clear" id="direct-search-clear">&times;</button>
            </div>
            <div id="media-count-indicator" style="font-size:12.5px; color:var(--text-dim); white-space:nowrap;">
                Loading...
            </div>
        </div>
    </div>

    <!-- Media Grid Container -->
    <div class="media-page-grid" id="direct-media-grid">
        <!-- Dynamically rendered via JS -->
    </div>

    <!-- Pagination Controls (50 Items Per Page) -->
    <div class="media-pagination-container" id="direct-pagination-bar" style="display:none;">
        <div style="font-size:12.5px; color:var(--text-dim);" id="direct-pagination-summary">
            Showing Page 1 of 1 (50 items per page)
        </div>
        <ul class="pagination-pages-list" id="direct-pagination-list">
            <!-- Dynamically populated page buttons -->
        </ul>
    </div>

</div>

<!-- Media Details Dialog Modal -->
<div class="media-detail-backdrop" id="media-detail-modal">
    <div class="media-detail-dialog">
        <div class="media-detail-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                <h3 style="font-size:15px; font-weight:700; color:var(--text-main); margin:0;">File Details</h3>
            </div>
            <button type="button" class="media-modal-close" id="close-detail-modal-btn" style="background:none; border:none; font-size:22px; color:var(--text-dim); cursor:pointer; line-height:1;">&times;</button>
        </div>

        <div class="media-detail-body">
            <!-- Left Preview -->
            <div class="detail-preview-container" id="modal-preview-box">
                <!-- Preview injected via JS -->
            </div>

            <!-- Right Metadata & Actions -->
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); letter-spacing:0.5px; display:block; margin-bottom:4px;">File Name</label>
                    <div id="modal-file-name" style="font-size:13.5px; font-weight:600; color:var(--text-main); word-break:break-all;"></div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; background:var(--bg-input); padding:10px 12px; border-radius:var(--radius-md); border:1px solid var(--border-color); font-size:12px;">
                    <div>
                        <span style="color:var(--text-dim);">Size:</span>
                        <strong id="modal-file-size" style="color:var(--text-main); display:block; margin-top:2px;"></strong>
                    </div>
                    <div>
                        <span style="color:var(--text-dim);">Type:</span>
                        <strong id="modal-file-type" style="color:var(--text-main); display:block; margin-top:2px; text-transform:uppercase;"></strong>
                    </div>
                    <div style="grid-column:1/-1;">
                        <span style="color:var(--text-dim);">Uploaded On:</span>
                        <span id="modal-file-date" style="color:var(--text-main); display:block; margin-top:2px;"></span>
                    </div>
                </div>

                <!-- Relative Path (Recommended for shortcodes & DB) -->
                <div>
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); letter-spacing:0.5px; display:block; margin-bottom:4px;">
                        Relative Path <span style="font-weight:normal; text-transform:none; color:var(--text-muted);">(Best for Subdomain Keys & Database)</span>
                    </label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="modal-relative-path-input" readonly class="form-control" style="font-size:12px; padding:7px 10px;">
                        <button type="button" class="btn-primary" id="modal-copy-rel-btn" style="width:auto; padding:7px 14px; font-size:12px; white-space:nowrap;">
                            Copy Path
                        </button>
                    </div>
                </div>

                <!-- Full Public URL -->
                <div>
                    <label style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-dim); letter-spacing:0.5px; display:block; margin-bottom:4px;">
                        Full Public URL <span style="font-weight:normal; text-transform:none; color:var(--text-muted);">(Direct browser access)</span>
                    </label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" id="modal-full-url-input" readonly class="form-control" style="font-size:12px; padding:7px 10px;">
                        <button type="button" class="action-btn" id="modal-copy-full-btn" style="width:auto; padding:7px 14px; font-size:12px; white-space:nowrap;">
                            Copy URL
                        </button>
                    </div>
                </div>

                <!-- Actions: Open / Download / Delete -->
                <div style="display:flex; gap:10px; margin-top:auto; padding-top:10px; border-top:1px solid var(--border-color);">
                    <a id="modal-download-link" href="#" target="_blank" class="action-btn" style="flex:1; text-align:center; padding:9px 12px; font-size:12.5px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        <span>Open / Download</span>
                    </a>
                    <button type="button" class="action-btn delete-btn" id="modal-delete-btn" style="flex:1; padding:9px 12px; font-size:12.5px; display:inline-flex; align-items:center; justify-content:center; gap:6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        <span>Delete Permanently</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div class="media-copy-toast" id="media-copy-toast">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
    <span id="media-copy-toast-msg">Copied to Clipboard!</span>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentPage = 1;
    const itemsPerPage = 50;
    let currentType = 'all';
    let currentSearch = '';
    let selectedFile = null;
    let searchTimer = null;

    const gridEl = document.getElementById('direct-media-grid');
    const countIndicator = document.getElementById('media-count-indicator');
    const paginationBar = document.getElementById('direct-pagination-bar');
    const paginationList = document.getElementById('direct-pagination-list');
    const paginationSummary = document.getElementById('direct-pagination-summary');
    const searchInput = document.getElementById('direct-media-search');
    const searchClear = document.getElementById('direct-search-clear');
    const filterPills = document.querySelectorAll('.filter-pill-btn');
    const refreshBtn = document.getElementById('refresh-library-btn');
    const dropzone = document.getElementById('direct-media-dropzone');
    const fileInput = document.getElementById('direct-file-input');
    const uploadProgressWrap = document.getElementById('direct-upload-progress-wrap');
    const uploadProgressBar = document.getElementById('direct-upload-progress-bar');
    const uploadStatusText = document.getElementById('direct-upload-status-text');
    const uploadPctText = document.getElementById('direct-upload-pct-text');
    const toast = document.getElementById('media-copy-toast');
    const toastMsg = document.getElementById('media-copy-toast-msg');

    // Details Modal Elements
    const detailModal = document.getElementById('media-detail-modal');
    const closeDetailBtn = document.getElementById('close-detail-modal-btn');
    const modalPreview = document.getElementById('modal-preview-box');
    const modalName = document.getElementById('modal-file-name');
    const modalSize = document.getElementById('modal-file-size');
    const modalType = document.getElementById('modal-file-type');
    const modalDate = document.getElementById('modal-file-date');
    const modalRelInput = document.getElementById('modal-relative-path-input');
    const modalFullInput = document.getElementById('modal-full-url-input');
    const modalCopyRelBtn = document.getElementById('modal-copy-rel-btn');
    const modalCopyFullBtn = document.getElementById('modal-copy-full-btn');
    const modalDownloadLink = document.getElementById('modal-download-link');
    const modalDeleteBtn = document.getElementById('modal-delete-btn');

    // Base API path helper
    const apiBasePath = '<?php echo BASE_URL; ?>/api';

    // 1. Initial Load
    fetchMediaList();

    // 2. Refresh Button
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            fetchMediaList();
            showToast('Library refreshed');
        });
    }

    // 3. Search Input Handling
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            const val = this.value.trim();
            if (searchClear) searchClear.style.display = val ? 'block' : 'none';
            searchTimer = setTimeout(() => {
                currentSearch = val;
                currentPage = 1;
                fetchMediaList();
            }, 300);
        });
    }

    if (searchClear) {
        searchClear.addEventListener('click', function() {
            searchInput.value = '';
            this.style.display = 'none';
            currentSearch = '';
            currentPage = 1;
            fetchMediaList();
        });
    }

    // 4. Filter Pills
    filterPills.forEach(pill => {
        pill.addEventListener('click', function() {
            filterPills.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            currentType = this.dataset.type || 'all';
            currentPage = 1;
            fetchMediaList();
        });
    });

    // 5. Fetch Media Items (AJAX with Pagination 50/page)
    function fetchMediaList() {
        gridEl.innerHTML = `
            <div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--text-dim);">
                <div style="display:inline-block; width:28px; height:28px; border:3px solid var(--border-color); border-top-color:var(--primary); border-radius:50%; animation:spin 0.8s linear infinite; margin-bottom:12px;"></div>
                <div style="font-size:13px;">Loading media assets...</div>
            </div>
        `;

        const url = `${apiBasePath}/media_list.php?type=${encodeURIComponent(currentType)}&q=${encodeURIComponent(currentSearch)}&page=${currentPage}&limit=${itemsPerPage}`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    gridEl.innerHTML = `<div style="grid-column:1/-1; color:var(--danger); padding:30px; text-align:center;">${data.message || 'Error fetching assets'}</div>`;
                    return;
                }

                renderMediaGrid(data.items || []);
                renderPagination(data.total, data.page, data.limit, data.total_pages);
            })
            .catch(err => {
                gridEl.innerHTML = `<div style="grid-column:1/-1; color:var(--danger); padding:30px; text-align:center;">Failed to load library items.</div>`;
            });
    }

    // 6. Render Grid Cards
    function renderMediaGrid(items) {
        if (!items || items.length === 0) {
            gridEl.innerHTML = `
                <div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--text-dim); background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg);">
                    <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:12px; color:var(--text-muted);"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                    <h4 style="font-size:15px; color:var(--text-main); margin-bottom:6px;">No files found</h4>
                    <p style="font-size:12.5px; max-width:340px; margin:0 auto 16px;">No uploaded media matches your filter or search. Drag & drop files above to upload.</p>
                </div>
            `;
            return;
        }

        gridEl.innerHTML = '';
        items.forEach(item => {
            const card = document.createElement('div');
            card.className = 'media-grid-card';
            card.dataset.id = item.id;

            let previewHtml = '';
            const previewUrl = item.display_url || item.file_url;
            const ext = (item.file_name.split('.').pop() || item.file_type).toUpperCase();

            if (item.file_type === 'image') {
                previewHtml = `<img src="${previewUrl}" alt="${escapeHtml(item.file_name)}" loading="lazy">`;
            } else if (item.file_type === 'audio') {
                previewHtml = `<div class="media-type-icon audio-icon"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle></svg></div>`;
            } else if (item.file_type === 'pdf') {
                previewHtml = `<div class="media-type-icon pdf-icon"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><text x="7" y="17" font-size="6" font-weight="bold" fill="currentColor">PDF</text></svg></div>`;
            } else if (item.file_type === 'video') {
                previewHtml = `<div class="media-type-icon video-icon"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect><line x1="7" y1="2" x2="7" y2="22"></line><line x1="17" y1="2" x2="17" y2="22"></line><line x1="2" y1="12" x2="22" y2="12"></line><line x1="2" y1="7" x2="7" y2="7"></line><line x1="2" y1="17" x2="7" y2="17"></line><line x1="17" y1="17" x2="22" y2="17"></line><line x1="17" y1="7" x2="22" y2="7"></line></svg></div>`;
            } else {
                previewHtml = `<div class="media-type-icon doc-icon"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg></div>`;
            }

            card.innerHTML = `
                <div class="media-card-preview">
                    ${previewHtml}
                    <span class="media-type-badge">${ext}</span>
                    <div class="media-card-overlay">
                        <button type="button" class="media-overlay-btn view" title="View Details">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        </button>
                        <button type="button" class="media-overlay-btn copy" title="Copy Path">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </button>
                        <button type="button" class="media-overlay-btn delete" title="Delete File">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </div>
                </div>
                <div class="media-card-meta">
                    <div class="media-card-filename" title="${escapeHtml(item.file_name)}">${escapeHtml(item.file_name)}</div>
                    <div class="media-card-subinfo">
                        <span>${item.formatted_size}</span>
                        <span>${item.created_at.split(',')[0]}</span>
                    </div>
                </div>
            `;

            // Card click -> Open Details
            card.addEventListener('click', (e) => {
                if (e.target.closest('.media-overlay-btn.copy')) {
                    e.stopPropagation();
                    copyToClipboard(item.file_path || item.file_url, 'Relative path copied!');
                    return;
                }
                if (e.target.closest('.media-overlay-btn.delete')) {
                    e.stopPropagation();
                    deleteFile(item);
                    return;
                }
                openDetailsModal(item);
            });

            gridEl.appendChild(card);
        });
    }

    // 7. Render Pagination (50 Items Per Page)
    function renderPagination(total, page, limit, totalPages) {
        if (countIndicator) {
            countIndicator.textContent = `Total ${total} file(s) found`;
        }

        if (total === 0 || totalPages <= 1) {
            paginationBar.style.display = 'none';
            return;
        }

        paginationBar.style.display = 'flex';
        const start = (page - 1) * limit + 1;
        const end = Math.min(page * limit, total);
        paginationSummary.textContent = `Showing ${start}-${end} of ${total} files (Page ${page} of ${totalPages}, 50 per page)`;

        paginationList.innerHTML = '';

        // Previous button
        const prevLi = document.createElement('li');
        prevLi.innerHTML = `<button type="button" class="page-num-btn" ${page <= 1 ? 'disabled' : ''}>&laquo; Prev</button>`;
        if (page > 1) {
            prevLi.querySelector('button').addEventListener('click', () => {
                currentPage--;
                fetchMediaList();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
        paginationList.appendChild(prevLi);

        // Page number range
        let startPage = Math.max(1, page - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        if (endPage - startPage < 4) {
            startPage = Math.max(1, endPage - 4);
        }

        if (startPage > 1) {
            const firstLi = document.createElement('li');
            firstLi.innerHTML = `<button type="button" class="page-num-btn">1</button>`;
            firstLi.querySelector('button').addEventListener('click', () => {
                currentPage = 1;
                fetchMediaList();
            });
            paginationList.appendChild(firstLi);

            if (startPage > 2) {
                const dotsLi = document.createElement('li');
                dotsLi.innerHTML = `<span style="padding:0 4px; color:var(--text-dim);">&hellip;</span>`;
                paginationList.appendChild(dotsLi);
            }
        }

        for (let p = startPage; p <= endPage; p++) {
            const numLi = document.createElement('li');
            numLi.innerHTML = `<button type="button" class="page-num-btn ${p === page ? 'active' : ''}">${p}</button>`;
            const btn = numLi.querySelector('button');
            const pageTarget = p;
            btn.addEventListener('click', () => {
                if (currentPage !== pageTarget) {
                    currentPage = pageTarget;
                    fetchMediaList();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
            paginationList.appendChild(numLi);
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const dotsLi = document.createElement('li');
                dotsLi.innerHTML = `<span style="padding:0 4px; color:var(--text-dim);">&hellip;</span>`;
                paginationList.appendChild(dotsLi);
            }

            const lastLi = document.createElement('li');
            lastLi.innerHTML = `<button type="button" class="page-num-btn">${totalPages}</button>`;
            lastLi.querySelector('button').addEventListener('click', () => {
                currentPage = totalPages;
                fetchMediaList();
            });
            paginationList.appendChild(lastLi);
        }

        // Next button
        const nextLi = document.createElement('li');
        nextLi.innerHTML = `<button type="button" class="page-num-btn" ${page >= totalPages ? 'disabled' : ''}>Next &raquo;</button>`;
        if (page < totalPages) {
            nextLi.querySelector('button').addEventListener('click', () => {
                currentPage++;
                fetchMediaList();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
        paginationList.appendChild(nextLi);
    }

    // 8. Open Details Modal
    function openDetailsModal(item) {
        selectedFile = item;
        detailModal.style.display = 'flex';

        modalName.textContent = item.file_name;
        modalSize.textContent = item.formatted_size;
        modalType.textContent = `${item.file_type} (${item.mime_type || 'N/A'})`;
        modalDate.textContent = item.created_at;

        const relPath = item.file_path || item.file_url;
        const fullUrl = item.display_url || (window.location.origin + '/' + relPath.replace(/^\//, ''));

        modalRelInput.value = relPath;
        modalFullInput.value = fullUrl;
        modalDownloadLink.href = fullUrl;

        // Render Preview Box
        if (item.file_type === 'image') {
            modalPreview.innerHTML = `<img src="${fullUrl}" alt="${escapeHtml(item.file_name)}" style="max-width:100%; max-height:260px; object-fit:contain; border-radius:6px;">`;
        } else if (item.file_type === 'audio') {
            modalPreview.innerHTML = `
                <div style="width:100%; text-align:center;">
                    <div style="font-size:36px; color:#ec4899; margin-bottom:12px;">🎵</div>
                    <audio controls style="width:100%;">
                        <source src="${fullUrl}" type="${item.mime_type}">
                        Your browser does not support audio element.
                    </audio>
                </div>
            `;
        } else if (item.file_type === 'video') {
            modalPreview.innerHTML = `
                <video controls style="max-width:100%; max-height:260px; border-radius:6px;">
                    <source src="${fullUrl}" type="${item.mime_type}">
                    Your browser does not support video tag.
                </video>
            `;
        } else if (item.file_type === 'pdf') {
            modalPreview.innerHTML = `
                <div style="text-align:center; padding:20px;">
                    <div style="font-size:42px; color:#ef4444; margin-bottom:10px;">📄</div>
                    <div style="font-weight:700; font-size:14px; color:var(--text-main);">PDF Document</div>
                    <a href="${fullUrl}" target="_blank" class="btn-primary" style="margin-top:14px; display:inline-block; text-decoration:none; padding:6px 14px; font-size:12px;">View PDF in Tab</a>
                </div>
            `;
        } else {
            modalPreview.innerHTML = `
                <div style="text-align:center; padding:20px;">
                    <div style="font-size:42px; color:var(--text-muted); margin-bottom:10px;">📁</div>
                    <div style="font-weight:700; font-size:14px; color:var(--text-main);">${escapeHtml(item.file_name)}</div>
                </div>
            `;
        }
    }

    // Close Details Modal
    if (closeDetailBtn) {
        closeDetailBtn.addEventListener('click', () => {
            detailModal.style.display = 'none';
            selectedFile = null;
        });
    }

    detailModal.addEventListener('click', (e) => {
        if (e.target === detailModal) {
            detailModal.style.display = 'none';
            selectedFile = null;
        }
    });

    // Copy Relative Path Button
    if (modalCopyRelBtn) {
        modalCopyRelBtn.addEventListener('click', () => {
            if (modalRelInput.value) {
                copyToClipboard(modalRelInput.value, 'Relative path copied!');
            }
        });
    }

    // Copy Full URL Button
    if (modalCopyFullBtn) {
        modalCopyFullBtn.addEventListener('click', () => {
            if (modalFullInput.value) {
                copyToClipboard(modalFullInput.value, 'Full URL copied!');
            }
        });
    }

    // Delete File Handler
    if (modalDeleteBtn) {
        modalDeleteBtn.addEventListener('click', () => {
            if (selectedFile) {
                deleteFile(selectedFile);
            }
        });
    }

    function deleteFile(file) {
        if (!confirm(`Are you sure you want to permanently delete "${file.file_name}"?\nThis action cannot be undone.`)) {
            return;
        }

        const formData = new FormData();
        formData.append('id', file.id);

        fetch(`${apiBasePath}/media_delete.php`, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast('File deleted successfully');
                if (detailModal) detailModal.style.display = 'none';
                selectedFile = null;
                fetchMediaList();
            } else {
                alert(data.message || 'Failed to delete file');
            }
        })
        .catch(err => {
            alert('Server error occurred while deleting file.');
        });
    }

    // 9. Direct File Upload (Drag & Drop + File Input)
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                uploadFilesDirectly(Array.from(this.files));
            }
        });
    }

    if (dropzone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('drag-over');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('drag-over');
            }, false);
        });

        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                uploadFilesDirectly(Array.from(files));
            }
        });
    }

    function uploadFilesDirectly(files) {
        if (!files.length) return;

        uploadProgressWrap.style.display = 'block';
        uploadProgressBar.style.width = '0%';
        uploadPctText.textContent = '0%';
        uploadStatusText.textContent = `Uploading 1 of ${files.length} file(s)...`;

        let completed = 0;
        let successCount = 0;

        files.forEach((file, index) => {
            const formData = new FormData();
            formData.append('media_file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${apiBasePath}/media_upload.php`, true);

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    uploadProgressBar.style.width = percent + '%';
                    uploadPctText.textContent = percent + '%';
                }
            };

            xhr.onload = function() {
                completed++;
                if (xhr.status === 200) {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            successCount++;
                        } else {
                            alert(`Upload error (${file.name}): ` + res.message);
                        }
                    } catch (err) {}
                }

                if (completed < files.length) {
                    uploadStatusText.textContent = `Uploading ${completed + 1} of ${files.length} file(s)...`;
                } else {
                    uploadProgressBar.style.width = '100%';
                    uploadPctText.textContent = '100%';
                    uploadStatusText.textContent = `Uploaded ${successCount} file(s) successfully!`;

                    setTimeout(() => {
                        uploadProgressWrap.style.display = 'none';
                        fileInput.value = '';
                        showToast(`Successfully uploaded ${successCount} file(s)!`);
                        currentPage = 1;
                        fetchMediaList();
                    }, 600);
                }
            };

            xhr.onerror = function() {
                completed++;
                alert(`Upload failed for: ${file.name}`);
            };

            xhr.send(formData);
        });
    }

    // Helper: Copy to Clipboard
    function copyToClipboard(text, msg) {
        navigator.clipboard.writeText(text).then(() => {
            showToast(msg || 'Copied to Clipboard!');
        }).catch(() => {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast(msg || 'Copied to Clipboard!');
        });
    }

    // Helper: Toast
    let toastTimeout = null;
    function showToast(msg) {
        if (!toast) return;
        toastMsg.textContent = msg;
        toast.style.display = 'flex';
        clearTimeout(toastTimeout);
        toastTimeout = setTimeout(() => {
            toast.style.display = 'none';
        }, 2200);
    }

    // Helper: Escape HTML
    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
});
</script>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
