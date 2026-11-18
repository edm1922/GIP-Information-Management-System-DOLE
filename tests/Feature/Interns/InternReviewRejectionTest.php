<?php

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Return/correction workflow: Admin → PESO → Intern → resubmission.
 */
function rjType(): DocumentType
{
    return DocumentType::firstOrCreate(
        ['code' => 'DTR'],
        ['department_id' => null, 'name' => 'Daily Time Record', 'has_expiry' => false, 'is_intern_document' => true, 'is_optional' => false]
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

function rjDocument(Intern $intern, string $status = InternDocument::STATUS_PENDING_PESO, ?float $hours = null, int $days = 2): InternDocument
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

it('shows stage-specific actions in the Blade review queue', function () {
    [$internUser, $intern] = rjIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    rjDocument($intern, InternDocument::STATUS_PENDING_PESO);

    $pesoResponse = $this->actingAs($peso)->get(route('intern-documents.index'));
    $pesoResponse->assertOk();
    $pesoResponse->assertSee('To Admin');
    $pesoResponse->assertSee('Send back');

    rjDocument($intern, InternDocument::STATUS_PENDING_ADMIN);

    $adminResponse = $this->actingAs($admin)->get(route('intern-documents.index'));
    $adminResponse->assertOk();
    $adminResponse->assertSee('Hours');
    $adminResponse->assertSee('Approve');
    $adminResponse->assertSee('Return');
    $adminResponse->assertSee(route('intern-documents.admin-return', InternDocument::where('review_status', InternDocument::STATUS_PENDING_ADMIN)->firstOrFail()));
});

it('requires remarks when the Admin returns to PESO and preserves hours', function () {
    [, $intern] = rjIntern();
    $admin = User::factory()->admin()->create();
    $document = rjDocument($intern, InternDocument::STATUS_PENDING_ADMIN, 8, 2);
    $hoursBefore = (float) $document->dtrDays()->sum('hours');

    $this->actingAs($admin)
        ->post(route('intern-documents.admin-return', $document), [])
        ->assertSessionHasErrors('remarks');

    $this->actingAs($admin)
        ->post(route('intern-documents.admin-return', $document), [
            'remarks' => 'The scan is unreadable; please provide a clearer copy.',
        ])
        ->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->review_status)->toBe(InternDocument::STATUS_RETURNED_TO_PESO)
        ->and($document->admin_remarks)->toContain('unreadable')
        ->and((float) $document->dtrDays()->sum('hours'))->toBe($hoursBefore);
});

it('lets PESO send the returned document to the intern, who then resubmits', function () {
    Storage::fake('local');
    [$internUser, $intern] = rjIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    $document = rjDocument($intern, InternDocument::STATUS_RETURNED_TO_PESO, 8, 2);
    $document->forceFill(['admin_remarks' => 'Wrong contract dates on the DTR.'])->save();

    $this->actingAs($peso)
        ->post(route('intern-documents.peso-return', $document), [
            'remarks' => 'Please correct the dates and resubmit a clear scan.',
        ])
        ->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->review_status)->toBe(InternDocument::STATUS_RETURNED_TO_INTERN);

    // Intern sees the remarks and resubmits (same dates, new evidence).
    $this->actingAs($internUser)
        ->get(route('my-internship.index'))
        ->assertOk()
        ->assertSee('clear scan')
        ->assertSee('Resubmit');

    $days = $document->dtrDays()->pluck('work_date')->map(fn ($date) => $date->format('Y-m-d'))->all();

    $this->actingAs($internUser)
        ->post(route('intern-documents.resubmit', $document), [
            'days' => $days,
            'file' => UploadedFile::fake()->create('dtr-corrected.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $document->refresh();
    expect($document->review_status)->toBe(InternDocument::STATUS_PENDING_PESO)
        ->and($document->reviewEvents()->where('action', 'resubmitted')->exists())->toBeTrue();
});

it('does not expose the old rejection flow', function () {
    [, $intern] = rjIntern();
    $admin = User::factory()->admin()->create();
    $document = rjDocument($intern, InternDocument::STATUS_PENDING_ADMIN);

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    $response->assertDontSee('Why is this document being rejected?');
    $response->assertDontSee('data-reject-document', false);
});
