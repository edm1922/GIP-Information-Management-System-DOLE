<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicInternApplicationRequest;
use App\Models\Company;
use App\Models\Intern;
use App\Models\InternApplication;
use App\Services\InternRegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * Public, unauthenticated endpoints used by the shareable registration page.
 * Nothing here reveals internal user/intern records or IDs.
 */
class PublicInternRegistrationController extends Controller
{
    public function __construct(private readonly InternRegistrationService $registration) {}

    public function show(string $token): JsonResponse
    {
        $link = $this->registration->resolve($token);

        if (! $link) {
            return response()->json(['message' => 'This registration link is not valid.'], 404);
        }

        if (! $link->isActive()) {
            return response()->json(['message' => 'This registration link has expired or been revoked.'], 410);
        }

        $agencies = Company::query()
            ->where('is_partner_agency', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'registration' => [
                'brand' => [
                    'name' => config('brand.name'),
                    'short_name' => config('brand.short_name'),
                ],
                'expires_at' => $link->expires_at?->toIso8601String(),
                'intern_types' => Intern::INTERN_TYPES,
                'agencies' => $agencies,
            ],
        ]);
    }

    public function store(StorePublicInternApplicationRequest $request, string $token): JsonResponse
    {
        $link = $this->registration->resolve($token);

        if (! $link) {
            return response()->json(['message' => 'This registration link is not valid.'], 404);
        }

        if (! $link->isActive()) {
            return response()->json(['message' => 'This registration link has expired or been revoked.'], 410);
        }

        /** @var InternApplication $application */
        $application = $this->registration->submit($link, $request->validated());

        return response()->json([
            'message' => 'Your application was submitted. Please wait for the PESO office to review it.',
            'reference' => $application->reference,
        ], 201);
    }
}
