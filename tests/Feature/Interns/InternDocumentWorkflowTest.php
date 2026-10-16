<?php

use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function makeInternDocumentType(string $code = 'DTR'): DocumentType
{
    return DocumentType::create([
        'department_id' => null,
        'name' => $code === 'DTR' ? 'Daily Time Record' : 'Accomplishment Report',
        'code' => $code,
        'has_expiry' => false,
        'is_intern_document' => true,
        'is_optional' => false,
    ]);
}

it('lets an intern upload a DTR and advances rendered hours', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('DTR');

    $user = User::factory()->intern()->create();
    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'document_period' => 'June 2026',
        'hours' => 8,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ]);

    $response->assertRedirect(route('my-internship.index'));
    $response->assertSessionHasNoErrors();

    $intern->refresh();
    expect($intern->rendered_hours)->toBe(8);

    $document = InternDocument::first();
    expect($document)->not->toBeNull();
    expect((int) $document->intern_id)->toBe((int) $intern->id);
    expect($document->review_status)->toBe('pending');
    expect($document->file_path)->toStartWith('intern-docs/'.$intern->id.'/');
    Storage::disk('local')->assertExists($document->file_path);
});

it('rejects a DTR upload without rendered hours', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('DTR');

    $user = User::factory()->intern()->create();
    Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('hours');
    expect(InternDocument::count())->toBe(0);
});

it('notifies the PESO team and allows an admin to approve an uploaded document', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('AR');

    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = Intern::create([
        'user_id' => $internUser->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ]);

    $this->actingAs($internUser)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::first();
    expect($document->review_status)->toBe('pending');

    // Admin (PESO team) was notified of the upload.
    expect($admin->fresh()->notifications()->count())->toBeGreaterThanOrEqual(1);

    $this->actingAs($admin)->patch(route('intern-documents.review', $document), [
        'decision' => 'approved',
    ])->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->review_status)->toBe('approved');
    expect((int) $document->reviewed_by)->toBe((int) $admin->id);
    expect($document->reviewed_at)->not->toBeNull();

    // Intern was notified of the review outcome.
    expect($internUser->fresh()->notifications()->count())->toBeGreaterThanOrEqual(1);
});

it('prevents an intern from downloading another intern\'s document', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('AR');

    $ownerUser = User::factory()->intern()->create();
    $owner = Intern::create([
        'user_id' => $ownerUser->id, 'required_hours' => 486, 'rendered_hours' => 0, 'status' => 'active',
    ]);

    $otherUser = User::factory()->intern()->create();
    Intern::create([
        'user_id' => $otherUser->id, 'required_hours' => 486, 'rendered_hours' => 0, 'status' => 'active',
    ]);

    $this->actingAs($ownerUser)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ]);

    $document = InternDocument::first();

    $this->actingAs($otherUser)
        ->get(route('intern-documents.download', $document))
        ->assertForbidden();
});

it('allows an intern and admin to preview a document inline with appropriate content headers', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('DTR');
    $user = User::factory()->intern()->create();
    $intern = Intern::create([
        'user_id' => $user->id, 'required_hours' => 486, 'rendered_hours' => 0, 'status' => 'active',
    ]);

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'document_period' => 'July 2026',
        'hours' => 8,
        'file' => UploadedFile::fake()->create('report.pdf', 30, 'application/pdf'),
    ]);

    $document = InternDocument::first();

    // The owner intern can preview the document inline
    $response = $this->actingAs($user)->get(route('intern-documents.preview', $document));
    $response->assertOk();
    $response->assertHeader('Content-Disposition');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');

    // Admin can also preview it
    $admin = User::factory()->admin()->create();
    $adminResponse = $this->actingAs($admin)->get(route('intern-documents.preview', $document));
    $adminResponse->assertOk();

    // Another intern cannot preview it
    $other = User::factory()->intern()->create();
    $this->actingAs($other)->get(route('intern-documents.preview', $document))->assertForbidden();
});

it('marks interns as completed when the completion threshold is reached', function () {
    $user = User::factory()->intern()->create();
    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 450,
        'status' => 'active',
    ]);

    $this->artisan('interns:check-completion')->assertSuccessful();

    expect($intern->fresh()->status)->toBe('completed');
});

it('flips expired active contracts to inactive', function () {
    $user = User::factory()->intern()->create();
    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 10,
        'status' => 'active',
        'contract_end_date' => now()->subDay()->toDateString(),
    ]);

    $this->artisan('interns:check-contract-expiry')->assertSuccessful();

    expect($intern->fresh()->status)->toBe('inactive');
});
