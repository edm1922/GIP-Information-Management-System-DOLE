<?php

namespace App\Policies;

use App\Models\InternApplication;
use App\Models\User;

/**
 * Only Admins and PESO Managers may review self-registration applications.
 * Interns (and guests, who have no policy at all) are always denied.
 */
class InternApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function view(User $user, InternApplication $application): bool
    {
        if ($user->isAdmin()) {
            // Admins only see an application after the PESO decision.
            return $application->status !== 'pending';
        }

        return $user->isPesoManager();
    }

    public function approve(User $user, InternApplication $application): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function reject(User $user, InternApplication $application): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function resendCredentials(User $user, InternApplication $application): bool
    {
        return $user->hasRole('admin', 'peso_manager') && $application->status === 'approved';
    }
}
