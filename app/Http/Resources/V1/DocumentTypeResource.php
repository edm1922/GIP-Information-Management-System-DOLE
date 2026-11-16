<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DocumentType
 */
class DocumentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'has_expiry' => (bool) $this->has_expiry,
            'max_pages' => (int) $this->max_pages,
            'is_intern_document' => (bool) $this->is_intern_document,
            'is_optional' => (bool) $this->is_optional,
            'intern_documents_count' => $this->when(
                isset($this->intern_documents_count),
                fn () => (int) $this->intern_documents_count
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
