<?php

use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * The intern self-service area is split into two tabs:
 *  - Daily Time Record (route name my-internship.index)
 *  - 201 Files (route name my-internship.files)
 */
function tabType(string $code, string $name, bool $optional = false): DocumentType
{
    return DocumentType::firstOrCreate(
        ['code' => $code],
        [
            'department_id' => null,
            'name' => $name,
            'has_expiry' => false,
            'is_intern_document' => true,
            'is_optional' => $optional,
        ]
    );
}

function tabIntern(): array
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

function tabDocument(Intern $intern, DocumentType $type, string $filename, string $status = 'pending_peso'): InternDocument
{
    return InternDocument::withoutGlobalScope('intern')->create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $intern->user_id,
        'file_path' => 'intern-docs/'.$intern->id.'/'.$filename,
        'original_filename' => $filename,
        'system_filename' => $filename,
        'page_count' => 1,
        'file_size_bytes' => 100,
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => $status,
    ]);
}

it('offers only the DTR type on the Daily Time Record tab', function () {
    [$user] = tabIntern();
    tabType('DTR', 'Daily Time Record');
    tabType('AR', 'Accomplishment Report');
    tabType('CERT', 'Certificate', true);

    $response = $this->actingAs($user)->get(route('my-internship.index'));

    $response->assertOk();
    $response->assertSee('Hours Rendered');
    $response->assertSee('Submit a Daily Time Record');

    $codes = $response->viewData('documentTypes')->pluck('code')->all();
    expect($codes)->toBe(['DTR']);
});

it('offers only non-DTR types on the 201 Files tab', function () {
    [$user] = tabIntern();
    tabType('DTR', 'Daily Time Record');
    tabType('AR', 'Accomplishment Report');
    tabType('CERT', 'Certificate', true);

    $response = $this->actingAs($user)->get(route('my-internship.files'));

    $response->assertOk();
    $response->assertSee('Requirements');
    // The DTR day-picker must not ship on this tab.
    $response->assertDontSee('Pick working days');
    $response->assertDontSee('dtrDaysModal');

    $codes = $response->viewData('documentTypes')->pluck('code')->sort()->values()->all();
    expect($codes)->toBe(['AR', 'CERT']);
});

it('lists only the matching documents on each tab', function () {
    [$user, $intern] = tabIntern();
    $dtrType = tabType('DTR', 'Daily Time Record');
    $arType = tabType('AR', 'Accomplishment Report');

    tabDocument($intern, $dtrType, 'october-dtr.pdf');
    tabDocument($intern, $arType, 'accomplishment-report.pdf');

    $dtrTab = $this->actingAs($user)->get(route('my-internship.index'));
    $dtrTab->assertOk();
    $dtrTab->assertSee('october-dtr.pdf');
    $dtrTab->assertDontSee('accomplishment-report.pdf');

    $filesTab = $this->actingAs($user)->get(route('my-internship.files'));
    $filesTab->assertOk();
    $filesTab->assertSee('accomplishment-report.pdf');
    $filesTab->assertDontSee('october-dtr.pdf');
});

it('redirects each upload to the tab of that document type', function () {
    [$user, $intern] = tabIntern();
    $dtrType = tabType('DTR', 'Daily Time Record');
    $arType = tabType('AR', 'Accomplishment Report');

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $dtrType->id,
        'days' => [now()->subDays(2)->format('Y-m-d')],
        'file' => UploadedFile::fake()->create('dtr.pdf', 40, 'application/pdf'),
    ])->assertRedirect(route('my-internship.index'));

    $this->actingAs($user)->post(route('intern-documents.store'), [
        'document_type_id' => $arType->id,
        'file' => UploadedFile::fake()->create('ar.pdf', 40, 'application/pdf'),
    ])->assertRedirect(route('my-internship.files'));
});

it('deep-links review notifications to the matching tab', function () {
    [, $intern] = tabIntern();
    $admin = User::factory()->admin()->create();
    $peso = User::factory()->pesoManager()->create();
    $arType = tabType('AR', 'Accomplishment Report');

    $document = tabDocument($intern, $arType, 'ar.pdf');

    apiApproveInternDocumentThroughWorkflow($document, $admin, $peso);

    $notification = $intern->user->notifications()->latest()->first();
    expect($notification->data['url'])->toBe('/my-internship/201-files');
});

it('shows requirement statuses on the 201 Files tab', function () {
    [$user, $intern] = tabIntern();
    tabType('AR', 'Accomplishment Report');
    tabType('CERT', 'Certificate', true);

    $ar = tabType('AR', 'Accomplishment Report');
    tabDocument($intern, $ar, 'ar.pdf', 'pending_peso');

    $response = $this->actingAs($user)->get(route('my-internship.files'));

    $response->assertOk();
    $response->assertSee('Submitted — awaiting review');
    $response->assertSee('optional');
});

it('shows both tabs in the intern sidebar', function () {
    [$user] = tabIntern();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('Daily Time Record');
    $response->assertSee('201 Files');
    $response->assertSee(route('my-internship.files'));
});

it('shows the unassigned notice on both tabs', function () {
    $user = User::factory()->intern()->create();

    $this->actingAs($user)
        ->get(route('my-internship.index'))
        ->assertOk()
        ->assertSee('No Internship Record Yet');

    $this->actingAs($user)
        ->get(route('my-internship.files'))
        ->assertOk()
        ->assertSee('No Internship Record Yet');
});
