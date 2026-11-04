<x-app-layout>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="h4 mb-1 fw-bold">Interns</h2>
            <p class="text-muted mb-0" style="font-size: 0.85rem;">Manage the GIP / internship program roster and hour monitoring.</p>
        </div>
        <div class="d-flex gap-2">
            @if(auth()->user()->hasRole('admin'))
                @if($showArchived)
                    <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-2" href="{{ route('interns.index') }}">
                        <i class="fas fa-users"></i> Active Interns
                    </a>
                @else
                    <a class="btn btn-outline-secondary d-inline-flex align-items-center gap-2" href="{{ route('interns.index', ['archived' => 1]) }}">
                        <i class="fas fa-box-archive"></i> Archived
                    </a>
                @endif
            @endif
            @can('create', App\Models\Intern::class)
                <a class="btn btn-brand d-inline-flex align-items-center gap-2" href="{{ route('interns.create') }}">
                    <i class="fas fa-plus"></i> Add Intern
                </a>
            @endcan
        </div>
    </div>

    @if($showArchived)
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="fas fa-box-archive"></i>
            You are viewing archived interns. Restore anyone who returns, or permanently delete records that were created in error.
        </div>
    @endif

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                @if($showArchived)
                    <input type="hidden" name="archived" value="1">
                @endif
                <div class="col-lg-4">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" value="{{ request('search') }}" placeholder="Name or username">
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        @foreach(\App\Models\Intern::STATUSES as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Contract</label>
                    <select name="expiring" class="form-select">
                        <option value="">All</option>
                        <option value="soon" @selected(request('expiring') === 'soon')>Expiring &le; 30 days</option>
                        <option value="expired" @selected(request('expiring') === 'expired')>Expired</option>
                    </select>
                </div>
                <div class="col-lg-4">
                    <label class="form-label">Partner Agency</label>
                    <select name="partner_agency_id" class="form-select">
                        <option value="">All</option>
                        @foreach($agencies as $agency)
                            <option value="{{ $agency->id }}" @selected((int) request('partner_agency_id') === $agency->id)>{{ $agency->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex gap-2 pt-1">
                    <button class="btn btn-primary" type="submit">Filter</button>
                    <a class="btn btn-outline-secondary" href="{{ route('interns.index') }}">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Intern</th>
                        <th>Partner Agency</th>
                        <th>Contract</th>
                        <th style="width: 180px;">Hours</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($interns as $intern)
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $intern->name }}</div>
                                <div class="text-muted" style="font-size: 0.8rem;">{{ $intern->user?->username }}</div>
                            </td>
                            <td>{{ $intern->partnerAgency?->name ?? '—' }}</td>
                            <td style="font-size: 0.82rem;">
                                {{ $intern->contract_start_date?->format('M d, Y') ?? '—' }}
                                &ndash;
                                {{ $intern->contract_end_date?->format('M d, Y') ?? '—' }}
                            </td>
                            <td>
                                <div class="d-flex justify-content-between" style="font-size: 0.8rem;">
                                    <span>{{ $intern->rendered_hours }} / {{ $intern->required_hours }} hrs</span>
                                    <span>{{ $intern->progress_percent }}%</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar" role="progressbar" style="width: {{ $intern->progress_percent }}%;"></div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-{{ $intern->status === 'active' ? 'success' : ($intern->status === 'completed' ? 'primary' : 'secondary') }}">
                                    {{ ucfirst($intern->status) }}
                                </span>
                                @if($showArchived)
                                    <span class="badge bg-warning text-dark ms-1">Archived</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('interns.show', $intern) }}">
                                    <i class="fas fa-eye"></i>
                                </a>
                                @if($showArchived)
                                    @can('restore', $intern)
                                        <form method="POST" action="{{ route('interns.restore', $intern->id) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Restore intern">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                        </form>
                                    @endcan
                                    @can('forceDelete', $intern)
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Permanently delete"
                                            data-bs-toggle="modal" data-bs-target="#internConfirmModal"
                                            data-action="{{ route('interns.forceDelete', $intern->id) }}"
                                            data-method="DELETE"
                                            data-title="Permanently delete intern"
                                            data-message="Permanently delete {{ $intern->name }}? Their login account and intern record will be removed. This cannot be undone."
                                            data-submit="Delete permanently">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    @endcan
                                @else
                                    @can('update', $intern)
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('interns.edit', $intern) }}">
                                            <i class="fas fa-pen"></i>
                                        </a>
                                    @endcan
                                    @can('delete', $intern)
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Archive intern"
                                            data-bs-toggle="modal" data-bs-target="#internConfirmModal"
                                            data-action="{{ route('interns.destroy', $intern) }}"
                                            data-method="DELETE"
                                            data-title="Archive intern"
                                            data-message="Archive {{ $intern->name }}? They will be hidden from the active list and their login will be disabled. You can restore them from the Archived view."
                                            data-submit="Archive">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">No interns found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($interns->hasPages())
            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <div class="text-muted" style="font-size: 0.8rem;">
                    Showing {{ $interns->firstItem() }}–{{ $interns->lastItem() }} of {{ $interns->total() }}
                </div>
                {{ $interns->links('pagination::bootstrap-5') }}
            </div>
        @endif
    </div>

    @include('interns._confirm-modal')
</x-app-layout>
