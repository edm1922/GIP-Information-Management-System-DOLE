"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

import { DashboardCharts } from "@/components/dashboard/DashboardCharts";
import { useDocumentDownload, useDocumentPreview } from "@/components/documents/DocumentPreview";
import { WorkCalendar } from "@/components/intern/WorkCalendar";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatNumber, reviewBadgeClass, reviewStatusLabel, ucfirst } from "@/lib/format";
import type { DashboardResponse, InternDocument } from "@/lib/types";

const brandName = process.env.NEXT_PUBLIC_BRAND_NAME ?? "GIP Information Management System (GIMS)";

function formatDate(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString("en-US", { month: "short", day: "2-digit", year: "numeric" });
}

function shiftMonth(dateValue: string, offset: number): string {
  const date = new Date(`${dateValue.slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return new Date().toISOString().slice(0, 10);
  date.setMonth(date.getMonth() + offset, 1);
  return date.toISOString().slice(0, 10);
}

const STAT_CARDS = [
  { key: "total_interns", label: "Total Interns", icon: "fa-users", color: "rgba(30, 66, 155, 0.1)", tint: "#1E429B" },
  { key: "active_interns", label: "Active", icon: "fa-user-check", color: "rgba(16, 185, 129, 0.12)", tint: "#059669" },
  { key: "inactive_interns", label: "Inactive", icon: "fa-user-clock", color: "rgba(156, 163, 175, 0.18)", tint: "#6b7280" },
  { key: "completed_interns", label: "Completed", icon: "fa-graduation-cap", color: "rgba(37, 99, 235, 0.12)", tint: "#2563eb" },
  {
    key: "expiring_soon_count",
    label: "Expiring Soon",
    icon: "fa-hourglass-half",
    color: "rgba(245, 158, 11, 0.15)",
    tint: "#d97706",
  },
] as const;

function internStatusBadgeClass(status: string | null | undefined): string {
  if (status === "active") return "bg-success";
  if (status === "completed") return "bg-info";
  return "bg-secondary";
}

export default function DashboardPage() {
  const { open: openDocument } = useDocumentPreview();
  const downloadDocument = useDocumentDownload();
  const { user } = useAuth();
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [year, setYear] = useState(new Date().getFullYear());
  const [calDate, setCalDate] = useState<string | null>(null);
  const [calendarOpen, setCalendarOpen] = useState(false);
  const [workMonth, setWorkMonth] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const params = new URLSearchParams({ year: String(year) });
      if (calDate) params.set("cal_date", calDate);
      if (workMonth) params.set("work_month", workMonth);
      const payload = await api.get<DashboardResponse>(`/api/v1/dashboard?${params.toString()}`);
      setData(payload);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load the dashboard.");
    }
  }, [year, calDate, workMonth]);

  useEffect(() => {
    load();
  }, [load]);

  const calendar = data?.calendar;
  const years = useMemo(() => {
    const current = new Date().getFullYear();
    return Array.from({ length: 6 }, (_value, index) => current - index);
  }, []);

  if (error) {
    return (
      <div className="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span>{error}</span>
        <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
          Retry
        </button>
      </div>
    );
  }

  if (!data) {
    return (
      <div className="text-center text-muted py-5">
        <i className="fas fa-circle-notch fa-spin fa-2x mb-3" aria-hidden="true" />
        <div>Loading dashboard…</div>
      </div>
    );
  }

  return (
    <>
      <link rel="stylesheet" href="/css/dashboard.css" />

      <div className="d-flex justify-content-between align-items-center mb-4 dashboard-header">
        <div>
          <h2 className="h4 mb-0 fw-bold">Dashboard</h2>
          <small>Welcome to the {brandName}</small>
        </div>

        <div className="d-flex align-items-center gap-3 dashboard-header__controls">
          {user?.role !== "intern" && (
            <select
              className="form-select form-select-sm w-auto"
              value={year}
              onChange={(event) => setYear(Number(event.target.value))}
              aria-label="Reporting year"
              title="Reporting year for the interns-onboarded trend"
            >
              {years.map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          )}

          <div className="calendar-wrapper ms-auto">
            <div
              className="date-pill"
              role="button"
              tabIndex={0}
              onClick={() => setCalendarOpen((value) => !value)}
              onKeyDown={(event) => event.key === "Enter" && setCalendarOpen((value) => !value)}
              title={calendar?.is_current_month ? "Today" : `Showing ${calendar?.month_name} ${calendar?.year}`}
            >
              <i className="fas fa-calendar-alt me-2" style={{ color: "var(--company-primary)" }} />
              <span>{formatDate(calendar?.pill_date)}</span>
            </div>

            {calendarOpen && calendar && (
              <div className="calendar-dropdown">
                <div className="calendar-nav">
                  <button
                    type="button"
                    className="calendar-nav-btn"
                    aria-label="Previous month"
                    onClick={() => setCalDate(shiftMonth(calendar.date, -1))}
                  >
                    <i className="fas fa-chevron-left" />
                  </button>
                  <h4>
                    {calendar.month_name} {calendar.year}
                  </h4>
                  <button
                    type="button"
                    className="calendar-nav-btn"
                    aria-label="Next month"
                    onClick={() => setCalDate(shiftMonth(calendar.date, 1))}
                  >
                    <i className="fas fa-chevron-right" />
                  </button>
                </div>

                <div className="calendar-grid">
                  {["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"].map((weekday) => (
                    <div key={weekday} className="calendar-weekday">
                      {weekday}
                    </div>
                  ))}
                  {calendar.data.map((day, index) => (
                    <div
                      key={`${day.day}-${index}`}
                      className={`calendar-day ${day.current_month ? "current" : "other"} ${day.today ? "today" : ""}`}
                    >
                      {day.day}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {data.show_intern_stats ? (
        <div className="row g-4">
          <div className="col-12">
            <div className="stat-cards-frame">
              <div className="d-flex align-items-center mb-3">
                <h3 className="dashboard-section-title mb-0">
                  <i className="fas fa-user-graduate" style={{ color: "var(--company-primary)" }} /> Internship Program
                </h3>
                <Link href="/interns" className="btn btn-sm btn-outline-secondary ms-auto">
                  Manage Interns
                </Link>
              </div>
              <div className="row g-3">
                {STAT_CARDS.map((card) => (
                  <div key={card.key} className="col-6 col-lg-4 col-xl">
                    <div className="stat-card h-100">
                      <div className="stat-header">
                        <div>
                          <span className="stat-title d-block mb-1">{card.label}</span>
                          <span className="stat-value-large">
                            {Number(data[card.key] ?? 0).toLocaleString()}
                          </span>
                        </div>
                        <div className="stat-icon-circle" style={{ background: card.color, color: card.tint }}>
                          <i className={`fas ${card.icon}`} />
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          <DashboardCharts
            internsTrend={data.interns_trend}
            internStatusDistribution={data.intern_status_distribution}
            partnerAgencyDistribution={data.partner_agency_distribution}
          />

          <div className="col-lg-7">
            <div className="graph-container">
              <div className="d-flex justify-content-between align-items-center mb-3">
                <h3 className="dashboard-section-title mb-0">
                  <i className="fas fa-hourglass-half" style={{ color: "var(--company-primary)" }} /> Contracts
                  Expiring (30 days)
                </h3>
                <Link href="/reports/intern" className="btn btn-sm btn-outline-secondary">
                  Reports
                </Link>
              </div>

              {data.expiring_contracts.length === 0 ? (
                <p className="text-muted small mb-0">No contracts are expiring within the next 30 days.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm align-middle mb-0 contracts-expiring-table">
                    <thead>
                      <tr>
                        <th>Intern</th>
                        <th>Partner Agency</th>
                        <th className="text-end">Ends</th>
                        <th className="text-end">Days Left</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.expiring_contracts.map((contract) => (
                        <tr key={contract.id}>
                          <td className="fw-semibold" data-label="Intern">{contract.name}</td>
                          <td data-label="Partner Agency">{contract.partner_agency?.name ?? "—"}</td>
                          <td className="text-end" data-label="Ends">{formatDate(contract.contract_end_date)}</td>
                          <td className="text-end" data-label="Days Left">
                            <span
                              className={`badge ${
                                contract.days_remaining !== null && contract.days_remaining <= 7
                                  ? "bg-danger"
                                  : "bg-warning text-dark"
                              }`}
                            >
                              {contract.days_remaining !== null ? `${contract.days_remaining}d` : "—"}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </div>
      ) : data.is_intern_user ? (
        <div className="row g-4">
          {data.intern_self ? (
            <InternPortalDashboard data={data} onWorkMonthChange={setWorkMonth} onPreview={openDocument} onDownload={downloadDocument} />
          ) : (
            <div className="col-12">
              <div className="card shadow-sm border-0 mt-2">
                <div className="card-body p-5 text-center">
                  <div className="mb-3">
                    <i className="fas fa-user-clock fa-3x text-warning" />
                  </div>
                  <h4 className="fw-bold text-dark">No Internship Record Assigned Yet</h4>
                  <p className="text-muted mb-3" style={{ maxWidth: 540, margin: "0 auto" }}>
                    Your account is registered as an intern, but your internship details have not been linked by the
                    administrator or PESO manager.
                  </p>
                  <div className="alert alert-info d-inline-block text-start small mb-0">
                    <i className="fas fa-info-circle me-1" /> Please contact your administrator or PESO coordinator to
                    set up your partner agency, required hours, and contract dates.
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div className="card shadow-sm border-0">
          <div className="card-body p-4 text-center text-muted">
            <p className="mb-0">Welcome to {brandName}.</p>
          </div>
        </div>
      )}
    </>
  );
}

/**
 * Intern self-service dashboard — mirrors the `$isInternUser` branch of
 * `resources/views/dashboard.blade.php`: welcome banner, four stat cards, the
 * work calendar from the claimed DTR days, and the five most recent documents.
 */
function InternPortalDashboard({
  data,
  onWorkMonthChange,
  onPreview,
  onDownload,
}: {
  data: DashboardResponse;
  onWorkMonthChange: (month: string) => void;
  onPreview: (document: InternDocument) => void;
  onDownload: (document: InternDocument) => void;
}) {
  const { user } = useAuth();
  const intern = data.intern_self;
  if (!intern) return null;

  const hoursRemaining = Number(intern.hours_remaining ?? 0);
  const wholeDays = Math.floor(Number(intern.days_remaining_from_hours ?? 0));
  const leftoverHours = Math.round((hoursRemaining - wholeDays * 8) * 10) / 10;
  const expiringSoon =
    intern.days_remaining !== null && intern.days_remaining !== undefined && intern.days_remaining >= 0 && intern.days_remaining <= 7;
  const contractMonths = intern.contract_months;

  return (
    <>
      {/* Welcome banner */}
      <div className="col-12">
        <div
          className="card shadow-sm border-0 intern-welcome"
          style={{
            background: "linear-gradient(135deg, #1E429B 0%, #2563eb 100%)",
            color: "#fff",
            borderRadius: 12,
          }}
        >
          <div className="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3 intern-welcome__body">
            <div>
              <div className="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span className="badge bg-white text-primary fw-bold px-2 py-1">Intern Portal</span>
                <span className={`badge ${internStatusBadgeClass(intern.status)}`}>
                  <i className="fas fa-circle me-1" style={{ fontSize: "0.5rem", verticalAlign: "middle" }} />{" "}
                  {ucfirst(intern.status)}
                </span>
                {expiringSoon && (
                  <span className="badge bg-warning text-dark">
                    <i className="fas fa-exclamation-triangle me-1" /> Ends in {intern.days_remaining}d
                  </span>
                )}
              </div>
              <h2 className="h4 fw-bold mb-1 text-white">Welcome back, {intern.name}!</h2>
              <p className="mb-0 text-white-50 small">
                <i className="fas fa-building me-1" /> {intern.partner_agency?.name ?? "Partner Agency: Not Assigned"}
                {intern.supervisor && (
                  <>
                    {" "}
                    &bull; <i className="fas fa-user-tie ms-2 me-1" /> Supervisor: {intern.supervisor}
                  </>
                )}
              </p>
            </div>
            <div>
              <Link href="/my-internship" className="btn btn-light fw-semibold text-primary shadow-sm">
                <i className="fas fa-file-upload me-1" /> Daily Time Record
              </Link>
            </div>
          </div>
        </div>
      </div>

      {/* Stat cards */}
      <div className="col-12">
        <div className="stat-cards-frame">
          <div className="row g-3">
            <div className="col-sm-6 col-xl-3">
              <div className="stat-card h-100 p-3">
                <div className="d-flex justify-content-between align-items-start mb-2">
                  <span className="stat-title">Hours Rendered</span>
                  <div className="stat-icon-circle" style={{ background: "rgba(30, 66, 155, 0.1)", color: "#1E429B" }}>
                    <i className="fas fa-clock" />
                  </div>
                </div>
                <div className="stat-value-large mb-1">
                  {formatNumber(intern.rendered_hours)}{" "}
                  <span className="text-muted fw-normal" style={{ fontSize: "0.9rem" }}>
                    / {formatNumber(intern.required_hours)} hrs
                  </span>
                </div>
                <div className="progress mb-1" style={{ height: 6 }}>
                  <div
                    className="progress-bar bg-primary"
                    role="progressbar"
                    style={{ width: `${Math.min(100, Number(intern.progress_percent ?? 0))}%` }}
                  />
                </div>
                <div className="d-flex justify-content-between text-muted" style={{ fontSize: "0.75rem" }}>
                  <span>Progress</span>
                  <span className="fw-bold text-primary">{formatNumber(intern.progress_percent)}%</span>
                </div>
              </div>
            </div>

            <div className="col-sm-6 col-xl-3">
              <div className="stat-card h-100 p-3">
                <div className="d-flex justify-content-between align-items-start mb-2">
                  <span className="stat-title">Days Remaining</span>
                  <div className="stat-icon-circle" style={{ background: "rgba(245, 158, 11, 0.15)", color: "#d97706" }}>
                    <i className="fas fa-hourglass-half" />
                  </div>
                </div>
                <div className="stat-value-large mb-1">
                  {hoursRemaining <= 0 ? (
                    <>
                      0 <span className="text-muted fw-normal" style={{ fontSize: "0.9rem" }}>days</span>
                    </>
                  ) : (
                    <>
                      {wholeDays}
                      <span className="text-muted fw-normal" style={{ fontSize: "0.9rem" }}>
                        {" "}
                        day{wholeDays === 1 ? "" : "s"}
                      </span>
                      {leftoverHours > 0 && (
                        <span className="text-muted fw-normal" style={{ fontSize: "0.8rem" }}>
                          {" "}
                          {formatNumber(leftoverHours)}h
                        </span>
                      )}
                    </>
                  )}
                </div>
                <div className="text-muted" style={{ fontSize: "0.75rem" }}>
                  {hoursRemaining <= 0 ? (
                    <span className="text-success fw-semibold">
                      <i className="fas fa-check-circle me-1" /> Target achieved
                    </span>
                  ) : (
                    <>{formatNumber(hoursRemaining)} hrs &bull; {formatNumber(intern.hours_per_workday)}-hr working day basis</>
                  )}
                </div>
              </div>
            </div>

            <div className="col-sm-6 col-xl-3">
              <div className="stat-card h-100 p-3">
                <div className="d-flex justify-content-between align-items-start mb-2">
                  <span className="stat-title">Contract Period</span>
                  <div className="stat-icon-circle" style={{ background: "rgba(16, 185, 129, 0.12)", color: "#059669" }}>
                    <i className="fas fa-calendar-check" />
                  </div>
                </div>
                <div className="stat-value-large mb-1" style={{ fontSize: "1.25rem" }}>
                  {contractMonths !== null && contractMonths !== undefined ? (
                    <>
                      {formatNumber(contractMonths)}{" "}
                      <span className="text-muted fw-normal" style={{ fontSize: "0.85rem" }}>
                        month{Number(contractMonths) !== 1 ? "s" : ""}
                      </span>
                    </>
                  ) : (
                    "—"
                  )}
                </div>
                <div className="text-muted" style={{ fontSize: "0.75rem" }}>
                  {intern.contract_start_date && intern.contract_end_date ? (
                    <>
                      {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
                    </>
                  ) : intern.contract_end_date ? (
                    <>Ends {formatDate(intern.contract_end_date)}</>
                  ) : (
                    <>No dates set</>
                  )}
                </div>
              </div>
            </div>

            <div className="col-sm-6 col-xl-3">
              <div className="stat-card h-100 p-3">
                <div className="d-flex justify-content-between align-items-start mb-2">
                  <span className="stat-title">Submitted Documents</span>
                  <div className="stat-icon-circle" style={{ background: "rgba(99, 102, 241, 0.12)", color: "#6366f1" }}>
                    <i className="fas fa-file-invoice" />
                  </div>
                </div>
                <div className="stat-value-large mb-1">
                  {data.intern_stats.total_docs}{" "}
                  <span className="text-muted fw-normal" style={{ fontSize: "0.85rem" }}>files</span>
                </div>
                <div className="d-flex gap-2 flex-wrap" style={{ fontSize: "0.72rem" }}>
                  <span className="text-success fw-semibold">{data.intern_stats.approved_docs} approved</span>
                  &bull;
                  <span className="text-warning fw-semibold">{data.intern_stats.pending_docs} pending</span>
                  {data.intern_stats.rejected_docs > 0 && (
                    <>
                      &bull;
                      <span className="text-danger fw-semibold">{data.intern_stats.rejected_docs} rejected</span>
                    </>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Work calendar: days claimed on submitted DTRs */}
      {data.work_calendar && (
        <div className="col-12">
          <WorkCalendar calendar={data.work_calendar} onMonthChange={onWorkMonthChange} />
        </div>
      )}

      {/* Recent documents */}
      <div className="col-lg-8">
        <div className="graph-container">
          <div className="d-flex justify-content-between align-items-center mb-3">
            <h3 className="dashboard-section-title mb-0">
              <i className="fas fa-file-alt" style={{ color: "var(--company-primary)" }} /> Recent Submitted Documents
            </h3>
            <Link href="/my-internship" className="btn btn-sm btn-outline-primary">
              <i className="fas fa-plus me-1" /> Submit New
            </Link>
          </div>

          {data.intern_documents.length === 0 ? (
            <div className="text-center py-4 text-muted">
              <i className="fas fa-folder-open fa-2x mb-2 text-muted opacity-50" />
              <p className="mb-2 small">You haven&apos;t submitted any internship documents yet.</p>
              <Link href="/my-internship" className="btn btn-sm btn-brand">
                Go to Daily Time Record to Upload
              </Link>
            </div>
          ) : (
            <>
              <div className="table-responsive">
              <table className="table table-sm align-middle mb-0 mobile-stack-table">
                <thead>
                  <tr>
                    <th>Document Type</th>
                    <th>Period</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th className="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {data.intern_documents.map((document) => (
                    <tr key={document.id}>
                      <td className="fw-semibold" data-label="Document Type">
                        <button
                          type="button"
                          className="btn btn-link p-0 text-start text-decoration-none text-dark"
                          title="Click to view inside system"
                          onClick={() => onPreview(document)}
                        >
                          <i className="far fa-file-alt text-primary me-1" />
                          {document.document_type?.name ?? "Document"}
                        </button>
                        <span className="d-block text-muted" style={{ fontSize: "0.72rem" }}>
                          {document.original_filename}
                        </span>
                      </td>
                      <td className="text-muted small" data-label="Period">
                        {document.dtr_summary ?? document.document_period ?? "—"}
                      </td>
                      <td className="text-muted small" data-label="Date">{formatDate(document.created_at)}</td>
                      <td data-label="Status">
                        <span className={`badge ${reviewBadgeClass(document.review_status)}`}>
                          {reviewStatusLabel(document.review_status)}
                        </span>
                      </td>
                      <td className="text-end text-nowrap" data-label="Action">
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-primary"
                          title="View in System"
                          onClick={() => onPreview(document)}
                        >
                          <i className="fas fa-eye" />
                        </button>{" "}
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-secondary"
                          title="Download"
                          onClick={() => onDownload(document)}
                        >
                          <i className="fas fa-download" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
              <div className="mt-3 text-end">
                <Link href="/my-internship/201-files" className="small text-decoration-none fw-semibold">
                  View all documents in 201 Files &rarr;
                </Link>
              </div>
            </>
          )}
        </div>
      </div>

      <div className="col-lg-4">
        <div className="graph-container">
          <h3 className="dashboard-section-title mb-3">
            <i className="fas fa-info-circle" style={{ color: "var(--company-primary)" }} /> Internship Details
          </h3>
          <ul className="list-unstyled mb-3" style={{ fontSize: "0.85rem" }}>
            <li className="mb-2 pb-2 border-bottom">
              <span className="text-muted d-block small">Partner Agency:</span>
              <strong className="text-dark">{intern.partner_agency?.name ?? "—"}</strong>
            </li>
            <li className="mb-2 pb-2 border-bottom">
              <span className="text-muted d-block small">Agency Coordinator / Supervisor:</span>
              <strong className="text-dark">{intern.supervisor ?? "—"}</strong>
            </li>
            <li className="mb-2 pb-2 border-bottom">
              <span className="text-muted d-block small">Contract Duration:</span>
              <strong className="text-dark">
                {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
              </strong>
            </li>
            <li className="mb-0">
              <span className="text-muted d-block small">Account Username:</span>
              <strong className="text-dark">{user?.username ?? "—"}</strong>
            </li>
          </ul>

          <div className="p-3 bg-light rounded-3 border">
            <h6 className="fw-bold mb-1 small text-dark">
              <i className="fas fa-lightbulb text-warning me-1" /> Quick Tip
            </h6>
            <p className="mb-0 text-muted" style={{ fontSize: "0.78rem" }}>
              Ensure your Daily Time Record (DTR) and monthly internship reports are signed before uploading.
            </p>
          </div>
        </div>
      </div>
    </>
  );
}
