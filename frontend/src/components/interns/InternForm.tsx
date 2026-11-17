"use client";

import { useState } from "react";

import { dateInputValue } from "@/lib/format";
import type { Company, Intern } from "@/lib/types";

export const INTERN_STATUSES = ["active", "inactive", "completed"] as const;
export const INTERN_TYPES = ["GIP", "OJT", "SPES", "Work Immersion"] as const;
export const DEFAULT_REQUIRED_HOURS = 486;
const PASSWORD_SUFFIX = process.env.NEXT_PUBLIC_DEFAULT_PASSWORD_SUFFIX ?? "gip";

export type InternFormValues = Record<string, string>;

interface InternFormProps {
  intern?: Intern | null;
  agencies: Company[];
  submitting: boolean;
  errors: Record<string, string[]>;
  onSubmit: (values: InternFormValues) => void;
  submitLabel: string;
  cancelHref: string;
}

function initialValues(intern?: Intern | null): InternFormValues {
  return {
    first_name: intern?.user?.first_name ?? "",
    middle_name: intern?.user?.middle_name ?? "",
    last_name: intern?.user?.last_name ?? "",
    suffix: intern?.user?.suffix ?? "",
    username: intern?.user?.username ?? "",
    partner_agency_id: intern?.partner_agency_id ? String(intern.partner_agency_id) : "",
    intern_type: intern?.intern_type ?? "",
    batch: intern?.batch ?? "",
    contract_start_date: dateInputValue(intern?.contract_start_date),
    contract_end_date: dateInputValue(intern?.contract_end_date),
    required_hours: String(intern?.required_hours ?? DEFAULT_REQUIRED_HOURS),
    rendered_hours: String(intern?.rendered_hours ?? 0),
    status: intern?.status ?? "active",
    school: intern?.school ?? "",
    course: intern?.course ?? "",
    contact_number: intern?.contact_number ?? "",
    supervisor: intern?.supervisor ?? "",
    beneficiary: intern?.beneficiary ?? "",
  };
}

/** The intern create/edit fields shared by both forms (`interns/_form.blade.php`). */
export function InternForm({ intern, agencies, submitting, errors, onSubmit, submitLabel, cancelHref }: InternFormProps) {
  const [values, setValues] = useState<InternFormValues>(() => initialValues(intern));

  const set = (field: string) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setValues((current) => ({ ...current, [field]: event.target.value }));

  const errorFor = (field: string) => errors[field]?.[0];

  const inputClass = (field: string) => `form-control ${errorFor(field) ? "is-invalid" : ""}`;
  const selectClass = (field: string) => `form-select ${errorFor(field) ? "is-invalid" : ""}`;

  const defaultPassword = `${(values.last_name || "lastname").toLowerCase()}${PASSWORD_SUFFIX}`;
  const hasErrors = Object.keys(errors).length > 0;

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit(values);
      }}
    >
      {hasErrors && (
        <div className="alert alert-danger">
          <ul className="mb-0 ps-3">
            {Object.entries(errors).map(([field, messages]) =>
              messages.map((message) => <li key={`${field}-${message}`}>{message}</li>),
            )}
          </ul>
        </div>
      )}

      <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
        Login Account
      </h6>
      <div className="row g-3 mb-4">
        <div className="col-md-3">
          <label className="form-label" htmlFor="first_name">
            First Name <span className="text-danger">*</span>
          </label>
          <input id="first_name" className={inputClass("first_name")} value={values.first_name} onChange={set("first_name")} required />
          {errorFor("first_name") && <div className="invalid-feedback d-block">{errorFor("first_name")}</div>}
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="middle_name">
            Middle Name
          </label>
          <input id="middle_name" className={inputClass("middle_name")} value={values.middle_name} onChange={set("middle_name")} />
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="last_name">
            Last Name <span className="text-danger">*</span>
          </label>
          <input id="last_name" className={inputClass("last_name")} value={values.last_name} onChange={set("last_name")} required />
          {errorFor("last_name") && <div className="invalid-feedback d-block">{errorFor("last_name")}</div>}
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="suffix">
            Suffix
          </label>
          <input id="suffix" className={inputClass("suffix")} value={values.suffix} onChange={set("suffix")} />
        </div>
        <div className="col-md-4">
          <label className="form-label" htmlFor="username">
            Username <span className="text-danger">*</span>
          </label>
          <input id="username" className={inputClass("username")} value={values.username} onChange={set("username")} required />
          {errorFor("username") ? (
            <div className="invalid-feedback d-block">{errorFor("username")}</div>
          ) : (
            <div className="form-text">
              {intern ? "Default password on reset" : "Default password"}: <code>{defaultPassword}</code>
            </div>
          )}
        </div>
      </div>

      <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
        Placement &amp; Contract
      </h6>
      <div className="row g-3 mb-4">
        <div className="col-md-6">
          <label className="form-label" htmlFor="partner_agency_id">
            Partner Agency
          </label>
          <select id="partner_agency_id" className={selectClass("partner_agency_id")} value={values.partner_agency_id} onChange={set("partner_agency_id")}>
            <option value="">— None —</option>
            {agencies.map((agency) => (
              <option key={agency.id} value={agency.id}>
                {agency.name}
              </option>
            ))}
          </select>
          {errorFor("partner_agency_id") && <div className="invalid-feedback d-block">{errorFor("partner_agency_id")}</div>}
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="intern_type">
            Intern Type
          </label>
          <select id="intern_type" className={selectClass("intern_type")} value={values.intern_type} onChange={set("intern_type")}>
            <option value="">— None —</option>
            {INTERN_TYPES.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </select>
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="batch">
            Batch
          </label>
          <input id="batch" className={inputClass("batch")} value={values.batch} onChange={set("batch")} placeholder="e.g. 2026-A" />
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="contract_start_date">
            Contract Start
          </label>
          <input id="contract_start_date" type="date" className={inputClass("contract_start_date")} value={values.contract_start_date} onChange={set("contract_start_date")} />
        </div>
        <div className="col-md-3">
          <label className="form-label" htmlFor="contract_end_date">
            Contract End
          </label>
          <input id="contract_end_date" type="date" className={inputClass("contract_end_date")} value={values.contract_end_date} onChange={set("contract_end_date")} />
          {errorFor("contract_end_date") && <div className="invalid-feedback d-block">{errorFor("contract_end_date")}</div>}
        </div>
        <div className="col-md-2">
          <label className="form-label" htmlFor="required_hours">
            Required Hours
          </label>
          <input id="required_hours" type="number" min={1} className={inputClass("required_hours")} value={values.required_hours} onChange={set("required_hours")} />
        </div>
        <div className="col-md-2">
          <label className="form-label" htmlFor="rendered_hours">
            Rendered Hours
          </label>
          <input id="rendered_hours" type="number" min={0} className={inputClass("rendered_hours")} value={values.rendered_hours} onChange={set("rendered_hours")} />
        </div>
        <div className="col-md-2">
          <label className="form-label" htmlFor="status">
            Status
          </label>
          <select id="status" className={selectClass("status")} value={values.status} onChange={set("status")}>
            {INTERN_STATUSES.map((status) => (
              <option key={status} value={status}>
                {status.charAt(0).toUpperCase() + status.slice(1)}
              </option>
            ))}
          </select>
        </div>
      </div>

      <h6 className="text-uppercase text-muted fw-bold mb-3" style={{ fontSize: "0.75rem" }}>
        Profile
      </h6>
      <div className="row g-3">
        <div className="col-md-4">
          <label className="form-label" htmlFor="school">
            School
          </label>
          <input id="school" className={inputClass("school")} value={values.school} onChange={set("school")} />
        </div>
        <div className="col-md-4">
          <label className="form-label" htmlFor="course">
            Course
          </label>
          <input id="course" className={inputClass("course")} value={values.course} onChange={set("course")} />
        </div>
        <div className="col-md-2">
          <label className="form-label" htmlFor="contact_number">
            Contact No.
          </label>
          <input id="contact_number" className={inputClass("contact_number")} value={values.contact_number} onChange={set("contact_number")} />
        </div>
        <div className="col-md-2">
          <label className="form-label" htmlFor="supervisor">
            Supervisor
          </label>
          <input id="supervisor" className={inputClass("supervisor")} value={values.supervisor} onChange={set("supervisor")} />
        </div>
        <div className="col-md-12">
          <label className="form-label" htmlFor="beneficiary">
            Beneficiary
          </label>
          <input
            id="beneficiary"
            className={inputClass("beneficiary")}
            value={values.beneficiary}
            onChange={set("beneficiary")}
            maxLength={255}
            placeholder="Beneficiary name or reference as it appears on the program documents"
          />
          <div className="form-text">Optional. Recorded on the intern&apos;s profile and used when matching program records.</div>
        </div>
      </div>

      <div className="d-flex justify-content-end gap-2 mt-4">
        <a className="btn btn-outline-secondary" href={cancelHref}>
          Cancel
        </a>
        <button className="btn btn-brand" type="submit" disabled={submitting}>
          {submitting ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
          {submitLabel}
        </button>
      </div>
    </form>
  );
}
