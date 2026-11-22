<?php

use App\Models\Intern;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('lets admins and peso managers list interns but blocks interns', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v1/interns')
        ->assertOk()
        ->assertJsonStructure(['interns' => ['data'], 'agencies', 'show_archived']);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->getJson('/api/v1/interns')
        ->assertOk();

    $this->actingAs(User::factory()->intern()->create())
        ->getJson('/api/v1/interns')
        ->assertForbidden();
});

it('creates an intern together with a linked login account', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'api-juandc@example.test',
        'username' => 'api-juandc',
        'required_hours' => 486,
        'status' => 'active',
    ]);

    $response->assertCreated()
        ->assertJsonPath('intern.user.username', 'api-juandc')
        ->assertJsonPath('intern.user.email', 'api-juandc@example.test');

    $intern = Intern::firstOrFail();
    $user = User::where('username', 'api-juandc')->firstOrFail();

    expect($user->role)->toBe('intern')
        ->and($user->must_change_password)->toBeTrue()
        ->and((int) $intern->user_id)->toBe((int) $user->id)
        ->and(Hash::check(strtolower('Dela Cruz').config('brand.password_suffix'), $user->password))->toBeTrue();
});

it('blocks non-admins from creating interns', function () {
    $this->actingAs(User::factory()->pesoManager()->create())
        ->postJson('/api/v1/interns', [
            'first_name' => 'Nope',
            'last_name' => 'Nope',
            'username' => 'nope',
        ])
        ->assertForbidden();
});

it('updates an intern through the API', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create(['username' => 'api-intern-update']);
    $intern = apiCreateIntern($internUser);

    $this->actingAs($admin)
        ->putJson("/api/v1/interns/{$intern->id}", [
            'first_name' => 'Renamed',
            'last_name' => 'Intern',
            'username' => 'api-intern-update',
            'required_hours' => 500,
            'rendered_hours' => 12,
            'status' => 'active',
        ])
        ->assertOk()
        ->assertJsonPath('intern.required_hours', 500);

    expect($intern->fresh()->required_hours)->toBe(500)
        ->and($internUser->fresh()->first_name)->toBe('Renamed');
});

it('soft-deletes and restores an intern through the API', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/interns/{$intern->id}")
        ->assertOk();

    $this->assertSoftDeleted('interns', ['id' => $intern->id]);
    expect($internUser->fresh()->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->patchJson("/api/v1/interns/{$intern->id}/restore")
        ->assertOk();

    expect($intern->fresh()->trashed())->toBeFalse()
        ->and($internUser->fresh()->is_active)->toBeTrue();
});

it('shows an intern record only to authorized users', function () {
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->getJson("/api/v1/interns/{$intern->id}")
        ->assertOk()
        ->assertJsonPath('intern.id', $intern->id);

    $this->actingAs($internUser)
        ->getJson("/api/v1/interns/{$intern->id}")
        ->assertOk();

    $this->actingAs(User::factory()->intern()->create())
        ->getJson("/api/v1/interns/{$intern->id}")
        ->assertForbidden();
});

it('does not expose archived interns to non-admin archive filters', function () {
    $admin = User::factory()->admin()->create();
    $intern = apiCreateIntern(User::factory()->intern()->create());
    $intern->delete();

    $this->actingAs($admin)
        ->getJson('/api/v1/interns?archived=1')
        ->assertOk()
        ->assertJsonPath('show_archived', true);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->getJson('/api/v1/interns?archived=1')
        ->assertOk()
        ->assertJsonPath('show_archived', false);
});

it('permanently deletes an intern through the API for admins only', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->deleteJson("/api/v1/interns/{$intern->id}/force-delete")
        ->assertForbidden();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/interns/{$intern->id}/force-delete")
        ->assertOk();

    $this->assertDatabaseMissing('interns', ['id' => $intern->id]);
    expect($internUser->fresh()->trashed())->toBeTrue();
});
it('uses a per-intern hours-per-working-day setting for the days estimate', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Part',
        'last_name' => 'Timer',
        'email' => 'part.timer@example.test',
        'username' => 'part_timer',
        'required_hours' => 100,
        'hours_per_workday' => 6.5,
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('intern.hours_per_workday', 6.5);

    $intern = Intern::query()->whereHas('user', fn ($q) => $q->where('username', 'part_timer'))->firstOrFail();

    expect((float) $intern->hours_per_workday)->toBe(6.5)
        ->and($intern->days_remaining_from_hours)->toBe(round(100 / 6.5, 1));

    $this->actingAs($admin)->putJson("/api/v1/interns/{$intern->id}", [
        'first_name' => 'Part',
        'last_name' => 'Timer',
        'username' => 'part_timer',
        'required_hours' => 100,
        'hours_per_workday' => 5,
        'status' => 'active',
    ])->assertOk()
        ->assertJsonPath('intern.hours_per_workday', 5);

    expect($intern->fresh()->days_remaining_from_hours)->toBe(20.0);
});

it('defaults hours per working day to eight when omitted', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create(['username' => 'default-hours-user']);

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Default',
        'last_name' => 'Hours',
        'email' => 'default.hours@example.test',
        'username' => 'default_hours',
        'required_hours' => 80,
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('intern.hours_per_workday', 8);

    $intern = Intern::query()->whereHas('user', fn ($q) => $q->where('username', 'default_hours'))->firstOrFail();
    expect($intern->days_remaining_from_hours)->toBe(10.0);
});