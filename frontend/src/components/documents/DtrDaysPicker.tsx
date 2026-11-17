"use client";

import { useEffect, useMemo, useRef, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { formatHours, formatNumber } from "@/lib/format";

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
  hours: number | null;
  taken: boolean;
  outsideContract: boolean;
  disabled: boolean;
}

interface DtrDaysPickerProps {
  days: string[];
  hours: Record<string, number>;
  onChange: (days: string[], hours: Record<string, number>) => void;
  /** Days already claimed by another DTR — blocked. */
  taken: string[];
  contractStart: string | null;
  contractEnd: string | null;
}

/**
 * Working-day picker for DTR uploads. Parity with `public/js/dtr-picker.js`:
 * the selected days are the record, the attached scan is the evidence.
 */
export function DtrDaysPicker({ days, hours, onChange, taken, contractStart, contractEnd }: DtrDaysPickerProps) {
  const [open, setOpen] = useState(false);
  const [cursor, setCursor] = useState(() => {
    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), 1);
  });
  const [entryKey, setEntryKey] = useState<string | null>(null);
  const [entryHours, setEntryHours] = useState(8);
  const entryInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (entryKey) {
      entryInputRef.current?.focus();
      entryInputRef.current?.select();
    }
  }, [entryKey]);

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
        hours: hours[key] ?? null,
        taken: takenSet.has(key),
        outsideContract,
        // Only future and already-claimed days are hard-blocked.
        disabled: !inMonth || future || takenSet.has(key),
      };
    });
  }, [cursor, hours, selectedSet, takenSet, todayKey, contractStart, contractEnd]);

  const monthStats = useMemo(() => {
    const weekdays = grid.filter((cell) => cell.inMonth && !cell.weekend).map((cell) => cell.key);
    return {
      weekdays: weekdays.length,
      covered: weekdays.filter((key) => selectedSet.has(key)).length,
    };
  }, [grid, selectedSet]);

  const totalHours = days.reduce((sum, day) => sum + (Number(hours[day]) || 0), 0);
  const monthHasSelectable = grid.some((cell) => cell.inMonth && !cell.disabled);
  const monthLabel = cursor.toLocaleString("en-US", { month: "long", year: "numeric" });
  const contractLabel =
    contractStart || contractEnd ? `${contractStart ?? "—"} to ${contractEnd ?? "—"}` : null;

  const saveEntry = () => {
    if (!entryKey) return;
    const value = Math.min(24, Math.max(0.1, Math.round((Number(entryHours) || 8) * 10) / 10));

    const nextDays = selectedSet.has(entryKey) ? days : [...days, entryKey];
    onChange(nextDays, { ...hours, [entryKey]: value });
    setEntryKey(null);
  };

  const removeEntry = () => {
    if (!entryKey) return;
    const nextHours = { ...hours };
    delete nextHours[entryKey];
    onChange(days.filter((day) => day !== entryKey), nextHours);
    setEntryKey(null);
  };

  const addWeekdays = () => {
    const nextDays = [...days];
    const nextHours = { ...hours };
    grid.forEach((cell) => {
      if (!cell.disabled && !cell.weekend && !nextDays.includes(cell.key)) {
        nextDays.push(cell.key);
        nextHours[cell.key] = 8;
      }
    });
    onChange(nextDays, nextHours);
  };

  const summary =
    days.length === 0
      ? ""
      : [...days].sort().length === 1
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

        {days.length === 0 ? (
          <span className="text-muted small">No days selected yet.</span>
        ) : (
          <span className="badge rounded-pill bg-primary-subtle text-primary-emphasis">
            {days.length} day{days.length !== 1 ? "s" : ""} &bull; {formatNumber(Math.round(totalHours * 10) / 10)} hrs
          </span>
        )}
      </div>

      <div className="form-text mt-1">
        {days.length === 0
          ? "Open the calendar and click each day you worked — you'll enter the hours you rendered for that day."
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
                      : "Click to enter the hours you rendered"
                }
                onClick={() => {
                  setEntryKey(cell.key);
                  setEntryHours(cell.selected ? Number(hours[cell.key]) || 8 : 8);
                }}
              >
                <span>{cell.day}</span>
                {cell.selected && <small className="dtr-cal-hours">{formatHours(hours[cell.key] ?? 0)}</small>}
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
              title="Add every weekday in this month at 8 hrs"
              onClick={addWeekdays}
            >
              Add weekdays
            </button>
            <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => onChange([], {})}>
              Clear
            </button>
          </div>
        </div>

        <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 border-top">
          <div className="text-muted small">
            {days.length} day(s) selected · {monthStats.covered} of {monthStats.weekdays} weekdays this month
          </div>
          <div className="fw-bold" style={{ color: "var(--company-primary)" }}>
            Total: {formatNumber(Math.round(totalHours * 10) / 10)} hrs
          </div>
        </div>

        {entryKey && (
          <div
            className="dtr-entry-layer"
            onClick={(event) => {
              if (event.target === event.currentTarget) setEntryKey(null);
            }}
          >
            <div className="dtr-entry-card">
              <div className="fw-semibold mb-1">{prettyDay(entryKey)}</div>
              <p className="text-muted small mb-2">How many hours did you render on this day?</p>

              <div className="input-group">
                <input
                  ref={entryInputRef}
                  type="number"
                  className="form-control"
                  min={0.1}
                  max={24}
                  step={0.1}
                  value={entryHours}
                  onChange={(event) => setEntryHours(Number(event.target.value))}
                  onKeyDown={(event) => {
                    if (event.key === "Enter") {
                      event.preventDefault();
                      saveEntry();
                    }
                  }}
                />
                <span className="input-group-text">hrs</span>
              </div>

              <div className="d-flex align-items-center mt-3">
                {selectedSet.has(entryKey) && (
                  <button type="button" className="btn btn-sm btn-outline-danger" onClick={removeEntry}>
                    Remove
                  </button>
                )}
                <div className="ms-auto d-flex gap-2">
                  <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setEntryKey(null)}>
                    Cancel
                  </button>
                  <button type="button" className="btn btn-sm btn-brand" onClick={saveEntry}>
                    Save
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}
