"use client";

import { useParams } from "next/navigation";
import { useEffect, useState } from "react";

import { ApiError, api } from "@/lib/api";
import type { PublicRegistrationMeta } from "@/lib/types";

interface FormValues {
  first_name: string;
  middle_name: string;
  last_name: string;
  suffix: string;
  email: string;
  contact_number: string;
  school: string;
  course: string;
  partner_agency_id: string;
  intern_type: string;
  preferred_start_date: string;
  notes: string;
}

const EMPTY_FORM: FormValues = {
  first_name: "",
  middle_name: "",
  last_name: "",
  suffix: "",
  email: "",
  contact_number: "",
  school: "",
  course: "",
  partner_agency_id: "",
  intern_type: "",
  preferred_start_date: "",
  notes: "",
};

export default function PublicRegisterPage() {
  const params = useParams<{ token: string }>();
  const token = params?.token ?? "";

  const [meta, setMeta] = useState<PublicRegistrationMeta["registration"] | null>(null);
  const [loading, setLoading] = useState(true);
  const [blocked, setBlocked] = useState<string | null>(null);
  const [values, setValues] = useState<FormValues>(EMPTY_FORM);
  const [consent, setConsent] = useState(false);
  const [website, setWebsite] = useState("");
  const [startedAt] = useState(() => Date.now());
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [reference, setReference] = useState<string | null>(null);

  useEffect(() => {
    if (!token) return;
    api
      .get<PublicRegistrationMeta>(`/api/v1/public/registration/${token}`)
      .then((payload) => setMeta(payload.registration))
      .catch((requestError) => {
        setBlocked(requestError instanceof ApiError ? requestError.message : "This registration link is not available.");
      })
      .finally(() => setLoading(false));
  }, [token]);

  const set = (field: keyof FormValues) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
    setValues((current) => ({ ...current, [field]: event.target.value }));

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setErrors({});
    setSubmitting(true);
    try {
      const payload = await api.post<{ message: string; reference: string }>(
        `/api/v1/public/registration/${token}/applications`,
        {
          ...values,
          partner_agency_id: values.partner_agency_id ? Number(values.partner_agency_id) : null,
          consent,
          website,
          form_started_at: startedAt,
        },
      );
      setReference(payload.reference);
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setError(requestError.message);
      } else {
        setError("The application could not be submitted. Please try again.");
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="register-page">
        <div className="text-center text-muted py-5">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
          <div>Loading the application form…</div>
        </div>
      </div>
    );
  }

  if (blocked) {
    return (
      <div className="register-page">
        <div className="register-card text-center">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/image/dole-logo.png" alt="DOLE logo" className="register-logo" />
          <h1 className="h4 fw-bold mt-3">Registration unavailable</h1>
          <p className="text-muted mb-0">{blocked}</p>
          <p className="text-muted small mb-0 mt-2">Please ask the PESO office for a new registration link.</p>
        </div>
      </div>
    );
  }

  if (reference) {
    return (
      <div className="register-page">
        <div className="register-card text-center">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/image/dole-logo.png" alt="DOLE logo" className="register-logo" />
          <div className="display-6 text-success mb-2">
            <i className="fas fa-circle-check" />
          </div>
          <h1 className="h4 fw-bold">Application submitted</h1>
          <p className="text-muted">
            Thank you, {values.first_name}! Your application is now <strong>pending review</strong> by the PESO
            office. Once approved, your login credentials will be sent to <strong>{values.email}</strong>.
          </p>
          <div className="register-reference">
            Reference: <strong>{reference}</strong>
          </div>
          <div className="alert alert-warning small text-start mt-3 mb-0">
            <i className="fas fa-clock me-1" />
            <strong>No email has been sent yet.</strong> Your username and temporary password will be emailed only
            after the PESO office approves your application.
          </div>
          <p className="text-muted small mb-0 mt-3">
            Keep this reference for follow-ups. You do not need an account to check the status; the PESO office will
            contact you at your email address.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="register-page">
      <div className="register-card">
        <div className="text-center mb-3">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/image/dole-logo.png" alt="DOLE logo" className="register-logo" />
          <h1 className="h4 fw-bold mt-2 mb-1">{meta?.brand.short_name ?? "GIMS"} Intern Application</h1>
          <p className="text-muted small mb-0">
            {meta?.brand.name ?? "GIP Information Management System"} · Department of Labor and Employment
          </p>
        </div>

        <div className="alert alert-light border small">
          <i className="fas fa-circle-info me-1" style={{ color: "var(--company-primary)" }} />
          Submit your details below. After the PESO office approves your application, your username and temporary
          password will be emailed to you. You will be asked to change the temporary password on first sign-in.
        </div>

        {error && <div className="alert alert-danger py-2">{error}</div>}

        <form onSubmit={submit} noValidate>
          <div className="row g-2">
            <div className="col-sm-6">
              <label className="form-label" htmlFor="first_name">
                First name <span className="text-danger">*</span>
              </label>
              <input id="first_name" className="form-control" value={values.first_name} onChange={set("first_name")} required />
              {errors.first_name && <div className="text-danger small">{errors.first_name[0]}</div>}
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="middle_name">
                Middle name
              </label>
              <input id="middle_name" className="form-control" value={values.middle_name} onChange={set("middle_name")} />
              {errors.middle_name && <div className="text-danger small">{errors.middle_name[0]}</div>}
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="last_name">
                Last name <span className="text-danger">*</span>
              </label>
              <input id="last_name" className="form-control" value={values.last_name} onChange={set("last_name")} required />
              {errors.last_name && <div className="text-danger small">{errors.last_name[0]}</div>}
            </div>
            <div className="col-sm-3">
              <label className="form-label" htmlFor="suffix">
                Suffix
              </label>
              <input id="suffix" className="form-control" value={values.suffix} onChange={set("suffix")} placeholder="Jr., III" />
            </div>
            <div className="col-sm-3">
              <label className="form-label" htmlFor="preferred_start_date">
                Preferred start
              </label>
              <input
                id="preferred_start_date"
                type="date"
                className="form-control"
                value={values.preferred_start_date}
                onChange={set("preferred_start_date")}
              />
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="email">
                Email address (Gmail) <span className="text-danger">*</span>
              </label>
              <input
                id="email"
                type="email"
                className="form-control"
                value={values.email}
                onChange={set("email")}
                placeholder="you@gmail.com"
                autoComplete="email"
                required
              />
              <div className="form-text">Your login credentials will be sent here.</div>
              {errors.email && <div className="text-danger small">{errors.email[0]}</div>}
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="contact_number">
                Contact number
              </label>
              <input
                id="contact_number"
                className="form-control"
                value={values.contact_number}
                onChange={set("contact_number")}
                placeholder="09xx xxx xxxx"
              />
              {errors.contact_number && <div className="text-danger small">{errors.contact_number[0]}</div>}
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="school">
                School
              </label>
              <input id="school" className="form-control" value={values.school} onChange={set("school")} />
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="course">
                Course
              </label>
              <input id="course" className="form-control" value={values.course} onChange={set("course")} />
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="intern_type">
                Internship type
              </label>
              <select id="intern_type" className="form-select" value={values.intern_type} onChange={set("intern_type")}>
                <option value="">Select…</option>
                {meta?.intern_types.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </select>
              {errors.intern_type && <div className="text-danger small">{errors.intern_type[0]}</div>}
            </div>
            <div className="col-sm-6">
              <label className="form-label" htmlFor="partner_agency_id">
                Preferred partner agency
              </label>
              <select
                id="partner_agency_id"
                className="form-select"
                value={values.partner_agency_id}
                onChange={set("partner_agency_id")}
              >
                <option value="">No preference</option>
                {meta?.agencies.map((agency) => (
                  <option key={agency.id} value={agency.id}>
                    {agency.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="notes">
                Message to the PESO office (optional)
              </label>
              <textarea
                id="notes"
                className="form-control"
                rows={2}
                maxLength={1000}
                value={values.notes}
                onChange={set("notes")}
              />
            </div>

            <div className="col-12">
              <div className="form-check">
                <input
                  id="consent"
                  type="checkbox"
                  className="form-check-input"
                  checked={consent}
                  onChange={(event) => setConsent(event.target.checked)}
                  required
                />
                <label className="form-check-label small" htmlFor="consent">
                  I confirm that the information above is correct and I agree that the PESO office may process it for
                  my internship application.
                </label>
                {errors.consent && <div className="text-danger small">{errors.consent[0]}</div>}
              </div>
            </div>

            {/* Honeypot: hidden from people, tempting for bots. */}
            <div className="d-none" aria-hidden="true">
              <label htmlFor="website">Website</label>
              <input id="website" tabIndex={-1} autoComplete="off" value={website} onChange={(event) => setWebsite(event.target.value)} />
            </div>
          </div>

          <button className="btn btn-brand w-100 mt-3" type="submit" disabled={submitting}>
            {submitting ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-paper-plane me-1" />}
            Submit application
          </button>
          <p className="text-muted small text-center mt-2 mb-0">
            Already have an account? <a href="/login">Sign in</a>
          </p>
        </form>
      </div>
    </div>
  );
}
