<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentTypeRequest;
use App\Http\Resources\V1\DocumentTypeResource;
use App\Models\DocumentType;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DocumentType::withCount('internDocuments');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $documentTypes = $query->orderBy('name')->paginate(15)->withQueryString();

        return response()->json([
            'document_types' => DocumentTypeResource::collection($documentTypes)->response()->getData(true),
        ]);
    }

    public function store(DocumentTypeRequest $request): JsonResponse
    {
        $documentType = DocumentType::create($request->validated());

        AuditService::log('created', 'Created document type.', $documentType, [
            'before' => [],
            'after' => [
                'name' => $documentType->name,
                'code' => $documentType->code,
            ],
        ]);

        return response()->json([
            'message' => 'Document type created successfully.',
            'document_type' => new DocumentTypeResource($documentType),
        ], 201);
    }

    public function update(DocumentTypeRequest $request, DocumentType $documentType): JsonResponse
    {
        $before = $documentType->only(['name', 'code']);

        $documentType->update($request->validated());
        $after = $documentType->fresh()->only(['name', 'code']);

        AuditService::log('updated', 'Updated document type.', $documentType, [
            'before' => $before,
            'after' => $after,
        ]);

        return response()->json([
            'message' => 'Document type updated successfully.',
            'document_type' => new DocumentTypeResource($documentType->fresh()),
        ]);
    }

    public function destroy(DocumentType $documentType): JsonResponse
    {
        $documentsCount = $documentType->internDocuments()->count();

        if ($documentsCount > 0) {
            return response()->json([
                'message' => 'This document type cannot be deleted because it is currently used by one or more uploaded documents.',
            ], 422);
        }

        $snapshot = [
            'name' => $documentType->name,
            'code' => $documentType->code,
            'documents_count_at_delete' => $documentsCount,
        ];

        $documentType->delete();

        AuditService::log('deleted', 'Deleted document type.', $documentType, [
            'before' => $snapshot,
            'after' => [],
        ]);

        return response()->json(['message' => 'Document type deleted successfully.']);
    }
}
