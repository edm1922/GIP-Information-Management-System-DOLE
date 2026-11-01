@once
    @push('scripts')
        <script>
            (function () {
                'use strict';

                document.addEventListener('click', function (event) {
                    var trigger = event.target.closest('[data-reject-document]');
                    if (!trigger) {
                        return;
                    }

                    event.preventDefault();

                    var modalEl = document.getElementById('rejectDocumentModal');
                    var form = document.getElementById('rejectDocumentForm');
                    if (!modalEl || !form) {
                        return;
                    }

                    form.setAttribute('action', trigger.getAttribute('data-reject-action') || '');

                    var context = modalEl.querySelector('[data-reject-context]');
                    if (context) {
                        context.textContent = trigger.getAttribute('data-reject-label') || '';
                    }

                    var textarea = form.querySelector('textarea[name="review_remarks"]');
                    if (textarea) {
                        textarea.value = '';
                        textarea.classList.remove('is-invalid');
                    }

                    if (!window.bootstrap || !window.bootstrap.Modal) {
                        return;
                    }

                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();

                    modalEl.addEventListener('shown.bs.modal', function focusOnce() {
                        if (textarea) {
                            textarea.focus();
                        }
                        modalEl.removeEventListener('shown.bs.modal', focusOnce);
                    });
                });
            })();
        </script>
    @endpush
@endonce

{{-- Rejection modal: the reviewer writes a detailed explanation instead of a one-line reason box. --}}
<div class="modal fade" id="rejectDocumentModal" tabindex="-1" aria-labelledby="rejectDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="rejectDocumentForm">
            @csrf
            @method('PATCH')
            <input type="hidden" name="decision" value="rejected">

            <div class="modal-content">
                <div class="modal-header align-items-start">
                    <div>
                        <h5 class="modal-title mb-0" id="rejectDocumentModalLabel">
                            <i class="fas fa-times-circle text-danger me-2"></i>Reject document
                        </h5>
                        <div class="text-muted small" data-reject-context></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <label class="form-label" for="rejectReviewRemarks">
                        Why is this document being rejected? <span class="text-danger">*</span>
                    </label>
                    <textarea id="rejectReviewRemarks" name="review_remarks" class="form-control" rows="5"
                              maxlength="2000" minlength="10" required
                              placeholder="Explain in detail what is wrong and what the intern needs to correct — e.g. which days do not match the attached DTR, missing pages, unreadable scan, hours exceeding the claimed days…"></textarea>
                    <div class="form-text">
                        The intern receives this explanation in their notification and can read it on their document list.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-1"></i> Reject document
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
