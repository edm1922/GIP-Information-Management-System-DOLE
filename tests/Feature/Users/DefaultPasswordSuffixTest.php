<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| SEC-02 — created/reset credentials must be random, never derived
|--------------------------------------------------------------------------
| This file used to assert the old `lastname + brand.password_suffix`
| derivation. That behaviour was removed in Remediation Round 1, so the
| expectations now assert the opposite (random, non-derivable) contract.
*/

it('creates intern accounts with a random password, not a name-derived one', function () {
    config(['brand.password_suffix' => 'gip']);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('interns.store'), [
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'email' => 'msantos@example.test',
        'username' => 'msantos',
        'required_hours' => 486,
        'status' => 'active',
    ])->assertRedirect();

    $user = User::where('username', 'msantos')->firstOrFail();

    expect($user->must_change_password)->toBeTrue()
        ->and(Hash::check('santosgip', $user->password))->toBeFalse()
        ->and(Hash::check('santos', $user->password))->toBeFalse();
});

it('resets a user password to a random value, not the configured suffix', function () {
    config(['brand.password_suffix' => 'gip']);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->intern()->create(['last_name' => 'Reyes', 'username' => 'reyes']);

    $this->actingAs($admin)
        ->post(route('settings.users.reset-password', $user))
        ->assertRedirect(route('settings.users.index'));

    expect(Hash::check('reyesgip', $user->fresh()->password))->toBeFalse();
});
