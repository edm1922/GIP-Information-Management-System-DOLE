<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\InternController;
use App\Http\Controllers\Api\V1\InternApplicationController;
use App\Http\Controllers\Api\V1\InternDocumentController;
use App\Http\Controllers\Api\V1\InternRegistrationLinkController;
use App\Http\Controllers\Api\V1\MyInternshipController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicInternRegistrationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Phase 1: versioned API for the Next.js frontend (Sanctum SPA).
| Existing Blade/web routes remain operational during migration.
| Authorization mirrors existing web routes/policies where applied.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:120,1')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    })->name('health');

    // Public intern self-registration (no auth). Strict per-IP limits guard the
    // unauthenticated endpoints; the token is validated in the controller.
    Route::prefix('public')->name('public.')->group(function () {
        Route::get('/registration/{token}', [PublicInternRegistrationController::class, 'show'])
            ->middleware('throttle:public-registration-meta')
            ->name('registration.show');
        Route::post('/registration/{token}/applications', [PublicInternRegistrationController::class, 'store'])
            ->middleware('throttle:public-registration')
            ->name('registration.applications.store');
    });

    Route::middleware('guest')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login'])
            ->name('auth.login');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
        Route::get('/auth/user', [AuthController::class, 'user'])
            ->name('auth.user');
        Route::get('/auth/force-password-change', [AuthController::class, 'showForcePasswordChange'])
            ->name('auth.force-password-change.show');
        Route::post('/auth/force-password-change', [AuthController::class, 'storeForcePasswordChange'])
            ->name('auth.force-password-change.store');
        Route::put('/auth/password', [AuthController::class, 'updatePassword'])
            ->name('auth.password.update');

        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->name('dashboard');

        Route::get('/profile', [ProfileController::class, 'show'])
            ->name('profile.show');
        Route::patch('/profile', [ProfileController::class, 'update'])
            ->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])
            ->name('profile.destroy');

        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.mark-all-read');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.mark-as-read');

        // Interns
        Route::get('/interns', [InternController::class, 'index'])
            ->middleware('can:viewAny,App\Models\Intern')
            ->name('interns.index');
        Route::get('/interns/agencies', [InternController::class, 'agencies'])
            ->middleware('can:viewAny,App\Models\Intern')
            ->name('interns.agencies');
        Route::post('/interns', [InternController::class, 'store'])
            ->middleware('role:admin')
            ->name('interns.store');
        Route::get('/interns/{intern}', [InternController::class, 'show'])
            ->middleware('can:view,intern')
            ->name('interns.show');
        Route::put('/interns/{intern}', [InternController::class, 'update'])
            ->middleware('role:admin')
            ->name('interns.update');
        Route::delete('/interns/{intern}', [InternController::class, 'destroy'])
            ->middleware('role:admin')
            ->name('interns.destroy');
        Route::patch('/interns/{id}/restore', [InternController::class, 'restore'])
            ->middleware('role:admin')
            ->name('interns.restore');
        Route::delete('/interns/{id}/force-delete', [InternController::class, 'forceDelete'])
            ->middleware('role:admin')
            ->name('interns.forceDelete');

        // Intern self-registration: shared link + application review
        Route::middleware('role:admin,peso_manager')->group(function () {
            Route::get('/intern-registration-link', [InternRegistrationLinkController::class, 'index'])
                ->middleware('can:viewAny,App\Models\InternRegistrationLink')
                ->name('intern-registration-link.index');
            Route::post('/intern-registration-link', [InternRegistrationLinkController::class, 'store'])
                ->middleware('can:manage,App\Models\InternRegistrationLink')
                ->name('intern-registration-link.store');
            Route::delete('/intern-registration-link', [InternRegistrationLinkController::class, 'destroy'])
                ->middleware('can:manage,App\Models\InternRegistrationLink')
                ->name('intern-registration-link.destroy');

            Route::get('/intern-applications', [InternApplicationController::class, 'index'])
                ->middleware('can:viewAny,App\Models\InternApplication')
                ->name('intern-applications.index');
            Route::get('/intern-applications/{application}', [InternApplicationController::class, 'show'])
                ->middleware('can:view,application')
                ->name('intern-applications.show');
            Route::post('/intern-applications/{application}/approve', [InternApplicationController::class, 'approve'])
                ->name('intern-applications.approve');
            Route::post('/intern-applications/{application}/reject', [InternApplicationController::class, 'reject'])
                ->middleware('can:reject,application')
                ->name('intern-applications.reject');
            Route::post('/intern-applications/{application}/credential-email', [InternApplicationController::class, 'resendCredentials'])
                ->middleware('can:resendCredentials,application')
                ->name('intern-applications.credential-email');
        });

        // Intern documents
        Route::get('/intern-documents', [InternDocumentController::class, 'index'])
            ->middleware('can:viewAny,App\Models\InternDocument')
            ->name('intern-documents.index');
        Route::post('/intern-documents', [InternDocumentController::class, 'store'])
            ->name('intern-documents.store');
        Route::get('/intern-documents/folders', [InternDocumentController::class, 'folders'])
            ->middleware('role:admin,peso_manager')
            ->name('intern-documents.folders');
        Route::get('/intern-documents/folders/{intern}', [InternDocumentController::class, 'internFolder'])
            ->middleware('role:admin,peso_manager')
            ->name('intern-documents.folder');
        Route::get('/intern-documents/{document}/preview', [InternDocumentController::class, 'preview'])
            ->middleware('can:download,document')
            ->withTrashed()
            ->name('intern-documents.preview');
        Route::get('/intern-documents/{document}/download', [InternDocumentController::class, 'download'])
            ->middleware('can:download,document')
            ->withTrashed()
            ->name('intern-documents.download');
        Route::get('/intern-documents/{document}/history', [InternDocumentController::class, 'history'])
            ->middleware('can:view,document')
            ->name('intern-documents.history');
        Route::patch('/intern-documents/{document}/hours', [InternDocumentController::class, 'saveHours'])
            ->middleware('role:admin')
            ->name('intern-documents.hours');
        Route::post('/intern-documents/{document}/peso-approve', [InternDocumentController::class, 'pesoApprove'])
            ->middleware('role:peso_manager')
            ->name('intern-documents.peso-approve');
        Route::post('/intern-documents/{document}/peso-return', [InternDocumentController::class, 'pesoReturn'])
            ->middleware('role:peso_manager')
            ->name('intern-documents.peso-return');
        Route::post('/intern-documents/{document}/admin-approve', [InternDocumentController::class, 'adminApprove'])
            ->middleware('role:admin')
            ->name('intern-documents.admin-approve');
        Route::post('/intern-documents/{document}/admin-return', [InternDocumentController::class, 'adminReturn'])
            ->middleware('role:admin')
            ->name('intern-documents.admin-return');
        Route::post('/intern-documents/{document}/resubmit', [InternDocumentController::class, 'resubmit'])
            ->name('intern-documents.resubmit');
        Route::patch('/intern-documents/{document}/review', [InternDocumentController::class, 'review'])
            ->middleware('role:admin,peso_manager')
            ->name('intern-documents.review');

        // Intern self-service
        Route::prefix('my-internship')->name('my-internship.')->middleware('role:intern')->group(function () {
            Route::get('/', [MyInternshipController::class, 'index'])->name('index');
            Route::get('/201-files', [MyInternshipController::class, 'files'])->name('files');
        });

        // Admin settings
        Route::middleware('role:admin')->prefix('settings')->name('settings.')->group(function () {
            Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
            Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
            Route::match(['put', 'patch'], '/companies/{company}', [CompanyController::class, 'update'])
                ->name('companies.update');
            Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
            Route::patch('/companies/{company}/toggle-active', [CompanyController::class, 'toggleActive'])
                ->name('companies.toggle-active');

            Route::get('/document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
            Route::post('/document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
            Route::match(['put', 'patch'], '/document-types/{document_type}', [DocumentTypeController::class, 'update'])
                ->name('document-types.update');
            Route::delete('/document-types/{document_type}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');

            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::match(['put', 'patch'], '/users/{user}', [UserController::class, 'update'])
                ->name('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
                ->name('users.reset-password');
            Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
                ->name('users.toggle-status');
        });

        // Reports
        Route::middleware('role:admin')->group(function () {
            Route::get('/reports/audit-log', [ActivityLogController::class, 'index'])
                ->name('reports.audit-log');
        });

        Route::middleware('role:admin,peso_manager')->prefix('reports')->name('reports.')->group(function () {
            Route::get('/intern', [ReportController::class, 'internIndex'])->name('intern');
            Route::get('/export/interns', [ReportController::class, 'exportInterns'])->name('export-interns');
            Route::get('/export/intern-hours', [ReportController::class, 'exportInternHours'])->name('export-intern-hours');
            Route::get('/export/intern-agencies', [ReportController::class, 'exportInternAgencySummary'])->name('export-intern-agencies');
            Route::get('/export/intern-expiry', [ReportController::class, 'exportInternExpiry'])->name('export-intern-expiry');
        });
    });
});
