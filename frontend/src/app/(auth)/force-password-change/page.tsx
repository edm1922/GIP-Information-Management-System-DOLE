"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";

const brandShort = process.env.NEXT_PUBLIC_BRAND_SHORT ?? "GIMS";

export default function ForcePasswordChangePage() {
  const { status, user, refresh } = useAuth();
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmation, setShowConfirmation] = useState(false);
  const [errors, setErrors] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (status === "guest") {
      router.replace("/login");
      return;
    }
    if (status === "authenticated" && user && !user.must_change_password) {
      router.replace("/dashboard");
    }
  }, [status, user, router]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setErrors([]);
    setSubmitting(true);
    try {
      await api.post("/api/v1/auth/force-password-change", {
        password,
        password_confirmation: confirmation,
      });
      await refresh();
      router.replace("/dashboard");
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        const fieldErrors = Object.values(error.errors).flat();
        setErrors(fieldErrors.length > 0 ? fieldErrors : [error.message]);
      } else if (error instanceof ApiError) {
        setErrors([error.message]);
      } else {
        setErrors(["Unable to reach the GIMS API. Please try again."]);
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="login-page-wrapper">
      <div className="login-container">
        <div className="overlay-panel">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src="/image/dole-logo.png"
            alt={`${brandShort} Logo`}
            style={{ height: 120, marginBottom: 16, maxWidth: "90%", objectFit: "contain" }}
          />

          <h2 style={{ fontSize: "1.4rem", fontWeight: 700, marginBottom: 10, color: "#111827" }}>Security Notice</h2>
          <p style={{ fontSize: "0.88rem", lineHeight: 1.6, color: "#4b5563", marginBottom: 20 }}>
            As this is your first time logging in, you are required to change your temporary default password
            before proceeding.
          </p>

          {user && (
            <div
              style={{
                background: "rgba(30, 66, 155, 0.08)",
                border: "1px solid var(--company-primary-border)",
                padding: "8px 16px",
                borderRadius: 20,
                fontSize: "0.8rem",
                color: "var(--company-primary)",
                fontWeight: 600,
                display: "inline-flex",
                alignItems: "center",
                gap: 6,
              }}
            >
              <i className="fas fa-user-circle" />
              <span>{user.username}</span>
              <span style={{ opacity: 0.6 }}>•</span>
              <span style={{ textTransform: "capitalize" }}>{user.role.replace("_", " ")}</span>
            </div>
          )}
        </div>

        <div className="form-panel">
          <form
            onSubmit={handleSubmit}
            style={{ width: "100%", display: "flex", flexDirection: "column", alignItems: "center" }}
          >
            <div
              style={{
                width: 44,
                height: 44,
                borderRadius: "50%",
                background: "var(--company-primary-light)",
                color: "var(--company-primary)",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                fontSize: "1.2rem",
                marginBottom: 12,
              }}
            >
              <i className="fas fa-shield-alt" />
            </div>

            <h1 style={{ fontSize: "1.6rem", fontWeight: 800, margin: "0 0 6px", color: "#111827" }}>
              Set New Password
            </h1>
            <span style={{ fontSize: "0.82rem", color: "#6b7280", marginBottom: 20, textAlign: "center" }}>
              Enter your new personal password to secure your account.
            </span>

            {errors.length > 0 && (
              <div
                role="alert"
                style={{
                  width: "100%",
                  marginBottom: 16,
                  padding: "10px 14px",
                  borderRadius: 8,
                  background: "#fee2e2",
                  borderLeft: "3px solid #ef4444",
                  color: "#b91c1c",
                  fontSize: "0.82rem",
                  fontWeight: 500,
                  textAlign: "left",
                }}
              >
                {errors.map((message) => (
                  <div key={message} style={{ marginBottom: 2 }}>
                    <i className="fas fa-exclamation-circle me-1" /> {message}
                  </div>
                ))}
              </div>
            )}

            <div className="input-group-custom">
              <input
                type={showPassword ? "text" : "password"}
                id="password"
                name="password"
                placeholder="New Password (min. 8 characters)"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                autoFocus
                autoComplete="new-password"
              />
              <button
                type="button"
                className="toggle-btn"
                onClick={() => setShowPassword((value) => !value)}
                aria-label="Toggle password visibility"
              >
                <i className={`fas ${showPassword ? "fa-eye-slash" : "fa-eye"}`} />
              </button>
            </div>

            <div className="input-group-custom" style={{ marginBottom: 22 }}>
              <input
                type={showConfirmation ? "text" : "password"}
                id="password_confirmation"
                name="password_confirmation"
                placeholder="Confirm New Password"
                value={confirmation}
                onChange={(event) => setConfirmation(event.target.value)}
                required
                autoComplete="new-password"
              />
              <button
                type="button"
                className="toggle-btn"
                onClick={() => setShowConfirmation((value) => !value)}
                aria-label="Toggle password confirmation visibility"
              >
                <i className={`fas ${showConfirmation ? "fa-eye-slash" : "fa-eye"}`} />
              </button>
            </div>

            <button type="submit" className="btn-submit" disabled={submitting}>
              <i className="fas fa-key" /> {submitting ? "Saving…" : "Save & Proceed"}
            </button>
          </form>

          <form
            onSubmit={async (event) => {
              event.preventDefault();
              await api.post("/api/v1/auth/logout").catch(() => undefined);
              await refresh();
              router.replace("/login");
            }}
            style={{ marginTop: 18 }}
          >
            <button type="submit" className="btn-logout">
              <i className="fas fa-sign-out-alt" /> Logout instead
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
