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
      if (error instanceof ApiError && error.status === 401) {
        // The session cookie is stale/invalid (e.g. the account was reset while
        // this tab was open). Clear it and start over at the login page.
        try {
          window.sessionStorage.setItem("gims_session_expired", "1");
        } catch {
          // private mode / storage disabled — still redirect
        }
        await api.post("/api/v1/auth/logout").catch(() => undefined);
        window.location.assign("/login");
        return;
      }
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
          <img src="/image/dole-logo.png" alt={`${brandShort} Logo`} className="login-logo" />

          <h2 style={{ fontSize: "1.6rem", marginBottom: 8 }}>Security Notice</h2>
          <p style={{ marginBottom: 18 }}>
            This is your first time signing in, so you need to replace your temporary password before continuing.
            Choose a password you have not used for GIMS before.
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
          <form onSubmit={handleSubmit} className="w-100 d-flex flex-column align-items-center">
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
                marginBottom: 10,
              }}
            >
              <i className="fas fa-shield-alt" />
            </div>

            <h1 style={{ fontSize: "1.7rem", margin: "0 0 4px" }}>Set New Password</h1>
            <span style={{ marginBottom: 18, textAlign: "center" }}>
              Use at least 8 characters. You will use this password for future sign-ins.
            </span>

            {errors.length > 0 && (
              <div className="alert alert-danger w-100 py-2" role="alert" style={{ fontSize: "0.85rem" }}>
                {errors.map((message) => (
                  <div key={message}>
                    <i className="fas fa-exclamation-circle me-1" /> {message}
                  </div>
                ))}
              </div>
            )}

            <div className="password-field">
              <input
                type={showPassword ? "text" : "password"}
                id="password"
                name="password"
                placeholder="New password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                minLength={8}
                autoFocus
                autoComplete="new-password"
              />
              <button
                type="button"
                className="toggle-btn"
                onClick={() => setShowPassword((value) => !value)}
                aria-label={showPassword ? "Hide password" : "Show password"}
                title={showPassword ? "Hide password" : "Show password"}
              >
                <i className={`fas ${showPassword ? "fa-eye-slash" : "fa-eye"}`} />
              </button>
            </div>

            <div className="password-field" style={{ marginBottom: 20 }}>
              <input
                type={showConfirmation ? "text" : "password"}
                id="password_confirmation"
                name="password_confirmation"
                placeholder="Confirm new password"
                value={confirmation}
                onChange={(event) => setConfirmation(event.target.value)}
                required
                minLength={8}
                autoComplete="new-password"
              />
              <button
                type="button"
                className="toggle-btn"
                onClick={() => setShowConfirmation((value) => !value)}
                aria-label={showConfirmation ? "Hide password confirmation" : "Show password confirmation"}
                title={showConfirmation ? "Hide password confirmation" : "Show password confirmation"}
              >
                <i className={`fas ${showConfirmation ? "fa-eye-slash" : "fa-eye"}`} />
              </button>
            </div>

            <button type="submit" className="btn-signin" disabled={submitting}>
              {submitting ? <i className="fas fa-circle-notch fa-spin me-1" /> : <i className="fas fa-key me-1" />}
              {submitting ? "Saving…" : "Save & Proceed"}
            </button>
          </form>

          <form
            onSubmit={async (event) => {
              event.preventDefault();
              await api.post("/api/v1/auth/logout").catch(() => undefined);
              await refresh();
              router.replace("/login");
            }}
            className="w-100 text-center mt-3"
          >
            <button type="submit" className="btn btn-link text-muted p-0" style={{ fontSize: "0.85rem" }}>
              <i className="fas fa-sign-out-alt me-1" /> Logout instead
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
