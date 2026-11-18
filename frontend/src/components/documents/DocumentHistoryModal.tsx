"use client";

import { useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import { formatDateTime, reviewStatusLabel } from "@/lib/format";
import type { InternDocumentReviewEvent } from "@/lib/types";

interface Props {
  documentId: number | null;
  show: boolean;
  onClose: () => void;
}

const ACTION_LABELS: Record<string, string> = {
  uploaded: "Submitted",
  peso_approved: "PESO Manager approved",
  peso_returned: "PESO Manager sent back to intern",
  admin_approved: "Admin approved",
  admin_returned: "Admin returned to PESO Manager",
  resubmitted: "Intern resubmitted",
  hours_saved: "Admin saved working hours",
};

export function DocumentHistoryModal({ documentId, show, onClose }: Props) {
  const [events, setEvents] = useState<InternDocumentReviewEvent[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!show || !documentId) return;
    setEvents(null);
    setError(null);
    api
      .get<{ events: InternDocumentReviewEvent[] }>(`/api/v1/intern-documents/${documentId}/history`)
      .then((payload) => setEvents(payload.events))
      .catch((requestError) => setError(requestError instanceof ApiError ? requestError.message : "Unable to load history."));
  }, [show, documentId]);

  return (
    <Modal show={show} onClose={onClose} size="lg" title="Approval & working-hours history">
      {error && <div className="alert alert-danger py-2">{error}</div>}
      {!events && !error && (
        <div className="text-center text-muted py-4">
          <i className="fas fa-circle-notch fa-spin me-2" /> Loading history…
        </div>
      )}
      {events && events.length === 0 && <div className="text-muted">No history recorded yet.</div>}
      {events && events.length > 0 && (
        <ol className="list-unstyled mb-0">
          {events.map((event) => (
            <li key={event.id} className="border-start border-3 ps-3 pb-3" style={{ borderColor: "var(--company-primary)" }}>
              <div className="d-flex flex-wrap justify-content-between gap-2">
                <strong>{ACTION_LABELS[event.action] ?? event.action}</strong>
                <span className="text-muted small">{formatDateTime(event.created_at)}</span>
              </div>
              <div className="small text-muted">
                {event.actor ? `${event.actor} (${(event.actor_role ?? "").replace("_", " ")})` : "System"}
                {event.from_status && event.to_status && (
                  <>
                    {" "}
                    · {reviewStatusLabel(event.from_status)} → {reviewStatusLabel(event.to_status)}
                  </>
                )}
              </div>
              {event.remarks && <div className="small mt-1">“{event.remarks}”</div>}
              {event.action === "hours_saved" && event.metadata?.hours ? (
                <div className="small text-muted mt-1">
                  {Object.entries(event.metadata.hours as Record<string, number>)
                    .map(([date, hours]) => `${date}: ${hours}h`)
                    .join(" · ")}
                </div>
              ) : null}
            </li>
          ))}
        </ol>
      )}
    </Modal>
  );
}
