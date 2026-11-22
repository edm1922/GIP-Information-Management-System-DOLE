<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\OtherSessionInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ForcePasswordChangeController extends Controller
{
    /**
     * Show the force password change view.
     */
    public function show()
    {
        return view('auth.force-password-change');
    }

    /**
     * Handle an incoming password change request.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // SEC-07: the current password is only waived while an account is
        // genuinely forced to rotate a temporary credential. For every other
        // user this is an ordinary password change and must prove the old one.
        $rules = [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];

        if (! $user->must_change_password) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $validated = $request->validate($rules);

        $before = [
            'must_change_password' => (bool) $user->must_change_password,
        ];

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        // SEC-07: sessions created with the old credential must not survive.
        OtherSessionInvalidator::invalidate(
            $user,
            $request->hasSession() ? $request->session()->getId() : null
        );

        AuditService::log('updated', 'Completed forced password change.', $user, [
            'before' => $before,
            'after' => [
                'must_change_password' => false,
            ],
        ]);

        return redirect()->intended(route('dashboard', absolute: false))
            ->with('success', 'Your password has been changed successfully.');
    }
}
