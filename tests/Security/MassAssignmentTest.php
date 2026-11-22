<?php

/*
|--------------------------------------------------------------------------
| WP-4 Security Audit — mass assignment / over-posting
|--------------------------------------------------------------------------
| Additive security probes. No application code is modified by this file.
|
| Each probe posts privilege-bearing fields the endpoint does not declare,
| then asserts the persisted record is unchanged.
*/

use App\Models\InternApplication;
use App\Models\InternRegistrationLink;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('ignores privilege fields over-posted to the profile update endpoint', function () {
    $user = User::factory()->intern()->create([
        'username' => 'massassigned',
        'is_active' => true,
        'must_change_password' => false,
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/profile', [
            'first_name' => 'Renamed',
            'middle_name' => null,
            'last_name' => 'User',
            'username' => 'massassigned',
            // Attempted privilege escalation:
            'role' => 'admin',
            'is_active' => true,
            'must_change_password' => false,
            'password' => 'pwned-password',
            'id' => 999,
        ])
        ->assertOk();

    $fresh = $user->fresh();

    expect($fresh->role)->toBe('intern')
        ->and($fresh->first_name)->toBe('Renamed')
        ->and((int) $fresh->id)->toBe((int) $user->id);
});

it('forces the intern role even when role=admin is posted to intern create', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/v1/interns', [
            'first_name' => 'Payload',
            'last_name' => 'Tester',
            'email' => 'payload.tester@example.test',
            'username' => 'payload-tester',
            'required_hours' => 486,
            'status' => 'active',
            // Attempted privilege escalation:
            'role' => 'admin',
            'is_active' => true,
            'must_change_password' => false,
        ])
        ->assertCreated();

    $created = User::query()->where('username', 'payload-tester')->firstOrFail();

    expect($created->role)->toBe('intern')
        ->and($created->must_change_password)->toBeTrue()
        ->and($created->is_active)->toBeTrue();
});

it('ignores role and activation fields over-posted to intern update', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $this->actingAs($admin)
        ->putJson("/api/v1/interns/{$intern->id}", [
            'first_name' => 'Updated',
            'last_name' => 'Intern',
            'username' => $internUser->username,
            'required_hours' => 486,
            'status' => 'active',
            // Attempted privilege escalation / account takeover:
            'role' => 'admin',
            'is_active' => false,
            'must_change_password' => false,
            'user_id' => $admin->id,
        ])
        ->assertOk();

    $freshUser = $internUser->fresh();

    expect($freshUser->role)->toBe('intern')
        ->and($freshUser->is_active)->toBeTrue()
        ->and((int) $intern->fresh()->user_id)->toBe((int) $internUser->id);
});

it('does not accept over-posted review state on a public application', function () {
    $peso = User::factory()->pesoManager()->create();
    $link = InternRegistrationLink::create([
        'token' => Str::random(64),
        'created_by' => $peso->id,
        'expires_at' => now()->addDay(),
    ]);

    $email = 'overpost.'.Str::lower(Str::random(6)).'@example.test';

    $response = $this->postJson("/api/v1/public/registration/{$link->token}/applications", [
        'first_name' => 'Over',
        'last_name' => 'Posted',
        'email' => $email,
        'consent' => true,
        'form_started_at' => (int) floor(microtime(true) * 1000) - 5000,
        // Attempted state manipulation:
        'status' => 'approved',
        'id' => 4242,
        'reference' => 'GIMS-APL-2026-HACKED',
        'approved_user_id' => $peso->id,
        'credential_email_status' => 'sent',
        'reviewer_id' => $peso->id,
    ])->assertCreated();

    $application = InternApplication::query()->where('email', $email)->firstOrFail();

    expect($application->status)->toBe('pending')
        ->and((int) $application->id)->not->toBe(4242)
        ->and($application->reference)->not->toBe('GIMS-APL-2026-HACKED')
        ->and($application->approved_user_id)->toBeNull()
        ->and($application->credential_email_status)->toBeNull()
        ->and($application->reviewer_id)->toBeNull();

    // The response must only expose a server-generated reference, no intern/user ids.
    expect($response->json('reference'))->toBe($application->reference);
});

it('rejects role values an admin is not allowed to assign through user settings', function (string $role) {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/users', [
            'first_name' => 'Role',
            'last_name' => 'Probe',
            'username' => 'role-probe-'.Str::lower(Str::random(5)),
            'email' => 'role.'.Str::lower(Str::random(5)).'@example.test',
            'role' => $role,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');
})->with(['intern', 'encoder', 'superadmin', '']);

it('generates the registration token server-side and ignores a posted token', function () {
    $admin = User::factory()->admin()->create();
    $chosen = str_repeat('f', 64);

    $this->actingAs($admin)
        ->postJson('/api/v1/intern-registration-link', [
            'expires_in_days' => 7,
            'token' => $chosen,
            'uses_count' => 99,
        ])
        ->assertCreated();

    $link = InternRegistrationLink::query()->latest('id')->firstOrFail();

    expect($link->token)->not->toBe($chosen)
        ->and($link->token)->toHaveLength(64)
        ->and((int) $link->uses_count)->toBe(0);
});
