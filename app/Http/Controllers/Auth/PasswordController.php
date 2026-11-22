<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\OtherSessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // SEC-07: other sessions keep working with the old password otherwise.
        OtherSessionInvalidator::invalidate(
            $request->user(),
            $request->hasSession() ? $request->session()->getId() : null
        );

        AuditService::log('updated', 'Changed account password.', $request->user());

        return back()->with('status', 'password-updated');
    }
}
