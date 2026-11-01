@once
    @push('scripts')
        <script src="{{ asset('js/dtr-days-viewer.js') }}?v={{ filemtime(public_path('js/dtr-days-viewer.js')) }}"></script>
    @endpush

    @push('styles')
        <style>
            .dtr-view-month + .dtr-view-month { margin-top: 1.25rem; }
            .dtr-view-month-label { font-weight: 600; font-size: .9rem; margin-bottom: .4rem; }
            .dtr-view-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .25rem; }
            .dtr-view-grid-head { margin-bottom: .2rem; }
            .dtr-view-weekday { text-align: center; font-size: .7rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; }
            .dtr-view-cell {
                aspect-ratio: 1 / 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
                border-radius: .45rem; font-size: .8rem; font-weight: 600; line-height: 1.05;
                background: #f8fafc; color: #94a3b8; border: 1px solid transparent;
            }
            .dtr-view-hours { font-size: .6rem; font-weight: 500; opacity: .9; }
            .dtr-view-cell.is-weekend { background: #f1f5f9; }
            .dtr-view-cell.is-outside { opacity: .2; }
            .dtr-view-cell.is-claimed { background: #dcfce7; color: #15803d; border-color: #86efac; }
            .dtr-view-cell.is-claimed.is-outside-contract { background: #fef3c7; color: #b45309; border-color: #f59e0b; border-style: dashed; }
            .dtr-view-legend { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; color: #64748b; }
            .dtr-view-swatch { width: 12px; height: 12px; border-radius: 3px; border: 1px solid #cbd5e1; display: inline-block; }
        </style>
    @endpush
@endonce

{{-- Shared modal: filled by public/js/dtr-days-viewer.js --}}
<div class="modal fade" id="dtrDaysViewer" tabindex="-1" aria-labelledby="dtrDaysViewerLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="dtrDaysViewerLabel" data-dtr-view-title>Claimed working days</h5>
                    <div class="text-muted small" data-dtr-view-meta></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <span class="dtr-view-legend">
                        <span class="dtr-view-swatch" style="background: #dcfce7; border-color: #86efac;"></span>
                        Claimed day
                    </span>
                    <span class="dtr-view-legend">
                        <span class="dtr-view-swatch" style="background: #fef3c7; border-color: #f59e0b; border-style: dashed;"></span>
                        Outside contract
                    </span>
                    <span class="dtr-view-legend">
                        <span class="dtr-view-swatch" style="background: #f1f5f9;"></span>
                        Weekend
                    </span>
                </div>

                <div data-dtr-view-body></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
