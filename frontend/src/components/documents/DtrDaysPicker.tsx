"use client";

import { useMemo, useState } from "react";

import { Modal } from "@/components/ui/Modal";

const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];

function pad(value: number): string {
  return String(value).padStart(2, "0");
}

function keyOf(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function prettyDay(day: string): string {
  const [year, month, date] = day.split("-").map(Number);
  return new Date(year, month - 1, date).toLocaleString("en-US", {
    weekday: "long",
    month: "short",
    day: "2-digit",
    year: "numeric",
  });
}

interface Cell {
  key: string;
  day: number;
  inMonth: boolean;
  weekend: boolean;
  today: boolean;
  selected: boolean;
  taken: boolean;
  outsideContract: boolean;
  disabled: boolean;
}

interface DtrDaysPickerProps {
  days: string[];
  onChange: (days: string[]) => void;
  /** Days already claimed by another DTR — blocked. */
  taken: string[];
  contractStart: string | null;
  contractEnd: string | null;
}

/**
 * Working-day picker for DTR submissions.
 *
 * Interns only choose dates. Per-day working hours are assigned by an Admin
 * during final review, so this component deliberately has no hours input and
 * no days/hours summary.
 */
export function DtrDaysPicker({ days, onChange, taken, contractStart, contractEnd }: DtrDaysPickerProps) {
  const [open, setOpen] = useState(false);
  const [cursor, setCursor] = useState(() => {
    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), 1);
  });

  const todayKey = keyOf(new Date());
  const takenSet = useMemo(() => new Set(taken), [taken]);
  const selectedSet = useMemo(() => new Set(days), [days]);

  const grid = useMemo<Cell[]>(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const startOffset = first.getDay();

    return Array.from({ length: 42 }, (_value, index) => {
      const date = new Date(cursor.getFullYear(), cursor.getMonth(), 1 - startOffset + index);
      const key = keyOf(date);
      const inMonth = date.getMonth() === cursor.getMonth();
      const future = key > todayKey;
      const outsideContract = Boolean(
        (contractStart && key < contractStart) || (contractEnd && key > contractEnd),
      );

      return {
        key,
        day: date.getDate(),
        inMonth,
        weekend: date.getDay() === 0 || date.getDay() === 6,
        today: key === todayKey,
        selected: selectedSet.has(key),
        taken: takenSet.has(key),
        outsideContract,
        disabled: !inMonth || future || takenSet.has(key),
      };
    });
  }, [cursor, selectedSet, takenSet, todayKey, contractStart, contractEnd]);

  const monthStats = useMemo(() => {
    const weekdays = grid.filter((cell) => cell.inMonth && !cell.weekend && !cell.taken).map((cell) => cell.key);
    return {
      weekdays: weekdays.length,
      covered: weekdays.filter((key) => selectedSet.has(key)).length,
    };
  }, [grid, selectedSet]);

  const monthHasSelectable = grid.some((cell) => cell.inMonth && !cell.disabled);
  const monthLabel = cursor.toLocaleString("en-US", { month: "long", year: "numeric" });
  const contractLabel =
    contractStart || contractEnd ? `${contractStart ?? "—"} to ${contractEnd ?? "—"}` : null;

  const toggleDay = (key: string) => {
    if (selectedSet.has(key)) {
      onChange(days.filter((day) => day !== key));
    } else {
      onChange([...days, key]);
    }
  };

  const addWeekdays = () => {
    const nextDays = [...days];
    grid.forEach((cell) => {
      if (!cell.disabled && !cell.weekend && !nextDays.includes(cell.key)) {
        nextDays.push(cell.key);
      }
    });
    onChange(nextDays);
  };

  const summary =
    days.length === 0
      ? ""
      : days.length === 1
        ? prettyDay(days[0])
        : `${prettyDay([...days].sort()[0])} → ${prettyDay([...days].sort().at(-1) as string)}`;

  return (
    <>
      <label className="form-label d-block">
        Working Days <span className="text-danger">*</span>
      </label>

      <div className="d-flex flex-wrap align-items-center gap-2">
        <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => setOpen(true)}>
          <i className="fas fa-calendar-days me-1" /> Pick working days
        </button>
        <span className="text-muted small">
          {days.length === 0 ? "No days selected yet." : "Selected dates are highlighted on the calendar."}
        </span>
      </div>

      <div className="form-text mt-1">
        {days.length === 0
          ? "Open the calendar and click each day you worked. Your Admin will assign the working hours after the PESO review."
          : summary}
      </div>

      <Modal
        show={open}
        onClose={() => setOpen(false)}
        size="lg"
        title={
          <span>
            <i className="fas fa-calendar-check me-2" style={{ color: "var(--company-primary)" }} />
            Select the days you worked
          </span>
        }
        footer={
          <button type="button" className="btn btn-brand" onClick={() => setOpen(false)}>
            Done
          </button>
        }
      >
        {contractLabel && (
          <p className="text-muted small mb-2">
            Your contract runs <span className="fw-semibold">{contractLabel}</span>. Days before your contract started
            (or after it ends) can still be selected to back-fill earlier DTRs — they are marked with a dashed border and
            flagged for the reviewer.
          </p>
        )}

        <div className="d-flex justify-content-between align-items-center mb-3">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary"
            aria-label="Previous month"
            onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}
          >
            <i className="fas fa-chevron-left" />
          </button>
          <div className="fw-semibold">{monthLabel}</div>
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary"
            aria-label="Next month"
            onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}
          >
            <i className="fas fa-chevron-right" />
          </button>
        </div>

        {!monthHasSelectable && (
          <div className="alert alert-warning py-2 px-3 small mb-3">
            <i className="fas fa-triangle-exclamation me-1" />
            Nothing can be picked in {monthLabel} — every day is either in the future or already claimed. Use{" "}
            <i className="fas fa-chevron-left" /> to go back to an earlier month.
          </div>
        )}

        <div className="dtr-cal-grid dtr-cal-grid-head">
          {WEEKDAYS.map((weekday) => (
            <div key={weekday} className="dtr-cal-weekday">
              {weekday}
            </div>
          ))}
        </div>

        <div className="dtr-cal-grid">
          {grid.map((cell) => {
            const classes = ["dtr-cal-cell"];
            if (!cell.inMonth) classes.push("is-outside");
            if (cell.weekend && cell.inMonth) classes.push("is-weekend");
            if (cell.today) classes.push("is-today");
            if (cell.selected) classes.push("is-selected");
            if (cell.taken) classes.push("is-taken");
            if (cell.outsideContract) classes.push("is-outside-contract");
            if (cell.disabled) classes.push("is-disabled");

            return (
              <button
                key={cell.key}
                type="button"
                className={classes.join(" ")}
                disabled={cell.disabled}
                title={
                  cell.taken
                    ? "Already covered by another DTR"
                    : cell.outsideContract
                      ? "Outside your contract dates — will be flagged for review"
                      : cell.selected
                        ? "Click to remove this day"
                        : "Click to select this day"
                }
                onClick={() => toggleDay(cell.key)}
              >
                <span>{cell.day}</span>
              </button>
            );
          })}
        </div>

        <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
          <div className="d-flex flex-wrap gap-2">
            <span className="dtr-legend">
              <span className="dtr-legend-swatch is-selected" /> Selected
            </span>
            <span className="dtr-legend">
              <span className="dtr-legend-swatch is-taken" /> Already claimed
            </span>
            <span className="dtr-legend">
              <span className="dtr-legend-swatch is-weekend" /> Weekend
            </span>
          </div>
          <div className="d-flex gap-2">
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              title="Add every weekday in this month"
              onClick={addWeekdays}
            >
              Add weekdays
            </button>
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => onChange([])}>
              Clear
            </button>
          </div>
        </div>

        <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
          <div className="text-muted small">
            {days.length} day(s) selected · {monthStats.covered} of {monthStats.weekdays} weekdays this month
          </div>
          <div className="text-muted small">Working hours will be assigned by the Admin after review.</div>
        </div>
      </Modal>
    </>
  );
}
