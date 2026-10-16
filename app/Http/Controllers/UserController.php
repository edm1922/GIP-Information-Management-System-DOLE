<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Requests\UserRequest;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

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
        
        // Auto-generate default password: {last_name}{suffix}
        $defaultPassword = strtolower($validated['last_name']) . config('brand.password_suffix');
        $validated['password'] = Hash::make($defaultPassword);
        
        $validated['is_active'] = $request->boolean('is_active', true);
        
        $user = User::create($validated);

        AuditService::log('created', "Created new user", $user);

        return redirect()->route('settings.users.index')
                         ->with('success', 'User created successfully. Default password is: ' . $defaultPassword);
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

        $defaultPassword = strtolower($user->last_name) . config('brand.password_suffix');
        
        $user->update([
            'password' => Hash::make($defaultPassword),
        ]);

        AuditService::log('updated', "Reset password", $user);

        return redirect()->route('settings.users.index')
                         ->with('success', 'Password reset successfully for ' . $user->name . '. Their new password is: ' . $defaultPassword);
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
