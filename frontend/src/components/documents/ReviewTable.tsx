"use client";

import { useState } from "react";

import { DocumentHistoryModal } from "@/components/documents/DocumentHistoryModal";
import { DtrDaysTrigger } from "@/components/documents/DtrDaysViewer";
import { DtrHoursAssignmentModal } from "@/components/documents/DtrHoursAssignmentModal";
import { useDocumentDownload, useDocumentPreview } from "@/components/documents/DocumentPreview";
import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatDateTime, reviewBadgeClass, reviewStatusLabel } from "@/lib/format";
import type { InternDocument } from "@/lib/types";

interface ReviewTableProps {
  documents: InternDocument[];
  showIntern?: boolean;
  emptyMessage?: string;
  canReview: boolean;
  internName?: string;
  onReviewed?: () => void | Promise<void>;
  footer?: React.ReactNode;
}

interface ReturnTarget {
  document: InternDocument;
  kind: "peso-return" | "admin-return";
}

/**
 * Review table shared by the documents list, intern folder and intern profile.
 *
 * PESO managers move a submission from pending_peso to pending_admin (or send
 * it back to the intern). Admins assign per-day hours and perform the final
 * approval / return. All actions are enforced again on the backend.
 */
export function ReviewTable({
  documents,
  showIntern = true,
  emptyMessage = "No intern documents found.",
  canReview,
  internName,
  onReviewed,
  footer,
}: ReviewTableProps) {
  const { isAdmin, isPesoManager } = useAuth();
  const { open } = useDocumentPreview();
  const download = useDocumentDownload();

  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [returning, setReturning] = useState<ReturnTarget | null>(null);
  const [remarks, setRemarks] = useState("");
  const [remarksError, setRemarksError] = useState<string | null>(null);
  const [hoursDocument, setHoursDocument] = useState<InternDocument | null>(null);
  const [historyDocument, setHistoryDocument] = useState<InternDocument | null>(null);

  const transition = async (document: InternDocument, action: string, payload: Record<string, unknown> = {}) => {
    setBusyId(document.id);
    setError(null);
    try {
      await api.post(`/api/v1/intern-documents/${document.id}/${action}`, payload);
      await onReviewed?.();
    } catch (requestError) {
      if (requestError instanceof ApiError && Object.keys(requestError.errors).length > 0) {
        setError(Object.values(requestError.errors).flat().join(" "));
      } else {
        setError(requestError instanceof ApiError ? requestError.message : "The document could not be updated.");
      }
    } finally {
      setBusyId(null);
    }
  };

  const submitReturn = async () => {
    if (!returning) return;
    if (remarks.trim().length < 10) {
      setRemarksError("Please explain what needs to be corrected (at least 10 characters).");
      return;
    }
    setRemarksError(null);
    await transition(returning.document, returning.kind, { remarks: remarks.trim() });
    setReturning(null);
    setRemarks("");
  };

  const colspan = showIntern ? 7 : 6;

  return (
    <>
      {error && (
        <div className="alert alert-danger" role="alert">
          {error}
        </div>
      )}

      <div className="card shadow-sm">
        <div className="table-responsive">
          <table className="table table-hover align-middle mb-0 mobile-stack-table">
            <thead>
              <tr>
                {showIntern && <th>Intern</th>}
                <th>Type</th>
                <th>File</th>
                <th>Period</th>
                <th>Uploaded</th>
                <th>Status</th>
                <th className="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              {documents.length === 0 ? (
                <tr>
                  <td colSpan={colspan} className="text-center text-muted py-5">
                    {emptyMessage}
                  </td>
                </tr>
              ) : (
                documents.map((document) => {
                  const outsideDays = document.outside_contract_days_count ?? 0;
                  const internLabel = document.intern?.name ?? internName ?? (showIntern ? "—" : undefined);
                  const status = document.review_status;
                  const canPesoApprove = canReview && isPesoManager && status === "pending_peso";
                  const canPesoReturn = canReview && isPesoManager && ["pending_peso", "returned_to_peso"].includes(status);
                  const canAdmin = canReview && isAdmin && status === "pending_admin";

                  return (
                    <tr key={document.id}>
                      {showIntern && (
                        <td data-label="Intern">
                          {document.intern_id ? (
                            <a href={`/interns/${document.intern_id}`}>{internLabel}</a>
                          ) : (
                            internLabel
                          )}
                        </td>
                      )}
                      <td data-label="Type">{document.document_type?.name ?? "—"}</td>
                      <td data-label="File" style={{ fontSize: "0.82rem" }}>
                        <button
                          type="button"
                          className="btn btn-link p-0 text-start text-decoration-none fw-semibold text-dark"
                          title="Click to view inside system"
                          onClick={() => open(document)}
                        >
                          <i className="far fa-file-alt text-primary me-1" />
                          {document.original_filename}
                        </button>
                      </td>
                      <td data-label="Period" style={{ fontSize: "0.82rem" }}>
                        {document.dtr_summary ?? document.document_period ?? "—"}
                        <DtrDaysTrigger
                          days={document.dtr_days_payload}
                          title={`${document.document_type?.name ?? "Document"} — claimed working days`}
                        />
                        {outsideDays > 0 && (
                          <span
                            className="badge bg-warning text-dark ms-1"
                            title="Some claimed days fall outside the intern's contract dates — verify against the scan."
                          >
                            <i className="fas fa-triangle-exclamation me-1" />
                            {outsideDays} outside contract
                          </span>
                        )}
                      </td>
                      <td data-label="Uploaded" style={{ fontSize: "0.82rem" }}>{formatDateTime(document.created_at)}</td>
                      <td data-label="Status">
                        <span className={`badge ${reviewBadgeClass(status)}`}>{reviewStatusLabel(status)}</span>
                        {status === "pending_admin" && (
                          <div className="small text-muted mt-1">
                            {(document.dtr_days ?? []).some((day) => day.hours === null) ? "Hours pending" : "Hours assigned"}
                          </div>
                        )}
                      </td>
                      <td className="text-end text-nowrap" data-label="Actions">
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-primary"
                          title="View in System"
                          onClick={() => open(document)}
                        >
                          <i className="fas fa-eye" />
                        </button>{" "}
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-secondary"
                          title="Download"
                          onClick={() => download(document)}
                        >
                          <i className="fas fa-download" />
                        </button>{" "}
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-secondary"
                          title="Approval & hours history"
                          onClick={() => setHistoryDocument(document)}
                        >
                          <i className="fas fa-clock-rotate-left" />
                        </button>
                        {canPesoApprove && (
                          <>
                            {" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-success"
                              title="Approve and forward to Admin"
                              disabled={busyId === document.id}
                              onClick={() => transition(document, "peso-approve")}
                            >
                              <i className={`fas ${busyId === document.id ? "fa-circle-notch fa-spin" : "fa-check"}`} /> To Admin
                            </button>
                          </>
                        )}
                        {canAdmin && (
                          <>
                            {" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-primary"
                              title="Assign working hours"
                              onClick={() => setHoursDocument(document)}
                            >
                              <i className="fas fa-clock" /> Hours
                            </button>
                          </>
                        )}
                        {canPesoReturn && (
                          <>
                            {" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Send back to the intern for correction"
                              onClick={() => {
                                setReturning({ document, kind: "peso-return" });
                                setRemarks(document.admin_remarks ?? "");
                                setRemarksError(null);
                              }}
                            >
                              <i className="fas fa-rotate-left" /> Send back
                            </button>
                          </>
                        )}
                        {canAdmin && (
                          <>
                            {" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Return to the PESO manager with remarks"
                              onClick={() => {
                                setReturning({ document, kind: "admin-return" });
                                setRemarks("");
                                setRemarksError(null);
                              }}
                            >
                              <i className="fas fa-rotate-left" /> Return
                            </button>
                          </>
                        )}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {footer && <div className="card-footer bg-white">{footer}</div>}
      </div>

      <Modal
        show={returning !== null}
        onClose={() => setReturning(null)}
        title={returning?.kind === "admin-return" ? "Return to PESO Manager" : "Send back to Intern"}
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setReturning(null)}>
              Cancel
            </button>
            <button type="button" className="btn btn-danger" disabled={busyId !== null} onClick={submitReturn}>
              {busyId !== null ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              {returning?.kind === "admin-return" ? "Return to PESO" : "Send back to Intern"}
            </button>
          </>
        }
      >
        <p className="text-muted small">
          {returning?.kind === "admin-return"
            ? "Explain what the PESO manager / intern needs to correct. The PESO manager will see these remarks and can forward them to the intern."
            : "Review the Admin remarks (shown below) and send the submission back to the intern with any additional instructions."}
        </p>
        <label className="form-label">Remarks</label>
        <textarea
          className="form-control"
          rows={4}
          minLength={10}
          value={remarks}
          onChange={(event) => setRemarks(event.target.value)}
          placeholder="Explain what needs to be corrected…"
        />
        {returning?.kind === "peso-return" && (returning.document.admin_remarks ?? "").length > 0 && (
          <div className="alert alert-warning small mt-2 mb-0">
            <strong>Admin remarks:</strong> {returning.document.admin_remarks}
          </div>
        )}
        {remarksError && <div className="text-danger small mt-1">{remarksError}</div>}
      </Modal>

      <DtrHoursAssignmentModal
        document={hoursDocument}
        show={hoursDocument !== null}
        onClose={() => setHoursDocument(null)}
        onChanged={async () => onReviewed?.()}
      />

      <DocumentHistoryModal
        documentId={historyDocument?.id ?? null}
        show={historyDocument !== null}
        onClose={() => setHistoryDocument(null)}
      />
    </>
  );
}
