<?php

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Hours accounting: only approved DTRs are credited, and rejecting a DTR takes
 * the hours back out of the intern's rendered total.
 */
function hoursDtrType(): DocumentType
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

function hoursIntern(int $rendered = 0): array
{
    $user = User::factory()->intern()->create();

    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => $rendered,
        'status' => 'active',
        'contract_start_date' => now()->subMonths(2)->toDateString(),
        'contract_end_date' => now()->addMonth()->toDateString(),
    ]);

    return [$user, $intern];
}

/** Directly create an approved/pending/rejected DTR with claimed days. */
function hoursClaimedDtr(Intern $intern, array $dates, string $status, float $hours = 8): InternDocument
{
    $document = InternDocument::withoutGlobalScope('intern')->create([
        'intern_id' => $intern->id,
        'document_type_id' => hoursDtrType()->id,
        'uploaded_by' => $intern->user_id,
        'file_path' => 'intern-docs/'.$intern->id.'/'.uniqid('h_', true).'.pdf',
        'original_filename' => 'dtr.pdf',
        'system_filename' => uniqid('h_', true).'.pdf',
        'page_count' => 1,
        'file_size_bytes' => 100,
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => $status,
    ]);

    foreach ($dates as $date) {
        DtrDay::create([
            'document_id' => $document->id,
            'intern_id' => $intern->id,
            'work_date' => $date,
            'hours' => $hours,
        ]);
    }

    return $document;
}

it('does not credit hours while a DTR is still pending', function () {
    Storage::fake('local');

    [$user, $intern] = hoursIntern();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => hoursDtrType()->id,
        'days' => [now()->subDays(2)->format('Y-m-d'), now()->subDay()->format('Y-m-d')],
        'hours_per_day' => 8,
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect((int) $intern->fresh()->rendered_hours)->toBe(0)
        ->and($intern->fresh()->pending_hours)->toBe(16.0);
});

it('revokes credited hours when an approved DTR is later rejected', function () {
    Storage::fake('local');

    [$user, $intern] = hoursIntern();
    $admin = User::factory()->admin()->create();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => hoursDtrType()->id,
        'days' => [now()->subDays(4)->format('Y-m-d'), now()->subDays(3)->format('Y-m-d')],
        'hours_per_day' => 8,
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();

    // Approve → credited.
    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), ['decision' => 'approved'])
        ->assertSessionHasNoErrors();
    expect((int) $intern->fresh()->rendered_hours)->toBe(16);

    // Reject → the hours must come straight back off.
    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), [
            'decision' => 'rejected',
            'review_remarks' => 'Scan does not match the claimed days.',
        ])
        ->assertSessionHasNoErrors();

    $intern->refresh();
    expect((int) $intern->rendered_hours)->toBe(0)
        ->and($intern->rejected_hours)->toBe(16.0)
        ->and($intern->pending_hours)->toBe(0.0);
});

it('recalculates recorded hours from approved days via artisan command', function () {
    [, $intern] = hoursIntern(rendered: 999);
    hoursClaimedDtr($intern, [now()->subDays(5)->format('Y-m-d')], 'approved');
    hoursClaimedDtr($intern, [now()->subDays(4)->format('Y-m-d')], 'approved');
    hoursClaimedDtr($intern, [now()->subDays(3)->format('Y-m-d')], 'pending');
    hoursClaimedDtr($intern, [now()->subDays(2)->format('Y-m-d')], 'rejected');

    $this->artisan('interns:recalculate-hours')->assertSuccessful();

    // 2 approved days × 8 hrs; pending and rejected contribute nothing.
    expect((int) $intern->fresh()->rendered_hours)->toBe(16);
});

it('leaves legacy interns without structured days untouched', function () {
    [, $legacy] = hoursIntern(rendered: 200);

    $this->artisan('interns:recalculate-hours')->assertSuccessful();

    expect((int) $legacy->fresh()->rendered_hours)->toBe(200);
});
