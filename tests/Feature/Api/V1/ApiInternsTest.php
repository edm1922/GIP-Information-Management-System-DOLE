<?php

use App\Models\Intern;
use App\Models\User;
use App\Support\WorkingDays;
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
        // SEC-02: the temporary password is random, never lastname+suffix.
        ->and(Hash::check(strtolower('Dela Cruz').config('brand.password_suffix'), $user->password))->toBeFalse();
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

it('counts Monday to Friday when deriving hours from a contract date range', function () {
    // Mon 7 Sep 2026 - Fri 11 Sep 2026: five weekdays.
    expect(WorkingDays::hoursBetween('2026-09-07', '2026-09-11', 8))->toBe(40.0)
        // Fri 11 Sep - Mon 14 Sep: Friday and Monday only (weekend skipped).
        ->and(WorkingDays::hoursBetween('2026-09-11', '2026-09-14', 8))->toBe(16.0)
        // Sat 12 Sep - Sun 13 Sep: no weekdays at all.
        ->and(WorkingDays::hoursBetween('2026-09-12', '2026-09-13', 8))->toBe(0.0);
});

it('rounds fractional hours per working day to whole hours', function () {
    // 5 weekdays x 6.5 = 32.5 -> 33 (PHP rounds halves away from zero).
    expect(WorkingDays::hoursBetween('2026-09-07', '2026-09-11', 6.5))->toBe(33.0)
        // 4 weekdays x 6.5 = 26 exactly.
        ->and(WorkingDays::hoursBetween('2026-09-08', '2026-09-11', 6.5))->toBe(26.0);
});

it('returns no derived hours for missing, invalid or reversed dates', function () {
    expect(WorkingDays::hoursBetween(null, '2026-09-11', 8))->toBeNull()
        ->and(WorkingDays::hoursBetween('2026-09-07', null, 8))->toBeNull()
        ->and(WorkingDays::hoursBetween('not-a-date', '2026-09-11', 8))->toBeNull()
        ->and(WorkingDays::hoursBetween('2026-09-07', 'also-not-a-date', 8))->toBeNull()
        ->and(WorkingDays::hoursBetween('2026-09-14', '2026-09-07', 8))->toBeNull();
});

it('derives required hours from contract dates when none is supplied', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Auto',
        'last_name' => 'Hours',
        'email' => 'auto.hours@example.test',
        'username' => 'auto_hours',
        'contract_start_date' => '2026-09-07',
        'contract_end_date' => '2026-09-11',
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('intern.required_hours', 40);

    $intern = Intern::query()->whereHas('user', fn ($q) => $q->where('username', 'auto_hours'))->firstOrFail();

    expect((int) $intern->required_hours)->toBe(40)
        ->and((float) $intern->hours_per_workday)->toBe(8.0);
});

it('derives required hours using a fractional hours-per-workday on create', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Fraction',
        'last_name' => 'Hours',
        'email' => 'fraction.hours@example.test',
        'username' => 'fraction_hours',
        'hours_per_workday' => 6.5,
        'contract_start_date' => '2026-09-07',
        'contract_end_date' => '2026-09-11',
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('intern.required_hours', 33)
        ->assertJsonPath('intern.hours_per_workday', 6.5);

    expect((int) Intern::query()->whereHas('user', fn ($q) => $q->where('username', 'fraction_hours'))->firstOrFail()->required_hours)
        ->toBe(33);
});

it('keeps an explicitly supplied required hours value when contract dates are present', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Explicit',
        'last_name' => 'Hours',
        'email' => 'explicit.hours@example.test',
        'username' => 'explicit_hours',
        'required_hours' => 500,
        'contract_start_date' => '2026-09-07',
        'contract_end_date' => '2026-09-11',
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('intern.required_hours', 500);
});

it('falls back to the configured default when no contract dates are supplied', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Default',
        'last_name' => 'Range',
        'email' => 'default.range@example.test',
        'username' => 'default_range',
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('intern.required_hours', (int) config('internship.default_required_hours', 486));
});

it('derives required hours on update when omitted but contract dates are supplied', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create(['username' => 'update_auto_hours']);
    $intern = apiCreateIntern($internUser, ['required_hours' => 486]);

    // Fri 11 Sep 2026 - Mon 14 Sep 2026: two weekdays x the intern's 8 hours/day.
    $this->actingAs($admin)->putJson("/api/v1/interns/{$intern->id}", [
        'first_name' => 'Update',
        'last_name' => 'Auto',
        'username' => 'update_auto_hours',
        'contract_start_date' => '2026-09-11',
        'contract_end_date' => '2026-09-14',
        'status' => 'active',
    ])->assertOk()
        ->assertJsonPath('intern.required_hours', 16);

    expect((int) $intern->fresh()->required_hours)->toBe(16);
});
