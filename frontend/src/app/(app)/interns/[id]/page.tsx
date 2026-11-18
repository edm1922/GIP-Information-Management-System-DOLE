"use client";

import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ReviewTable } from "@/components/documents/ReviewTable";
import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { daysFromHours, formatDate, formatNumber, ucfirst } from "@/lib/format";
import type { Intern } from "@/lib/types";

export default function InternDetailPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const { isAdmin, isPesoManager } = useAuth();

  const [intern, setIntern] = useState<Intern | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmingArchive, setConfirmingArchive] = useState(false);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const payload = await api.get<{ intern: Intern }>(`/api/v1/interns/${params.id}`);
      setIntern(payload.intern);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load this intern.");
    }
  }, [params.id]);

  useEffect(() => {
    load();
  }, [load]);

  const archive = async () => {
    setBusy(true);
    setActionError(null);
    try {
      await api.delete(`/api/v1/interns/${params.id}`);
      router.push("/interns");
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "The intern could not be archived.");
      setBusy(false);
    }
  };

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

  if (!intern) {
    return (
      <div className="text-center text-muted py-5">
        <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
        <div>Loading intern…</div>
      </div>
    );
  }

  const canReview = isAdmin || isPesoManager;

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">{intern.name}</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            {intern.intern_type ?? "Intern"}
            {intern.batch ? ` · Batch ${intern.batch}` : ""}
          </p>
        </div>
        <div className="d-flex gap-2">
          <a className="btn btn-outline-secondary" href="/interns">
            <i className="fas fa-arrow-left" /> Back
          </a>
          {isAdmin && (
            <>
              <a className="btn btn-brand" href={`/interns/${intern.id}/edit`}>
                <i className="fas fa-pen" /> Edit
              </a>
              <button type="button" className="btn btn-outline-danger" onClick={() => setConfirmingArchive(true)}>
                <i className="fas fa-box-archive" /> Archive
              </button>
            </>
          )}
        </div>
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      <div className="row g-3">
        <div className="col-lg-4">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
                Progress
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
                  <strong>Hours remaining:</strong> {formatNumber(intern.hours_remaining)}
                  {intern.hours_remaining > 0 ? ` (${daysFromHours(intern.hours_remaining)})` : ""}
                </li>
                <li className="mb-1">
                  <strong>Partner Agency:</strong> {intern.partner_agency?.name ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>Supervisor:</strong> {intern.supervisor ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>School:</strong> {intern.school ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>Course:</strong> {intern.course ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>Contact:</strong> {intern.contact_number ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>Beneficiary:</strong> {intern.beneficiary ?? "—"}
                </li>
                <li className="mb-1">
                  <strong>Contract:</strong> {formatDate(intern.contract_start_date)} &ndash; {formatDate(intern.contract_end_date)}
                </li>
                <li className="mb-1">
                  <strong>Email:</strong> {intern.user?.email ?? "-"}
                </li>
                <li>
                  <strong>Username:</strong> <code>{intern.user?.username}</code>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <div className="col-lg-8">
          <div className="card shadow-sm h-100">
            <div className="card-body">
              <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
                Submitted Documents
              </h6>

              <ReviewTable
                documents={intern.intern_documents ?? []}
                showIntern={false}
                emptyMessage="No documents submitted yet."
                canReview={canReview}
                internName={intern.name}
                onReviewed={load}
              />
            </div>
          </div>
        </div>
      </div>

      <Modal
        show={confirmingArchive}
        onClose={() => setConfirmingArchive(false)}
        title="Archive intern"
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setConfirmingArchive(false)}>
              Cancel
            </button>
            <button type="button" className="btn btn-danger" disabled={busy} onClick={archive}>
              {busy ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              Archive
            </button>
          </>
        }
      >
        Archive {intern.name}? They will be hidden from the active list and their login will be disabled. You can restore
        them from the Archived view on the interns list.
      </Modal>
    </>
  );
}
