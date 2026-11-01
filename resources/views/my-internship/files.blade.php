<x-app-layout>
    <div class="mb-4">
        <h2 class="h4 mb-1 fw-bold">201 Files</h2>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">
            Submit your Accomplishment Report and other supporting documents for review.
        </p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Requirements</h6>

                    @if($checklist->isEmpty())
                        <p class="text-muted small mb-0">No documents are required for your internship type right now.</p>
                    @else
                        <ul class="list-unstyled mb-0">
                            @foreach($checklist as $item)
                                @php
                                    $status = $item['status'];
                                    $icon = match ($status) {
                                        'approved' => ['fas fa-check-circle', 'text-success'],
                                        'pending' => ['fas fa-clock', 'text-warning'],
                                        'rejected' => ['fas fa-times-circle', 'text-danger'],
                                        default => $item['required'] ? ['fas fa-exclamation-circle', 'text-danger'] : ['far fa-circle', 'text-muted'],
                                    };
                                @endphp
                                <li class="d-flex align-items-start gap-2 mb-2">
                                    <i class="{{ $icon[0] }} {{ $icon[1] }} mt-1"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold" style="font-size: 0.88rem;">
                                            {{ $item['type']->name }}
                                            @if(! $item['required'])
                                                <span class="badge bg-light text-muted fw-normal ms-1">optional</span>
                                            @endif
                                        </div>
                                        <div class="small
                                            @if($status === 'approved') text-success
                                            @elseif($status === 'rejected') text-danger
                                            @elseif($status === 'pending') text-warning
                                            @else text-muted @endif">
                                            @if($status === 'approved')
                                                Approved
                                            @elseif($status === 'pending')
                                                Submitted — awaiting review
                                            @elseif($status === 'rejected')
                                                Rejected — please re-submit
                                            @else
                                                {{ $item['required'] ? 'Not submitted' : 'Not submitted (optional)' }}
                                            @endif
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <p class="text-muted small mb-0 mt-3">
                            <i class="fas fa-info-circle me-1"></i>
                            Daily Time Records are submitted from the <strong>Daily Time Record</strong> tab.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Submit a Document</h6>
                    @include('intern-documents._upload-form', ['documentTypes' => $documentTypes])
                </div>
            </div>
        </div>
    </div>

    @include('my-internship._documents-table', [
        'documents' => $documents,
        'tableTitle' => 'My 201 Files',
        'emptyMessage' => 'You have not submitted any 201 files yet.',
    ])
</x-app-layout>
