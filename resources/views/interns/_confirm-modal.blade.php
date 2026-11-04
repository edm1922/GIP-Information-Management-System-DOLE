{{-- Generic confirmation modal for intern actions (archive / permanent delete). --}}
<div class="modal fade" id="internConfirmModal" tabindex="-1" aria-labelledby="internConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="#" id="internConfirmForm">
                @csrf
                <input type="hidden" name="_method" value="DELETE" id="internConfirmMethod">
                <div class="modal-header">
                    <h5 class="modal-title" id="internConfirmTitle">Confirm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="internConfirmBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" id="internConfirmSubmit">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('internConfirmModal');
        if (!modal) {
            return;
        }

        modal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const form = document.getElementById('internConfirmForm');
            const methodField = document.getElementById('internConfirmMethod');

            if (button) {
                form.setAttribute('action', button.getAttribute('data-action') || '#');
                methodField.value = button.getAttribute('data-method') || 'DELETE';
                document.getElementById('internConfirmTitle').textContent = button.getAttribute('data-title') || 'Confirm';
                document.getElementById('internConfirmBody').textContent = button.getAttribute('data-message') || '';
                document.getElementById('internConfirmSubmit').textContent = button.getAttribute('data-submit') || 'Confirm';
            }
        });
    });
</script>
