<?php

use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * PESO managers are reviewers for intern documents (`role:admin,peso_manager`
 * on the review routes plus `InternDocumentPolicy::review()`). The rest of the
 * suite only covers admin approval, so this file locks the manager path in.
 */

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

/**
 * Upload a two-day DTR (8h/day) as the owning intern and return [document, intern].
 *
 * @return array{0: InternDocument, 1: Intern}
 */
function pesoReviewUploadPendingDtr(): array
{
    $type = apiInternDocumentType('DTR');
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    test()->actingAs($internUser)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'days' => [now()->subDays(2)->format('Y-m-d'), now()->subDay()->format('Y-m-d')],
        'hours_per_day' => 8,
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ])->assertCreated();

    return [InternDocument::firstOrFail(), $intern];
}

it('lets a PESO manager approve a DTR and credits the hours', function () {
    [$document, $intern] = pesoReviewUploadPendingDtr();

    $this->actingAs(User::factory()->pesoManager()->create())
        ->patchJson("/api/v1/intern-documents/{$document->id}/review", ['decision' => 'approved'])
        ->assertOk()
        ->assertJsonPath('document.review_status', 'approved');

    expect((float) $intern->fresh()->rendered_hours)->toBe(16.0)
        ->and($document->fresh()->reviewed_by)->not->toBeNull();
});

it('lets a PESO manager reject a DTR with remarks without crediting the hours', function () {
    [$document, $intern] = pesoReviewUploadPendingDtr();

    $this->actingAs(User::factory()->pesoManager()->create())
        ->patchJson("/api/v1/intern-documents/{$document->id}/review", [
            'decision' => 'rejected',
            'review_remarks' => 'The scanned DTR is unsigned; please re-upload.',
        ])
        ->assertOk()
        ->assertJsonPath('document.review_status', 'rejected');

    expect((float) $intern->fresh()->rendered_hours)->toBe(0.0)
        ->and($document->fresh()->review_remarks)->toContain('unsigned');
});

it('lets a PESO manager review a DTR from the Blade document queue', function () {
    [$document, $intern] = pesoReviewUploadPendingDtr();

    $this->from('/intern-documents')
        ->actingAs(User::factory()->pesoManager()->create())
        ->patch("/intern-documents/{$document->id}/review", ['decision' => 'approved'])
        ->assertRedirect('/intern-documents')
        ->assertSessionHas('success');

    expect($document->fresh()->review_status)->toBe('approved')
        ->and((float) $intern->fresh()->rendered_hours)->toBe(16.0);
});

it('gives a PESO manager access to the reviewer screens but not admin settings', function () {
    $manager = User::factory()->pesoManager()->create();

    $this->actingAs($manager)->getJson('/api/v1/intern-documents/folders')->assertOk();
    $this->actingAs($manager)->getJson('/api/v1/intern-documents?review_status=pending')->assertOk();

    // Admin-only surface stays closed to reviewers.
    $this->actingAs($manager)->getJson('/api/v1/settings/users')->assertForbidden();
});
