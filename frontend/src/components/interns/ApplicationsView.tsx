"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";

import { DEFAULT_REQUIRED_HOURS, INTERN_TYPES, INTERN_STATUSES } from "@/components/interns/InternForm";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatDate, ucfirst } from "@/lib/format";
import type {
  InternApplication,
  InternApplicationResponse,
  InternApplicationsResponse,
  InternsIndexResponse,
} from "@/lib/types";

type Mode = "approve" | "reject" | null;

interface ApproveValues {
  partner_agency_id: string;
  supervisor: string;
  contract_start_date: string;
  contract_end_date: string;
  required_hours: string;
  status: string;
  intern_type: string;
  batch: string;
  beneficiary: string;
}

function statusLabel(status: InternApplication["status"]): string {
  if (status === "pending") return "Pending PESO Review";
  if (status === "pending_admin") return "Awaiting Admin Approval";
  if (status === "approved") return "Approved";
  if (status === "rejected") return "Rejected";
  return status;
}

function statusBadge(status: InternApplication["status"]): string {
  if (status === "approved") return "bg-success";
  if (status === "pending_admin") return "bg-info text-dark";
  if (status === "rejected") return "bg-danger";
  return "bg-warning text-dark";
}

export function ApplicationsView() {
  const { isAdmin, user } = useAuth();
  const isPesoManager = user?.role === "peso_manager";
  const [data, setData] = useState<InternApplicationsResponse | null>(null);
  const [agencies, setAgencies] = useState<InternsIndexResponse["agencies"]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const [status, setStatus] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);

  const [selected, setSelected] = useState<InternApplication | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [mode, setMode] = useState<Mode>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [approve, setApprove] = useState<ApproveValues | null>(null);
  const [suggestedUsername, setSuggestedUsername] = useState("");
  const [rejectReason, setRejectReason] = useState("");

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (status) params.set("status", status);
      if (search.trim()) params.set("search", search.trim());
      if (page > 1) params.set("page", String(page));
      const query = params.toString();
      setData(await api.get<InternApplicationsResponse>(`/api/v1/intern-applications${query ? `?${query}` : ""}`));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load applications.");
    } finally {
      if (!silent) setLoading(false);
    }
  }, [status, search, page]);

  useEffect(() => {
    load();
  }, [load]);

  // Keep the queue fresh while it is open: new applications appear without a
  // manual reload, and the screen refreshes when the tab regains focus.
  useEffect(() => {
    const interval = window.setInterval(() => load(true), 30_000);
    const onFocus = () => load(true);
    const onVisibility = () => {
      if (document.visibilityState === "visible") load(true);
    };

    window.addEventListener("focus", onFocus);
    document.addEventListener("visibilitychange", onVisibility);

    return () => {
      window.clearInterval(interval);
      window.removeEventListener("focus", onFocus);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [load]);

  useEffect(() => {
    api
      .get<InternsIndexResponse>("/api/v1/interns/agencies")
      .then((payload) => setAgencies(payload.agencies))
      .catch(() => setAgencies([]));
  }, []);

  const openReview = async (application: InternApplication) => {
    setSelected(application);
    setDetailLoading(true);
    setMode(null);
    setActionError(null);
    setRejectReason("");
    try {
      const payload = await api.get<InternApplicationResponse>(`/api/v1/intern-applications/${application.id}`);
      setSelected(payload.application);
      setSuggestedUsername(payload.application.approved_username ?? payload.suggested_username ?? "");
      setApprove({
        partner_agency_id: payload.application.partner_agency_id ? String(payload.application.partner_agency_id) : "",
        supervisor: "",
        contract_start_date: payload.application.preferred_start_date ?? "",
        contract_end_date: "",
        required_hours: String(DEFAULT_REQUIRED_HOURS),
        status: "active",
        intern_type: payload.application.intern_type ?? "GIP",
        batch: "",
        beneficiary: "",
      });
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "Unable to load the application.");
    } finally {
      setDetailLoading(false);
    }
  };

  const closeReview = () => {
    setSelected(null);
    setMode(null);
    setApprove(null);
    setActionError(null);
  };

  const submitApprove = async () => {
    if (!selected || !approve) return;
    setBusy(true);
    setActionError(null);
    try {
      const payload = await api.post<{ message: string; application: InternApplication }>(
        `/api/v1/intern-applications/${selected.id}/approve`,
        {
          partner_agency_id: approve.partner_agency_id ? Number(approve.partner_agency_id) : null,
          supervisor: approve.supervisor || null,
          contract_start_date: approve.contract_start_date || null,
          contract_end_date: approve.contract_end_date || null,
          required_hours: Number(approve.required_hours || DEFAULT_REQUIRED_HOURS),
          status: approve.status,
          intern_type: approve.intern_type || null,
          batch: approve.batch || null,
          beneficiary: approve.beneficiary || null,
        },
      );
      closeReview();
      setNotice(payload.message);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError && Object.keys(requestError.errors).length > 0) {
        setActionError(Object.values(requestError.errors).flat().join(" "));
      } else {
        setActionError(requestError instanceof ApiError ? requestError.message : "The application could not be approved.");
      }
    } finally {
      setBusy(false);
    }
  };

  const submitReject = async () => {
    if (!selected) return;
    setBusy(true);
    setActionError(null);
    try {
      const payload = await api.post<{ message: string; application: InternApplication }>(
        `/api/v1/intern-applications/${selected.id}/reject`,
        { reason: rejectReason },
      );
      closeReview();
      setNotice(payload.message);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError && Object.keys(requestError.errors).length > 0) {
        setActionError(Object.values(requestError.errors).flat().join(" "));
      } else {
        setActionError(requestError instanceof ApiError ? requestError.message : "The application could not be rejected.");
      }
    } finally {
      setBusy(false);
    }
  };

  const resendCredentials = async () => {
    if (!selected) return;
    setBusy(true);
    setActionError(null);
    try {
      const payload = await api.post<{ message: string; application: InternApplication }>(
        `/api/v1/intern-applications/${selected.id}/credential-email`,
      );
      setSelected(payload.application);
      setNotice(payload.message);
      await load();
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "The email could not be sent.");
    } finally {
      setBusy(false);
    }
  };

  const pendingCount = data?.pending_count ?? 0;
  const canReviewSelected =
    Boolean(selected) &&
    ((selected?.status === "pending" && isPesoManager) ||
      (selected?.status === "pending_admin" && isAdmin));

  return (
    <>
      <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">
            Intern Applications{" "}
            {pendingCount > 0 && (
              <span className="badge bg-warning text-dark align-middle">
                {pendingCount} {isAdmin ? "awaiting final approval" : "pending"}
              </span>
            )}
          </h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            {isAdmin
              ? "Applications appear here only after a PESO manager has reviewed them."
              : "Applications submitted through the shareable self-registration link. Submitting an application does not send any email — credentials are emailed when you approve."}
          </p>
        </div>
        <div className="d-flex flex-wrap gap-2">
          <button type="button" className="btn btn-outline-secondary" onClick={() => load(true)}>
            <i className="fas fa-rotate-right me-1" /> Refresh
          </button>
          <Link className="btn btn-outline-secondary" href="/interns">
            <i className="fas fa-arrow-left me-1" /> Back to Interns
          </Link>
        </div>
      </div>

      {notice && (
        <div className="alert alert-success alert-dismissible">
          {notice}
          <button type="button" className="btn-close" aria-label="Close" onClick={() => setNotice(null)} />
        </div>
      )}
      {error && <div className="alert alert-danger">{error}</div>}

      <div className="card shadow-sm mb-3">
        <div className="card-body">
          <form
            className="row g-2 align-items-end"
            onSubmit={(event) => {
              event.preventDefault();
              setPage(1);
              load();
            }}
          >
            <div className="col-sm-5">
              <label className="form-label" htmlFor="application-search">
                Search
              </label>
              <input
                id="application-search"
                className="form-control"
                value={search}
                placeholder="Name, email or reference"
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
            <div className="col-sm-3">
              <label className="form-label" htmlFor="application-status">
                Status
              </label>
              <select
                id="application-status"
                className="form-select"
                value={status}
                onChange={(event) => {
                  setStatus(event.target.value);
                  setPage(1);
                }}
              >
                <option value="">All</option>
                {!isAdmin && <option value="pending">Pending PESO Review</option>}
                <option value="pending_admin">Awaiting Admin Approval</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </div>
            <div className="col-sm-4 d-flex gap-2">
              <button className="btn btn-primary" type="submit">
                Filter
              </button>
              <button
                type="button"
                className="btn btn-outline-secondary"
                onClick={() => {
                  setStatus("");
                  setSearch("");
                  setPage(1);
                }}
              >
                Reset
              </button>
            </div>
          </form>
        </div>
      </div>

      <div className="card shadow-sm">
        <div className="table-responsive">
          <table className="table table-hover mb-0 align-middle">
            <thead>
              <tr>
                <th>Applicant</th>
                <th>Email</th>
                <th>Type / Agency</th>
                <th>Submitted</th>
                <th>Status</th>
                <th>Reviewer</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr>
                  <td colSpan={7} className="text-center text-muted py-4">
                    <i className="fas fa-circle-notch fa-spin me-2" /> Loading applications…
                  </td>
                </tr>
              )}
              {!loading && data?.applications.data.length === 0 && (
                <tr>
                  <td colSpan={7} className="text-center text-muted py-4">
                    {isAdmin
                      ? "No PESO-reviewed applications yet. Pending applications are only visible to PESO managers."
                      : "No applications found. Share the registration link from the Interns page."}
                  </td>
                </tr>
              )}
              {!loading &&
                data?.applications.data.map((application) => (
                  <tr key={application.id}>
                    <td>
                      <div className="fw-semibold">{application.name}</div>
                      <div className="text-muted small">{application.reference}</div>
                    </td>
                    <td className="small">{application.email}</td>
                    <td className="small">
                      {application.intern_type ?? "—"}
                      <div className="text-muted">{application.partner_agency?.name ?? "No agency"}</div>
                    </td>
                    <td className="small">{formatDate(application.created_at)}</td>
                    <td>
                      <span className={`badge ${statusBadge(application.status)}`}>{statusLabel(application.status)}</span>
                    </td>
                    <td className="small">{application.reviewer ?? "—"}</td>
                    <td className="text-end">
                      <button className="btn btn-sm btn-outline-primary" onClick={() => openReview(application)}>
                        Review
                      </button>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
        {data && (
          <div className="card-body border-top">
            <Pagination page={data.applications} onPageChange={(next) => setPage(next)} />
          </div>
        )}
      </div>

      <Modal
        show={Boolean(selected)}
        onClose={closeReview}
        title={selected ? `Application ${selected.reference}` : "Application"}
        size="lg"
      >
        {detailLoading && (
          <div className="text-center text-muted py-4">
            <i className="fas fa-circle-notch fa-spin me-2" /> Loading application…
          </div>
        )}

        {selected && !detailLoading && (
          <>
            {actionError && <div className="alert alert-danger py-2">{actionError}</div>}

            <div className="row g-3 mb-3">
              <div className="col-md-6">
                <div className="text-muted small text-uppercase fw-bold">Applicant</div>
                <div className="fw-semibold">{selected.name}</div>
                <div className="small">{selected.email}</div>
                <div className="small text-muted">{selected.contact_number ?? "No contact number"}</div>
              </div>
              <div className="col-md-6">
                <div className="text-muted small text-uppercase fw-bold">Application</div>
                <div className="small">
                  Submitted {formatDate(selected.created_at)} ·{" "}
                  <span className={`badge ${statusBadge(selected.status)}`}>{statusLabel(selected.status)}</span>
                </div>
                <div className="small">Reference: {selected.reference}</div>
                {selected.peso_reviewer && (
                  <div className="small text-muted">
                    PESO: {selected.peso_reviewer} on {formatDate(selected.peso_reviewed_at)}
                  </div>
                )}
                {selected.admin_reviewer && (
                  <div className="small text-muted">
                    Admin: {selected.admin_reviewer} on {formatDate(selected.admin_reviewed_at)}
                  </div>
                )}
              </div>
              <div className="col-md-6">
                <div className="text-muted small text-uppercase fw-bold">Education</div>
                <div className="small">{selected.school ?? "—"}</div>
                <div className="small text-muted">{selected.course ?? ""}</div>
              </div>
              <div className="col-md-6">
                <div className="text-muted small text-uppercase fw-bold">Placement preference</div>
                <div className="small">
                  {selected.intern_type ?? "—"} · {selected.partner_agency?.name ?? "No agency preference"}
                </div>
                {selected.preferred_start_date && (
                  <div className="small text-muted">Preferred start {formatDate(selected.preferred_start_date)}</div>
                )}
              </div>
              {selected.notes && (
                <div className="col-12">
                  <div className="text-muted small text-uppercase fw-bold">Applicant notes</div>
                  <div className="small">{selected.notes}</div>
                </div>
              )}
              {selected.rejection_reason && (
                <div className="col-12">
                  <div className="alert alert-danger py-2 mb-0 small">Rejected: {selected.rejection_reason}</div>
                </div>
              )}
            </div>

            {selected.status === "approved" && (
              <div className="alert alert-info small">
                <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <span>
                    Account: <strong>{selected.approved_username ?? "—"}</strong> · Credentials email:{" "}
                    <strong>{selected.credential_email_status ?? "unknown"}</strong>
                    {selected.credential_email_sent_at && ` (${formatDate(selected.credential_email_sent_at)})`}
                  </span>
                  <button className="btn btn-sm btn-outline-primary" onClick={resendCredentials} disabled={busy}>
                    <i className="fas fa-paper-plane me-1" /> Resend credentials
                  </button>
                </div>
                {selected.credential_email_status === "failed" && selected.credential_email_error && (
                  <div className="text-danger mt-1">{selected.credential_email_error}</div>
                )}
              </div>
            )}

            {selected.status === "pending_admin" && isPesoManager && (
              <div className="alert alert-info small mb-0">
                Endorsed to the Admin for final approval. Credentials are emailed only after the Admin approves.
              </div>
            )}

            {canReviewSelected && mode === null && (
              <div className="d-flex flex-wrap gap-2">
                <button className="btn btn-success" onClick={() => setMode("approve")}>
                  <i className="fas fa-check me-1" />
                  {isAdmin ? "Final approve application" : "Approve & forward to Admin"}
                </button>
                <button className="btn btn-outline-danger" onClick={() => setMode("reject")}>
                  <i className="fas fa-xmark me-1" /> Reject application
                </button>
              </div>
            )}

            {canReviewSelected && mode === "approve" && approve && (
              <form
                onSubmit={(event) => {
                  event.preventDefault();
                  submitApprove();
                }}
              >
                {isAdmin ? (
                  <>
                    <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
                      Final approval — create account &amp; intern record
                    </h6>
                    <div className="row g-2">
                  <div className="col-sm-6">
                    <label className="form-label">Username (auto-generated)</label>
                    <input className="form-control" value={suggestedUsername} readOnly />
                    <div className="form-text">
                      Format: <code>lastname_first2letters</code> (e.g. <code>delacruz_ma</code>). Credentials are
                      emailed only after you approve.
                    </div>
                  </div>
                  <div className="col-sm-6">
                    <label className="form-label">Partner Agency</label>
                    <select
                      className="form-select"
                      value={approve.partner_agency_id}
                      onChange={(event) => setApprove({ ...approve, partner_agency_id: event.target.value })}
                    >
                      <option value="">— None —</option>
                      {agencies.map((agency) => (
                        <option key={agency.id} value={agency.id}>
                          {agency.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="col-sm-6">
                    <label className="form-label">Supervisor</label>
                    <input
                      className="form-control"
                      value={approve.supervisor}
                      onChange={(event) => setApprove({ ...approve, supervisor: event.target.value })}
                    />
                  </div>
                  <div className="col-sm-3">
                    <label className="form-label">Start date</label>
                    <input
                      type="date"
                      className="form-control"
                      value={approve.contract_start_date}
                      onChange={(event) => setApprove({ ...approve, contract_start_date: event.target.value })}
                    />
                  </div>
                  <div className="col-sm-3">
                    <label className="form-label">End date</label>
                    <input
                      type="date"
                      className="form-control"
                      value={approve.contract_end_date}
                      onChange={(event) => setApprove({ ...approve, contract_end_date: event.target.value })}
                    />
                  </div>
                  <div className="col-sm-3">
                    <label className="form-label">Required hours</label>
                    <input
                      type="number"
                      min={1}
                      className="form-control"
                      value={approve.required_hours}
                      onChange={(event) => setApprove({ ...approve, required_hours: event.target.value })}
                    />
                  </div>
                  <div className="col-sm-3">
                    <label className="form-label">Intern type</label>
                    <select
                      className="form-select"
                      value={approve.intern_type}
                      onChange={(event) => setApprove({ ...approve, intern_type: event.target.value })}
                    >
                      {INTERN_TYPES.map((type) => (
                        <option key={type} value={type}>
                          {type}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="col-sm-3">
                    <label className="form-label">Status</label>
                    <select
                      className="form-select"
                      value={approve.status}
                      onChange={(event) => setApprove({ ...approve, status: event.target.value })}
                    >
                      {INTERN_STATUSES.map((option) => (
                        <option key={option} value={option}>
                          {ucfirst(option)}
                        </option>
                      ))}
                    </select>
                  </div>
                  <div className="col-sm-3">
                    <label className="form-label">Batch</label>
                    <input
                      className="form-control"
                      value={approve.batch}
                      onChange={(event) => setApprove({ ...approve, batch: event.target.value })}
                    />
                  </div>
                  <div className="col-sm-6">
                    <label className="form-label">Beneficiary reference</label>
                    <input
                      className="form-control"
                      value={approve.beneficiary}
                      onChange={(event) => setApprove({ ...approve, beneficiary: event.target.value })}
                    />
                  </div>
                    </div>
                  </>
                ) : (
                  <div className="alert alert-info small">
                    First-stage PESO approval only. The application is forwarded to the Admin for final approval — no
                    intern account is created and no credentials are emailed at this stage.
                  </div>
                )}
                <div className="d-flex flex-wrap gap-2 mt-3">
                  <button className="btn btn-success" type="submit" disabled={busy}>
                    {busy ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-check me-1" />}
                    {isAdmin ? "Final approve & email credentials" : "Approve & forward to Admin"}
                  </button>
                  <button type="button" className="btn btn-outline-secondary" onClick={() => setMode(null)} disabled={busy}>
                    Cancel
                  </button>
                </div>
              </form>
            )}

            {canReviewSelected && mode === "reject" && (
              <form
                onSubmit={(event) => {
                  event.preventDefault();
                  submitReject();
                }}
              >
                <label className="form-label">Reason for rejection</label>
                <textarea
                  className="form-control"
                  rows={3}
                  value={rejectReason}
                  onChange={(event) => setRejectReason(event.target.value)}
                  placeholder="Explain what the applicant needs to correct or why the application is rejected."
                  required
                  minLength={10}
                />
                <div className="d-flex flex-wrap gap-2 mt-3">
                  <button className="btn btn-danger" type="submit" disabled={busy || rejectReason.trim().length < 10}>
                    {busy ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-xmark me-1" />}
                    Reject application
                  </button>
                  <button type="button" className="btn btn-outline-secondary" onClick={() => setMode(null)} disabled={busy}>
                    Cancel
                  </button>
                </div>
              </form>
            )}
          </>
        )}
      </Modal>
    </>
  );
}
