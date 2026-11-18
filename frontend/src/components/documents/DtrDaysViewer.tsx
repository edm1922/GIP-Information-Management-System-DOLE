"use client";

import { useMemo, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { formatHours } from "@/lib/format";
import type { DtrDayClaim } from "@/lib/types";

const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];

function pad(value: number): string {
  return String(value).padStart(2, "0");
}

function dateKey(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function monthLabel(year: number, monthIndex: number): string {
  return new Date(year, monthIndex, 1).toLocaleString("en-US", { month: "long", year: "numeric" });
}

function ClaimedMonth({ year, monthIndex, days }: { year: number; monthIndex: number; days: DtrDayClaim[] }) {
  const cells = useMemo(() => {
    const byDate = new Map(days.map((day) => [day.date, day]));
    const first = new Date(year, monthIndex, 1);
    const startOffset = first.getDay();

    return Array.from({ length: 42 }, (_value, index) => {
      const date = new Date(year, monthIndex, 1 - startOffset + index);
      const key = dateKey(date);
      const claim = byDate.get(key);
      const inMonth = date.getMonth() === monthIndex;
      const weekend = date.getDay() === 0 || date.getDay() === 6;

      const classes = ["dtr-view-cell"];
      if (!inMonth) classes.push("is-outside");
      if (weekend) classes.push("is-weekend");
      if (claim) classes.push("is-claimed");
      if (claim?.outside_contract) classes.push("is-outside-contract");

      return { key, day: date.getDate(), classes: classes.join(" "), claim };
    });
  }, [year, monthIndex, days]);

  return (
    <div className="dtr-view-month">
      <div className="dtr-view-month-label">{monthLabel(year, monthIndex)}</div>
      <div className="dtr-view-grid dtr-view-grid-head">
        {WEEKDAYS.map((weekday) => (
          <div key={weekday} className="dtr-view-weekday">
            {weekday}
          </div>
        ))}
      </div>
      <div className="dtr-view-grid">
        {cells.map((cell) => (
          <div
            key={cell.key}
            className={cell.classes}
            title={
              cell.claim
                ? `${cell.key} · ${cell.claim.hours !== null ? formatHours(cell.claim.hours) : "hours pending"}${cell.claim.outside_contract ? " · outside contract" : ""}`
                : undefined
            }
          >
            <span>{cell.day}</span>
            {cell.claim && (
              <small className="dtr-view-hours">
                {cell.claim.hours !== null ? formatHours(cell.claim.hours) : "—"}
              </small>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}

/**
 * Reviewer-facing calendar of the days claimed on a DTR (Blade parity with
 * `public/js/dtr-days-viewer.js`).
 */
export function DtrDaysTrigger({ days, title }: { days: DtrDayClaim[] | undefined; title: string }) {
  const [open, setOpen] = useState(false);
  const claims = days ?? [];

  if (claims.length === 0) return null;

  const assignedHours = claims.filter((day) => day.hours !== null);
  const totalHours = assignedHours.reduce((sum, day) => sum + (Number(day.hours) || 0), 0);
  const outside = claims.filter((day) => day.outside_contract).length;
  const hoursLabel = assignedHours.length === 0 ? "hours pending Admin assignment" : `${formatHours(Math.round(totalHours * 10) / 10)}`;

  const groups = new Map<string, DtrDayClaim[]>();
  claims.forEach((day) => {
    const key = day.date.slice(0, 7);
    groups.set(key, [...(groups.get(key) ?? []), day]);
  });
  const months = [...groups.keys()].sort();

  return (
    <>
      <button
        type="button"
        className="btn btn-sm btn-outline-secondary ms-1"
        onClick={() => setOpen(true)}
        title={`Show the ${claims.length} working day(s) claimed on this DTR`}
      >
        <i className="fas fa-calendar-days" />
      </button>

      <Modal
        show={open}
        onClose={() => setOpen(false)}
        size="lg"
        title={
          <span>
            {title}
            <span className="d-block text-muted small fw-normal">
              {claims.length} day(s) · {hoursLabel}
              {outside ? ` · ${outside} outside contract` : ""}
            </span>
          </span>
        }
        footer={
          <button type="button" className="btn btn-outline-secondary" onClick={() => setOpen(false)}>
            Close
          </button>
        }
      >
        <div className="d-flex flex-wrap gap-3 mb-3">
          <span className="dtr-view-legend">
            <span className="dtr-view-swatch" style={{ background: "#dcfce7", borderColor: "#86efac" }} />
            Claimed day
          </span>
          <span className="dtr-view-legend">
            <span className="dtr-view-swatch" style={{ background: "#fef3c7", borderColor: "#f59e0b", borderStyle: "dashed" }} />
            Outside contract
          </span>
          <span className="dtr-view-legend">
            <span className="dtr-view-swatch" style={{ background: "#f1f5f9" }} />
            Weekend
          </span>
        </div>

        {months.map((month) => {
          const [year, monthNumber] = month.split("-");
          return (
            <ClaimedMonth
              key={month}
              year={Number(year)}
              monthIndex={Number(monthNumber) - 1}
              days={groups.get(month) ?? []}
            />
          );
        })}
      </Modal>
    </>
  );
}
