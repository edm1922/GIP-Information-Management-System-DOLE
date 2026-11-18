@once
    @push('scripts')
        <script src="{{ asset('js/dtr-picker.js') }}?v={{ filemtime(public_path('js/dtr-picker.js')) }}"></script>
    @endpush
@endonce

@once
    @push('styles')
        <style>
            .dtr-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .3rem; }
            .dtr-cal-grid-head { margin-bottom: .25rem; }
            .dtr-cal-weekday { text-align: center; font-size: .72rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; }
            .dtr-cal-cell {
                aspect-ratio: 1 / 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
                border-radius: .5rem; border: 1px solid transparent; background: #f8fafc;
                color: #334155; font-size: .85rem; font-weight: 600; cursor: pointer; line-height: 1.05;
                transition: background .12s, border-color .12s;
            }
            .dtr-cal-cell:hover:not(:disabled) { background: #eef2ff; border-color: #cbd5e1; }
            .dtr-cal-cell.is-weekend { background: #f1f5f9; color: #94a3b8; }
            .dtr-cal-cell.is-outside { opacity: .25; }
            .dtr-cal-cell.is-outside-contract { border-style: dashed; border-color: #f59e0b; }
            .dtr-cal-cell.is-selected { background: {{ config('brand.primary_color') }}; border-color: {{ config('brand.primary_color') }}; color: #fff; }
            .dtr-cal-cell.is-taken { background: #fee2e2; color: #b91c1c; text-decoration: line-through; }
            .dtr-cal-cell.is-disabled { cursor: not-allowed; opacity: .45; }
            .dtr-cal-cell.is-today { box-shadow: inset 0 0 0 2px {{ config('brand.primary_color') }}; }
            .dtr-legend { display: inline-flex; align-items: center; gap: .3rem; font-size: .74rem; color: #64748b; }
            .dtr-legend-swatch { width: 11px; height: 11px; border-radius: 3px; display: inline-block; border: 1px solid #cbd5e1; }
            .dtr-legend-swatch.is-selected { background: {{ config('brand.primary_color') }}; border-color: {{ config('brand.primary_color') }}; }
            .dtr-legend-swatch.is-taken { background: #fee2e2; border-color: #fca5a5; }
            .dtr-legend-swatch.is-weekend { background: #f1f5f9; }
        </style>
    @endpush
@endonce

@php
    $pickerConfig = [
        'days' => old('days', $preselectedDays ?? []),
        'taken' => $takenDays ?? [],
        'contractStart' => isset($intern) && $intern->contract_start_date ? $intern->contract_start_date->format('Y-m-d') : null,
        'contractEnd' => isset($intern) && $intern->contract_end_date ? $intern->contract_end_date->format('Y-m-d') : null,
    ];
@endphp

{{-- Working-day picker: the selected dates are the record; the scan is evidence. --}}
<div class="dtr-picker" x-data="dtrPicker(@js($pickerConfig))">
    <label class="form-label d-block">Working Days <span class="text-danger">*</span></label>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dtrDaysModal">
            <i class="fas fa-calendar-days me-1"></i> Pick working days
        </button>

        <span class="text-muted small" x-show="count === 0" x-cloak>No days selected yet.</span>
        <span class="text-muted small" x-show="count > 0" x-cloak>Selected dates are highlighted on the calendar.</span>
    </div>

    <div class="form-text mt-1" x-show="count === 0" x-cloak>
        Open the calendar and click each day you worked. Your Admin will assign the working hours after the PESO review.
    </div>
    <div class="form-text mt-1" x-show="count > 0" x-cloak>
        <span x-text="summary"></span>
    </div>

    @error('days')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
    @error('days.*')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror

    {{-- Submitted with the form --}}
    <template x-for="day in days" :key="day">
        <input type="hidden" name="days[]" :value="day">
    </template>

    {{-- Date picker modal --}}
    <div class="modal fade" id="dtrDaysModal" tabindex="-1" aria-labelledby="dtrDaysModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dtrDaysModalLabel">
                        <i class="fas fa-calendar-check me-2" style="color: {{ config('brand.primary_color') }};"></i>
                        Select the days you worked
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-muted small mb-2" x-show="contractLabel" x-cloak>
                        Your contract runs <span class="fw-semibold" x-text="contractLabel"></span>.
                        Days before your contract started (or after it ends) can still be selected to
                        back-fill earlier DTRs â€” they are marked with a dashed border and flagged for the reviewer.
                    </p>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="prev()" aria-label="Previous month">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div class="fw-semibold" x-text="monthLabel"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="next()" aria-label="Next month">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>

                    <div class="alert alert-warning py-2 px-3 small mb-3" x-show="!monthHasSelectable" x-cloak>
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        Nothing can be picked in <span x-text="monthLabel"></span> â€” every day is either in the
                        future or already claimed. Use <i class="fas fa-chevron-left"></i> to go back to an earlier month.
                    </div>

                    <div class="dtr-cal-grid dtr-cal-grid-head">
                        <template x-for="label in ['Su','Mo','Tu','We','Th','Fr','Sa']" :key="label">
                            <div class="dtr-cal-weekday" x-text="label"></div>
                        </template>
                    </div>

                    <div class="dtr-cal-grid">
                        <template x-for="cell in grid" :key="cell.key">
                            <button type="button"
                                    class="dtr-cal-cell"
                                    :class="{
                                        'is-outside': !cell.inMonth,
                                        'is-weekend': cell.weekend && cell.inMonth,
                                        'is-today': cell.today,
                                        'is-selected': cell.selected,
                                        'is-taken': cell.taken,
                                        'is-outside-contract': cell.outsideContract,
                                        'is-disabled': cell.disabled
                                    }"
                                    :disabled="cell.disabled"
                                    :title="cell.taken ? 'Already covered by another DTR' : (cell.outsideContract ? 'Outside your contract dates â€” will be flagged for review' : (cell.selected ? 'Click to remove this day' : 'Click to select this day'))"
                                    @click="toggle(cell)">
                                <span x-text="cell.day"></span>
                            </button>
                        </template>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <div class="d-flex flex-wrap gap-2">
                            <span class="dtr-legend"><span class="dtr-legend-swatch is-selected"></span> Selected</span>
                            <span class="dtr-legend"><span class="dtr-legend-swatch is-taken"></span> Already claimed</span>
                            <span class="dtr-legend"><span class="dtr-legend-swatch is-weekend"></span> Weekend</span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="addWeekdays()" title="Add every weekday in this month">Add weekdays</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="clear()">Clear</button>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
                        <div class="text-muted small" x-text="count + ' day(s) selected Â· ' + monthStats.covered + ' of ' + monthStats.weekdays + ' weekdays this month'"></div>
                        <div class="text-muted small">Working hours are assigned by the Admin after review.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-brand" data-bs-dismiss="modal">Done</button>
                </div>
            </div>
        </div>
    </div>
</div>
