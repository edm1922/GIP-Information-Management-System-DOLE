<?php

/*
|--------------------------------------------------------------------------
| Round-1 backend hardening — session invalidation + download headers
|--------------------------------------------------------------------------
| Covers the remaining WP-R1 fixes:
|   SEC-07  admin password reset kills the target's live sessions
|   SEC-01b download() carries nosniff + CSP and stays an attachment
|   SEC-01b a DB row whose stored file is gone answers 404, not 500
|
| The session tests override SESSION_DRIVER to the database driver and use
| the sqlite `sessions` table, so real other-device rows can be seeded and
| observed (the default array driver cannot model them).
*/

use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

function hardeningUseDatabaseSessions(): void
{
    config([
        'session.driver' => 'database',
        'session.connection' => null,
        'session.table' => 'sessions',
    ]);
    app()->forgetInstance('session');
    app()->forgetInstance('session.store');
}

function hardeningSeedSession(string $id, int $userId): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'seeded-device',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

function hardeningPdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n".
        "2 0 obj\n<< /Type /Pages /Kids [ ] /Count 0 >>\nendobj\n".
        "trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function hardeningRealUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'gips-hardening');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

it('SEC-07 an API admin password reset deletes the target sessions and keeps the admin session', function () {
    hardeningUseDatabaseSessions();

    $admin = User::factory()->admin()->create(['password' => 'password']);
    $target = User::factory()->pesoManager()->create(['password' => 'password']);

    hardeningSeedSession('target-existing-session', $target->id);
    hardeningSeedSession('admin-existing-session', $admin->id);

    $this->actingAs($admin)
        ->postJson("/api/v1/settings/users/{$target->id}/reset-password")
        ->assertOk()
        ->assertJsonStructure(['message', 'default_password']);

    expect(DB::table('sessions')->where('id', 'target-existing-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'admin-existing-session')->exists())->toBeTrue();
});

it('SEC-07 a web admin password reset deletes the target sessions and keeps the admin session', function () {
    hardeningUseDatabaseSessions();

    $admin = User::factory()->admin()->create(['password' => 'password']);
    $target = User::factory()->pesoManager()->create(['password' => 'password']);

    hardeningSeedSession('target-web-session', $target->id);
    hardeningSeedSession('admin-web-session', $admin->id);

    $this->actingAs($admin)
        ->post(route('settings.users.reset-password', $target))
        ->assertRedirect(route('settings.users.index'));

    expect(DB::table('sessions')->where('id', 'target-web-session')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'admin-web-session')->exists())->toBeTrue();
});

it('SEC-01b download carries nosniff and CSP as an attachment', function () {
    $user = User::factory()->intern()->create();
    $intern = apiCreateIntern($user);
    $type = apiInternDocumentType('AR');

    $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => hardeningRealUpload('genuine.pdf', hardeningPdf()),
    ])->assertCreated();

    $document = InternDocument::query()->firstOrFail();

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$document->id}/download")
        ->assertOk();

    expect($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('content-security-policy'))->toContain("default-src 'none'")
        ->and($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('content-type'))->toContain('application/pdf');

    // The Blade route uses the same hardening.
    $web = $this->actingAs($user)
        ->get("/intern-documents/{$document->id}/download")
        ->assertOk();

    expect($web->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($web->headers->get('content-security-policy'))->toContain("default-src 'none'");
});

it('SEC-01b a missing stored file answers 404, not 500', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);
    $type = apiInternDocumentType('AR');

    $document = InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $internUser->id,
        'file_path' => 'intern-docs/'.$intern->id.'/missing-file.pdf',
        'original_filename' => 'missing-file.pdf',
        'system_filename' => 'INT-'.$intern->id.'_AR_20260101000000.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => InternDocument::STATUS_PENDING_PESO,
    ]);

    // Storage::fake('local') is empty, so the row points at a file that does not exist.
    Storage::disk('local')->assertMissing($document->file_path);

    $this->actingAs($admin)
        ->get("/api/v1/intern-documents/{$document->id}/download")
        ->assertNotFound();

    $this->actingAs($admin)
        ->get("/api/v1/intern-documents/{$document->id}/preview")
        ->assertNotFound();
});
