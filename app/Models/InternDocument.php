<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A document belonging to an intern.
 *
 * Intern documents share the `documents` table with department (201-Files)
 * documents but are isolated through a global scope on `intern_id` and are
 * never indexed by Scout. This keeps a dedicated policy/relationship while
 * reusing the existing storage and schema.
 *
 * @mixin Builder
 */
class InternDocument extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'intern_id',
        'document_type_id',
        'uploaded_by',
        'file_path',
        'original_filename',
        'system_filename',
        'page_count',
        'file_size_bytes',
        'mime_type',
        'status',
        'document_period',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('intern', function (Builder $query) {
            $query->whereNotNull('intern_id');
        });
    }

    public function intern(): BelongsTo
    {
        return $this->belongsTo(Intern::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPendingReview(): bool
    {
        return $this->review_status === 'pending';
    }
}
