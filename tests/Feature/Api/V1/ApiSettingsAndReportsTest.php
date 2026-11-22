<?php

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('blocks non-admins from settings endpoints', function () {
    $this->actingAs(User::factory()->pesoManager()->create())
        ->getJson('/api/v1/settings/companies')
        ->assertForbidden();

    $this->actingAs(User::factory()->intern()->create())
        ->getJson('/api/v1/settings/users')
        ->assertForbidden();
});

it('creates, toggles and deletes a partner agency through the API', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/companies', [
            'name' => 'API Partner Agency',
            'code' => 'api',
            'is_active' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('company.code', 'API');

    $company = Company::where('code', 'API')->firstOrFail();

    $this->actingAs($admin)
        ->patchJson("/api/v1/settings/companies/{$company->id}/toggle-active")
        ->assertOk()
        ->assertJsonPath('company.is_active', false);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/settings/companies/{$company->id}")
        ->assertOk();

    expect(Company::find($company->id))->toBeNull();
});

it('refuses to delete an agency that still has interns', function () {
    $admin = User::factory()->admin()->create();
    $company = Company::create(['name' => 'Busy Agency', 'code' => 'BUSY', 'is_active' => true, 'is_partner_agency' => true]);

    $internUser = User::factory()->intern()->create();
    Intern::create([
        'user_id' => $internUser->id,
        'partner_agency_id' => $company->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/settings/companies/{$company->id}")
        ->assertStatus(422);

    expect(Company::find($company->id))->not->toBeNull();
});

it('creates a document type and refuses to delete one in use', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson('/api/v1/settings/document-types', [
            'name' => 'API Certificate',
            'code' => 'API-CERT',
        ])
        ->assertCreated();

    $type = DocumentType::where('code', 'API-CERT')->firstOrFail();

    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $admin->id,
        'file_path' => 'intern-docs/'.$intern->id.'/cert.pdf',
        'original_filename' => 'cert.pdf',
        'system_filename' => 'cert.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/settings/document-types/{$type->id}")
        ->assertStatus(422);

    expect(DocumentType::find($type->id))->not->toBeNull();
});

it('updates a document type whose name and code are unchanged', function () {
    $admin = User::factory()->admin()->create();
    $type = DocumentType::create([
        'name' => 'Unchanged Name',
        'code' => 'UNCHANGED',
        'has_expiry' => false,
    ]);

    // The route parameter is `{document_type}`; the request must ignore the
    // record itself when checking uniqueness, even with no `id` in the body.
    $this->actingAs($admin)
        ->putJson("/api/v1/settings/document-types/{$type->id}", [
            'name' => 'Unchanged Name',
            'code' => 'UNCHANGED',
            'has_expiry' => true,
        ])
        ->assertOk()
        ->assertJsonPath('document_type.has_expiry', true);

    expect($type->fresh()->name)->toBe('Unchanged Name')
        ->and($type->fresh()->code)->toBe('UNCHANGED')
        ->and($type->fresh()->has_expiry)->toBeTrue();
});

it('creates admin users and reports a random one-time password', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/settings/users', [
            'first_name' => 'New',
            'last_name' => 'Manager',
            'username' => 'api-manager',
            'role' => 'peso_manager',
            'is_active' => true,
        ])
        ->assertCreated();

    $created = User::where('username', 'api-manager')->firstOrFail();
    $returned = $response->json('default_password');
    $derived = strtolower('Manager').config('brand.password_suffix');

    expect($created->role)->toBe('peso_manager')
        ->and($created->must_change_password)->toBeTrue()
        // SEC-02: the one-time password is random, never name-derived.
        ->and($returned)->not->toBe($derived)
        ->and(str_contains(strtolower((string) $returned), 'manager'))->toBeFalse()
        ->and(Hash::check((string) $returned, $created->password))->toBeTrue();
});

it('protects the last administrator and prevents self-deletion', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->putJson("/api/v1/settings/users/{$admin->id}", [
            'first_name' => $admin->first_name,
            'last_name' => $admin->last_name,
            'username' => $admin->username,
            'role' => 'peso_manager',
            'is_active' => true,
        ])
        ->assertStatus(422);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/settings/users/{$admin->id}")
        ->assertStatus(422);

    expect($admin->fresh()->role)->toBe('admin');
});

it('returns role-appropriate dashboard data', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('show_intern_stats', true)
        ->assertJsonPath('total_interns', 0);

    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $this->actingAs($internUser)
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('is_intern_user', true)
        ->assertJsonPath('intern_self.id', $intern->id);
});

it('returns intern self-service tabs for interns only', function () {
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    $this->actingAs($internUser)
        ->getJson('/api/v1/my-internship')
        ->assertOk()
        ->assertJsonPath('intern.id', $intern->id)
        ->assertJsonPath('is_dtr_tab', true);

    $this->actingAs($internUser)
        ->getJson('/api/v1/my-internship/201-files')
        ->assertOk()
        ->assertJsonStructure(['checklist']);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v1/my-internship')
        ->assertForbidden();
});

it('streams CSV reports for managers and blocks interns', function () {
    $internUser = User::factory()->intern()->create();
    apiCreateIntern($internUser);

    $response = $this->actingAs(User::factory()->pesoManager()->create())
        ->get('/api/v1/reports/export/interns');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->streamedContent())->toContain('Name,Username');

    $this->actingAs(User::factory()->intern()->create())
        ->get('/api/v1/reports/export/interns')
        ->assertForbidden();
});

it('restricts the audit log to admins', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v1/reports/audit-log')
        ->assertOk()
        ->assertJsonStructure(['logs' => ['data'], 'users']);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->getJson('/api/v1/reports/audit-log')
        ->assertForbidden();
});
