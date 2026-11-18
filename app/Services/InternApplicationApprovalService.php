<?php

namespace App\Services;

use App\Mail\InternCredentialsMail;
use App\Models\Intern;
use App\Models\InternApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a pending application into a real intern + login account.
 *
 * The temporary password only ever lives in memory and in the credential
 * email; only the hash is persisted. A failed email keeps the approved
 * account and is retried with a freshly rotated password.
 */
class InternApplicationApprovalService
{
    /**
     * @param  array<string, mixed>  $data  placement/account fields from the reviewer
     *
     * @throws ValidationException
     */
    public function approve(InternApplication $application, User $reviewer, array $data): InternApplication
    {
        $plainPassword = null;

        $application = DB::transaction(function () use ($application, $reviewer, $data, &$plainPassword) {
            $locked = InternApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'This application has already been reviewed.',
                ]);
            }

            $username = $this->suggestUsername($locked);

            if (User::query()->where('username', $username)->exists()) {
                throw ValidationException::withMessages([
                    'username' => 'This username is already taken.',
                ]);
            }

            if (User::query()->where('email', $locked->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'An account with this email address already exists.',
                ]);
            }

            $plainPassword = $this->temporaryPassword();

            $user = User::create([
                'first_name' => $locked->first_name,
                'middle_name' => $locked->middle_name,
                'last_name' => $locked->last_name,
                'suffix' => $locked->suffix,
                'username' => $username,
                'email' => $locked->email,
                'password' => Hash::make($plainPassword),
                'role' => 'intern',
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $intern = Intern::create([
                'user_id' => $user->id,
                'partner_agency_id' => $data['partner_agency_id'] ?? $locked->partner_agency_id,
                'contract_start_date' => $data['contract_start_date'] ?? null,
                'contract_end_date' => $data['contract_end_date'] ?? null,
                'required_hours' => $data['required_hours'] ?? config('internship.default_required_hours', 486),
                'rendered_hours' => 0,
                'status' => $data['status'] ?? 'active',
                'intern_type' => $data['intern_type'] ?? $locked->intern_type,
                'batch' => $data['batch'] ?? null,
                'beneficiary' => $data['beneficiary'] ?? null,
                'school' => $locked->school,
                'course' => $locked->course,
                'contact_number' => $locked->contact_number,
                'supervisor' => $data['supervisor'] ?? null,
            ]);

            $locked->forceFill([
                'status' => 'approved',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'approved_user_id' => $user->id,
                'approved_intern_id' => $intern->id,
                'credential_email_status' => 'pending',
                'credential_email_error' => null,
            ])->save();

            AuditService::log('created', "Approved intern application {$locked->reference}", $locked);

            return $locked;
        });

        $this->sendCredentials($application, $plainPassword, $reviewer);

        return $application->fresh();
    }

    /** @throws ValidationException */
    public function reject(InternApplication $application, User $reviewer, string $reason): InternApplication
    {
        $application = DB::transaction(function () use ($application, $reviewer, $reason) {
            $locked = InternApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'This application has already been reviewed.',
                ]);
            }

            $locked->forceFill([
                'status' => 'rejected',
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            AuditService::log('rejected', "Rejected intern application {$locked->reference}", $locked);

            return $locked;
        });

        return $application;
    }

    /**
     * Rotate the temporary password and re-send the credential email for an
     * already-approved application (used when Gmail delivery failed or the
     * applicant lost the message).
     *
     * @throws ValidationException
     */
    public function resendCredentials(InternApplication $application): InternApplication
    {
        $plainPassword = null;

        DB::transaction(function () use ($application, &$plainPassword) {
            $locked = InternApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved' || ! $locked->approved_user_id) {
                throw ValidationException::withMessages([
                    'status' => 'Only approved applications have credentials to send.',
                ]);
            }

            $user = User::query()->find($locked->approved_user_id);
            if (! $user) {
                throw ValidationException::withMessages([
                    'status' => 'The linked login account could not be found.',
                ]);
            }

            $plainPassword = $this->temporaryPassword();
            $user->forceFill([
                'password' => Hash::make($plainPassword),
                'must_change_password' => true,
            ])->save();

            $locked->forceFill([
                'credential_email_status' => 'pending',
                'credential_email_error' => null,
            ])->save();
        });

        $this->sendCredentials($application->fresh(), $plainPassword, $application->reviewer);

        return $application->fresh();
    }

    /**
     * Standard intern username: LASTNAME + "_" + first two letters of the
     * given name, lower-cased and stripped of punctuation (e.g. "delacruz_ma").
     * A numeric suffix is appended on collisions.
     */
    public function suggestUsername(InternApplication $application): string
    {
        $lastName = Str::slug((string) $application->last_name, '');
        $firstName = Str::slug((string) $application->first_name, '');

        $base = ($lastName !== '' ? $lastName : 'intern').'_'.substr($firstName !== '' ? $firstName : 'in', 0, 2);

        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.$suffix;
        }

        return $candidate;
    }

    private function temporaryPassword(): string
    {
        // Letters + digits only: easy to type on a phone, ~82 bits of entropy.
        return Str::password(14, letters: true, numbers: true, symbols: false, spaces: false);
    }

    private function sendCredentials(InternApplication $application, string $plainPassword, ?User $reviewer = null): void
    {
        $username = $application->approvedUser?->username ?? '';

        try {
            Mail::to($application->email)->send(new InternCredentialsMail(
                application: $application,
                username: $username,
                temporaryPassword: $plainPassword,
                loginUrl: rtrim((string) config('internship.registration.frontend_url'), '/').'/login',
            ));

            $application->forceFill([
                'credential_email_status' => 'sent',
                'credential_email_sent_at' => now(),
                'credential_email_error' => null,
                'credential_email_attempts' => $application->credential_email_attempts + 1,
            ])->save();

            AuditService::log('notified', "Sent credential email for {$application->reference}", $application);
        } catch (\Throwable $exception) {
            // Keep the approved account; the reviewer can retry (which rotates
            // the temporary password). Never log the password itself.
            $application->forceFill([
                'credential_email_status' => 'failed',
                'credential_email_error' => Str::limit($exception->getMessage(), 1000),
                'credential_email_attempts' => $application->credential_email_attempts + 1,
            ])->save();

            AuditService::log(
                'notified',
                "Credential email FAILED for {$application->reference}",
                $application,
                ['error' => Str::limit($exception->getMessage(), 500)]
            );
        }
    }
}
