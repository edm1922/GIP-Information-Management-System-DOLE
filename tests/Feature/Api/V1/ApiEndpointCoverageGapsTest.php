<?php

/*
|--------------------------------------------------------------------------
| API v1 — coverage-gap contract tests
|--------------------------------------------------------------------------
| Fills the endpoints that had no automated coverage before this pass:
| auth/force-password-change (show), profile show/delete, single
| notification read, interns/agencies, company show/update, document-type
| index, user show/toggle-status/reset-password, registration-link
| index/revoke, and the legacy single-step document review endpoint.
*/

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('exposes the forced-change state and the profile payload', function () {
    $flagged = User::factory()->intern()->create(['must_change_password' => true]);

    $this->actingAs($flagged)
        ->getJson('/api/v1/auth/force-password-change')
        ->assertOk()
        ->assertJsonPath('must_change_password', true);

    // /profile is a business endpoint, so it is closed while a change is pending.
    $this->actingAs($flagged)->getJson('/api/v1/profile')->assertForbidden();

    $user = User::factory()->intern()->create(['must_change_password' => false]);

    $this->actingAs($user)
        ->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'username', 'email', 'first_name', 'last_name', 'role', 'is_active', 'must_change_password'],
        ])
        ->assertJsonMissingPath('user.password');
});

it('deletes the authenticated account through the API and ends the session', function () {
    $user = User::factory()->intern()->create([
        'password' => 'password',
        'username' => 'delete-me-probe',
    ]);
    apiCreateIntern($user);

    $this->actingAs($user)
        ->deleteJson('/api/v1/profile', ['password' => 'password'])
        ->assertOk()
        ->assertJsonPath('message', 'Account deleted.');

    expect(User::withTrashed()->find($user->id)->trashed())->toBeTrue();
    $this->assertGuest();

    // A deleted account cannot authenticate again.
    $this->postJson('/api/v1/auth/login', [
        'username' => 'delete-me-probe',
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('username');
});

it('marks one notification as read and scopes it to its owner', function () {
    $owner = User::factory()->intern()->create();
    $other = User::factory()->intern()->create();

    $notification = $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\InternDocumentSubmitted',
        'data' => ['message' => 'Coverage probe', 'url' => '/my-internship'],
        'read_at' => null,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonStructure(['message', 'notification', 'target_url']);

    expect($notification->fresh()->read_at)->not->toBeNull();

    $otherNotification = $other->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\InternDocumentSubmitted',
        'data' => ['message' => 'Other', 'url' => '/my-internship'],
        'read_at' => null,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/v1/notifications/{$otherNotification->id}/read")
        ->assertNotFound();
});

it('lists partner agencies for intern staff', function () {
    $peso = User::factory()->pesoManager()->create();
    Company::create(['name' => 'Coverage Agency', 'code' => 'COV01']);

    $this->actingAs($peso)
        ->getJson('/api/v1/interns/agencies')
        ->assertOk()
        ->assertJsonStructure([
            'agencies' => [['id', 'name', 'code', 'is_active', 'is_partner_agency']],
        ]);

    $this->actingAs(User::factory()->intern()->create())
        ->getJson('/api/v1/interns/agencies')
        ->assertForbidden();
});

it('shows and updates a partner agency', function () {
    $admin = User::factory()->admin()->create();
    $company = Company::create(['name' => 'Original Agency', 'code' => 'ORIG01']);

    $this->actingAs($admin)
        ->getJson("/api/v1/settings/companies/{$company->id}")
        ->assertOk()
        ->assertJsonPath('company.code', 'ORIG01')
        ->assertJsonStructure(['company' => ['id', 'name', 'code', 'is_active', 'is_partner_agency', 'interns_count']]);

    $this->actingAs($admin)
        ->putJson("/api/v1/settings/companies/{$company->id}", [
            'name' => 'Renamed Agency',
            'code' => 'ORIG01',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('company.name', 'Renamed Agency')
        ->assertJsonPath('company.is_active', false)
        ->assertJsonPath('company.is_partner_agency', true);
});

it('lists document types with usage counts', function () {
    $admin = User::factory()->admin()->create();
    DocumentType::create([
        'name' => 'Coverage Type',
        'code' => 'COVDOC',
        'has_expiry' => true,
        'is_intern_document' => true,
        'is_optional' => false,
    ]);

    $response = $this->actingAs($admin)
        ->getJson('/api/v1/settings/document-types')
        ->assertOk()
        ->assertJsonStructure([
            'document_types' => [
                'data' => [['id', 'name', 'code', 'has_expiry', 'max_pages', 'is_intern_document', 'is_optional', 'intern_documents_count']],
                'links',
                'meta',
            ],
        ]);

    expect(collect($response->json('document_types.data'))->contains(
        fn ($type) => $type['code'] === 'COVDOC'
    ))->toBeTrue();
});

it('shows a staff user and toggles their activation status', function () {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->pesoManager()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->getJson("/api/v1/settings/users/{$target->id}")
        ->assertOk()
        ->assertJsonPath('user.id', $target->id)
        ->assertJsonPath('user.is_active', true);

    $this->actingAs($admin)
        ->patchJson("/api/v1/settings/users/{$target->id}/toggle-status")
        ->assertOk()
        ->assertJsonPath('user.is_active', false);

    // Admins may not deactivate themselves.
    $this->actingAs($admin)
        ->patchJson("/api/v1/settings/users/{$admin->id}/toggle-status")
        ->assertStatus(422);
});

it('resets a staff password and reports a random one-time password', function () {
    $admin = User::factory()->admin()->create(['password' => 'password']);
    $target = User::factory()->pesoManager()->create([
        'last_name' => 'Santos',
        'username' => 'santos-user',
        'password' => 'password',
    ]);

    $response = $this->actingAs($admin)
        ->postJson("/api/v1/settings/users/{$target->id}/reset-password")
        ->assertOk()
        ->assertJsonStructure(['message', 'default_password']);

    $returned = (string) $response->json('default_password');
    $derived = 'santos'.config('brand.password_suffix');

    // SEC-02: random one-time password, never name-derived.
    expect($returned)->not->toBe($derived)
        ->and(str_contains($returned, 'santos'))->toBeFalse()
        ->and(Hash::check($returned, $target->fresh()->password))->toBeTrue();

    // The reported password actually authenticates.
    $this->actingAs($admin)->postJson('/api/v1/auth/logout')->assertOk();
    $this->postJson('/api/v1/auth/login', [
        'username' => 'santos-user',
        'password' => $returned,
    ])->assertOk();
});

it('returns, exposes and revokes the active registration link', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson('/api/v1/intern-registration-link')
        ->assertOk()
        ->assertJsonPath('link', null);

    $created = $this->actingAs($admin)
        ->postJson('/api/v1/intern-registration-link', ['expires_in_days' => 7])
        ->assertCreated()
        ->assertJsonPath('link.is_active', true)
        ->assertJsonStructure(['message', 'link' => ['id', 'url', 'expires_at', 'is_active', 'uses_count']]);

    $url = $created->json('link.url');
    expect($url)->toContain('/register/');

    $this->actingAs($admin)
        ->getJson('/api/v1/intern-registration-link')
        ->assertOk()
        ->assertJsonPath('link.url', $url);

    $this->actingAs($admin)
        ->deleteJson('/api/v1/intern-registration-link')
        ->assertOk()
        ->assertJsonPath('message', 'Registration link revoked.');

    $this->actingAs($admin)
        ->getJson('/api/v1/intern-registration-link')
        ->assertOk()
        ->assertJsonPath('link', null);
});

it('reviews a document through the legacy single-step endpoint', function () {
    $type = apiInternDocumentType('AR');
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $document = InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $internUser->id,
        'file_path' => 'intern-docs/'.$intern->id.'/coverage.pdf',
        'original_filename' => 'coverage.pdf',
        'system_filename' => 'INT-'.$intern->id.'_AR_20260101000000.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => InternDocument::STATUS_PENDING_PESO,
    ]);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->patchJson("/api/v1/intern-documents/{$document->id}/review", ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_PENDING_ADMIN);

    $this->actingAs(User::factory()->admin()->create())
        ->patchJson("/api/v1/intern-documents/{$document->id}/review", ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_APPROVED);
});
