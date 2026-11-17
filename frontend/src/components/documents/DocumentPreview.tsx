"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";

import { ApiError, fetchDocumentBlob } from "@/lib/api";
import { saveBlob } from "@/lib/files";

interface PreviewTarget {
  id: number;
  name: string;
}

interface DocumentPreviewValue {
  /** Open the in-system preview overlay for an authorized document. */
  open: (document: { id: number; original_filename: string }) => void;
}

const DocumentPreviewContext = createContext<DocumentPreviewValue | null>(null);

type Kind = "image" | "pdf" | "docx" | "sheet" | "other";

function fileKind(filename: string): Kind {
  const extension = filename.split(".").pop()?.toLowerCase() ?? "";
  if (["jpg", "jpeg", "png", "gif", "webp", "svg", "bmp"].includes(extension)) return "image";
  if (extension === "pdf") return "pdf";
  if (["doc", "docx"].includes(extension)) return "docx";
  if (["xls", "xlsx", "csv"].includes(extension)) return "sheet";
  return "other";
}

/**
 * Google-Drive-style preview of a private intern document.
 *
 * The file is fetched with the session cookie and rendered from a blob URL, so
 * previews keep working when the API lives on a different origin (an <img> or
 * <iframe> would not send Sanctum's SameSite=Lax cookie cross-origin).
 */
export function DocumentPreviewProvider({ children }: { children: React.ReactNode }) {
  const [target, setTarget] = useState<PreviewTarget | null>(null);
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [downloading, setDownloading] = useState(false);

  const close = useCallback(() => {
    setTarget(null);
    setError(null);
    setLoading(false);
  }, []);

  const open = useCallback((document: { id: number; original_filename: string }) => {
    setTarget({ id: document.id, name: document.original_filename });
    setError(null);
    setBlobUrl(null);
  }, []);

  useEffect(() => {
    if (!target) return;

    let cancelled = false;
    let createdUrl: string | null = null;
    setLoading(true);

    fetchDocumentBlob(target.id, "preview")
      .then((blob) => {
        if (cancelled) return;
        createdUrl = URL.createObjectURL(blob);
        setBlobUrl(createdUrl);
      })
      .catch((requestError: unknown) => {
        if (cancelled) return;
        setError(
          requestError instanceof ApiError
            ? requestError.message
            : "This document could not be loaded. Please try again.",
        );
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
      if (createdUrl) URL.revokeObjectURL(createdUrl);
    };
  }, [target]);

  useEffect(() => {
    if (!target) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") close();
    };
    document.addEventListener("keydown", onKeyDown);
    document.body.classList.add("preview-overlay-open");
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      document.body.classList.remove("preview-overlay-open");
    };
  }, [target, close]);

  const download = useCallback(async () => {
    if (!target) return;
    setDownloading(true);
    try {
      await saveBlob(await fetchDocumentBlob(target.id, "download"), target.name);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Download failed.");
    } finally {
      setDownloading(false);
    }
  }, [target]);

  const value = useMemo<DocumentPreviewValue>(() => ({ open }), [open]);

  const kind = target ? fileKind(target.name) : "other";

  return (
    <DocumentPreviewContext.Provider value={value}>
      {children}

      {target && (
        <div className="preview-overlay" id="document-preview-overlay" role="dialog" aria-modal="true">
          <div className="preview-overlay__backdrop" onClick={close} />

          <div className="preview-overlay__toolbar">
            <div className="preview-overlay__toolbar-left">
              <button
                type="button"
                className="preview-overlay__btn"
                onClick={close}
                aria-label="Close preview"
                title="Close (Esc)"
              >
                <i className="fas fa-times" />
              </button>
              <span className="preview-overlay__filename">{target.name}</span>
            </div>

            <div className="preview-overlay__toolbar-right">
              <button
                type="button"
                className="preview-overlay__btn"
                onClick={download}
                disabled={downloading}
                title="Download Document"
              >
                <i className={`fas ${downloading ? "fa-circle-notch fa-spin" : "fa-download"}`} />
              </button>
              <button
                type="button"
                className="preview-overlay__btn"
                onClick={() => {
                  if (!blobUrl) return;
                  if (kind === "pdf") {
                    const frame = document.querySelector<HTMLIFrameElement>("#preview-blob-frame");
                    try {
                      frame?.contentWindow?.focus();
                      frame?.contentWindow?.print();
                      return;
                    } catch {
                      // Fall through to opening a tab.
                    }
                  }
                  window.open(blobUrl, "_blank", "noopener");
                }}
                title="Print Document"
              >
                <i className="fas fa-print" />
              </button>
              <a
                className="preview-overlay__btn"
                href={blobUrl ?? "#"}
                target="_blank"
                rel="noopener noreferrer"
                title="Open in new tab"
              >
                <i className="fas fa-external-link-alt" />
              </a>
            </div>
          </div>

          <div className="preview-overlay__content" onClick={close}>
            <div className="preview-overlay__body" onClick={(event) => event.stopPropagation()}>
              {loading && (
                <div className="preview-overlay__loading">
                  <div className="spinner-border spinner-border-sm text-light me-2" role="status" />
                  Loading document preview...
                </div>
              )}

              {!loading && error && (
                <div
                  className="preview-overlay__unsupported"
                  style={{
                    maxWidth: 480,
                    margin: "40px auto",
                    padding: "28px 24px",
                    borderRadius: 12,
                    background: "#202124",
                    textAlign: "center",
                    color: "#fff",
                  }}
                >
                  <i className="fas fa-triangle-exclamation fa-3x mb-3 text-warning" />
                  <p className="mb-0 text-white-50">{error}</p>
                </div>
              )}

              {!loading && !error && blobUrl && kind === "pdf" && (
                <iframe
                  id="preview-blob-frame"
                  src={`${blobUrl}#toolbar=1`}
                  className="preview-overlay__iframe"
                  style={{ width: "100%", height: "calc(100vh - 120px)", border: "none", borderRadius: 6, background: "#fff" }}
                  title={target.name}
                />
              )}

              {!loading && !error && blobUrl && kind === "image" && (
                <div style={{ display: "flex", justifyContent: "center", alignItems: "center", minHeight: "60vh", padding: 12 }}>
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={blobUrl}
                    alt={target.name}
                    className="preview-overlay__image"
                    style={{ maxHeight: "calc(100vh - 130px)", maxWidth: "100%", objectFit: "contain", borderRadius: 6 }}
                  />
                </div>
              )}

              {!loading && !error && blobUrl && kind !== "pdf" && kind !== "image" && (
                <div
                  className="preview-overlay__unsupported"
                  style={{
                    maxWidth: 540,
                    margin: "40px auto",
                    padding: "32px 24px",
                    borderRadius: 12,
                    background: "#202124",
                    textAlign: "center",
                    color: "#fff",
                  }}
                >
                  <i
                    className={`fas ${
                      kind === "docx" ? "fa-file-word text-primary" : kind === "sheet" ? "fa-file-excel text-success" : "fa-file-alt text-secondary"
                    } fa-4x mb-3`}
                  />
                  <h5 className="fw-bold mb-1">{target.name}</h5>
                  <p className="text-white-50 small mb-4">
                    This file format (<strong>.{target.name.split(".").pop()?.toUpperCase()}</strong>) cannot be
                    rendered directly inline by the browser. Download it to open with your desktop application.
                  </p>
                  <button type="button" className="btn btn-primary px-4 py-2" onClick={download} disabled={downloading}>
                    <i className="fas fa-download me-1" /> Download File
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </DocumentPreviewContext.Provider>
  );
}

export function useDocumentPreview(): DocumentPreviewValue {
  const context = useContext(DocumentPreviewContext);
  if (!context) {
    throw new Error("useDocumentPreview must be used inside <DocumentPreviewProvider>");
  }
  return context;
}

/** Direct download that keeps the session-authorized fetch (no bare <a href>). */
export function useDocumentDownload() {
  return useCallback(async (document: { id: number; original_filename: string }) => {
    await saveBlob(await fetchDocumentBlob(document.id, "download"), document.original_filename);
  }, []);
}
