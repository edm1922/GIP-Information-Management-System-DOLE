"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { RegistrationLinkModal } from "@/components/interns/RegistrationLinkModal";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatDate, formatNumber, statusBadgeClass, ucfirst } from "@/lib/format";
import type { Intern, InternsIndexResponse } from "@/lib/types";

const STATUS_OPTIONS = ["active", "inactive", "completed"] as const;

interface ConfirmAction {
  kind: "archive" | "restore" | "force-delete";
  intern: Intern;
}

function messageFor(action: ConfirmAction): { title: string; message: string; submit: string } {
  if (action.kind === "restore") {
    return {
      title: "Restore intern",
      message: `Restore ${action.intern.name}? They will reappear in the active list and their login will be re-enabled.`,
      submit: "Restore",
    };
  }
  if (action.kind === "force-delete") {
    return {
      title: "Permanently delete intern",
      message: `Permanently delete ${action.intern.name}? Their login account and intern record will be removed. This cannot be undone.`,
      submit: "Delete permanently",
    };
  }
  return {
    title: "Archive intern",
    message: `Archive ${action.intern.name}? They will be hidden from the active list and their login will be disabled. You can restore them from the Archived view.`,
    submit: "Archive",
  };
}

/** Intern roster — `interns/index.blade.php`. */
export function InternsView() {
  const { isAdmin, isPesoManager } = useAuth();
  const router = useRouter();
  const searchParams = useSearchParams();

  const staff = isAdmin || isPesoManager;
  const [linkOpen, setLinkOpen] = useState(false);

  const query = searchParams.toString();
  const archived = searchParams.get("archived") === "1";

  const [data, setData] = useState<InternsIndexResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirming, setConfirming] = useState<ConfirmAction | null>(null);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const [draft, setDraft] = useState({
    search: searchParams.get("search") ?? "",
    status: searchParams.get("status") ?? "",
    expiring: searchParams.get("expiring") ?? "",
    partner_agency_id: searchParams.get("partner_agency_id") ?? "",
  });

  const load = useCallback(async () => {
    setError(null);
    try {
      setData(await api.get<InternsIndexResponse>(`/api/v1/interns${query ? `?${query}` : ""}`));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load interns.");
    }
  }, [query]);

  useEffect(() => {
    load();
  }, [load]);

  const go = (params: URLSearchParams) => {
    const search = params.toString();
    router.push(search ? `/interns?${search}` : "/interns");
  };

  const applyFilters = (event: React.FormEvent) => {
    event.preventDefault();
    const params = new URLSearchParams();
    if (archived) params.set("archived", "1");
    Object.entries(draft).forEach(([key, value]) => {
      if (value) params.set(key, value);
    });
    go(params);
  };

  const changePage = (page: number) => {
    const params = new URLSearchParams(query);
    params.set("page", String(page));
    go(params);
  };

  const runAction = async () => {
    if (!confirming) return;
    setBusy(true);
    setActionError(null);
    try {
      if (confirming.kind === "archive") {
        await api.delete(`/api/v1/interns/${confirming.intern.id}`);
      } else if (confirming.kind === "restore") {
        await api.patch(`/api/v1/interns/${confirming.intern.id}/restore`);
      } else {
        await api.delete(`/api/v1/interns/${confirming.intern.id}/force-delete`);
      }
      setConfirming(null);
      await load();
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "The action could not be completed.");
    } finally {
      setBusy(false);
    }
  };

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
        <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
        <div>Loading interns…</div>
      </div>
    );
  }

  const showArchived = data.show_archived;

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">Interns</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Manage the GIP / internship program roster and hour monitoring.
          </p>
        </div>
        <div className="d-flex flex-wrap gap-2">
          {staff && (
            <a className="btn btn-outline-primary d-inline-flex align-items-center gap-2" href="/interns/applications">
              <i className="fas fa-clipboard-check" /> Applications
            </a>
          )}
          {staff && (
            <button
              type="button"
              className="btn btn-outline-secondary d-inline-flex align-items-center gap-2"
              onClick={() => setLinkOpen(true)}
            >
              <i className="fas fa-link" /> Registration Link
            </button>
          )}
          {isAdmin && (
            <button
              type="button"
              className="btn btn-outline-secondary d-inline-flex align-items-center gap-2"
              onClick={() => router.push(showArchived ? "/interns" : "/interns?archived=1")}
            >
              <i className={`fas ${showArchived ? "fa-users" : "fa-box-archive"}`} />
              {showArchived ? "Active Interns" : "Archived"}
            </button>
          )}
          {isAdmin && (
            <a className="btn btn-brand d-inline-flex align-items-center gap-2" href="/interns/create">
              <i className="fas fa-plus" /> Add Intern
            </a>
          )}
        </div>
      </div>

      {showArchived && (
        <div className="alert alert-warning d-flex align-items-center gap-2">
          <i className="fas fa-box-archive" />
          You are viewing archived interns. Restore anyone who returns, or permanently delete records that were created
          in error.
        </div>
      )}

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      <div className="card shadow-sm mb-3">
        <div className="card-body">
          <form className="row g-2 align-items-end" onSubmit={applyFilters}>
            <div className="col-lg-4">
              <label className="form-label" htmlFor="search">
                Search
              </label>
              <input
                id="search"
                type="text"
                className="form-control"
                value={draft.search}
                placeholder="Name or username"
                onChange={(event) => setDraft((current) => ({ ...current, search: event.target.value }))}
              />
            </div>
            <div className="col-lg-2">
              <label className="form-label" htmlFor="status">
                Status
              </label>
              <select
                id="status"
                className="form-select"
                value={draft.status}
                onChange={(event) => setDraft((current) => ({ ...current, status: event.target.value }))}
              >
                <option value="">All</option>
                {STATUS_OPTIONS.map((status) => (
                  <option key={status} value={status}>
                    {ucfirst(status)}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-lg-2">
              <label className="form-label" htmlFor="expiring">
                Contract
              </label>
              <select
                id="expiring"
                className="form-select"
                value={draft.expiring}
                onChange={(event) => setDraft((current) => ({ ...current, expiring: event.target.value }))}
              >
                <option value="">All</option>
                <option value="soon">Expiring ≤ 30 days</option>
                <option value="expired">Expired</option>
              </select>
            </div>
            <div className="col-lg-4">
              <label className="form-label" htmlFor="partner_agency_id">
                Partner Agency
              </label>
              <select
                id="partner_agency_id"
                className="form-select"
                value={draft.partner_agency_id}
                onChange={(event) => setDraft((current) => ({ ...current, partner_agency_id: event.target.value }))}
              >
                <option value="">All</option>
                {data.agencies.map((agency) => (
                  <option key={agency.id} value={agency.id}>
                    {agency.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-12 d-flex gap-2 pt-1">
              <button className="btn btn-primary" type="submit">
                Filter
              </button>
              <button type="button" className="btn btn-outline-secondary" onClick={() => router.push(showArchived ? "/interns?archived=1" : "/interns")}>
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
                <th>Intern</th>
                <th>Partner Agency</th>
                <th>Contract</th>
                <th style={{ width: 180 }}>Hours</th>
                <th>Status</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.interns.data.length === 0 ? (
                <tr>
                  <td colSpan={6} className="text-center text-muted py-5">
                    No interns found.
                  </td>
                </tr>
              ) : (
                data.interns.data.map((intern) => (
                  <tr key={intern.id}>
                    <td>
                      <div className="fw-medium">{intern.name}</div>
                      <div className="text-muted" style={{ fontSize: "0.8rem" }}>
                        {intern.user?.username}
                      </div>
                    </td>
                    <td>{intern.partner_agency?.name ?? "—"}</td>
                    <td style={{ fontSize: "0.82rem" }}>
                      {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
                    </td>
                    <td>
                      <div className="d-flex justify-content-between" style={{ fontSize: "0.8rem" }}>
                        <span>
                          {formatNumber(intern.rendered_hours)} / {formatNumber(intern.required_hours)} hrs
                        </span>
                        <span>{formatNumber(intern.progress_percent)}%</span>
                      </div>
                      <div className="progress" style={{ height: 6 }}>
                        <div className="progress-bar" role="progressbar" style={{ width: `${intern.progress_percent}%` }} />
                      </div>
                    </td>
                    <td>
                      <span className={`badge ${statusBadgeClass(intern.status)}`}>{ucfirst(intern.status)}</span>
                      {showArchived && <span className="badge bg-warning text-dark ms-1">Archived</span>}
                    </td>
                    <td className="text-end text-nowrap">
                      <a className="btn btn-sm btn-outline-secondary" href={`/interns/${intern.id}`} title="View intern">
                        <i className="fas fa-eye" />
                      </a>
                      {showArchived ? (
                        isAdmin && (
                          <>
                            {" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-success"
                              title="Restore intern"
                              onClick={() => setConfirming({ kind: "restore", intern })}
                            >
                              <i className="fas fa-rotate-left" />
                            </button>{" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Permanently delete"
                              onClick={() => setConfirming({ kind: "force-delete", intern })}
                            >
                              <i className="fas fa-trash-can" />
                            </button>
                          </>
                        )
                      ) : (
                        isAdmin && (
                          <>
                            {" "}
                            <a className="btn btn-sm btn-outline-primary" href={`/interns/${intern.id}/edit`} title="Edit intern">
                              <i className="fas fa-pen" />
                            </a>{" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Archive intern"
                              onClick={() => setConfirming({ kind: "archive", intern })}
                            >
                              <i className="fas fa-box-archive" />
                            </button>
                          </>
                        )
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {data.interns.last_page > 1 && (
          <div className="card-footer bg-white">
            <Pagination page={data.interns} onPageChange={changePage} />
          </div>
        )}
      </div>

      <Modal
        show={confirming !== null}
        onClose={() => setConfirming(null)}
        title={confirming ? messageFor(confirming).title : "Confirm"}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setConfirming(null)}>
              Cancel
            </button>
            <button type="button" className="btn btn-danger" disabled={busy} onClick={runAction}>
              {busy ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              {confirming ? messageFor(confirming).submit : "Confirm"}
            </button>
          </>
        }
      >
        {confirming ? messageFor(confirming).message : null}
      </Modal>

      <RegistrationLinkModal show={linkOpen} onClose={() => setLinkOpen(false)} />
    </>
  );
}
