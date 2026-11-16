<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Intern document payload.
 *
 * The physical storage path and generated system filename are deliberately
 * never serialized: files are only reachable through the authorized preview
 * and download endpoints exposed here as `preview_url` / `download_url`.
 *
 * @mixin \App\Models\InternDocument
 */
class InternDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dtrDaysLoaded = $this->resource->relationLoaded('dtrDays');
        $internLoaded = $this->resource->relationLoaded('intern');

        return [
            'id' => $this->id,
            'intern_id' => $this->intern_id,
            'document_type_id' => $this->document_type_id,
            'document_type' => new DocumentTypeResource($this->whenLoaded('documentType')),
            'uploaded_by' => $this->uploaded_by,
            'uploader' => new UserResource($this->whenLoaded('uploader')),
            'original_filename' => $this->original_filename,
            'page_count' => $this->page_count,
            'file_size_bytes' => $this->file_size_bytes,
            'mime_type' => $this->mime_type,
            'status' => $this->status,
            'document_period' => $this->document_period,
            'review_status' => $this->review_status,
            'reviewed_by' => $this->reviewed_by,
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_remarks' => $this->review_remarks,
            'metadata' => $this->metadata,
            'dtr_days' => DtrDayResource::collection($this->whenLoaded('dtrDays')),
            // Only computed when the caller eager-loaded the days, to keep list
            // payloads free of accidental N+1 queries.
            'dtr_summary' => $this->when($dtrDaysLoaded, fn () => $this->dtr_summary),
            'outside_contract_days_count' => $this->when(
                $dtrDaysLoaded && $internLoaded,
                fn () => $this->outside_contract_days_count
            ),
            'dtr_days_payload' => $this->when(
                $dtrDaysLoaded && $internLoaded,
                fn () => $this->dtr_days_payload
            ),
            'preview_url' => route('api.v1.intern-documents.preview', $this->id),
            'download_url' => route('api.v1.intern-documents.download', $this->id),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
