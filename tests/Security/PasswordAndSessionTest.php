<?php

/*
|--------------------------------------------------------------------------
| WP-4 Security Audit — password policy, forced change and session lifetime
|--------------------------------------------------------------------------
| Additive security probes. No application code is modified by this file.
*/

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('blocks every business endpoint while a password change is pending', function (string $method, string $uri) {
    $admin = User::factory()->admin()->create(['must_change_password' => true]);

    $this->actingAs($admin)->json($method, $uri)
        ->assertForbidden()
        ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
})->with([
    ['GET', '/api/v1/dashboard'],
    ['GET', '/api/v1/interns'],
    ['POST', '/api/v1/interns'],
    ['GET', '/api/v1/settings/users'],
    ['POST', '/api/v1/settings/users'],
    ['GET', '/api/v1/reports/audit-log'],
    ['POST', '/api/v1/intern-registration-link'],
    ['PUT', '/api/v1/auth/password'],
]);

it('keeps only the identity and escape-hatch endpoints reachable before the change', function () {
    $user = User::factory()->intern()->create(['must_change_password' => true]);
    apiCreateIntern($user);

    $this->actingAs($user)->getJson('/api/v1/auth/user')->assertOk();
    $this->actingAs($user)->getJson('/api/v1/auth/force-password-change')
        ->assertOk()
        ->assertJsonPath('must_change_password', true);
    $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();
});

it('rejects a short password on the forced-change endpoint', function () {
    $user = User::factory()->intern()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => 'short7c',
            'password_confirmation' => 'short7c',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('SEC-05 rejects an all-numeric 8-character password on forced change', function () {
    $user = User::factory()->intern()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect($user->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('12345678', $user->fresh()->password))->toBeFalse();
});

it('SEC-05 rejects a 5-character password through the self-service password update', function () {
    // AppServiceProvider must enforce min 12 + mixed case + numbers + symbols.
    $user = User::factory()->admin()->create(['password' => 'password']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'abcde',
            'password_confirmation' => 'abcde',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(Hash::check('abcde', $user->fresh()->password))->toBeFalse();
});

it('rejects the old password after a password change', function () {
    $user = User::factory()->intern()->create(['password' => 'password']);
    apiCreateIntern($user);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertOk();

    // Leave the current session so the guest-only login route can be exercised.
    $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertGuest();

    $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('username');

    $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => 'Str0ng!Passw0rd',
    ])->assertOk();
});

it('terminates the current session on logout', function () {
    $user = User::factory()->intern()->create();
    apiCreateIntern($user);

    $this->actingAs($user)->getJson('/api/v1/dashboard')->assertOk();
    $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();

    $this->assertGuest();
});

it('SEC-06 answers 403 to a deactivated account holding an existing session', function () {
    // EnsureAccountIsActive must reject authenticated-but-deactivated users,
    // not only refuse their next login.
    $user = User::factory()->admin()->create(['is_active' => false]);

    $this->actingAs($user)->getJson('/api/v1/dashboard')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/settings/users')->assertForbidden();
});
