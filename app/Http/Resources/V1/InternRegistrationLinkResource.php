<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shareable registration link payload (staff only).
 *
 * @mixin \App\Models\InternRegistrationLink
 */
class InternRegistrationLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => app(\App\Services\InternRegistrationService::class)->url($this->resource),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'uses_count' => (int) $this->uses_count,
            'is_active' => $this->isActive(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
