<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DocumentTypeResource;
use App\Http\Resources\V1\InternDocumentResource;
use App\Http\Resources\V1\InternResource;
use App\Models\DocumentType;
use App\Models\DtrDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MyInternshipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->renderTab($request, isDtrTab: true);
    }

    public function files(Request $request): JsonResponse
    {
        return $this->renderTab($request, isDtrTab: false);
    }

    private function renderTab(Request $request, bool $isDtrTab): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isIntern()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $intern = $user->intern;

        if (! $intern) {
            return response()->json([
                'unassigned' => true,
                'is_dtr_tab' => $isDtrTab,
            ]);
        }

        $intern->load(['user', 'partnerAgency']);

        $documents = $intern->internDocuments()
            ->with(['documentType', 'reviewer', 'dtrDays', 'intern'])
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

        $payload = [
            'unassigned' => false,
            'is_dtr_tab' => $isDtrTab,
            'intern' => new InternResource($intern),
            'documents' => InternDocumentResource::collection($documents),
            'document_types' => DocumentTypeResource::collection($documentTypes),
            'taken_days' => $takenDays,
        ];

        if (! $isDtrTab) {
            $checklist = $documentTypes
                ->map(function (DocumentType $type) use ($documents) {
                    $latest = $documents
                        ->where('document_type_id', $type->id)
                        ->sortByDesc('created_at')
                        ->first();

                    $status = match (true) {
                        $latest === null => 'missing',
                        $latest->review_status === 'approved' => 'approved',
                        in_array($latest->review_status, ['returned_to_peso', 'returned_to_intern', 'rejected'], true) => 'returned',
                        default => 'pending',
                    };

                    return [
                        'type' => new DocumentTypeResource($type),
                        'required' => ! $type->is_optional,
                        'status' => $status,
                        'latest_document' => $latest ? new InternDocumentResource($latest) : null,
                    ];
                })
                ->values();

            $payload['checklist'] = $checklist;
        }

        return response()->json($payload);
    }
}
