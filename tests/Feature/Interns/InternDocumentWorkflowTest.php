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

function workflowBladeIntern(): array
{
    $user = User::factory()->intern()->create();
    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ]);

    return [$user, $intern];
}

it('lets an intern select days without entering hours and credits them only after the full workflow', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('DTR');
    [$user, $intern] = workflowBladeIntern();
    $worked = [now()->subDays(3)->format('Y-m-d'), now()->subDays(2)->format('Y-m-d')];

    $response = $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'days' => $worked,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ]);

    $response->assertRedirect(route('my-internship.index'));
    $response->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();

    expect($document->review_status)->toBe(InternDocument::STATUS_PENDING_PESO)
        ->and($document->dtrDays()->count())->toBe(2)
        ->and($document->dtrDays()->whereNotNull('hours')->count())->toBe(0)
        ->and($intern->fresh()->rendered_hours)->toBe(0.0)
        ->and($intern->fresh()->pending_hours)->toBe(0.0);

    [$admin] = apiApproveInternDocumentThroughWorkflow($document);

    expect($document->fresh()->review_status)->toBe(InternDocument::STATUS_APPROVED)
        ->and($document->fresh()->admin_reviewed_by)->toBe($admin->id)
        ->and($intern->fresh()->rendered_hours)->toBe(16.0);
});

it('rejects a DTR upload without selected working days', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('DTR');
    [$user] = workflowBladeIntern();

    $response = $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('days');
    expect(InternDocument::count())->toBe(0);
});

it('notifies only PESO on upload, then Admin and intern as the workflow advances', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('AR');
    [$internUser, $intern] = workflowBladeIntern();
    $admin = User::factory()->admin()->create();
    $peso = User::factory()->pesoManager()->create();

    $this->actingAs($internUser)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();

    expect($peso->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($admin->fresh()->unreadNotifications()->count())->toBe(0);

    $this->actingAs($peso)->post(route('intern-documents.peso-approve', $document))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($document->fresh()->review_status)->toBe(InternDocument::STATUS_PENDING_ADMIN)
        ->and($admin->fresh()->unreadNotifications()->count())->toBe(1);

    $this->actingAs($admin)->post(route('intern-documents.admin-approve', $document))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($document->fresh()->review_status)->toBe(InternDocument::STATUS_APPROVED)
        ->and($document->fresh()->admin_reviewed_by)->toBe($admin->id)
        ->and($internUser->fresh()->unreadNotifications()->count())->toBe(1);
});

it('prevents an intern from downloading another intern\'s document', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('AR');

    [$ownerUser] = workflowBladeIntern();
    [$otherUser] = workflowBladeIntern();

    $this->actingAs($ownerUser)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();

    $this->actingAs($otherUser)
        ->get(route('intern-documents.download', $document))
        ->assertForbidden();
});

it('allows an intern and admin to preview a document inline with appropriate content headers', function () {
    Storage::fake('local');

    $type = makeInternDocumentType('DTR');
    [$user] = workflowBladeIntern();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $type->id,
        'days' => [now()->subDays(2)->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('report.pdf', 30, 'application/pdf'),
    ]);

    $document = InternDocument::firstOrFail();

    $response = $this->actingAs($user)->get(route('intern-documents.preview', $document));
    $response->assertOk();
    $response->assertHeader('Content-Disposition');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('intern-documents.preview', $document))
        ->assertOk();

    $this->actingAs(User::factory()->intern()->create())
        ->get(route('intern-documents.preview', $document))
        ->assertForbidden();
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
