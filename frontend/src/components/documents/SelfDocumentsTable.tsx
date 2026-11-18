"use client";

import { useState } from "react";

import { DocumentHistoryModal } from "@/components/documents/DocumentHistoryModal";
import { DtrDaysPicker } from "@/components/documents/DtrDaysPicker";
import { DtrDaysTrigger } from "@/components/documents/DtrDaysViewer";
import { useDocumentDownload, useDocumentPreview } from "@/components/documents/DocumentPreview";
import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import { formatDate, reviewBadgeClass, reviewStatusLabel } from "@/lib/format";
import type { InternDocument } from "@/lib/types";

interface SelfDocumentsTableProps {
  title: string;
  documents: InternDocument[];
  emptyMessage: string;
  takenDays?: string[];
  contractStart?: string | null;
  contractEnd?: string | null;
  onResubmitted?: () => void | Promise<void>;
}

/** The intern's own document list (`my-internship/_documents-table.blade.php`). */
export function SelfDocumentsTable({
  title,
  documents,
  emptyMessage,
  takenDays = [],
  contractStart = null,
  contractEnd = null,
  onResubmitted,
}: SelfDocumentsTableProps) {
  const { open } = useDocumentPreview();
  const download = useDocumentDownload();

  const [resubmitting, setResubmitting] = useState<InternDocument | null>(null);
  const [days, setDays] = useState<string[]>([]);
  const [file, setFile] = useState<File | null>(null);
  const [remarks, setRemarks] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [historyDocument, setHistoryDocument] = useState<InternDocument | null>(null);

  const openResubmit = (document: InternDocument) => {
    setResubmitting(document);
    setDays((document.dtr_days ?? []).map((day) => day.work_date).filter((day): day is string => Boolean(day)));
    setFile(null);
    setRemarks("");
    setError(null);
  };

  const ownDates = new Set(
    (resubmitting?.dtr_days ?? []).map((day) => day.work_date).filter((day): day is string => Boolean(day)),
  );
  const blockers = takenDays.filter((day) => !ownDates.has(day));

  const submit = async () => {
    if (!resubmitting) return;
    setBusy(true);
    setError(null);
    try {
      const formData = new FormData();
      if (resubmitting.document_type?.code === "DTR") {
        if (days.length === 0) {
          setError("Select at least one working day.");
          return;
        }
        days.forEach((day) => formData.append("days[]", day));
      } else if (!file) {
        setError("Attach the corrected document before resubmitting.");
        return;
      }
      if (file) formData.append("file", file);
      if (remarks.trim()) formData.append("remarks", remarks.trim());

      await api.post(`/api/v1/intern-documents/${resubmitting.id}/resubmit`, formData);
      setResubmitting(null);
      await onResubmitted?.();
    } catch (requestError) {
      if (requestError instanceof ApiError && Object.keys(requestError.errors).length > 0) {
        setError(Object.values(requestError.errors).flat().join(" "));
      } else {
        setError(requestError instanceof ApiError ? requestError.message : "The document could not be resubmitted.");
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <div className="card shadow-sm">
        <div className="card-body">
          <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
            {title}
          </h6>
          <div className="table-responsive">
            <table className="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Type</th>
                  <th>File</th>
                  <th>Period</th>
                  <th>Uploaded</th>
                  <th>Status</th>
                  <th>Remarks</th>
                  <th className="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                {documents.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="text-center text-muted py-4">
                      {emptyMessage}
                    </td>
                  </tr>
                ) : (
                  documents.map((document) => {
                    const outsideDays = document.outside_contract_days_count ?? 0;
                    const returned = document.review_status === "returned_to_intern";
                    const remarks =
                      document.admin_remarks ?? document.peso_remarks ?? document.review_remarks ?? "—";

                    return (
                      <tr key={document.id}>
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
                              title="These days fall outside your contract dates; the reviewer will see them flagged."
                            >
                              <i className="fas fa-triangle-exclamation me-1" />
                              {outsideDays} outside contract
                            </span>
                          )}
                        </td>
                        <td style={{ fontSize: "0.82rem" }}>{formatDate(document.created_at)}</td>
                        <td>
                          <span className={`badge ${reviewBadgeClass(document.review_status)}`}>
                            {reviewStatusLabel(document.review_status)}
                          </span>
                        </td>
                        <td style={{ fontSize: "0.82rem", maxWidth: 240 }}>{remarks}</td>
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
                          </button>{" "}
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            title="Approval history"
                            onClick={() => setHistoryDocument(document)}
                          >
                            <i className="fas fa-clock-rotate-left" />
                          </button>
                          {returned && (
                            <>
                              {" "}
                              <button
                                type="button"
                                className="btn btn-sm btn-outline-danger"
                                title="Correct and resubmit"
                                onClick={() => openResubmit(document)}
                              >
                                <i className="fas fa-rotate-right" /> Resubmit
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
        </div>
      </div>

      <Modal
        show={resubmitting !== null}
        onClose={() => setResubmitting(null)}
        size="lg"
        title="Correct and resubmit"
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setResubmitting(null)} disabled={busy}>
              Cancel
            </button>
            <button type="button" className="btn btn-brand" onClick={submit} disabled={busy}>
              {busy ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-paper-plane me-1" />}
              Resubmit for PESO review
            </button>
          </>
        }
      >
        {error && <div className="alert alert-danger py-2">{error}</div>}
        {resubmitting && (
          <>
            <div className="alert alert-warning small">
              <strong>Corrections required.</strong>{" "}
              {resubmitting.admin_remarks ?? resubmitting.peso_remarks ?? resubmitting.review_remarks ?? "See the reviewer remarks."}
            </div>

            {resubmitting.document_type?.code === "DTR" ? (
              <DtrDaysPicker
                days={days}
                onChange={setDays}
                taken={blockers}
                contractStart={contractStart}
                contractEnd={contractEnd}
              />
            ) : (
              <div className="mb-3">
                <label className="form-label">
                  Replacement file <span className="text-danger">*</span>
                </label>
                <input
                  type="file"
                  className="form-control"
                  accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv"
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
              </div>
            )}

            <div className="mt-3">
              <label className="form-label">Optional note to the PESO manager</label>
              <textarea
                className="form-control"
                rows={2}
                maxLength={2000}
                value={remarks}
                onChange={(event) => setRemarks(event.target.value)}
              />
            </div>

            {resubmitting.document_type?.code === "DTR" && (
              <div className="mt-3">
                <label className="form-label">Replace the DTR scan (optional)</label>
                <input
                  type="file"
                  className="form-control"
                  accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv"
                  onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                />
              </div>
            )}
          </>
        )}
      </Modal>

      <DocumentHistoryModal
        documentId={historyDocument?.id ?? null}
        show={historyDocument !== null}
        onClose={() => setHistoryDocument(null)}
      />
    </>
  );
}
