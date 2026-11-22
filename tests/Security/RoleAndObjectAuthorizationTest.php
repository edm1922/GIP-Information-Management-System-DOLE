<?php

/*
|--------------------------------------------------------------------------
| WP-4 Security Audit — role enforcement and object-level authorization
|--------------------------------------------------------------------------
| Additive security probes. No application code is modified by this file.
|
| Every probe uses synthetic records created inside the test transaction.
*/

use App\Models\InternApplication;
use App\Models\InternDocument;
use App\Models\InternRegistrationLink;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

/** Build a document row directly (same shape the upload service writes). */
function securityDoc(int $internId, int $ownerId, int $typeId, string $status = InternDocument::STATUS_PENDING_PESO): InternDocument
{
    return InternDocument::create([
        'intern_id' => $internId,
        'document_type_id' => $typeId,
        'uploaded_by' => $ownerId,
        'file_path' => "intern-docs/{$internId}/evidence.pdf",
        'original_filename' => 'evidence.pdf',
        'system_filename' => 'INT-'.$internId.'_DTR_20260101000000.pdf',
        'page_count' => 1,
        'file_size_bytes' => 1024,
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => $status,
    ]);
}

it('enforces role boundaries on every privileged route family', function (string $role, string $method, string $uri, int $expected) {
    // Seed a model-bound target so the probe measures the role gate (403), not
    // a missing-record 404 from route binding.
    apiCreateIntern(User::factory()->intern()->create());

    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'peso' => User::factory()->pesoManager()->create(),
        default => User::factory()->intern()->create(),
    };

    if ($role === 'intern') {
        apiCreateIntern($user);
    }

    $this->actingAs($user)->json($method, $uri)->assertStatus($expected);
})->with([
    // Interns: no access to staff surfaces.
    ['intern', 'GET', '/api/v1/settings/users', 403],
    ['intern', 'GET', '/api/v1/settings/companies', 403],
    ['intern', 'GET', '/api/v1/reports/audit-log', 403],
    ['intern', 'GET', '/api/v1/reports/intern', 403],
    ['intern', 'GET', '/api/v1/reports/export/interns', 403],
    ['intern', 'POST', '/api/v1/interns', 403],
    ['intern', 'GET', '/api/v1/interns', 403],
    ['intern', 'GET', '/api/v1/intern-documents/folders', 403],
    ['intern', 'GET', '/api/v1/intern-registration-link', 403],
    ['intern', 'POST', '/api/v1/intern-registration-link', 403],

    // PESO managers: review access, but no admin settings.
    ['peso', 'GET', '/api/v1/settings/users', 403],
    ['peso', 'POST', '/api/v1/settings/users', 403],
    ['peso', 'POST', '/api/v1/interns', 403],
    ['peso', 'DELETE', '/api/v1/interns/1', 403],
    ['peso', 'GET', '/api/v1/reports/audit-log', 403],
    ['peso', 'GET', '/api/v1/interns', 200],
    ['peso', 'GET', '/api/v1/reports/intern', 200],

    // Admins: no PESO-only stage, no intern self-service.
    ['admin', 'GET', '/api/v1/settings/users', 200],
    ['admin', 'GET', '/api/v1/interns', 200],
    ['admin', 'GET', '/api/v1/my-internship', 403],
]);

it('stops an intern from reading another intern record (IDOR, intern record)', function () {
    $owner = User::factory()->intern()->create();
    $ownerIntern = apiCreateIntern($owner);
    $intruder = User::factory()->intern()->create();
    apiCreateIntern($intruder);

    $this->actingAs($intruder)
        ->getJson("/api/v1/interns/{$ownerIntern->id}")
        ->assertForbidden();

    $this->actingAs($owner)
        ->getJson("/api/v1/interns/{$ownerIntern->id}")
        ->assertOk();
});

it('stops an intern from reading, previewing, downloading or resubmitting another intern document (IDOR)', function () {
    $type = apiInternDocumentType('DTR');
    $owner = User::factory()->intern()->create();
    $ownerIntern = apiCreateIntern($owner);
    $intruder = User::factory()->intern()->create();
    apiCreateIntern($intruder);

    $doc = securityDoc($ownerIntern->id, $owner->id, $type->id, InternDocument::STATUS_RETURNED_TO_INTERN);

    foreach ([
        ['GET', "/api/v1/intern-documents/{$doc->id}/history"],
        ['GET', "/api/v1/intern-documents/{$doc->id}/preview"],
        ['GET', "/api/v1/intern-documents/{$doc->id}/download"],
        ['POST', "/api/v1/intern-documents/{$doc->id}/resubmit"],
        ['PATCH', "/api/v1/intern-documents/{$doc->id}/hours"],
        ['POST', "/api/v1/intern-documents/{$doc->id}/admin-approve"],
        ['POST', "/api/v1/intern-documents/{$doc->id}/peso-approve"],
    ] as [$method, $uri]) {
        $this->actingAs($intruder)->json($method, $uri)->assertForbidden();
    }

    // The owner can see their own returned document.
    $this->actingAs($owner)
        ->getJson("/api/v1/intern-documents/{$doc->id}/history")
        ->assertOk();
});

it('stops an intern from marking another user notification as read (IDOR, notification)', function () {
    $owner = User::factory()->intern()->create();
    $intruder = User::factory()->intern()->create();

    $notification = $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\InternDocumentSubmitted',
        'data' => ['url' => '/review'],
        'read_at' => null,
    ]);

    $this->actingAs($intruder)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('hides a pending application from admins until the PESO stage endorses it', function () {
    $peso = User::factory()->pesoManager()->create();
    $admin = User::factory()->admin()->create();
    $link = InternRegistrationLink::create([
        'token' => Str::random(64),
        'created_by' => $peso->id,
        'expires_at' => now()->addDay(),
    ]);

    $application = InternApplication::create([
        'reference' => 'GIMS-APL-2026-ABCDEF',
        'registration_link_id' => $link->id,
        'first_name' => 'Test',
        'last_name' => 'Applicant',
        'email' => 'applicant@example.test',
        'status' => 'pending',
    ]);

    $this->actingAs($peso)
        ->getJson("/api/v1/intern-applications/{$application->id}")
        ->assertOk();

    $this->actingAs($admin)
        ->getJson("/api/v1/intern-applications/{$application->id}")
        ->assertForbidden();

    // Interns see no applications at all.
    $this->actingAs(User::factory()->intern()->create())
        ->getJson('/api/v1/intern-applications')
        ->assertForbidden();
});
