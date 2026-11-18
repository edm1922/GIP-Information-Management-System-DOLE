<?php

use App\Models\DocumentType;
use App\Models\DtrDay;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Working-day picker + intern work calendar under the sequential workflow.
 * Interns choose dates only; Admins assign the hours later.
 */
function calDtrType(): DocumentType
{
    return DocumentType::firstOrCreate(
        ['code' => 'DTR'],
        ['department_id' => null, 'name' => 'Daily Time Record', 'has_expiry' => false, 'is_intern_document' => true, 'is_optional' => false]
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

function calClaimedDtr(Intern $intern, array $dates, string $reviewStatus = 'pending_peso', ?float $hours = null): InternDocument
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

it('stores selected days without hours and shows no hours summary', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $monday = now()->subDays(4)->format('Y-m-d');
    $tuesday = now()->subDays(3)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$monday, $tuesday],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();

    expect($document->dtrDays()->whereNotNull('hours')->count())->toBe(0)
        ->and($intern->fresh()->pending_hours)->toBe(0.0)
        ->and($document->dtr_summary)->toContain('2 days')
        ->and($document->dtr_summary)->not->toContain('hrs')
        ->and($document->document_period)->not->toBeNull();
});

it('credits the admin-assigned fractional hours after final approval', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $first = now()->subDays(4)->format('Y-m-d');
    $second = now()->subDays(3)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$first, $second],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($peso)->post(route('intern-documents.peso-approve', $document));
    $this->actingAs($admin)->patch(route('intern-documents.hours', $document), [
        'day_hours' => [$first => 8.2, $second => 8.2],
    ])->assertSessionHasNoErrors();

    expect($intern->fresh()->pending_hours)->toBe(16.4)
        ->and((float) $intern->fresh()->rendered_hours)->toBe(0.0);

    $this->actingAs($admin)->post(route('intern-documents.admin-approve', $document))->assertSessionHasNoErrors();

    expect((float) $intern->fresh()->rendered_hours)->toBe(16.4);
});

it('rejects future days and days already claimed by another DTR', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [now()->addDays(2)->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('days.0');

    $day = now()->subDays(6)->format('Y-m-d');
    calClaimedDtr($intern, [$day], 'pending_peso');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$day],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasErrors('days');

    expect(InternDocument::count())->toBe(1);
});

it('accepts back-filled days outside the contract window and flags them', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern(now()->subDays(5)->toDateString(), now()->addMonth()->toDateString());
    $backfilled = now()->subDays(30)->format('Y-m-d');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$backfilled],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = InternDocument::firstOrFail();
    expect($document->outside_contract_days_count)->toBe(1);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('intern-documents.index'))
        ->assertOk()
        ->assertSee('outside contract');
});

it('lets an intern re-submit dates from a returned document through resubmission', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $day = now()->subDays(7)->format('Y-m-d');
    $document = calClaimedDtr($intern, [$day], 'returned_to_intern');

    $this->actingAs($user)->post(route('intern-documents.resubmit', $document), [
        'days' => [$day],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect($document->fresh()->review_status)->toBe(InternDocument::STATUS_PENDING_PESO);
});

it('renders approved and in-flight days on the work calendar', function () {
    [$user, $intern] = calIntern();

    $anchor = now()->subMonthsNoOverflow(2)->startOfMonth();
    $approvedDay = $anchor->copy()->addDays(4)->format('Y-m-d');
    $pendingDay = $anchor->copy()->addDays(5)->format('Y-m-d');

    calClaimedDtr($intern, [$approvedDay], 'approved', 8);
    calClaimedDtr($intern, [$pendingDay], 'pending_peso', 8);

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
    calClaimedDtr($otherIntern, [$day], 'approved', 8);

    [$user] = calIntern();

    $this->actingAs($user)
        ->get(route('dashboard', ['work_month' => $anchor->format('Y-m')]))
        ->assertOk()
        ->assertDontSee('data-status="approved"', false);
});

it('navigates the work calendar by month', function () {
    [$user, $intern] = calIntern();

    $target = now()->subMonthNoOverflow()->startOfMonth();
    calClaimedDtr($intern, [$target->copy()->addDays(3)->format('Y-m-d')], 'approved', 8);

    $this->actingAs($user)
        ->get(route('dashboard', ['work_month' => $target->format('Y-m')]))
        ->assertOk()
        ->assertSee($target->format('F Y'))
        ->assertSee('data-status="approved"', false);
});

it('shows the claimed day summary in the review queue without inventing hours', function () {
    [, $intern] = calIntern();
    calClaimedDtr($intern, [now()->subDays(3)->format('Y-m-d'), now()->subDays(2)->format('Y-m-d')], 'pending_peso');

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    $response->assertSee('2 days');
    $response->assertDontSee('16 hrs');
});

it('exposes the claimed days and their assigned hours to the reviewer day viewer', function () {
    [, $intern] = calIntern();
    $first = now()->subDays(3)->format('Y-m-d');
    $second = now()->subDays(2)->format('Y-m-d');

    calClaimedDtr($intern, [$first], 'pending_admin', 8.5);
    calClaimedDtr($intern, [$second], 'pending_admin', 7.5);

    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    $response->assertSee('data-dtr-days-view', false);
    $response->assertSee('dtrDaysViewer');
    $response->assertSee($first, false);
    $response->assertSee($second, false);
    $response->assertSee('8.5', false);
    $response->assertSee('7.5', false);

    expect(file_get_contents(public_path('js/dtr-days-viewer.js')))->toContain('dtr-view-hours');
});

it('renders the days-only picker on the internship page', function () {
    [$user] = calIntern();
    calDtrType();

    $response = $this->actingAs($user)->get(route('my-internship.index'));

    $response->assertOk();
    $response->assertSee('Pick working days');
    $response->assertSee('dtrDaysModal');
    $response->assertSee('js/dtr-picker.js');
    $response->assertSee('reference evidence');
    $response->assertDontSee('How many hours did you render on this day?');
    $response->assertDontSee('Default hours per day');
    $response->assertDontSee('Apply to all');
});

it('unlocks the days of a returned DTR so the intern can correct them', function () {
    Storage::fake('local');

    [$user, $intern] = calIntern();
    $day = now()->subDays(8)->format('Y-m-d');
    calClaimedDtr($intern, [$day], 'returned_to_intern');

    // The returned document's dates are not advertised as taken...
    $this->actingAs($user)
        ->get(route('my-internship.index'))
        ->assertOk()
        ->assertDontSee($day.'\\"', false);

    // ...and the intern may submit those dates again on a corrected/new DTR.
    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => calDtrType()->id,
        'days' => [$day],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect(InternDocument::count())->toBe(2);
});