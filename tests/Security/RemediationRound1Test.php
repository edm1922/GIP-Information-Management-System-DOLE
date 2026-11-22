<?php

/*
|--------------------------------------------------------------------------
| Remediation Round 1 — red-first security regression suite
|--------------------------------------------------------------------------
| Encodes the post-fix contract in
| gips-security-audit/REMEDIATION-ROUND-1.md (WP-R3). These tests are meant
| to FAIL against the pre-fix tree and PASS once WP-R1 (Kai) and WP-R2
| (Mira) land.
|
| SEC-01a/01b upload + preview · SEC-02 credentials · SEC-05 policy
| SEC-06 deactivation · SEC-07 credential change · SEC-28 old() escaping
| SEC-26 notification URL scheme
*/

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\InternDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
    Storage::fake('local');
});

/** Real upload whose MIME type is detected from content (not the filename). */
function remediationUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'gips-rem');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function remediationPdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n".
        "2 0 obj\n<< /Type /Pages /Kids [ ] /Count 0 >>\nendobj\n".
        "trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/** @return array{0: User, 1: \App\Models\Intern, 2: DocumentType} */
function remediationIntern(): array
{
    $user = User::factory()->intern()->create();
    $intern = apiCreateIntern($user);
    $type = apiInternDocumentType('AR');

    return [$user, $intern, $type];
}

function remediationDocument(int $internId, int $ownerId, int $typeId, string $storedMime, string $filename = 'legacy.pdf'): InternDocument
{
    return InternDocument::create([
        'intern_id' => $internId,
        'document_type_id' => $typeId,
        'uploaded_by' => $ownerId,
        'file_path' => "intern-docs/{$internId}/{$filename}",
        'original_filename' => $filename,
        'system_filename' => 'INT-'.$internId.'_AR_20260101000000.pdf',
        'mime_type' => $storedMime,
        'status' => 'active',
        'review_status' => InternDocument::STATUS_PENDING_PESO,
    ]);
}

// ---------------------------------------------------------------------------
// SEC-01a — uploads are validated on detected content, not the extension
// ---------------------------------------------------------------------------

it('SEC-01a rejects HTML content renamed to .pdf with no row and no stored file', function () {
    [$user, , $type] = remediationIntern();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => remediationUpload('audited.pdf', '<html><body><script>alert(document.domain)</script></body></html>'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('SEC-01a rejects a .php.pdf double extension with mismatched content', function () {
    [$user, , $type] = remediationIntern();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => remediationUpload('evidence.php.pdf', '<html><script>alert(1)</script></html>'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(InternDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('SEC-01a still accepts a genuine PDF', function () {
    [$user, $intern, $type] = remediationIntern();

    $this->actingAs($user)
        ->post('/api/v1/intern-documents', [
            'document_type_id' => $type->id,
            'file' => remediationUpload('genuine.pdf', remediationPdf()),
        ])
        ->assertCreated();

    $document = InternDocument::query()->firstOrFail();
    expect((int) $document->intern_id)->toBe((int) $intern->id)
        ->and($document->mime_type)->toBe('application/pdf');
    Storage::disk('local')->assertExists($document->file_path);
});

it('SEC-01a rejects a resubmitted HTML file renamed to .pdf', function () {
    [$user, $intern, $type] = remediationIntern();
    $document = remediationDocument($intern->id, $user->id, $type->id, 'application/pdf');
    $document->forceFill(['review_status' => InternDocument::STATUS_RETURNED_TO_INTERN])->save();
    Storage::disk('local')->put($document->file_path, remediationPdf());

    $this->actingAs($user)
        ->post("/api/v1/intern-documents/{$document->id}/resubmit", [
            'file' => remediationUpload('corrected.pdf', '<html><script>alert(1)</script></html>'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

// ---------------------------------------------------------------------------
// SEC-01b — preview never renders active content inline
// ---------------------------------------------------------------------------

it('SEC-01b serves a genuine PDF inline with nosniff', function () {
    [$user, , $type] = remediationIntern();

    $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => remediationUpload('genuine.pdf', remediationPdf()),
    ])->assertCreated();

    $document = InternDocument::query()->firstOrFail();

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$document->id}/preview")
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('SEC-01b serves a non-inlineable document type as an attachment', function () {
    [$user, , $type] = remediationIntern();

    $this->actingAs($user)->post('/api/v1/intern-documents', [
        'document_type_id' => $type->id,
        'file' => remediationUpload('summary.csv', "name,amount\nalpha,1\n"),
    ])->assertCreated();

    $document = InternDocument::query()->firstOrFail();

    $response = $this->actingAs($user)
        ->get("/api/v1/intern-documents/{$document->id}/preview")
        ->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('content-type'))->not->toContain('text/csv')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('SEC-01b never renders a legacy text/html row inline', function () {
    $admin = User::factory()->admin()->create();
    [$internUser, $intern, $type] = remediationIntern();
    $document = remediationDocument($intern->id, $internUser->id, $type->id, 'text/html', 'legacy.html');
    Storage::disk('local')->put($document->file_path, '<html><script>alert(1)</script></html>');

    $response = $this->actingAs($admin)
        ->get("/api/v1/intern-documents/{$document->id}/preview")
        ->assertOk();

    expect($response->headers->get('content-type'))->not->toContain('text/html')
        ->and($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

// ---------------------------------------------------------------------------
// SEC-02 — created/reset credentials are random, never derived from names
// ---------------------------------------------------------------------------

it('SEC-02 creates staff with a random, non-derivable password and must_change_password', function () {
    $admin = User::factory()->admin()->create();
    $derived = 'santos'.config('brand.password_suffix');

    $response = $this->actingAs($admin)->postJson('/api/v1/settings/users', [
        'first_name' => 'Coverage',
        'last_name' => 'Santos',
        'username' => 'santos-cover',
        'email' => 'santos.cover@example.test',
        'role' => 'peso_manager',
        'is_active' => true,
    ])->assertCreated();

    $user = User::query()->where('username', 'santos-cover')->firstOrFail();

    expect($user->must_change_password)->toBeTrue()
        ->and(Hash::check($derived, $user->password))->toBeFalse();

    $returned = $response->json('default_password');
    if ($returned !== null) {
        expect($returned)->not->toBe($derived)
            ->and(str_contains(strtolower($returned), 'santos'))->toBeFalse()
            ->and(strlen($returned))->toBeGreaterThanOrEqual(12)
            ->and(Hash::check($returned, $user->password))->toBeTrue();

        // The one-time password must actually authenticate; the forced change
        // then blocks the business surface.
        $this->actingAs($admin)->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'username' => 'santos-cover',
            'password' => $returned,
        ])->assertOk();

        $this->getJson('/api/v1/dashboard')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }
});

it('SEC-02 creates interns with a random password not derived from the last name', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson('/api/v1/interns', [
        'first_name' => 'Maria',
        'last_name' => 'Dela Cruz',
        'email' => 'maria.derived@example.test',
        'username' => 'maria-derived',
        'required_hours' => 486,
        'status' => 'active',
    ])->assertCreated();

    $internUser = User::query()->where('username', 'maria-derived')->firstOrFail();

    expect($internUser->must_change_password)->toBeTrue()
        ->and(Hash::check('dela cruzgip', $internUser->password))->toBeFalse()
        ->and(Hash::check('delacruzgip', $internUser->password))->toBeFalse()
        ->and(Hash::check('dela cruz'.config('brand.password_suffix'), $internUser->password))->toBeFalse();
});

// ---------------------------------------------------------------------------
// SEC-05 — password policy min 12, mixed case, numbers, symbols
// ---------------------------------------------------------------------------

it('SEC-05 rejects a 5-character password on self-service', function () {
    $user = User::factory()->admin()->create(['password' => 'password']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'abcde',
            'password_confirmation' => 'abcde',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('SEC-05 rejects a 5-character password on forced change', function () {
    $flagged = User::factory()->intern()->create(['must_change_password' => true]);

    $this->actingAs($flagged)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => 'abcde',
            'password_confirmation' => 'abcde',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect($flagged->fresh()->must_change_password)->toBeTrue();
});

it('SEC-05 accepts a 12+ mixed-case/number/symbol password on self-service', function () {
    $strong = 'Str0ng!Passw0rd';

    $user = User::factory()->admin()->create(['password' => 'password']);
    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => $strong,
            'password_confirmation' => $strong,
        ])
        ->assertOk();

    expect(Hash::check($strong, $user->fresh()->password))->toBeTrue();
});

it('SEC-05 accepts a 12+ mixed-case/number/symbol password on forced change', function () {
    $strong = 'Str0ng!Passw0rd';

    $flagged = User::factory()->intern()->create(['must_change_password' => true]);
    $this->actingAs($flagged)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => $strong,
            'password_confirmation' => $strong,
        ])
        ->assertOk();

    expect($flagged->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check($strong, $flagged->fresh()->password))->toBeTrue();
});

// ---------------------------------------------------------------------------
// SEC-06 — deactivated accounts lose live sessions
// ---------------------------------------------------------------------------

it('SEC-06 answers 403 to a deactivated account holding a live session', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    $this->actingAs($admin)
        ->getJson('/api/v1/dashboard')
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');

    $this->actingAs($admin)
        ->getJson('/api/v1/settings/users')
        ->assertForbidden();

    // Login must keep refusing inactive accounts too.
    $this->app['auth']->guard('web')->logout();

    $this->postJson('/api/v1/auth/login', [
        'username' => $admin->username,
        'password' => 'password',
    ])->assertStatus(422)->assertJsonValidationErrors('username');
});

// ---------------------------------------------------------------------------
// SEC-07 — current_password required only when no forced change is pending
// ---------------------------------------------------------------------------

it('SEC-07 requires current_password when must_change_password is false', function () {
    $user = User::factory()->admin()->create([
        'password' => 'password',
        'must_change_password' => false,
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'current_password' => 'password',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertOk();

    expect(Hash::check('Str0ng!Passw0rd', $user->fresh()->password))->toBeTrue();
});

it('SEC-07 does not require current_password while a forced change is pending', function () {
    $user = User::factory()->intern()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/force-password-change', [
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertOk();

    expect($user->fresh()->must_change_password)->toBeFalse();
});

// ---------------------------------------------------------------------------
// SEC-28 — old() inside inline JS is escaped/inert in the three edit forms
// ---------------------------------------------------------------------------

it('SEC-28 does not break out of the script block on the users page', function () {
    $admin = User::factory()->admin()->create(['username' => 'sec28-admin']);
    $target = User::factory()->admin()->create(['username' => 'sec28-target']);
    $payload = '</script><img src=x onerror=alert(1)>';

    $this->withHeader('Accept', 'text/html')
        ->actingAs($admin)
        ->from('/settings/users')
        ->post("/settings/users/{$target->id}", [
            '_method' => 'PUT',
            'id' => $target->id,
            'first_name' => $payload,
            'middle_name' => null,
            'last_name' => 'Owning',
            'suffix' => null,
            'username' => 'sec28-admin',
            'email' => $target->email,
            'role' => 'admin',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('username');

    $html = $this->actingAs($admin)->get('/settings/users')->assertOk()->getContent();

    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('</script><img');
});

it('SEC-28 does not break out of the script block on the companies page', function () {
    $admin = User::factory()->admin()->create();
    $first = Company::create(['name' => 'First Co', 'code' => 'SEC2801']);
    Company::create(['name' => 'Second Co', 'code' => 'SEC2802']);
    $payload = '</script><img src=x onerror=alert(2)>';

    $this->withHeader('Accept', 'text/html')
        ->actingAs($admin)
        ->from('/settings/companies')
        ->post("/settings/companies/{$first->id}", [
            '_method' => 'PUT',
            'id' => $first->id,
            'name' => $payload,
            'code' => 'SEC2802',
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('code');

    $html = $this->actingAs($admin)->get('/settings/companies')->assertOk()->getContent();

    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('</script><img');
});

it('SEC-28 does not break out of the script block on the document-types page', function () {
    $admin = User::factory()->admin()->create();
    $first = DocumentType::create([
        'name' => 'First Type', 'code' => 'SEC28A',
        'has_expiry' => false, 'is_intern_document' => false, 'is_optional' => false,
    ]);
    DocumentType::create([
        'name' => 'Second Type', 'code' => 'SEC28B',
        'has_expiry' => false, 'is_intern_document' => false, 'is_optional' => false,
    ]);
    $payload = '</script><img src=x onerror=alert(3)>';

    $this->withHeader('Accept', 'text/html')
        ->actingAs($admin)
        ->from('/settings/document-types')
        ->post("/settings/document-types/{$first->id}", [
            '_method' => 'PUT',
            'id' => $first->id,
            'name' => $payload,
            'code' => 'SEC28B',
            'has_expiry' => 0,
        ])
        ->assertSessionHasErrors('code');

    $html = $this->actingAs($admin)->get('/settings/document-types')->assertOk()->getContent();

    expect($html)->not->toContain($payload)
        ->and($html)->not->toContain('</script><img');
});

// ---------------------------------------------------------------------------
// SEC-26 — notification javascript: URL is not rendered as an anchor
// ---------------------------------------------------------------------------

it('SEC-26 does not render a javascript: notification url as an anchor', function () {
    $user = User::factory()->admin()->create();

    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\InternDocumentSubmitted',
        'data' => [
            'message' => 'Scheme probe',
            'url' => 'javascript:alert(document.domain)',
        ],
        'read_at' => null,
    ]);

    $html = $this->actingAs($user)->get('/notifications')->assertOk()->getContent();

    // The layout legitimately uses href="javascript:void(0)" in the sidebar, so
    // the assertion targets the injected payload specifically.
    expect($html)->not->toContain('javascript:alert(document.domain)')
        ->and($html)->not->toContain('href="javascript:alert');
});

// ---------------------------------------------------------------------------
// SEC-07 — cross-session invalidation (real two-session check)
// ---------------------------------------------------------------------------

it('SEC-07 deletes the other session row on password change and keeps the current one', function () {
    // Production runs the database session driver; the suite pins
    // SESSION_DRIVER=array. Point this test at the real sqlite `sessions`
    // table so an actual other-device row can be seeded and observed.
    config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
    $this->app->forgetInstance('session');
    $this->app->forgetInstance('session.store');

    $user = User::factory()->admin()->create(['password' => 'password']);

    DB::table('sessions')->insert([
        'id' => 'other-device-session',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => 'other-device',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'Str0ng!Passw0rd',
            'password_confirmation' => 'Str0ng!Passw0rd',
        ])
        ->assertOk();

    $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id');

    // The other device's row is gone...
    expect($remaining)->not->toContain('other-device-session');
    // ...while the session that performed the change is still present.
    expect($remaining)->toHaveCount(1)
        ->and($remaining->first())->not->toBe('other-device-session');
});
