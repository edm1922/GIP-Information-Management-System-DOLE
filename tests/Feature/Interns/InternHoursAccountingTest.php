<?php

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Hours accounting under the sequential workflow: interns no longer submit
 * hours; an Admin assigns them during final review, and only final approval
 * credits them to the intern.
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

it('credits nothing until an Admin assigns hours and finally approves', function () {
    Storage::fake('local');

    [$user, $intern] = hoursIntern();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => hoursDtrType()->id,
        'days' => [now()->subDays(2)->format('Y-m-d'), now()->subDay()->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    expect((int) $intern->fresh()->rendered_hours)->toBe(0)
        ->and($intern->fresh()->pending_hours)->toBe(0.0);

    $this->actingAs($peso)->post(route('intern-documents.peso-approve', $document))->assertSessionHasNoErrors();

    $dates = $document->dtrDays()->pluck('work_date')->map(fn ($date) => $date->format('Y-m-d'))->all();

    $this->actingAs($admin)->patch(route('intern-documents.hours', $document), [
        'day_hours' => [$dates[0] => 8, $dates[1] => 8],
    ])->assertSessionHasNoErrors();

    expect($intern->fresh()->pending_hours)->toBe(16.0)
        ->and((int) $intern->fresh()->rendered_hours)->toBe(0);

    $this->actingAs($admin)->post(route('intern-documents.admin-approve', $document))->assertSessionHasNoErrors();

    expect((int) $intern->fresh()->rendered_hours)->toBe(16);
});

it('does not credit hours while a returned document waits for corrections', function () {
    Storage::fake('local');

    [$user, $intern] = hoursIntern();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => hoursDtrType()->id,
        'days' => [now()->subDays(4)->format('Y-m-d'), now()->subDays(3)->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();
    $this->actingAs($peso)->post(route('intern-documents.peso-approve', $document));

    $dates = $document->dtrDays()->pluck('work_date')->map(fn ($date) => $date->format('Y-m-d'))->all();
    $this->actingAs($admin)->patch(route('intern-documents.hours', $document), [
        'day_hours' => [$dates[0] => 8, $dates[1] => 8],
    ]);

    $this->actingAs($admin)->post(route('intern-documents.admin-return', $document), [
        'remarks' => 'The scan does not match the selected days.',
    ])->assertSessionHasNoErrors();

    $intern->refresh();

    expect((int) $intern->rendered_hours)->toBe(0)
        ->and((float) $intern->rejected_hours)->toBe(16.0) // returned documents are not credited
        ->and($document->fresh()->review_status)->toBe(InternDocument::STATUS_RETURNED_TO_PESO);
});

it('recalculates recorded hours from approved days via artisan command', function () {
    [, $intern] = hoursIntern(rendered: 999);
    hoursClaimedDtr($intern, [now()->subDays(5)->format('Y-m-d')], 'approved');
    hoursClaimedDtr($intern, [now()->subDays(4)->format('Y-m-d')], 'approved');
    hoursClaimedDtr($intern, [now()->subDays(3)->format('Y-m-d')], 'pending_peso');
    hoursClaimedDtr($intern, [now()->subDays(2)->format('Y-m-d')], 'returned_to_intern');

    $this->artisan('interns:recalculate-hours')->assertSuccessful();

    expect((int) $intern->fresh()->rendered_hours)->toBe(16);
});

it('leaves legacy interns without structured days untouched', function () {
    [, $legacy] = hoursIntern(rendered: 200);

    $this->artisan('interns:recalculate-hours')->assertSuccessful();

    expect((int) $legacy->fresh()->rendered_hours)->toBe(200);
});
