<?php

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;

/**
 * Reviewer folder browser: one folder per intern containing their DTRs and
 * 201 files.
 */
function fldType(string $code, string $name, bool $optional = false): DocumentType
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

function fldIntern(string $first = 'Juan', string $last = 'Dela Cruz'): array
{
    $user = User::factory()->intern()->create([
        'first_name' => $first,
        'middle_name' => null,
        'last_name' => $last,
    ]);

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

function fldDocument(Intern $intern, DocumentType $type, string $filename, string $status = 'pending'): InternDocument
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

it('shows a folder per intern with DTR and 201 file counts', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');
    $arType = fldType('AR', 'Accomplishment Report');

    [, $withDocs] = fldIntern('Juan', 'Dela Cruz');
    [, $emptyIntern] = fldIntern('Maria', 'Santos');

    fldDocument($withDocs, $dtrType, 'dtr-1.pdf');
    fldDocument($withDocs, $dtrType, 'dtr-2.pdf');
    fldDocument($withDocs, $arType, 'ar.pdf');

    $response = $this->actingAs($admin)->get(route('intern-documents.folders'));

    $response->assertOk();
    $response->assertSee('Juan Dela Cruz');
    $response->assertSee('Maria Santos');
    $response->assertSee('DTR: 2');
    $response->assertSee('201: 1');
    $response->assertSee('DTR: 0');
});

it('opens a folder showing that intern\'s DTRs and 201 files', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');
    $arType = fldType('AR', 'Accomplishment Report');

    [, $intern] = fldIntern('Juan', 'Dela Cruz');
    fldDocument($intern, $dtrType, 'october-dtr.pdf');
    fldDocument($intern, $arType, 'accomplishment.pdf');

    $response = $this->actingAs($admin)->get(route('intern-documents.folder', $intern));

    $response->assertOk();
    $response->assertSee('Juan Dela Cruz');
    $response->assertSee('Daily Time Records (1)');
    $response->assertSee('201 Files (1)');
    $response->assertSee('october-dtr.pdf');
    $response->assertSee('accomplishment.pdf');
});

it('does not leak another intern\'s documents into a folder', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');

    [, $owner] = fldIntern('Juan', 'Dela Cruz');
    [, $other] = fldIntern('Maria', 'Santos');

    fldDocument($owner, $dtrType, 'owner-dtr.pdf');
    fldDocument($other, $dtrType, 'other-dtr.pdf');

    $response = $this->actingAs($admin)->get(route('intern-documents.folder', $owner));

    $response->assertOk();
    $response->assertSee('owner-dtr.pdf');
    $response->assertDontSee('other-dtr.pdf');
});

it('shows empty states for a folder without documents', function () {
    $admin = User::factory()->admin()->create();
    [, $intern] = fldIntern('Empty', 'Folder');

    $response = $this->actingAs($admin)->get(route('intern-documents.folder', $intern));

    $response->assertOk();
    $response->assertSee('This intern has not submitted any DTRs yet.');
    $response->assertSee('This intern has not submitted any 201 files yet.');
});

it('lets peso managers browse folders but blocks interns', function () {
    $peso = User::factory()->pesoManager()->create();
    fldType('DTR', 'Daily Time Record');
    [, $intern] = fldIntern();

    $this->actingAs($peso)
        ->get(route('intern-documents.folders'))
        ->assertOk();

    $this->actingAs($peso)
        ->get(route('intern-documents.folder', $intern))
        ->assertOk();

    $internUser = User::factory()->intern()->create();

    $this->actingAs($internUser)
        ->get(route('intern-documents.folders'))
        ->assertForbidden();
});

it('links to the folder browser from the review queue', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('intern-documents.index'));

    $response->assertOk();
    $response->assertSee('Browse folders');
    $response->assertSee(route('intern-documents.folders'));
});

it('searches folders by intern name', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');

    [, $juan] = fldIntern('Juan', 'Dela Cruz');
    fldIntern('Maria', 'Santos');
    fldDocument($juan, $dtrType, 'juan.pdf');

    $response = $this->actingAs($admin)->get(route('intern-documents.folders', ['q' => 'maria']));

    $response->assertOk();
    $response->assertSee('Maria Santos');
    $response->assertDontSee('Juan Dela Cruz');
    $response->assertSee('1 folder');
    $response->assertSee('name="q"', false);
});

it('searches folders by partner agency', function () {
    $admin = User::factory()->admin()->create();

    $agency = Company::create(['name' => 'Sunrise Trading Corp', 'code' => 'STC']);

    [, $intern] = fldIntern('Juan', 'Dela Cruz');
    $intern->update(['partner_agency_id' => $agency->id]);

    fldIntern('Maria', 'Santos');

    $response = $this->actingAs($admin)->get(route('intern-documents.folders', ['q' => 'Sunrise']));

    $response->assertOk();
    $response->assertSee('Juan Dela Cruz');
    $response->assertDontSee('Maria Santos');
});

it('filters folders that still have pending documents', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');

    [, $pendingIntern] = fldIntern('Juan', 'Dela Cruz');
    [, $clearedIntern] = fldIntern('Maria', 'Santos');

    fldDocument($pendingIntern, $dtrType, 'pending.pdf', 'pending');
    fldDocument($clearedIntern, $dtrType, 'approved.pdf', 'approved');

    $response = $this->actingAs($admin)->get(route('intern-documents.folders', ['filter' => 'pending']));

    $response->assertOk();
    $response->assertSee('Juan Dela Cruz');
    $response->assertDontSee('Maria Santos');
    $response->assertSee('with pending documents');
});

it('filters folders with no documents yet', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');

    [, $withDocs] = fldIntern('Juan', 'Dela Cruz');
    fldIntern('Maria', 'Santos');

    fldDocument($withDocs, $dtrType, 'doc.pdf');

    $response = $this->actingAs($admin)->get(route('intern-documents.folders', ['filter' => 'none']));

    $response->assertOk();
    $response->assertSee('Maria Santos');
    $response->assertDontSee('Juan Dela Cruz');
    $response->assertSee('with no documents yet');
});

it('searches the review queue by intern name and file name', function () {
    $admin = User::factory()->admin()->create();
    $dtrType = fldType('DTR', 'Daily Time Record');
    $arType = fldType('AR', 'Accomplishment Report');

    [, $juan] = fldIntern('Juan', 'Dela Cruz');
    [, $maria] = fldIntern('Maria', 'Santos');

    fldDocument($juan, $dtrType, 'juan-time-record.pdf');
    fldDocument($maria, $arType, 'accomplishment-report.pdf');

    $byName = $this->actingAs($admin)->get(route('intern-documents.index', ['q' => 'maria']));
    $byName->assertOk();
    $byName->assertSee('accomplishment-report.pdf');
    $byName->assertDontSee('juan-time-record.pdf');

    $byFile = $this->actingAs($admin)->get(route('intern-documents.index', ['q' => 'time-record']));
    $byFile->assertOk();
    $byFile->assertSee('juan-time-record.pdf');
    $byFile->assertDontSee('accomplishment-report.pdf');
});
