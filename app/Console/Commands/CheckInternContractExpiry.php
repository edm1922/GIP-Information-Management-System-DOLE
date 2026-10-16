<?php

namespace App\Console\Commands;

use App\Models\Intern;
use App\Notifications\InternStatusAlert;
use App\Services\InternNotificationService;
use Illuminate\Console\Command;

class CheckInternContractExpiry extends Command
{
    protected $signature = 'interns:check-contract-expiry';

    protected $description = 'Flag expired intern contracts and remind the PESO team of upcoming contract end dates';

    public function __construct(
        private readonly InternNotificationService $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = now()->startOfDay();
        $warningDays = (int) config('internship.contract_expiry_warning_days', 7);
        $thresholds = collect([$warningDays, 3, 1])->filter(fn (int $d) => $d > 0)->unique();

        $reminders = 0;
        foreach ($thresholds as $days) {
            $targetDate = $today->copy()->addDays($days)->toDateString();

            $interns = Intern::query()
                ->active()
                ->whereDate('contract_end_date', $targetDate)
                ->get();

            foreach ($interns as $intern) {
                $this->notifier->notifyPesoTeam(new InternStatusAlert($intern, 'contract_expiring'));
                $reminders++;
            }
        }

        $expired = Intern::query()
            ->active()
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<', $today->toDateString())
            ->get();

        foreach ($expired as $intern) {
            $intern->update(['status' => 'inactive']);
            $this->notifier->notifyPesoTeam(new InternStatusAlert($intern, 'expired'));
        }

        $this->info("Contract expiry: {$reminders} reminder(s) sent, {$expired->count()} intern(s) marked inactive.");

        return self::SUCCESS;
    }
}
