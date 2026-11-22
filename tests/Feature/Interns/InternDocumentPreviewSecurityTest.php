<?php

use App\Models\DocumentType;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| KAI-01 — stored XSS through the intern-document preview
|--------------------------------------------------------------------------
| Uploads are validated by the *detected* content type (not just the client
| extension) and previews may only render an inline server-side allowlist.
| A file whose stored MIME type is active/scriptable (e.g. text/html) must be
| served as an opaque download, never executed on the application origin.
*/

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

/**
 * A real upload whose MIME type is detected from its content. This matters:
 * UploadedFile::fake() reports a MIME type derived from the filename, so it
 * would not exercise the content-based validation.
 */
function previewSecurityUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'gips-preview');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}

/**
 * @return array{0: User, 1: Intern, 2: DocumentType}
 */
function previewSecurityIntern(): array
{
    $user = User::factory()->intern()->create();
    $intern = apiCreateIntern($user);
    $type = apiInternDocumentType('AR');

    return [$user, $intern, $type];
}

function previewSecurityDocument(
    User $user,
    Intern $intern,
    DocumentType $type,
    string $content,
    string $storedMimeType,
    string $filename,
    string $reviewStatus = InternDocument::STATUS_PENDING_PESO,
): InternDocument {
    $path = "intern-docs/{$intern->id}/{$filename}";
    Storage::disk('local')->put($path, $content);

    return InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $user->id,
        'file_path' => $path,
        'original_filename' => $filename,
        'system_filename' => $filename,
        'page_count' => 1,
        'file_size_bytes' => strlen($content),
        'mime_type' => $storedMimeType,
        'status' => 'active',
        'review_status' => $reviewStatus,
    ]);
}

const PREVIEW_SECURITY_HTML = '<html><body><script>alert(document.domain)</script></body></html>';

it('rejects an HTML payload renamed to .pdf at upload', function () {
    [$user, , $type] = previewSecurityIntern();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => previewSecurityUpload('audited.pdf', PREVIEW_SECURITY_HTML),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('forces a text/html document to download as an opaque attachment', function () {
    [$user, $intern, $type] = previewSecurityIntern();
    $document = previewSecurityDocument($user, $intern, $type, PREVIEW_SECURITY_HTML, 'text/html', 'legacy.html');

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$document->id}/preview");

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Disposition'))->not->toContain('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'none'")
        ->toContain('sandbox')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin');
});

it('forces the same opaque attachment for the Blade preview route', function () {
    [$user, $intern, $type] = previewSecurityIntern();
    $document = previewSecurityDocument($user, $intern, $type, PREVIEW_SECURITY_HTML, 'text/html', 'legacy.html');

    $response = $this->actingAs($user)
        ->get("/intern-documents/{$document->id}/preview");

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('keeps allowlisted PDF previews inline', function () {
    [$user, $intern, $type] = previewSecurityIntern();
    $document = previewSecurityDocument($user, $intern, $type, '%PDF-1.4 synthetic', 'application/pdf', 'report.pdf');

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$document->id}/preview");

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('rejects an HTML payload renamed to .pdf on resubmission', function () {
    [$user, $intern, $type] = previewSecurityIntern();
    $document = previewSecurityDocument(
        $user,
        $intern,
        $type,
        '%PDF-1.4 original',
        'application/pdf',
        'original.pdf',
        InternDocument::STATUS_RETURNED_TO_INTERN,
    );

    $this->actingAs($user)
        ->post("/api/v1/intern-documents/{$document->id}/resubmit", [
            'file' => previewSecurityUpload('audited.pdf', PREVIEW_SECURITY_HTML),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect($document->fresh()->file_path)->toBe("intern-docs/{$intern->id}/original.pdf")
        ->and($document->fresh()->mime_type)->toBe('application/pdf');
});
