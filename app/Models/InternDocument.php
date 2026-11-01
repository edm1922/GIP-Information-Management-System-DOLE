<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Working days claimed by this DTR. These, not the uploaded scan, are the
     * authoritative record of which days were worked.
     */
    public function dtrDays(): HasMany
    {
        return $this->hasMany(DtrDay::class, 'document_id')->orderBy('work_date');
    }

    /**
     * Human-readable summary of the claimed working days, e.g.
     * "Oct 01, 2026 – Oct 15, 2026 · 11 days · 88 hrs".
     */
    public function getDtrSummaryAttribute(): ?string
    {
        $days = $this->relationLoaded('dtrDays') ? $this->dtrDays : $this->dtrDays()->get();

        if ($days->isEmpty()) {
            return null;
        }

        $sorted = $days->sortBy(fn (DtrDay $day) => $day->work_date->timestamp);
        $count = $sorted->count();
        $hours = (float) $sorted->sum('hours');

        return sprintf(
            '%s – %s · %d %s · %s %s',
            $sorted->first()->work_date->format('M d, Y'),
            $sorted->last()->work_date->format('M d, Y'),
            $count,
            $count === 1 ? 'day' : 'days',
            rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.'),
            'hrs'
        );
    }

    /**
     * Number of claimed days that fall outside the intern's contract window.
     *
     * These are allowed (back-filling earlier DTRs) but surfaced to reviewers.
     */
    public function getOutsideContractDaysCountAttribute(): int
    {
        $days = $this->relationLoaded('dtrDays') ? $this->dtrDays : $this->dtrDays()->get();

        if ($days->isEmpty()) {
            return 0;
        }

        $start = $this->intern?->contract_start_date?->copy()->startOfDay();
        $end = $this->intern?->contract_end_date?->copy()->endOfDay();

        if (! $start && ! $end) {
            return 0;
        }

        return $days->filter(function (DtrDay $day) use ($start, $end) {
            $date = $day->work_date->copy()->startOfDay();

            return ($start && $date->lessThan($start)) || ($end && $date->greaterThan($end));
        })->count();
    }

    /**
     * Payload for the day viewer: every claimed day with its hours and whether
     * it falls outside the intern's contract window.
     *
     * @return array<int, array{date: string, hours: float, outside_contract: bool}>
     */
    public function getDtrDaysPayloadAttribute(): array
    {
        $days = $this->relationLoaded('dtrDays') ? $this->dtrDays : $this->dtrDays()->get();

        if ($days->isEmpty()) {
            return [];
        }

        $start = $this->intern?->contract_start_date?->copy()->startOfDay();
        $end = $this->intern?->contract_end_date?->copy()->endOfDay();

        return $days
            ->sortBy(fn (DtrDay $day) => $day->work_date->timestamp)
            ->map(function (DtrDay $day) use ($start, $end) {
                $date = $day->work_date->copy()->startOfDay();

                return [
                    'date' => $date->format('Y-m-d'),
                    'hours' => (float) $day->hours,
                    'outside_contract' => ($start && $date->lessThan($start)) || ($end && $date->greaterThan($end)),
                ];
            })
            ->values()
            ->all();
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
