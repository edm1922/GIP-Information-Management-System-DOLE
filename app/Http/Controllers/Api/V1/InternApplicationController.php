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

        // PESO reviews first; Admins only see applications after that decision.
        if ($viewer?->isAdmin()) {
            $query->where('status', '!=', 'pending');
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
            'pending_count' => $viewer?->isPesoManager() ? InternApplication::query()->pending()->count() : 0,
        ]);
    }

    public function show(InternApplication $application): JsonResponse
    {
        $this->authorize('view', $application);

        $application->load(['partnerAgency', 'reviewer', 'approvedUser', 'approvedIntern']);

        return response()->json([
            'application' => new InternApplicationResource($application),
            'suggested_username' => $this->approval->suggestUsername($application),
        ]);
    }

    public function approve(ApproveInternApplicationRequest $request, InternApplication $application): JsonResponse
    {
        $this->authorize('approve', $application);

        $approved = $this->approval->approve($application, $request->user(), $request->validated());
        $approved->loadMissing(['approvedUser', 'approvedIntern', 'reviewer']);

        $emailSent = $approved->credential_email_status === 'sent';

        return response()->json([
            'message' => $emailSent
                ? 'Application approved. Login credentials were emailed to '.$approved->email.'.'
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
            'application' => new InternApplicationResource($rejected->loadMissing('reviewer')),
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
