<?php

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Working-day picker + intern work calendar.
 *
 * The uploaded scan is only evidence for the reviewer; the selected days are
 * the authoritative record and drive the dashboard calendar.
 */
function calDtrType(): DocumentType
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

function calIntern(?string $contractStart = null, ?string $contractEnd = null): array
{
    $user = User::factory()->intern()->create();

    $intern = Intern::create([
        'user_id' => $user->id,
        'required_hours' => 486,
        'rendered_hours' => 0,
        'status' => 'active',
        'contract_start_date' => $contractStart ?? now()->subMonths(2)->toDateString(),
        'contract_end_date' => $contractEnd ?? now()->addMonth()->toDateString(),
    ]);

    return [$user, $intern];
}

/** Create a DTR document row (no upload) with the given claimed days. */
function calClaimedDtr(Intern $intern, array $dates, string $reviewStatus = 'pending', float $hours = 8): InternDocument
{
    $unique = uniqid('dtr_', true).'.pdf';

    $document = InternDocument::withoutGlobalScope('intern')->create([
        'intern_id' => $intern->id,
        'document_type_id' => calDtrType()->id,
        'uploaded_by' => $intern->user_id,
        'file_path' => 'intern-docs/'.$intern->id.'/'.$unique,
        'original_filename' => 'dtr.pdf',
        'system_filename' => $unique,
        'page_count' => 1,
        'file_size_bytes' => 100,
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => $reviewStatus,
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

it('records a different number of hours for each selected day', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $monday = now()->subDays(4)->format('Y-m-d');
    $tuesday = now()->subDays(3)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$monday, $tuesday],
        'day_hours' => [$monday => 8.2, $tuesday => 7.8],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();
    $stored = $document->dtrDays()->get()->keyBy(fn (DtrDay $day) => $day->work_date->format('Y-m-d'));

    expect((float) $stored[$monday]->hours)->toBe(8.2)
        ->and((float) $stored[$tuesday]->hours)->toBe(7.8)
        ->and($intern->fresh()->pending_hours)->toBe(16.0);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), ['decision' => 'approved']);

    // 8.2 + 7.8 = 16.0 credited once approved.
    expect((float) $intern->fresh()->rendered_hours)->toBe(16.0);
});

it('keeps fractional totals accurate when they do not sum to a whole hour', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $first = now()->subDays(4)->format('Y-m-d');
    $second = now()->subDays(3)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$first, $second],
        'day_hours' => [$first => 8.2, $second => 8.2],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->patch(route('intern-documents.review', InternDocument::firstOrFail()), ['decision' => 'approved']);

    expect((float) $intern->fresh()->rendered_hours)->toBe(16.4);
});

it('falls back to the default hours for days without an explicit value', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $withValue = now()->subDays(4)->format('Y-m-d');
    $withoutValue = now()->subDays(3)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$withValue, $withoutValue],
        'hours_per_day' => 6,
        'day_hours' => [$withValue => 9.5],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $stored = InternDocument::firstOrFail()->dtrDays()->get()
        ->keyBy(fn (DtrDay $day) => $day->work_date->format('Y-m-d'));

    expect((float) $stored[$withValue]->hours)->toBe(9.5)
        ->and((float) $stored[$withoutValue]->hours)->toBe(6.0);
});

it('rejects per-day hours above 24 or for a day that was not selected', function () {
    Storage::fake('local');

    [$user] = calIntern();
    $day = now()->subDays(4)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$day],
        'day_hours' => [$day => 25],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('day_hours.'.$day);

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$day],
        'day_hours' => [now()->subDays(9)->format('Y-m-d') => 8],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('day_hours');

    expect(InternDocument::count())->toBe(0);
});

it('stores the picked days and derives hours from them once approved', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $days = [now()->subDays(4)->format('Y-m-d'), now()->subDays(3)->format('Y-m-d'), now()->subDays(2)->format('Y-m-d')];

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => $days,
        'hours_per_day' => 8,
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();
    $admin = User::factory()->admin()->create();

    expect($document->dtrDays()->count())->toBe(3)
        ->and((float) $intern->fresh()->rendered_hours)->toBe(0.0)
        ->and($intern->fresh()->pending_hours)->toBe(24.0)
        ->and($document->dtr_summary)->toContain('3 days')
        ->and($document->document_period)->not->toBeNull();

    $this->actingAs($admin)
        ->patch(route('intern-documents.review', $document), ['decision' => 'approved'])
        ->assertSessionHasNoErrors();

    expect((float) $intern->fresh()->rendered_hours)->toBe(24.0);
});

it('honours a custom hours-per-day value once approved', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [now()->subDay()->format('Y-m-d')],
        'hours_per_day' => 4,
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect((float) $intern->fresh()->rendered_hours)->toBe(0.0);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->patch(route('intern-documents.review', InternDocument::firstOrFail()), ['decision' => 'approved']);

    expect((float) $intern->fresh()->rendered_hours)->toBe(4.0);
    expect((float) DtrDay::firstOrFail()->hours)->toBe(4.0);
});

it('rejects future working days', function () {
    Storage::fake('local');

    [$user] = calIntern();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [now()->addDays(2)->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('days.0');

    expect(InternDocument::count())->toBe(0);
});

it('accepts back-filled days outside the contract window and flags them for review', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern(now()->subDays(5)->toDateString(), now()->addMonth()->toDateString());
    $backfilled = now()->subDays(30)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$backfilled],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();

    expect($document->dtrDays()->count())->toBe(1)
        ->and($document->outside_contract_days_count)->toBe(1);

    // The reviewer is told about it rather than the upload being blocked.
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)
        ->get(route('intern-documents.index'))
        ->assertOk()
        ->assertSee('outside contract');
});

it('blocks a day already claimed by another non-rejected DTR', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $day = now()->subDays(6)->format('Y-m-d');

    calClaimedDtr($intern, [$day], 'pending');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$day],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('days');

    expect(InternDocument::count())->toBe(1);
});

it('lets an intern re-submit days from a rejected DTR', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $day = now()->subDays(7)->format('Y-m-d');

    calClaimedDtr($intern, [$day], 'rejected');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$day],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect(InternDocument::count())->toBe(2);
});

it('renders the work calendar with approved and pending days', function () {
    [$user, $intern] = calIntern();

    $anchor = now()->subMonthsNoOverflow(2)->startOfMonth();
    $approvedDay = $anchor->copy()->addDays(4)->format('Y-m-d');
    $pendingDay = $anchor->copy()->addDays(5)->format('Y-m-d');

    calClaimedDtr($intern, [$approvedDay], 'approved');
    calClaimedDtr($intern, [$pendingDay], 'pending');

    $response = $this->actingAs($user)->get(route('dashboard', ['work_month' => $anchor->format('Y-m')]));

    $response->assertOk();
    $response->assertSee('Work Calendar');
    $response->assertSee('data-status="approved"', false);
    $response->assertSee('data-status="pending"', false);
});

it('does not leak another intern\'s claimed days into the calendar', function () {
    $anchor = now()->subMonthsNoOverflow(2)->startOfMonth();
    $day = $anchor->copy()->addDays(4)->format('Y-m-d');

    [, $otherIntern] = calIntern();
    calClaimedDtr($otherIntern, [$day], 'approved');

    [$user] = calIntern();

    $response = $this->actingAs($user)->get(route('dashboard', ['work_month' => $anchor->format('Y-m')]));

    $response->assertOk();
    $response->assertDontSee('data-status="approved"', false);
});

it('navigates the work calendar by month', function () {
    [$user, $intern] = calIntern();

    $target = now()->subMonthNoOverflow()->startOfMonth();
    calClaimedDtr($intern, [$target->copy()->addDays(3)->format('Y-m-d')], 'approved');

    $response = $this->actingAs($user)->get(route('dashboard', ['work_month' => $target->format('Y-m')]));

    $response->assertOk();
    $response->assertSee($target->format('F Y'));
    $response->assertSee('data-status="approved"', false);
});

it('shows the claimed day summary in the review queue', function () {
    [, $intern] = calIntern();
    calClaimedDtr($intern, [
        now()->subDays(3)->format('Y-m-d'),
        now()->subDays(2)->format('Y-m-d'),
    ], 'pending');

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    $response->assertSee('2 days');
    $response->assertSee('16 hrs');
});

it('exposes the claimed days and their hours to the reviewer day viewer', function () {
    [, $intern] = calIntern();
    $first = now()->subDays(3)->format('Y-m-d');
    $second = now()->subDays(2)->format('Y-m-d');

    calClaimedDtr($intern, [$first], 'pending', 8.5);
    calClaimedDtr($intern, [$second], 'pending', 7.5);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    // Trigger button carries the day payload; shared modal + script are present.
    $response->assertSee('data-dtr-days-view', false);
    $response->assertSee('dtrDaysViewer');
    $response->assertSee($first, false);
    $response->assertSee($second, false);
    // Per-day hours travel with the payload…
    $response->assertSee('8.5', false);
    $response->assertSee('7.5', false);

    // …and the viewer renders them inside each claimed day.
    expect(file_get_contents(public_path('js/dtr-days-viewer.js')))->toContain('dtr-view-hours');
});

it('marks claimed days outside the contract in the day viewer payload', function () {
    [$user, $intern] = calIntern(now()->subDay()->toDateString(), now()->addMonth()->toDateString());

    // One day inside the contract, one back-filled before it started.
    $inside = now()->format('Y-m-d');
    $outside = now()->subDays(20)->format('Y-m-d');
    calClaimedDtr($intern, [$inside, $outside], 'pending');

    $document = InternDocument::firstOrFail();
    $payload = collect($document->dtr_days_payload)->keyBy('date');

    expect($payload[$outside]['outside_contract'])->toBeTrue()
        ->and($payload[$inside]['outside_contract'])->toBeFalse();

    // The intern's own list offers the same viewer.
    $this->actingAs($user)
        ->get(route('my-internship.index'))
        ->assertOk()
        ->assertSee('data-dtr-days-view', false);
});

it('renders the working-day picker on the internship page', function () {
    [$user] = calIntern();
    calDtrType(); // the picker only renders when the DTR type is offered

    $response = $this->actingAs($user)->get(route('my-internship.index'));

    $response->assertOk();
    $response->assertSee('Pick working days');
    $response->assertSee('dtrDaysModal');
    $response->assertSee('js/dtr-picker.js');
    // The scan is explicitly framed as evidence, not the record.
    $response->assertSee('reference evidence');

    // Per-day hours are captured through a prompt opened by clicking a day;
    // there is no bulk "default hours per day" control anymore.
    $response->assertSee('How many hours did you render on this day?');
    $response->assertDontSee('Default hours per day');
    $response->assertDontSee('Apply to all');
});
