<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInternRequest;
use App\Http\Requests\UpdateInternRequest;
use App\Models\Company;
use App\Models\Intern;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class InternController extends Controller
{
    /**
     * Display a listing of interns.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Intern::class);

        $query = Intern::query()->with(['user', 'partnerAgency']);

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
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $interns = $query->latest()->paginate(15)->withQueryString();

        $agencies = Company::query()->where('is_partner_agency', true)->orderBy('name')->get();

        return view('interns.index', compact('interns', 'agencies'));
    }

    /**
     * Show the form for creating a new intern (and its login account).
     */
    public function create(): View
    {
        $this->authorize('create', Intern::class);

        $agencies = Company::query()->where('is_partner_agency', true)->where('is_active', true)->orderBy('name')->get();

        return view('interns.create', compact('agencies'));
    }

    /**
     * Store a newly created intern and linked user account.
     */
    public function store(StoreInternRequest $request): RedirectResponse
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

        return redirect()->route('interns.show', $intern)
            ->with('success', 'Intern created successfully.');
    }

    /**
     * Display the specified intern.
     */
    public function show(Intern $intern): View
    {
        $this->authorize('view', $intern);

        $intern->load([
            'user',
            'partnerAgency',
            'internDocuments.documentType',
            'internDocuments.reviewer',
            'internDocuments.dtrDays',
        ]);

        return view('interns.show', compact('intern'));
    }

    /**
     * Show the form for editing the specified intern.
     */
    public function edit(Intern $intern): View
    {
        $this->authorize('update', $intern);

        $intern->load('user');

        $agencies = Company::query()
            ->where('is_partner_agency', true)
            ->where(function ($q) use ($intern) {
                $q->where('is_active', true);
                if ($intern->partner_agency_id) {
                    $q->orWhere('id', $intern->partner_agency_id);
                }
            })
            ->orderBy('name')
            ->get();

        return view('interns.edit', compact('intern', 'agencies'));
    }

    /**
     * Update the specified intern and its linked account.
     */
    public function update(UpdateInternRequest $request, Intern $intern): RedirectResponse
    {
        $this->authorize('update', $intern);

        $validated = $request->validated();

        DB::transaction(function () use ($intern, $validated) {
            $intern->user?->update([
                'first_name' => $validated['first_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'last_name' => $validated['last_name'],
                'suffix' => $validated['suffix'] ?? null,
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

        return redirect()->route('interns.show', $intern)
            ->with('success', 'Intern updated successfully.');
    }

    /**
     * Soft delete the specified intern.
     */
    public function destroy(Intern $intern): RedirectResponse
    {
        $this->authorize('delete', $intern);

        $intern->delete();
        AuditService::log('deleted', 'Deleted intern', $intern);

        return redirect()->route('interns.index')
            ->with('success', 'Intern archived successfully.');
    }

    /**
     * Restore a soft-deleted intern.
     */
    public function restore(int $id): RedirectResponse
    {
        $intern = Intern::withTrashed()->findOrFail($id);
        $this->authorize('restore', $intern);

        $intern->restore();
        AuditService::log('restored', 'Restored intern', $intern);

        return redirect()->route('interns.index')
            ->with('success', 'Intern restored successfully.');
    }

    /**
     * Permanently delete an intern (and detach its login account).
     */
    public function forceDelete(int $id): RedirectResponse
    {
        $intern = Intern::withTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $intern);

        $userId = $intern->user_id;
        $intern->forceDelete();

        if ($userId) {
            User::query()->whereKey($userId)->delete();
        }

        AuditService::log('deleted', "Permanently deleted intern #{$id}");

        return redirect()->route('interns.index')
            ->with('success', 'Intern permanently deleted.');
    }

    /**
     * Create the login account for a new intern.
     */
    private function createInternAccount(array $validated): User
    {
        $defaultPassword = strtolower($validated['last_name']).config('brand.password_suffix');

        return User::create([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'suffix' => $validated['suffix'] ?? null,
            'username' => $validated['username'],
            'password' => Hash::make($defaultPassword),
            'role' => 'intern',
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }
}
