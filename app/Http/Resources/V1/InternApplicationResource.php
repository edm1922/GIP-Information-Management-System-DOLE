<?php

namespace App\Http\Resources\V1;

use App\Services\InternApplicationApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Application review payload (staff only). Never contains passwords.
 *
 * @mixin \App\Models\InternApplication
 */
class InternApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'name' => $this->name,
            'email' => $this->email,
            'contact_number' => $this->contact_number,
            'school' => $this->school,
            'course' => $this->course,
            'partner_agency_id' => $this->partner_agency_id,
            'partner_agency' => new CompanyResource($this->whenLoaded('partnerAgency')),
            'intern_type' => $this->intern_type,
            'preferred_start_date' => $this->preferred_start_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'status' => $this->status,
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'approved_user_id' => $this->approved_user_id,
            'approved_intern_id' => $this->approved_intern_id,
            'approved_username' => $this->whenLoaded('approvedUser', fn () => $this->approvedUser?->username),
            'credential_email_status' => $this->credential_email_status,
            'credential_email_sent_at' => $this->credential_email_sent_at?->toIso8601String(),
            'credential_email_error' => $this->credential_email_error,
            'credential_email_attempts' => $this->credential_email_attempts,
            'suggested_username' => $this->when(
                $this->status === 'pending',
                fn () => app(InternApplicationApprovalService::class)->suggestUsername($this->resource)
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
