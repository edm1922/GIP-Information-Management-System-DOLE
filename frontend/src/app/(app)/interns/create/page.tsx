"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { InternForm, type InternFormValues } from "@/components/interns/InternForm";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Company, Intern } from "@/lib/types";

export default function CreateInternPage() {
  const { isAdmin, status } = useAuth();
  const router = useRouter();

  const [agencies, setAgencies] = useState<Company[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [loadError, setLoadError] = useState<string | null>(null);

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

  const submit = async (values: InternFormValues) => {
    setSubmitting(true);
    setErrors({});
    try {
      const response = await api.post<{ intern: Intern }>("/api/v1/interns", values);
      router.push(`/interns/${response.intern.id}`);
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
