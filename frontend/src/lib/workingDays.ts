/**
 * Weekday arithmetic for the intern contract-hours calculation.
 *
 * Required hours are estimated from the contract range by counting Monday–Friday
 * calendar dates inclusively (weekends excluded) and multiplying by the intern's
 * hours per working day. Dates are handled as plain calendar dates so local time
 * zones and DST never shift the count.
 */

const DATE_INPUT_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const MS_PER_DAY = 86_400_000;

/** Parses a `YYYY-MM-DD` form value as a calendar date; impossible dates yield `null`. */
export function parseDateInput(value: string | null | undefined): Date | null {
  if (!value) return null;

  const match = DATE_INPUT_PATTERN.exec(value.trim());
  if (!match) return null;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const date = new Date(year, month - 1, day);

  // Reject overflow such as 2026-02-31, which would otherwise roll into March.
  if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
    return null;
  }
  return date;
}

/** Whole days since 1970-01-01 for the calendar date, ignoring time of day. */
function calendarDayNumber(date: Date): number {
  return Math.round(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()) / MS_PER_DAY);
}

/** Counts Monday–Friday dates from `start` through `end`, inclusive; `null` when the range is backwards. */
export function countWeekdays(start: Date, end: Date): number | null {
  const totalDays = calendarDayNumber(end) - calendarDayNumber(start) + 1;
  if (totalDays < 1) return null;

  // Every full week contributes five weekdays; walk the leftover leading days.
  let count = Math.floor(totalDays / 7) * 5;
  const remainder = totalDays % 7;
  const startWeekday = start.getDay();

  for (let offset = 0; offset < remainder; offset += 1) {
    const weekday = (startWeekday + offset) % 7;
    if (weekday !== 0 && weekday !== 6) count += 1;
  }
  return count;
}

/** Weekdays between two `YYYY-MM-DD` values; `null` when either date is missing/invalid or the range is backwards. */
export function weekdayCountBetween(startValue: string, endValue: string): number | null {
  const start = parseDateInput(startValue);
  const end = parseDateInput(endValue);
  if (!start || !end) return null;
  return countWeekdays(start, end);
}

/** `true` when both dates are valid but the contract ends before it starts. */
export function contractEndBeforeStart(startValue: string, endValue: string): boolean {
  const start = parseDateInput(startValue);
  const end = parseDateInput(endValue);
  if (!start || !end) return false;
  return calendarDayNumber(end) < calendarDayNumber(start);
}

/**
 * Required hours derived from the contract range and hours per working day.
 *
 * Returns `null` when the inputs cannot produce a sane value (missing/invalid date,
 * end before start, non-positive hours), so callers leave the field untouched
 * instead of writing `NaN`/negative numbers.
 */
export function requiredHoursFromContract(
  startValue: string,
  endValue: string,
  hoursPerWorkday: string | number,
): number | null {
  const weekdays = weekdayCountBetween(startValue, endValue);
  const hours = typeof hoursPerWorkday === "number" ? hoursPerWorkday : Number(hoursPerWorkday);
  if (weekdays === null || !Number.isFinite(hours) || hours <= 0) return null;
  return Math.round(weekdays * hours);
}
