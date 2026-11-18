"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { formatDate, timeAgo } from "@/lib/format";
import type { NotificationData, NotificationsResponse } from "@/lib/types";

/**
 * Maps the API's route hints onto SPA paths. Route names are preserved from the
 * Blade app so notifications created by either frontend resolve the same way.
 */
function notificationTarget(data: NotificationData): string | null {
  const params = data.url_params ?? {};
  const param = (key: string) => (params[key] !== undefined ? String(params[key]) : null);

  switch (data.url_route) {
    case "dashboard":
      return "/dashboard";
    case "my-internship.index":
      return "/my-internship";
    case "my-internship.files":
      return "/my-internship/201-files";
    case "interns.index":
      return "/interns";
    case "interns.show":
      return param("intern") ? `/interns/${param("intern")}` : "/interns";
    case "intern-documents.index":
      return "/intern-documents";
    case "intern-documents.folders":
      return "/intern-documents/folders";
    case "intern-documents.folder":
      return param("intern") ? `/intern-documents/folders/${param("intern")}` : "/intern-documents/folders";
    case "notifications.index":
      return "/notifications";
    case "profile.edit":
      return "/profile";
    default:
      return typeof data.url === "string" && data.url.startsWith("/") ? data.url : null;
  }
}

export default function NotificationsPage() {
  const router = useRouter();

  const [data, setData] = useState<NotificationsResponse | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [status, setStatus] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setData(await api.get<NotificationsResponse>(`/api/v1/notifications?page=${page}`));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load notifications.");
    }
  }, [page]);

  useEffect(() => {
    load();
  }, [load]);

  const markAllRead = async () => {
    setBusy("all");
    setStatus(null);
    try {
      const response = await api.post<{ message: string; affected_count: number }>("/api/v1/notifications/mark-all-read");
      setStatus(response.message);
      window.dispatchEvent(new Event("gims:notifications-updated"));
      await load();
    } catch (requestError) {
      setStatus(requestError instanceof ApiError ? requestError.message : "Notifications could not be updated.");
    } finally {
      setBusy(null);
    }
  };

  const markRead = async (id: string) => {
    setBusy(id);
    setStatus(null);
    try {
      const response = await api.post<{ message: string }>(`/api/v1/notifications/${id}/read`);
      setStatus(response.message);
      window.dispatchEvent(new Event("gims:notifications-updated"));
      await load();
    } catch (requestError) {
      setStatus(requestError instanceof ApiError ? requestError.message : "The notification could not be updated.");
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4 mt-2">
        <div>
          <h1 className="h5 mb-1 fw-bold text-dark">Notifications</h1>
          <p className="mb-0 text-muted small">Document expiry reminders and alerts.</p>
        </div>
        {data && data.unread_count > 0 && (
          <button type="button" className="btn btn-sm btn-outline-secondary" onClick={markAllRead} disabled={busy !== null}>
            {busy === "all" ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-check-double me-1" />}
            Mark all as read
          </button>
        )}
      </div>

      {status && (
        <div
          className={`alert ${status.toLowerCase().includes("could not") ? "alert-danger" : "alert-success"} mb-4`}
          role="status"
          aria-live="polite"
        >
          <i className="fas fa-check-circle me-2" />
          {status}
        </div>
      )}

      {error && (
        <div className="alert alert-danger d-flex justify-content-between align-items-center">
          <span>{error}</span>
          <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
            Retry
          </button>
        </div>
      )}

      <section className="notif-list" aria-label="Notification list">
        {!data ? (
          <div className="py-5 text-center text-muted">
            <i className="fas fa-circle-notch fa-spin fa-2x mb-2" />
            <div>Loading notifications…</div>
          </div>
        ) : data.data.length === 0 ? (
          <div className="py-5 text-center px-3">
            <div className="mb-2">
              <i className="far fa-bell-slash fa-2x text-muted" />
            </div>
            <h2 className="h6 mb-1 text-dark">No notifications yet</h2>
            <p className="text-muted mb-0">You will see expiry reminders and document alerts here.</p>
          </div>
        ) : (
          data.data.map((notification) => {
            const isUnread = notification.read_at === null;
            const isExpired = Boolean(notification.data.is_expired);
            const target = notificationTarget(notification.data);

            return (
              <article key={notification.id} className={`notif-item ${isUnread ? "notif-item-unread" : ""}`}>
                <div className="d-flex gap-3 align-items-start">
                  <div className={`notif-dot ${isUnread ? "notif-dot-unread" : ""}`} aria-hidden="true" />

                  <div className="flex-grow-1">
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                      <span className="notif-chip">{isExpired ? "Expired" : "Expiry Reminder"}</span>
                      {notification.data.department_name && (
                        <span className="notif-chip">{notification.data.department_name}</span>
                      )}
                      {isUnread && <span className="badge text-bg-primary">New</span>}
                    </div>

                    <h2 className="mb-1 fw-semibold text-dark" style={{ fontSize: "0.95rem" }}>
                      {notification.data.message ?? "Notification"}
                    </h2>
                    <div className="text-muted" style={{ fontSize: "0.78rem" }}>
                      <i className="far fa-clock me-1" />
                      {timeAgo(notification.created_at)}
                      {notification.data.expiry_date && (
                        <span className="ms-2">
                          <i className="far fa-calendar-alt me-1" />
                          Expiry: {formatDate(notification.data.expiry_date)}
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="notif-actions d-flex flex-column flex-md-row align-items-stretch gap-2">
                    {target ? (
                      <button type="button" className="btn btn-outline-danger" onClick={() => router.push(target)}>
                        <i className="fas fa-arrow-up-right-from-square me-1" /> Open
                      </button>
                    ) : (
                      <button type="button" className="btn btn-outline-secondary" disabled title="No destination for this notification">
                        <i className="fas fa-arrow-up-right-from-square me-1" /> Open
                      </button>
                    )}
                    {isUnread && (
                      <button
                        type="button"
                        className="btn btn-danger"
                        disabled={busy !== null}
                        onClick={() => markRead(notification.id)}
                      >
                        {busy === notification.id ? (
                          <i className="fas fa-circle-notch fa-spin me-1" />
                        ) : (
                          <i className="fas fa-check me-1" />
                        )}
                        Mark as read
                      </button>
                    )}
                  </div>
                </div>
              </article>
            );
          })
        )}
      </section>

      {data && data.last_page > 1 && (
        <div className="mt-3">
          <Pagination page={data} onPageChange={setPage} showSummary={false} />
        </div>
      )}
    </>
  );
}
