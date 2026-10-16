<x-app-layout>
    @php $canReview = auth()->user()->hasRole('admin', 'peso_manager'); @endphp

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h4 mb-1 fw-bold">{{ $intern->name }}</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">
                {{ $intern->intern_type ?? 'Intern' }}@if($intern->batch) &middot; Batch {{ $intern->batch }}@endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('interns.index') }}"><i class="fas fa-arrow-left"></i> Back</a>
            @can('update', $intern)
                <a class="btn btn-brand" href="{{ route('interns.edit', $intern) }}"><i class="fas fa-pen"></i> Edit</a>
            @endcan
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Progress</h6>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ $intern->rendered_hours }} / {{ $intern->required_hours }} hrs</span>
                        <span class="fw-bold">{{ $intern->progress_percent }}%</span>
                    </div>
                    <div class="progress mb-3" style="height: 10px;">
                        <div class="progress-bar" role="progressbar" style="width: {{ $intern->progress_percent }}%;"></div>
                    </div>
                    <ul class="list-unstyled mb-0" style="font-size: 0.85rem;">
                        <li class="mb-1"><strong>Status:</strong> {{ ucfirst($intern->status) }}</li>
                        <li class="mb-1"><strong>Hours remaining:</strong> {{ $intern->hours_remaining }}</li>
                        <li class="mb-1"><strong>Partner Agency:</strong> {{ $intern->partnerAgency?->name ?? '—' }}</li>
                        <li class="mb-1"><strong>Supervisor:</strong> {{ $intern->supervisor ?? '—' }}</li>
                        <li class="mb-1"><strong>School:</strong> {{ $intern->school ?? '—' }}</li>
                        <li class="mb-1"><strong>Course:</strong> {{ $intern->course ?? '—' }}</li>
                        <li class="mb-1"><strong>Contact:</strong> {{ $intern->contact_number ?? '—' }}</li>
                        <li class="mb-1"><strong>Contract:</strong>
                            {{ $intern->contract_start_date?->format('M d, Y') ?? '—' }}
                            &ndash;
                            {{ $intern->contract_end_date?->format('M d, Y') ?? '—' }}
                        </li>
                        <li><strong>Username:</strong> <code>{{ $intern->user?->username }}</code></li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Submitted Documents</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>File</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($intern->internDocuments as $document)
                                    <tr>
                                        <td>{{ $document->documentType?->name ?? '—' }}</td>
                                        <td style="font-size: 0.82rem;">
                                            <a href="javascript:void(0)" class="preview-doc-trigger text-decoration-none fw-semibold text-dark" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="Click to view inside system">
                                                <i class="far fa-file-alt text-primary me-1"></i>{{ $document->original_filename }}
                                            </a>
                                        </td>
                                        <td style="font-size: 0.82rem;">{{ $document->document_period ?? '—' }}</td>
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
                                            @if($canReview && $document->review_status === 'pending')
                                                <form method="POST" action="{{ route('intern-documents.review', $document) }}" class="d-inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="decision" value="approved">
                                                    <button class="btn btn-sm btn-outline-success" type="submit" title="Approve"><i class="fas fa-check"></i></button>
                                                </form>
                                                <form method="POST" action="{{ route('intern-documents.review', $document) }}" class="d-inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="decision" value="rejected">
                                                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Reject"><i class="fas fa-times"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">No documents submitted yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
