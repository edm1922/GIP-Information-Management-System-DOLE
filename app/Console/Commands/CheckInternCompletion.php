<?php

namespace App\Console\Commands;

use App\Models\Intern;
use App\Notifications\InternStatusAlert;
use App\Services\InternNotificationService;
use Illuminate\Console\Command;

class CheckInternCompletion extends Command
{
    protected $signature = 'interns:check-completion';

    protected $description = 'Mark interns as completed once they reach the required-hours threshold';

    public function __construct(
        private readonly InternNotificationService $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $threshold = (float) config('internship.completion_threshold', 90);
        $ratio = max(0, $threshold) / 100;

        $interns = Intern::query()
            ->active()
            ->where('required_hours', '>', 0)
            ->whereRaw('rendered_hours >= (required_hours * ?)', [$ratio])
            ->get();

        foreach ($interns as $intern) {
            $intern->update(['status' => 'completed']);
            $this->notifier->notifyPesoTeam(new InternStatusAlert($intern, 'completed'));
        }

        $this->info("Completion check: {$interns->count()} intern(s) marked as completed.");

        return self::SUCCESS;
    }
}
