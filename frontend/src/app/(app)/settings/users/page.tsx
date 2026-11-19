"use client";

import { useCallback, useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { timeAgo, ucfirst } from "@/lib/format";
import type { Paginated, Role, User } from "@/lib/types";

interface UserFormValues {
  first_name: string;
  middle_name: string;
  last_name: string;
  suffix: string;
  username: string;
  role: Role;
  is_active: boolean;
}

const EMPTY_FORM: UserFormValues = {
  first_name: "",
  middle_name: "",
  last_name: "",
  suffix: "",
  username: "",
  role: "admin",
  is_active: true,
};

type ConfirmState = { kind: "toggle" | "delete" | "reset"; user: User } | null;

const passwordSuffix = process.env.NEXT_PUBLIC_DEFAULT_PASSWORD_SUFFIX ?? "gip";

function roleBadge(role: string) {
  if (role === "admin") return <span className="badge" style={{ background: "rgba(220, 38, 38, 0.1)", color: "#dc2626", padding: "5px 10px", fontWeight: 600 }}>Admin</span>;
  if (role === "peso_manager") return <span className="badge" style={{ background: "rgba(245, 158, 11, 0.1)", color: "#f59e0b", padding: "5px 10px", fontWeight: 600 }}>PESO Manager</span>;
  return <span className="badge bg-info-subtle text-info">{ucfirst(role.replace("_", " "))}</span>;
}

export default function UserManagementPage() {
  const { user: currentUser, isAdmin, status } = useAuth();

  const [users, setUsers] = useState<Paginated<User> | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [defaultPassword, setDefaultPassword] = useState<string | null>(null);

  const [createOpen, setCreateOpen] = useState(false);
  const [createValues, setCreateValues] = useState<UserFormValues>(EMPTY_FORM);
  const [editing, setEditing] = useState<User | null>(null);
  const [editValues, setEditValues] = useState<UserFormValues>(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [confirming, setConfirming] = useState<ConfirmState>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const payload = await api.get<{ users: Paginated<User> }>(`/api/v1/settings/users?page=${page}`);
      setUsers(payload.users);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load users.");
    }
  }, [page]);

  useEffect(() => {
    if (status === "authenticated" && isAdmin) load();
  }, [status, isAdmin, load]);

  if (status === "authenticated" && !isAdmin) {
    return (
      <div className="alert alert-danger" role="alert">
        You do not have permission to manage users.
      </div>
    );
  }

  const submitCreate = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setFormErrors({});
    setFormMessage(null);
    try {
      const response = await api.post<{ message: string; default_password: string }>("/api/v1/settings/users", createValues);
      setCreateOpen(false);
      setCreateValues(EMPTY_FORM);
      setSuccess(response.message);
      setDefaultPassword(response.default_password);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFormErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setFormMessage(requestError.message);
      } else {
        setFormMessage("The user could not be created.");
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
      const response = await api.put<{ message: string }>(`/api/v1/settings/users/${editing.id}`, editValues);
      setEditing(null);
      setSuccess(response.message);
      setDefaultPassword(null);
      await load();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFormErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setFormMessage(requestError.message);
      } else {
        setFormMessage("The user could not be updated.");
      }
    } finally {
      setSaving(false);
    }
  };

  const runConfirm = async () => {
    if (!confirming) return;
    setSaving(true);
    setActionError(null);
    // A stale default password from an earlier create/reset must not be shown
    // under an unrelated success message.
    setDefaultPassword(null);
    try {
      if (confirming.kind === "delete") {
        await api.delete(`/api/v1/settings/users/${confirming.user.id}`);
        setSuccess("User deleted successfully.");
      } else if (confirming.kind === "toggle") {
        const response = await api.patch<{ message: string }>(`/api/v1/settings/users/${confirming.user.id}/toggle-status`);
        setSuccess(response.message);
      } else {
        const response = await api.post<{ message: string; default_password: string }>(
          `/api/v1/settings/users/${confirming.user.id}/reset-password`,
        );
        setSuccess(response.message);
        setDefaultPassword(response.default_password);
        setEditing(null);
      }
      setConfirming(null);
      await load();
    } catch (requestError) {
      setActionError(requestError instanceof ApiError ? requestError.message : "The action could not be completed.");
    } finally {
      setSaving(false);
    }
  };

  const confirmCopy = (state: ConfirmState) => {
    if (!state) return { title: "", message: "", submit: "" };
    if (state.kind === "delete") {
      return {
        title: "Delete User",
        message: `Are you sure you want to delete user ${state.user.name}? This action cannot be undone.`,
        submit: "Delete",
      };
    }
    if (state.kind === "toggle") {
      return {
        title: state.user.is_active ? "Deactivate User" : "Activate User",
        message: `Are you sure you want to ${state.user.is_active ? "deactivate" : "activate"} user ${state.user.name}?`,
        submit: state.user.is_active ? "Deactivate" : "Activate",
      };
    }
    return {
      title: "Reset Password to Default",
      message: `Are you sure you want to reset the password for ${state.user.name} back to the default?`,
      submit: "Reset Password",
    };
  };

  const field = (errors: Record<string, string[]>, key: string) => errors[key]?.[0];

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

  const renderForm = (
    values: UserFormValues,
    setValues: (values: UserFormValues) => void,
    prefix: string,
  ) => (
    <div className="row">
      <div className="col-md-6 mb-3">
        <label className="form-label text-muted fw-semibold" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_first_name`}>
          First Name <span className="text-danger">*</span>
        </label>
        <input
          id={`${prefix}_first_name`}
          className="form-control"
          value={values.first_name}
          onChange={(event) => setValues({ ...values, first_name: event.target.value })}
          required
          placeholder="e.g. John"
        />
      </div>
      <div className="col-md-6 mb-3">
        <label className="form-label text-muted fw-semibold" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_middle_name`}>
          Middle Name
        </label>
        <input
          id={`${prefix}_middle_name`}
          className="form-control"
          value={values.middle_name}
          onChange={(event) => setValues({ ...values, middle_name: event.target.value })}
          placeholder="e.g. Smith"
        />
      </div>
      <div className="col-md-8 mb-3">
        <label className="form-label text-muted fw-semibold" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_last_name`}>
          Last Name <span className="text-danger">*</span>
        </label>
        <input
          id={`${prefix}_last_name`}
          className="form-control"
          value={values.last_name}
          onChange={(event) => setValues({ ...values, last_name: event.target.value })}
          required
          placeholder="e.g. Doe"
        />
      </div>
      <div className="col-md-4 mb-3">
        <label className="form-label text-muted fw-semibold" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_suffix`}>
          Suffix
        </label>
        <input
          id={`${prefix}_suffix`}
          className="form-control"
          value={values.suffix}
          onChange={(event) => setValues({ ...values, suffix: event.target.value })}
          placeholder="e.g. Jr., Sr."
        />
      </div>
      <div className="col-12 mb-3">
        <label className="form-label text-muted fw-semibold" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_username`}>
          Username <span className="text-danger">*</span>
        </label>
        <input
          id={`${prefix}_username`}
          className="form-control"
          value={values.username}
          onChange={(event) => setValues({ ...values, username: event.target.value })}
          required
          placeholder="e.g. jdoe"
        />
        {field(formErrors, "username") && <div className="invalid-feedback d-block">{field(formErrors, "username")}</div>}
      </div>
      <div className="col-12 mb-3">
        <label className="form-label text-muted fw-semibold" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_role`}>
          Role <span className="text-danger">*</span>
        </label>
        <select
          id={`${prefix}_role`}
          className="form-select"
          value={values.role}
          onChange={(event) => setValues({ ...values, role: event.target.value as Role })}
          required
        >
          <option value="admin">Admin</option>
          <option value="peso_manager">PESO Manager</option>
        </select>
      </div>
      <div className="col-12 mb-3">
        <div className="form-check form-switch d-flex align-items-center gap-3">
          <label className="form-check-label fw-semibold text-muted mb-0" style={{ fontSize: "0.85rem" }} htmlFor={`${prefix}_is_active`}>
            Account Active
          </label>
          <input
            className="form-check-input ms-0"
            type="checkbox"
            id={`${prefix}_is_active`}
            checked={values.is_active}
            onChange={(event) => setValues({ ...values, is_active: event.target.checked })}
            style={{ width: "2.8em", height: "1.4em", cursor: "pointer" }}
          />
        </div>
      </div>
    </div>
  );

  return (
    <>
      <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">User Management</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Manage administrative and staff accounts (Admin, PESO Manager).
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
          <i className="fas fa-plus" /> Add User
        </button>
      </div>

      {success && (
        <div className="alert alert-success">
          <i className="fas fa-check-circle me-2" />
          {success}
          {defaultPassword && (
            <div className="small mt-1">
              Default password: <code>{defaultPassword}</code>
            </div>
          )}
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
            <table className="table doc-table mb-0 users-mgmt-table">
              <thead>
                <tr>
                  <th style={{ width: 200 }}>Name</th>
                  <th style={{ width: 130 }}>Username</th>
                  <th style={{ width: 130 }}>Role</th>
                  <th style={{ width: 100, textAlign: "center" }}>Status</th>
                  <th style={{ width: 140, textAlign: "center" }}>Last Active</th>
                  <th style={{ width: 150, textAlign: "center" }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {!users ? (
                  <tr>
                    <td colSpan={6} className="text-center text-muted py-5">
                      <i className="fas fa-circle-notch fa-spin me-2" /> Loading users…
                    </td>
                  </tr>
                ) : users.data.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="text-center text-muted py-5">
                      <i className="fas fa-users mb-2 d-block" style={{ fontSize: "2rem", opacity: 0.3 }} />
                      No users found.
                    </td>
                  </tr>
                ) : (
                  users.data.map((row) => {
                    const isSelf = currentUser?.id === row.id;
                    return (
                      <tr key={row.id}>
                        <td className="fw-medium" data-label="Name">
                          {row.name}
                          {isSelf && (
                            <span className="badge bg-secondary ms-1" style={{ fontSize: "0.65rem" }}>
                              You
                            </span>
                          )}
                        </td>
                        <td data-label="Username">
                          <div className="text-muted" style={{ fontSize: "0.85rem", fontFamily: "monospace" }}>
                            {row.username}
                          </div>
                        </td>
                        <td data-label="Role">{roleBadge(row.role)}</td>
                        <td className="text-center" data-label="Status">
                          {row.is_active ? (
                            <span className="badge" style={{ background: "rgba(16, 185, 129, 0.1)", color: "#10b981", padding: "5px 10px", fontWeight: 600 }}>
                              Active
                            </span>
                          ) : (
                            <span className="badge" style={{ background: "rgba(107, 114, 128, 0.1)", color: "#6b7280", padding: "5px 10px", fontWeight: 600 }}>
                              Inactive
                            </span>
                          )}
                        </td>
                        <td className="text-center text-muted" data-label="Last Active" style={{ fontSize: "0.8rem" }}>
                          {row.last_active_at ? timeAgo(row.last_active_at) : "Never"}
                        </td>
                        <td className="text-center" data-label="Actions">
                          <div className="d-flex justify-content-center gap-2">
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-primary"
                              title="Edit user"
                              onClick={() => {
                                setEditing(row);
                                setEditValues({
                                  first_name: row.first_name ?? "",
                                  middle_name: row.middle_name ?? "",
                                  last_name: row.last_name ?? "",
                                  suffix: row.suffix ?? "",
                                  username: row.username,
                                  role: row.role,
                                  is_active: row.is_active,
                                });
                                setFormErrors({});
                                setFormMessage(null);
                              }}
                            >
                              <i className="fas fa-pen" />
                            </button>

                            {!isSelf && (
                              <>
                                <button
                                  type="button"
                                  className={`btn btn-sm ${row.is_active ? "btn-outline-warning" : "btn-outline-success"}`}
                                  title={row.is_active ? "Deactivate user" : "Activate user"}
                                  onClick={() => setConfirming({ kind: "toggle", user: row })}
                                >
                                  <i className={`fas ${row.is_active ? "fa-user-slash" : "fa-user-check"}`} />
                                </button>
                                <button
                                  type="button"
                                  className="btn btn-sm btn-outline-danger"
                                  title="Delete user"
                                  onClick={() => setConfirming({ kind: "delete", user: row })}
                                >
                                  <i className="fas fa-trash" />
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>

        {users && users.last_page > 1 && (
          <div className="card-footer bg-white">
            <Pagination page={users} onPageChange={setPage} />
          </div>
        )}
      </div>

      <Modal
        show={createOpen}
        onClose={() => setCreateOpen(false)}
        size="lg"
        title={
          <span>
            <i className="fas fa-user-plus text-brand me-2" />
            Add New User
          </span>
        }
        footer={
          <>
            <button type="button" className="btn btn-light" onClick={() => setCreateOpen(false)}>
              Cancel
            </button>
            <button type="submit" form="create-user-form" className="btn btn-brand px-4" disabled={saving}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              Create User
            </button>
          </>
        }
      >
        <form id="create-user-form" onSubmit={submitCreate}>
          {errorList}
          {formMessage && <div className="alert alert-danger">{formMessage}</div>}
          {renderForm(createValues, setCreateValues, "create")}
          <div className="alert alert-info mt-3 mb-0" role="alert" style={{ fontSize: "0.85rem" }}>
            <i className="fas fa-info-circle me-1" /> A default password will be automatically generated as{" "}
            <strong>
              (last name){passwordSuffix}
            </strong>
            .
          </div>
        </form>
      </Modal>

      <Modal
        show={editing !== null}
        onClose={() => setEditing(null)}
        size="lg"
        title={
          <span>
            <i className="fas fa-user-edit text-brand me-2" />
            Edit User
          </span>
        }
        footer={
          <>
            <button type="button" className="btn btn-light" onClick={() => setEditing(null)}>
              Cancel
            </button>
            <button type="submit" form="edit-user-form" className="btn btn-brand px-4" disabled={saving}>
              {saving ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
              Save Changes
            </button>
          </>
        }
      >
        <form id="edit-user-form" onSubmit={submitEdit}>
          {errorList}
          {formMessage && <div className="alert alert-danger">{formMessage}</div>}
          {renderForm(editValues, setEditValues, "edit")}

          <div className="mt-4 p-3 bg-light rounded-2 border d-flex justify-content-between align-items-center">
            <div>
              <p className="mb-1 fw-bold text-dark" style={{ fontSize: "0.85rem" }}>
                Reset User Password
              </p>
              <p className="mb-0 text-muted" style={{ fontSize: "0.8rem" }}>
                Resets the user&apos;s password to the default <code>(last name){passwordSuffix}</code>.
              </p>
            </div>
            <button
              type="button"
              className="btn btn-sm btn-outline-danger shadow-sm"
              onClick={() => editing && setConfirming({ kind: "reset", user: editing })}
            >
              <i className="fas fa-undo-alt me-1" /> Reset
            </button>
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
