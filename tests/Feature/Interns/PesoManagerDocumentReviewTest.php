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

function pesoReviewUploadPendingDtr(): array
{
    $type = apiInternDocumentType('DTR');
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);

    test()->actingAs($internUser)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'days' => [now()->subDays(2)->format('Y-m-d'), now()->subDay()->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 50, 'application/pdf'),
    ])->assertCreated();

    return [InternDocument::firstOrFail(), $intern];
}

it('lets a PESO manager approve a DTR for Admin review without crediting hours', function () {
    [$document, $intern] = pesoReviewUploadPendingDtr();
    $peso = User::factory()->pesoManager()->create();

    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_PENDING_ADMIN);

    expect((float) $intern->fresh()->rendered_hours)->toBe(0.0)
        ->and($document->fresh()->peso_reviewed_by)->toBe($peso->id);
});

it('lets a PESO manager send a DTR back to the intern with remarks', function () {
    [$document, $intern] = pesoReviewUploadPendingDtr();
    $peso = User::factory()->pesoManager()->create();

    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/peso-return", [
            'remarks' => 'The scanned DTR is unsigned; please re-upload.',
        ])
        ->assertOk()
        ->assertJsonPath('document.review_status', InternDocument::STATUS_RETURNED_TO_INTERN);

    expect((float) $intern->fresh()->rendered_hours)->toBe(0.0)
        ->and($document->fresh()->peso_remarks)->toContain('unsigned');
});

it('blocks PESO managers from Admin-only hours and final approval', function () {
    [$document] = pesoReviewUploadPendingDtr();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    // Move to Admin stage so the status would otherwise allow hours.
    $this->actingAs($peso)->postJson("/api/v1/intern-documents/{$document->id}/peso-approve")->assertOk();
    $date = $document->fresh()->dtrDays()->first()->work_date->format('Y-m-d');

    $this->actingAs($peso)
        ->patchJson("/api/v1/intern-documents/{$document->id}/hours", ['day_hours' => [$date => 8]])
        ->assertForbidden();

    $this->actingAs($peso)
        ->postJson("/api/v1/intern-documents/{$document->id}/admin-approve")
        ->assertForbidden();
});

it('shows the PESO review action on the Blade queue', function () {
    [$document] = pesoReviewUploadPendingDtr();
    $peso = User::factory()->pesoManager()->create();

    $response = $this->actingAs($peso)->get('/intern-documents');

    $response->assertOk();
    $response->assertSee(route('intern-documents.peso-approve', $document));
    $response->assertSee('To Admin');
});

it('gives a PESO manager access to the reviewer screens but not admin settings', function () {
    $manager = User::factory()->pesoManager()->create();

    $this->actingAs($manager)->getJson('/api/v1/intern-documents/folders')->assertOk();
    $this->actingAs($manager)->getJson('/api/v1/intern-documents?review_status=pending_peso')->assertOk();
    $this->actingAs($manager)->getJson('/api/v1/settings/users')->assertForbidden();
});
