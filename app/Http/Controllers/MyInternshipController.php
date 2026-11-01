<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\DtrDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Intern self-service.
 *
 * The module is split into two tabs:
 *  - Daily Time Record (DTR): hours summary, day-picker uploads, DTR history.
 *  - 201 Files: every other intern document (Accomplishment Report, resignation,
 *    certificates, …). The label is historical; it no longer refers to the
 *    employee/department 201 files module that was retired from this codebase.
 */
class MyInternshipController extends Controller
{
    /** Daily Time Record tab. */
    public function index(Request $request): View|RedirectResponse
    {
        return $this->renderTab($request, isDtrTab: true);
    }

    /** 201 Files tab. */
    public function files(Request $request): View|RedirectResponse
    {
        return $this->renderTab($request, isDtrTab: false);
    }

    private function renderTab(Request $request, bool $isDtrTab): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->isIntern()) {
            return redirect()->route('dashboard');
        }

        $intern = $user->intern;

        if (! $intern) {
            return view('my-internship.unassigned', ['isDtrTab' => $isDtrTab]);
        }

        $intern->load(['partnerAgency']);

        $documents = $intern->internDocuments()
            ->with(['documentType', 'reviewer', 'dtrDays'])
            ->when(
                $isDtrTab,
                fn ($query) => $query->whereHas('documentType', fn ($type) => $type->where('code', 'DTR')),
                fn ($query) => $query->whereDoesntHave('documentType', fn ($type) => $type->where('code', 'DTR'))
            )
            ->latest()
            ->get();

        $documentTypes = DocumentType::query()
            ->where('is_intern_document', true)
            ->when(
                $isDtrTab,
                fn ($query) => $query->where('code', 'DTR'),
                fn ($query) => $query->where('code', '!=', 'DTR')
            )
            ->orderBy('name')
            ->get();

        $takenDays = $isDtrTab
            ? DtrDay::query()
                ->counted()
                ->where('intern_id', $intern->id)
                ->pluck('work_date')
                ->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'))
                ->unique()
                ->values()
                ->all()
            : [];

        $data = compact('intern', 'documents', 'documentTypes', 'takenDays', 'isDtrTab');

        if ($isDtrTab) {
            return view('my-internship.index', $data);
        }

        // Requirements checklist for the 201 Files tab.
        $checklist = $documentTypes
            ->map(function (DocumentType $type) use ($documents) {
                $latest = $documents
                    ->where('document_type_id', $type->id)
                    ->sortByDesc('created_at')
                    ->first();

                $status = match (true) {
                    $latest === null => 'missing',
                    $latest->review_status === 'approved' => 'approved',
                    $latest->review_status === 'rejected' => 'rejected',
                    default => 'pending',
                };

                return [
                    'type' => $type,
                    'required' => ! $type->is_optional,
                    'status' => $status,
                ];
            })
            ->values();

        return view('my-internship.files', $data + compact('checklist'));
    }
}
