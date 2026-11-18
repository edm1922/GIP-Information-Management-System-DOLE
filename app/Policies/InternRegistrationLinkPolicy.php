<?php

namespace App\Policies;

use App\Models\InternRegistrationLink;
use App\Models\User;

/** Admins and PESO Managers share/manage the public registration link. */
class InternRegistrationLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function manage(User $user): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function view(User $user, InternRegistrationLink $link): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }
}
