"use client";

import { useCallback, useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { DocumentType, Paginated } from "@/lib/types";

interface DocumentTypeFormValues {
  name: string;
  code: string;
  has_expiry: boolean;
}

const EMPTY_FORM: DocumentTypeFormValues = { name: "", code: "", has_expiry: false };

export default function DocumentTypesPage() {
  const { isAdmin, status } = useAuth();

  const [documentTypes, setDocumentTypes] = useState<Paginated<DocumentType> | null>(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const [createOpen, setCreateOpen] = useState(false);
  const [createValues, setCreateValues] = useState<DocumentTypeFormValues>(EMPTY_FORM);
  const [editing, setEditing] = useState<DocumentType | null>(null);
  const [editValues, setEditValues] = useState<DocumentTypeFormValues>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState<DocumentType | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const params = new URLSearchParams({ page: String(page) });
      if (appliedSearch) params.set("search", appliedSearch);
      const payload = await api.get<{ document_types: Paginated<DocumentType> }>(
        `/api/v1/settings/document-types?${params.toString()}`,
      );
      setDocumentTypes(payload.document_types);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load document types.");
    }
  }, [page, appliedSearch]);

  useEffect(() => {
    if (status === "authenticated" && isAdmin) load();
  }, [status, isAdmin, load]);

  if (status === "authenticated" && !isAdmin) {
    return (
      <div className="alert alert-danger" role="alert">
        You do not have permission to manage document types.
      </div>
    );
  }

  const submitCreate = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setFormErrors({});
    setFormMessage(null);
    try {
      const response = await api.post<{ message: string }>("/api/v1/settings/document-types", createValues);
      setCreateOpen(false);
      setCreateValues(EMPTY_FORM);
      setSuccess(response.message);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFormErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setFormMessage(requestError.message);
      } else {
        setFormMessage("The document type could not be created.");
      }
    } finally {
      setSaving(false);
    }
  };

  const submitEdit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!editing) return;
    setSaving(true);
    setFormErrors({});
    setFormMessage(null);
    try {
      const response = await api.put<{ message: string }>(`/api/v1/settings/document-types/${editing.id}`, editValues);
      setEditing(null);
      setSuccess(response.message);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFormErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setFormMessage(requestError.message);
      } else {
        setFormMessage("The document type could not be updated.");
      }
    } finally {
      setSaving(false);
    }
  };

  const destroy = async () => {
    if (!deleting) return;
    setSaving(true);
    setActionError(null);
    try {
      const response = await api.delete<{ message: string }>(`/api/v1/settings/document-types/${deleting.id}`);
      setSuccess(response.message);
      setDeleting(null);
      await load();
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "The document type could not be deleted.");
    } finally {
      setSaving(false);
    }
  };

  const errorList =
    Object.keys(formErrors).length > 0 ? (
      <div className="alert alert-danger">
        <ul className="mb-0 ps-3">
          {Object.entries(formErrors).map(([key, messages]) =>
            messages.map((message) => <li key={`${key}-${message}`}>{message}</li>),
          )}
        </ul>
      </div>
    ) : null;

  return (
    <>
      <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">Document Types</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Manage the classification types used to categorize uploaded documents.
          </p>
        </div>
        <button
          type="button"
          className="btn btn-brand d-inline-flex align-items-center gap-2"
          onClick={() => {
            setCreateValues(EMPTY_FORM);
            setFormErrors({});
            setFormMessage(null);
            setCreateOpen(true);
          }}
        >
          <i className="fas fa-plus" /> Add Document Type
        </button>
      </div>

      {success && (
        <div className="alert alert-success">
          <i className="fas fa-check-circle me-2" />
          {success}
        </div>
      )}
      {error && (
        <div className="alert alert-danger d-flex justify-content-between align-items-center">
          <span>{error}</span>
          <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
            Retry
          </button>
        </div>
      )}

      <div className="card shadow-sm mb-4">
        <div className="card-body py-3">
          <form
            className="row g-3 align-items-end"
            onSubmit={(event) => {
              event.preventDefault();
              setPage(1);
              setAppliedSearch(search.trim());
            }}
          >
            <div className="col-md-6">
              <label
                className="form-label"
                htmlFor="search"
                style={{ fontSize: "0.78rem", fontWeight: 600, color: "#6b7280", textTransform: "uppercase", letterSpacing: "0.05em" }}
              >
                Search
              </label>
              <input
                id="search"
                type="text"
                className="form-control"
                placeholder="Search by name or code…"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
            <div className="col-md-3 col-lg-2 d-flex gap-2">
              <button type="submit" className="btn btn-brand flex-grow-1">
                <i className="fas fa-search me-1" /> Filter
              </button>
              <button
                type="button"
                className="btn btn-outline-secondary"
                title="Clear filters"
                onClick={() => {
                  setSearch("");
                  setAppliedSearch("");
                  setPage(1);
                }}
              >
                <i className="fas fa-times" />
              </button>
            </div>
          </form>
        </div>
      </div>

      <div className="card shadow-sm">
        <div className="doc-table-wrapper" style={{ border: "none" }}>
          <div className="table-responsive">
            <table className="table doc-table mb-0 mobile-stack-table">
              <thead>
                <tr>
                  <th style={{ width: 250 }}>Name</th>
                  <th style={{ width: 120, textAlign: "center" }}>Code</th>
                  <th style={{ width: 90, textAlign: "center" }}>Expiry?</th>
                  <th style={{ width: 100, textAlign: "center" }}>Documents</th>
                  <th style={{ width: 80, textAlign: "center" }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {!documentTypes ? (
                  <tr>
                    <td colSpan={5} className="text-center text-muted py-5">
                      <i className="fas fa-circle-notch fa-spin me-2" /> Loading document types…
                    </td>
                  </tr>
                ) : documentTypes.data.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="text-center text-muted py-5">
                      <i className="fas fa-file-alt mb-2 d-block" style={{ fontSize: "2rem", opacity: 0.3 }} />
                      No document types found.
                    </td>
                  </tr>
                ) : (
                  documentTypes.data.map((documentType) => (
                    <tr key={documentType.id}>
                      <td className="fw-medium" data-label="Name">{documentType.name}</td>
                      <td className="text-center" data-label="Code">
                        <code
                          style={{ background: "#f1f5f9", color: "#475569", padding: "3px 8px", borderRadius: 4, fontSize: "0.78rem", fontWeight: 600 }}
                        >
                          {documentType.code}
                        </code>
                      </td>
                      <td className="text-center" data-label="Expiry?">
                        {documentType.has_expiry ? (
                          <i className="fas fa-check-circle text-success" title="Has expiry tracking" />
                        ) : (
                          <i className="fas fa-minus-circle" style={{ color: "#d1d5db" }} title="No expiry" />
                        )}
                      </td>
                      <td className="text-center" data-label="Documents">
                        <span className="badge" style={{ background: "rgba(79, 70, 229, 0.1)", color: "#4f46e5", padding: "5px 10px", fontWeight: 600 }}>
                          {documentType.intern_documents_count ?? 0}
                        </span>
                      </td>
                      <td className="text-center" data-label="Actions">
                        <div className="d-flex justify-content-center gap-2">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-primary"
                            title="Edit document type"
                            onClick={() => {
                              setEditing(documentType);
                              setEditValues({
                                name: documentType.name,
                                code: documentType.code,
                                has_expiry: documentType.has_expiry,
                              });
                              setFormErrors({});
                              setFormMessage(null);
                            }}
                          >
                            <i className="fas fa-pen" />
                          </button>
                          {(documentType.intern_documents_count ?? 0) === 0 ? (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Delete document type"
                              onClick={() => {
                                setDeleting(documentType);
                                setActionError(null);
                              }}
                            >
                              <i className="fas fa-trash" />
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-secondary"
                              title="Cannot delete document types with active documents"
                              disabled
                            >
                              <i className="fas fa-trash" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {documentTypes && documentTypes.last_page > 1 && (
          <div className="card-footer bg-white">
            <Pagination page={documentTypes} onPageChange={setPage} />
          </div>
        )}
      </div>

      <Modal
        show={createOpen}
        onClose={() => setCreateOpen(false)}
        size="lg"
        title="Add Document Type"
        footer={
          <>
            <button type="button" className="btn btn-light" onClick={() => setCreateOpen(false)}>
              Cancel
            </button>
            <button type="submit" form="create-document-type-form" className="btn btn-brand" disabled={saving}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-save me-1" />}
              Save Document Type
            </button>
          </>
        }
      >
        <p className="text-muted mb-4" style={{ fontSize: "0.9rem" }}>
          Register a new classification type used to categorize uploaded documents.
        </p>
        <form id="create-document-type-form" onSubmit={submitCreate}>
          {errorList}
          {formMessage && <div className="alert alert-danger">{formMessage}</div>}

          <div className="row g-4">
            <div className="col-md-6">
              <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="doc_type_name">
                Document Name <span className="text-danger">*</span>
              </label>
              <input
                id="doc_type_name"
                className="form-control"
                value={createValues.name}
                onChange={(event) => setCreateValues({ ...createValues, name: event.target.value })}
                placeholder="e.g. Birth Certificate"
                required
              />
              <div className="form-text">The full, readable name.</div>
            </div>

            <div className="col-md-6">
              <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="doc_type_code">
                Short Code <span className="text-danger">*</span>
              </label>
              <input
                id="doc_type_code"
                className="form-control"
                value={createValues.code}
                onChange={(event) => setCreateValues({ ...createValues, code: event.target.value })}
                placeholder="e.g. BIRTHCERT"
                required
                style={{ fontFamily: "monospace" }}
              />
              <div className="form-text">A unique, URL-safe identifier. No spaces.</div>
            </div>

            <hr className="mt-4 mb-2" />

            <div className="col-md-6">
              <div className="form-check form-switch">
                <input
                  className="form-check-input"
                  type="checkbox"
                  id="has_expiry"
                  checked={createValues.has_expiry}
                  onChange={(event) => setCreateValues({ ...createValues, has_expiry: event.target.checked })}
                />
                <label className="form-check-label fw-semibold" htmlFor="has_expiry" style={{ fontSize: "0.85rem" }}>
                  <i className="fas fa-calendar-times me-1 text-warning" style={{ fontSize: "0.8rem" }} /> Has Expiry Date
                </label>
              </div>
              <div className="form-text ms-4" style={{ fontSize: "0.78rem" }}>
                Enable if this document type can expire (e.g. NBI Clearance).
              </div>
            </div>
          </div>
        </form>
      </Modal>

      <Modal
        show={editing !== null}
        onClose={() => setEditing(null)}
        size="lg"
        title="Edit Document Type"
        footer={
          <>
            <button type="button" className="btn btn-light" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button type="submit" form="edit-document-type-form" className="btn btn-brand" disabled={saving}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-save me-1" />}
              Save Changes
            </button>
          </>
        }
      >
        <p className="text-muted mb-4" style={{ fontSize: "0.9rem" }}>
          Update the details for this document classification.
        </p>
        <form id="edit-document-type-form" onSubmit={submitEdit}>
          {errorList}
          {formMessage && <div className="alert alert-danger">{formMessage}</div>}

          <div className="row g-4">
            <div className="col-md-6">
              <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="edit_doc_type_name">
                Document Name <span className="text-danger">*</span>
              </label>
              <input
                id="edit_doc_type_name"
                className="form-control"
                value={editValues.name}
                onChange={(event) => setEditValues({ ...editValues, name: event.target.value })}
                required
              />
              <div className="form-text">The full, readable name.</div>
            </div>

            <div className="col-md-6">
              <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="edit_doc_type_code">
                Short Code <span className="text-danger">*</span>
              </label>
              <input
                id="edit_doc_type_code"
                className="form-control"
                value={editValues.code}
                onChange={(event) => setEditValues({ ...editValues, code: event.target.value })}
                required
                style={{ fontFamily: "monospace" }}
              />
              <div className="form-text">A unique, URL-safe identifier. No spaces.</div>
            </div>

            <hr className="mt-4 mb-2" />

            <div className="col-md-6">
              <div className="form-check form-switch">
                <input
                  className="form-check-input"
                  type="checkbox"
                  id="edit_has_expiry"
                  checked={editValues.has_expiry}
                  onChange={(event) => setEditValues({ ...editValues, has_expiry: event.target.checked })}
                />
                <label className="form-check-label fw-semibold" htmlFor="edit_has_expiry" style={{ fontSize: "0.85rem" }}>
                  <i className="fas fa-calendar-times me-1 text-warning" style={{ fontSize: "0.8rem" }} /> Has Expiry Date
                </label>
              </div>
              <div className="form-text ms-4" style={{ fontSize: "0.78rem" }}>
                Enable if this document type can expire (e.g. NBI Clearance).
              </div>
            </div>
          </div>
        </form>
      </Modal>

      <Modal
        show={deleting !== null}
        onClose={() => setDeleting(null)}
        title="Delete Document Type"
        footer={
          <>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setDeleting(null)}>
              Cancel
            </button>
            <button type="button" className="btn btn-danger" disabled={saving} onClick={destroy}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              Delete
            </button>
          </>
        }
      >
        {actionError && <div className="alert alert-danger">{actionError}</div>}
        Are you sure you want to permanently delete <strong>{deleting?.name}</strong>? This action cannot be undone.
      </Modal>
    </>
  );
}
