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

    /** Only PESO managers (used for the first-stage review). @return Collection<int, User> */
    public function pesoManagers(): Collection
    {
        return User::query()->where('role', 'peso_manager')->where('is_active', true)->get();
    }

    /** Only administrators (used for final approval). @return Collection<int, User> */
    public function admins(): Collection
    {
        return User::query()->where('role', 'admin')->where('is_active', true)->get();
    }

    public function notifyPesoManagers(Notification $notification): void
    {
        foreach ($this->pesoManagers() as $user) {
            $user->notify($notification);
        }
    }

    public function notifyAdmins(Notification $notification): void
    {
        foreach ($this->admins() as $user) {
            $user->notify($notification);
        }
    }

    public function notifyIntern(Intern $intern, Notification $notification): void
    {
        $intern->user?->notify($notification);
    }
}
