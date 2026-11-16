<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewInternDocumentRequest;
use App\Http\Requests\StoreInternDocumentRequest;
use App\Http\Resources\V1\InternDocumentResource;
use App\Http\Resources\V1\InternResource;
use App\Models\Intern;
use App\Models\InternDocument;
use App\Notifications\InternDocumentReviewed;
use App\Notifications\InternDocumentUploaded;
use App\Services\AuditService;
use App\Services\InternDocumentUploadService;
use App\Services\InternHoursService;
use App\Services\InternNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InternDocumentController extends Controller
{
    public function __construct(
        private readonly InternDocumentUploadService $uploadService,
        private readonly InternNotificationService $notifier,
    ) {}

    public function index(Request $request): JsonResponse
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
            ->with(['documentType', 'uploader', 'reviewer', 'dtrDays', 'intern'])
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

        $this->notifier->notifyPesoTeam(new InternDocumentUploaded($document));

        $selfServiceRoute = $actor->isIntern() ? $this->selfServiceRouteFor($document) : null;

        return response()->json([
            'message' => 'Document uploaded successfully and is now pending review.',
            'document' => new InternDocumentResource($document->fresh(['documentType', 'uploader', 'reviewer', 'dtrDays', 'intern'])),
            'self_service_route' => $selfServiceRoute,
        ], 201);
    }

    public function review(ReviewInternDocumentRequest $request, InternDocument $document): JsonResponse
    {
        $validated = $request->validated();

        $document->update([
            'review_status' => $validated['decision'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_remarks' => $validated['review_remarks'] ?? null,
        ]);

        app(InternHoursService::class)->recalculate($document->intern);

        $this->notifier->notifyIntern($document->intern, new InternDocumentReviewed($document->fresh(['documentType'])));

        AuditService::log(
            $validated['decision'] === 'approved' ? 'approved' : 'rejected',
            "Intern document {$validated['decision']}: {$document->system_filename}",
            $document->intern,
            ['document_id' => $document->id]
        );

        return response()->json([
            'message' => 'Document '.$validated['decision'].' successfully.',
            'document' => new InternDocumentResource($document->fresh(['documentType', 'uploader', 'reviewer', 'dtrDays', 'intern'])),
        ]);
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
