@php
    $showIntern = $showIntern ?? true;
    $emptyMessage = $emptyMessage ?? 'No intern documents found.';
    $colspan = $showIntern ? 7 : 6;
@endphp

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    @if($showIntern)
                        <th>Intern</th>
                    @endif
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
                        @if($showIntern)
                            <td>
                                <a href="{{ $document->intern ? route('interns.show', $document->intern) : '#' }}">
                                    {{ $document->intern?->name ?? '—' }}
                                </a>
                            </td>
                        @endif
                        <td>{{ $document->documentType?->name ?? '—' }}</td>
                        <td style="font-size: 0.82rem;">
                            <a href="javascript:void(0)" class="preview-doc-trigger text-decoration-none fw-semibold text-dark" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="Click to view inside system">
                                <i class="far fa-file-alt text-primary me-1"></i>{{ $document->original_filename }}
                            </a>
                        </td>
                        <td style="font-size: 0.82rem;">
                            {{ $document->dtr_summary ?? $document->document_period ?? '—' }}
                            @include('intern-documents._dtr-days-trigger', ['document' => $document])
                            @if(($outsideDays = $document->outside_contract_days_count) > 0)
                                <span class="badge bg-warning text-dark ms-1"
                                      title="Some claimed days fall outside the intern's contract dates — verify against the DTR scan.">
                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ $outsideDays }} outside contract
                                </span>
                            @endif
                        </td>
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
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        data-reject-document
                                        data-reject-action="{{ route('intern-documents.review', $document) }}"
                                        data-reject-label="{{ $document->intern?->name ?? 'Intern' }} · {{ $document->documentType?->name ?? 'Document' }} · {{ $document->dtr_summary ?? $document->document_period ?? 'no period' }}"
                                        title="Reject with a written explanation">
                                    <i class="fas fa-times"></i>
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $colspan }}" class="text-center text-muted py-5">{{ $emptyMessage }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(method_exists($documents, 'hasPages') && $documents->hasPages())
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <div class="text-muted" style="font-size: 0.8rem;">
                Showing {{ $documents->firstItem() }}–{{ $documents->lastItem() }} of {{ $documents->total() }}
            </div>
            {{ $documents->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
