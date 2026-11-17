"use client";

import Link from "next/link";

import { formatDate, formatNumber } from "@/lib/format";
import type { WorkCalendar as WorkCalendarData, WorkCalendarCell } from "@/lib/types";

const WEEKDAYS = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];

const STATUS_LABELS: Record<"approved" | "pending" | "rejected", string> = {
  approved: "Approved",
  pending: "Pending review",
  rejected: "Rejected",
};

/**
 * Mirrors `partials/intern-work-calendar.blade.php`: the days claimed on the
 * intern's submitted DTRs, with per-status colouring and a month summary.
 */
export function WorkCalendar({
  calendar,
  onMonthChange,
}: {
  calendar: WorkCalendarData;
  onMonthChange: (month: string) => void;
}) {
  const { stats } = calendar;

  return (
    <div className="card shadow-sm border-0">
      <div className="card-body">
        <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
          <div>
            <h6 className="text-uppercase text-muted fw-bold mb-1" style={{ fontSize: "0.75rem" }}>
              <i className="fas fa-calendar-days me-1" style={{ color: "var(--company-primary)" }} />
              Work Calendar
            </h6>
            <p className="text-muted mb-0" style={{ fontSize: "0.8rem" }}>
              Days you claimed on your submitted DTRs. The scan is evidence — these days are your record.
            </p>
          </div>

          <div className="d-flex align-items-center gap-2">
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              aria-label="Previous month"
              onClick={() => onMonthChange(calendar.prev_month)}
            >
              <i className="fas fa-chevron-left" />
            </button>
            <span className="fw-semibold px-1" style={{ minWidth: 130, textAlign: "center" }}>
              {calendar.label}
            </span>
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              aria-label="Next month"
              onClick={() => onMonthChange(calendar.next_month)}
            >
              <i className="fas fa-chevron-right" />
            </button>
          </div>
        </div>

        <div className="row g-3">
          <div className="col-lg-8">
            <div className="work-cal-grid">
              {WEEKDAYS.map((weekday) => (
                <div key={weekday} className="work-cal-weekday">
                  {weekday}
                </div>
              ))}
              {calendar.cells.map((cell) => (
                <div
                  key={cell.date}
                  className={cellClasses(cell)}
                  data-date={cell.date}
                  data-status={cell.status ?? "none"}
                  title={cellTitle(cell)}
                >
                  {cell.day}
                </div>
              ))}
            </div>
          </div>

          <div className="col-lg-4">
            <div className="p-3 rounded-3 h-100" style={{ background: "#f8fafc" }}>
              <div className="mb-3">
                <div
                  className="text-muted"
                  style={{ fontSize: "0.72rem", textTransform: "uppercase", fontWeight: 700 }}
                >
                  This month
                </div>
                <div className="fw-bold fs-4 mb-0">
                  {stats.covered_days}{" "}
                  <span className="text-muted fw-normal" style={{ fontSize: "0.9rem" }}>
                    of {stats.working_days} weekdays
                  </span>
                </div>
                <div className="text-muted" style={{ fontSize: "0.8rem" }}>
                  {formatNumber(stats.hours)} hrs claimed
                </div>
              </div>

              <div className="d-flex flex-column gap-2">
                <span className="work-cal-legend">
                  <span
                    className="work-cal-legend-swatch"
                    style={{ background: "#dcfce7", border: "1px solid #86efac" }}
                  />
                  Approved DTR
                </span>
                <span className="work-cal-legend">
                  <span
                    className="work-cal-legend-swatch"
                    style={{ background: "#fef3c7", border: "1px solid #fcd34d" }}
                  />
                  Pending review
                </span>
                <span className="work-cal-legend">
                  <span
                    className="work-cal-legend-swatch"
                    style={{ background: "#fee2e2", border: "1px solid #fca5a5" }}
                  />
                  Rejected DTR
                </span>
                <span className="work-cal-legend">
                  <span
                    className="work-cal-legend-swatch"
                    style={{
                      background:
                        "repeating-linear-gradient(45deg, #f8fafc, #f8fafc 2px, #eef2f6 2px, #eef2f6 4px)",
                      border: "1px solid #e2e8f0",
                    }}
                  />
                  Outside contract
                </span>
              </div>

              {stats.covered_days === 0 && (
                <div className="alert alert-light border mt-3 mb-0" style={{ fontSize: "0.8rem" }}>
                  <i className="fas fa-info-circle me-1" style={{ color: "var(--company-primary)" }} />
                  No DTR days recorded for this month.{" "}
                  <Link href="/my-internship" className="fw-semibold text-decoration-none">
                    Upload a DTR
                  </Link>{" "}
                  and pick the days you worked.
                </div>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function cellClasses(cell: WorkCalendarCell): string {
  const classes = ["work-cal-cell"];
  if (!cell.in_month) classes.push("is-outside");
  if (cell.weekend) classes.push("is-weekend");
  if (cell.in_month && !cell.in_contract && !cell.status) classes.push("is-outside-contract");
  if (cell.today) classes.push("is-today");
  if (cell.status) classes.push(`is-${cell.status}`);
  return classes.join(" ");
}

function cellTitle(cell: WorkCalendarCell): string {
  const date = formatDate(cell.date);
  if (!cell.status) return date;
  return `${date} · ${STATUS_LABELS[cell.status]} · ${formatNumber(cell.hours)} hrs`;
}
