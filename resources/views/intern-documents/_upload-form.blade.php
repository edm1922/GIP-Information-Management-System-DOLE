@php
    $selectedTypeId = old('document_type_id');
    $isDtrSelected = $documentTypes->firstWhere('id', (int) $selectedTypeId)?->code === 'DTR';
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

@if($documentTypes->isEmpty())
    <div class="alert alert-warning small mb-0">
        <i class="fas fa-triangle-exclamation me-1"></i>
        No document types are configured for this tab yet. Please contact the PESO office.
    </div>
@else

<form method="POST" action="{{ route('intern-documents.store') }}" enctype="multipart/form-data"
      x-data="{
            typeId: '{{ $selectedTypeId }}',
            isDtr: {{ $isDtrSelected ? 'true' : 'false' }},
            dragging: false,
            fileName: '',
            onTypeChange(event) {
                this.isDtr = event.target.selectedOptions[0]?.dataset.code === 'DTR';
            },
            handleSelect(event) {
                this.fileName = event.target.files[0]?.name || '';
            },
            handleDrop(event) {
                const files = event.dataTransfer.files;
                if (files.length) {
                    this.$refs.file.files = files;
                    this.fileName = files[0].name;
                }
                this.dragging = false;
            }
      }">
    @csrf

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Document Type <span class="text-danger">*</span></label>
            <select name="document_type_id" class="form-select" x-model="typeId" @change="onTypeChange($event)" required>
                <option value="">Select&hellip;</option>
                @foreach($documentTypes as $type)
                    <option value="{{ $type->id }}" data-code="{{ $type->code }}">
                        {{ $type->name }}@if($type->is_optional) (optional)@endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4" x-show="!isDtr" x-cloak>
            <label class="form-label">Period</label>
            <input type="text" name="document_period" class="form-control" value="{{ old('document_period') }}" placeholder="e.g. June 2026">
        </div>

        {{-- DTRs capture structured working days; hours are derived from them.
             Only rendered when the DTR type is actually offered (DTR tab). --}}
        @if($documentTypes->contains('code', 'DTR'))
            <div class="col-12" x-show="isDtr" x-cloak>
                @include('intern-documents._dtr-days-picker')
            </div>

            <div class="col-12" x-show="isDtr" x-cloak>
                <div class="alert alert-light border small mb-0">
                    <i class="fas fa-circle-info me-1" style="color: {{ config('brand.primary_color') }};"></i>
                    The file you attach is <strong>reference evidence</strong> for the PESO reviewer.
                    The days you select above are what the system records and what your work calendar shows.
                </div>
            </div>
        @endif

        <div class="col-12">
            <label class="form-label">File <span class="text-danger">*</span></label>
            <div class="upload-dropzone text-center p-4 rounded-3"
                 style="border: 2px dashed #cbd5e1; cursor: pointer; transition: all .2s;"
                 :style="dragging ? 'border-color: {{ config('brand.primary_color') }}; background: #fef2f2;' : ''"
                 @click="$refs.file.click()"
                 @dragover.prevent="dragging = true"
                 @dragleave.prevent="dragging = false"
                 @drop.prevent="handleDrop($event)">
                <input type="file" name="file" x-ref="file" class="d-none"
                       @change="handleSelect($event)"
                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv">
                <i class="fas fa-cloud-upload-alt fa-2x mb-2" style="color: {{ config('brand.primary_color') }};"></i>
                <div class="text-muted" x-show="!fileName">
                    Drag &amp; drop your file here, or
                    <span class="fw-semibold" style="color: {{ config('brand.primary_color') }};">browse</span>
                </div>
                <div class="fw-semibold text-success" x-show="fileName" x-cloak>
                    <i class="fas fa-file-circle-check"></i> <span x-text="fileName"></span>
                </div>
                <div class="form-text mt-2">PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, CSV (max 10 MB).</div>
            </div>
            @error('file')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-brand" type="submit"><i class="fas fa-upload"></i> Upload</button>
        </div>
    </div>
</form>

@endif
