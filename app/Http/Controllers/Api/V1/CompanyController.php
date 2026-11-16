<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::withCount('interns');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->input('status') !== 'all') {
            $isActive = $request->input('status') === 'active';
            $query->where('is_active', $isActive);
        }

        $companies = $query->orderBy('name')->paginate(15)->withQueryString();

        return response()->json([
            'companies' => CompanyResource::collection($companies)->response()->getData(true),
        ]);
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        $company = Company::create([
            'name' => $request->validated('name'),
            'code' => strtoupper($request->validated('code')),
            'is_active' => $request->boolean('is_active', true),
            'is_partner_agency' => true,
        ]);

        AuditService::log('created', 'Created new partner agency', $company);

        return response()->json([
            'message' => 'Partner agency created successfully.',
            'company' => new CompanyResource($company),
        ], 201);
    }

    public function show(Company $company): JsonResponse
    {
        $company->loadCount('interns');

        return response()->json(['company' => new CompanyResource($company)]);
    }

    public function update(CompanyRequest $request, Company $company): JsonResponse
    {
        $company->update([
            'name' => $request->validated('name'),
            'code' => strtoupper($request->validated('code')),
            'is_active' => $request->boolean('is_active'),
            'is_partner_agency' => true,
        ]);

        AuditService::log('updated', 'Updated partner agency', $company);

        return response()->json([
            'message' => 'Partner agency updated successfully.',
            'company' => new CompanyResource($company->fresh()),
        ]);
    }

    public function toggleActive(Company $company): JsonResponse
    {
        $company->update(['is_active' => ! $company->is_active]);

        $status = $company->is_active ? 'activated' : 'deactivated';
        AuditService::log('updated', "Partner agency status updated to {$status}", $company);

        return response()->json([
            'message' => "Partner agency {$status} successfully.",
            'company' => new CompanyResource($company->fresh()),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        if ($company->interns()->count() > 0) {
            return response()->json([
                'message' => 'This partner agency cannot be deleted because it currently has one or more interns assigned to it. Please deactivate the agency instead.',
            ], 422);
        }

        $company->delete();
        AuditService::log('deleted', 'Deleted partner agency');

        return response()->json(['message' => 'Partner agency removed successfully.']);
    }
}
