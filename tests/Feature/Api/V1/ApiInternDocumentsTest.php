<?php

use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

it('uploads a DTR as pending PESO review and credits hours only after the full workflow', function () {
    $type = apiInternDocumentType('DTR');
    $user = User::factory()->intern()->create();
    $intern = apiCreateIntern($user);

    $worked = [now()->subDays(3)->format('Y-m-d'), now()->subDays(2)->format('Y-m-d')];

    $response = $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'days' => $worked,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_PENDING_PESO);

    expect($intern->fresh()->rendered_hours)->toBe(0.0)
        ->and($intern->fresh()->pending_hours)->toBe(0.0);

    $document = InternDocument::firstOrFail();

    expect((int) $document->intern_id)->toBe((int) $intern->id)
        ->and($document->file_path)->toStartWith('intern-docs/'.$intern->id.'/')
        ->and($document->dtrDays()->count())->toBe(2)
        ->and($document->dtrDays()->whereNotNull('hours')->count())->toBe(0);

    Storage::disk('local')->assertExists($document->file_path);

    [$admin, $peso] = apiApproveInternDocumentThroughWorkflow($document->fresh());

    expect($document->fresh()->review_status)->toBe(InternDocument::STATUS_APPROVED)
        ->and($document->fresh()->peso_reviewed_by)->toBe($peso->id)
        ->and($document->fresh()->admin_reviewed_by)->toBe($admin->id)
        ->and($intern->fresh()->rendered_hours)->toBe(16.0);
});

it('rejects a DTR upload without selected working days', function () {
    $type = apiInternDocumentType('DTR');
    $user = User::factory()->intern()->create();
    apiCreateIntern($user);

    $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ])->assertStatus(422)
        ->assertJsonValidationErrors('days');

    expect(InternDocument::count())->toBe(0);
});

it('requires admin remarks when returning a document to the PESO manager', function () {
    $type = apiInternDocumentType('AR');
    $internUser = User::factory()->intern()->create();
    apiCreateIntern($internUser);

    $this->actingAs($internUser)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ])->assertCreated();

    $document = InternDocument::firstOrFail();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-return", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('remarks');

    expect($document->fresh()->review_status)->toBe(InternDocument::STATUS_PENDING_ADMIN);
});

it('prevents an intern from downloading another intern document', function () {
    $type = apiInternDocumentType('AR');

    $owner = User::factory()->intern()->create();
    apiCreateIntern($owner);

    $other = User::factory()->intern()->create();
    apiCreateIntern($other);

    $this->actingAs($owner)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ])->assertCreated();

    $document = InternDocument::firstOrFail();

    $this->actingAs($other)
        ->get("/api/v1/intern-documents/{$document->id}/download")
        ->assertForbidden();
});

it('previews documents inline for the owner and admins only', function () {
    $type = apiInternDocumentType('DTR');
    $owner = User::factory()->intern()->create();
    apiCreateIntern($owner);

    $this->actingAs($owner)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'days' => [now()->subDay()->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('report.pdf', 30, 'application/pdf'),
    ])->assertCreated();

    $document = InternDocument::firstOrFail();

    $response = $this->actingAs($owner)
        ->get("/api/v1/intern-documents/{$document->id}/preview");

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('inline');

    $this->actingAs(User::factory()->admin()->create())
        ->get("/api/v1/intern-documents/{$document->id}/preview")
        ->assertOk();

    $this->actingAs(User::factory()->intern()->create())
        ->get("/api/v1/intern-documents/{$document->id}/preview")
        ->assertForbidden();
});

it('blocks interns from reviewing documents and browsing folders', function () {
    $intern = User::factory()->intern()->create();
    apiCreateIntern($intern);
    $document = InternDocument::create([
        'intern_id' => $intern->intern->id,
        'document_type_id' => apiInternDocumentType('AR')->id,
        'uploaded_by' => $intern->id,
        'file_path' => 'intern-docs/1/example.pdf',
        'original_filename' => 'example.pdf',
        'system_filename' => 'example.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => InternDocument::STATUS_PENDING_PESO,
    ]);

    $this->actingAs($intern)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")
        ->assertForbidden();

    $this->actingAs($intern)
        ->patchJson("/api/v1/intern-documents/{$document->id}/hours", ['day_hours' => ['2026-01-01' => 8]])
        ->assertForbidden();

    $this->actingAs($intern)
        ->getJson('/api/v1/intern-documents/folders')
        ->assertForbidden();
});

it('lists and filters documents for admins and peso managers', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v1/intern-documents?review_status=pending_peso')
        ->assertOk()
        ->assertJsonStructure(['documents' => ['data']]);

    $this->actingAs(User::factory()->pesoManager()->create())
        ->getJson('/api/v1/intern-documents/folders')
        ->assertOk()
        ->assertJsonStructure(['interns' => ['data'], 'search', 'filter']);
});
