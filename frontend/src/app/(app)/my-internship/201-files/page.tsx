"use client";

import { useCallback, useEffect, useState } from "react";

import { UploadForm } from "@/components/documents/UploadForm";
import { SelfDocumentsTable } from "@/components/documents/SelfDocumentsTable";
import { ApiError, api } from "@/lib/api";
import type { ChecklistItem, MyInternshipResponse } from "@/lib/types";

function checklistIcon(item: ChecklistItem): { icon: string; color: string; label: string; labelColor: string } {
  switch (item.status) {
    case "approved":
      return { icon: "fas fa-check-circle", color: "text-success", label: "Approved", labelColor: "text-success" };
    case "pending":
      return {
        icon: "fas fa-clock",
        color: "text-warning",
        label: "Submitted — awaiting review",
        labelColor: "text-warning",
      };
    case "returned":
      return {
        icon: "fas fa-times-circle",
        color: "text-danger",
        label: "Rejected — please re-submit",
        labelColor: "text-danger",
      };
    default:
      return item.required
        ? { icon: "fas fa-exclamation-circle", color: "text-danger", label: "Not submitted", labelColor: "text-muted" }
        : { icon: "far fa-circle", color: "text-muted", label: "Not submitted (optional)", labelColor: "text-muted" };
  }
}

export default function MyFilesPage() {
  const [data, setData] = useState<MyInternshipResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setData(await api.get<MyInternshipResponse>("/api/v1/my-internship/201-files"));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load your 201 Files.");
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

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
        <div>Loading your 201 Files…</div>
      </div>
    );
  }

  if (data.unassigned || !data.intern) {
    return (
      <div className="text-center py-5">
        <i className="fas fa-user-clock text-muted mb-3" style={{ fontSize: "3rem", opacity: 0.4 }} />
        <h4 className="fw-bold">No Internship Record Yet</h4>
        <p className="text-muted mb-0">
          Your login account is not yet linked to an intern profile.
          <br />
          Please contact the PESO office so they can set up your internship record.
        </p>
      </div>
    );
  }

  const checklist = data.checklist ?? [];
  const documents = data.documents ?? [];

  return (
    <>
      <div className="mb-4">
        <h2 className="h4 mb-1 fw-bold">201 Files</h2>
        <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
          Submit your Accomplishment Report and other supporting documents for review.
        </p>
      </div>

      <div className="row g-3 mb-3">
        <div className="col-lg-5">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
                Requirements
              </h6>

              {checklist.length === 0 ? (
                <p className="text-muted small mb-0">
                  No documents are required for your internship type right now.
                </p>
              ) : (
                <>
                  <ul className="list-unstyled mb-0">
                    {checklist.map((item) => {
                      const meta = checklistIcon(item);
                      return (
                        <li key={item.type.id} className="d-flex align-items-start gap-2 mb-2">
                          <i className={`${meta.icon} ${meta.color} mt-1`} />
                          <div className="flex-grow-1">
                            <div className="fw-semibold" style={{ fontSize: "0.88rem" }}>
                              {item.type.name}
                              {!item.required && (
                                <span className="badge bg-light text-muted fw-normal ms-1">optional</span>
                              )}
                            </div>
                            <div className={`small ${meta.labelColor}`}>{meta.label}</div>
                          </div>
                        </li>
                      );
                    })}
                  </ul>

                  <p className="text-muted small mb-0 mt-3">
                    <i className="fas fa-info-circle me-1" />
                    Daily Time Records are submitted from the <strong>Daily Time Record</strong> tab.
                  </p>
                </>
              )}
            </div>
          </div>
        </div>

        <div className="col-lg-7">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
                Submit a Document
              </h6>
              <UploadForm documentTypes={data.document_types ?? []} onUploaded={load} />
            </div>
          </div>
        </div>
      </div>

      <SelfDocumentsTable
        title="My 201 Files"
        documents={documents}
        emptyMessage="You have not submitted any 201 files yet."
        onResubmitted={load}
      />
    </>
  );
}
