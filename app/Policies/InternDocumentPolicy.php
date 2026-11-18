<?php

namespace App\Policies;

use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;

class InternDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'peso_manager');
    }

    public function view(User $user, InternDocument $document): bool
    {
        if ($user->hasRole('admin', 'peso_manager')) {
            return true;
        }

        return $user->isIntern() && $document->intern?->user_id === $user->id;
    }

    public function create(User $user, ?Intern $intern = null): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isIntern() && $intern !== null && $intern->user_id === $user->id;
    }

    /** PESO managers review the first stage (initial or after an Admin return). */
    public function reviewAsPeso(User $user, InternDocument $document): bool
    {
        return $user->isPesoManager()
            && in_array($document->review_status, [
                InternDocument::STATUS_PENDING_PESO,
                InternDocument::STATUS_RETURNED_TO_PESO,
            ], true);
    }

    /** Admins perform the final approval / return. */
    public function reviewAsAdmin(User $user, InternDocument $document): bool
    {
        return $user->isAdmin() && $document->review_status === InternDocument::STATUS_PENDING_ADMIN;
    }

    /** Only Admins assign working hours, and only while awaiting final approval. */
    public function assignHours(User $user, InternDocument $document): bool
    {
        return $user->isAdmin() && $document->review_status === InternDocument::STATUS_PENDING_ADMIN;
    }

    /** Interns may correct/resubmit only their own returned documents. */
    public function resubmit(User $user, InternDocument $document): bool
    {
        return $user->isIntern()
            && $document->intern?->user_id === $user->id
            && $document->review_status === InternDocument::STATUS_RETURNED_TO_INTERN;
    }

    /** Legacy ability used by the backward-compatible single-step review endpoint. */
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
