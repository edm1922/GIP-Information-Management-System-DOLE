<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class ForcePasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->must_change_password) {

            // Allow them to navigate to the change password page and process the form/API endpoint
            if (! $request->routeIs(
                'password.force-change',
                'password.force-change.store',
                'logout',
                'api.v1.auth.logout',
                'api.v1.auth.force-password-change.show',
                'api.v1.auth.force-password-change.store',
                'api.v1.auth.user'
            )) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json([
                        'message' => 'Password change required.',
                        'code' => 'PASSWORD_CHANGE_REQUIRED',
                        'must_change_password' => true,
                    ], 403);
                }

                return redirect()->route('password.force-change');
            }
        }

        return $next($request);
    }
}
