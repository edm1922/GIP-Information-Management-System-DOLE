@php
    $tableTitle = $tableTitle ?? 'My Documents';
    $emptyMessage = $emptyMessage ?? "You haven't submitted any documents yet.";
@endphp

<div class="card shadow-sm">
    <div class="card-body">
        <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">{{ $tableTitle }}</h6>
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
                            <td style="font-size: 0.82rem;">
                                {{ $document->dtr_summary ?? $document->document_period ?? '—' }}
                                @include('intern-documents._dtr-days-trigger', ['document' => $document])
                                @if(($outsideDays = $document->outside_contract_days_count) > 0)
                                    <span class="badge bg-warning text-dark ms-1" title="These days fall outside your contract dates; the reviewer will see them flagged.">
                                        <i class="fas fa-triangle-exclamation me-1"></i>{{ $outsideDays }} outside contract
                                    </span>
                                @endif
                            </td>
                            <td style="font-size: 0.82rem;">{{ $document->created_at?->format('M d, Y') }}</td>
                            <td>
                                <span class="badge bg-{{ in_array($document->review_status, ['approved'], true) ? 'success' : ($document->review_status === 'pending_admin' ? 'info text-dark' : (in_array($document->review_status, ['returned_to_peso', 'returned_to_intern', 'rejected'], true) ? 'danger' : 'warning text-dark')) }}">
                                    {{ \App\Models\InternDocument::statusLabel($document->review_status) }}
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
                                @if($document->review_status === \App\Models\InternDocument::STATUS_RETURNED_TO_INTERN)
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse"
                                            data-bs-target="#resubmit-{{ $document->id }}" title="Correct and resubmit">
                                        <i class="fas fa-rotate-right"></i> Resubmit
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @if($document->review_status === \App\Models\InternDocument::STATUS_RETURNED_TO_INTERN)
                            <tr class="collapse" id="resubmit-{{ $document->id }}">
                                <td colspan="7" class="bg-body-tertiary">
                                    <form method="POST" action="{{ route('intern-documents.resubmit', $document) }}" enctype="multipart/form-data" class="row g-2">
                                        @csrf
                                        <div class="col-12">
                                            <div class="alert alert-warning small py-2 mb-0">
                                                <strong>Corrections required:</strong>
                                                {{ $document->peso_remarks ?? $document->admin_remarks ?? $document->review_remarks ?? 'See the reviewer remarks.' }}
                                            </div>
                                        </div>
                                        @if($document->documentType?->code === 'DTR')
                                            @foreach($document->dtrDays as $day)
                                                <input type="hidden" name="days[]" value="{{ $day->work_date->format('Y-m-d') }}">
                                            @endforeach
                                            <div class="col-md-6">
                                                <label class="form-label small mb-1">Replacement scan (optional)</label>
                                                <input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv">
                                            </div>
                                        @else
                                            <div class="col-md-6">
                                                <label class="form-label small mb-1">Replacement file <span class="text-danger">*</span></label>
                                                <input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv">
                                            </div>
                                        @endif
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1">Note to the PESO manager (optional)</label>
                                            <input type="text" name="remarks" class="form-control form-control-sm" maxlength="2000">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-sm btn-brand" type="submit"><i class="fas fa-paper-plane me-1"></i> Resubmit for PESO review</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ $emptyMessage }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
