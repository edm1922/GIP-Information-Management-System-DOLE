<?php

/*
|--------------------------------------------------------------------------
| Frontend candidates (MIR-02, MIR-04, MIR-07) — post-remediation contract
|--------------------------------------------------------------------------
| Red-first expectations per REMEDIATION-ROUND-1.md:
|   SEC-28 — old() inside inline JS must be escaped/inert.
|   SEC-26 — javascript: notification URLs must not become anchors.
|   SEC-02 — created credentials must be random, never lastname+suffix, and
|            the default-password suffix must disappear from the SPA source.
*/

use App\Models\InternApplication;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('SEC-28 escapes the old() payload instead of breaking out of the script block', function () {
    $admin = User::factory()->admin()->create(['username' => 'mir02-admin']);
    $target = User::factory()->admin()->create(['username' => 'mir02-target']);

    $payload = '</script><img src=x onerror=alert(1)>';

    // Submit the edit form with a value that fails validation, so it comes back
    // through old() into the inline JS object literal.
    $this->withHeader('Accept', 'text/html')
        ->actingAs($admin)
        ->from('/settings/users')
        ->post("/settings/users/{$target->id}", [
            '_method' => 'PUT',
            'id' => $target->id,
            'first_name' => $payload,
            'middle_name' => null,
            'last_name' => 'Owning',
            'suffix' => null,
            // Duplicate of the signed-in admin's username: forces a 302 + errors.
            'username' => 'mir02-admin',
            'email' => $target->email,
            'role' => 'admin',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('username');

    $html = $this->actingAs($admin)
        ->get('/settings/users')
        ->assertOk()
        ->getContent();

    // The payload may round-trip as data, but it must never close the script tag.
    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('</script><img');
});

it('SEC-28 escapes the old() payload on the document-types page', function () {
    $admin = User::factory()->admin()->create();
    $type = \App\Models\DocumentType::create([
        'name' => 'Existing Type',
        'code' => 'EXIST01',
        'has_expiry' => false,
        'is_intern_document' => false,
        'is_optional' => false,
    ]);
    \App\Models\DocumentType::create([
        'name' => 'Other Type',
        'code' => 'OTHER01',
        'has_expiry' => false,
        'is_intern_document' => false,
        'is_optional' => false,
    ]);

    $payload = '</script><img src=x onerror=alert(2)>';

    $this->withHeader('Accept', 'text/html')
        ->actingAs($admin)
        ->from('/settings/document-types')
        ->post("/settings/document-types/{$type->id}", [
            '_method' => 'PUT',
            'id' => $type->id,
            'name' => $payload,
            // Duplicate code of another type: forces a 302 + errors.
            'code' => 'OTHER01',
            'has_expiry' => 0,
        ])
        ->assertSessionHasErrors('code');

    $html = $this->actingAs($admin)->get('/settings/document-types')->assertOk()->getContent();

    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('</script><img');
});

it('SEC-28 escapes the old() payload on the companies page', function () {
    $admin = User::factory()->admin()->create();
    $first = \App\Models\Company::create(['name' => 'First Co', 'code' => 'MIR2C01']);
    \App\Models\Company::create(['name' => 'Second Co', 'code' => 'MIR2C02']);
    $payload = '</script><img src=x onerror=alert(3)>';

    $this->withHeader('Accept', 'text/html')
        ->actingAs($admin)
        ->from('/settings/companies')
        ->post("/settings/companies/{$first->id}", [
            '_method' => 'PUT',
            'id' => $first->id,
            'name' => $payload,
            'code' => 'MIR2C02',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('code');

    $html = $this->actingAs($admin)->get('/settings/companies')->assertOk()->getContent();

    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('</script><img');
});

it('SEC-26 does not render a javascript: notification url as an href', function () {
    $user = User::factory()->admin()->create();

    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\InternDocumentSubmitted',
        'data' => [
            'message' => 'Audit notification',
            'url' => 'javascript:alert(document.domain)',
        ],
        'read_at' => null,
    ]);

    $html = $this->actingAs($user)->get('/notifications')->assertOk()->getContent();

    // The layout legitimately uses href="javascript:void(0)" in the sidebar, so
    // the assertion targets the injected payload specifically.
    expect($html)->not->toContain('javascript:alert(document.domain)')
        ->and($html)->not->toContain('href="javascript:alert')
        ->and($notification->fresh()->read_at)->toBeNull();
});

it('SEC-02 created intern credentials are random, not derived from the last name', function () {
    $admin = User::factory()->admin()->create();
    $suffix = config('brand.password_suffix');

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Maria',
        'last_name' => 'Dela Cruz',
        'email' => 'maria.delacruz@example.test',
        'username' => 'maria-delacruz',
        'required_hours' => 486,
        'status' => 'active',
    ])->assertCreated();

    $internUser = User::query()->where('username', 'maria-delacruz')->firstOrFail();
    $derived = strtolower('Dela Cruz').$suffix;

    expect(Hash::check($derived, $internUser->password))->toBeFalse()
        ->and(Hash::check(str_replace(' ', '', strtolower('Dela Cruz')).$suffix, $internUser->password))->toBeFalse()
        ->and($internUser->must_change_password)->toBeTrue();
});

it('SEC-02 the default-password suffix is removed from the SPA source', function () {
    $form = file_get_contents(base_path('frontend/src/components/interns/InternForm.tsx'));
    $users = file_get_contents(base_path('frontend/src/app/(app)/settings/users/page.tsx'));

    expect($form)->not->toContain('NEXT_PUBLIC_DEFAULT_PASSWORD_SUFFIX')
        ->and($users)->not->toContain('NEXT_PUBLIC_DEFAULT_PASSWORD_SUFFIX')
        ->and(InternApplication::query()->count())->toBe(0);
});
