<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an intern document's approval/return history.
 */
class InternDocumentReviewEvent extends Model
{
    protected $fillable = [
        'document_id',
        'actor_id',
        'actor_role',
        'action',
        'from_status',
        'to_status',
        'remarks',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(InternDocument::class, 'document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
