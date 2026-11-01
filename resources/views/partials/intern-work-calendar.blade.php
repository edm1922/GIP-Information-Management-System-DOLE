@push('styles')
<style>
    .work-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .3rem; }
    .work-cal-weekday { text-align: center; font-size: .72rem; font-weight: 700; color: #94a3b8; padding: .2rem 0; text-transform: uppercase; letter-spacing: .03em; }
    .work-cal-cell {
        aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center;
        border-radius: .55rem; font-size: .85rem; font-weight: 600;
        background: #f8fafc; color: #475569; border: 1px solid transparent;
        position: relative;
    }
    .work-cal-cell.is-weekend { background: #f1f5f9; color: #94a3b8; font-weight: 500; }
    .work-cal-cell.is-outside { opacity: .22; }
    .work-cal-cell.is-outside-contract { opacity: .35; background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 4px, #eef2f6 4px, #eef2f6 8px); }
    .work-cal-cell.is-approved { background: #dcfce7; color: #15803d; border-color: #86efac; }
    .work-cal-cell.is-pending { background: #fef3c7; color: #b45309; border-color: #fcd34d; }
    .work-cal-cell.is-rejected { background: #fee2e2; color: #b91c1c; border-color: #fca5a5; text-decoration: line-through; }
    .work-cal-cell.is-today { box-shadow: inset 0 0 0 2px {{ config('brand.primary_color') }}; }
    .work-cal-legend { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; color: #64748b; }
    .work-cal-legend-swatch { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
</style>
@endpush

@php
    $cal = $workCalendar;
    $statusLabels = [
        'approved' => 'Approved',
        'pending' => 'Pending review',
        'rejected' => 'Rejected',
    ];
@endphp

<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h6 class="text-uppercase text-muted fw-bold mb-1" style="font-size: 0.75rem;">
                    <i class="fas fa-calendar-days me-1" style="color: {{ config('brand.primary_color') }};"></i>
                    Work Calendar
                </h6>
                <p class="text-muted mb-0" style="font-size: 0.8rem;">
                    Days you claimed on your submitted DTRs. The scan is evidence — these days are your record.
                </p>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('dashboard', ['work_month' => $cal['prev_month']]) }}"
                   class="btn btn-sm btn-outline-secondary" aria-label="Previous month">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <span class="fw-semibold px-1" style="min-width: 130px; text-align: center;">{{ $cal['label'] }}</span>
                <a href="{{ route('dashboard', ['work_month' => $cal['next_month']]) }}"
                   class="btn btn-sm btn-outline-secondary" aria-label="Next month">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="work-cal-grid">
                    @foreach(['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $weekday)
                        <div class="work-cal-weekday">{{ $weekday }}</div>
                    @endforeach

                    @foreach($cal['cells'] as $cell)
                        @php
                            $classes = ['work-cal-cell'];
                            if (! $cell['in_month']) $classes[] = 'is-outside';
                            if ($cell['weekend']) $classes[] = 'is-weekend';
                            if ($cell['in_month'] && ! $cell['in_contract'] && ! $cell['status']) $classes[] = 'is-outside-contract';
                            if ($cell['today']) $classes[] = 'is-today';
                            if ($cell['status']) $classes[] = 'is-' . $cell['status'];
                        @endphp
                        <div class="{{ implode(' ', $classes) }}"
                             data-date="{{ $cell['date'] }}"
                             data-status="{{ $cell['status'] ?? 'none' }}"
                             title="{{ \Illuminate\Support\Carbon::parse($cell['date'])->format('M d, Y') }}@if($cell['status']) · {{ $statusLabels[$cell['status']] }} · {{ rtrim(rtrim(number_format($cell['hours'], 1, '.', ''), '0'), '.') }} hrs @endif">
                            {{ $cell['day'] }}
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="col-lg-4">
                <div class="p-3 rounded-3 h-100" style="background: #f8fafc;">
                    <div class="mb-3">
                        <div class="text-muted" style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700;">This month</div>
                        <div class="fw-bold fs-4 mb-0">{{ $cal['stats']['covered_days'] }} <span class="text-muted fw-normal" style="font-size: 0.9rem;">of {{ $cal['stats']['working_days'] }} weekdays</span></div>
                        <div class="text-muted" style="font-size: 0.8rem;">
                            {{ rtrim(rtrim(number_format($cal['stats']['hours'], 1, '.', ''), '0'), '.') }} hrs claimed
                        </div>
                    </div>

                    <div class="d-flex flex-column gap-2">
                        <span class="work-cal-legend">
                            <span class="work-cal-legend-swatch" style="background: #dcfce7; border: 1px solid #86efac;"></span>
                            Approved DTR
                        </span>
                        <span class="work-cal-legend">
                            <span class="work-cal-legend-swatch" style="background: #fef3c7; border: 1px solid #fcd34d;"></span>
                            Pending review
                        </span>
                        <span class="work-cal-legend">
                            <span class="work-cal-legend-swatch" style="background: #fee2e2; border: 1px solid #fca5a5;"></span>
                            Rejected DTR
                        </span>
                        <span class="work-cal-legend">
                            <span class="work-cal-legend-swatch" style="background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 2px, #eef2f6 2px, #eef2f6 4px); border: 1px solid #e2e8f0;"></span>
                            Outside contract
                        </span>
                    </div>

                    @if($cal['stats']['covered_days'] === 0)
                        <div class="alert alert-light border mt-3 mb-0" style="font-size: 0.8rem;">
                            <i class="fas fa-info-circle me-1" style="color: {{ config('brand.primary_color') }};"></i>
                            No DTR days recorded for this month.
                            <a href="{{ route('my-internship.index') }}" class="fw-semibold text-decoration-none">Upload a DTR</a>
                            and pick the days you worked.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
