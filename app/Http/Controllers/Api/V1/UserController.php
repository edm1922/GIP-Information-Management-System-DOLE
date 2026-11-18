<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\InternApplication;
use App\Models\User;
use App\Notifications\InternApplicationSubmitted;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::whereIn('role', ['admin', 'peso_manager'])->latest()->paginate(10);

        return response()->json([
            'users' => UserResource::collection($users)->response()->getData(true),
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $defaultPassword = strtolower($validated['last_name']).config('brand.password_suffix');
        $validated['password'] = Hash::make($defaultPassword);
        $validated['is_active'] = $request->boolean('is_active', true);

        $user = User::create($validated);
        AuditService::log('created', 'Created new user', $user);

        // A newly created PESO manager should be aware of applications that are
        // already waiting for first-stage review.
        if ($user->role === 'peso_manager') {
            InternApplication::query()->pending()->get()->each(
                fn (InternApplication $application) => $user->notify(new InternApplicationSubmitted($application))
            );
        }

        return response()->json([
            'message' => 'User created successfully.',
            'default_password' => $defaultPassword,
            'user' => new UserResource($user),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['user' => new UserResource($user->loadMissing('intern'))]);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if ($user->role === 'admin' && ($validated['role'] !== 'admin' || ! $request->boolean('is_active', true))) {
            $adminCount = User::where('role', 'admin')->where('is_active', true)->count();
            if ($adminCount <= 1) {
                $reason = $validated['role'] !== 'admin' ? 'demote' : 'deactivate';
                return response()->json([
                    'message' => "Cannot {$reason} the last active administrator in the system.",
                ], 422);
            }
        }

        $validated['is_active'] = $request->boolean('is_active', true);
        $user->update($validated);
        AuditService::log('updated', 'Updated user', $user);

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => new UserResource($user->fresh()->loadMissing('intern')),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if (Auth::id() === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->role === 'admin') {
            $adminCount = User::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot delete the last administrator in the system.',
                ], 422);
            }
        }

        $username = $user->username;
        $user->delete();
        AuditService::log('deleted', "Deleted user: {$username}");

        return response()->json(['message' => 'User deleted successfully.']);
    }

    public function resetPassword(User $user): JsonResponse
    {
        if (Auth::id() === $user->id) {
            return response()->json([
                'message' => 'You cannot reset your own password here. Please use normal password change mechanisms.',
            ], 422);
        }

        $defaultPassword = strtolower($user->last_name).config('brand.password_suffix');

        $user->update([
            'password' => Hash::make($defaultPassword),
        ]);

        AuditService::log('updated', 'Reset password', $user);

        return response()->json([
            'message' => 'Password reset successfully for '.$user->name.'.',
            'default_password' => $defaultPassword,
        ]);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        if (Auth::id() === $user->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if ($user->role === 'admin' && $user->is_active) {
            $adminCount = User::where('role', 'admin')->where('is_active', true)->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot deactivate the last administrator in the system.',
                ], 422);
            }
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        $status = $user->is_active ? 'activated' : 'deactivated';
        AuditService::log('updated', "User account {$status}", $user);

        return response()->json([
            'message' => "User account has been {$status}.",
            'user' => new UserResource($user->fresh()->loadMissing('intern')),
        ]);
    }
}
