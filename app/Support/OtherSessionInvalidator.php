<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEC-07 (WP-R1): after a credential change, sessions created with the old
 * password must stop working.
 *
 * Only the database session driver keeps sessions in a queryable table
 * (`sessions.user_id`), so that is the only driver where targeted invalidation
 * is possible. Under the array driver (tests) or cookie/file drivers there is
 * no supported, race-free way to enumerate a user's sessions from application
 * code, so this is a documented limitation of those drivers rather than a
 * silently skipped control: production runs the database driver.
 */
final class OtherSessionInvalidator
{
    public static function invalidate(User $user, ?string $currentSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        try {
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
                ->delete();
        } catch (Throwable $e) {
            // Never let session housekeeping fail a successful password change,
            // but leave a trace so a silent invalidation failure is diagnosable.
            Log::warning('Failed to invalidate other sessions after a credential change.', [
                'user_id' => $user->getAuthIdentifier(),
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
