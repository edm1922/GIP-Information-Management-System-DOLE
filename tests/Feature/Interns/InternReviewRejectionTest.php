<?php

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Rejection now goes through a modal with a detailed written explanation
 * instead of a one-line "Reason" box on the row.
 */
function rjType(): DocumentType
{
    return DocumentType::firstOrCreate(
        ['code' => 'DTR'],
        [
            'department_id' => null,
            'name' => 'Daily Time Record',
            'has_expiry' => false,
            'is_intern_document' => true,
            'is_optional' => false,
        ]
    );
}

function rjIntern(): array
{
    $user = User::factory()->intern()->create();

    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
        'contract_start_date' => now()->subMonths(2)->toDateString(),
        'contract_end_date' => now()->addMonth()->toDateString(),
    ]);

    return [$user, $intern];
}

function rjDocument(Intern $intern, string $status = 'pending', float $hours = 8, int $days = 2): InternDocument
{
    $document = InternDocument::withoutGlobalScope('intern')->create([
        'intern_id' => $intern->id,
        'document_type_id' => rjType()->id,
        'uploaded_by' => $intern->user_id,
        'file_path' => 'intern-docs/'.$intern->id.'/'.uniqid('rj_', true).'.pdf',
        'original_filename' => 'dtr.pdf',
        'system_filename' => uniqid('rj_', true).'.pdf',
        'page_count' => 1,
        'file_size_bytes' => 100,
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => $status,
    ]);

    for ($i = 0; $i < $days; $i++) {
        DtrDay::create([
            'document_id' => $document->id,
            'intern_id' => $intern->id,
            'work_date' => now()->subDays($i + 2)->format('Y-m-d'),
            'hours' => $hours,
        ]);
    }

    return $document;
}

it('offers a rejection modal instead of an inline reason box', function () {
    [, $intern] = rjIntern();
    rjDocument($intern);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    $response->assertSee('data-reject-document', false);
    $response->assertSee('rejectDocumentModal');
    $response->assertSee('Why is this document being rejected?');
    $response->assertSee('review_remarks');
    $response->assertDontSee('placeholder="Reason"', false);
    $response->assertDontSee('style="width: 140px;"', false);
});

it('uses the same rejection modal on the intern detail page', function () {
    [, $intern] = rjIntern();
    rjDocument($intern);
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('interns.show', $intern));

    $response->assertOk();
    $response->assertSee('data-reject-document', false);
    $response->assertSee('rejectDocumentModal');
});

it('refuses a rejection without an explanation', function () {
    Storage::fake('local');

    [$user, $intern] = rjIntern();
    $document = rjDocument($intern);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), ['decision' => 'rejected'])
        ->assertSessionHasErrors('review_remarks');

    expect($document->fresh()->review_status)->toBe('pending');
});

it('refuses a token one-word rejection reason', function () {
    [$user, $intern] = rjIntern();
    $document = rjDocument($intern);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), [
            'decision' => 'rejected',
            'review_remarks' => 'Wrong',
        ])
        ->assertSessionHasErrors('review_remarks');

    expect($document->fresh()->review_status)->toBe('pending');
});

it('stores the detailed explanation and revokes the credited hours', function () {
    [$user, $intern] = rjIntern();
    $document = rjDocument($intern, 'pending', 8, 2);
    $admin = User::factory()->admin()->create();

    // Credit first by approving.
    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), ['decision' => 'approved'])
        ->assertSessionHasNoErrors();

    expect((float) $intern->fresh()->rendered_hours)->toBe(16.0);

    $explanation = 'Only 2 of the 5 claimed days appear on the attached DTR scan, and page 2 is unreadable.';

    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), [
            'decision' => 'rejected',
            'review_remarks' => $explanation,
        ])
        ->assertSessionHasNoErrors();

    $document->refresh();

    expect($document->review_status)->toBe('rejected')
        ->and($document->review_remarks)->toBe($explanation)
        ->and((float) $intern->fresh()->rendered_hours)->toBe(0.0);

    // The intern can read the explanation on their own page.
    $this->actingAs($user)
        ->get(route('my-internship.index'))
        ->assertOk()
        ->assertSee('page 2 is unreadable');
});
