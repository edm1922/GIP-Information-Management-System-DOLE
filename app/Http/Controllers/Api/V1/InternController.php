<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInternRequest;
use App\Http\Requests\UpdateInternRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Http\Resources\V1\InternResource;
use App\Models\Company;
use App\Models\Intern;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InternController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Intern::class);

        $showArchived = $request->boolean('archived') && $request->user()->hasRole('admin');

        $query = $showArchived
            ? Intern::onlyTrashed()->with(['user', 'partnerAgency'])
            : Intern::query()->with(['user', 'partnerAgency']);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($agencyId = $request->get('partner_agency_id')) {
            $query->where('partner_agency_id', (int) $agencyId);
        }

        if ($request->get('expiring') === 'soon') {
            $query->expiringSoon(30);
        } elseif ($request->get('expiring') === 'expired') {
            $query->whereNotNull('contract_end_date')
                ->whereDate('contract_end_date', '<', now()->startOfDay());
        }

        if ($search = trim((string) $request->get('search'))) {
            $query->whereHas('user', function ($userQuery) use ($search) {
                $userQuery->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $interns = $query->latest()->paginate(15)->withQueryString();
        $agencies = Company::query()->where('is_partner_agency', true)->orderBy('name')->get();

        return response()->json([
            'interns' => InternResource::collection($interns)->response()->getData(true),
            'agencies' => CompanyResource::collection($agencies),
            'show_archived' => $showArchived,
        ]);
    }

    public function show(Intern $intern): JsonResponse
    {
        $this->authorize('view', $intern);

        $intern->load([
            'user',
            'partnerAgency',
            'internDocuments.documentType',
            'internDocuments.reviewer',
            'internDocuments.dtrDays',
        ]);

        return response()->json([
            'intern' => new InternResource($intern),
        ]);
    }

    public function store(StoreInternRequest $request): JsonResponse
    {
        $this->authorize('create', Intern::class);

        $validated = $request->validated();

        $intern = DB::transaction(function () use ($validated) {
            $user = $this->createInternAccount($validated);

            return Intern::create([
                'user_id' => $user->id,
                'partner_agency_id' => $validated['partner_agency_id'] ?? null,
                'contract_start_date' => $validated['contract_start_date'] ?? null,
                'contract_end_date' => $validated['contract_end_date'] ?? null,
                'required_hours' => $validated['required_hours'],
                'rendered_hours' => $validated['rendered_hours'] ?? 0,
                'status' => $validated['status'],
                'intern_type' => $validated['intern_type'] ?? null,
                'batch' => $validated['batch'] ?? null,
                'beneficiary' => $validated['beneficiary'] ?? null,
                'school' => $validated['school'] ?? null,
                'course' => $validated['course'] ?? null,
                'contact_number' => $validated['contact_number'] ?? null,
                'supervisor' => $validated['supervisor'] ?? null,
            ]);
        });

        AuditService::log('created', 'Created new intern', $intern);
        $intern->load(['user', 'partnerAgency']);

        return response()->json([
            'message' => 'Intern created successfully.',
            'intern' => new InternResource($intern),
        ], 201);
    }

    public function update(UpdateInternRequest $request, Intern $intern): JsonResponse
    {
        $this->authorize('update', $intern);

        $validated = $request->validated();

        DB::transaction(function () use ($intern, $validated) {
            $intern->user?->update([
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'suffix' => $validated['suffix'] ?? null,
                'email' => $validated['email'] ?? null,
                'username' => $validated['username'],
            ]);

            $intern->update([
                'partner_agency_id' => $validated['partner_agency_id'] ?? null,
                'contract_start_date' => $validated['contract_start_date'] ?? null,
                'contract_end_date' => $validated['contract_end_date'] ?? null,
                'required_hours' => $validated['required_hours'],
                'rendered_hours' => $validated['rendered_hours'] ?? 0,
                'status' => $validated['status'],
                'intern_type' => $validated['intern_type'] ?? null,
                'batch' => $validated['batch'] ?? null,
                'beneficiary' => $validated['beneficiary'] ?? null,
                'school' => $validated['school'] ?? null,
                'course' => $validated['course'] ?? null,
                'contact_number' => $validated['contact_number'] ?? null,
                'supervisor' => $validated['supervisor'] ?? null,
            ]);
        });

        AuditService::log('updated', 'Updated intern', $intern);
        $intern->load(['user', 'partnerAgency']);

        return response()->json([
            'message' => 'Intern updated successfully.',
            'intern' => new InternResource($intern),
        ]);
    }

    public function destroy(Intern $intern): JsonResponse
    {
        $this->authorize('delete', $intern);

        $intern->delete();
        $intern->user?->update(['is_active' => false]);
        AuditService::log('deleted', 'Deleted intern', $intern);

        return response()->json(['message' => 'Intern archived successfully.']);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $intern = Intern::withTrashed()->findOrFail($id);
        $this->authorize('restore', $intern);

        $intern->restore();
        $intern->user?->update(['is_active' => true]);
        AuditService::log('restored', 'Restored intern', $intern);

        return response()->json(['message' => 'Intern restored successfully.']);
    }

    public function forceDelete(Request $request, int $id): JsonResponse
    {
        $intern = Intern::withTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $intern);

        $userId = $intern->user_id;

        $intern->internDocuments()->delete();
        $intern->forceDelete();

        if ($userId) {
            User::query()->whereKey($userId)->update(['is_active' => false]);
            User::query()->whereKey($userId)->delete();
        }

        AuditService::log('deleted', "Permanently deleted intern #{$id}");

        return response()->json(['message' => 'Intern permanently deleted.']);
    }

    public function agencies(): JsonResponse
    {
        $this->authorize('viewAny', Intern::class);

        $agencies = Company::query()
            ->where('is_partner_agency', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['agencies' => CompanyResource::collection($agencies)]);
    }

    private function createInternAccount(array $validated): User
    {
        $defaultPassword = strtolower($validated['last_name']).config('brand.password_suffix');

        return User::create([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'email' => $validated['email'] ?? null,
            'username' => $validated['username'],
            'password' => Hash::make($defaultPassword),
            'role' => 'intern',
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }
}
