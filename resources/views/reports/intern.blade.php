<x-app-layout>
    <div class="animate-fade-in stagger-1">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="h4 mb-1 fw-bold text-dark">Internship Reports</h2>
                <p class="text-muted mb-0" style="font-size: 0.85rem;">Export GIP / internship datasets as CSV.</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="alert-flash alert-flash--error mb-4">
                <i class="fas fa-exclamation-triangle me-2"></i>{{ $errors->first() }}
            </div>
        @endif

        <div class="row g-4">
            <div class="col-12 mt-3">
                <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                    <i class="fas fa-user-graduate text-brand"></i> Internship Program
                    <hr class="flex-grow-1 ms-2 opacity-10">
                </h5>
            </div>

            {{-- ── Intern Master List ── --}}
            <div class="col-lg-6 animate-fade-in stagger-2">
                <div class="card report-card h-100 shadow-sm border-0">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-circle bg-brand-light text-brand me-3">
                                <i class="fas fa-list"></i>
                            </div>
                            <div>
                                <h5 class="report-title">Intern Master List</h5>
                                <p class="report-desc">Full roster with placement, contract &amp; hours.</p>
                            </div>
                        </div>

                        <form action="{{ route('reports.export-interns') }}" method="GET" data-report-export-form>
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <label class="report-label">Status</label>
                                    <select name="status" class="form-select report-input">
                                        <option value="">All Statuses</option>
                                        @foreach (\App\Models\Intern::STATUSES as $status)
                                            <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="report-label">Contract</label>
                                    <select name="expiring" class="form-select report-input">
                                        <option value="all">All</option>
                                        <option value="soon">Expiring &le; 30 days</option>
                                        <option value="expired">Expired</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="report-label">Partner Agency</label>
                                    <select name="partner_agency_id" class="form-select report-input">
                                        <option value="">All Agencies</option>
                                        @foreach ($agencies as $agency)
                                            <option value="{{ $agency->id }}">{{ $agency->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="mt-auto">
                                <button type="submit"
                                    class="btn btn-brand w-100 py-2 d-flex align-items-center justify-content-center"
                                    data-export-submit>
                                    <i class="fas fa-file-csv me-2"></i>
                                    <span class="fw-bold" data-export-label>Download Master List</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ── Hours Summary ── --}}
            <div class="col-lg-6 animate-fade-in stagger-3">
                <div class="card report-card h-100 shadow-sm border-0">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-circle bg-success-light text-success me-3">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <h5 class="report-title">Hours Monitoring Summary</h5>
                                <p class="report-desc">Rendered vs. required hours with totals.</p>
                            </div>
                        </div>

                        <form action="{{ route('reports.export-intern-hours') }}" method="GET"
                            class="d-flex flex-column h-100" data-report-export-form>
                            <div class="mb-4">
                                <label class="report-label">Status</label>
                                <select name="status" class="form-select report-input">
                                    <option value="">All Statuses</option>
                                    @foreach (\App\Models\Intern::STATUSES as $status)
                                        <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <p class="text-muted small mb-4">Per-intern hours rendered, remaining, and completion
                                percentage, with a system-wide total row.</p>
                            <div class="mt-auto">
                                <button type="submit"
                                    class="btn btn-success w-100 py-2 fw-bold d-flex align-items-center justify-content-center shadow-sm"
                                    data-export-submit>
                                    <i class="fas fa-file-csv me-2"></i>
                                    <span data-export-label>Download Hours Summary</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- ── Agency Summary ── --}}
            <div class="col-lg-6 animate-fade-in stagger-4">
                <div class="card report-card h-100 shadow-sm border-0">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-circle bg-brand-light text-brand me-3">
                                <i class="fas fa-handshake"></i>
                            </div>
                            <div>
                                <h5 class="report-title">Partner Agency Summary</h5>
                                <p class="report-desc">Intern headcount &amp; hours per partner agency.</p>
                            </div>
                        </div>
                        <p class="text-muted small mb-4">Aggregates intern counts by status and total monitored hours
                            for every partner agency.</p>
                        <div class="mt-auto">
                            <a href="{{ route('reports.export-intern-agencies') }}"
                                class="btn btn-brand w-100 py-2 fw-bold d-flex align-items-center justify-content-center shadow-sm">
                                <i class="fas fa-file-csv me-2"></i>
                                <span>Export Agency Summary</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Contract Expiry ── --}}
            <div class="col-lg-6 animate-fade-in stagger-5">
                <div class="card report-card h-100 shadow-sm border-0">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center mb-1">
                            <h5 class="report-title fs-5 mb-0">Contract Expiry</h5>
                            <span class="badge bg-secondary ms-auto fw-medium">Monitoring</span>
                        </div>
                        <p class="report-desc mb-4">Interns grouped by contract expiry timeline.</p>

                        <form action="{{ route('reports.export-intern-expiry') }}" method="GET"
                            class="d-flex flex-column h-100" data-report-export-form>
                            <div class="mb-4">
                                <label class="report-label">Filter by Status</label>
                                <select name="expiry_status" class="form-select report-input">
                                    <option value="all">All Contracts</option>
                                    <option value="expired">Already Expired</option>
                                    <option value="expiring">Expiring Soon (Next 30 Days)</option>
                                    <option value="valid">Valid (&gt; 30 Days)</option>
                                </select>
                            </div>
                            <div class="mt-auto">
                                <button type="submit"
                                    class="btn btn-secondary w-100 py-2 d-flex align-items-center justify-content-center text-white"
                                    data-export-submit>
                                    <i class="fas fa-hourglass-half me-2"></i>
                                    <span class="fw-bold" data-export-label>Download Expiry List</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .report-card {
            border-radius: 12px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .report-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08) !important;
        }

        .icon-circle {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .bg-brand-light {
            background: var(--company-primary-light);
        }

        .bg-success-light {
            background: rgba(16, 185, 129, 0.1);
        }

        .report-title {
            color: #1e293b;
            font-weight: 700;
            font-size: 1.05rem;
            margin-bottom: 2px;
        }

        .report-desc {
            color: #64748b;
            font-size: 0.8rem;
            margin-bottom: 0;
        }

        .report-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 5px;
        }

        .report-input {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.85rem;
            background-color: #f8fafc;
            padding: 8px 12px;
        }

        .report-input:focus {
            background-color: #fff;
            border-color: {{ config('brand.primary_color') }};
            box-shadow: 0 0 0 3px var(--company-primary-light);
        }
    </style>

    @push('scripts')
        <script>
            (() => {
                document.querySelectorAll('form[data-report-export-form]').forEach((form) => {
                    if (form.dataset.exportBound === '1') return;
                    form.dataset.exportBound = '1';

                    form.addEventListener('submit', () => {
                        const button = form.querySelector('[data-export-submit]');
                        const label = form.querySelector('[data-export-label]');
                        if (!button || !label || button.disabled) return;

                        button.disabled = true;
                        button.dataset.originalLabel = label.textContent.trim();
                        label.textContent = 'Preparing CSV...';

                        const spinner = document.createElement('span');
                        spinner.className = 'spinner-border spinner-border-sm me-2';
                        spinner.dataset.exportSpinner = '1';
                        button.insertBefore(spinner, button.firstChild);

                        setTimeout(() => {
                            button.disabled = false;
                            label.textContent = button.dataset.originalLabel;
                            const s = button.querySelector('[data-export-spinner]');
                            if (s) s.remove();
                        }, 5000);
                    });
                });
            })();
        </script>
    @endpush
</x-app-layout>
