<x-app-layout>
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb mb-0" style="font-size: 0.82rem;">
            <li class="breadcrumb-item"><a href="{{ route('intern-documents.index') }}">Intern Documents</a></li>
            <li class="breadcrumb-item"><a href="{{ route('intern-documents.folders') }}">Folders</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $intern->name }}</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size: 2.4rem; line-height: 1; color: {{ config('brand.primary_color') }};">
                <i class="fas fa-folder-open"></i>
            </div>
            <div>
                <h2 class="h4 mb-1 fw-bold">{{ $intern->name }}</h2>
                <p class="text-muted mb-0" style="font-size: 0.85rem;">
                    {{ $intern->partnerAgency?->name ?? 'No partner agency' }}
                    @if($intern->supervisor) &bull; Supervisor: {{ $intern->supervisor }} @endif
                </p>
            </div>
        </div>
        <div class="d-flex gap-2">
            @if($intern->user)
                <a href="{{ route('interns.show', $intern) }}" class="btn btn-outline-secondary">
                    <i class="fas fa-user me-1"></i> Intern profile
                </a>
            @endif
            <a href="{{ route('intern-documents.folders') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Folders
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Intern summary --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3" style="font-size: 0.85rem;">
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Status</div>
                    <div class="fw-semibold">{{ ucfirst($intern->status) }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Hours credited</div>
                    <div class="fw-semibold">{{ $intern->rendered_hours }} / {{ $intern->required_hours }} hrs ({{ $intern->progress_percent }}%)</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Contract</div>
                    <div class="fw-semibold">
                        {{ $intern->contract_start_date?->format('M d, Y') ?? '—' }}
                        &ndash;
                        {{ $intern->contract_end_date?->format('M d, Y') ?? '—' }}
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">Documents</div>
                    <div class="fw-semibold">
                        {{ $dtrs->count() }} DTR · {{ $files->count() }} 201 files
                        @php $pending = $dtrs->concat($files)->where('review_status', 'pending')->count(); @endphp
                        @if($pending > 0)
                            <span class="badge bg-warning text-dark ms-1">{{ $pending }} pending</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Daily Time Records --}}
    <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.75rem;">
        <i class="fas fa-clock me-1"></i>Daily Time Records ({{ $dtrs->count() }})
    </h6>
    <div class="mb-4">
        @include('intern-documents._review-table', [
            'documents' => $dtrs,
            'showIntern' => false,
            'emptyMessage' => 'This intern has not submitted any DTRs yet.',
        ])
    </div>

    {{-- 201 Files --}}
    <h6 class="text-uppercase text-muted fw-bold mb-2" style="font-size: 0.75rem;">
        <i class="fas fa-folder-open me-1"></i>201 Files ({{ $files->count() }})
    </h6>
    @include('intern-documents._review-table', [
        'documents' => $files,
        'showIntern' => false,
        'emptyMessage' => 'This intern has not submitted any 201 files yet.',
    ])

    @include('intern-documents._dtr-days-viewer')
    @include('intern-documents._reject-modal')
</x-app-layout>
