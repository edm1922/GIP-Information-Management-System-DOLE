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
            ->with(['intern.user', 'documentType', 'uploader', 'reviewer']);

        if ($status = $request->get('review_status')) {
            $query->where('review_status', $status);
        }

        if ($internId = $request->get('intern_id')) {
            $query->where('intern_id', (int) $internId);
        }

        $documents = $query->latest()->paginate(20)->withQueryString();

        return view('intern-documents.index', compact('documents'));
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
            ? redirect()->route('my-internship.index')
            : back();

        return $redirect->with('success', 'Document uploaded successfully and is now pending review.');
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
