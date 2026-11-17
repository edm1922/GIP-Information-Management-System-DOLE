"use client";

import { useEffect, useState } from "react";

import { ApiError, api, fetchFile } from "@/lib/api";
import { saveBlob } from "@/lib/files";
import type { Company } from "@/lib/types";

const STATUSES = ["active", "inactive", "completed"] as const;

type ExportKey = "master" | "hours" | "agencies" | "expiry";

export default function InternReportsPage() {
  const [agencies, setAgencies] = useState<Company[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<ExportKey | null>(null);

  const [master, setMaster] = useState({ status: "", expiring: "all", partner_agency_id: "" });
  const [hours, setHours] = useState({ status: "" });
  const [expiry, setExpiry] = useState({ expiry_status: "all" });

  useEffect(() => {
    api
      .get<{ agencies: Company[] }>("/api/v1/reports/intern")
      .then((payload) => setAgencies(payload.agencies))
      .catch((requestError: unknown) =>
        setError(requestError instanceof ApiError ? requestError.message : "Unable to load partner agencies."),
      );
  }, []);

  const exportCsv = async (key: ExportKey, path: string, fallback: string) => {
    setBusy(key);
    setError(null);
    try {
      const { blob, filename } = await fetchFile(path, fallback);
      saveBlob(blob, filename);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "The export could not be prepared.");
    } finally {
      setBusy(null);
    }
  };

  const buttonContent = (key: ExportKey, label: string, icon: string) => (
    <>
      {busy === key ? (
        <span className="spinner-border spinner-border-sm me-2" role="status" />
      ) : (
        <i className={`fas ${icon} me-2`} />
      )}
      <span>{busy === key ? "Preparing CSV..." : label}</span>
    </>
  );

  return (
    <div>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold text-dark">Internship Reports</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Export GIP / internship datasets as CSV.
          </p>
        </div>
      </div>

      {error && (
        <div className="alert alert-danger">
          <i className="fas fa-exclamation-triangle me-2" />
          {error}
        </div>
      )}

      <div className="row g-4">
        <div className="col-12 mt-3">
          <h5 className="fw-bold mb-3 d-flex align-items-center gap-2">
            <i className="fas fa-user-graduate text-brand" /> Internship Program
            <hr className="flex-grow-1 ms-2 opacity-10" />
          </h5>
        </div>

        <div className="col-lg-6">
          <div className="card report-card h-100 shadow-sm border-0">
            <div className="card-body p-4 d-flex flex-column">
              <div className="d-flex align-items-center mb-4">
                <div className="icon-circle bg-brand-light text-brand me-3">
                  <i className="fas fa-list" />
                </div>
                <div>
                  <h5 className="report-title">Intern Master List</h5>
                  <p className="report-desc">Full roster with placement, contract &amp; hours.</p>
                </div>
              </div>

              <form
                onSubmit={(event) => {
                  event.preventDefault();
                  const params = new URLSearchParams();
                  if (master.status) params.set("status", master.status);
                  if (master.expiring) params.set("expiring", master.expiring);
                  if (master.partner_agency_id) params.set("partner_agency_id", master.partner_agency_id);
                  const query = params.size > 0 ? `?${params.toString()}` : "";
                  exportCsv("master", `/api/v1/reports/export/interns${query}`, "intern_master_list.csv");
                }}
              >
                <div className="row g-3 mb-4">
                  <div className="col-6">
                    <label className="report-label" htmlFor="master_status">
                      Status
                    </label>
                    <select
                      id="master_status"
                      className="form-select report-input"
                      value={master.status}
                      onChange={(event) => setMaster({ ...master, status: event.target.value })}
                    >
                      <option value="">All Statuses</option>
                      {STATUSES.map((status) => (
                        <option key={status} value={status}>
                          {status.charAt(0).toUpperCase() + status.slice(1)}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="col-6">
                    <label className="report-label" htmlFor="master_expiring">
                      Contract
                    </label>
                    <select
                      id="master_expiring"
                      className="form-select report-input"
                      value={master.expiring}
                      onChange={(event) => setMaster({ ...master, expiring: event.target.value })}
                    >
                      <option value="all">All</option>
                      <option value="soon">Expiring ≤ 30 days</option>
                      <option value="expired">Expired</option>
                    </select>
                  </div>
                  <div className="col-12">
                    <label className="report-label" htmlFor="master_agency">
                      Partner Agency
                    </label>
                    <select
                      id="master_agency"
                      className="form-select report-input"
                      value={master.partner_agency_id}
                      onChange={(event) => setMaster({ ...master, partner_agency_id: event.target.value })}
                    >
                      <option value="">All Agencies</option>
                      {agencies.map((agency) => (
                        <option key={agency.id} value={agency.id}>
                          {agency.name}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                <div className="mt-auto">
                  <button type="submit" className="btn btn-brand w-100 py-2 d-flex align-items-center justify-content-center" disabled={busy !== null}>
                    {buttonContent("master", "Download Master List", "fa-file-csv")}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div className="col-lg-6">
          <div className="card report-card h-100 shadow-sm border-0">
            <div className="card-body p-4 d-flex flex-column">
              <div className="d-flex align-items-center mb-4">
                <div className="icon-circle bg-success-light text-success me-3">
                  <i className="fas fa-clock" />
                </div>
                <div>
                  <h5 className="report-title">Hours Monitoring Summary</h5>
                  <p className="report-desc">Rendered vs. required hours with totals.</p>
                </div>
              </div>

              <form
                className="d-flex flex-column h-100"
                onSubmit={(event) => {
                  event.preventDefault();
                  const params = new URLSearchParams();
                  if (hours.status) params.set("status", hours.status);
                  const query = params.size > 0 ? `?${params.toString()}` : "";
                  exportCsv("hours", `/api/v1/reports/export/intern-hours${query}`, "intern_hours_summary.csv");
                }}
              >
                <div className="mb-4">
                  <label className="report-label" htmlFor="hours_status">
                    Status
                  </label>
                  <select
                    id="hours_status"
                    className="form-select report-input"
                    value={hours.status}
                    onChange={(event) => setHours({ status: event.target.value })}
                  >
                    <option value="">All Statuses</option>
                    {STATUSES.map((status) => (
                      <option key={status} value={status}>
                        {status.charAt(0).toUpperCase() + status.slice(1)}
                      </option>
                    ))}
                  </select>
                </div>
                <p className="text-muted small mb-4">
                  Per-intern hours rendered, remaining, and completion percentage, with a system-wide total row.
                </p>
                <div className="mt-auto">
                  <button
                    type="submit"
                    className="btn btn-success w-100 py-2 fw-bold d-flex align-items-center justify-content-center shadow-sm"
                    disabled={busy !== null}
                  >
                    {buttonContent("hours", "Download Hours Summary", "fa-file-csv")}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div className="col-lg-6">
          <div className="card report-card h-100 shadow-sm border-0">
            <div className="card-body p-4 d-flex flex-column">
              <div className="d-flex align-items-center mb-4">
                <div className="icon-circle bg-brand-light text-brand me-3">
                  <i className="fas fa-handshake" />
                </div>
                <div>
                  <h5 className="report-title">Partner Agency Summary</h5>
                  <p className="report-desc">Intern headcount &amp; hours per partner agency.</p>
                </div>
              </div>
              <p className="text-muted small mb-4">
                Aggregates intern counts by status and total monitored hours for every partner agency.
              </p>
              <div className="mt-auto">
                <button
                  type="button"
                  className="btn btn-brand w-100 py-2 fw-bold d-flex align-items-center justify-content-center shadow-sm"
                  disabled={busy !== null}
                  onClick={() => exportCsv("agencies", "/api/v1/reports/export/intern-agencies", "intern_agency_summary.csv")}
                >
                  {buttonContent("agencies", "Export Agency Summary", "fa-file-csv")}
                </button>
              </div>
            </div>
          </div>
        </div>

        <div className="col-lg-6">
          <div className="card report-card h-100 shadow-sm border-0">
            <div className="card-body p-4 d-flex flex-column">
              <div className="d-flex align-items-center mb-1">
                <h5 className="report-title fs-5 mb-0">Contract Expiry</h5>
                <span className="badge bg-secondary ms-auto fw-medium">Monitoring</span>
              </div>
              <p className="report-desc mb-4">Interns grouped by contract expiry timeline.</p>

              <form
                className="d-flex flex-column h-100"
                onSubmit={(event) => {
                  event.preventDefault();
                  const params = new URLSearchParams({ expiry_status: expiry.expiry_status });
                  exportCsv(
                    "expiry",
                    `/api/v1/reports/export/intern-expiry?${params.toString()}`,
                    "intern_contract_expiry.csv",
                  );
                }}
              >
                <div className="mb-4">
                  <label className="report-label" htmlFor="expiry_status">
                    Filter by Status
                  </label>
                  <select
                    id="expiry_status"
                    className="form-select report-input"
                    value={expiry.expiry_status}
                    onChange={(event) => setExpiry({ expiry_status: event.target.value })}
                  >
                    <option value="all">All Contracts</option>
                    <option value="expired">Already Expired</option>
                    <option value="expiring">Expiring Soon (Next 30 Days)</option>
                    <option value="valid">Valid (&gt; 30 Days)</option>
                  </select>
                </div>
                <div className="mt-auto">
                  <button
                    type="submit"
                    className="btn btn-secondary w-100 py-2 d-flex align-items-center justify-content-center text-white"
                    disabled={busy !== null}
                  >
                    {buttonContent("expiry", "Download Expiry List", "fa-hourglass-half")}
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
