<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h2 class="h4 mb-0 fw-bold">Dashboard</h2>
        <small>Welcome to the {{ config('brand.name') }}</small></div>
        <div class="d-flex align-items-center gap-3">
            <div class="calendar-wrapper ms-auto" x-data="{ open: false }">
                <div class="date-pill" @click="open = !open" title="{{ $isCurrentMonth ? 'Today' : 'Showing ' . $calendarMonthName . ' ' . $calendarYear }}">
                    <i class="fas fa-calendar-alt me-2" style="color: {{ config('brand.primary_color') }} !important;"></i>
                    <span>{{ date('M d, Y', strtotime($pillDate)) }}</span>
                </div>

                <div class="calendar-dropdown" x-show="open" @click.away="open = false" x-transition x-cloak>
                    <div class="calendar-nav">
                        <a href="{{ route('dashboard', ['cal_date' => date('Y-m-d', strtotime('-1 month', strtotime($calendarDate))), 'year' => $year, 'month' => $month]) }}" class="calendar-nav-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <h4>{{ $calendarMonthName }} {{ $calendarYear }}</h4>
                        <a href="{{ route('dashboard', ['cal_date' => date('Y-m-d', strtotime('+1 month', strtotime($calendarDate))), 'year' => $year, 'month' => $month]) }}" class="calendar-nav-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>

                    <div class="calendar-grid">
                        <div class="calendar-weekday">Su</div>
                        <div class="calendar-weekday">Mo</div>
                        <div class="calendar-weekday">Tu</div>
                        <div class="calendar-weekday">We</div>
                        <div class="calendar-weekday">Th</div>
                        <div class="calendar-weekday">Fr</div>
                        <div class="calendar-weekday">Sa</div>

                        @foreach($calendarData as $day)
                            <div class="calendar-day {{ $day['current_month'] ? 'current' : 'other' }} {{ $day['today'] ? 'today' : '' }}">
                                {{ $day['day'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Initialize external JS variables -->
    <script>
        window.dashboardData = {
            internsTrend: @json($internsTrend),
            internStatusDistribution: @json($internStatusDistribution),
            partnerAgencyDistribution: @json($partnerAgencyDistribution)
        };
    </script>

    @if($showInternStats)
    <div class="row g-4">
        {{-- Internship Program Stats --}}
        <div class="col-12">
            <div class="stat-cards-frame">
                <div class="d-flex align-items-center mb-3">
                    <h3 class="dashboard-section-title mb-0">
                        <i class="fas fa-user-graduate" style="color: {{ config('brand.primary_color') }};"></i> Internship Program
                    </h3>
                    <a href="{{ route('interns.index') }}" class="btn btn-sm btn-outline-secondary ms-auto">Manage Interns</a>
                </div>
                <div class="row g-3">
                    <div class="col-6 col-lg-4 col-xl">
                        <div class="stat-card h-100">
                            <div class="stat-header">
                                <div>
                                    <span class="stat-title d-block mb-1">Total Interns</span>
                                    <span class="stat-value-large">{{ number_format($totalInterns) }}</span>
                                </div>
                                <div class="stat-icon-circle"><i class="fas fa-users"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4 col-xl">
                        <div class="stat-card h-100">
                            <div class="stat-header">
                                <div>
                                    <span class="stat-title d-block mb-1">Active</span>
                                    <span class="stat-value-large">{{ number_format($activeInterns) }}</span>
                                </div>
                                <div class="stat-icon-circle" style="background: rgba(16, 185, 129, 0.12); color: #059669;"><i class="fas fa-user-check"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4 col-xl">
                        <div class="stat-card h-100">
                            <div class="stat-header">
                                <div>
                                    <span class="stat-title d-block mb-1">Inactive</span>
                                    <span class="stat-value-large">{{ number_format($inactiveInterns) }}</span>
                                </div>
                                <div class="stat-icon-circle" style="background: rgba(156, 163, 175, 0.18); color: #6b7280;"><i class="fas fa-user-clock"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4 col-xl">
                        <div class="stat-card h-100">
                            <div class="stat-header">
                                <div>
                                    <span class="stat-title d-block mb-1">Completed</span>
                                    <span class="stat-value-large">{{ number_format($completedInterns) }}</span>
                                </div>
                                <div class="stat-icon-circle" style="background: rgba(37, 99, 235, 0.12); color: #2563eb;"><i class="fas fa-graduation-cap"></i></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-4 col-xl">
                        <div class="stat-card h-100">
                            <div class="stat-header">
                                <div>
                                    <span class="stat-title d-block mb-1">Expiring Soon</span>
                                    <span class="stat-value-large">{{ number_format($expiringSoonCount) }}</span>
                                </div>
                                <div class="stat-icon-circle" style="background: rgba(245, 158, 11, 0.15); color: #d97706;"><i class="fas fa-hourglass-half"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Interns Onboarded Trend --}}
        <div class="col-lg-8">
            <div class="graph-container">
                <h3 class="dashboard-section-title mb-3">
                    <i class="fas fa-chart-line" style="color: {{ config('brand.primary_color') }};"></i> Interns Onboarded ({{ $year }})
                </h3>
                <div style="height: 300px;" class="mt-2">
                    <canvas id="internsChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Intern Status Distribution --}}
        <div class="col-lg-4">
            <div class="graph-container">
                <h3 class="dashboard-section-title mb-3">
                    <i class="fas fa-chart-pie" style="color: {{ config('brand.primary_color') }};"></i> Status Distribution
                </h3>
                <div style="height: 300px;" class="mt-2">
                    <canvas id="internStatusChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Partner Agency Distribution --}}
        <div class="col-lg-5">
            <div class="graph-container">
                <h3 class="dashboard-section-title mb-3">
                    <i class="fas fa-handshake" style="color: {{ config('brand.primary_color') }};"></i> Partner Agency Distribution
                </h3>
                <div style="height: 300px;" class="mt-2">
                    <canvas id="partnerAgencyChart"></canvas>
                </div>
            </div>
        </div>

        {{-- Expiring Contracts --}}
        <div class="col-lg-7">
            <div class="graph-container">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="dashboard-section-title mb-0">
                        <i class="fas fa-hourglass-half" style="color: {{ config('brand.primary_color') }};"></i> Contracts Expiring (30 days)
                    </h3>
                    <a href="{{ route('reports.intern') }}" class="btn btn-sm btn-outline-secondary">Reports</a>
                </div>

                @if($expiringContracts->isEmpty())
                    <p class="text-muted small mb-0">No contracts are expiring within the next 30 days.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Intern</th>
                                    <th>Partner Agency</th>
                                    <th class="text-end">Ends</th>
                                    <th class="text-end">Days Left</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($expiringContracts as $contract)
                                    @php $daysLeft = $contract->days_remaining; @endphp
                                    <tr>
                                        <td class="fw-semibold">{{ $contract->name }}</td>
                                        <td>{{ $contract->partnerAgency?->name ?? '—' }}</td>
                                        <td class="text-end">{{ optional($contract->contract_end_date)->format('M d, Y') ?? '—' }}</td>
                                        <td class="text-end">
                                            <span class="badge {{ $daysLeft !== null && $daysLeft <= 7 ? 'bg-danger' : 'bg-warning text-dark' }}">
                                                {{ $daysLeft !== null ? $daysLeft . 'd' : '—' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
    @elseif($isInternUser)
    {{-- === Intern Self-Service Dashboard === --}}
    @if(!$internSelf)
        <div class="card shadow-sm border-0 mt-2">
            <div class="card-body p-5 text-center">
                <div class="mb-3">
                    <i class="fas fa-user-clock fa-3x text-warning"></i>
                </div>
                <h4 class="fw-bold text-dark">No Internship Record Assigned Yet</h4>
                <p class="text-muted mb-3" style="max-width: 540px; margin: 0 auto;">
                    Your account is registered as an intern, but your internship details have not been linked by the administrator or PESO manager.
                </p>
                <div class="alert alert-info d-inline-block text-start small mb-0">
                    <i class="fas fa-info-circle me-1"></i> Please contact your administrator or PESO coordinator to set up your partner agency, required hours, and contract dates.
                </div>
            </div>
        </div>
    @else
        <div class="row g-4">
            {{-- Welcome Banner --}}
            <div class="col-12">
                <div class="card shadow-sm border-0" style="background: linear-gradient(135deg, #1E429B 0%, #2563eb 100%); color: #fff; border-radius: 12px;">
                    <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-white text-primary fw-bold px-2 py-1">Intern Portal</span>
                                <span class="badge bg-{{ $internSelf->status === 'active' ? 'success' : ($internSelf->status === 'completed' ? 'info' : 'secondary') }}">
                                    <i class="fas fa-circle me-1" style="font-size: 0.5rem; vertical-align: middle;"></i> {{ ucfirst($internSelf->status) }}
                                </span>
                                @if($internSelf->isExpiringSoon())
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-exclamation-triangle me-1"></i> Ends in {{ $internSelf->days_remaining }}d
                                    </span>
                                @endif
                            </div>
                            <h2 class="h4 fw-bold mb-1 text-white">Welcome back, {{ $internSelf->name ?? Auth::user()->name }}!</h2>
                            <p class="mb-0 text-white-50 small">
                                <i class="fas fa-building me-1"></i> {{ $internSelf->partnerAgency?->name ?? 'Partner Agency: Not Assigned' }}
                                @if($internSelf->supervisor)
                                    &bull; <i class="fas fa-user-tie ms-2 me-1"></i> Supervisor: {{ $internSelf->supervisor }}
                                @endif
                            </p>
                        </div>
                        <div>
                            <a href="{{ route('my-internship.index') }}" class="btn btn-light fw-semibold text-primary shadow-sm">
                                <i class="fas fa-file-upload me-1"></i> Daily Time Record
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Stat Cards --}}
            <div class="col-12">
                <div class="stat-cards-frame">
                    <div class="row g-3">
                        {{-- Hours Rendered --}}
                        <div class="col-sm-6 col-xl-3">
                            <div class="stat-card h-100 p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="stat-title">Hours Rendered</span>
                                    <div class="stat-icon-circle" style="background: rgba(30, 66, 155, 0.1); color: #1E429B;">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                                <div class="stat-value-large mb-1">
                                    {{ $internSelf->rendered_hours }} <span class="text-muted fw-normal" style="font-size: 0.9rem;">/ {{ $internSelf->required_hours }} hrs</span>
                                </div>
                                <div class="progress mb-1" style="height: 6px;">
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: {{ min(100, $internSelf->progress_percent) }}%;"></div>
                                </div>
                                <div class="d-flex justify-content-between text-muted" style="font-size: 0.75rem;">
                                    <span>Progress</span>
                                    <span class="fw-bold text-primary">{{ $internSelf->progress_percent }}%</span>
                                </div>
                            </div>
                        </div>

                        {{-- Hours Remaining (converted to working days) --}}
                        <div class="col-sm-6 col-xl-3">
                            <div class="stat-card h-100 p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="stat-title">Days Remaining</span>
                                    <div class="stat-icon-circle" style="background: rgba(245, 158, 11, 0.15); color: #d97706;">
                                        <i class="fas fa-hourglass-half"></i>
                                    </div>
                                </div>
                                @php
                                    $daysFromHours = $internSelf->days_remaining_from_hours;
                                    $wholeDays     = (int) $daysFromHours;
                                    $leftoverHrs   = $internSelf->hours_remaining - ($wholeDays * 8);
                                @endphp
                                <div class="stat-value-large mb-1">
                                    @if($internSelf->hours_remaining <= 0)
                                        0 <span class="text-muted fw-normal" style="font-size: 0.9rem;">days</span>
                                    @else
                                        {{ $wholeDays }}<span class="text-muted fw-normal" style="font-size: 0.9rem;"> day{{ $wholeDays !== 1 ? 's' : '' }}</span>
                                        @if($leftoverHrs > 0)
                                            <span class="text-muted fw-normal" style="font-size: 0.8rem;"> {{ $leftoverHrs }}h</span>
                                        @endif
                                    @endif
                                </div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    @if($internSelf->hours_remaining <= 0)
                                        <span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i> Target achieved</span>
                                    @else
                                        {{ $internSelf->hours_remaining }} hrs &bull; 8-hr working day basis
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Contract Period (duration in months) --}}
                        <div class="col-sm-6 col-xl-3">
                            <div class="stat-card h-100 p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="stat-title">Contract Period</span>
                                    <div class="stat-icon-circle" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                </div>
                                <div class="stat-value-large mb-1" style="font-size: 1.25rem;">
                                    @if($internSelf->contract_months !== null)
                                        {{ $internSelf->contract_months }} <span class="text-muted fw-normal" style="font-size: 0.85rem;">month{{ $internSelf->contract_months != 1 ? 's' : '' }}</span>
                                    @else
                                        &mdash;
                                    @endif
                                </div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    @if($internSelf->contract_start_date && $internSelf->contract_end_date)
                                        {{ $internSelf->contract_start_date->format('M d, Y') }} &ndash; {{ $internSelf->contract_end_date->format('M d, Y') }}
                                    @elseif($internSelf->contract_end_date)
                                        Ends {{ $internSelf->contract_end_date->format('M d, Y') }}
                                    @else
                                        No dates set
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Submitted Documents --}}
                        <div class="col-sm-6 col-xl-3">
                            <div class="stat-card h-100 p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <span class="stat-title">Submitted Documents</span>
                                    <div class="stat-icon-circle" style="background: rgba(99, 102, 241, 0.12); color: #6366f1;">
                                        <i class="fas fa-file-invoice"></i>
                                    </div>
                                </div>
                                <div class="stat-value-large mb-1">
                                    {{ $internStats['total_docs'] }} <span class="text-muted fw-normal" style="font-size: 0.85rem;">files</span>
                                </div>
                                <div class="d-flex gap-2 flex-wrap" style="font-size: 0.72rem;">
                                    <span class="text-success fw-semibold">{{ $internStats['approved_docs'] }} approved</span>
                                    &bull;
                                    <span class="text-warning fw-semibold">{{ $internStats['pending_docs'] }} pending</span>
                                    @if($internStats['rejected_docs'] > 0)
                                        &bull;
                                        <span class="text-danger fw-semibold">{{ $internStats['rejected_docs'] }} rejected</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Work Calendar: days claimed on submitted DTRs --}}
            <div class="col-12">
                @include('partials.intern-work-calendar')
            </div>

            {{-- Recent Documents & Quick Overview --}}
            <div class="col-lg-8">
                <div class="graph-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h3 class="dashboard-section-title mb-0">
                            <i class="fas fa-file-alt" style="color: {{ config('brand.primary_color') }};"></i> Recent Submitted Documents
                        </h3>
                        <a href="{{ route('my-internship.index') }}" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-plus me-1"></i> Submit New
                        </a>
                    </div>

                    @if($internDocuments->isEmpty())
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-folder-open fa-2x mb-2 text-muted opacity-50"></i>
                            <p class="mb-2 small">You haven't submitted any internship documents yet.</p>
                            <a href="{{ route('my-internship.index') }}" class="btn btn-sm btn-brand">Go to Daily Time Record to Upload</a>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Document Type</th>
                                        <th>Period</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($internDocuments as $doc)
                                        <tr>
                                            <td class="fw-semibold">
                                                <a href="javascript:void(0)" class="preview-doc-trigger text-decoration-none text-dark" data-preview-url="{{ route('intern-documents.preview', $doc) }}" data-preview-download="{{ route('intern-documents.download', $doc) }}" data-preview-name="{{ $doc->original_filename }}" title="Click to view inside system">
                                                    <i class="far fa-file-alt text-primary me-1"></i>{{ $doc->documentType?->name ?? 'Document' }}
                                                </a>
                                                <span class="d-block text-muted" style="font-size: 0.72rem;">{{ $doc->original_filename }}</span>
                                            </td>
                                            <td class="text-muted small">{{ $doc->dtr_summary ?? $doc->document_period ?? '—' }}</td>
                                            <td class="text-muted small">{{ $doc->created_at?->format('M d, Y') }}</td>
                                            <td>
                                                <span class="badge bg-{{ $doc->review_status === 'approved' ? 'success' : ($doc->review_status === 'rejected' ? 'danger' : 'warning text-dark') }}">
                                                    {{ ucfirst($doc->review_status) }}
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary preview-doc-btn" data-preview-url="{{ route('intern-documents.preview', $doc) }}" data-preview-download="{{ route('intern-documents.download', $doc) }}" data-preview-name="{{ $doc->original_filename }}" title="View in System">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a href="{{ route('intern-documents.download', $doc) }}" class="btn btn-sm btn-outline-secondary" title="Download">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 text-end">
                            <a href="{{ route('my-internship.files') }}" class="small text-decoration-none fw-semibold">
                                View all documents in 201 Files &rarr;
                            </a>
                        </div>
                    @endif
                </div>
            </div>

            <div class="col-lg-4">
                <div class="graph-container">
                    <h3 class="dashboard-section-title mb-3">
                        <i class="fas fa-info-circle" style="color: {{ config('brand.primary_color') }};"></i> Internship Details
                    </h3>
                    <ul class="list-unstyled mb-3" style="font-size: 0.85rem;">
                        <li class="mb-2 pb-2 border-bottom">
                            <span class="text-muted d-block small">Partner Agency:</span>
                            <strong class="text-dark">{{ $internSelf->partnerAgency?->name ?? '—' }}</strong>
                        </li>
                        <li class="mb-2 pb-2 border-bottom">
                            <span class="text-muted d-block small">Agency Coordinator / Supervisor:</span>
                            <strong class="text-dark">{{ $internSelf->supervisor ?? '—' }}</strong>
                        </li>
                        <li class="mb-2 pb-2 border-bottom">
                            <span class="text-muted d-block small">Contract Duration:</span>
                            <strong class="text-dark">
                                {{ $internSelf->contract_start_date?->format('M d, Y') ?? '—' }} &ndash; {{ $internSelf->contract_end_date?->format('M d, Y') ?? '—' }}
                            </strong>
                        </li>
                        <li class="mb-0">
                            <span class="text-muted d-block small">Account Email / Username:</span>
                            <strong class="text-dark">{{ Auth::user()->email ?? Auth::user()->username }}</strong>
                        </li>
                    </ul>

                    <div class="p-3 bg-light rounded-3 border">
                        <h6 class="fw-bold mb-1 small text-dark"><i class="fas fa-lightbulb text-warning me-1"></i> Quick Tip</h6>
                        <p class="mb-0 text-muted" style="font-size: 0.78rem;">
                            Ensure your Daily Time Record (DTR) and monthly internship reports are signed before uploading.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @else
        <div class="card shadow-sm border-0">
            <div class="card-body p-4 text-center text-muted">
                <p class="mb-0">Welcome to {{ config('brand.name') }}.</p>
            </div>
        </div>
    @endif
</x-app-layout>
