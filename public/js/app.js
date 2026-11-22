// Sidebar Drawer — plain JS, no bundler needed
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('sidebar-toggle');
    var drawer = document.getElementById('sidebar-drawer');
    var backdrop = document.getElementById('sidebar-backdrop');

    if (toggle && drawer && backdrop) {
        function openDrawer() {
            drawer.classList.add('open');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer() {
            drawer.classList.remove('open');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        toggle.addEventListener('click', function () {
            drawer.classList.contains('open') ? closeDrawer() : openDrawer();
        });

        backdrop.addEventListener('click', closeDrawer);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDrawer();
        });
    }
});


// Select2 + flash messages (initialised once the DOM is ready)
$(document).ready(function () {

    // Select2 Initialization
    $('.basic-select').select2({
        width: '100%',
        allowClear: true,
        minimumResultsForSearch: 0,
        escapeMarkup: function (markup) { return markup; }
    });

    $('.tagging-select').select2({
        width: '100%',
        tags: true,
        allowClear: true,
        minimumResultsForSearch: 0
    });

    // Flash Messages Handling (Auto-hide and Manual Close)
    $('.alert, .alert-flash').each(function () {
        const $alert = $(this);

        // Add manual close button if it's not already there
        if ($alert.find('.btn-close-flash').length === 0) {
            const closeBtn = $('<button type="button" class="btn-close-flash" title="Close"><i class="fas fa-times"></i></button>');
            $alert.append(closeBtn);

            // Manual close event
            closeBtn.on('click', function () {
                $alert.fadeOut('fast');
            });
        }
    });

    // Auto-hide after 6 seconds
    setTimeout(function () {
        $('.alert, .alert-flash').fadeOut('slow');
    }, 6000);
});

/**
 * 201 Files View - Scripts
 */

document.addEventListener('alpine:init', () => {
    Alpine.data('statusManager', (initialStatus = '', initialTab = 'employee') => ({
        currentStatus: initialStatus,
        previousStatus: initialStatus,
        activeTab: initialTab,
        modal: null,

        init() {
            // Universal uppercase for inputs with .text-uppercase
            document.querySelectorAll('.text-uppercase').forEach(input => {
                input.addEventListener('input', function () {
                    this.value = this.value.toUpperCase();
                });
            });

            const modalEl = document.getElementById('resignedWarningModal');
            if (modalEl) {
                this.modal = new bootstrap.Modal(modalEl);
            }

            // Listen for Select2 change
            $('#statusSelect').on('change', (e) => {
                this.handleStatusChange(e.target.value);
            });
        },

        handleStatusChange(newStatus) {
            if (newStatus === 'resigned') {
                this.modal.show();
            } else {
                this.previousStatus = newStatus;
                this.currentStatus = newStatus;
            }
        },

        proceedWithResignation() {
            // Just submit the form - the server handles the archive logic
            const form = document.getElementById('employeeForm');
            if (form) form.submit();
        },

        cancelResignation() {
            // Revert the Select2 value
            this.currentStatus = this.previousStatus;
            $('#statusSelect').val(this.previousStatus).trigger('change.select2');
            this.modal.hide();
        }
    }));
});

document.addEventListener('DOMContentLoaded', function () {
    // Sync toolbar company dropdown → hidden form field
    const companySelect = document.getElementById('companySelect');
    if (companySelect) {
        companySelect.addEventListener('change', function () {
            const hiddenField = document.getElementById('companyIdHidden');
            if (hiddenField) hiddenField.value = this.value;
        });
    }

    const companySelectForm = document.getElementById('companySelectForm');
    const availableCodeSelect = document.getElementById('availableCodeSelect');
    const locationSelect = document.getElementById('locationSelectForm');
    const close201Btn = document.getElementById('close201Btn');

    if (close201Btn) {
        const navigationEntries = window.performance && typeof window.performance.getEntriesByType === 'function'
            ? window.performance.getEntriesByType('navigation')
            : [];
        const navigationType = navigationEntries.length > 0 ? navigationEntries[0].type : '';
        const isReload = navigationType === 'reload';
        const isEmployeeProfilePath = /^\/employees\/\d+$/.test(window.location.pathname);

        if (isReload && isEmployeeProfilePath) {
            const baseTarget = close201Btn.getAttribute('href') || '/201files';
            const separator = baseTarget.includes('?') ? '&' : '?';
            window.location.replace(`${baseTarget}${separator}refresh=${Date.now()}`);
            return;
        }
    }

    const initialLocation = locationSelect ? locationSelect.value : '';
    const allLocationOptions = locationSelect
        ? Array.from(locationSelect.options)
            .filter(function (option) { return !!option.value; })
            .map(function (option) {
                return {
                    value: option.value,
                    text: option.textContent,
                    companyId: option.getAttribute('data-company-id') || '',
                    initiallyDisabled: option.getAttribute('data-initial-disabled') === '1',
                    selected: option.selected,
                };
            })
        : [];
    let firstLocationFilterPass = true;

    function getCompanyFolderMap() {
        if (!availableCodeSelect) {
            return {};
        }

        try {
            return JSON.parse(availableCodeSelect.getAttribute('data-company-folders') || '{}');
        } catch (_err) {
            return {};
        }
    }

    function refreshFolderCodeOptions() {
        if (!availableCodeSelect || !companySelectForm) {
            return;
        }

        const selectedCompanyId = String(companySelectForm.value || '');
        const folderMap = JSON.parse(availableCodeSelect.getAttribute('data-company-folders') || '{}');
        const nextCodesMap = JSON.parse(availableCodeSelect.getAttribute('data-company-next-codes') || '{}');
        const lastCodesMap = JSON.parse(availableCodeSelect.getAttribute('data-company-last-codes') || '{}');

        const companyFolders = folderMap[selectedCompanyId] || [];
        const nextCode = nextCodesMap[selectedCompanyId] || '';
        const lastCode = lastCodesMap[selectedCompanyId] || 'None';

        const selectedCompanyOption = companySelectForm.options[companySelectForm.selectedIndex];
        const companyCode = selectedCompanyOption ? (selectedCompanyOption.getAttribute('data-code') || '') : '';
        const folderPrefix = document.querySelector('meta[name="app-folder-prefix"]')?.getAttribute('content') || 'CSC';
        const prefix = companyCode ? `${folderPrefix}-${companyCode.toUpperCase()}-` : `${folderPrefix}-HR-`;

        // Update UI elements
        const prefixSpan = document.getElementById('companyPrefixSpan');
        if (prefixSpan) prefixSpan.textContent = prefix;

        const lastCodeSpan = document.getElementById('lastFolderCodeSpan');
        if (lastCodeSpan) lastCodeSpan.textContent = lastCode;

        // Populate availableCodeSelect
        availableCodeSelect.innerHTML = '<option value="">- Select -</option>';
        companyFolders.forEach(function (folder) {
            const numeric = folder.folder_code.replace(prefix, '');
            const option = document.createElement('option');
            option.value = String(folder.id);
            option.setAttribute('data-numeric', numeric);
            option.textContent = numeric;
            availableCodeSelect.appendChild(option);
        });

        // Toggle visibility of Available Code select and Note
        const availableCodeGroup = document.getElementById('availableCodeGroup');
        const folderNote = document.getElementById('folderNote');
        const hasAvailable = companyFolders.length > 0;

        if (availableCodeGroup) {
            availableCodeGroup.style.setProperty('display', hasAvailable ? 'flex' : 'none', 'important');
        }
        if (folderNote) {
            folderNote.style.setProperty('display', hasAvailable ? 'block' : 'none', 'important');
        }

        // Logic for setting initial/current values
        const hiddenIdField = document.getElementById('folderIdHidden');
        const codeInput = document.getElementById('folderCodeInput');

        if (hiddenIdField && codeInput && !codeInput.getAttribute('data-user-touched')) {
            const currentFolderId = hiddenIdField.value;
            if (currentFolderId) {
                // If we have a folder ID (editing or already selected), try to find it in available
                const matching = companyFolders.find(f => String(f.id) === String(currentFolderId));
                if (matching) {
                    codeInput.value = matching.folder_code.replace(prefix, '');
                    availableCodeSelect.value = currentFolderId;
                }
            } else {
                // Default to next code
                codeInput.value = nextCode.replace(prefix, '');
            }
        }
    }

    if (availableCodeSelect) {
        availableCodeSelect.addEventListener('change', function () {
            const selectedOption = this.options[this.selectedIndex];
            const hiddenIdField = document.getElementById('folderIdHidden');
            const codeInput = document.getElementById('folderCodeInput');

            if (hiddenIdField && codeInput) {
                if (this.value) {
                    hiddenIdField.value = this.value;
                    codeInput.value = selectedOption.getAttribute('data-numeric');
                } else {
                    // Reset to next available
                    const selectedCompanyId = String(companySelectForm.value || '');
                    const nextCodesMap = JSON.parse(availableCodeSelect.getAttribute('data-company-next-codes') || '{}');
                    const nextCode = nextCodesMap[selectedCompanyId] || '';
                    const folderPrefix = document.querySelector('meta[name="app-folder-prefix"]')?.getAttribute('content') || 'CSC';
                    const prefix = (document.getElementById('companyPrefixSpan')?.textContent || `${folderPrefix}-HR-`);

                    hiddenIdField.value = '';
                    codeInput.value = nextCode.replace(prefix, '');
                }
                codeInput.setAttribute('data-user-touched', '1');
            }
        });
    }

    const clearFolderBtn = document.getElementById('clearFolderCode');
    if (clearFolderBtn) {
        clearFolderBtn.addEventListener('click', function () {
            const hiddenIdField = document.getElementById('folderIdHidden');
            const codeInput = document.getElementById('folderCodeInput');
            const selectedCompanyId = String(companySelectForm.value || '');
            const nextCodesMap = JSON.parse(availableCodeSelect.getAttribute('data-company-next-codes') || '{}');
            const nextCode = nextCodesMap[selectedCompanyId] || '';
            const folderPrefix = document.querySelector('meta[name="app-folder-prefix"]')?.getAttribute('content') || 'CSC';
            const prefix = (document.getElementById('companyPrefixSpan')?.textContent || `${folderPrefix}-HR-`);

            if (hiddenIdField) hiddenIdField.value = '';
            if (availableCodeSelect) availableCodeSelect.value = '';
            if (codeInput) {
                codeInput.value = nextCode.replace(prefix, '');
                codeInput.setAttribute('data-user-touched', '1');
            }
        });
    }

    function filterLocationsByCompany() {
        if (!locationSelect || !companySelectForm) {
            return;
        }

        const selectedCompanyId = companySelectForm.value;
        const previousValue = locationSelect.value;
        let hasSelectedVisible = false;

        locationSelect.innerHTML = '<option value=""></option>';

        allLocationOptions.forEach(function (item) {
            const shouldShow = !!selectedCompanyId && item.companyId === selectedCompanyId;
            if (!shouldShow) {
                return;
            }

            const option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.text;
            option.setAttribute('data-company-id', item.companyId);
            option.setAttribute('data-initial-disabled', item.initiallyDisabled ? '1' : '0');

            if (item.value === initialLocation) {
                option.disabled = false;
            } else {
                option.disabled = item.initiallyDisabled;
            }

            if (item.value === previousValue) {
                option.selected = true;
                hasSelectedVisible = true;
            }

            locationSelect.appendChild(option);
        });

        if (selectedCompanyId && !hasSelectedVisible && (!firstLocationFilterPass || previousValue !== initialLocation)) {
            locationSelect.value = '';
        }

        if (window.jQuery && $(locationSelect).hasClass('select2-hidden-accessible')) {
            $(locationSelect).trigger('change.select2');
        }

        firstLocationFilterPass = false;
    }

    function handleCompanySelectionChange(openFolderPicker = false) {
        const codeInput = document.getElementById('folderCodeInput');
        if (codeInput) codeInput.removeAttribute('data-user-touched');

        refreshFolderCodeOptions();
        filterLocationsByCompany();

        if (openFolderPicker && availableCodeSelect && companySelectForm?.value) {
            // No automated opening for the simple select
        }
    }

    if (companySelectForm) {
        companySelectForm.addEventListener('change', function () {
            handleCompanySelectionChange(true);
        });

        if (window.jQuery) {
            $(companySelectForm).on('select2:select select2:clear', function () {
                handleCompanySelectionChange(true);
            });
        }
    }

    refreshFolderCodeOptions();
    filterLocationsByCompany();

    if (close201Btn) {
        close201Btn.addEventListener('click', function (event) {
            event.preventDefault();
            const target = this.getAttribute('href') || '/201files';
            const separator = target.includes('?') ? '&' : '?';
            window.location.replace(`${target}${separator}refresh=${Date.now()}`);
        });
    }

    window.addEventListener('pageshow', function () {
        refreshFolderCodeOptions();
        filterLocationsByCompany();
    });
});
