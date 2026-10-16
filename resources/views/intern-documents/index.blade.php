<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h4 mb-1 fw-bold">Intern Documents</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">Review and manage documents submitted by interns.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Review Status</label>
                    <select name="review_status" class="form-select">
                        <option value="">All</option>
                        @foreach(['pending', 'approved', 'rejected'] as $status)
                            <option value="{{ $status }}" @selected(request('review_status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Intern</th>
                        <th>Type</th>
                        <th>File</th>
                        <th>Period</th>
                        <th>Uploaded</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $document)
                        <tr>
                            <td>
                                <a href="{{ $document->intern ? route('interns.show', $document->intern) : '#' }}">
                                    {{ $document->intern?->name ?? '—' }}
                                </a>
                            </td>
                            <td>{{ $document->documentType?->name ?? '—' }}</td>
                            <td style="font-size: 0.82rem;">
                                <a href="javascript:void(0)" class="preview-doc-trigger text-decoration-none fw-semibold text-dark" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="Click to view inside system">
                                    <i class="far fa-file-alt text-primary me-1"></i>{{ $document->original_filename }}
                                </a>
                            </td>
                            <td style="font-size: 0.82rem;">{{ $document->document_period ?? '—' }}</td>
                            <td style="font-size: 0.82rem;">{{ $document->created_at?->format('M d, Y H:i') }}</td>
                            <td>
                                <span class="badge bg-{{ $document->review_status === 'approved' ? 'success' : ($document->review_status === 'rejected' ? 'danger' : 'warning') }}">
                                    {{ ucfirst($document->review_status) }}
                                </span>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary preview-doc-btn" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="View in System">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('intern-documents.download', $document) }}" title="Download">
                                    <i class="fas fa-download"></i>
                                </a>
                                @if(auth()->user()->hasRole('admin', 'peso_manager') && $document->review_status === 'pending')
                                    <form method="POST" action="{{ route('intern-documents.review', $document) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="decision" value="approved">
                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Approve"><i class="fas fa-check"></i></button>
                                    </form>
                                    <form method="POST" action="{{ route('intern-documents.review', $document) }}" class="d-inline-flex">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="decision" value="rejected">
                                        <input type="text" name="review_remarks" class="form-control form-control-sm me-1" style="width: 140px;" placeholder="Reason" required>
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Reject"><i class="fas fa-times"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-5">No intern documents found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($documents->hasPages())
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <div class="text-muted" style="font-size: 0.8rem;">
                    Showing {{ $documents->firstItem() }}–{{ $documents->lastItem() }} of {{ $documents->total() }}
                </div>
                {{ $documents->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>
</x-app-layout>
