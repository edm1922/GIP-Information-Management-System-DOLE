<x-app-layout>
    <div class="mb-4">
        <h2 class="h4 mb-1 fw-bold">My Internship</h2>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">Track your rendered hours and submit your required documents.</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Hours Rendered</h6>
                    <div class="d-flex justify-content-between mb-1">
                        <span>{{ $intern->rendered_hours }} / {{ $intern->required_hours }} hrs</span>
                        <span class="fw-bold">{{ $intern->progress_percent }}%</span>
                    </div>
                    <div class="progress mb-3" style="height: 10px;">
                        <div class="progress-bar" role="progressbar" style="width: {{ $intern->progress_percent }}%;"></div>
                    </div>
                    <ul class="list-unstyled mb-0" style="font-size: 0.85rem;">
                        <li class="mb-1"><strong>Status:</strong> {{ ucfirst($intern->status) }}</li>
                        @php
                            $hoursLeft  = $intern->hours_remaining;
                            $daysLeft   = (int) ($hoursLeft / 8);
                            $leftoverH  = $hoursLeft - ($daysLeft * 8);
                        @endphp
                        <li class="mb-1">
                            <strong>Days Remaining:</strong>
                            @if($hoursLeft <= 0)
                                <span class="text-success"><i class="fas fa-check-circle me-1"></i>Completed</span>
                            @else
                                {{ $daysLeft }} day{{ $daysLeft !== 1 ? 's' : '' }}{{ $leftoverH > 0 ? ' ' . $leftoverH . 'h' : '' }}
                                <small class="text-muted">({{ $hoursLeft }} hrs &bull; 8-hr day)</small>
                            @endif
                        </li>
                        <li class="mb-1"><strong>Supervisor:</strong> {{ $intern->supervisor ?? '—' }}</li>
                        <li class="mb-1"><strong>Contract:</strong>
                            @if($intern->contract_months !== null)
                                {{ $intern->contract_months }} month{{ $intern->contract_months != 1 ? 's' : '' }}
                                <small class="text-muted d-block">
                                    {{ $intern->contract_start_date?->format('M d, Y') ?? '—' }}
                                    &ndash;
                                    {{ $intern->contract_end_date?->format('M d, Y') ?? '—' }}
                                </small>
                            @else
                                {{ $intern->contract_start_date?->format('M d, Y') ?? '—' }}
                                &ndash;
                                {{ $intern->contract_end_date?->format('M d, Y') ?? '—' }}
                            @endif
                        </li>
                        @if($intern->isExpiringSoon())
                            <li class="text-warning"><i class="fas fa-exclamation-triangle"></i> Contract ends in {{ $intern->days_remaining }} day(s).</li>
                        @endif
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Submit a Document</h6>
                    @include('intern-documents._upload-form', ['documentTypes' => $documentTypes])
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">My Documents</h6>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>File</th>
                            <th>Period</th>
                            <th>Uploaded</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($documents as $document)
                            <tr>
                                <td>{{ $document->documentType?->name ?? '—' }}</td>
                                <td style="font-size: 0.82rem;">
                                    <a href="javascript:void(0)" class="preview-doc-trigger text-decoration-none fw-semibold text-dark" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="Click to view inside system">
                                        <i class="far fa-file-alt text-primary me-1"></i>{{ $document->original_filename }}
                                    </a>
                                </td>
                                <td style="font-size: 0.82rem;">{{ $document->document_period ?? '—' }}</td>
                                <td style="font-size: 0.82rem;">{{ $document->created_at?->format('M d, Y') }}</td>
                                <td>
                                    <span class="badge bg-{{ $document->review_status === 'approved' ? 'success' : ($document->review_status === 'rejected' ? 'danger' : 'warning') }}">
                                        {{ ucfirst($document->review_status) }}
                                    </span>
                                </td>
                                <td style="font-size: 0.82rem;">{{ $document->review_remarks ?? '—' }}</td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary preview-doc-btn" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="View in System">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('intern-documents.download', $document) }}" title="Download">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">You haven't submitted any documents yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
