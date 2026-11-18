<?php

namespace App\Services;

use App\Models\DtrDay;
use App\Models\Intern;

/**
 * Keeps `interns.rendered_hours` consistent with the reviewed DTR days.
 *
 * Rendered hours are credited only once a DTR is **approved**; a rejected or
 * still-pending DTR contributes nothing to the credited total (pending hours are
 * surfaced separately so the intern knows what is in flight).
 *
 * Interns whose hours pre-date the structured day picker (no dtr_days rows) are
 * left untouched so legacy totals are not silently wiped.
 */
class InternHoursService
{
    public function recalculate(Intern $intern): float
    {
        if (! $this->hasStructuredDays($intern)) {
            return (float) $intern->rendered_hours;
        }

        // Keep one decimal place: per-day DTR hours may be fractional.
        $approved = round($this->approvedHours($intern), 1);
        $intern->forceFill(['rendered_hours' => $approved])->save();

        return $approved;
    }

    /** Hours claimed on approved DTRs (falls back to the stored column for legacy interns). */
    public function approvedHours(Intern $intern): float
    {
        if (! $this->hasStructuredDays($intern)) {
            return (float) $intern->rendered_hours;
        }

        return round((float) $this->query($intern)
            ->whereHas('document', fn ($query) => $query->where('review_status', 'approved'))
            ->sum('hours'), 1);
    }

    /** Hours sitting in the review queue (pending DTRs). */
    public function pendingHours(Intern $intern): float
    {
        return (float) $this->query($intern)
            ->whereHas('document', fn ($query) => $query->whereIn('review_status', ['pending_peso', 'pending_admin']))
            ->sum('hours');
    }

    /** Hours on rejected DTRs — shown so interns understand what was not credited. */
    public function rejectedHours(Intern $intern): float
    {
        return (float) $this->query($intern)
            ->whereHas('document', fn ($query) => $query->whereIn('review_status', ['returned_to_peso', 'returned_to_intern']))
            ->sum('hours');
    }

    public function hasStructuredDays(Intern $intern): bool
    {
        return DtrDay::query()->where('intern_id', $intern->id)->exists();
    }

    private function query(Intern $intern)
    {
        return DtrDay::query()->where('intern_id', $intern->id);
    }
}
