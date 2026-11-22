<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

/**
 * Working-day helpers for internship contract date ranges.
 *
 * The project has no holiday calendar, so a working day is simply any
 * Monday–Friday calendar date (inclusive of both endpoints).
 */
class WorkingDays
{
    /**
     * Total whole hours covered by a contract date range.
     *
     * Counts Monday–Friday dates between the two dates (inclusive), then
     * multiplies by the hours worked per day and rounds to whole hours to
     * match the integer `interns.required_hours` column.
     *
     * Returns null when either date is missing/unparseable or the end date
     * falls before the start date.
     */
    public static function hoursBetween(
        string|DateTimeInterface|null $start,
        string|DateTimeInterface|null $end,
        float $hoursPerWorkday,
    ): ?float {
        $startDate = self::parseDate($start);
        $endDate = self::parseDate($end);

        if ($startDate === null || $endDate === null || $endDate->lt($startDate)) {
            return null;
        }

        // Inclusive day count, then whole weeks (5 weekdays each) plus the
        // weekdays left over in the trailing partial week.
        $totalDays = (int) $startDate->diffInDays($endDate) + 1;
        $weekdays = intdiv($totalDays, 7) * 5;

        for ($offset = 0; $offset < $totalDays % 7; $offset++) {
            if ($startDate->copy()->addDays($offset)->isWeekday()) {
                $weekdays++;
            }
        }

        return (float) round($weekdays * $hoursPerWorkday);
    }

    /**
     * Normalise supported date input to a start-of-day Carbon instance.
     */
    private static function parseDate(string|DateTimeInterface|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $date->startOfDay();
    }
}
