"use client";

import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { InternForm, type InternFormValues } from "@/components/interns/InternForm";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Company, Intern } from "@/lib/types";

export default function EditInternPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const { isAdmin, status } = useAuth();

  const [intern, setIntern] = useState<Intern | null>(null);
  const [agencies, setAgencies] = useState<Company[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (status !== "authenticated" || !isAdmin) return;
    Promise.all([
      api.get<{ intern: Intern }>(`/api/v1/interns/${params.id}`),
      api.get<{ agencies: Company[] }>("/api/v1/interns/agencies"),
    ])
      .then(([internPayload, agencyPayload]) => {
        setIntern(internPayload.intern);
        setAgencies(agencyPayload.agencies);
      })
      .catch((requestError: unknown) =>
        setLoadError(requestError instanceof ApiError ? requestError.message : "Unable to load this intern."),
      );
  }, [status, isAdmin, params.id]);

  if (status === "authenticated" && !isAdmin) {
    return (
      <div className="alert alert-danger" role="alert">
        You do not have permission to edit interns.
      </div>
    );
  }

  const submit = async (values: InternFormValues) => {
    setSubmitting(true);
    setErrors({});
    try {
      await api.put(`/api/v1/interns/${params.id}`, values);
      router.push(`/interns/${params.id}`);
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setErrors(Object.keys(requestError.errors).length ? requestError.errors : { form: [requestError.message] });
      } else {
        setErrors({ form: ["Unable to save the intern."] });
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h2 className="h4 mb-1 fw-bold">Edit Intern</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            {intern ? `Update ${intern.name}'s record and login account.` : "Update the intern record and login account."}
          </p>
        </div>
        <a className="btn btn-outline-secondary" href={`/interns/${params.id}`}>
          <i className="fas fa-arrow-left" /> Back
        </a>
      </div>

      {loadError && <div className="alert alert-danger">{loadError}</div>}

      <div className="card shadow-sm">
        <div className="card-body">
          {intern ? (
            <InternForm
              intern={intern}
              agencies={agencies}
              submitting={submitting}
              errors={errors}
              onSubmit={submit}
              submitLabel="Save Changes"
              cancelHref={`/interns/${params.id}`}
            />
          ) : (
            !loadError && (
              <div className="text-center text-muted py-4">
                <i className="fas fa-circle-notch fa-spin me-2" /> Loading intern…
              </div>
            )
          )}
        </div>
      </div>
    </>
  );
}
