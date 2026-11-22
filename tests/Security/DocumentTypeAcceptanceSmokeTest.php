<?php

/*
|--------------------------------------------------------------------------
| SEC-01a — real-file acceptance matrix for every allowed document type
|--------------------------------------------------------------------------
| Kai's content-sniffing change is the main regression risk: a genuine
| Office/image/PDF upload must still be accepted and stored with its
| canonical MIME type. These tests generate REAL files (GD images,
| PhpSpreadsheet XLS/XLSX, a minimal OOXML DOCX package) — never
| UploadedFile::fake(), whose MIME type comes from the filename.
|
| Legacy .doc (Word 97-2003 OLE) cannot be generated on this host (no Word /
| LibreOffice, no sample .doc on disk), so the OLE branch is exercised the
| other way round: a genuine OLE container renamed to .doc must be rejected
| because its content does not match the claimed extension.
*/

use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

/** Build a real file on disk and wrap it as an upload with content-detected MIME. */
function matrixUpload(string $name, string $path): UploadedFile
{
    return new UploadedFile($path, $name, null, null, true);
}

function matrixTempPath(string $name): string
{
    // Per-process root so two concurrent test runs cannot share (and unlink)
    // each other's generated files.
    $dir = sys_get_temp_dir().'/gips-matrix-'.getmypid();
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir.'/'.$name;
}

function matrixPdfPath(): string
{
    $path = matrixTempPath('doc.pdf');
    file_put_contents(
        $path,
        "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n".
        "2 0 obj\n<< /Type /Pages /Kids [ ] /Count 0 >>\nendobj\n".
        "trailer\n<< /Root 1 0 R >>\n%%EOF\n"
    );

    return $path;
}

function matrixImagePath(string $format): string
{
    $path = matrixTempPath('doc.'.$format);
    $image = imagecreatetruecolor(16, 16);
    imagefilledrectangle($image, 0, 0, 15, 15, imagecolorallocate($image, 40, 90, 200));

    match ($format) {
        'jpg', 'jpeg' => imagejpeg($image, $path),
        'png' => imagepng($image, $path),
    };

    imagedestroy($image);

    return $path;
}

function matrixCsvPath(): string
{
    $path = matrixTempPath('doc.csv');
    file_put_contents($path, "name,amount\nalpha,1\nbeta,2\n");

    return $path;
}

function matrixSpreadsheetPath(string $format): string
{
    $path = matrixTempPath('doc.'.$format);
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getActiveSheet()->setCellValue('A1', 'hours');
    $spreadsheet->getActiveSheet()->setCellValue('B1', 8);

    if ($format === 'xlsx') {
        (new Xlsx($spreadsheet))->save($path);
    } else {
        (new Xls($spreadsheet))->save($path);
    }

    $spreadsheet->disconnectWorksheets();

    return $path;
}

function matrixDocxPath(): string
{
    $path = matrixTempPath('doc.docx');
    @unlink($path);

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .'</Types>'
    );
    $zip->addFromString(
        '_rels/.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        .'</Relationships>'
    );
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body><w:p><w:r><w:t>DTR evidence</w:t></w:r></w:p></w:body></w:document>'
    );
    $zip->close();

    return $path;
}

/** @return array{0: User, 1: \App\Models\Intern, 2: \App\Models\DocumentType} */
function matrixIntern(): array
{
    $user = User::factory()->intern()->create();
    $intern = apiCreateIntern($user);
    $type = apiInternDocumentType('AR');

    return [$user, $intern, $type];
}

it('accepts real files of every generatable allowed type and stores the canonical MIME', function (string $filename, callable $factory, string $expectedMime, string $expectedFinfo) {
    [$user, $intern, $type] = matrixIntern();
    $path = $factory();

    // Record the detected type independently of the app for the report.
    $finfo = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    expect($finfo)->toBe($expectedFinfo);

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => matrixUpload($filename, $path),
        ])
        ->assertCreated();

    $document = InternDocument::query()->firstOrFail();

    expect($document->mime_type)->toBe($expectedMime)
        ->and((int) $document->intern_id)->toBe((int) $intern->id);

    Storage::disk('local')->assertExists($document->file_path);

    @unlink($path);
})->with([
    'pdf' => ['evidence.pdf', fn () => matrixPdfPath(), 'application/pdf', 'application/pdf'],
    'jpg' => ['photo.jpg', fn () => matrixImagePath('jpg'), 'image/jpeg', 'image/jpeg'],
    'jpeg' => ['photo.jpeg', fn () => matrixImagePath('jpeg'), 'image/jpeg', 'image/jpeg'],
    'png' => ['photo.png', fn () => matrixImagePath('png'), 'image/png', 'image/png'],
    'csv' => ['summary.csv', fn () => matrixCsvPath(), 'text/csv', 'text/csv'],
    'xlsx' => ['sheet.xlsx', fn () => matrixSpreadsheetPath('xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'xls' => ['sheet.xls', fn () => matrixSpreadsheetPath('xls'), 'application/vnd.ms-excel', 'application/vnd.ms-excel'],
    'docx' => ['report.docx', fn () => matrixDocxPath(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
]);

it('rejects an OLE container whose content does not match the claimed .doc extension', function () {
    // A genuine OLE file (the XLS writer output) renamed to .doc: the container
    // signature is valid but finfo reports application/vnd.ms-excel, which is
    // not a Word type, so the upload must be rejected.
    [$user, , $type] = matrixIntern();
    $path = matrixSpreadsheetPath('xls');

    expect((new finfo(FILEINFO_MIME_TYPE))->file($path))->toBe('application/vnd.ms-excel');

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => matrixUpload('legacy.doc', $path),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0);

    @unlink($path);
});

it('still serves generated images inline and spreadsheets as attachments', function () {
    [$user, , $type] = matrixIntern();

    $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => matrixUpload('photo.png', matrixImagePath('png')),
    ])->assertCreated();

    $png = InternDocument::query()->firstOrFail();

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$png->id}/preview")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('image/png')
        ->and($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');

    $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => matrixUpload('sheet.xlsx', matrixSpreadsheetPath('xlsx')),
    ])->assertCreated();

    $xlsx = InternDocument::query()->latest('id')->firstOrFail();

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$xlsx->id}/preview")
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('content-type'))->not->toContain('spreadsheetml')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});
