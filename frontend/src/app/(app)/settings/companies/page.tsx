"use client";

import { useCallback, useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Company, Paginated } from "@/lib/types";

interface CompanyFormValues {
  name: string;
  code: string;
  is_active: boolean;
}

const EMPTY_FORM: CompanyFormValues = { name: "", code: "", is_active: true };

type ConfirmState = { kind: "toggle" | "delete"; company: Company } | null;

export default function PartnerAgenciesPage() {
  const { isAdmin, status } = useAuth();

  const [companies, setCompanies] = useState<Paginated<Company> | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const [createOpen, setCreateOpen] = useState(false);
  const [createValues, setCreateValues] = useState<CompanyFormValues>(EMPTY_FORM);
  const [editing, setEditing] = useState<Company | null>(null);
  const [editValues, setEditValues] = useState<CompanyFormValues>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [confirming, setConfirming] = useState<ConfirmState>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const payload = await api.get<{ companies: Paginated<Company> }>(`/api/v1/settings/companies?page=${page}`);
      setCompanies(payload.companies);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load partner agencies.");
    }
  }, [page]);

  useEffect(() => {
    if (status === "authenticated" && isAdmin) load();
  }, [status, isAdmin, load]);

  if (status === "authenticated" && !isAdmin) {
    return (
      <div className="alert alert-danger" role="alert">
        You do not have permission to manage partner agencies.
      </div>
    );
  }

  const submitCreate = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setFormErrors({});
    setFormMessage(null);
    try {
      const response = await api.post<{ message: string }>("/api/v1/settings/companies", createValues);
      setCreateOpen(false);
      setCreateValues(EMPTY_FORM);
      setSuccess(response.message);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFormErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setFormMessage(requestError.message);
      } else {
        setFormMessage("The partner agency could not be created.");
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
      const response = await api.put<{ message: string }>(`/api/v1/settings/companies/${editing.id}`, editValues);
      setEditing(null);
      setSuccess(response.message);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFormErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setFormMessage(requestError.message);
      } else {
        setFormMessage("The partner agency could not be updated.");
      }
    } finally {
      setSaving(false);
    }
  };

  const runConfirm = async () => {
    if (!confirming) return;
    setSaving(true);
    setActionError(null);
    try {
      if (confirming.kind === "toggle") {
        const response = await api.patch<{ message: string }>(
          `/api/v1/settings/companies/${confirming.company.id}/toggle-active`,
        );
        setSuccess(response.message);
      } else {
        const response = await api.delete<{ message: string }>(`/api/v1/settings/companies/${confirming.company.id}`);
        setSuccess(response.message);
      }
      setConfirming(null);
      await load();
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "The action could not be completed.");
    } finally {
      setSaving(false);
    }
  };

  const errorList = (
    <ul className="mb-0 ps-3">
      {Object.entries(formErrors).map(([key, messages]) =>
        messages.map((message) => <li key={`${key}-${message}`}>{message}</li>),
      )}
    </ul>
  );

  const confirmCopy = (state: ConfirmState) => {
    if (!state) return { title: "", message: "", submit: "" };
    if (state.kind === "delete") {
      return {
        title: "Delete Partner Agency",
        message: `Are you sure you want to permanently delete ${state.company.name}? This action cannot be undone.`,
        submit: "Delete",
      };
    }
    return {
      title: state.company.is_active ? "Deactivate Partner Agency" : "Reactivate Partner Agency",
      message: state.company.is_active
        ? `Are you sure you want to deactivate ${state.company.name}? It will no longer appear as an option for new intern placements.`
        : `Are you sure you want to reactivate ${state.company.name}? It will be available for new intern placements again.`,
      submit: state.company.is_active ? "Deactivate" : "Reactivate",
    };
  };

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">Partner Agencies</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Manage partner agencies and organizations where interns are deployed.
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
          <i className="fas fa-plus" /> Add Partner Agency
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

      <div className="card shadow-sm">
        <div className="doc-table-wrapper" style={{ border: "none" }}>
          <div className="table-responsive">
            <table className="table doc-table mb-0">
              <thead>
                <tr>
                  <th style={{ width: 100 }}>Code</th>
                  <th>Agency Name</th>
                  <th style={{ width: 120, textAlign: "center" }}>Status</th>
                  <th style={{ width: 140, textAlign: "center" }}>Assigned Interns</th>
                  <th style={{ width: 150, textAlign: "center" }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {!companies ? (
                  <tr>
                    <td colSpan={5} className="text-center text-muted py-5">
                      <i className="fas fa-circle-notch fa-spin me-2" /> Loading partner agencies…
                    </td>
                  </tr>
                ) : companies.data.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="text-center text-muted py-5">
                      <i className="fas fa-handshake mb-2 d-block" style={{ fontSize: "2rem", opacity: 0.3 }} />
                      No partner agencies found.
                    </td>
                  </tr>
                ) : (
                  companies.data.map((company) => (
                    <tr key={company.id}>
                      <td>
                        <span className="doc-type-cell" style={{ cursor: "default" }}>
                          {company.code}
                        </span>
                      </td>
                      <td className="fw-medium">{company.name}</td>
                      <td className="text-center">
                        {company.is_active ? (
                          <span className="badge rounded-pill" style={{ background: "#e8f5e9", color: "#2e7d32", fontSize: "0.75rem", padding: "5px 12px" }}>
                            <i className="fas fa-circle me-1" style={{ fontSize: "0.45rem", verticalAlign: "middle" }} /> Active
                          </span>
                        ) : (
                          <span className="badge rounded-pill" style={{ background: "#fce4ec", color: "#c62828", fontSize: "0.75rem", padding: "5px 12px" }}>
                            <i className="fas fa-circle me-1" style={{ fontSize: "0.45rem", verticalAlign: "middle" }} /> Inactive
                          </span>
                        )}
                      </td>
                      <td className="text-center fw-semibold" style={{ color: "#374151" }}>
                        {company.interns_count ?? 0}
                      </td>
                      <td className="text-center">
                        <div className="d-flex justify-content-center gap-2">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-primary"
                            title="Edit agency"
                            onClick={() => {
                              setEditing(company);
                              setEditValues({
                                name: company.name,
                                code: company.code ?? "",
                                is_active: company.is_active,
                              });
                              setFormErrors({});
                              setFormMessage(null);
                            }}
                          >
                            <i className="fas fa-pen" />
                          </button>
                          <button
                            type="button"
                            className={`btn btn-sm ${company.is_active ? "btn-outline-danger" : "btn-outline-success"}`}
                            title={company.is_active ? "Deactivate" : "Reactivate"}
                            onClick={() => setConfirming({ kind: "toggle", company })}
                          >
                            <i className={`fas ${company.is_active ? "fa-ban" : "fa-check"}`} />
                          </button>
                          {company.interns_count === 0 ? (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger"
                              title="Delete agency"
                              onClick={() => setConfirming({ kind: "delete", company })}
                            >
                              <i className="fas fa-trash" />
                            </button>
                          ) : (
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-secondary"
                              title="Cannot delete agencies with assigned interns"
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

        {companies && companies.last_page > 1 && (
          <div className="card-footer bg-white">
            <Pagination page={companies} onPageChange={setPage} />
          </div>
        )}
      </div>

      <Modal
        show={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Add Partner Agency"
        footer={
          <>
            <button type="button" className="btn btn-light" onClick={() => setCreateOpen(false)}>
              Cancel
            </button>
            <button type="submit" form="create-company-form" className="btn btn-brand" disabled={saving}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-save me-1" />}
              Save Agency
            </button>
          </>
        }
      >
        <p className="text-muted mb-4" style={{ fontSize: "0.9rem" }}>
          Register a new partner agency into the system.
        </p>
        <form id="create-company-form" onSubmit={submitCreate}>
          {Object.keys(formErrors).length > 0 && <div className="alert alert-danger">{errorList}</div>}
          {formMessage && <div className="alert alert-danger">{formMessage}</div>}

          <div className="mb-4">
            <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="company_name">
              Agency Name <span className="text-danger">*</span>
            </label>
            <input
              id="company_name"
              className="form-control"
              value={createValues.name}
              onChange={(event) => setCreateValues({ ...createValues, name: event.target.value })}
              placeholder="e.g. PESO Provincial Office"
              required
              autoFocus
            />
          </div>

          <div className="mb-4">
            <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="company_code">
              Agency Code <span className="text-danger">*</span>
            </label>
            <input
              id="company_code"
              className="form-control text-uppercase"
              value={createValues.code}
              onChange={(event) => setCreateValues({ ...createValues, code: event.target.value })}
              placeholder="e.g. PESO-PO"
              maxLength={20}
              required
            />
            <div className="form-text">A unique short code (max 20 characters). Auto-uppercased.</div>
          </div>

          <div className="mb-2 p-3" style={{ backgroundColor: "#f3f4f6", borderRadius: 10, border: "1px solid #e5e7eb" }}>
            <div className="d-flex justify-content-between align-items-center">
              <div>
                <label className="fw-semibold mb-0" style={{ fontSize: "0.85rem", color: "#1f2937" }} htmlFor="company_active">
                  Active Status
                </label>
                <div className="form-text mt-0" style={{ fontSize: "0.75rem" }}>
                  Inactive agencies won&apos;t appear in intern placement options.
                </div>
              </div>
              <input
                className="form-check-input m-0"
                type="checkbox"
                id="company_active"
                checked={createValues.is_active}
                onChange={(event) => setCreateValues({ ...createValues, is_active: event.target.checked })}
                style={{ width: "2.5em", height: "1.25em", cursor: "pointer" }}
              />
            </div>
          </div>
        </form>
      </Modal>

      <Modal
        show={editing !== null}
        onClose={() => setEditing(null)}
        title="Edit Partner Agency"
        footer={
          <>
            <button type="button" className="btn btn-light" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button type="submit" form="edit-company-form" className="btn btn-brand" disabled={saving}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-save me-1" />}
              Update Agency
            </button>
          </>
        }
      >
        <p className="text-muted mb-4" style={{ fontSize: "0.9rem" }}>
          Update details for <strong>{editing?.name}</strong>.
        </p>
        <form id="edit-company-form" onSubmit={submitEdit}>
          {Object.keys(formErrors).length > 0 && <div className="alert alert-danger">{errorList}</div>}
          {formMessage && <div className="alert alert-danger">{formMessage}</div>}

          <div className="mb-4">
            <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="edit_company_name">
              Agency Name <span className="text-danger">*</span>
            </label>
            <input
              id="edit_company_name"
              className="form-control"
              value={editValues.name}
              onChange={(event) => setEditValues({ ...editValues, name: event.target.value })}
              required
            />
          </div>

          <div className="mb-4">
            <label className="form-label fw-semibold" style={{ fontSize: "0.85rem", color: "#374151" }} htmlFor="edit_company_code">
              Agency Code <span className="text-danger">*</span>
            </label>
            <input
              id="edit_company_code"
              className="form-control text-uppercase"
              value={editValues.code}
              onChange={(event) => setEditValues({ ...editValues, code: event.target.value })}
              maxLength={20}
              required
            />
            <div className="form-text">A unique short code. Auto-uppercased.</div>
          </div>

          <div className="mb-2 p-3" style={{ backgroundColor: "#f3f4f6", borderRadius: 10, border: "1px solid #e5e7eb" }}>
            <div className="d-flex justify-content-between align-items-center">
              <div>
                <label className="fw-semibold mb-0" style={{ fontSize: "0.85rem", color: "#1f2937" }} htmlFor="edit_company_active">
                  Active Status
                </label>
                <div className="form-text mt-0" style={{ fontSize: "0.75rem" }}>
                  Inactive agencies won&apos;t appear in intern placement options.
                </div>
              </div>
              <input
                className="form-check-input m-0"
                type="checkbox"
                id="edit_company_active"
                checked={editValues.is_active}
                onChange={(event) => setEditValues({ ...editValues, is_active: event.target.checked })}
                style={{ width: "2.5em", height: "1.25em", cursor: "pointer" }}
              />
            </div>
          </div>
        </form>
      </Modal>

      <Modal
        show={confirming !== null}
        onClose={() => {
          setConfirming(null);
          setActionError(null);
        }}
        title={confirmCopy(confirming).title}
        footer={
          <>
            <button
              type="button"
              className="btn btn-outline-secondary"
              onClick={() => {
                setConfirming(null);
                setActionError(null);
              }}
            >
              Cancel
            </button>
            <button type="button" className="btn btn-danger" disabled={saving} onClick={runConfirm}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              {confirmCopy(confirming).submit}
            </button>
          </>
        }
      >
        {actionError && <div className="alert alert-danger">{actionError}</div>}
        {confirmCopy(confirming).message}
      </Modal>
    </>
  );
}
