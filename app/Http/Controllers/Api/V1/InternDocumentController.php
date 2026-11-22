<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResubmitInternDocumentRequest;
use App\Http\Requests\ReturnInternDocumentRequest;
use App\Http\Requests\ReviewInternDocumentRequest;
use App\Http\Requests\SaveDocumentHoursRequest;
use App\Http\Requests\StoreInternDocumentRequest;
use App\Http\Resources\V1\InternDocumentResource;
use App\Http\Resources\V1\InternResource;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Notifications\InternDocumentSubmitted;
use App\Services\InternDocumentUploadService;
use App\Services\InternDocumentWorkflowService;
use App\Services\InternNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternDocumentController extends Controller
{
    /**
     * Content types that are safe to render inline in the browser.
     *
     * SEC-01b: only passive document/image types are allowed. Everything else —
     * including legacy rows whose content-detected type was never validated
     * (e.g. text/html, image/svg+xml) — is forced to download as
     * application/octet-stream so a stored payload can never execute on the
     * application origin. This list is server-controlled, never taken from the
     * request or the stored row.
     */
    private const INLINE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private readonly InternDocumentUploadService $uploadService,
        private readonly InternNotificationService $notifier,
        private readonly InternDocumentWorkflowService $workflow,
    ) {}

    public function index(Request $request): JsonResponse
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

        return response()->json([
            'documents' => InternDocumentResource::collection($documents)->response()->getData(true),
        ]);
    }

    public function folders(Request $request): JsonResponse
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

        return response()->json([
            'interns' => InternResource::collection($interns)->response()->getData(true),
            'search' => $search,
            'filter' => $filter,
        ]);
    }

    public function internFolder(Intern $intern): JsonResponse
    {
        $this->authorize('viewAny', InternDocument::class);

        $intern->load(['user', 'partnerAgency']);

        $documents = $intern->internDocuments()
            ->with(['documentType', 'uploader', 'reviewer', 'pesoReviewer', 'adminReviewer', 'reviewEvents.actor', 'dtrDays', 'intern'])
            ->latest()
            ->get();

        $isDtr = fn (InternDocument $document) => $document->documentType?->code === 'DTR';

        return response()->json([
            'intern' => new InternResource($intern),
            'dtrs' => InternDocumentResource::collection($documents->filter($isDtr)->values()),
            'files' => InternDocumentResource::collection($documents->reject($isDtr)->values()),
        ]);
    }

    public function store(StoreInternDocumentRequest $request): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validated();

        if ($actor->isIntern()) {
            $intern = $actor->intern;
        } else {
            $intern = Intern::find((int) ($validated['intern_id'] ?? 0));
        }

        if (! $intern) {
            return response()->json([
                'message' => 'No intern record is associated with this upload.',
                'errors' => ['intern_id' => ['No intern record is associated with this upload.']],
            ], 422);
        }

        $this->authorize('create', [InternDocument::class, $intern]);

        $document = $this->uploadService->upload($intern, $validated, $actor);

        $this->notifier->notifyPesoManagers(new InternDocumentSubmitted($document));

        $selfServiceRoute = $actor->isIntern() ? $this->selfServiceRouteFor($document) : null;

        return response()->json([
            'message' => 'Document uploaded successfully and is now pending PESO review.',
            'document' => $this->present($document),
            'self_service_route' => $selfServiceRoute,
        ], 201);
    }

    public function pesoApprove(Request $request, InternDocument $document): JsonResponse
    {
        $this->authorize('reviewAsPeso', $document);

        $validated = $request->validate([
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->workflow->pesoApprove($document, $request->user(), $validated['review_remarks'] ?? null);

        return $this->stateResponse($updated, 'Document approved and forwarded to the Admin for final review.');
    }

    public function pesoReturn(ReturnInternDocumentRequest $request, InternDocument $document): JsonResponse
    {
        $this->authorize('reviewAsPeso', $document);

        $updated = $this->workflow->pesoReturn($document, $request->user(), $request->validated('remarks'));

        return $this->stateResponse($updated, 'Document returned to the intern for correction.');
    }

    public function adminApprove(Request $request, InternDocument $document): JsonResponse
    {
        $this->authorize('reviewAsAdmin', $document);

        $validated = $request->validate([
            'review_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->workflow->adminApprove($document, $request->user(), $validated['review_remarks'] ?? null);

        return $this->stateResponse($updated, 'Document approved successfully.');
    }

    public function adminReturn(ReturnInternDocumentRequest $request, InternDocument $document): JsonResponse
    {
        $this->authorize('reviewAsAdmin', $document);

        $updated = $this->workflow->adminReturn($document, $request->user(), $request->validated('remarks'));

        return $this->stateResponse($updated, 'Document returned to the PESO manager for correction.');
    }

    public function saveHours(SaveDocumentHoursRequest $request, InternDocument $document): JsonResponse
    {
        $this->authorize('assignHours', $document);

        $updated = $this->workflow->saveHours($document, $request->user(), $request->validated('day_hours'));

        return $this->stateResponse($updated, 'Working hours saved.');
    }

    public function resubmit(ResubmitInternDocumentRequest $request, InternDocument $document): JsonResponse
    {
        $this->authorize('resubmit', $document);

        $updated = $this->workflow->resubmit(
            $document,
            $request->user(),
            $request->validated(),
            $request->file('file')
        );

        return $this->stateResponse($updated, 'Corrections submitted. The document is pending PESO review again.');
    }

    public function history(InternDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        return response()->json([
            'events' => $this->workflow->history($document),
        ]);
    }

    /**
     * Legacy single-step review endpoint kept for backward compatibility with
     * older clients/Blade forms. Maps onto the sequential workflow.
     */
    public function review(ReviewInternDocumentRequest $request, InternDocument $document): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        if ($actor->isAdmin()) {
            $this->authorize('reviewAsAdmin', $document);
            $updated = $validated['decision'] === 'approved'
                ? $this->workflow->adminApprove($document, $actor, $validated['review_remarks'] ?? null)
                : $this->workflow->adminReturn($document, $actor, $validated['review_remarks'] ?? 'Returned for correction.');
        } else {
            $this->authorize('reviewAsPeso', $document);
            $updated = $validated['decision'] === 'approved'
                ? $this->workflow->pesoApprove($document, $actor, $validated['review_remarks'] ?? null)
                : $this->workflow->pesoReturn($document, $actor, $validated['review_remarks'] ?? 'Returned for correction.');
        }

        return $this->stateResponse($updated, 'Document reviewed successfully.');
    }

    public function preview(InternDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        // A DB row whose file is gone must 404, not 500 (Storage::response()
        // calls size(), which throws UnableToRetrieveMetadata otherwise).
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        // Normalise the stored type (ignore any charset/parameter suffix) and
        // only fall back to the filename extension for legacy rows that never
        // recorded one. The served type is always chosen from the server-side
        // allowlist below, never echoed straight from the database.
        $mimeType = strtolower(trim(explode(';', (string) $document->mime_type)[0]));
        if ($mimeType === '' || $mimeType === 'application/octet-stream') {
            $ext = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));
            $mimeType = match ($ext) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                default => 'application/octet-stream',
            };
        }

        $inline = in_array($mimeType, self::INLINE_MIME_TYPES, true);

        // Inline types are passive (they cannot script the application origin),
        // so they only need the resource restrictions. The `sandbox` directive
        // is reserved for downloads, where it never affects a PDF viewer.
        $contentSecurityPolicy = $inline
            ? "default-src 'none'"
            : "default-src 'none'; sandbox";

        // The filesystem response builds Content-Disposition with Symfony's
        // makeDisposition(), so the filename is always safely encoded.
        return Storage::disk('local')->response(
            $document->file_path,
            $document->original_filename,
            [
                'Content-Type' => $inline ? $mimeType : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => $contentSecurityPolicy,
                'Cross-Origin-Resource-Policy' => 'same-origin',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }

    public function download(InternDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_filename, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function present(InternDocument $document): InternDocumentResource
    {
        return new InternDocumentResource($document->fresh([
            'documentType', 'uploader', 'reviewer', 'pesoReviewer', 'adminReviewer', 'reviewEvents.actor', 'dtrDays', 'intern.user',
        ]));
    }

    private function stateResponse(InternDocument $document, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'document' => $this->present($document),
        ]);
    }

    private function selfServiceRouteFor(InternDocument $document): string
    {
        return $document->documentType?->code === 'DTR'
            ? 'my-internship.index'
            : 'my-internship.files';
    }
}
