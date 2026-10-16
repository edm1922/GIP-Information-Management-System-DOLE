<?php

namespace App\Policies;

use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;

class InternDocumentPolicy
{
    /**
     * Admin and PESO Manager may browse all intern documents.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    /**
     * Admin/PESO may view any intern document; an intern may view only their own.
     */
    public function view(User $user, InternDocument $document): bool
    {
        if ($user->hasRole('admin', 'peso_manager')) {
            return true;
        }

        return $user->isIntern() && $document->intern?->user_id === $user->id;
    }

    /**
     * An intern may upload documents for their own record; admin may upload on behalf.
     */
    public function create(User $user, ?Intern $intern = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isIntern() && $intern !== null && $intern->user_id === $user->id;
    }

    /**
     * Only Admin and PESO Manager may approve/reject documents.
     */
    public function review(User $user, InternDocument $document): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function download(User $user, InternDocument $document): bool
    {
        return $this->view($user, $document);
    }

    public function delete(User $user, InternDocument $document): bool
    {
        return $user->hasRole('admin');
    }
}
