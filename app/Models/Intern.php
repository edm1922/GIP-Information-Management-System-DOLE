<?php

namespace App\Models;

use App\Services\InternHoursService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin Builder
 */
class Intern extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['active', 'inactive', 'completed'];

    public const INTERN_TYPES = ['GIP', 'OJT', 'SPES', 'Work Immersion'];

    protected $fillable = [
        'user_id',
        'partner_agency_id',
        'contract_start_date',
        'contract_end_date',
        'required_hours',
        'hours_per_workday',
        'rendered_hours',
        'status',
        'intern_type',
        'batch',
        'beneficiary',
        'school',
        'course',
        'contact_number',
        'supervisor',
    ];

    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'required_hours' => 'integer',
            'hours_per_workday' => 'float',
            'rendered_hours' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partnerAgency(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'partner_agency_id');
    }

    public function internDocuments(): HasMany
    {
        return $this->hasMany(InternDocument::class);
    }

    /**
     * Full name, sourced from the linked user account.
     */
    public function getNameAttribute(): string
    {
        return $this->user?->name ?? 'Unassigned';
    }

    public function getHoursRemainingAttribute(): float
    {
        return max(0.0, (float) $this->required_hours - (float) $this->rendered_hours);
    }

    /**
     * Hours credited from approved DTRs (equals rendered_hours for structured data).
     */
    public function getApprovedHoursAttribute(): float
    {
        return app(InternHoursService::class)->approvedHours($this);
    }

    /** Hours still waiting for review — not yet credited. */
    public function getPendingHoursAttribute(): float
    {
        return app(InternHoursService::class)->pendingHours($this);
    }

    /** Hours on rejected DTRs — deliberately not credited. */
    public function getRejectedHoursAttribute(): float
    {
        return app(InternHoursService::class)->rejectedHours($this);
    }

    /**
     * Convert hours remaining to working days using the intern's configured
     * hours-per-workday (defaults to config('internship.hours_per_workday')).
     */
    public function getDaysRemainingFromHoursAttribute(): float
    {
        $hoursPerDay = (float) ($this->hours_per_workday ?: config('internship.hours_per_workday', 8));

        return round($this->hours_remaining / max($hoursPerDay, 0.01), 1);
    }

    /**
     * Total contract duration expressed in months (start → end).
     * Returns null if either date is missing.
     */
    public function getContractMonthsAttribute(): ?float
    {
        if (! $this->contract_start_date || ! $this->contract_end_date) {
            return null;
        }

        $totalDays = $this->contract_start_date->diffInDays($this->contract_end_date);

        return round($totalDays / 30.44, 1); // average days per month
    }

    public function getProgressPercentAttribute(): float
    {
        if ((int) $this->required_hours <= 0) {
            return 0.0;
        }

        return min(100.0, round(($this->rendered_hours / $this->required_hours) * 100, 2));
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->contract_end_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->contract_end_date->copy()->startOfDay(), false);
    }

    public function isExpiringSoon(int $days = 7): bool
    {
        $remaining = $this->days_remaining;

        return $remaining !== null && $remaining >= 0 && $remaining <= $days;
    }

    public function isExpired(): bool
    {
        return $this->contract_end_date && $this->contract_end_date->isPast();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('status', 'active')
            ->whereNotNull('contract_end_date')
            ->whereBetween('contract_end_date', [now()->startOfDay(), now()->addDays($days)->endOfDay()]);
    }
}
