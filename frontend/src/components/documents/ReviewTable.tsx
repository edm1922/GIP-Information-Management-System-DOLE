"use client";

import { useState } from "react";

import { DtrDaysTrigger } from "@/components/documents/DtrDaysViewer";
import { useDocumentDownload, useDocumentPreview } from "@/components/documents/DocumentPreview";
import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import { formatDateTime, reviewBadgeClass, ucfirst } from "@/lib/format";
import type { InternDocument } from "@/lib/types";

interface ReviewTableProps {
  documents: InternDocument[];
  /** Shown as a column on the all-documents list; hidden inside an intern folder. */
  showIntern?: boolean;
  emptyMessage?: string;
  canReview: boolean;
  /** Fallback name for the reject dialog when the row has no nested intern. */
  internName?: string;
  onReviewed?: () => void | Promise<void>;
  footer?: React.ReactNode;
}

/**
 * Review table shared by the documents list, the intern folder and the intern
 * profile — the SPA counterpart of `intern-documents/_review-table.blade.php`.
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
  const { open } = useDocumentPreview();
  const download = useDocumentDownload();

  const [busyId, setBusyId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rejecting, setRejecting] = useState<InternDocument | null>(null);
  const [remarks, setRemarks] = useState("");
  const [remarksError, setRemarksError] = useState<string | null>(null);

  const review = async (document: InternDocument, decision: "approved" | "rejected", reviewRemarks?: string) => {
    setBusyId(document.id);
    setError(null);
    try {
      await api.patch(`/api/v1/intern-documents/${document.id}/review`, {
        decision,
        review_remarks: reviewRemarks ?? null,
      });
      setRejecting(null);
      setRemarks("");
      await onReviewed?.();
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "The document could not be updated.");
    } finally {
      setBusyId(null);
    }
  };

  const submitRejection = async () => {
    if (!rejecting) return;
    if (remarks.trim().length < 10) {
      setRemarksError("Please explain in detail why this document is being rejected (at least 10 characters).");
      return;
    }
    setRemarksError(null);
    await review(rejecting, "rejected", remarks.trim());
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
          <table className="table table-hover align-middle mb-0">
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
                  const internLabel =
                    document.intern?.name ??
                    internName ??
                    (showIntern ? "—" : undefined);

                  return (
                    <tr key={document.id}>
                      {showIntern && (
                        <td>
                          {document.intern_id ? (
                            <a href={`/interns/${document.intern_id}`}>{internLabel}</a>
                          ) : (
                            internLabel
                          )}
                        </td>
                      )}
                      <td>{document.document_type?.name ?? "—"}</td>
                      <td style={{ fontSize: "0.82rem" }}>
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
                      <td style={{ fontSize: "0.82rem" }}>
                        {document.dtr_summary ?? document.document_period ?? "—"}
                        <DtrDaysTrigger
                          days={document.dtr_days_payload}
                          title={`${document.document_type?.name ?? "Document"} — claimed working days`}
                        />
                        {outsideDays > 0 && (
                          <span
                            className="badge bg-warning text-dark ms-1"
                            title="Some claimed days fall outside the intern's contract dates — verify against the DTR scan."
                          >
                            <i className="fas fa-triangle-exclamation me-1" />
                            {outsideDays} outside contract
                          </span>
                        )}
                      </td>
                      <td style={{ fontSize: "0.82rem" }}>{formatDateTime(document.created_at)}</td>
                      <td>
                        <span className={`badge ${reviewBadgeClass(document.review_status)}`}>
                          {ucfirst(document.review_status)}
                        </span>
                      </td>
                      <td className="text-end text-nowrap">
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
                        </button>
                        {canReview && document.review_status === "pending" && (
                          <>
                            {" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-success"
                              title="Approve"
                              disabled={busyId === document.id}
                              onClick={() => review(document, "approved")}
                            >
                              <i className={`fas ${busyId === document.id ? "fa-circle-notch fa-spin" : "fa-check"}`} />
                            </button>{" "}
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Reject with a written explanation"
                              onClick={() => {
                                setRejecting(document);
                                setRemarks("");
                                setRemarksError(null);
                              }}
                            >
                              <i className="fas fa-times" />
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
        show={rejecting !== null}
        onClose={() => setRejecting(null)}
        title={
          <span>
            <i className="fas fa-times-circle text-danger me-2" />
            Reject document
            <span className="d-block text-muted small fw-normal">
              {[rejecting?.intern?.name ?? internName, rejecting?.document_type?.name, rejecting?.dtr_summary ?? rejecting?.document_period]
                .filter(Boolean)
                .join(" · ")}
            </span>
          </span>
        }
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setRejecting(null)}>
              Cancel
            </button>
            <button
              type="button"
              className="btn btn-danger"
              disabled={busyId === rejecting?.id}
              onClick={submitRejection}
            >
              <i className="fas fa-times me-1" /> Reject document
            </button>
          </>
        }
      >
        <label className="form-label" htmlFor="reject-remarks">
          Why is this document being rejected? <span className="text-danger">*</span>
        </label>
        <textarea
          id="reject-remarks"
          className={`form-control ${remarksError ? "is-invalid" : ""}`}
          rows={5}
          maxLength={2000}
          value={remarks}
          onChange={(event) => {
            setRemarks(event.target.value);
            setRemarksError(null);
          }}
          placeholder="Explain in detail what is wrong and what the intern needs to correct — e.g. which days do not match the attached DTR, missing pages, unreadable scan, hours exceeding the claimed days…"
        />
        {remarksError ? (
          <div className="text-danger small mt-1">{remarksError}</div>
        ) : (
          <div className="form-text">
            The intern receives this explanation in their notification and can read it on their document list.
          </div>
        )}
      </Modal>
    </>
  );
}
