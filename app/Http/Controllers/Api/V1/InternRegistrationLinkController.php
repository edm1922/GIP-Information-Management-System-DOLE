<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateInternRegistrationLinkRequest;
use App\Models\InternRegistrationLink;
use App\Services\AuditService;
use App\Services\InternRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternRegistrationLinkController extends Controller
{
    public function __construct(private readonly InternRegistrationService $registration) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', InternRegistrationLink::class);

        $active = $this->registration->currentLink();
        $history = InternRegistrationLink::query()
            ->with('creator')
            ->latest('id')
            ->limit(5)
            ->get();

        return response()->json([
            'link' => $active ? $this->payload($active) : null,
            'history' => $history->map(fn (InternRegistrationLink $link) => $this->payload($link)),
        ]);
    }

    public function store(GenerateInternRegistrationLinkRequest $request): JsonResponse
    {
        $this->authorize('manage', InternRegistrationLink::class);

        $link = $this->registration->generate(
            $request->user(),
            $request->validated('expires_in_days')
        );

        AuditService::log('created', 'Generated intern registration link', $link);

        return response()->json([
            'message' => 'Registration link generated.',
            'link' => $this->payload($link),
        ], 201);
    }

    public function destroy(): JsonResponse
    {
        $this->authorize('manage', InternRegistrationLink::class);

        $link = $this->registration->currentLink();
        if (! $link) {
            return response()->json(['message' => 'There is no active registration link.'], 404);
        }

        $this->registration->revoke($link);
        AuditService::log('updated', 'Revoked intern registration link', $link);

        return response()->json(['message' => 'Registration link revoked.']);
    }

    private function payload(InternRegistrationLink $link): array
    {
        return [
            'id' => $link->id,
            'url' => $this->registration->url($link),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'uses_count' => $link->uses_count,
            'is_active' => $link->isActive(),
            'created_by' => $link->creator?->name,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
