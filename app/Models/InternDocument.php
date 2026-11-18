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

    public const STATUS_PENDING_PESO = 'pending_peso';
    public const STATUS_PENDING_ADMIN = 'pending_admin';
    public const STATUS_RETURNED_TO_PESO = 'returned_to_peso';
    public const STATUS_RETURNED_TO_INTERN = 'returned_to_intern';
    public const STATUS_APPROVED = 'approved';

    /** Workflow statuses used by new submissions. */
    public const WORKFLOW_STATUSES = [
        self::STATUS_PENDING_PESO,
        self::STATUS_PENDING_ADMIN,
        self::STATUS_RETURNED_TO_PESO,
        self::STATUS_RETURNED_TO_INTERN,
        self::STATUS_APPROVED,
    ];

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_PESO => 'Pending PESO Review',
            self::STATUS_PENDING_ADMIN => 'Pending Admin Approval',
            self::STATUS_RETURNED_TO_PESO => 'Returned to PESO Manager',
            self::STATUS_RETURNED_TO_INTERN => 'Returned to Intern',
            self::STATUS_APPROVED => 'Approved',
            'pending' => 'Pending Review (legacy)',
            'rejected' => 'Rejected (legacy)',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

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
        'peso_reviewed_by',
        'peso_reviewed_at',
        'peso_remarks',
        'admin_reviewed_by',
        'admin_reviewed_at',
        'admin_remarks',
        'hours_assigned_by',
        'hours_assigned_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'peso_reviewed_at' => 'datetime',
            'admin_reviewed_at' => 'datetime',
            'hours_assigned_at' => 'datetime',
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
        $hasHours = $sorted->contains(fn (DtrDay $day) => $day->hours !== null);
        $hours = $hasHours ? (float) $sorted->sum(fn (DtrDay $day) => (float) $day->hours) : 0.0;

        return sprintf(
            '%s – %s · %d %s%s',
            $sorted->first()->work_date->format('M d, Y'),
            $sorted->last()->work_date->format('M d, Y'),
            $count,
            $count === 1 ? 'day' : 'days',
            $hasHours ? ' · '.rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.').' hrs' : ''
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
                    'hours' => $day->hours === null ? null : (float) $day->hours,
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

    public function pesoReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'peso_reviewed_by');
    }

    public function adminReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_reviewed_by');
    }

    public function hoursAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hours_assigned_by');
    }

    public function reviewEvents(): HasMany
    {
        return $this->hasMany(InternDocumentReviewEvent::class, 'document_id')->orderBy('created_at');
    }

    public function isPendingReview(): bool
    {
        return in_array($this->review_status, [
            self::STATUS_PENDING_PESO,
            self::STATUS_PENDING_ADMIN,
        ], true);
    }
}
