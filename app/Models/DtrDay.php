<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single working day claimed on a Daily Time Record (DTR).
 *
 * Days are selected by the intern in a date picker at upload time. The DTR
 * scan itself is only a reference for the reviewer.
 *
 * @property int $id
 * @property int $document_id
 * @property int $intern_id
 * @property Carbon $work_date
 * @property string $hours
 */
class DtrDay extends Model
{
    protected $fillable = [
        'document_id',
        'intern_id',
        'work_date',
        'hours',
    ];

    protected $casts = [
        'work_date' => 'date',
        'hours' => 'float',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(InternDocument::class, 'document_id');
    }

    public function intern(): BelongsTo
    {
        return $this->belongsTo(Intern::class);
    }

    /**
     * Days that still count towards the intern's record: rows belonging to a
     * document that was not rejected and has not been soft-deleted.
     */
    public function scopeCounted(Builder $query): Builder
    {
        // Days from a DTR that was returned to the intern (or rejected) are no
        // longer held: the intern must be able to correct/re-pick those dates.
        return $query->whereHas('document', function (Builder $q) {
            $q->whereNotIn('review_status', ['rejected', InternDocument::STATUS_RETURNED_TO_INTERN]);
        });
    }
}
