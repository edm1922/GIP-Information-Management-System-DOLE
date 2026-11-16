<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Resources\V1\UserResource;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->loadMissing('intern')),
        ]);
    }

    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $before = $user->only(['first_name', 'middle_name', 'last_name', 'suffix', 'username']);

        $user->fill($request->validated());
        $after = $user->only(['first_name', 'middle_name', 'last_name', 'suffix', 'username']);
        $user->save();

        if ($before !== $after) {
            AuditService::log('updated', 'Updated profile information.', $user, [
                'before' => $before,
                'after' => $after,
            ]);
        }

        return response()->json([
            'message' => 'Profile updated.',
            'status' => 'profile-updated',
            'user' => new UserResource($user->fresh()->loadMissing('intern')),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $snapshot = [
            'first_name' => $user->first_name,
            'middle_name' => $user->middle_name,
            'last_name' => $user->last_name,
            'suffix' => $user->suffix,
            'username' => $user->username,
            'role' => $user->role,
            'self_service' => true,
        ];

        AuditService::log('deleted', 'Deleted own user account.', $user, [
            'before' => $snapshot,
            'after' => [],
        ]);

        Auth::logout();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Account deleted.']);
    }
}
