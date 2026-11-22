<?php

/*
|--------------------------------------------------------------------------
| WP-4 Security Audit — upload validation
|--------------------------------------------------------------------------
| Additive security probes. No application code is modified by this file.
|
| Synthetic files only (a few bytes of text/HTML). Nothing destructive.
*/

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

function securityUploader(): array
{
    $user = User::factory()->intern()->create();
    $intern = apiCreateIntern($user);
    $type = apiInternDocumentType('AR'); // non-DTR keeps the probe to the file rules

    return [$user, $intern, $type];
}

/**
 * A real UploadedFile whose MIME type is detected from its content (unlike
 * Illuminate\Http\Testing\File, which reports the MIME type from the name).
 */
function securityRealUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'sec');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

it('rejects disallowed upload extensions', function (string $filename) {
    [$user, , $type] = securityUploader();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create($filename, 8, 'application/octet-stream'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0);
})->with([
    'php' => ['payload.php'],
    'phtml' => ['payload.phtml'],
    'exe' => ['payload.exe'],
    'html' => ['payload.html'],
    'svg' => ['payload.svg'],
    'js' => ['payload.js'],
    'no-extension' => ['payload'],
]);

it('rejects an upload larger than the 10 MB limit', function () {
    [$user, , $type] = securityUploader();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0);
});

it('keeps a path-traversal filename from escaping the intern directory', function (string $filename) {
    [$user, $intern, $type] = securityUploader();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create($filename, 8, 'application/pdf'),
        ])
        ->assertCreated();

    $document = InternDocument::query()->firstOrFail();

    expect(str_contains($document->file_path, '..'))->toBeFalse()
        ->and($document->file_path)->toStartWith("intern-docs/{$intern->id}/");

    Storage::disk('local')->assertExists($document->file_path);

    // The stored file must live under the intern's own directory on disk.
    $files = Storage::disk('local')->allFiles();
    foreach ($files as $path) {
        expect(str_contains($path, '..'))->toBeFalse();
    }
})->with([
    'dot-dot' => ['../../evil.pdf'],
    'windows-dot-dot' => ['..\\..\\evil.pdf'],
    'absolute' => ['C:/Windows/evil.pdf'],
]);

it('SEC-01a rejects an HTML payload renamed to .pdf', function () {
    // The stored MIME type is what preview trusts, so content must be validated
    // at upload time: an allowed extension does not prove safe content.
    [$user, $intern, $type] = securityUploader();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => securityRealUpload(
                'audited.pdf',
                '<html><body><script>alert(document.domain)</script></body></html>'
            ),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0)
        ->and($intern->fresh()->rendered_hours)->toBe(0.0);

    // No stored file may carry the rejected payload. (Other tests in this file
    // share the fake disk, so the assertion is scoped to the payload content.)
    foreach (Storage::disk('local')->allFiles() as $path) {
        expect(Storage::disk('local')->get($path))->not->toContain('alert(');
    }
});

it('rejects a double extension whose last segment is a script extension', function () {
    [$user, , $type] = securityUploader();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => UploadedFile::fake()->create('invoice.pdf.php', 8, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0);
});

it('SEC-01a rejects a double extension ending in an allowed extension when content mismatches', function () {
    // "payload.php.pdf" has an allowed final extension; content validation must
    // still reject it when the bytes are not a real PDF.
    [$user, $intern, $type] = securityUploader();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => securityRealUpload('payload.php.pdf', '<html><script>alert(1)</script></html>'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0)
        ->and($intern->fresh()->rendered_hours)->toBe(0.0);

    // No stored file may carry the rejected payload. (Other tests in this file
    // share the fake disk, so the assertion is scoped to the payload content.)
    foreach (Storage::disk('local')->allFiles() as $path) {
        expect(Storage::disk('local')->get($path))->not->toContain('alert(');
    }
});

it('blocks an upload that targets another intern through intern_id', function () {
    [$owner, , $type] = securityUploader();
    $intruder = User::factory()->intern()->create();
    apiCreateIntern($intruder);

    $ownerInternId = $owner->intern->id;

    $this->actingAs($intruder)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'intern_id' => $ownerInternId,
            'file' => UploadedFile::fake()->create('probe.pdf', 8, 'application/pdf'),
        ])
        ->assertCreated();

    $document = InternDocument::query()->firstOrFail();

    // The upload lands on the intruder's own intern record, never the target's.
    expect((int) $document->intern_id)->toBe((int) $intruder->intern->id)
        ->and((int) $document->intern_id)->not->toBe((int) $ownerInternId)
        ->and((int) $document->uploaded_by)->toBe($intruder->id);
});
