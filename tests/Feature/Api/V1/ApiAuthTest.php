<?php

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    // Treat the test client as the first-party SPA so Sanctum adds the session
    // and CSRF middleware to API requests (CSRF is skipped while unit testing).
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('exposes an unauthenticated health check', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('authenticates a user through the stateful API session', function () {
    $user = User::factory()->admin()->create([
        'username' => 'api-admin',
        'password' => 'password',
        'must_change_password' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => 'api-admin',
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.username', 'api-admin')
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonPath('user.must_change_password', false);

    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials through the API', function () {
    User::factory()->create(['username' => 'api-invalid']);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'api-invalid',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('username');

    $this->assertGuest();
});

it('rejects deactivated accounts at API login', function () {
    User::factory()->create([
        'username' => 'api-disabled',
        'password' => 'password',
        'is_active' => false,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'api-disabled',
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('username');

    $this->assertGuest();
});

it('throttles repeated failed API login attempts', function () {
    User::factory()->create([
        'username' => 'api-throttle',
        'password' => 'password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'api-throttle',
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    // The lockout applies even when the supplied password is now correct.
    $this->postJson('/api/v1/auth/login', [
        'username' => 'api-throttle',
        'password' => 'password',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('username');

    $this->assertGuest();
});

it('returns 401 for unauthenticated API requests', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    $this->getJson('/api/v1/auth/user')->assertUnauthorized();
});

it('returns JSON errors for API requests without a JSON Accept header', function () {
    // A plain browser/fetch call may not send Accept: application/json. API
    // routes must still fail with JSON (no redirect to the Blade login page).
    $this->withHeader('Accept', '*/*')
        ->get('/api/v1/dashboard')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('returns the authenticated user payload', function () {
    $user = User::factory()->pesoManager()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', 'peso_manager');
});

it('enforces forced password changes for API consumers', function () {
    $user = User::factory()->intern()->create([
        'must_change_password' => true,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertForbidden()
        ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED')
        ->assertJsonPath('must_change_password', true);

    // The auth/user endpoint must remain reachable so the SPA can detect state.
    $this->actingAs($user)
        ->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonPath('user.must_change_password', true);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertOk();

    expect($user->fresh()->must_change_password)->toBeFalse();

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk();
});

it('updates the password with the current password', function () {
    $user = User::factory()->admin()->create(['password' => 'password']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'password-updated');

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('updates the authenticated user profile', function () {
    $user = User::factory()->admin()->create(['username' => 'profile-user']);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', [
            'first_name' => 'Updated',
            'middle_name' => null,
            'last_name' => 'Name',
            'suffix' => null,
            'username' => 'profile-user',
        ])
        ->assertOk()
        ->assertJsonPath('user.first_name', 'Updated')
        ->assertJsonPath('user.last_name', 'Name');

    expect($user->fresh()->first_name)->toBe('Updated');
});

it('marks all notifications as read through the API', function () {
    $user = User::factory()->admin()->create();

    $user->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return ['message' => 'API notification test'];
        }
    });

    expect($user->unreadNotifications()->count())->toBe(1);

    $this->actingAs($user)
        ->postJson('/api/v1/notifications/mark-all-read')
        ->assertOk()
        ->assertJsonPath('affected_count', 1);

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('logs out of the API session', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    $this->assertGuest();
});
