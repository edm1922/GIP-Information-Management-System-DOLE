@php
    $intern = $intern ?? null;
    $user = $intern?->user;
    $val = fn ($field, $default = '') => old($field, $user?->{$field} ?? $intern?->{$field} ?? $default);
@endphp

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Login Account</h6>
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <label class="form-label">First Name <span class="text-danger">*</span></label>
        <input type="text" name="first_name" class="form-control" value="{{ $val('first_name') }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Middle Name</label>
        <input type="text" name="middle_name" class="form-control" value="{{ $val('middle_name') }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="last_name" class="form-control" value="{{ $val('last_name') }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Suffix</label>
        <input type="text" name="suffix" class="form-control" value="{{ $val('suffix') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">Username <span class="text-danger">*</span></label>
        <input type="text" name="username" class="form-control" value="{{ $val('username') }}" required>
        <div class="form-text">Default password: <code>{{ strtolower(old('last_name', $user?->last_name ?? 'lastname')) . config('brand.password_suffix') }}</code></div>
    </div>
    <div class="col-md-4">
        <label class="form-label">Email Address <span class="text-danger">*</span></label>
        <input type="email" name="email" class="form-control" value="{{ $val('email') }}" {{ $intern ? '' : 'required' }}>
        <div class="form-text">Login credentials are sent to this address.</div>
    </div>
</div>

<h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Placement &amp; Contract</h6>
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <label class="form-label">Partner Agency</label>
        <select name="partner_agency_id" class="form-select">
            <option value="">— None —</option>
            @foreach($agencies as $agency)
                <option value="{{ $agency->id }}" @selected((int) old('partner_agency_id', $intern?->partner_agency_id) === $agency->id)>{{ $agency->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Intern Type</label>
        <select name="intern_type" class="form-select">
            <option value="">— None —</option>
            @foreach(\App\Models\Intern::INTERN_TYPES as $type)
                <option value="{{ $type }}" @selected(old('intern_type', $intern?->intern_type) === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Batch</label>
        <input type="text" name="batch" class="form-control" value="{{ $val('batch') }}" placeholder="e.g. 2026-A">
    </div>
    <div class="col-md-3">
        <label class="form-label">Contract Start</label>
        <input type="date" name="contract_start_date" class="form-control" value="{{ old('contract_start_date', $intern?->contract_start_date?->format('Y-m-d')) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">Contract End</label>
        <input type="date" name="contract_end_date" class="form-control" value="{{ old('contract_end_date', $intern?->contract_end_date?->format('Y-m-d')) }}">
    </div>
    <div class="col-md-2">
        <label class="form-label">Required Hours</label>
        <input type="number" name="required_hours" class="form-control" value="{{ old('required_hours', $intern?->required_hours ?? config('internship.default_required_hours')) }}" min="1">
    </div>
    <div class="col-md-2">
        <label class="form-label">Rendered Hours</label>
        <input type="number" name="rendered_hours" class="form-control" value="{{ old('rendered_hours', $intern?->rendered_hours ?? 0) }}" min="0">
    </div>
    <div class="col-md-2">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            @foreach(\App\Models\Intern::STATUSES as $status)
                <option value="{{ $status }}" @selected(old('status', $intern?->status ?? 'active') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </div>
</div>

<h6 class="text-uppercase text-muted fw-bold mb-3" style="font-size: 0.75rem;">Profile</h6>
<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">School</label>
        <input type="text" name="school" class="form-control" value="{{ $val('school') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">Course</label>
        <input type="text" name="course" class="form-control" value="{{ $val('course') }}">
    </div>
    <div class="col-md-2">
        <label class="form-label">Contact No.</label>
        <input type="text" name="contact_number" class="form-control" value="{{ $val('contact_number') }}">
    </div>
    <div class="col-md-2">
        <label class="form-label">Supervisor</label>
        <input type="text" name="supervisor" class="form-control" value="{{ $val('supervisor') }}">
    </div>
    <div class="col-md-12">
        <label class="form-label">Beneficiary</label>
        <input type="text" name="beneficiary" class="form-control" value="{{ $val('beneficiary') }}"
               maxlength="255" placeholder="Beneficiary name or reference as it appears on the program documents">
        <div class="form-text">Optional. Recorded on the intern's profile and used when matching program records.</div>
    </div>
</div>
