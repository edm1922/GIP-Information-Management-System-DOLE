"use client";

import { useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import { formatDateTime, reviewBadgeClass, reviewStatusLabel } from "@/lib/format";
import type { InternDocument } from "@/lib/types";

interface Props {
  document: InternDocument | null;
  show: boolean;
  onClose: () => void;
  onChanged: () => void | Promise<void>;
}

/** Admin-only per-day hours assignment for a DTR awaiting final approval. */
export function DtrHoursAssignmentModal({ document, show, onClose, onChanged }: Props) {
  const [values, setValues] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState<"draft" | "approve" | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!document) return;
    const next: Record<string, string> = {};
    (document.dtr_days ?? []).forEach((day) => {
      if (day.work_date) next[day.work_date] = day.hours !== null && day.hours !== undefined ? String(day.hours) : "";
    });
    setValues(next);
    setError(null);
  }, [document]);

  if (!document) return null;

  const days = (document.dtr_days ?? []).filter((day) => day.work_date);
  const total = days.reduce((sum, day) => sum + (Number(values[day.work_date as string]) || 0), 0);
  const allAssigned = days.length > 0 && days.every((day) => Number(values[day.work_date as string]) > 0);

  const payload = () => {
    const dayHours: Record<string, number> = {};
    days.forEach((day) => {
      const key = day.work_date as string;
      if (values[key] !== undefined && values[key] !== "") {
        dayHours[key] = Number(values[key]);
      }
    });
    return dayHours;
  };

  const saveDraft = async () => {
    setBusy("draft");
    setError(null);
    try {
      await api.patch(`/api/v1/intern-documents/${document.id}/hours`, { day_hours: payload() });
      await onChanged();
      onClose();
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "The hours could not be saved.");
    } finally {
      setBusy(null);
    }
  };

  const saveAndApprove = async () => {
    setBusy("approve");
    setError(null);
    try {
      await api.patch(`/api/v1/intern-documents/${document.id}/hours`, { day_hours: payload() });
      await api.post(`/api/v1/intern-documents/${document.id}/admin-approve`);
      await onChanged();
      onClose();
    } catch (requestError) {
      if (requestError instanceof ApiError && Object.keys(requestError.errors).length > 0) {
        setError(Object.values(requestError.errors).flat().join(" "));
      } else {
        setError(requestError instanceof ApiError ? requestError.message : "The document could not be approved.");
      }
    } finally {
      setBusy(null);
    }
  };

  return (
    <Modal
      show={show}
      onClose={onClose}
      size="lg"
      title={
        <span>
          <i className="fas fa-clock me-2" style={{ color: "var(--company-primary)" }} />
          Assign working hours
          <span className="d-block text-muted small fw-normal">
            {document.intern?.name ?? "Intern"} · {document.document_type?.name ?? "Document"} ·{" "}
            <span className={`badge ${reviewBadgeClass(document.review_status)}`}>
              {reviewStatusLabel(document.review_status)}
            </span>
          </span>
        </span>
      }
      footer={
        <>
          <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={busy !== null}>
            Cancel
          </button>
          <button type="button" className="btn btn-outline-primary" onClick={saveDraft} disabled={busy !== null}>
            {busy === "draft" ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-save me-1" />}
            Save draft
          </button>
          <button
            type="button"
            className="btn btn-brand"
            onClick={saveAndApprove}
            disabled={busy !== null || !allAssigned}
            title={allAssigned ? "Approve this DTR" : "Assign hours to every selected day first"}
          >
            {busy === "approve" ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-check me-1" />}
            Save &amp; approve
          </button>
        </>
      }
    >
      {error && <div className="alert alert-danger py-2">{error}</div>}

      <p className="text-muted small">
        The intern selected these dates. Enter the hours rendered per day; this is the authoritative record used for the
        intern&apos;s credited hours and reports.
      </p>

      <div className="table-responsive">
        <table className="table table-sm align-middle">
          <thead>
            <tr>
              <th>Working date</th>
              <th style={{ width: 170 }}>Hours</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            {days.map((day) => (
              <tr key={day.id}>
                <td>{formatDay(day.work_date as string)}</td>
                <td>
                  <div className="input-group input-group-sm">
                    <input
                      type="number"
                      min={0.5}
                      max={24}
                      step={0.5}
                      className="form-control"
                      value={values[day.work_date as string] ?? ""}
                      onChange={(event) =>
                        setValues((current) => ({ ...current, [day.work_date as string]: event.target.value }))
                      }
                    />
                    <span className="input-group-text">hrs</span>
                  </div>
                </td>
                <td className="small text-muted">
                  {day.hours !== null && day.hours !== undefined ? `${day.hours} hrs pending` : "Not assigned"}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr>
              <th>Total</th>
              <th>{Math.round(total * 10) / 10} hrs</th>
              <th />
            </tr>
          </tfoot>
        </table>
      </div>

      {!allAssigned && (
        <div className="alert alert-warning py-2 small mb-0">
          Assign hours to every selected date before approving. You can still save a draft and come back later.
        </div>
      )}
    </Modal>
  );
}

function formatDay(value: string): string {
  const [year, month, date] = value.split("-").map(Number);
  return new Date(year, month - 1, date).toLocaleDateString(undefined, {
    weekday: "short",
    year: "numeric",
    month: "short",
    day: "2-digit",
  });
}
