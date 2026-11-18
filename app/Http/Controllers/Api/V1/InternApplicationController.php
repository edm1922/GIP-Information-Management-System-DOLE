<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveInternApplicationRequest;
use App\Http\Requests\RejectInternApplicationRequest;
use App\Http\Resources\V1\InternApplicationResource;
use App\Models\InternApplication;
use App\Services\InternApplicationApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternApplicationController extends Controller
{
    public function __construct(private readonly InternApplicationApprovalService $approval) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InternApplication::class);

        $viewer = $request->user();
        $query = InternApplication::query()->with(['partnerAgency', 'reviewer', 'approvedUser']);

        // PESO reviews first; Admins see applications after that endorsement.
        if ($viewer?->isAdmin()) {
            $query->whereIn('status', ['pending_admin', 'approved', 'rejected']);
        }

        if ($status = $request->get('status')) {
            if (in_array($status, InternApplication::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($search = trim((string) $request->get('search'))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $applications = $query->latest()->paginate(15)->withQueryString();

        return response()->json([
            'applications' => InternApplicationResource::collection($applications)->response()->getData(true),
            'pending_count' => $viewer?->isAdmin()
                ? InternApplication::query()->where('status', 'pending_admin')->count()
                : InternApplication::query()->pending()->count(),
        ]);
    }

    public function show(InternApplication $application): JsonResponse
    {
        $this->authorize('view', $application);

        $application->load(['partnerAgency', 'reviewer', 'pesoReviewer', 'adminReviewer', 'approvedUser', 'approvedIntern']);

        return response()->json([
            'application' => new InternApplicationResource($application),
            'suggested_username' => in_array($application->status, ['pending', 'pending_admin'], true)
                ? $this->approval->suggestUsername($application)
                : null,
        ]);
    }

    public function approve(ApproveInternApplicationRequest $request, InternApplication $application): JsonResponse
    {
        $actor = $request->user();

        if ($actor->isPesoManager()) {
            $this->authorize('approveAsPeso', $application);

            $forwarded = $this->approval->pesoApprove($application, $actor);
            $forwarded->loadMissing(['reviewer', 'pesoReviewer', 'adminReviewer']);

            return response()->json([
                'message' => 'Application endorsed to the Admin for final approval. No credentials were sent yet.',
                'application' => new InternApplicationResource($forwarded),
            ]);
        }

        $this->authorize('approveAsAdmin', $application);

        $approved = $this->approval->adminApprove($application, $actor, $request->validated());
        $approved->loadMissing(['approvedUser', 'approvedIntern', 'reviewer', 'pesoReviewer', 'adminReviewer']);

        $emailSent = $approved->credential_email_status === 'sent';

        return response()->json([
            'message' => $emailSent
                ? 'Application finally approved. Login credentials were emailed to '.$approved->email.'.'
                : 'Application approved, but the credential email could not be sent. Use "Resend credentials" to try again.',
            'application' => new InternApplicationResource($approved),
        ]);
    }

    public function reject(RejectInternApplicationRequest $request, InternApplication $application): JsonResponse
    {
        $this->authorize('reject', $application);

        $rejected = $this->approval->reject($application, $request->user(), $request->validated('reason'));

        return response()->json([
            'message' => 'Application rejected.',
            'application' => new InternApplicationResource($rejected->loadMissing(['reviewer', 'pesoReviewer', 'adminReviewer'])),
        ]);
    }

    public function resendCredentials(InternApplication $application): JsonResponse
    {
        $this->authorize('resendCredentials', $application);

        $updated = $this->approval->resendCredentials($application);

        return response()->json([
            'message' => $updated->credential_email_status === 'sent'
                ? 'A new temporary password was emailed to '.$updated->email.'.'
                : 'The credential email could not be sent. Check the mail configuration and try again.',
            'application' => new InternApplicationResource($updated->loadMissing(['reviewer', 'approvedUser'])),
        ]);
    }
}
