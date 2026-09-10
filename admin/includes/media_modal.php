<?php
/**
 * Universal Media Library Popup Modal Component
 */
?>
<!-- Media Library Modal -->
<div id="universal-media-modal" class="media-modal-backdrop" style="display:none;">
    <div class="media-modal-box">
        <div class="media-modal-header">
            <div style="display:flex; align-items:center; gap:16px;">
                <h3 class="media-modal-title">Media Library</h3>
                <div class="media-modal-tabs">
                    <button type="button" class="media-tab-btn active" data-tab="tab-library">Choose Existing</button>
                    <button type="button" class="media-tab-btn" data-tab="tab-upload">Upload New File</button>
                </div>
            </div>
            <button type="button" class="media-modal-close" id="close-media-modal-btn" title="Close modal">&times;</button>
        </div>

        <div class="media-modal-body">
            <!-- Tab 1: Library Grid -->
            <div id="tab-library" class="media-tab-content active">
                <div class="media-toolbar">
                    <div style="display:flex; gap:10px; align-items:center;">
                        <select id="media-filter-type" class="form-select" style="width:160px; padding:7px 12px; font-size:12.5px;">
                            <option value="all">All Media</option>
                            <option value="image">Images (PNG, JPG, WebP)</option>
                            <option value="audio">Audio (MP3, M4A, WAV)</option>
                            <option value="pdf">PDF Documents</option>
                            <option value="video">Videos (MP4, WebM)</option>
                        </select>
                        <input type="text" id="media-search-input" class="form-control" placeholder="Search by file name..." style="width:240px; padding:7px 12px; font-size:12.5px;">
                    </div>
                    <div style="font-size:12px; color:var(--text-dim);" id="media-count-label">Loading files...</div>
                </div>

                <div class="media-main-layout">
                    <!-- Left: Grid of media thumbnails -->
                    <div class="media-grid-wrap" id="media-items-grid">
                        <!-- Loaded dynamically via JS -->
                    </div>

                    <!-- Right: File Details & Action Panel -->
                    <div class="media-details-panel" id="media-details-panel" style="display:none;">
                        <div class="media-preview-box" id="media-detail-preview"></div>
                        <div class="media-detail-meta">
                            <div class="media-detail-name" id="media-detail-name"></div>
                            <div class="media-detail-info" id="media-detail-info"></div>
                            <div class="media-detail-url">
                                <input type="text" id="media-detail-url-input" class="form-control" readonly style="font-size:11px; padding:6px 10px;">
                                <button type="button" class="btn-sm action-btn" id="media-copy-url-btn" title="Copy URL" style="margin-top:6px; width:100%;">
                                    Copy Direct URL
                                </button>
                            </div>
                        </div>
                        <div style="margin-top:auto; display:flex; flex-direction:column; gap:10px;">
                            <button type="button" class="btn-primary" id="media-select-and-insert-btn" style="padding:10px 16px;">
                                Use Selected File
                            </button>
                            <button type="button" class="action-btn delete-btn" id="media-delete-file-btn" style="width:100%; font-size:12px; height:34px;">
                                Delete Permanently
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Drag & Drop Upload Zone -->
            <div id="tab-upload" class="media-tab-content">
                <div class="media-dropzone" id="media-dropzone">
                    <div class="dropzone-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                    </div>
                    <div class="dropzone-title">Drag & Drop your files here</div>
                    <div class="dropzone-sub">Supports Images (PNG, JPG, WebP, SVG), Audio (MP3, M4A), PDF, Video (MP4) up to 50MB</div>
                    <label class="btn-primary" style="width:auto; padding:10px 24px; cursor:pointer; margin-top:16px;">
                        <span>Browse From Computer</span>
                        <input type="file" id="media-file-browser-input" multiple style="display:none;" accept="image/*,audio/*,video/*,application/pdf">
                    </label>
                    <div id="upload-progress-container" style="display:none; width:100%; max-width:400px; margin-top:20px;">
                        <div style="font-size:12px; color:var(--text-muted); margin-bottom:6px;" id="upload-status-text">Uploading...</div>
                        <div style="width:100%; height:6px; background:var(--bg-input); border-radius:3px; overflow:hidden;">
                            <div id="upload-progress-bar" style="width:0%; height:100%; background:var(--primary-gradient); transition:width 0.2s;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
