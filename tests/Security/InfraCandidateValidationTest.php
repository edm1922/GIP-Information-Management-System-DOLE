<?php

/*
|--------------------------------------------------------------------------
| WP-4 Phase 2 — infrastructure candidates (KAI-15 / ATL-10, ATL-12, ATL-26)
|--------------------------------------------------------------------------
| Additive validation tests. No application code is modified.
*/

use App\Models\InternApplication;
use App\Models\InternRegistrationLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('KAI-15: the literal "*" trusted proxy entry is inert, so XFF is only honoured from trusted IPs', function () {
    // Mechanism check: Symfony never matches a literal "*" as a proxy address.
    expect(IpUtils::checkIp('192.168.1.122', ['127.0.0.1', '::1', '*']))->toBeFalse()
        ->and(IpUtils::checkIp('127.0.0.1', ['127.0.0.1', '::1', '*']))->toBeTrue();

    Route::get('/_sec_ip_probe', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'ips' => $request->ips(),
    ]))->middleware('web');

    // Loopback is a trusted proxy (by default and by config) -> XFF honoured.
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('X-Forwarded-For', '1.2.3.4, 198.51.100.9')
        ->get('/_sec_ip_probe')
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.9');

    // A direct LAN client is NOT trusted: "*" does not widen the list.
    $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.122'])
        ->withHeader('X-Forwarded-For', '1.2.3.4')
        ->get('/_sec_ip_probe')
        ->assertOk()
        ->assertJsonPath('ip', '192.168.1.122');
});

it('ATL-12: public registration enumerates existing accounts through distinct errors', function () {
    $peso = User::factory()->pesoManager()->create();
    $link = InternRegistrationLink::create([
        'token' => Str::random(64),
        'created_by' => $peso->id,
        'expires_at' => now()->addDay(),
    ]);

    $taken = User::factory()->create(['email' => 'taken.account@example.test']);

    $base = [
        'first_name' => 'Enum',
        'last_name' => 'Probe',
        'consent' => true,
        'form_started_at' => (int) floor(microtime(true) * 1000) - 5000,
    ];

    $existing = $this->postJson("/api/v1/public/registration/{$link->token}/applications", $base + [
        'email' => 'taken.account@example.test',
    ]);

    $fresh = $this->postJson("/api/v1/public/registration/{$link->token}/applications", $base + [
        'email' => 'brand.new.'.Str::lower(Str::random(6)).'@example.test',
    ]);

    $existing->assertStatus(422);
    $fresh->assertCreated();

    expect($existing->json('errors.email.0'))->toBe('An account with this email address already exists.')
        ->and($existing->json('errors.email.0'))->not->toBe($fresh->json('message'))
        ->and(InternApplication::query()->where('email', 'taken.account@example.test')->count())->toBe(0);
});

it('SEC-07 password change keeps the current session usable (cross-session is Blocked)', function () {
    $user = User::factory()->intern()->create([
        'password' => 'password',
        'username' => 'session-probe',
    ]);
    apiCreateIntern($user);

    // Log in through the real Blade login form (web session).
    $this->post('/login', [
        'username' => 'session-probe',
        'password' => 'password',
    ])->assertRedirect();

    $this->get('/dashboard')->assertOk();

    // Change the password through the API (same session guard).
    $this->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'Str0ng!Passw0rd',
        'password_confirmation' => 'Str0ng!Passw0rd',
    ])->assertOk();

    // The session that performed the change stays valid (expected behaviour).
    $this->get('/dashboard')->assertOk();

    // Cross-session invalidation cannot be modelled here: phpunit.xml pins
    // SESSION_DRIVER=array, so there is no session store shared between two
    // independent clients. Reported as Blocked rather than guessed.
    expect(config('session.driver'))->toBe('array');
});
