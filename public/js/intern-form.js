/**
 * Intern form: auto-calculate Required Hours from the contract date range.
 *
 * Counts Monday–Friday dates inclusively (Saturday and Sunday are excluded)
 * between Contract Start and Contract End, then multiplies the weekday count
 * by Hours / Working Day and rounds the result.
 *
 * Required Hours is only recalculated after the user edits Contract Start,
 * Contract End, or Hours / Working Day. The initial page load (including a
 * page re-rendered after a failed server-side validation) never overwrites
 * the value that came from the database or the old input.
 *
 * Invalid dates, an end date before the start date, or a non-positive
 * Hours / Working Day leave the current Required Hours value untouched.
 */
(() => {
    'use strict';

    // Defensive guard: only bind once even if the partial is ever rendered
    // more than once on a page.
    if (document.documentElement.dataset.internFormHoursBound === '1') {
        return;
    }

    const startInput = document.getElementById('intern-contract-start');
    const endInput = document.getElementById('intern-contract-end');
    const hoursPerDayInput = document.getElementById('intern-hours-per-workday');
    const requiredHoursInput = document.getElementById('intern-required-hours');

    if (!startInput || !endInput || !hoursPerDayInput || !requiredHoursInput) {
        return;
    }

    document.documentElement.dataset.internFormHoursBound = '1';

    const DAY_MS = 24 * 60 * 60 * 1000;

    /**
     * Parse a YYYY-MM-DD input value into a UTC timestamp at midnight.
     * Returns null for empty or invalid dates.
     */
    const parseDate = (value) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec((value || '').trim());
        if (!match) {
            return null;
        }

        const year = Number(match[1]);
        const month = Number(match[2]);
        const day = Number(match[3]);
        const time = Date.UTC(year, month - 1, day);
        const date = new Date(time);

        // Reject impossible dates such as 2026-02-31.
        if (
            date.getUTCFullYear() !== year ||
            date.getUTCMonth() !== month - 1 ||
            date.getUTCDate() !== day
        ) {
            return null;
        }

        return time;
    };

    /**
     * Count Monday–Friday dates from start to end, both inclusive.
     */
    const countWeekdays = (startTime, endTime) => {
        let count = 0;

        for (let time = startTime; time <= endTime; time += DAY_MS) {
            const weekday = new Date(time).getUTCDay();
            if (weekday !== 0 && weekday !== 6) {
                count += 1;
            }
        }

        return count;
    };

    const recalculate = () => {
        const start = parseDate(startInput.value);
        const end = parseDate(endInput.value);

        // Both dates must be valid and the range must not be reversed.
        if (start === null || end === null || end < start) {
            return;
        }

        const hoursPerDay = Number.parseFloat(hoursPerDayInput.value);
        if (!Number.isFinite(hoursPerDay) || hoursPerDay <= 0) {
            return;
        }

        const totalHours = Math.round(countWeekdays(start, end) * hoursPerDay);

        if (!Number.isFinite(totalHours) || totalHours < 0) {
            return;
        }

        requiredHoursInput.value = String(totalHours);
    };

    [startInput, endInput, hoursPerDayInput].forEach((input) => {
        input.addEventListener('input', recalculate);
        input.addEventListener('change', recalculate);
    });
})();
