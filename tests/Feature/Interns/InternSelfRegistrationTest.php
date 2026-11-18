<?php

use App\Mail\InternCredentialsMail;
use App\Models\Intern;
use App\Models\InternApplication;
use App\Models\InternRegistrationLink;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

function registrationLinkFor(User $creator, array $attributes = []): InternRegistrationLink
{
    return InternRegistrationLink::create(array_merge([
        'token' => Str::random(64),
        'created_by' => $creator->id,
        'expires_at' => now()->addDays(7),
    ], $attributes));
}

function applicationPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Maria',
        'middle_name' => 'Santos',
        'last_name' => 'Dela Cruz',
        'suffix' => null,
        'email' => 'maria.santos.'.Str::lower(Str::random(6)).'@example.test',
        'contact_number' => '09171234567',
        'school' => 'University of Example',
        'course' => 'BSIT',
        'intern_type' => 'GIP',
        'notes' => 'Please consider my application.',
        'consent' => true,
        'form_started_at' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('generates a shareable registration link for admins and peso managers only', function () {
    $admin = User::factory()->admin()->create();
    $peso = User::factory()->pesoManager()->create();
    $intern = User::factory()->intern()->create();

    $first = $this->actingAs($admin)
        ->postJson('/api/v1/intern-registration-link', ['expires_in_days' => 14])
        ->assertCreated()
        ->assertJsonPath('link.is_active', true);

    $url = $first->json('link.url');
    expect($url)->toContain('/register/')
        ->and(InternRegistrationLink::firstOrFail()->token)->toHaveLength(64);

    $firstId = InternRegistrationLink::firstOrFail()->id;

    // Generating again revokes the previous link.
    $this->actingAs($peso)
        ->postJson('/api/v1/intern-registration-link')
        ->assertCreated();

    expect(InternRegistrationLink::findOrFail($firstId)->revoked_at)->not->toBeNull()
        ->and($peso->fresh()->name)->not->toBeEmpty();

    $this->actingAs($intern)
        ->postJson('/api/v1/intern-registration-link')
        ->assertForbidden();
});

it('serves public form metadata for an active link and rejects expired, revoked or unknown links', function () {
    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->getJson("/api/v1/public/registration/{$link->token}")
        ->assertOk()
        ->assertJsonPath('registration.intern_types.0', 'GIP')
        ->assertJsonStructure(['registration' => ['brand', 'expires_at', 'agencies', 'intern_types']]);

    $expired = registrationLinkFor($admin, ['expires_at' => now()->subMinute()]);
    $this->getJson("/api/v1/public/registration/{$expired->token}")->assertStatus(410);

    $revoked = registrationLinkFor($admin, ['revoked_at' => now()]);
    $this->getJson("/api/v1/public/registration/{$revoked->token}")->assertStatus(410);

    $this->getJson('/api/v1/public/registration/'.Str::random(64))->assertNotFound();
});

it('stores a pending application without creating a user or intern account', function () {
    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $response = $this->postJson(
        "/api/v1/public/registration/{$link->token}/applications",
        applicationPayload(['email' => 'applicant@example.test'])
    );

    $response->assertCreated()
        ->assertJsonStructure(['message', 'reference']);

    expect($response->json('reference'))->toStartWith('GIMS-APL-');

    $this->assertDatabaseHas('intern_applications', [
        'email' => 'applicant@example.test',
        'status' => 'pending',
    ]);
    $this->assertDatabaseCount('interns', 0);
    // Only the admin account exists; public submission creates no login.
    $this->assertDatabaseCount('users', 1);
    expect($link->fresh()->uses_count)->toBe(1);
});

it('validates public applications and blocks duplicate email addresses', function () {
    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'dupe@example.test',
    ]))->assertCreated();

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'dupe@example.test',
    ]))->assertStatus(422)->assertJsonValidationErrors('email');

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'first_name' => '',
        'consent' => false,
    ]))->assertStatus(422)->assertJsonValidationErrors(['first_name', 'consent']);

    // Honeypot submission is rejected.
    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'website' => 'https://spam.example',
    ]))->assertStatus(422);
});

it('approves an application, creates the account and emails the credentials', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'approved.applicant@example.test',
    ]))->assertCreated();

    $application = InternApplication::firstOrFail();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve", [
            'supervisor' => 'JUAN DELA CRUZ',
            'required_hours' => 486,
            'intern_type' => 'GIP',
        ])
        ->assertOk()
        ->assertJsonPath('application.status', 'approved')
        ->assertJsonPath('application.credential_email_status', 'sent');

    $application->refresh();
    $user = User::where('username', 'delacruz_ma')->firstOrFail();
    $intern = Intern::firstOrFail();

    expect($user->role)->toBe('intern')
        ->and($user->email)->toBe('approved.applicant@example.test')
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($application->approved_user_id)->toBe($user->id)
        ->and($application->approved_intern_id)->toBe($intern->id)
        ->and($intern->user_id)->toBe($user->id)
        ->and($intern->supervisor)->toBe('JUAN DELA CRUZ');

    Mail::assertSent(InternCredentialsMail::class, function (InternCredentialsMail $mail) {
        return $mail->hasTo('approved.applicant@example.test');
    });

    /** @var InternCredentialsMail $mailable */
    $mailable = Mail::sent(InternCredentialsMail::class)->first();
    expect($mailable)->not->toBeNull()
        ->and($mailable->username)->toBe('delacruz_ma')
        ->and(Hash::check($mailable->temporaryPassword, $user->password))->toBeTrue();

    // The generated credentials work, and the first login is forced to change.
    Auth::logout();
    $this->postJson('/api/v1/auth/login', [
        'username' => 'delacruz_ma',
        'password' => $mailable->temporaryPassword,
    ])->assertOk()
        ->assertJsonPath('user.must_change_password', true);

    $this->assertAuthenticatedAs($user);
});

it('allows peso managers to approve and blocks interns or guests', function () {
    Mail::fake();

    $peso = User::factory()->pesoManager()->create();
    $applicantUser = registrationLinkFor($peso);

    $this->postJson("/api/v1/public/registration/{$applicantUser->token}/applications", applicationPayload([
        'email' => 'peso.approved@example.test',
    ]))->assertCreated();

    $application = InternApplication::firstOrFail();

    $this->actingAs(User::factory()->intern()->create())
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertForbidden();

    $this->actingAs($peso)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertOk();
});

it('cannot approve the same application twice', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload())->assertCreated();
    $application = InternApplication::firstOrFail();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertOk();

    $userCount = User::count();
    $internCount = Intern::count();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    expect(User::count())->toBe($userCount)
        ->and(Intern::count())->toBe($internCount);
});

it('rejects an application with a reason and creates no account', function () {
    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload())->assertCreated();
    $application = InternApplication::firstOrFail();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/reject", ['reason' => 'Incomplete requirements.'])
        ->assertOk()
        ->assertJsonPath('application.status', 'rejected');

    expect($application->fresh()->rejection_reason)->toBe('Incomplete requirements.')
        ->and($application->fresh()->reviewer_id)->toBe($admin->id);
    $this->assertDatabaseCount('interns', 0);
    $this->assertDatabaseCount('users', 1);

    // Repeated review is blocked.
    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/reject", ['reason' => 'Again rejected now.'])
        ->assertStatus(422);
});

it('lists applications for staff and resends credentials with a rotated password', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $peso = User::factory()->pesoManager()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'resend@example.test',
    ]))->assertCreated();
    $application = InternApplication::firstOrFail();

    $this->actingAs($peso)
        ->getJson('/api/v1/intern-applications?status=pending')
        ->assertOk()
        ->assertJsonPath('pending_count', 1)
        ->assertJsonStructure(['applications' => ['data'], 'pending_count']);

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertOk();

    $user = User::where('email', 'resend@example.test')->firstOrFail();
    $originalHash = $user->password;

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/credential-email")
        ->assertOk()
        ->assertJsonPath('application.credential_email_status', 'sent');

    Mail::assertSent(InternCredentialsMail::class, 2);

    expect($user->fresh()->password)->not->toBe($originalHash)
        ->and(Hash::check('wrong-password', $user->fresh()->password))->toBeFalse();

    $this->actingAs(User::factory()->intern()->create())
        ->postJson("/api/v1/intern-applications/{$application->id}/credential-email")
        ->assertForbidden();
});

it('does not send any email when an application is first submitted', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload())
        ->assertCreated()
        ->assertJsonMissingPath('username');

    Mail::assertNothingSent();
});

it('suffixes the standardized username when it is already taken', function () {
    Mail::fake();

    User::factory()->intern()->create(['username' => 'delacruz_ma']);

    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload())
        ->assertCreated();

    $application = InternApplication::firstOrFail();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertOk();

    expect(User::where('username', 'delacruz_ma2')->exists())->toBeTrue()
        ->and($application->fresh()->approvedUser?->username)->toBe('delacruz_ma2');
});

it('blocks resubmission when a soft-deleted account still owns the email', function () {
    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    User::factory()->intern()->create([
        'username' => 'deleted.account',
        'email' => 'deleted.account@example.test',
    ])->delete();

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'deleted.account@example.test',
    ]))->assertStatus(422)->assertJsonValidationErrors('email');
});

it('notifies admins and peso managers when an application is submitted', function () {
    $admin = User::factory()->admin()->create();
    $peso = User::factory()->pesoManager()->create();
    $internUser = User::factory()->intern()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload())
        ->assertCreated();

    expect($admin->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($peso->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($internUser->fresh()->unreadNotifications()->count())->toBe(0);

    $notification = $admin->fresh()->unreadNotifications()->first();
    expect($notification->data['url'])->toBe('/interns/applications')
        ->and($notification->data['message'])->toContain('submitted an intern application');

    $this->actingAs($admin)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('unread_count', 1);
});

it('hides pending applications from admins but shows them to PESO managers', function () {
    $admin = User::factory()->admin()->create();
    $peso = User::factory()->pesoManager()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'visibility@example.test',
    ]))->assertCreated();

    $application = InternApplication::firstOrFail();

    $this->actingAs($admin)
        ->getJson('/api/v1/intern-applications')
        ->assertOk()
        ->assertJsonCount(0, 'applications.data')
        ->assertJsonPath('pending_count', 0);

    $this->actingAs($admin)
        ->getJson("/api/v1/intern-applications/{$application->id}")
        ->assertForbidden();

    $this->actingAs($peso)
        ->getJson('/api/v1/intern-applications')
        ->assertOk()
        ->assertJsonCount(1, 'applications.data')
        ->assertJsonPath('pending_count', 1)
        ->assertJsonPath('applications.data.0.status', 'pending');

    // Once PESO approves, the admin sees the application.
    Mail::fake();
    $this->actingAs($peso)
        ->postJson("/api/v1/intern-applications/{$application->id}/approve")
        ->assertOk();

    $this->actingAs($admin)
        ->getJson('/api/v1/intern-applications')
        ->assertOk()
        ->assertJsonCount(1, 'applications.data')
        ->assertJsonPath('applications.data.0.status', 'approved');
});

it('notifies a newly created PESO manager about existing pending applications', function () {
    $admin = User::factory()->admin()->create();
    $link = registrationLinkFor($admin);

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", applicationPayload([
        'email' => 'peso.new@example.test',
    ]))->assertCreated();

    $this->actingAs($admin)->postJson('/api/v1/settings/users', [
        'first_name' => 'New',
        'last_name' => 'Manager',
        'username' => 'new.peso.manager',
        'role' => 'peso_manager',
        'is_active' => true,
    ])->assertCreated();

    $peso = User::where('username', 'new.peso.manager')->firstOrFail();
    expect($peso->unreadNotifications()->count())->toBe(1);
});