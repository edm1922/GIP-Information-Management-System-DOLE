<?php

/*
|--------------------------------------------------------------------------
| WP-4 Security Audit — unauthenticated access matrix
|--------------------------------------------------------------------------
| Additive security probes. No application code is modified by this file.
|
| Purpose: prove that every protected API endpoint answers 401 to an
| anonymous caller, and that only the intentionally public endpoints
| (health check + shareable registration link) answer without a session.
*/

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->withHeader('Origin', 'http://localhost')
        ->withHeader('Accept', 'application/json');
});

it('answers 401 to anonymous callers on every protected verb+endpoint', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    // Session / identity
    ['GET', '/api/v1/auth/user'],
    ['POST', '/api/v1/auth/logout'],
    ['PUT', '/api/v1/auth/password'],
    ['GET', '/api/v1/auth/force-password-change'],
    ['POST', '/api/v1/auth/force-password-change'],
    ['GET', '/api/v1/dashboard'],
    ['GET', '/api/v1/profile'],
    ['PATCH', '/api/v1/profile'],
    ['DELETE', '/api/v1/profile'],
    ['GET', '/api/v1/notifications'],
    ['POST', '/api/v1/notifications/mark-all-read'],
    ['POST', '/api/v1/notifications/1/read'],

    // Interns
    ['GET', '/api/v1/interns'],
    ['GET', '/api/v1/interns/agencies'],
    ['GET', '/api/v1/interns/1'],
    ['POST', '/api/v1/interns'],
    ['PUT', '/api/v1/interns/1'],
    ['DELETE', '/api/v1/interns/1'],
    ['PATCH', '/api/v1/interns/1/restore'],
    ['DELETE', '/api/v1/interns/1/force-delete'],

    // Registration link / applications
    ['GET', '/api/v1/intern-registration-link'],
    ['POST', '/api/v1/intern-registration-link'],
    ['DELETE', '/api/v1/intern-registration-link'],
    ['GET', '/api/v1/intern-applications'],
    ['GET', '/api/v1/intern-applications/1'],
    ['POST', '/api/v1/intern-applications/1/approve'],
    ['POST', '/api/v1/intern-applications/1/reject'],
    ['POST', '/api/v1/intern-applications/1/credential-email'],

    // Documents
    ['GET', '/api/v1/intern-documents'],
    ['POST', '/api/v1/intern-documents'],
    ['GET', '/api/v1/intern-documents/folders'],
    ['GET', '/api/v1/intern-documents/folders/1'],
    ['GET', '/api/v1/intern-documents/1/preview'],
    ['GET', '/api/v1/intern-documents/1/download'],
    ['GET', '/api/v1/intern-documents/1/history'],
    ['PATCH', '/api/v1/intern-documents/1/hours'],
    ['POST', '/api/v1/intern-documents/1/peso-approve'],
    ['POST', '/api/v1/intern-documents/1/peso-return'],
    ['POST', '/api/v1/intern-documents/1/admin-approve'],
    ['POST', '/api/v1/intern-documents/1/admin-return'],
    ['POST', '/api/v1/intern-documents/1/resubmit'],
    ['PATCH', '/api/v1/intern-documents/1/review'],

    // Self-service
    ['GET', '/api/v1/my-internship'],
    ['GET', '/api/v1/my-internship/201-files'],

    // Settings
    ['GET', '/api/v1/settings/companies'],
    ['POST', '/api/v1/settings/companies'],
    ['GET', '/api/v1/settings/companies/1'],
    ['PUT', '/api/v1/settings/companies/1'],
    ['DELETE', '/api/v1/settings/companies/1'],
    ['PATCH', '/api/v1/settings/companies/1/toggle-active'],
    ['GET', '/api/v1/settings/document-types'],
    ['POST', '/api/v1/settings/document-types'],
    ['PUT', '/api/v1/settings/document-types/1'],
    ['DELETE', '/api/v1/settings/document-types/1'],
    ['GET', '/api/v1/settings/users'],
    ['POST', '/api/v1/settings/users'],
    ['GET', '/api/v1/settings/users/1'],
    ['PUT', '/api/v1/settings/users/1'],
    ['DELETE', '/api/v1/settings/users/1'],
    ['POST', '/api/v1/settings/users/1/reset-password'],
    ['PATCH', '/api/v1/settings/users/1/toggle-status'],

    // Reports
    ['GET', '/api/v1/reports/audit-log'],
    ['GET', '/api/v1/reports/intern'],
    ['GET', '/api/v1/reports/export/interns'],
    ['GET', '/api/v1/reports/export/intern-hours'],
    ['GET', '/api/v1/reports/export/intern-agencies'],
    ['GET', '/api/v1/reports/export/intern-expiry'],
]);

it('keeps only the health check and registration landing page public', function () {
    // Intentional public endpoints.
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    // Unknown public token: 404, not a redirect/login page or 500.
    $this->getJson('/api/v1/public/registration/'.str_repeat('a', 64))
        ->assertNotFound()
        ->assertJsonPath('message', 'This registration link is not valid.');
});

it('renders JSON (never a login redirect) for anonymous API errors', function () {
    $this->withHeader('Accept', 'text/html')
        ->get('/api/v1/settings/users')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');

    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound();
});

it('redirects anonymous browser visitors away from the Blade dashboard', function () {
    $this->withHeader('Accept', 'text/html')
        ->get('/dashboard')
        ->assertRedirect('/login');
});
