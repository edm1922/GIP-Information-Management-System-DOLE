"use client";

import { useCallback, useEffect, useState } from "react";

import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { baselineName, formatWallClock } from "@/lib/format";
import type { AuditLog, AuditLogsResponse } from "@/lib/types";

const ACTIONS = ["created", "updated", "uploaded", "deleted", "archived", "restored", "login", "logout"] as const;
const IGNORED_CHANGE_KEYS = new Set([
  "id",
  "created_at",
  "updated_at",
  "deleted_at",
  "remember_token",
  "password",
  "folder_id",
  "is_available",
]);

function actionStyle(action: string): { className: string; icon: string } {
  switch (action) {
    case "created":
      return { className: "bg-success-subtle text-success", icon: "fa-plus-circle" };
    case "updated":
    case "uploaded":
      return { className: "bg-info-subtle text-info", icon: action === "uploaded" ? "fa-upload" : "fa-edit" };
    case "deleted":
      return { className: "bg-danger-subtle text-danger", icon: "fa-trash-alt" };
    case "archived":
      return { className: "bg-warning-subtle text-warning", icon: "fa-archive" };
    case "restored":
      return { className: "bg-primary-subtle text-primary", icon: "fa-undo" };
    default:
      return { className: "bg-secondary-subtle text-secondary", icon: "fa-history" };
  }
}

function changeRows(log: AuditLog) {
  const before = log.changes?.before;
  const after = log.changes?.after;
  if (!before && !after) return [];

  return Object.keys(before ?? after ?? {})
    .filter((key) => !IGNORED_CHANGE_KEYS.has(key))
    .map((key) => {
      const previous = before?.[key];
      const next = after?.[key];
      const render = (value: unknown) =>
        value === null || value === undefined || value === ""
          ? "NONE"
          : typeof value === "object"
            ? JSON.stringify(value)
            : String(value);
      return { key, before: render(previous), after: render(next) };
    })
    .filter((row) => row.before !== row.after);
}

export default function AuditLogPage() {
  const { isAdmin, status } = useAuth();

  const [data, setData] = useState<AuditLogsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ action: "all", user_id: "all", date_from: "", date_to: "" });

  const load = useCallback(async () => {
    setError(null);
    try {
      const params = new URLSearchParams();
      if (filters.action !== "all") params.set("action", filters.action);
      if (filters.user_id !== "all") params.set("user_id", filters.user_id);
      if (filters.date_from) params.set("date_from", filters.date_from);
      if (filters.date_to) params.set("date_to", filters.date_to);
      params.set("page", String(page));
      setData(await api.get<AuditLogsResponse>(`/api/v1/reports/audit-log?${params.toString()}`));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load activity logs.");
    }
  }, [filters, page]);

  useEffect(() => {
    if (status === "authenticated" && isAdmin) load();
  }, [status, isAdmin, load]);

  if (status === "authenticated" && !isAdmin) {
    return (
      <div className="alert alert-danger" role="alert">
        You do not have permission to view activity logs.
      </div>
    );
  }

  const update = (key: keyof typeof filters, value: string) => {
    setPage(1);
    setFilters((current) => ({ ...current, [key]: value }));
  };

  return (
    <div className="py-4">
      <div className="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">Audit &amp; Activity Logs</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Track system activities and administrative actions.
          </p>
        </div>

        <div className="d-flex flex-wrap gap-2">
          <select
            className="form-select form-select-sm"
            style={{ width: 160 }}
            aria-label="Action filter"
            value={filters.action}
            onChange={(event) => update("action", event.target.value)}
          >
            <option value="all">All Actions</option>
            {ACTIONS.map((action) => (
              <option key={action} value={action}>
                {action.charAt(0).toUpperCase() + action.slice(1)}
              </option>
            ))}
          </select>

          <select
            className="form-select form-select-sm"
            style={{ width: 190 }}
            aria-label="User filter"
            value={filters.user_id}
            onChange={(event) => update("user_id", event.target.value)}
          >
            <option value="all">All Users</option>
            {(data?.users ?? []).map((user) => (
              <option key={user.id} value={user.id}>
                {user.name}
              </option>
            ))}
          </select>

          <input
            type="date"
            className="form-control form-control-sm"
            style={{ width: 150 }}
            aria-label="From date"
            value={filters.date_from}
            onChange={(event) => update("date_from", event.target.value)}
          />
          <input
            type="date"
            className="form-control form-control-sm"
            style={{ width: 150 }}
            aria-label="To date"
            value={filters.date_to}
            onChange={(event) => update("date_to", event.target.value)}
          />

          {(filters.action !== "all" || filters.user_id !== "all" || filters.date_from || filters.date_to) && (
            <button
              type="button"
              className="btn btn-sm btn-outline-secondary"
              onClick={() => {
                setPage(1);
                setFilters({ action: "all", user_id: "all", date_from: "", date_to: "" });
              }}
            >
              Clear
            </button>
          )}
        </div>
      </div>

      {error && (
        <div className="alert alert-danger d-flex justify-content-between align-items-center">
          <span>{error}</span>
          <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
            Retry
          </button>
        </div>
      )}

      <div className="card shadow-sm">
        <div className="doc-table-wrapper" style={{ border: "none" }}>
          <div className="table-responsive">
            <table className="table doc-table mb-0 align-middle mobile-stack-table">
              <thead>
                <tr>
                  <th className="ps-4">User</th>
                  <th>Action</th>
                  <th>Details</th>
                  <th>Target</th>
                  <th>IP Address</th>
                  <th className="pe-4">Timestamp</th>
                </tr>
              </thead>
              <tbody>
                {!data ? (
                  <tr>
                    <td colSpan={6} className="text-center py-5 text-muted">
                      <i className="fas fa-circle-notch fa-spin me-2" /> Loading activity logs…
                    </td>
                  </tr>
                ) : data.logs.data.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="text-center py-5 text-muted">
                      <i className="fas fa-history d-block mb-3 opacity-25" style={{ fontSize: "3rem" }} />
                      No activity logs found.
                    </td>
                  </tr>
                ) : (
                  data.logs.data.map((log) => {
                    const style = actionStyle(log.action);
                    const rows = changeRows(log);
                    return (
                      <tr key={log.id}>
                        <td className="ps-4" data-label="User">
                          <div className="d-flex align-items-center">
                            <div
                              className="avatar-sm me-3 bg-light rounded-circle d-flex align-items-center justify-content-center"
                              style={{ width: 32, height: 32 }}
                            >
                              <i className="fas fa-user text-secondary" style={{ fontSize: "0.8rem" }} />
                            </div>
                            <div>
                              <div className="fw-bold text-dark">{log.user?.name ?? "System"}</div>
                              <div className="small text-muted text-uppercase" style={{ fontSize: "0.7rem", letterSpacing: "0.5px" }}>
                                {log.user?.role ?? "System"}
                              </div>
                            </div>
                          </div>
                        </td>
                        <td data-label="Action">
                          <span
                            className={`badge rounded-pill ${style.className}`}
                            style={{ fontSize: "0.7rem", padding: "4px 10px", border: "1px solid currentColor" }}
                          >
                            <i className={`fas ${style.icon} me-1`} />
                            {log.action.toUpperCase()}
                          </span>
                        </td>
                        <td data-label="Details" style={{ minWidth: 250 }}>
                          <div className="text-dark fw-medium">{log.clean_description ?? log.description ?? "—"}</div>
                          {rows.length > 0 && (
                            <div className="mt-2 pt-2 border-top" style={{ borderTopColor: "#f3f4f6" }}>
                              {rows.map((row) => (
                                <div
                                  key={row.key}
                                  className="mb-1 d-flex align-items-center flex-wrap"
                                  style={{ fontSize: "0.72rem" }}
                                >
                                  <span
                                    className="text-secondary text-uppercase fw-bold me-1"
                                    style={{ fontSize: "0.62rem", letterSpacing: "0.02em" }}
                                  >
                                    {row.key.replace(/_/g, " ")}:
                                  </span>
                                  <span className="text-muted text-decoration-line-through small">{row.before}</span>
                                  <i className="fas fa-arrow-right mx-1 text-danger opacity-50" style={{ fontSize: "0.6rem" }} />
                                  <span className="text-danger fw-bold">{row.after}</span>
                                </div>
                              ))}
                            </div>
                          )}
                        </td>
                        <td data-label="Target">
                          <div className="fw-bold text-dark">{log.target_name ?? "—"}</div>
                          {log.model_type && (
                            <div className="small text-muted" style={{ fontSize: "0.7rem" }}>
                              {baselineName(log.model_type)}
                            </div>
                          )}
                        </td>
                        <td data-label="IP Address">
                          <code className="small text-muted bg-light px-2 py-1 rounded">{log.ip_address ?? "—"}</code>
                        </td>
                        <td className="pe-4" data-label="Timestamp">
                          <div className="text-dark">{formatWallClock(log.created_at)}</div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>

        {data && data.logs.last_page > 1 && (
          <div className="card-footer bg-white">
            <Pagination page={data.logs} onPageChange={setPage} />
          </div>
        )}
      </div>
    </div>
  );
}
