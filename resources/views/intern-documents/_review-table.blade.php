@php
    $showIntern = $showIntern ?? true;
    $emptyMessage = $emptyMessage ?? 'No intern documents found.';
    $colspan = $showIntern ? 7 : 6;
    $role = auth()->user()?->role;
    $statusClasses = [
        'approved' => 'success',
        'pending_admin' => 'info text-dark',
        'pending_peso' => 'warning text-dark',
        'returned_to_peso' => 'danger',
        'returned_to_intern' => 'danger',
        'pending' => 'warning text-dark',
        'rejected' => 'danger',
    ];
@endphp

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    @if($showIntern)<th>Intern</th>@endif
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
                    @php
                        $status = $document->review_status;
                        $canPesoApprove = $role === 'peso_manager' && $status === \App\Models\InternDocument::STATUS_PENDING_PESO;
                        $canPesoReturn = $role === 'peso_manager' && in_array($status, [\App\Models\InternDocument::STATUS_PENDING_PESO, \App\Models\InternDocument::STATUS_RETURNED_TO_PESO], true);
                        $canAdmin = $role === 'admin' && $status === \App\Models\InternDocument::STATUS_PENDING_ADMIN;
                    @endphp
                    <tr>
                        @if($showIntern)
                            <td>
                                <a href="{{ $document->intern ? route('interns.show', $document->intern) : '#' }}">
                                    {{ $document->intern?->name ?? 'â€”' }}
                                </a>
                            </td>
                        @endif
                        <td>{{ $document->documentType?->name ?? 'â€”' }}</td>
                        <td style="font-size: 0.82rem;">
                            <a href="javascript:void(0)" class="preview-doc-trigger text-decoration-none fw-semibold text-dark" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="Click to view inside system">
                                <i class="far fa-file-alt text-primary me-1"></i>{{ $document->original_filename }}
                            </a>
                        </td>
                        <td style="font-size: 0.82rem;">
                            {{ $document->dtr_summary ?? $document->document_period ?? 'â€”' }}
                            @include('intern-documents._dtr-days-trigger', ['document' => $document])
                            @if(($outsideDays = $document->outside_contract_days_count) > 0)
                                <span class="badge bg-warning text-dark ms-1" title="Some claimed days fall outside the intern's contract dates â€” verify against the DTR scan.">
                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ $outsideDays }} outside contract
                                </span>
                            @endif
                        </td>
                        <td style="font-size: 0.82rem;">{{ $document->created_at?->format('M d, Y H:i') }}</td>
                        <td>
                            <span class="badge bg-{{ $statusClasses[$status] ?? 'secondary' }}">
                                {{ \App\Models\InternDocument::statusLabel($status) }}
                            </span>
                            @if($status === \App\Models\InternDocument::STATUS_PENDING_ADMIN)
                                <div class="small text-muted mt-1">
                                    {{ $document->dtrDays->contains(fn ($day) => $day->hours === null) ? 'Hours pending' : 'Hours assigned' }}
                                </div>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary preview-doc-btn" data-preview-url="{{ route('intern-documents.preview', $document) }}" data-preview-download="{{ route('intern-documents.download', $document) }}" data-preview-name="{{ $document->original_filename }}" title="View in System">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('intern-documents.download', $document) }}" title="Download">
                                <i class="fas fa-download"></i>
                            </a>

                            @if($canPesoApprove)
                                <form method="POST" action="{{ route('intern-documents.peso-approve', $document) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success" type="submit" title="Approve and forward to Admin">
                                        <i class="fas fa-check"></i> To Admin
                                    </button>
                                </form>
                            @endif

                            @if($canAdmin)
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#hours-{{ $document->id }}" title="Assign working hours">
                                    <i class="fas fa-clock"></i> Hours
                                </button>
                                <form method="POST" action="{{ route('intern-documents.admin-approve', $document) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-success" type="submit" title="Final approval">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                </form>
                            @endif

                            @if($canPesoReturn)
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#peso-return-{{ $document->id }}" title="Send back to the intern">
                                    <i class="fas fa-rotate-left"></i> Send back
                                </button>
                            @endif

                            @if($canAdmin)
                                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#admin-return-{{ $document->id }}" title="Return to PESO manager">
                                    <i class="fas fa-rotate-left"></i> Return
                                </button>
                            @endif
                        </td>
                    </tr>

                    @if($canAdmin)
                        <tr class="collapse" id="hours-{{ $document->id }}">
                            <td colspan="{{ $colspan }}" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('intern-documents.hours', $document) }}" class="row g-2 align-items-end">
                                    @csrf
                                    @method('PATCH')
                                    <div class="col-12">
                                        <div class="fw-semibold mb-1"><i class="fas fa-clock me-1"></i> Assign working hours (Admin only)</div>
                                        <div class="text-muted small mb-2">The intern selected these dates. Enter hours per day; approve once every day has hours.</div>
                                    </div>
                                    @foreach($document->dtrDays as $day)
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small mb-0">{{ $day->work_date->format('M d, Y') }}</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" min="0.5" max="24" step="0.5" class="form-control" name="day_hours[{{ $day->work_date->format('Y-m-d') }}]" value="{{ $day->hours }}">
                                                <span class="input-group-text">hrs</span>
                                            </div>
                                        </div>
                                    @endforeach
                                    <div class="col-12">
                                        <button class="btn btn-sm btn-brand mt-2" type="submit"><i class="fas fa-save me-1"></i> Save hours</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endif

                    @if($canPesoReturn)
                        <tr class="collapse" id="peso-return-{{ $document->id }}">
                            <td colspan="{{ $colspan }}" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('intern-documents.peso-return', $document) }}" class="row g-2">
                                    @csrf
                                    @if($document->admin_remarks)
                                        <div class="col-12">
                                            <div class="alert alert-warning small py-2 mb-0"><strong>Admin remarks:</strong> {{ $document->admin_remarks }}</div>
                                        </div>
                                    @endif
                                    <div class="col-12">
                                        <label class="form-label small mb-1">Remarks to the intern</label>
                                        <textarea class="form-control" name="remarks" rows="2" required minlength="10" placeholder="Explain what needs to be correctedâ€¦">{{ $document->admin_remarks }}</textarea>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-rotate-left me-1"></i> Send back to intern</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endif

                    @if($canAdmin)
                        <tr class="collapse" id="admin-return-{{ $document->id }}">
                            <td colspan="{{ $colspan }}" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('intern-documents.admin-return', $document) }}" class="row g-2">
                                    @csrf
                                    <div class="col-12">
                                        <label class="form-label small mb-1">Remarks for the PESO manager</label>
                                        <textarea class="form-control" name="remarks" rows="2" required minlength="10" placeholder="Explain what needs to be correctedâ€¦"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-rotate-left me-1"></i> Return to PESO manager</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="{{ $colspan }}" class="text-center text-muted py-5">{{ $emptyMessage }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(method_exists($documents, 'hasPages') && $documents->hasPages())
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <div class="text-muted" style="font-size: 0.8rem;">
                Showing {{ $documents->firstItem() }}â€“{{ $documents->lastItem() }} of {{ $documents->total() }}
            </div>
            {{ $documents->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>
