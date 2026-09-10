/**
 * Universal Media Library & Picker JavaScript
 */

(function() {
    let currentTargetInputId = null;
    let currentPreviewTargetId = null;
    let selectedMediaItem = null;
    let mediaItems = [];

    const modal = document.getElementById('universal-media-modal');
    if (!modal) return;

    const closeBtn = document.getElementById('close-media-modal-btn');
    const tabBtns = document.querySelectorAll('.media-tab-btn');
    const tabContents = document.querySelectorAll('.media-tab-content');
    const gridContainer = document.getElementById('media-items-grid');
    const detailsPanel = document.getElementById('media-details-panel');
    const countLabel = document.getElementById('media-count-label');
    const filterType = document.getElementById('media-filter-type');
    const searchInput = document.getElementById('media-search-input');
    const fileBrowserInput = document.getElementById('media-file-browser-input');
    const dropzone = document.getElementById('media-dropzone');
    const uploadProgressBox = document.getElementById('upload-progress-container');
    const uploadProgressBar = document.getElementById('upload-progress-bar');
    const uploadStatusText = document.getElementById('upload-status-text');
    const selectAndInsertBtn = document.getElementById('media-select-and-insert-btn');
    const copyUrlBtn = document.getElementById('media-copy-url-btn');
    const deleteFileBtn = document.getElementById('media-delete-file-btn');

    // 1. Tab Switching
    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            const tabId = this.dataset.tab;
            const targetContent = document.getElementById(tabId);
            if (targetContent) targetContent.classList.add('active');
        });
    });

    // 2. Open Modal trigger listener (delegated)
    document.addEventListener('click', function(e) {
        const pickerBtn = e.target.closest('.media-picker-btn, [data-media-target]');
        if (pickerBtn) {
            e.preventDefault();
            currentTargetInputId = pickerBtn.dataset.mediaTarget || pickerBtn.dataset.target;
            currentPreviewTargetId = pickerBtn.dataset.mediaPreview || pickerBtn.dataset.preview;
            const prefillType = pickerBtn.dataset.mediaType || 'all';
            if (filterType) filterType.value = prefillType;
            openMediaModal();
        }
    });

    // 3. Close Modal
    if (closeBtn) closeBtn.addEventListener('click', closeMediaModal);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeMediaModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.style.display !== 'none') {
            closeMediaModal();
        }
    });

    function openMediaModal() {
        modal.style.display = 'flex';
        // Default to Library tab
        const libTabBtn = document.querySelector('.media-tab-btn[data-tab="tab-library"]');
        if (libTabBtn) libTabBtn.click();
        loadMediaList();
    }

    function closeMediaModal() {
        modal.style.display = 'none';
        selectedMediaItem = null;
        if (detailsPanel) detailsPanel.style.display = 'none';
    }

    // 4. Fetch Media Items
    let searchTimeout = null;
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(loadMediaList, 300);
        });
    }

    if (filterType) {
        filterType.addEventListener('change', loadMediaList);
    }

    function loadMediaList() {
        if (!gridContainer) return;
        const type = filterType ? filterType.value : 'all';
        const q = searchInput ? searchInput.value.trim() : '';

        gridContainer.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:40px; color:var(--text-dim);">Loading media files...</div>';

        const url = `${window.location.origin}${window.location.pathname.replace(/\/modules\/.*|\/change_password\.php|\/dashboard\.php|\/login\.php|\/index\.php/, '')}/api/media_list.php?type=${encodeURIComponent(type)}&q=${encodeURIComponent(q)}`;

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    gridContainer.innerHTML = `<div style="grid-column:1/-1; color:var(--danger); padding:20px;">${data.message || 'Error loading files'}</div>`;
                    return;
                }

                mediaItems = data.items || [];
                if (countLabel) countLabel.textContent = `${data.total} item(s) found`;

                if (mediaItems.length === 0) {
                    gridContainer.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:50px; color:var(--text-dim);">No files uploaded yet. Click "Upload New File" above.</div>';
                    if (detailsPanel) detailsPanel.style.display = 'none';
                    return;
                }

                renderMediaGrid(mediaItems);
            })
            .catch(err => {
                gridContainer.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:30px; color:var(--danger);">Failed to load library items.</div>';
            });
    }

    // 5. Render Grid Cards
    function renderMediaGrid(items) {
        gridContainer.innerHTML = '';
        items.forEach(item => {
            const card = document.createElement('div');
            card.className = 'media-card-item';
            card.dataset.id = item.id;

            let iconHtml = '';
            const previewUrl = item.display_url || item.file_url;
            if (item.file_type === 'image') {
                iconHtml = `<img src="${previewUrl}" alt="${item.file_name}" class="media-thumb-img" loading="lazy">`;
            } else if (item.file_type === 'audio') {
                iconHtml = `<div class="media-type-icon audio-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle></svg></div>`;
            } else if (item.file_type === 'pdf') {
                iconHtml = `<div class="media-type-icon pdf-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><text x="7" y="17" font-size="6" font-weight="bold" fill="currentColor">PDF</text></svg></div>`;
            } else if (item.file_type === 'video') {
                iconHtml = `<div class="media-type-icon video-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect><line x1="7" y1="2" x2="7" y2="22"></line><line x1="17" y1="2" x2="17" y2="22"></line><line x1="2" y1="12" x2="22" y2="12"></line><line x1="2" y1="7" x2="7" y2="7"></line><line x1="2" y1="17" x2="7" y2="17"></line><line x1="17" y1="17" x2="22" y2="17"></line><line x1="17" y1="7" x2="22" y2="7"></line></svg></div>`;
            } else {
                iconHtml = `<div class="media-type-icon doc-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg></div>`;
            }

            card.innerHTML = `
                <div class="media-thumb-box">${iconHtml}</div>
                <div class="media-card-title" title="${item.file_name}">${item.file_name}</div>
            `;

            card.addEventListener('click', function() {
                document.querySelectorAll('.media-card-item').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                showMediaDetails(item);
            });

            // Double click to directly insert
            card.addEventListener('dblclick', function() {
                useMediaItem(item);
            });

            gridContainer.appendChild(card);
        });
    }

    // 6. Show Details in Right Sidebar
    function showMediaDetails(item) {
        selectedMediaItem = item;
        if (!detailsPanel) return;
        detailsPanel.style.display = 'flex';

        const previewBox = document.getElementById('media-detail-preview');
        const nameEl = document.getElementById('media-detail-name');
        const infoEl = document.getElementById('media-detail-info');
        const urlInput = document.getElementById('media-detail-url-input');

        const previewUrl = item.display_url || item.file_url;
        const insertPath = item.file_path || item.file_url;

        if (nameEl) nameEl.textContent = item.file_name;
        if (infoEl) infoEl.innerHTML = `${item.formatted_size} &bull; ${item.file_type.toUpperCase()} &bull; ${item.created_at}`;
        if (urlInput) urlInput.value = insertPath;

        if (previewBox) {
            if (item.file_type === 'image') {
                previewBox.innerHTML = `<img src="${previewUrl}" alt="preview" style="max-height:160px; max-width:100%; border-radius:6px; object-fit:contain;">`;
            } else if (item.file_type === 'audio') {
                previewBox.innerHTML = `<audio controls style="width:100%; margin-top:10px;"><source src="${previewUrl}" type="${item.mime_type}">Your browser does not support audio.</audio>`;
            } else if (item.file_type === 'video') {
                previewBox.innerHTML = `<video controls style="max-height:150px; max-width:100%; border-radius:6px;"><source src="${previewUrl}">Your browser does not support video.</video>`;
            } else {
                previewBox.innerHTML = `<div style="padding:20px; font-weight:700; color:var(--text-muted);">${item.file_name}</div>`;
            }
        }
    }

    // 7. Select & Insert Button
    if (selectAndInsertBtn) {
        selectAndInsertBtn.addEventListener('click', function() {
            if (selectedMediaItem) {
                useMediaItem(selectedMediaItem);
            }
        });
    }

    function useMediaItem(item) {
        const insertPath = item.file_path || item.file_url;
        const previewUrl = item.display_url || item.file_url;

        if (currentTargetInputId) {
            const targetInput = document.getElementById(currentTargetInputId);
            if (targetInput) {
                targetInput.value = insertPath;
                targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                targetInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        if (currentPreviewTargetId) {
            const previewEl = document.getElementById(currentPreviewTargetId);
            if (previewEl) {
                if (item.file_type === 'image') {
                    previewEl.innerHTML = `<img src="${previewUrl}" alt="thumb" style="height:38px; width:38px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">`;
                    previewEl.style.display = 'block';
                } else {
                    previewEl.innerHTML = `<span class="badge badge-info" style="font-size:11px;">${item.file_type.toUpperCase()} Attached</span>`;
                    previewEl.style.display = 'block';
                }
            }
        }

        closeMediaModal();
    }

    // 8. Copy Direct URL
    if (copyUrlBtn) {
        copyUrlBtn.addEventListener('click', function() {
            const urlInput = document.getElementById('media-detail-url-input');
            if (urlInput && urlInput.value) {
                navigator.clipboard.writeText(urlInput.value).then(() => {
                    const origText = copyUrlBtn.textContent;
                    copyUrlBtn.textContent = 'Copied to Clipboard!';
                    setTimeout(() => copyUrlBtn.textContent = origText, 2000);
                });
            }
        });
    }

    // 9. Delete File Action
    if (deleteFileBtn) {
        deleteFileBtn.addEventListener('click', function() {
            if (!selectedMediaItem) return;
            if (!confirm(`Are you sure you want to delete "${selectedMediaItem.file_name}"?`)) return;

            const deleteUrl = `${window.location.origin}${window.location.pathname.replace(/\/modules\/.*|\/change_password\.php|\/dashboard\.php|\/login\.php|\/index\.php/, '')}/api/media_delete.php`;

            const formData = new FormData();
            formData.append('id', selectedMediaItem.id);

            fetch(deleteUrl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    selectedMediaItem = null;
                    if (detailsPanel) detailsPanel.style.display = 'none';
                    loadMediaList();
                } else {
                    alert(data.message || 'Failed to delete file');
                }
            });
        });
    }

    // 10. File Upload Handling (Drag & Drop + File Browser)
    if (fileBrowserInput) {
        fileBrowserInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                uploadFiles(Array.from(this.files));
            }
        });
    }

    if (dropzone) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('drag-over');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('drag-over');
            }, false);
        });

        dropzone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files && files.length > 0) {
                uploadFiles(Array.from(files));
            }
        });
    }

    function uploadFiles(files) {
        if (!files.length) return;

        if (uploadProgressBox) uploadProgressBox.style.display = 'block';
        if (uploadProgressBar) uploadProgressBar.style.width = '0%';
        if (uploadStatusText) uploadStatusText.textContent = `Uploading 1 of ${files.length} file(s)...`;

        const uploadUrl = `${window.location.origin}${window.location.pathname.replace(/\/modules\/.*|\/change_password\.php|\/dashboard\.php|\/login\.php|\/index\.php/, '')}/api/media_upload.php`;

        let completed = 0;
        let lastUploadedItem = null;

        files.forEach((file, index) => {
            const formData = new FormData();
            formData.append('media_file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', uploadUrl, true);

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable && uploadProgressBar) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    uploadProgressBar.style.width = percent + '%';
                }
            };

            xhr.onload = function() {
                completed++;
                if (xhr.status === 200) {
                    try {
                        const res = JSON.parse(xhr.responseText);
                        if (res.success) {
                            lastUploadedItem = res;
                        } else {
                            alert(`Upload error for ${file.name}: ${res.message}`);
                        }
                    } catch (err) {}
                }

                if (completed === files.length) {
                    setTimeout(() => {
                        if (uploadProgressBox) uploadProgressBox.style.display = 'none';
                        if (fileBrowserInput) fileBrowserInput.value = '';
                        // Switch to Library tab and reload
                        const libTabBtn = document.querySelector('.media-tab-btn[data-tab="tab-library"]');
                        if (libTabBtn) libTabBtn.click();
                        loadMediaList();
                    }, 500);
                }
            };

            xhr.onerror = function() {
                completed++;
                alert(`Upload failed for ${file.name}`);
            };

            xhr.send(formData);
        });
    }
})();
