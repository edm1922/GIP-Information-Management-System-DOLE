<?php

use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\InternController;
use App\Http\Controllers\InternDocumentController;
use App\Http\Controllers\MyInternshipController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('auth.login');
});

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/force-password-change', [ForcePasswordChangeController::class, 'show'])->name('password.force-change');
    Route::post('/force-password-change', [ForcePasswordChangeController::class, 'store'])->name('password.force-change.store');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead'])->name('mark-as-read');
    });

    // ── Admin Settings ──
    Route::middleware('role:admin')->prefix('settings')->name('settings.')->group(function () {
        Route::resource('companies', CompanyController::class)->except(['show']);
        Route::patch('companies/{company}/toggle-active', [CompanyController::class, 'toggleActive'])
            ->name('companies.toggle-active');

        Route::resource('document-types', DocumentTypeController::class)->except(['show', 'create', 'edit']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->name('users.reset-password');
        Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])
            ->name('users.toggle-status');
        Route::resource('users', UserController::class)->except(['show']);
    });

    // ── Admin Only ──
    Route::middleware('role:admin')->group(function () {
        // Audit & Activity Logs
        Route::get('/reports/audit-log', [ActivityLogController::class, 'index'])
            ->name('reports.audit-log');
    });

    // ── Internship Reports (Admin & PESO Manager) ──
    Route::middleware('role:admin,peso_manager')->group(function () {
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/intern', [ReportController::class, 'internIndex'])->name('intern');
            Route::get('/export/interns', [ReportController::class, 'exportInterns'])->name('export-interns');
            Route::get('/export/intern-hours', [ReportController::class, 'exportInternHours'])->name('export-intern-hours');
            Route::get('/export/intern-agencies', [ReportController::class, 'exportInternAgencySummary'])->name('export-intern-agencies');
            Route::get('/export/intern-expiry', [ReportController::class, 'exportInternExpiry'])->name('export-intern-expiry');
        });
    });

    Route::prefix('interns')->name('interns.')->group(function () {
        Route::get('/', [InternController::class, 'index'])
            ->middleware('can:viewAny,App\Models\Intern')
            ->name('index');

        Route::get('/create', [InternController::class, 'create'])
            ->middleware('role:admin')
            ->name('create');

        Route::post('/', [InternController::class, 'store'])
            ->middleware('role:admin')
            ->name('store');

        Route::get('/{intern}', [InternController::class, 'show'])
            ->middleware('can:view,intern')
            ->name('show');

        Route::get('/{intern}/edit', [InternController::class, 'edit'])
            ->middleware('role:admin')
            ->name('edit');

        Route::put('/{intern}', [InternController::class, 'update'])
            ->middleware('role:admin')
            ->name('update');

        Route::delete('/{intern}', [InternController::class, 'destroy'])
            ->middleware('role:admin')
            ->name('destroy');

        Route::patch('/{id}/restore', [InternController::class, 'restore'])
            ->middleware('role:admin')
            ->name('restore');

        Route::delete('/{id}/force-delete', [InternController::class, 'forceDelete'])
            ->middleware('role:admin')
            ->name('forceDelete');
    });

    // ── Intern Documents (shared by admins/PESO for review and interns for upload) ──
    Route::prefix('intern-documents')->name('intern-documents.')->group(function () {
        Route::get('/', [InternDocumentController::class, 'index'])
            ->middleware('can:viewAny,App\Models\InternDocument')
            ->name('index');

        Route::post('/', [InternDocumentController::class, 'store'])
            ->name('store');

        // Folder browser: one folder per intern.
        Route::get('/folders', [InternDocumentController::class, 'folders'])
            ->middleware('role:admin,peso_manager')
            ->name('folders');

        Route::get('/folders/{intern}', [InternDocumentController::class, 'internFolder'])
            ->middleware('role:admin,peso_manager')
            ->name('folder');

        Route::get('/{document}/preview', [InternDocumentController::class, 'preview'])
            ->middleware('can:download,document')
            ->name('preview')
            ->withTrashed();

        Route::get('/{document}/download', [InternDocumentController::class, 'download'])
            ->middleware('can:download,document')
            ->name('download')
            ->withTrashed();

        Route::patch('/{document}/review', [InternDocumentController::class, 'review'])
            ->middleware('role:admin,peso_manager')
            ->name('review');

        Route::get('/{document}/history', [InternDocumentController::class, 'history'])
            ->middleware('can:view,document')
            ->name('history');

        Route::patch('/{document}/hours', [InternDocumentController::class, 'saveHours'])
            ->middleware('role:admin')
            ->name('hours');

        Route::post('/{document}/peso-approve', [InternDocumentController::class, 'pesoApprove'])
            ->middleware('role:peso_manager')
            ->name('peso-approve');

        Route::post('/{document}/peso-return', [InternDocumentController::class, 'pesoReturn'])
            ->middleware('role:peso_manager')
            ->name('peso-return');

        Route::post('/{document}/admin-approve', [InternDocumentController::class, 'adminApprove'])
            ->middleware('role:admin')
            ->name('admin-approve');

        Route::post('/{document}/admin-return', [InternDocumentController::class, 'adminReturn'])
            ->middleware('role:admin')
            ->name('admin-return');

        Route::post('/{document}/resubmit', [InternDocumentController::class, 'resubmit'])
            ->name('resubmit');
    });

    // ── Intern Self-Service ──
    Route::prefix('my-internship')->name('my-internship.')->middleware('role:intern')->group(function () {
        // Daily Time Record tab (route name kept for existing notifications/links).
        Route::get('/', [MyInternshipController::class, 'index'])->name('index');

        // 201 Files tab: every non-DTR intern document.
        Route::get('/201-files', [MyInternshipController::class, 'files'])->name('files');
    });
});

require __DIR__.'/auth.php';
