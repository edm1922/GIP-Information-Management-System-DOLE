"use client";

import { useRef, useState } from "react";

import { DtrDaysPicker } from "@/components/documents/DtrDaysPicker";
import { ApiError, api } from "@/lib/api";
import type { DocumentType } from "@/lib/types";

interface UploadFormProps {
  documentTypes: DocumentType[];
  takenDays?: string[];
  contractStart?: string | null;
  contractEnd?: string | null;
  /** Admin upload on behalf of an intern; interns upload for themselves. */
  internId?: number;
  onUploaded?: () => void | Promise<void>;
}

const ACCEPT = ".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv";

/** Upload form shared by the DTR and 201 Files tabs (`_upload-form.blade.php`). */
export function UploadForm({
  documentTypes,
  takenDays = [],
  contractStart = null,
  contractEnd = null,
  internId,
  onUploaded,
}: UploadFormProps) {
  const fileRef = useRef<HTMLInputElement | null>(null);

  const [typeId, setTypeId] = useState("");
  const [period, setPeriod] = useState("");
  const [days, setDays] = useState<string[]>([]);
  const [hours, setHours] = useState<Record<string, number>>({});
  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const selectedType = documentTypes.find((type) => String(type.id) === typeId) ?? null;
  const isDtr = selectedType?.code === "DTR";
  const offersDtr = documentTypes.some((type) => type.code === "DTR");

  const reset = () => {
    setTypeId("");
    setPeriod("");
    setDays([]);
    setHours({});
    setFile(null);
    if (fileRef.current) fileRef.current.value = "";
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);
    setErrors({});

    if (!typeId) {
      setErrors({ document_type_id: ["Please select a document type."] });
      return;
    }
    if (!file) {
      setErrors({ file: ["Please attach the file for this document."] });
      return;
    }

    const formData = new FormData();
    formData.append("document_type_id", typeId);
    if (internId) formData.append("intern_id", String(internId));
    if (period.trim() && !isDtr) formData.append("document_period", period.trim());

    if (isDtr) {
      if (days.length === 0) {
        setErrors({ days: ["Select the working days covered by this DTR."] });
        return;
      }
      days.forEach((day) => formData.append("days[]", day));
      Object.entries(hours).forEach(([day, value]) => formData.append(`day_hours[${day}]`, String(value)));
    }

    formData.append("file", file);

    setSubmitting(true);
    try {
      const response = await api.post<{ message: string }>("/api/v1/intern-documents", formData);
      setSuccess(response.message);
      reset();
      await onUploaded?.();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setError(requestError.message);
      } else {
        setError("The document could not be uploaded.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (documentTypes.length === 0) {
    return (
      <div className="alert alert-warning small mb-0">
        <i className="fas fa-triangle-exclamation me-1" />
        No document types are configured for this tab yet. Please contact the PESO office.
      </div>
    );
  }

  const errorList = Object.entries(errors).flatMap(([field, messages]) =>
    messages.map((message) => ({ field, message })),
  );

  return (
    <form onSubmit={submit} encType="multipart/form-data">
      {error && <div className="alert alert-danger">{error}</div>}
      {errorList.length > 0 && (
        <div className="alert alert-danger">
          <ul className="mb-0 ps-3">
            {errorList.map((entry) => (
              <li key={`${entry.field}-${entry.message}`}>{entry.message}</li>
            ))}
          </ul>
        </div>
      )}
      {success && <div className="alert alert-success">{success}</div>}

      <div className="row g-3">
        <div className="col-md-4">
          <label className="form-label" htmlFor="document_type_id">
            Document Type <span className="text-danger">*</span>
          </label>
          <select
            id="document_type_id"
            className="form-select"
            value={typeId}
            onChange={(event) => setTypeId(event.target.value)}
            required
          >
            <option value="">Select…</option>
            {documentTypes.map((type) => (
              <option key={type.id} value={type.id} data-code={type.code}>
                {type.name}
                {type.is_optional ? " (optional)" : ""}
              </option>
            ))}
          </select>
        </div>

        {!isDtr && (
          <div className="col-md-4">
            <label className="form-label" htmlFor="document_period">
              Period
            </label>
            <input
              id="document_period"
              className="form-control"
              value={period}
              onChange={(event) => setPeriod(event.target.value)}
              placeholder="e.g. June 2026"
            />
          </div>
        )}

        {offersDtr && isDtr && (
          <>
            <div className="col-12">
              <DtrDaysPicker
                days={days}
                hours={hours}
                onChange={(nextDays, nextHours) => {
                  setDays(nextDays);
                  setHours(nextHours);
                }}
                taken={takenDays}
                contractStart={contractStart}
                contractEnd={contractEnd}
              />
            </div>

            <div className="col-12">
              <div className="alert alert-light border small mb-0">
                <i className="fas fa-circle-info me-1" style={{ color: "var(--company-primary)" }} />
                The file you attach is <strong>reference evidence</strong> for the PESO reviewer. The days you select
                above are what the system records and what your work calendar shows.
              </div>
            </div>
          </>
        )}

        <div className="col-12">
          <label className="form-label">
            File <span className="text-danger">*</span>
          </label>
          <div
            className="upload-dropzone text-center p-4 rounded-3"
            role="button"
            tabIndex={0}
            style={{
              border: `2px dashed ${dragging ? "var(--company-primary)" : "#cbd5e1"}`,
              background: dragging ? "#fef2f2" : undefined,
              cursor: "pointer",
              transition: "all .2s",
            }}
            onClick={() => fileRef.current?.click()}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ") fileRef.current?.click();
            }}
            onDragOver={(event) => {
              event.preventDefault();
              setDragging(true);
            }}
            onDragLeave={(event) => {
              event.preventDefault();
              setDragging(false);
            }}
            onDrop={(event) => {
              event.preventDefault();
              setDragging(false);
              const dropped = event.dataTransfer.files?.[0];
              if (dropped) setFile(dropped);
            }}
          >
            <input
              ref={fileRef}
              type="file"
              className="d-none"
              accept={ACCEPT}
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
            />
            <i className="fas fa-cloud-upload-alt fa-2x mb-2" style={{ color: "var(--company-primary)" }} />
            {file ? (
              <div className="fw-semibold text-success">
                <i className="fas fa-file-circle-check" /> {file.name}
              </div>
            ) : (
              <div className="text-muted">
                Drag &amp; drop your file here, or{" "}
                <span className="fw-semibold" style={{ color: "var(--company-primary)" }}>
                  browse
                </span>
              </div>
            )}
            <div className="form-text mt-2">PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, CSV (max 10 MB).</div>
          </div>
          {errors.file && <div className="text-danger small mt-1">{errors.file[0]}</div>}
        </div>

        <div className="col-12 d-flex justify-content-end">
          <button className="btn btn-brand" type="submit" disabled={submitting}>
            {submitting ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-upload me-1" />}
            Upload
          </button>
        </div>
      </div>
    </form>
  );
}
