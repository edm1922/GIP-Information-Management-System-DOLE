<?php

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\InternDocument;
use App\Models\User;

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('never serializes private storage internals for documents', function () {
    $admin = User::factory()->admin()->create();
    $intern = apiCreateIntern(User::factory()->intern()->create());
    $type = apiInternDocumentType('AR');

    InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $admin->id,
        'file_path' => 'intern-docs/'.$intern->id.'/secret.pdf',
        'original_filename' => 'visible-name.pdf',
        'system_filename' => 'generated-internal-name.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/intern-documents')
        ->assertOk()
        ->assertJsonStructure([
            'documents' => [
                'data' => [[
                    'id', 'intern_id', 'document_type_id', 'document_type',
                    'original_filename', 'review_status', 'dtr_days',
                    'preview_url', 'download_url', 'created_at',
                ]],
                'links',
                'meta',
            ],
        ])
        ->assertJsonPath('documents.data.0.original_filename', 'visible-name.pdf')
        ->assertJsonPath('documents.data.0.document_type.code', 'AR')
        ->assertJsonMissingPath('documents.data.0.file_path')
        ->assertJsonMissingPath('documents.data.0.system_filename')
        ->assertJsonPath(
            'documents.data.0.preview_url',
            route('api.v1.intern-documents.preview', InternDocument::firstOrFail()->id)
        );
});

it('returns only whitelisted user fields on auth endpoints', function () {
    $user = User::factory()->admin()->create(['username' => 'contract-admin']);

    $response = $this->actingAs($user)->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonStructure([
            'user' => [
                'id', 'username', 'email', 'first_name', 'middle_name', 'last_name', 'suffix',
                'name', 'role', 'is_active', 'must_change_password', 'last_active_at',
                'created_at', 'updated_at',
            ],
        ])
        ->assertJsonPath('user.username', 'contract-admin');

    $response->assertJsonMissingPath('user.password');
    $response->assertJsonMissingPath('user.remember_token');
    $response->assertJsonMissingPath('user.email_verified_at');
});

it('exposes the computed intern figures the Blade views render', function () {
    $admin = User::factory()->admin()->create();
    $agency = Company::create(['name' => 'Contract Agency', 'code' => 'CONTRACT']);
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);
    $intern->update([
        'partner_agency_id' => $agency->id,
        'contract_start_date' => '2026-01-05',
        'contract_end_date' => '2026-06-30',
        'required_hours' => 100,
        'rendered_hours' => 50,
        'status' => 'active',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/v1/interns')
        ->assertOk()
        ->assertJsonPath('interns.data.0.id', $intern->id)
        ->assertJsonPath('interns.data.0.progress_percent', 50)
        ->assertJsonPath('interns.data.0.hours_remaining', 50)
        ->assertJsonPath('interns.data.0.contract_start_date', '2026-01-05')
        ->assertJsonPath('interns.data.0.partner_agency.code', 'CONTRACT')
        ->assertJsonPath('interns.data.0.user.username', $internUser->username)
        ->assertJsonStructure(['interns' => ['data', 'links', 'meta'], 'agencies', 'show_archived']);
});

it('returns the day-level DTR breakdown for a single intern folder', function () {
    $admin = User::factory()->admin()->create();
    $internUser = User::factory()->intern()->create();
    $intern = apiCreateIntern($internUser);
    $type = apiInternDocumentType('DTR');

    $document = InternDocument::create([
        'intern_id' => $intern->id,
        'document_type_id' => $type->id,
        'uploaded_by' => $internUser->id,
        'file_path' => 'intern-docs/'.$intern->id.'/dtr.pdf',
        'original_filename' => 'dtr.pdf',
        'system_filename' => 'dtr-internal.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'active',
        'review_status' => 'approved',
    ]);

    $document->dtrDays()->createMany([
        ['intern_id' => $intern->id, 'work_date' => '2026-02-02', 'hours' => 8],
        ['intern_id' => $intern->id, 'work_date' => '2026-02-03', 'hours' => 8],
    ]);

    $this->actingAs($admin)
        ->getJson("/api/v1/intern-documents/folders/{$intern->id}")
        ->assertOk()
        ->assertJsonCount(1, 'dtrs')
        ->assertJsonCount(0, 'files')
        ->assertJsonPath('dtrs.0.dtr_summary', 'Feb 02, 2026 – Feb 03, 2026 · 2 days · 16 hrs')
        ->assertJsonPath('dtrs.0.dtr_days_payload.0.date', '2026-02-02')
        ->assertJsonPath('dtrs.0.dtr_days_payload.0.hours', 8)
        ->assertJsonPath('dtrs.0.dtr_days.0.work_date', '2026-02-02')
        ->assertJsonMissingPath('dtrs.0.file_path')
        ->assertJsonMissingPath('dtrs.0.system_filename');
});

it('serializes notifications as read-only payloads with raw route hints', function () {
    $user = User::factory()->admin()->create();

    $user->notifications()->create([
        'id' => '00000000-0000-4000-8000-000000000001',
        'type' => 'App\\Notifications\\TestNotification',
        'data' => [
            'message' => 'Hello',
            'url_route' => 'interns.show',
            'url_params' => ['intern' => 5],
        ],
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'type', 'data', 'read_at', 'created_at']], 'links', 'meta'])
        ->assertJsonPath('data.0.data.url_route', 'interns.show')
        ->assertJsonPath('data.0.data.url_params.intern', 5)
        ->assertJsonPath('data.0.read_at', null);
});

it('throttles API traffic at the configured limit', function () {
    $limited = false;

    foreach (range(1, 121) as $attempt) {
        $response = $this->getJson('/api/v1/health');

        if ($response->status() === 429) {
            $limited = true;
            break;
        }
    }

    expect($limited)->toBeTrue();
});
