<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe, explicit serialization of a user account for API consumers.
 *
 * Only the fields the frontend actually renders are exposed. Credentials,
 * remember tokens, email columns (this app authenticates by username) and
 * verification columns are intentionally omitted.
 *
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'name' => $this->name,
            'role' => $this->role,
            'is_active' => (bool) $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            // Resolved only when the caller eager-loaded the relation, so list
            // endpoints never trigger one query per user.
            'intern_id' => $this->when(
                $this->resource->relationLoaded('intern'),
                fn () => $this->intern?->id
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
