"use client";

import { useEffect, useState } from "react";

import { InternForm, type InternFormValues } from "@/components/interns/InternForm";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Company, Intern } from "@/lib/types";

export default function CreateInternPage() {
  const { isAdmin, status } = useAuth();

  const [agencies, setAgencies] = useState<Company[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [loadError, setLoadError] = useState<string | null>(null);
  // The API returns the random one-time password exactly once, on creation.
  const [created, setCreated] = useState<{ id: number; password: string | null } | null>(null);

  useEffect(() => {
    if (status !== "authenticated" || !isAdmin) return;
    api
      .get<{ agencies: Company[] }>("/api/v1/interns/agencies")
      .then((payload) => setAgencies(payload.agencies))
      .catch((requestError: unknown) =>
        setLoadError(requestError instanceof ApiError ? requestError.message : "Unable to load partner agencies."),
      );
  }, [status, isAdmin]);

  if (status === "authenticated" && !isAdmin) {
    return (
      <div className="alert alert-danger" role="alert">
        You do not have permission to create interns.
      </div>
    );
  }

  if (created) {
    return (
      <>
        <div className="d-flex justify-content-between align-items-center mb-4">
          <div>
            <h2 className="h4 mb-1 fw-bold">Intern Created</h2>
            <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
              The intern record and its login account are ready.
            </p>
          </div>
        </div>

        <div className="card shadow-sm">
          <div className="card-body">
            <div className="alert alert-success mb-3" role="status">
              <i className="fas fa-check-circle me-2" />
              The intern was created successfully.
            </div>

            {created.password ? (
              <div className="alert alert-warning mb-3" role="alert">
                <p className="mb-1 fw-semibold">Temporary password (shown once)</p>
                <code style={{ fontSize: "1rem" }}>{created.password}</code>
                <p className="mb-0 mt-2 small text-muted">
                  Copy it now and give it to the intern. It cannot be shown again; a reset issues a new one-time password.
                </p>
              </div>
            ) : (
              <div className="alert alert-info mb-3" role="alert">
                The temporary password is sent through the configured credential channel and is not shown here.
              </div>
            )}

            <div className="d-flex gap-2">
              <a className="btn btn-brand" href={`/interns/${created.id}`}>
                Open intern profile
              </a>
              <a className="btn btn-outline-secondary" href="/interns">
                Back to interns
              </a>
            </div>
          </div>
        </div>
      </>
    );
  }

  const submit = async (values: InternFormValues) => {
    setSubmitting(true);
    setErrors({});
    try {
      const response = await api.post<{ intern: Intern; default_password?: string | null }>("/api/v1/interns", values);
      setCreated({ id: response.intern.id, password: response.default_password ?? null });
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setErrors({ form: [requestError.message] });
      } else {
        setErrors({ form: ["Unable to create the intern."] });
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">Add Intern</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Create the intern record and its login account in one step.
          </p>
        </div>
        <a className="btn btn-outline-secondary" href="/interns">
          <i className="fas fa-arrow-left" /> Back
        </a>
      </div>

      {loadError && <div className="alert alert-danger">{loadError}</div>}

      <div className="card shadow-sm">
        <div className="card-body">
          <InternForm
            agencies={agencies}
            submitting={submitting}
            errors={errors}
            onSubmit={submit}
            submitLabel="Create Intern"
            cancelHref="/interns"
          />
        </div>
      </div>
    </>
  );
}
