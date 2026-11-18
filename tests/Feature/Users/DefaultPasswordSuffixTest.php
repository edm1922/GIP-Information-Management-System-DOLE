<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates intern accounts using the configured default password suffix', function () {
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

    expect(Hash::check('santosgip', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue();
});

it('resets a user password to the configured suffix', function () {
    config(['brand.password_suffix' => 'gip']);

    $admin = User::factory()->admin()->create();
    $user = User::factory()->intern()->create(['last_name' => 'Reyes', 'username' => 'reyes']);

    $this->actingAs($admin)
        ->post(route('settings.users.reset-password', $user))
        ->assertRedirect(route('settings.users.index'));

    expect(Hash::check('reyesgip', $user->fresh()->password))->toBeTrue();
});
