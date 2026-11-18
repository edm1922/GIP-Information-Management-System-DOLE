<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResubmitInternDocumentRequest;
use App\Http\Requests\ReturnInternDocumentRequest;
use App\Http\Requests\ReviewInternDocumentRequest;
use App\Http\Requests\SaveDocumentHoursRequest;
use App\Http\Requests\StoreInternDocumentRequest;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Notifications\InternDocumentSubmitted;
use App\Services\InternDocumentUploadService;
use App\Services\InternDocumentWorkflowService;
use App\Services\InternNotificationService;
use Illuminate\Http\JsonResponse;
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
        private readonly InternDocumentWorkflowService $workflow,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', InternDocument::class);

        $query = InternDocument::query()
            ->with(['intern.user', 'documentType', 'uploader', 'reviewer', 'pesoReviewer', 'adminReviewer', 'dtrDays']);

        if ($status = $request->get('review_status')) {
            $query->where('review_status', $status);
        }

        if ($internId = $request->get('intern_id')) {
            $query->where('intern_id', (int) $internId);
        }

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

    public function folders(Request $request): View
    {
        $this->authorize('viewAny', InternDocument::class);

        $search = trim((string) $request->get('q'));
        $filter = (string) $request->get('filter');

        $pendingStatuses = [
            InternDocument::STATUS_PENDING_PESO,
            InternDocument::STATUS_PENDING_ADMIN,
            InternDocument::STATUS_RETURNED_TO_PESO,
            InternDocument::STATUS_RETURNED_TO_INTERN,
        ];

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
                    ->whereIn('review_status', $pendingStatuses),
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
                ->whereHas('internDocuments', fn ($documents) => $documents->whereIn('review_status', $pendingStatuses)))
            ->when($filter === 'none', fn ($query) => $query->whereDoesntHave('internDocuments'))
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->paginate(24)
            ->withQueryString();

        return view('intern-documents.folders', compact('interns', 'search', 'filter'));
    }

    public function internFolder(Intern $intern): View
    {
        $this->authorize('viewAny', InternDocument::class);

        $intern->load(['user', 'partnerAgency']);

        $documents = $intern->internDocuments()
            ->with(['documentType', 'uploader', 'reviewer', 'pesoReviewer', 'adminReviewer', 'reviewEvents.actor', 'dtrDays'])
            ->latest()
            ->get();

        $isDtr = fn (InternDocument $document) => $document->documentType?->code === 'DTR';

        $dtrs = $documents->filter($isDtr)->values();
        $files = $documents->reject($isDtr)->values();

        return view('intern-documents.folder', compact('intern', 'dtrs', 'files'));
    }

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

        $this->notifier->notifyPesoManagers(new InternDocumentSubmitted($document));

        $redirect = $actor->isIntern()
            ? redirect()->route($this->selfServiceRouteFor($document))
            : back();

        return $redirect->with('success', 'Document uploaded successfully and is now pending PESO review.');
    }

    public function pesoApprove(Request $request, InternDocument $document): RedirectResponse
    {
        $this->authorize('reviewAsPeso', $document);

        $validated = $request->validate(['review_remarks' => ['nullable', 'string', 'max:2000']]);
        $this->workflow->pesoApprove($document, $request->user(), $validated['review_remarks'] ?? null);

        return back()->with('success', 'Document approved and forwarded to the Admin.');
    }

    public function pesoReturn(ReturnInternDocumentRequest $request, InternDocument $document): RedirectResponse
    {
        $this->authorize('reviewAsPeso', $document);

        $this->workflow->pesoReturn($document, $request->user(), $request->validated('remarks'));

        return back()->with('success', 'Document sent back to the intern for correction.');
    }

    public function adminApprove(Request $request, InternDocument $document): RedirectResponse
    {
        $this->authorize('reviewAsAdmin', $document);

        $validated = $request->validate(['review_remarks' => ['nullable', 'string', 'max:2000']]);
        $this->workflow->adminApprove($document, $request->user(), $validated['review_remarks'] ?? null);

        return back()->with('success', 'Document approved successfully.');
    }

    public function adminReturn(ReturnInternDocumentRequest $request, InternDocument $document): RedirectResponse
    {
        $this->authorize('reviewAsAdmin', $document);

        $this->workflow->adminReturn($document, $request->user(), $request->validated('remarks'));

        return back()->with('success', 'Document returned to the PESO manager.');
    }

    public function saveHours(SaveDocumentHoursRequest $request, InternDocument $document): RedirectResponse
    {
        $this->authorize('assignHours', $document);

        $this->workflow->saveHours($document, $request->user(), $request->validated('day_hours'));

        return back()->with('success', 'Working hours saved.');
    }

    public function resubmit(ResubmitInternDocumentRequest $request, InternDocument $document): RedirectResponse
    {
        $this->authorize('resubmit', $document);

        $this->workflow->resubmit($document, $request->user(), $request->validated(), $request->file('file'));

        return back()->with('success', 'Corrections submitted. The document is pending PESO review again.');
    }

    public function history(InternDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json(['events' => $this->workflow->history($document)]);
    }

    /** Legacy Blade review form (maps onto the sequential workflow). */
    public function review(ReviewInternDocumentRequest $request, InternDocument $document): RedirectResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        if ($actor->isAdmin()) {
            $this->authorize('reviewAsAdmin', $document);
            $validated['decision'] === 'approved'
                ? $this->workflow->adminApprove($document, $actor, $validated['review_remarks'] ?? null)
                : $this->workflow->adminReturn($document, $actor, $validated['review_remarks'] ?? 'Returned for correction.');
        } else {
            $this->authorize('reviewAsPeso', $document);
            $validated['decision'] === 'approved'
                ? $this->workflow->pesoApprove($document, $actor, $validated['review_remarks'] ?? null)
                : $this->workflow->pesoReturn($document, $actor, $validated['review_remarks'] ?? 'Returned for correction.');
        }

        return back()->with('success', 'Document reviewed successfully.');
    }

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

    public function download(InternDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }

    private function selfServiceRouteFor(InternDocument $document): string
    {
        return $document->documentType?->code === 'DTR'
            ? 'my-internship.index'
            : 'my-internship.files';
    }
}
