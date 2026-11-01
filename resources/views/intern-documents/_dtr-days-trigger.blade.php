@php
    $dtrDayCount = $document->relationLoaded('dtrDays')
        ? $document->dtrDays->count()
        : $document->dtrDays()->count();
@endphp

@if($dtrDayCount > 0)
    <button type="button"
            class="btn btn-sm btn-outline-secondary ms-1"
            data-dtr-days-view
            data-dtr-days="{{ json_encode($document->dtr_days_payload) }}"
            data-dtr-title="{{ $document->documentType?->name ?? 'Document' }} — claimed working days"
            title="Show the {{ $dtrDayCount }} working day(s) claimed on this DTR">
        <i class="fas fa-calendar-days"></i>
    </button>
@endif
