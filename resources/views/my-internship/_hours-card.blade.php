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
                $leftoverH  = round($hoursLeft - ($daysLeft * 8), 1);
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
