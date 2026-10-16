<?php

namespace App\Policies;

use App\Models\Intern;
use App\Models\User;

class InternPolicy
{
    /**
     * Admin and PESO Manager may browse the intern list.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    /**
     * Admin/PESO may view any intern; an intern may view only their own record.
     */
    public function view(User $user, Intern $intern): bool
    {
        if ($user->hasRole('admin', 'peso_manager')) {
            return true;
        }

        return $user->isIntern() && $intern->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Intern $intern): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Intern $intern): bool
    {
        return $user->hasRole('admin');
    }

    public function restore(User $user, Intern $intern): bool
    {
        return $user->hasRole('admin');
    }

    public function forceDelete(User $user, Intern $intern): bool
    {
        return $user->hasRole('admin');
    }
}
