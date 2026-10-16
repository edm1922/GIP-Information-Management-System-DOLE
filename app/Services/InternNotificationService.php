<?php

namespace App\Services;

use App\Models\Intern;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resolves and dispatches intern-related notifications to the right people.
 */
class InternNotificationService
{
    /**
     * Users who oversee interns: administrators and PESO managers.
     *
     * @return Collection<int, User>
     */
    public function pesoTeam(): Collection
    {
        return User::query()
            ->whereIn('role', ['admin', 'peso_manager'])
            ->where('is_active', true)
            ->get();
    }

    public function notifyPesoTeam(Notification $notification): void
    {
        foreach ($this->pesoTeam() as $user) {
            $user->notify($notification);
        }
    }

    public function notifyIntern(Intern $intern, Notification $notification): void
    {
        $intern->user?->notify($notification);
    }
}
