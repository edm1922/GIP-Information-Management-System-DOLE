<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-06 (WP-R1): deactivation must revoke access immediately.
 *
 * LoginRequest already rejects inactive accounts at login time, but a session
 * created before the account was deactivated stayed valid until it expired.
 * This middleware runs for every web and API request, so an authenticated but
 * inactive user is logged out and refused on the very next request.
 *
 * Unauthenticated requests (login, public registration, health check) are not
 * affected; the check only applies once a user is resolved.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Note the strict `=== false`: a model instance that was not loaded
        // with the column (e.g. a freshly created factory model whose DB
        // default was not hydrated yet) must not be treated as deactivated.
        // A persisted `is_active` boolean casts to exactly false, so the
        // deactivation control itself is unaffected.
        if ($user !== null && $user->is_active === false) {
            // Drop the session so the deactivated cookie cannot be reused.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'This account has been deactivated.',
                    'code' => 'ACCOUNT_DEACTIVATED',
                ], 403);
            }

            return redirect()
                ->route('login')
                ->withErrors(['username' => 'This account has been deactivated. Please contact the PESO office.']);
        }

        return $next($request);
    }
}
