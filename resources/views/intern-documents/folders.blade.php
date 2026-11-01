<x-app-layout>
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1 fw-bold">Intern Documents</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
                Browse each intern's folder — their Daily Time Records and 201 Files in one place.
            </p>
        </div>
        <a href="{{ route('intern-documents.index') }}" class="btn btn-outline-secondary">
            <i class="fas fa-list me-1"></i> All documents
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    {{-- Search & filter --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('intern-documents.folders') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Search intern</label>
                    <input type="search" name="q" class="form-control" value="{{ $search }}"
                           placeholder="Name, username, or partner agency…">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Show</label>
                    <select name="filter" class="form-select">
                        <option value="" @selected($filter === '')>All interns</option>
                        <option value="pending" @selected($filter === 'pending')>With pending documents</option>
                        <option value="none" @selected($filter === 'none')>No documents yet</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">
                        <i class="fas fa-magnifying-glass me-1"></i> Apply
                    </button>
                    @if($search !== '' || $filter !== '')
                        <a href="{{ route('intern-documents.folders') }}" class="btn btn-outline-secondary">Clear</a>
                    @endif
                </div>
            </form>

            <div class="text-muted mt-2" style="font-size: 0.8rem;">
                {{ $interns->total() }} folder{{ $interns->total() === 1 ? '' : 's' }}
                @if($search !== '')
                    matching “{{ $search }}”
                @endif
                @if($filter === 'pending')
                    with pending documents
                @elseif($filter === 'none')
                    with no documents yet
                @endif
            </div>
        </div>
    </div>

    @php
        $folderColors = ['#1E429B', '#CE2028', '#0f766e', '#7c3aed', '#b45309', '#0369a1'];
    @endphp

    <div class="row g-3">
        @forelse($interns as $index => $intern)
            @php
                $color = $folderColors[$index % count($folderColors)];
                $totalDocs = $intern->dtr_count + $intern->files_count;
            @endphp
            <div class="col-sm-6 col-lg-4 col-xl-3">
                <a href="{{ route('intern-documents.folder', $intern) }}"
                   class="card shadow-sm h-100 text-decoration-none folder-card">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <div class="folder-icon" style="color: {{ $color }};">
                                <i class="fas fa-folder"></i>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-bold text-dark text-truncate">{{ $intern->name }}</div>
                                <div class="text-muted small text-truncate">{{ $intern->partnerAgency?->name ?? 'No partner agency' }}</div>
                                <div class="text-muted" style="font-size: 0.72rem;">{{ $totalDocs }} document{{ $totalDocs === 1 ? '' : 's' }}</div>
                            </div>
                            @if($intern->pending_count > 0)
                                <span class="badge bg-warning text-dark" title="{{ $intern->pending_count }} pending review">
                                    {{ $intern->pending_count }}
                                </span>
                            @endif
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3" style="font-size: 0.72rem;">
                            <span class="badge bg-light text-dark border">
                                <i class="fas fa-clock me-1" style="color: {{ $color }};"></i>DTR: {{ $intern->dtr_count }}
                            </span>
                            <span class="badge bg-light text-dark border">
                                <i class="fas fa-folder-open me-1" style="color: {{ $color }};"></i>201: {{ $intern->files_count }}
                            </span>
                        </div>
                    </div>
                </a>
            </div>
        @empty
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body text-center text-muted py-5">
                        <i class="fas fa-folder-open fa-2x mb-2 opacity-50"></i>
                        <p class="mb-2">
                            @if($search !== '' || $filter !== '')
                                No intern folders match your search or filter.
                            @else
                                No interns yet. Intern folders appear here once an intern record exists.
                            @endif
                        </p>
                        @if($search !== '' || $filter !== '')
                            <a href="{{ route('intern-documents.folders') }}" class="btn btn-sm btn-outline-secondary">Clear search</a>
                        @endif
                    </div>
                </div>
            </div>
        @endforelse
    </div>

    @if($interns->hasPages())
        <div class="mt-3">
            {{ $interns->links('pagination::bootstrap-5') }}
        </div>
    @endif

    @push('styles')
        <style>
            .folder-card { transition: transform .12s ease, box-shadow .12s ease; }
            .folder-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1.25rem rgba(15, 23, 42, .12) !important; }
            .folder-icon { font-size: 1.9rem; line-height: 1; }
            .min-w-0 { min-width: 0; }
        </style>
    @endpush
</x-app-layout>
