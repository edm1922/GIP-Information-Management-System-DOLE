<x-app-layout>
    <div class="mb-4">
        <h2 class="h4 mb-1 fw-bold">Daily Time Record</h2>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">
            Submit your DTR, track your rendered hours, and review which days are credited.
        </p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            @include('my-internship._hours-card')
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-uppercase text-muted fw-bold mb-0" style="font-size: 0.75rem;">Submit a Daily Time Record</h6>
                        <a href="{{ route('dashboard') }}" class="small text-decoration-none fw-semibold">
                            <i class="fas fa-calendar-days me-1"></i>View work calendar
                        </a>
                    </div>
                    @include('intern-documents._upload-form', ['documentTypes' => $documentTypes])
                </div>
            </div>
        </div>
    </div>

    @include('my-internship._documents-table', [
        'documents' => $documents,
        'tableTitle' => 'My Daily Time Records',
        'emptyMessage' => 'You have not submitted any DTRs yet.',
    ])

    @include('intern-documents._dtr-days-viewer')
</x-app-layout>
