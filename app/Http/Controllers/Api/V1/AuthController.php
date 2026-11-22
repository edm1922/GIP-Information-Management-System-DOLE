<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\AuditService;
use App\Support\OtherSessionInvalidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = Auth::user();
        $user->update(['last_active_at' => now()]);
        AuditService::logLogin();

        return response()->json([
            'message' => 'Authenticated.',
            'user' => new UserResource($user->loadMissing('intern')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditService::logLogout();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->loadMissing('intern')),
        ]);
    }

    public function showForcePasswordChange(Request $request): JsonResponse
    {
        return response()->json([
            'must_change_password' => (bool) $request->user()?->must_change_password,
        ]);
    }

    public function storeForcePasswordChange(Request $request): JsonResponse
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

        return response()->json([
            'message' => 'Your password has been changed successfully.',
            'user' => new UserResource($user->fresh()->loadMissing('intern')),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
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

        return response()->json([
            'message' => 'Password updated.',
            'status' => 'password-updated',
        ]);
    }
}
