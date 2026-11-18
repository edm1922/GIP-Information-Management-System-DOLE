"use client";

import { useCallback, useEffect, useState } from "react";

import { Modal } from "@/components/ui/Modal";
import { ApiError, api } from "@/lib/api";
import type { RegistrationLink, RegistrationLinkResponse } from "@/lib/types";

function formatDate(value: string | null): string {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "—" : date.toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" });
}

export function RegistrationLinkModal({ show, onClose }: { show: boolean; onClose: () => void }) {
  const [data, setData] = useState<RegistrationLinkResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [qr, setQr] = useState<string | null>(null);
  const [ttl, setTtl] = useState(30);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setData(await api.get<RegistrationLinkResponse>("/api/v1/intern-registration-link"));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load the registration link.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (show) load();
  }, [show, load]);

  const link: RegistrationLink | null = data?.link ?? null;

  useEffect(() => {
    if (!link?.url) {
      setQr(null);
      return;
    }
    let cancelled = false;
    import("qrcode")
      .then((qrcode) => qrcode.toDataURL(link.url, { width: 480, margin: 2, color: { dark: "#1E429B", light: "#ffffff" } }))
      .then((url) => {
        if (!cancelled) setQr(url);
      })
      .catch(() => {
        if (!cancelled) setQr(null);
      });
    return () => {
      cancelled = true;
    };
  }, [link?.url]);

  const generate = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.post("/api/v1/intern-registration-link", { expires_in_days: ttl });
      await load();
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "The registration link could not be generated.");
    } finally {
      setBusy(false);
    }
  };

  const revoke = async () => {
    if (!window.confirm("Revoke this registration link? The shared URL and QR code will stop working immediately.")) return;
    setBusy(true);
    setError(null);
    try {
      await api.delete("/api/v1/intern-registration-link");
      await load();
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "The link could not be revoked.");
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    if (!link) return;
    try {
      await navigator.clipboard.writeText(link.url);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setError("Copying failed — select the link text and copy it manually.");
    }
  };

  const download = () => {
    if (!qr) return;
    const anchor = document.createElement("a");
    anchor.href = qr;
    anchor.download = "gims-registration-qr.png";
    anchor.click();
  };

  return (
    <Modal show={show} onClose={onClose} title="Intern Self-Registration" size="lg">
      <p className="text-muted" style={{ fontSize: "0.85rem" }}>
        Share this link or QR code with prospective interns. They can submit an application from their phone; nothing is
        created until an Admin or PESO Manager approves it.
      </p>

      {error && <div className="alert alert-danger py-2">{error}</div>}

      {loading && !data && (
        <div className="text-center text-muted py-4">
          <i className="fas fa-circle-notch fa-spin me-2" /> Loading…
        </div>
      )}

      {data && !link && (
        <div className="alert alert-info">
          There is no active registration link yet. Generate one to start accepting applications.
        </div>
      )}

      {link && (
        <div className="border rounded-3 p-3 mb-3">
          <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
            <span className={`badge ${link.is_active ? "bg-success" : "bg-secondary"}`}>
              {link.is_active ? "Active" : "Revoked / expired"}
            </span>
            <span className="text-muted small">
              Expires {formatDate(link.expires_at)} · {link.uses_count} application{link.uses_count === 1 ? "" : "s"}
            </span>
          </div>

          <div className="d-flex flex-column flex-md-row gap-3 align-items-md-center">
            {qr ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={qr} alt="Registration QR code" className="register-qr" />
            ) : (
              <div className="text-muted small">QR code unavailable.</div>
            )}

            <div className="flex-grow-1" style={{ minWidth: 0 }}>
              <label className="form-label small mb-1" htmlFor="registration-link-url">
                Registration URL
              </label>
              <input
                id="registration-link-url"
                className="form-control font-monospace"
                style={{ fontSize: "0.8rem" }}
                value={link.url}
                readOnly
                onFocus={(event) => event.target.select()}
              />
              <div className="form-text">Anyone with this link can submit an application until it expires or is revoked.</div>
            </div>
          </div>

          <div className="d-flex flex-wrap gap-2 mt-3">
            <button type="button" className="btn btn-brand" onClick={copy}>
              <i className={`fas ${copied ? "fa-check" : "fa-link"} me-1`} /> {copied ? "Copied" : "Copy link"}
            </button>
            <button type="button" className="btn btn-outline-secondary" onClick={download} disabled={!qr}>
              <i className="fas fa-download me-1" /> Download QR
            </button>
            <button type="button" className="btn btn-outline-danger ms-auto" onClick={revoke} disabled={busy}>
              <i className="fas fa-ban me-1" /> Revoke
            </button>
          </div>
        </div>
      )}

      <div className="row g-2 align-items-end">
        <div className="col-sm-6">
          <label className="form-label" htmlFor="link-ttl">
            New link lifetime
          </label>
          <select
            id="link-ttl"
            className="form-select"
            value={ttl}
            onChange={(event) => setTtl(Number(event.target.value))}
          >
            <option value={1}>1 day</option>
            <option value={7}>7 days</option>
            <option value={30}>30 days</option>
            <option value={90}>90 days</option>
          </select>
        </div>
        <div className="col-sm-6">
          <button type="button" className="btn btn-outline-primary w-100" onClick={generate} disabled={busy}>
            {busy ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-rotate me-1" />}
            {link ? "Regenerate link" : "Generate link"}
          </button>
        </div>
      </div>
      {link && (
        <div className="form-text mt-2">Generating a new link revokes the current one immediately.</div>
      )}
    </Modal>
  );
}
