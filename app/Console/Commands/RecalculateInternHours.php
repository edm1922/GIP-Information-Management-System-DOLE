<?php

namespace App\Console\Commands;

use App\Models\Intern;
use App\Services\InternHoursService;
use Illuminate\Console\Command;

class RecalculateInternHours extends Command
{
    protected $signature = 'interns:recalculate-hours';

    protected $description = 'Recompute interns.rendered_hours from approved DTR days (pending/rejected no longer count)';

    public function handle(InternHoursService $hours): int
    {
        $changed = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach (Intern::withTrashed()->get() as $intern) {
            if (! $hours->hasStructuredDays($intern)) {
                $skipped++;
                $this->line("intern #{$intern->id}: skipped (no structured DTR days — legacy hours kept)");

                continue;
            }

            $before = (int) $intern->rendered_hours;
            $after = $hours->recalculate($intern);

            if ($before !== $after) {
                $changed++;
                $this->line("intern #{$intern->id}: {$before} -> {$after} hrs");
            } else {
                $unchanged++;
            }
        }

        $this->info("Done. changed={$changed}, unchanged={$unchanged}, skipped={$skipped}");

        return self::SUCCESS;
    }
}
