<?php

namespace App\Services;

use App\Models\DtrDay;
use App\Models\Intern;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the month grid shown on the intern dashboard from the working days the
 * intern claimed on their DTRs (structured data), not from the uploaded scans.
 */
class InternWorkCalendar
{
    /**
     * @return array{
     *     month: string, label: string, prev_month: string, next_month: string,
     *     cells: array<int, array<string, mixed>>,
     *     stats: array{working_days: int, covered_days: int, hours: float}
     * }
     */
    public function month(Intern $intern, ?string $month = null): array
    {
        $anchor = $this->resolveMonth($month) ?? now()->startOfMonth();
        $monthStart = $anchor->copy()->startOfMonth();
        $monthEnd = $anchor->copy()->endOfMonth();

        /** @var Collection<string, Collection<int, DtrDay>> $claimedDays */
        $claimedDays = DtrDay::query()
            ->where('intern_id', $intern->id)
            ->whereDate('work_date', '>=', $monthStart->toDateString())
            ->whereDate('work_date', '<=', $monthEnd->toDateString())
            ->with(['document:id,document_type_id,review_status', 'document.documentType:id,code,name'])
            ->get()
            ->groupBy(fn (DtrDay $day) => $day->work_date->format('Y-m-d'));

        $contractStart = $intern->contract_start_date?->copy()->startOfDay();
        $contractEnd = $intern->contract_end_date?->copy()->endOfDay();
        $today = now()->startOfDay();

        $gridStart = $monthStart->copy()->subDays((int) $monthStart->dayOfWeek);
        $cells = [];

        for ($i = 0; $i < 42; $i++) {
            $date = $gridStart->copy()->addDays($i);
            $key = $date->format('Y-m-d');
            $claimed = $claimedDays->get($key, collect());

            $inContract = (! $contractStart || $date->greaterThanOrEqualTo($contractStart))
                && (! $contractEnd || $date->lessThanOrEqualTo($contractEnd));

            $cells[] = [
                'date' => $key,
                'day' => (int) $date->format('j'),
                'in_month' => $date->month === $monthStart->month,
                'weekend' => $date->isWeekend(),
                'today' => $date->isSameDay($today),
                'status' => $this->resolveStatus($claimed),
                'hours' => (float) $claimed->sum('hours'),
                'in_contract' => $inContract,
                'documents' => $claimed->map(fn (DtrDay $day) => [
                    'id' => $day->document_id,
                    'type' => $day->document?->documentType?->code,
                    'name' => $day->document?->documentType?->name,
                    'status' => $day->document?->review_status,
                    'hours' => (float) $day->hours,
                ])->values()->all(),
            ];
        }

        $workingDays = 0;
        $coveredDays = 0;
        $hours = 0.0;

        foreach ($cells as $cell) {
            if (! $cell['in_month'] || $cell['weekend'] || ! $cell['in_contract']) {
                continue;
            }

            $workingDays++;

            if ($cell['status'] !== null) {
                $coveredDays++;
                $hours += $cell['hours'];
            }
        }

        return [
            'month' => $monthStart->format('Y-m'),
            'label' => $monthStart->format('F Y'),
            'prev_month' => $monthStart->copy()->subMonth()->format('Y-m'),
            'next_month' => $monthStart->copy()->addMonth()->format('Y-m'),
            'cells' => $cells,
            'stats' => [
                'working_days' => $workingDays,
                'covered_days' => $coveredDays,
                'hours' => $hours,
            ],
        ];
    }

    /**
     * A day may be claimed by several DTRs (e.g. one was rejected and replaced);
     * the strongest status wins.
     */
    private function resolveStatus(Collection $claimed): ?string
    {
        if ($claimed->isEmpty()) {
            return null;
        }

        $statuses = $claimed
            ->map(fn (DtrDay $day) => $day->document?->review_status)
            ->filter()
            ->unique();

        $mapped = $statuses->map(fn ($status) => match ($status) {
            'approved' => 'approved',
            'pending_peso', 'pending_admin', 'pending' => 'pending',
            'returned_to_peso', 'returned_to_intern', 'rejected' => 'rejected',
            default => null,
        })->filter();

        foreach (['approved', 'pending', 'rejected'] as $status) {
            if ($mapped->contains($status)) {
                return $status;
            }
        }

        return null;
    }

    private function resolveMonth(?string $month): ?Carbon
    {
        if (! $month) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}
