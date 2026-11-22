<?php

/*
|--------------------------------------------------------------------------
| WP-4 Security Audit — public registration link
|--------------------------------------------------------------------------
| Additive security probes. No application code is modified by this file.
|
| These endpoints are unauthenticated by design; the probes check token
| entropy handling, expiry/revocation, throttling and replay.
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

function securityLink(array $attributes = []): InternRegistrationLink
{
    return InternRegistrationLink::create(array_merge([
        'token' => Str::random(64),
        'created_by' => User::factory()->admin()->create()->id,
        'expires_at' => now()->addDay(),
    ], $attributes));
}

function securityApplicationPayload(string $email, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Audit',
        'middle_name' => null,
        'last_name' => 'Applicant',
        'email' => $email,
        'contact_number' => '09171234567',
        'school' => 'Audit University',
        'course' => 'BSIT',
        'intern_type' => 'GIP',
        'consent' => true,
        'form_started_at' => (int) floor(microtime(true) * 1000) - 5000,
    ], $overrides);
}

it('does not resolve guessed or malformed registration tokens', function () {
    securityLink();

    foreach (range(1, 5) as $attempt) {
        $this->getJson('/api/v1/public/registration/'.Str::random(64))->assertNotFound();
    }

    foreach (['short', str_repeat('a', 64).'x', '0', '../etc/passwd', '<script>'] as $token) {
        $this->getJson('/api/v1/public/registration/'.rawurlencode($token))->assertNotFound();
    }
});

it('answers 410 for expired and revoked links on both public verbs', function () {
    $expired = securityLink(['expires_at' => now()->subMinute()]);
    $revoked = securityLink(['revoked_at' => now()]);
    $payload = securityApplicationPayload('expired.probe@example.test');

    $this->getJson("/api/v1/public/registration/{$expired->token}")->assertStatus(410);
    $this->postJson("/api/v1/public/registration/{$expired->token}/applications", $payload)->assertStatus(410);

    $this->getJson("/api/v1/public/registration/{$revoked->token}")->assertStatus(410);
    $this->postJson("/api/v1/public/registration/{$revoked->token}/applications", $payload)->assertStatus(410);

    expect(InternApplication::query()->count())->toBe(0);
});

it('exposes no applicant, user or intern data on the public landing endpoint', function () {
    $link = securityLink();

    $response = $this->getJson("/api/v1/public/registration/{$link->token}")
        ->assertOk()
        ->assertJsonStructure(['registration' => ['brand', 'expires_at', 'intern_types', 'agencies']]);

    // The landing payload is a closed shape: only these keys may appear.
    expect(array_keys($response->json('registration')))
        ->toBe(['brand', 'expires_at', 'intern_types', 'agencies']);

    $payload = json_encode($response->json());

    foreach (['"applications"', '"intern"', '"user_id"', '"approved_user_id"', '"reviewer_id"', '"password"', '"email"'] as $needle) {
        expect(str_contains($payload, $needle))->toBeFalse();
    }
});

it('throttles the public landing endpoint against token enumeration', function () {
    securityLink();

    $statuses = [];
    for ($i = 0; $i < 62; $i++) {
        $statuses[] = $this->getJson('/api/v1/public/registration/'.str_repeat('z', 64))->status();
    }

    expect($statuses)->toContain(429);
});

it('throttles repeated public application submissions from one IP', function () {
    $link = securityLink();

    $statuses = [];
    for ($i = 0; $i < 7; $i++) {
        $statuses[] = $this->postJson(
            "/api/v1/public/registration/{$link->token}/applications",
            securityApplicationPayload('throttle.'.$i.'@example.test')
        )->status();
    }

    expect($statuses)->toContain(429);
    expect(InternApplication::query()->count())->toBeLessThanOrEqual(5);
});

it('blocks a replayed application for the same email', function () {
    $link = securityLink();
    $email = 'replay.probe@example.test';

    $this->postJson(
        "/api/v1/public/registration/{$link->token}/applications",
        securityApplicationPayload($email)
    )->assertCreated();

    $this->postJson(
        "/api/v1/public/registration/{$link->token}/applications",
        securityApplicationPayload($email)
    )->assertStatus(422)->assertJsonValidationErrors('email');

    expect(InternApplication::query()->where('email', $email)->count())->toBe(1);
});

it('refuses an application for an email that already has an account', function () {
    $link = securityLink();
    User::factory()->create(['email' => 'existing.account@example.test']);

    $this->postJson(
        "/api/v1/public/registration/{$link->token}/applications",
        securityApplicationPayload('existing.account@example.test')
    )->assertStatus(422)->assertJsonValidationErrors('email');

    expect(InternApplication::query()->count())->toBe(0);
});

it('keeps the registration token out of reach for non-staff users', function () {
    $link = securityLink();

    $this->actingAs(User::factory()->intern()->create())
        ->getJson('/api/v1/intern-registration-link')
        ->assertForbidden();

    $admin = User::factory()->admin()->create();
    $response = $this->actingAs($admin)
        ->getJson('/api/v1/intern-registration-link')
        ->assertOk()
        ->assertJsonPath('link.id', $link->id);

    // The token is only exposed inside the shareable URL, never as a bare field.
    expect($response->json('link.url'))->toContain($link->token)
        ->and($response->json('link.token'))->toBeNull();
});
