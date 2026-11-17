"use client";

import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ReviewTable } from "@/components/documents/ReviewTable";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatDate, formatNumber, ucfirst } from "@/lib/format";
import type { InternFolderResponse } from "@/lib/types";

const brandPrimary = process.env.NEXT_PUBLIC_BRAND_PRIMARY ?? "#1E429B";

export default function InternFolderPage() {
  const params = useParams<{ id: string }>();
  const { isAdmin, isPesoManager } = useAuth();

  const [data, setData] = useState<InternFolderResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      setData(await api.get<InternFolderResponse>(`/api/v1/intern-documents/folders/${params.id}`));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load this folder.");
    }
  }, [params.id]);

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
        <div>Loading folder…</div>
      </div>
    );
  }

  const { intern, dtrs, files } = data;
  const pending = [...dtrs, ...files].filter((document) => document.review_status === "pending").length;
  const canReview = isAdmin || isPesoManager;

  return (
    <>
      <nav aria-label="breadcrumb" className="mb-2">
        <ol className="breadcrumb mb-0" style={{ fontSize: "0.82rem" }}>
          <li className="breadcrumb-item">
            <a href="/intern-documents">Intern Documents</a>
          </li>
          <li className="breadcrumb-item">
            <a href="/intern-documents/folders">Folders</a>
          </li>
          <li className="breadcrumb-item active" aria-current="page">
            {intern.name}
          </li>
        </ol>
      </nav>

      <div className="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div className="d-flex align-items-center gap-3">
          <div style={{ fontSize: "2.4rem", lineHeight: 1, color: brandPrimary }}>
            <i className="fas fa-folder-open" />
          </div>
          <div>
            <h2 className="h4 mb-1 fw-bold">{intern.name}</h2>
            <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
              {intern.partner_agency?.name ?? "No partner agency"}
              {intern.supervisor ? ` • Supervisor: ${intern.supervisor}` : ""}
            </p>
          </div>
        </div>
        <div className="d-flex gap-2">
          {intern.user && (
            <a href={`/interns/${intern.id}`} className="btn btn-outline-secondary">
              <i className="fas fa-user me-1" /> Intern profile
            </a>
          )}
          <a href="/intern-documents/folders" className="btn btn-outline-secondary">
            <i className="fas fa-arrow-left me-1" /> Folders
          </a>
        </div>
      </div>

      <div className="card shadow-sm mb-3">
        <div className="card-body">
          <div className="row g-3" style={{ fontSize: "0.85rem" }}>
            <div className="col-sm-6 col-lg-3">
              <div className="text-muted" style={{ fontSize: "0.72rem", textTransform: "uppercase", fontWeight: 700 }}>
                Status
              </div>
              <div className="fw-semibold">{ucfirst(intern.status)}</div>
            </div>
            <div className="col-sm-6 col-lg-3">
              <div className="text-muted" style={{ fontSize: "0.72rem", textTransform: "uppercase", fontWeight: 700 }}>
                Hours credited
              </div>
              <div className="fw-semibold">
                {formatNumber(intern.rendered_hours)} / {formatNumber(intern.required_hours)} hrs (
                {formatNumber(intern.progress_percent)}%)
              </div>
            </div>
            <div className="col-sm-6 col-lg-3">
              <div className="text-muted" style={{ fontSize: "0.72rem", textTransform: "uppercase", fontWeight: 700 }}>
                Contract
              </div>
              <div className="fw-semibold">
                {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
              </div>
            </div>
            <div className="col-sm-6 col-lg-3">
              <div className="text-muted" style={{ fontSize: "0.72rem", textTransform: "uppercase", fontWeight: 700 }}>
                Documents
              </div>
              <div className="fw-semibold">
                {dtrs.length} DTR · {files.length} 201 files
                {pending > 0 && <span className="badge bg-warning text-dark ms-1">{pending} pending</span>}
              </div>
            </div>
          </div>
        </div>
      </div>

      <h6 className="text-uppercase text-muted fw-bold mb-2" style={{ fontSize: "0.75rem" }}>
        <i className="fas fa-clock me-1" />
        Daily Time Records ({dtrs.length})
      </h6>
      <div className="mb-4">
        <ReviewTable
          documents={dtrs}
          showIntern={false}
          emptyMessage="This intern has not submitted any DTRs yet."
          canReview={canReview}
          internName={intern.name}
          onReviewed={load}
        />
      </div>

      <h6 className="text-uppercase text-muted fw-bold mb-2" style={{ fontSize: "0.75rem" }}>
        <i className="fas fa-folder-open me-1" />
        201 Files ({files.length})
      </h6>
      <ReviewTable
        documents={files}
        showIntern={false}
        emptyMessage="This intern has not submitted any 201 files yet."
        canReview={canReview}
        internName={intern.name}
        onReviewed={load}
      />
    </>
  );
}
