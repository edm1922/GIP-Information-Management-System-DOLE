<?php

namespace App\Services;

use App\Models\InternApplication;
use App\Models\InternRegistrationLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generates and validates the shareable self-registration links and accepts
 * public applications. No user/intern account is created here: applications
 * wait for an admin / PESO manager review (see InternApplicationApprovalService).
 */
class InternRegistrationService
{
    /** The currently active link (not revoked and not expired), if any. */
    public function currentLink(): ?InternRegistrationLink
    {
        return InternRegistrationLink::query()
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    /** Revokes any active link and creates a fresh one. */
    public function generate(?User $creator, ?int $ttlDays = null): InternRegistrationLink
    {
        $ttl = $ttlDays ?? (int) config('internship.registration.link_ttl_days', 30);

        return DB::transaction(function () use ($creator, $ttl) {
            InternRegistrationLink::query()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return InternRegistrationLink::create([
                'token' => Str::random(64),
                'created_by' => $creator?->id,
                'expires_at' => $ttl > 0 ? now()->addDays($ttl) : null,
            ]);
        });
    }

    public function revoke(InternRegistrationLink $link): InternRegistrationLink
    {
        if (! $link->isRevoked()) {
            $link->update(['revoked_at' => now()]);
        }

        return $link->fresh();
    }

    public function resolve(string $token): ?InternRegistrationLink
    {
        return InternRegistrationLink::query()->where('token', $token)->first();
    }

    /** Public SPA URL that the QR code and copied link point at. */
    public function url(InternRegistrationLink $link): string
    {
        $base = rtrim((string) config('internship.registration.frontend_url'), '/');

        return $base.'/register/'.$link->token;
    }

    /**
     * Store a pending application for an active link.
     *
     * @throws ValidationException when the email already has a live application
     *                             or belongs to an existing account.
     */
    public function submit(InternRegistrationLink $link, array $data): InternApplication
    {
        $email = strtolower(trim((string) $data['email']));

        return DB::transaction(function () use ($link, $data, $email) {
            $busyApplication = InternApplication::query()
                ->where('email', $email)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->first();

            if ($busyApplication) {
                throw ValidationException::withMessages([
                    'email' => 'An application with this email address is already pending or approved.',
                ]);
            }

            if (User::withTrashed()->where('email', $email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email address already exists.',
                ]);
            }

            $application = InternApplication::create([
                'reference' => $this->reference(),
                'registration_link_id' => $link->id,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'email' => $email,
                'contact_number' => $data['contact_number'] ?? null,
                'school' => $data['school'] ?? null,
                'course' => $data['course'] ?? null,
                'partner_agency_id' => $data['partner_agency_id'] ?? null,
                'intern_type' => $data['intern_type'] ?? null,
                'preferred_start_date' => $data['preferred_start_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);

            $link->increment('uses_count');
            $link->forceFill(['last_used_at' => now()])->save();

            AuditService::log('created', 'Submitted intern application', $application);

            return $application;
        });
    }

    private function reference(): string
    {
        do {
            $reference = 'GIMS-APL-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (InternApplication::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
