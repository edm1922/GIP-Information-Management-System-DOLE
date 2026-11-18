<?php

use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\InternDocumentReviewEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

function workflowIntern(): Intern
{
    $user = User::factory()->intern()->create();

    return Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
    ]);
}

/** @return array<string, mixed> */
function workflowDtrPayload(array $overrides = []): array
{
    return array_merge([
        'document_type_id' => apiInternDocumentType('DTR')->id,
        'days' => [now()->subDays(4)->format('Y-m-d'), now()->subDays(3)->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 100, 'application/pdf'),
    ], $overrides);
}

function workflowUpload(Intern $intern, array $overrides = []): InternDocument
{
    test()->actingAs($intern->user)
        ->postJson('/api/v1/intern-documents', workflowDtrPayload($overrides))
        ->assertCreated();

    return InternDocument::latest('id')->firstOrFail();
}

it('stores intern submissions as pending PESO review without hours', function () {
    $intern = workflowIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    $document = workflowUpload($intern);

    expect($document->review_status)->toBe(InternDocument::STATUS_PENDING_PESO)
        ->and($document->dtrDays)->toHaveCount(2)
        ->and($document->dtrDays->every(fn (DtrDay $day) => $day->hours === null))->toBeTrue()
        ->and($intern->fresh()->pending_hours)->toBe(0.0)
        ->and($peso->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($admin->fresh()->unreadNotifications()->count())->toBe(0);

    $uploadEvent = InternDocumentReviewEvent::where('document_id', $document->id)->firstOrFail();
    expect($uploadEvent->action)->toBe('uploaded')
        ->and($uploadEvent->to_status)->toBe(InternDocument::STATUS_PENDING_PESO);
});

it('rejects intern-supplied working hours', function () {
    $intern = workflowIntern();
    $type = apiInternDocumentType('DTR');

    $this->actingAs($intern->user)
        ->postJson('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'days' => [now()->subDay()->format('Y-m-d')],
            'day_hours' => [now()->subDay()->format('Y-m-d') => 8],
            'file' => UploadedFile::fake()->create('dtr.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hours');
});

it('runs PESO approval, admin hours assignment and final approval', function () {
    $intern = workflowIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();
    $document = workflowUpload($intern);
    $dates = $document->dtrDays->map(fn (DtrDay $day) => $day->work_date->format('Y-m-d'))->all();

    // PESO moves it to the Admin.
    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_PENDING_ADMIN);

    expect($document->fresh()->peso_reviewed_by)->toBe($peso->id)
        ->and($admin->fresh()->unreadNotifications()->count())->toBe(1);

    // Admin cannot approve before assigning hours.
    $this->actingAs($admin)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-approve")
        ->assertStatus(422);

    // Save a draft for one day only.
    $this->actingAs($admin)
        ->patchJson("/api/v1/intern-documents/{$document->id}/hours", [
            'day_hours' => [$dates[0] => 8],
        ])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_PENDING_ADMIN);

    // Still incomplete.
    $this->actingAs($admin)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-approve")
        ->assertStatus(422)
        ->assertJsonValidationErrors('hours');

    // Complete hours and approve.
    $this->actingAs($admin)
        ->patchJson("/api/v1/intern-documents/{$document->id}/hours", [
            'day_hours' => [$dates[0] => 8, $dates[1] => 7.5],
        ])
        ->assertOk();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-approve")
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_APPROVED)
        ->assertJsonPath('document.admin_reviewer', $admin->name);

    expect((float) $intern->fresh()->rendered_hours)->toBe(15.5)
        ->and($intern->user->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($peso->fresh()->unreadNotifications()->count())->toBe(2)
        ->and(InternDocumentReviewEvent::where('document_id', $document->id)->where('action', 'admin_approved')->exists())->toBeTrue();
});

it('supports admin return to PESO and PESO return to intern, then resubmission', function () {
    $intern = workflowIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();
    $document = workflowUpload($intern);
    $dates = $document->dtrDays->map(fn (DtrDay $day) => $day->work_date->format('Y-m-d'))->all();

    $this->actingAs($peso)->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")->assertOk();
    $this->actingAs($admin)->patchJson("/api/v1/intern-documents/{$document->id}/hours", [
        'day_hours' => [$dates[0] => 8, $dates[1] => 8],
    ])->assertOk();

    // Admin returns to PESO with remarks.
    $this->actingAs($admin)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-return", ['remarks' => 'Scan is unreadable, please resubmit.'])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_RETURNED_TO_PESO);

    // Hours and dates are preserved.
    expect((float) $document->fresh()->dtrDays()->sum('hours'))->toBe(16.0)
        ->and($document->fresh()->admin_remarks)->toBe('Scan is unreadable, please resubmit.');

    // PESO forwards to the intern.
    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-return", ['remarks' => 'Please upload a clearer scan.'])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_RETURNED_TO_INTERN);

    expect($intern->user->fresh()->unreadNotifications()->count())->toBe(1);

    // Intern resubmits with the same dates and a new file.
    $this->actingAs($intern->user)
        ->post("/api/v1/intern-documents/{$document->id}/resubmit", [
            'days' => $dates,
            'file' => UploadedFile::fake()->create('dtr-corrected.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_PENDING_PESO);

    $fresh = $document->fresh();
    expect((float) $fresh->dtrDays()->sum('hours'))->toBe(16.0) // unchanged dates keep hours
        ->and($fresh->admin_remarks)->toBeNull()
        ->and($fresh->peso_remarks)->toBeNull()
        ->and($peso->fresh()->unreadNotifications()->count())->toBe(3)
        ->and($fresh->reviewEvents()->where('action', 'resubmitted')->exists())->toBeTrue();
});

it('enforces role permissions on every workflow action', function () {
    $intern = workflowIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();
    $document = workflowUpload($intern);
    $date = $document->dtrDays->first()->work_date->format('Y-m-d');

    $this->actingAs($intern->user)
        ->patchJson("/api/v1/intern-documents/{$document->id}/hours", ['day_hours' => [$date => 8]])
        ->assertForbidden();

    $this->actingAs($admin)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")
        ->assertForbidden();

    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-approve")
        ->assertForbidden();

    $this->actingAs($peso)
        ->patchJson("/api/v1/intern-documents/{$document->id}/hours", ['day_hours' => [$date => 8]])
        ->assertForbidden();
});

it('shows the review history without losing previous cycles', function () {
    $intern = workflowIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();
    $document = workflowUpload($intern);

    $this->actingAs($peso)->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/intern-documents/{$document->id}/admin-return", ['remarks' => 'Contract dates are wrong.'])->assertOk();
    $this->actingAs($peso)->postJson("/api/v1/intern-documents/{$document->id}/peso-return", ['remarks' => 'Please fix the contract dates.'])->assertOk();

    $this->actingAs($admin)
        ->getJson("/api/v1/intern-documents/{$document->id}/history")
        ->assertOk()
        ->assertJsonCount(4, 'events')
        ->assertJsonPath('events.1.action', 'peso_approved')
        ->assertJsonPath('events.2.action', 'admin_returned')
        ->assertJsonPath('events.2.remarks', 'Contract dates are wrong.')
        ->assertJsonPath('events.3.action', 'peso_returned');

    $this->actingAs(User::factory()->intern()->create())
        ->getJson("/api/v1/intern-documents/{$document->id}/history")
        ->assertForbidden();
});
