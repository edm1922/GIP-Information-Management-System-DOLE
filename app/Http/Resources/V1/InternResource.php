<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Intern payload used by list, detail and dashboard screens.
 *
 * Computed values (progress, remaining hours/days, contract length) mirror the
 * accessors the Blade views render, so the SPA does not have to recreate the
 * business rules. `rendered_hours` remains the authoritative credited total.
 *
 * @mixin \App\Models\Intern
 */
class InternResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'partner_agency_id' => $this->partner_agency_id,
            'partner_agency' => new CompanyResource($this->whenLoaded('partnerAgency')),
            'name' => $this->name,
            'contract_start_date' => $this->contract_start_date?->format('Y-m-d'),
            'contract_end_date' => $this->contract_end_date?->format('Y-m-d'),
            'contract_months' => $this->contract_months,
            'required_hours' => (int) $this->required_hours,
            'rendered_hours' => (float) $this->rendered_hours,
            'hours_remaining' => (float) $this->hours_remaining,
            'days_remaining_from_hours' => (float) $this->days_remaining_from_hours,
            'days_remaining' => $this->days_remaining,
            'progress_percent' => (float) $this->progress_percent,
            'status' => $this->status,
            'intern_type' => $this->intern_type,
            'batch' => $this->batch,
            'beneficiary' => $this->beneficiary,
            'school' => $this->school,
            'course' => $this->course,
            'contact_number' => $this->contact_number,
            'supervisor' => $this->supervisor,
            'is_completed' => (bool) $this->is_completed,
            'is_active' => (bool) $this->is_active,
            // Aggregate counts, present only on the folder/list queries that
            // select them explicitly.
            'dtr_count' => $this->when(isset($this->dtr_count), fn () => (int) $this->dtr_count),
            'files_count' => $this->when(isset($this->files_count), fn () => (int) $this->files_count),
            'pending_count' => $this->when(isset($this->pending_count), fn () => (int) $this->pending_count),
            'intern_documents_count' => $this->when(
                isset($this->intern_documents_count),
                fn () => (int) $this->intern_documents_count
            ),
            'intern_documents' => InternDocumentResource::collection($this->whenLoaded('internDocuments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
