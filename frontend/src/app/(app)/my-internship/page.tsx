"use client";

import { useCallback, useEffect, useState } from "react";

import { UploadForm } from "@/components/documents/UploadForm";
import { SelfDocumentsTable } from "@/components/documents/SelfDocumentsTable";
import { ApiError, api } from "@/lib/api";
import { daysFromHours, formatDate, formatNumber, ucfirst } from "@/lib/format";
import type { MyInternshipResponse } from "@/lib/types";

export default function MyInternshipPage() {
  const [data, setData] = useState<MyInternshipResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setData(await api.get<MyInternshipResponse>("/api/v1/my-internship"));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load your Daily Time Records.");
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
        <div>Loading your internship…</div>
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

  const intern = data.intern;
  const documents = data.documents ?? [];
  const documentTypes = data.document_types ?? [];

  return (
    <>
      <div className="mb-4">
        <h2 className="h4 mb-1 fw-bold">Daily Time Record</h2>
        <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
          Submit your DTR, track your rendered hours, and review which days are credited.
        </p>
      </div>

      <div className="row g-3 mb-3">
        <div className="col-lg-4">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
                Hours Rendered
              </h6>
              <div className="d-flex justify-content-between mb-1">
                <span>
                  {formatNumber(intern.rendered_hours)} / {formatNumber(intern.required_hours)} hrs
                </span>
                <span className="fw-bold">{formatNumber(intern.progress_percent)}%</span>
              </div>
              <div className="progress mb-3" style={{ height: 10 }}>
                <div className="progress-bar" role="progressbar" style={{ width: `${intern.progress_percent}%` }} />
              </div>
              <ul className="list-unstyled mb-0" style={{ fontSize: "0.85rem" }}>
                <li className="mb-1">
                  <strong>Status:</strong> {ucfirst(intern.status)}
                </li>
                <li className="mb-1">
                  <strong>Days Remaining:</strong>{" "}
                  {intern.hours_remaining <= 0 ? (
                    <span className="text-success">
                      <i className="fas fa-check-circle me-1" />
                      Completed
                    </span>
                  ) : (
                    <>
                      {daysFromHours(intern.hours_remaining)}
                      <small className="text-muted">
                        {" "}
                        ({formatNumber(intern.hours_remaining)} hrs &bull; 8-hr day)
                      </small>
                    </>
                  )}
                </li>
                <li className="mb-1">
                  <strong>Supervisor:</strong> {intern.supervisor ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>Contract:</strong>{" "}
                  {intern.contract_months !== null ? (
                    <>
                      {formatNumber(intern.contract_months)} month{intern.contract_months !== 1 ? "s" : ""}
                      <small className="text-muted d-block">
                        {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
                      </small>
                    </>
                  ) : (
                    <>
                      {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
                    </>
                  )}
                </li>
                {intern.days_remaining !== null && intern.days_remaining >= 0 && intern.days_remaining <= 7 && (
                  <li className="text-warning">
                    <i className="fas fa-exclamation-triangle" /> Contract ends in {intern.days_remaining} day(s).
                  </li>
                )}
              </ul>
            </div>
          </div>
        </div>

        <div className="col-lg-8">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <div className="d-flex justify-content-between align-items-center mb-3">
                <h6 className="text-uppercase text-muted fw-bold mb-0" style={{ fontSize: "0.75rem" }}>
                  Submit a Daily Time Record
                </h6>
                <a href="/dashboard" className="small text-decoration-none fw-semibold">
                  <i className="fas fa-calendar-days me-1" />
                  View work calendar
                </a>
              </div>
              <UploadForm
                documentTypes={documentTypes}
                takenDays={data.taken_days ?? []}
                contractStart={intern.contract_start_date}
                contractEnd={intern.contract_end_date}
                onUploaded={load}
              />
            </div>
          </div>
        </div>
      </div>

      <SelfDocumentsTable
        title="My Daily Time Records"
        documents={documents}
        emptyMessage="You have not submitted any DTRs yet."
        takenDays={data.taken_days ?? []}
        contractStart={intern.contract_start_date}
        contractEnd={intern.contract_end_date}
        onResubmitted={load}
      />
    </>
  );
}
