{{-- ── In-System Document Preview Overlay (Google Drive style) ── --}}
<div class="preview-overlay" id="document-preview-overlay" hidden>
    <div class="preview-overlay__backdrop" data-preview-close></div>
    
    <div class="preview-overlay__toolbar">
        <div class="preview-overlay__toolbar-left">
            <button type="button" class="preview-overlay__btn" data-preview-close aria-label="Close preview" title="Close (Esc)">
                <i class="fas fa-times"></i>
            </button>
            <span class="preview-overlay__filename" id="document-preview-title">Document Preview</span>
        </div>
        
        <div class="preview-overlay__toolbar-right">
            <a href="#" class="preview-overlay__btn" id="preview-download-btn" title="Download Document" download>
                <i class="fas fa-download"></i>
            </a>
            <button type="button" class="preview-overlay__btn" id="preview-print-btn" title="Print Document">
                <i class="fas fa-print"></i>
            </button>
            <a href="#" class="preview-overlay__btn" id="preview-external-btn" target="_blank" rel="noopener" title="Open in new tab">
                <i class="fas fa-external-link-alt"></i>
            </a>
        </div>
    </div>

    <div class="preview-overlay__content" data-preview-close>
        <div class="preview-overlay__body" id="document-preview-body" onclick="event.stopPropagation();">
            <div class="preview-overlay__loading">
                <div class="spinner-border spinner-border-sm text-light me-2" role="status"></div>
                Loading document preview...
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const overlay = document.getElementById('document-preview-overlay');
    const titleEl = document.getElementById('document-preview-title');
    const bodyEl = document.getElementById('document-preview-body');
    const downloadBtn = document.getElementById('preview-download-btn');
    const printBtn = document.getElementById('preview-print-btn');
    const externalBtn = document.getElementById('preview-external-btn');

    if (!overlay) return;

    let currentUrl = '';
    let currentName = '';
    let currentKind = '';

    const escapeHtml = (str) => String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const getFileKind = (filename) => {
        const ext = (filename || '').split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'].includes(ext)) return 'image';
        if (ext === 'pdf') return 'pdf';
        if (['doc', 'docx'].includes(ext)) return 'docx';
        if (['xls', 'xlsx', 'csv'].includes(ext)) return 'sheet';
        return 'other';
    };

    const closePreview = () => {
        overlay.hidden = true;
        document.body.classList.remove('preview-overlay-open');
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
        currentUrl = '';
    };

    const openPreview = (url, name, downloadUrl) => {
        if (!url) return;
        currentUrl = url;
        currentName = name || 'Document Preview';
        currentKind = getFileKind(currentName);

        if (titleEl) titleEl.textContent = currentName;

        const effectiveDownload = downloadUrl || url;
        if (downloadBtn) {
            downloadBtn.href = effectiveDownload;
            downloadBtn.setAttribute('download', currentName);
        }
        if (externalBtn) {
            externalBtn.href = url;
        }

        overlay.hidden = false;
        document.body.classList.add('preview-overlay-open');

        renderContent(url, currentName, currentKind, effectiveDownload);
    };

    const renderContent = (url, name, kind, downloadUrl) => {
        if (!bodyEl) return;
        const safeUrl = encodeURI(url);
        const safeName = escapeHtml(name);
        const ext = name.split('.').pop().toUpperCase();

        if (kind === 'pdf') {
            bodyEl.innerHTML = `
                <div style="position:relative; width:100%; height:calc(100vh - 120px);">
                    <div id="pdf-spinner" class="preview-overlay__loading" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%);">
                        <div class="spinner-border spinner-border-sm text-light me-2" role="status"></div>
                        Rendering PDF...
                    </div>
                    <iframe src="${safeUrl}#toolbar=1" 
                            class="preview-overlay__iframe" 
                            style="width:100%; height:100%; border:none; border-radius:6px; background:#fff;" 
                            title="${safeName}"
                            onload="const s = document.getElementById('pdf-spinner'); if(s) s.style.display='none';">
                    </iframe>
                </div>
            `;
            return;
        }

        if (kind === 'image') {
            bodyEl.innerHTML = `
                <div style="display:flex; justify-content:center; align-items:center; min-height:60vh; padding:12px;">
                    <img src="${safeUrl}" 
                         alt="${safeName}" 
                         class="preview-overlay__image" 
                         style="max-height:calc(100vh - 130px); max-width:100%; object-fit:contain; border-radius:6px; box-shadow:0 4px 20px rgba(0,0,0,0.5);">
                </div>
            `;
            return;
        }

        // Fallback for Office / unsupported in-browser files
        const iconClass = kind === 'docx' ? 'fa-file-word text-primary' 
                        : (kind === 'sheet' ? 'fa-file-excel text-success' : 'fa-file-alt text-secondary');
        
        bodyEl.innerHTML = `
            <div class="preview-overlay__unsupported" style="max-width:540px; margin:40px auto; padding:32px 24px; border-radius:12px; background:#202124; text-align:center; color:#fff; box-shadow:0 8px 30px rgba(0,0,0,0.4);">
                <div class="mb-3">
                    <i class="fas ${iconClass} fa-4x"></i>
                </div>
                <h5 class="fw-bold mb-1">${safeName}</h5>
                <p class="text-white-50 small mb-4">
                    This file format (<strong>.${ext}</strong>) cannot be rendered directly inline by the browser. You can download and open it with your desktop application.
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="${downloadUrl || safeUrl}" class="btn btn-primary px-4 py-2" download="${safeName}">
                        <i class="fas fa-download me-1"></i> Download File
                    </a>
                </div>
            </div>
        `;
    };

    // Print action
    if (printBtn) {
        printBtn.addEventListener('click', () => {
            if (!currentUrl) return;
            if (currentKind === 'pdf') {
                const iframe = bodyEl?.querySelector('iframe');
                if (iframe && iframe.contentWindow) {
                    try {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                        return;
                    } catch (e) {
                        // Fallback below
                    }
                }
            }
            const printWin = window.open(currentUrl, '_blank');
            if (printWin) {
                printWin.addEventListener('load', () => {
                    printWin.print();
                });
            }
        });
    }

    // Close on backdrop or close button
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-preview-close]')) {
            closePreview();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !overlay.hidden) {
            closePreview();
        }
    });

    // Global delegation for preview triggers
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-preview-url], .preview-doc-trigger, .preview-doc-btn');
        if (!trigger) return;

        e.preventDefault();
        const url = trigger.getAttribute('data-preview-url') || trigger.dataset.previewUrl;
        const name = trigger.getAttribute('data-preview-name') || trigger.dataset.previewName;
        const downloadUrl = trigger.getAttribute('data-preview-download') || trigger.dataset.previewDownload;

        if (url) {
            openPreview(url, name, downloadUrl);
        }
    });

    // Expose global API
    window.openDocumentPreview = openPreview;
    window.closeDocumentPreview = closePreview;
})();
</script>
