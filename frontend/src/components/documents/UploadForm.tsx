"use client";

import { useEffect, useRef, useState } from "react";

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
const IMAGE_ACCEPT = "image/jpeg,image/png,image/webp";
/** Matches StoreInternDocumentRequest (`max:10240` KB). */
const MAX_FILE_BYTES = 10 * 1024 * 1024;

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
  const cameraRef = useRef<HTMLInputElement | null>(null);

  // When a tab offers a single document type (the DTR tab only has "Daily Time
  // Record"), preselect it and skip the one-option dropdown entirely.
  const singleType = documentTypes.length === 1 ? documentTypes[0] : null;
  const lockedTypeId = singleType ? String(singleType.id) : "";

  const [typeId, setTypeId] = useState(lockedTypeId);
  const [period, setPeriod] = useState("");
  const [days, setDays] = useState<string[]>([]);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [dragging, setDragging] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (lockedTypeId) setTypeId(lockedTypeId);
  }, [lockedTypeId]);

  // Thumbnail feedback for image files, including photos taken with the camera.
  useEffect(() => {
    if (!file || !file.type.startsWith("image/")) {
      setPreview(null);
      return;
    }
    const objectUrl = URL.createObjectURL(file);
    setPreview(objectUrl);
    return () => URL.revokeObjectURL(objectUrl);
  }, [file]);

  const pickFile = (candidate: File | null) => {
    setErrors((current) => {
      if (!("file" in current)) return current;
      const next = { ...current };
      delete next.file;
      return next;
    });

    if (!candidate) {
      setFile(null);
      return;
    }

    if (candidate.size > MAX_FILE_BYTES) {
      setFile(null);
      setErrors((current) => ({
        ...current,
        file: ["That file is larger than 10 MB. Choose a smaller file or lower your camera's photo resolution."],
      }));
      return;
    }

    setFile(candidate);
  };

  const effectiveTypeId = singleType ? lockedTypeId : typeId;
  const selectedType = documentTypes.find((type) => String(type.id) === effectiveTypeId) ?? null;
  const isDtr = selectedType?.code === "DTR";
  const offersDtr = documentTypes.some((type) => type.code === "DTR");

  const reset = () => {
    setTypeId(lockedTypeId);
    setPeriod("");
    setDays([]);
    setFile(null);
    setPreview(null);
    if (fileRef.current) fileRef.current.value = "";
    if (cameraRef.current) cameraRef.current.value = "";
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);
    setErrors({});

    if (!effectiveTypeId) {
      setErrors({ document_type_id: ["Please select a document type."] });
      return;
    }
    if (!file) {
      setErrors({ file: ["Please attach the file for this document."] });
      return;
    }

    const formData = new FormData();
    formData.append("document_type_id", effectiveTypeId);
    if (internId) formData.append("intern_id", String(internId));
    if (period.trim() && !isDtr) formData.append("document_period", period.trim());

    if (isDtr) {
      if (days.length === 0) {
        setErrors({ days: ["Select the working days covered by this DTR."] });
        return;
      }
      days.forEach((day) => formData.append("days[]", day));
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
        {singleType ? (
          <div className="col-md-4">
            <label className="form-label">Document Type</label>
            <div className="form-control-plaintext fw-semibold">
              {singleType.name}
              {singleType.is_optional && <span className="badge bg-light text-muted fw-normal ms-1">optional</span>}
            </div>
          </div>
        ) : (
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
        )}

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
                onChange={(nextDays) => setDays(nextDays)}
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
              onChange={(event) => pickFile(event.target.files?.[0] ?? null)}
            />
            {preview ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={preview} alt="Selected document preview" className="upload-preview mb-2" />
            ) : (
              <i className="fas fa-cloud-upload-alt fa-2x mb-2" style={{ color: "var(--company-primary)" }} />
            )}
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

          <div className="upload-mobile-actions mt-2 text-center">
            <button
              type="button"
              className="btn btn-outline-secondary upload-camera-btn"
              onClick={() => cameraRef.current?.click()}
            >
              <i className="fas fa-camera me-2" /> Take a photo instead
            </button>
            <div className="form-text mt-1">Opens your phone camera. You can retake the photo before uploading.</div>
          </div>
          <input
            ref={cameraRef}
            type="file"
            accept={IMAGE_ACCEPT}
            capture="environment"
            className="d-none"
            onChange={(event) => pickFile(event.target.files?.[0] ?? null)}
          />

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
