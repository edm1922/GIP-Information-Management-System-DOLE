<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DtrDay
 */
class DtrDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'intern_id' => $this->intern_id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'hours' => (float) $this->hours,
        ];
    }
}
