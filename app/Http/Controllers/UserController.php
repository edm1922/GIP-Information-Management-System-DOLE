<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Requests\UserRequest;
use App\Services\AuditService;
use App\Support\OtherSessionInvalidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::whereIn('role', ['admin', 'peso_manager'])->latest()->paginate(10);

        return view('users.index', compact('users'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('users.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(UserRequest $request)
    {
        $validated = $request->validated();

        // SEC-02: credentials are random and one-time; never derived from user
        // data (which made them guessable from the last name).
        $temporaryPassword = Str::password(16);
        $validated['password'] = Hash::make($temporaryPassword);
        $validated['must_change_password'] = true;

        $validated['is_active'] = $request->boolean('is_active', true);
        
        $user = User::create($validated);

        AuditService::log('created', "Created new user", $user);

        return redirect()->route('settings.users.index')
                         ->with('success', 'User created successfully. Temporary password: ' . $temporaryPassword);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        abort(404);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UserRequest $request, User $user)
    {
        $validated = $request->validated();

        // Prevent deactivating or demoting the last administrator
        if ($user->role === 'admin' && ($validated['role'] !== 'admin' || !$request->boolean('is_active', true))) {
            $adminCount = User::where('role', 'admin')->where('is_active', true)->count();
            if ($adminCount <= 1) {
                $reason = $validated['role'] !== 'admin' ? 'demote' : 'deactivate';
                return back()->with('error', "Cannot {$reason} the last active administrator in the system.");
            }
        }

        $validated['is_active'] = $request->boolean('is_active', true);

        $user->update($validated);

        AuditService::log('updated', "Updated user", $user);

        return redirect()->route('settings.users.index')
                         ->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        if (Auth::id() === $user->id) {
            return redirect()->route('settings.users.index')
                             ->with('error', 'You cannot delete your own account.');
        }

        // Prevent deleting the last administrator
        if ($user->role === 'admin') {
            $adminCount = User::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return redirect()->route('settings.users.index')
                                 ->with('error', 'Cannot delete the last administrator in the system.');
            }
        }

        $username = $user->username;
        $user->delete();

        AuditService::log('deleted', "Deleted user: {$username}");
        return redirect()->route('settings.users.index')
                         ->with('success', 'User deleted successfully.');
    }

    /**
     * Reset the user's password to the default systematically.
     */
    public function resetPassword(User $user)
    {
        // Don't allow resetting own password to avoid lockouts during an active session
        if (Auth::id() === $user->id) {
            return redirect()->route('settings.users.index')
                             ->with('error', 'You cannot reset your own password here. Please use normal password change mechanisms.');
        }

        // SEC-02: random one-time credential, and the user must rotate it on
        // the next login.
        $temporaryPassword = Str::password(16);
        
        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ]);

        // SEC-07: a reset credential must not leave the target's live sessions
        // authenticated with the old password.
        OtherSessionInvalidator::invalidate($user);

        AuditService::log('updated', "Reset password", $user);

        return redirect()->route('settings.users.index')
                         ->with('success', 'Password reset successfully for ' . $user->name . '. Temporary password: ' . $temporaryPassword);
    }

    /**
     * Toggle the user's active status.
     */
    public function toggleStatus(User $user)
    {
        if (Auth::id() === $user->id) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        // Prevent deactivating the last administrator
        if ($user->role === 'admin' && $user->is_active) {
            $adminCount = User::where('role', 'admin')->where('is_active', true)->count();
            if ($adminCount <= 1) {
                return back()->with('error', 'Cannot deactivate the last administrator in the system.');
            }
        }

        $user->is_active = !$user->is_active;
        $user->save();

        $status = $user->is_active ? 'activated' : 'deactivated';
        AuditService::log('updated', "User account {$status}", $user);

        return back()->with('success', "User account has been {$status}.");
    }
}
