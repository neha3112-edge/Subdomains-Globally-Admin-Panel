<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_login();
require_permission('media');

$page_title = 'Media Library';
$page_subtitle = 'Upload, manage, and copy links for images, audios, and documents';
$active_page_key = 'media';

require_once ADMIN_PATH . '/includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <span class="section-heading-sm" style="margin-bottom:0;">Uploaded Assets & Files</span>
    </div>
    <button type="button" class="btn-primary btn-sm media-picker-btn" style="padding:10px 18px; font-size:13.5px; width:auto;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Upload & Media Library
    </button>
</div>

<div class="admin-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span class="card-title">All Files</span>
        <button type="button" class="btn-primary btn-sm media-picker-btn" style="width:auto; padding:6px 14px;">
            Open Media Modal
        </button>
    </div>
    <div class="card-body" style="padding:40px; text-align:center;">
        <div style="max-width:500px; margin:0 auto;">
            <div style="width:64px; height:64px; border-radius:50%; background:var(--bg-input); display:flex; align-items:center; justify-content:center; margin:0 auto 16px; color:var(--primary);">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
            </div>
            <h3 style="font-size:18px; font-weight:700; color:var(--text-main); margin-bottom:8px;">Centralized Asset Management</h3>
            <p style="font-size:13px; color:var(--text-dim); line-height:1.6; margin-bottom:20px;">
                You can upload high-resolution university logos, desktop/mobile hero banners, podcast audios (MP3), admission brochures (PDF), and YouTube embeds directly into the system.
            </p>
            <button type="button" class="btn-primary media-picker-btn" style="padding:12px 28px; width:auto; margin:0 auto;">
                Launch Media Manager
            </button>
        </div>
    </div>
</div>

<?php
require_once ADMIN_PATH . '/includes/footer.php';
?>
