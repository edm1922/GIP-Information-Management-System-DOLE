<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewInternDocumentRequest;
use App\Http\Requests\StoreInternDocumentRequest;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Notifications\InternDocumentReviewed;
use App\Notifications\InternDocumentUploaded;
use App\Services\AuditService;
use App\Services\InternDocumentUploadService;
use App\Services\InternHoursService;
use App\Services\InternNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternDocumentController extends Controller
{
    public function __construct(
        private readonly InternDocumentUploadService $uploadService,
        private readonly InternNotificationService $notifier,
    ) {}

    /**
     * Review queue for administrators and PESO managers.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', InternDocument::class);

        $query = InternDocument::query()
            ->with(['intern.user', 'documentType', 'uploader', 'reviewer', 'dtrDays']);

        if ($status = $request->get('review_status')) {
            $query->where('review_status', $status);
        }

        if ($internId = $request->get('intern_id')) {
            $query->where('intern_id', (int) $internId);
        }

        // Free-text search across the file name and the intern's name/username.
        if ($search = trim((string) $request->get('q'))) {
            $like = '%'.$search.'%';
            $query->where(function ($where) use ($like) {
                $where->where('original_filename', 'like', $like)
                    ->orWhereHas('intern.user', function ($user) use ($like) {
                        $user->where('first_name', 'like', $like)
                            ->orWhere('middle_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('username', 'like', $like);
                    });
            });
        }

        $documents = $query->latest()->paginate(20)->withQueryString();

        return view('intern-documents.index', compact('documents'));
    }

    /**
     * Folder browser: one folder per intern, showing how many DTRs / 201 files
     * they have submitted. Supports searching by intern and filtering by state.
     */
    public function folders(Request $request): View
    {
        $this->authorize('viewAny', InternDocument::class);

        $search = trim((string) $request->get('q'));
        $filter = (string) $request->get('filter');

        $interns = Intern::query()
            ->select('interns.*')
            ->leftJoin('users', 'users.id', '=', 'interns.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'interns.partner_agency_id')
            ->with(['user', 'partnerAgency'])
            ->withCount([
                'internDocuments as dtr_count' => fn ($query) => $query
                    ->whereHas('documentType', fn ($type) => $type->where('code', 'DTR')),
                'internDocuments as files_count' => fn ($query) => $query
                    ->whereDoesntHave('documentType', fn ($type) => $type->where('code', 'DTR')),
                'internDocuments as pending_count' => fn ($query) => $query
                    ->where('review_status', 'pending'),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($where) use ($like) {
                    $where->where('users.first_name', 'like', $like)
                        ->orWhere('users.middle_name', 'like', $like)
                        ->orWhere('users.last_name', 'like', $like)
                        ->orWhere('users.username', 'like', $like)
                        ->orWhere('companies.name', 'like', $like);
                });
            })
            ->when($filter === 'pending', fn ($query) => $query
                ->whereHas('internDocuments', fn ($documents) => $documents->where('review_status', 'pending')))
            ->when($filter === 'none', fn ($query) => $query->whereDoesntHave('internDocuments'))
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->paginate(24)
            ->withQueryString();

        return view('intern-documents.folders', compact('interns', 'search', 'filter'));
    }

    /**
     * A single intern's folder: their DTRs and 201 files side by side.
     */
    public function internFolder(Intern $intern): View
    {
        $this->authorize('viewAny', InternDocument::class);

        $intern->load(['user', 'partnerAgency']);

        $documents = $intern->internDocuments()
            ->with(['documentType', 'uploader', 'reviewer', 'dtrDays'])
            ->latest()
            ->get();

        $isDtr = fn (InternDocument $document) => $document->documentType?->code === 'DTR';

        $dtrs = $documents->filter($isDtr)->values();
        $files = $documents->reject($isDtr)->values();

        return view('intern-documents.folder', compact('intern', 'dtrs', 'files'));
    }

    /**
     * Store a newly uploaded intern document.
     */
    public function store(StoreInternDocumentRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $validated = $request->validated();

        if ($actor->isIntern()) {
            $intern = $actor->intern;
        } else {
            $intern = Intern::find((int) ($validated['intern_id'] ?? 0));
        }

        if (! $intern) {
            return back()->with('error', 'No intern record is associated with this upload.')->withInput();
        }

        $this->authorize('create', [InternDocument::class, $intern]);

        $document = $this->uploadService->upload($intern, $validated, $actor);

        $this->notifier->notifyPesoTeam(new InternDocumentUploaded($document));

        $redirect = $actor->isIntern()
            ? redirect()->route($this->selfServiceRouteFor($document))
            : back();

        return $redirect->with('success', 'Document uploaded successfully and is now pending review.');
    }

    /**
     * Interns are split across two tabs: DTRs land on the Daily Time Record tab,
     * every other document lands on 201 Files.
     */
    private function selfServiceRouteFor(InternDocument $document): string
    {
        return $document->documentType?->code === 'DTR'
            ? 'my-internship.index'
            : 'my-internship.files';
    }

    /**
     * Approve or reject a submitted intern document.
     */
    public function review(ReviewInternDocumentRequest $request, InternDocument $document): RedirectResponse
    {
        $validated = $request->validated();

        $document->update([
            'review_status' => $validated['decision'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['review_remarks'] ?? null,
        ]);

        // Approving credits the claimed hours; rejecting removes them again.
        app(InternHoursService::class)->recalculate($document->intern);

        $this->notifier->notifyIntern($document->intern, new InternDocumentReviewed($document->fresh(['documentType'])));

        AuditService::log(
            $validated['decision'] === 'approved' ? 'approved' : 'rejected',
            "Intern document {$validated['decision']}: {$document->system_filename}",
            $document->intern,
            ['document_id' => $document->id]
        );

        return back()->with('success', 'Document '.$validated['decision'].' successfully.');
    }

    /**
     * Stream the document for inline preview.
     */
    public function preview(InternDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        $mimeType = $document->mime_type;
        if (! $mimeType || $mimeType === 'application/octet-stream') {
            $ext = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));
            $mimeType = match ($ext) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'csv' => 'text/csv',
                default => 'application/octet-stream',
            };
        }

        return Storage::disk('local')->response($document->file_path, $document->original_filename, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_filename).'"',
        ]);
    }

    /**
     * Download the document as an attachment.
     */
    public function download(InternDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }
}
