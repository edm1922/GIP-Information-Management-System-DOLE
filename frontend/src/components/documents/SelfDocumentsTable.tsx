"use client";

import { DtrDaysTrigger } from "@/components/documents/DtrDaysViewer";
import { useDocumentDownload, useDocumentPreview } from "@/components/documents/DocumentPreview";
import { formatDate, reviewBadgeClass, ucfirst } from "@/lib/format";
import type { InternDocument } from "@/lib/types";

interface SelfDocumentsTableProps {
  title: string;
  documents: InternDocument[];
  emptyMessage: string;
}

/** The intern's own document list (`my-internship/_documents-table.blade.php`). */
export function SelfDocumentsTable({ title, documents, emptyMessage }: SelfDocumentsTableProps) {
  const { open } = useDocumentPreview();
  const download = useDocumentDownload();

  return (
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
                          {ucfirst(document.review_status)}
                        </span>
                      </td>
                      <td style={{ fontSize: "0.82rem" }}>{document.review_remarks ?? "—"}</td>
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
  );
}
