<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1 fw-bold">Intern Documents</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">Review and manage documents submitted by interns.</p>
        </div>
        <a href="{{ route('intern-documents.folders') }}" class="btn btn-outline-primary">
            <i class="fas fa-folder-open me-1"></i> Browse folders
        </a>
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
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Search</label>
                    <input type="search" name="q" class="form-control" value="{{ request('q') }}"
                           placeholder="Intern name, username, or file name…">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Review Status</label>
                    <select name="review_status" class="form-select">
                        <option value="">All</option>
                        @foreach(['pending', 'approved', 'rejected'] as $status)
                            <option value="{{ $status }}" @selected(request('review_status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">
                        <i class="fas fa-magnifying-glass me-1"></i> Filter
                    </button>
                    @if(request()->filled('q') || request()->filled('review_status'))
                        <a href="{{ route('intern-documents.index') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    @include('intern-documents._review-table', ['documents' => $documents])

    @include('intern-documents._dtr-days-viewer')
    @include('intern-documents._reject-modal')
</x-app-layout>
