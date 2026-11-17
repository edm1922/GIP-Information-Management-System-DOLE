"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";

import { DashboardCharts } from "@/components/dashboard/DashboardCharts";
import { ApiError, api } from "@/lib/api";
import type { DashboardResponse } from "@/lib/types";

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

export default function DashboardPage() {
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [year, setYear] = useState(new Date().getFullYear());
  const [calDate, setCalDate] = useState<string | null>(null);
  const [calendarOpen, setCalendarOpen] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const params = new URLSearchParams({ year: String(year) });
      if (calDate) params.set("cal_date", calDate);
      const payload = await api.get<DashboardResponse>(`/api/v1/dashboard?${params.toString()}`);
      setData(payload);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load the dashboard.");
    }
  }, [year, calDate]);

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

      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-0 fw-bold">Dashboard</h2>
          <small>Welcome to the {brandName}</small>
        </div>

        <div className="d-flex align-items-center gap-3">
          <select
            className="form-select form-select-sm w-auto"
            value={year}
            onChange={(event) => setYear(Number(event.target.value))}
            aria-label="Reporting year"
          >
            {years.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>

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
                  <table className="table table-sm align-middle mb-0">
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
                          <td className="fw-semibold">{contract.name}</td>
                          <td>{contract.partner_agency?.name ?? "—"}</td>
                          <td className="text-end">{formatDate(contract.contract_end_date)}</td>
                          <td className="text-end">
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
            <>
              <div className="col-12">
                <div
                  className="card shadow-sm border-0"
                  style={{
                    background: "linear-gradient(135deg, #1E429B 0%, #2563eb 100%)",
                    color: "#fff",
                    borderRadius: 12,
                  }}
                >
                  <div className="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                      <div className="d-flex align-items-center gap-2 mb-2">
                        <span className="badge bg-white text-primary fw-bold px-2 py-1">Intern Portal</span>
                        <span className="badge bg-success">
                          <i className="fas fa-circle me-1" style={{ fontSize: "0.5rem", verticalAlign: "middle" }} />
                          {data.intern_self.status ?? "active"}
                        </span>
                      </div>
                      <h2 className="h4 fw-bold mb-1 text-white">Welcome back, {data.intern_self.name}!</h2>
                      <p className="mb-0 text-white-50 small">
                        <i className="fas fa-building me-1" /> {data.intern_self.partner_agency?.name ?? "Partner Agency: Not Assigned"}
                      </p>
                    </div>
                    <Link href="/my-internship" className="btn btn-light fw-semibold text-primary shadow-sm">
                      <i className="fas fa-file-upload me-1" /> Daily Time Record
                    </Link>
                  </div>
                </div>
              </div>

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
                          {data.intern_self.rendered_hours ?? 0}{" "}
                          <span className="text-muted fw-normal" style={{ fontSize: "0.9rem" }}>
                            / {data.intern_self.required_hours ?? 0} hrs
                          </span>
                        </div>
                        <div className="progress mb-1" style={{ height: 6 }}>
                          <div
                            className="progress-bar bg-primary"
                            role="progressbar"
                            style={{ width: `${Math.min(100, data.intern_self.progress_percent ?? 0)}%` }}
                          />
                        </div>
                        <div className="d-flex justify-content-between text-muted" style={{ fontSize: "0.75rem" }}>
                          <span>Progress</span>
                          <span className="fw-bold text-primary">{data.intern_self.progress_percent ?? 0}%</span>
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
                        <div className="stat-value-large mb-1">{data.intern_self.days_remaining ?? "—"}</div>
                      </div>
                    </div>

                    {(
                      [
                        ["total_docs", "Total Documents", "fa-folder-open"],
                        ["approved_docs", "Approved", "fa-check-circle"],
                        ["pending_docs", "Pending Review", "fa-clock"],
                        ["rejected_docs", "Rejected", "fa-times-circle"],
                      ] as const
                    ).map(([key, label, icon]) => (
                      <div key={key} className="col-sm-6 col-xl-3">
                        <div className="stat-card h-100 p-3">
                          <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="stat-title">{label}</span>
                            <div className="stat-icon-circle">
                              <i className={`fas ${icon}`} />
                            </div>
                          </div>
                          <div className="stat-value-large mb-1">{data.intern_stats[key]}</div>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>

              <div className="col-12">
                <div className="graph-container">
                  <div className="d-flex justify-content-between align-items-center mb-3">
                    <h3 className="dashboard-section-title mb-0">
                      <i className="fas fa-file-signature" style={{ color: "var(--company-primary)" }} /> Recent Documents
                    </h3>
                    <Link href="/my-internship/201-files" className="small fw-semibold">
                      View all documents in 201 Files →
                    </Link>
                  </div>

                  {data.intern_documents.length === 0 ? (
                    <p className="text-muted small mb-0">No documents uploaded yet.</p>
                  ) : (
                    <div className="table-responsive">
                      <table className="table table-sm align-middle mb-0">
                        <thead>
                          <tr>
                            <th>Document</th>
                            <th>Status</th>
                            <th className="text-end">Uploaded</th>
                          </tr>
                        </thead>
                        <tbody>
                          {data.intern_documents.map((document) => (
                            <tr key={document.id}>
                              <td className="fw-semibold">
                                {document.document_type?.name ?? document.title ?? `Document #${document.id}`}
                              </td>
                              <td>
                                <span
                                  className={`badge text-capitalize ${
                                    document.review_status === "approved"
                                      ? "bg-success"
                                      : document.review_status === "rejected"
                                        ? "bg-danger"
                                        : "bg-warning text-dark"
                                  }`}
                                >
                                  {document.review_status.replace("_", " ")}
                                </span>
                              </td>
                              <td className="text-end">{formatDate(document.created_at)}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              </div>
            </>
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
