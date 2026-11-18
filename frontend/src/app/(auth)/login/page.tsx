"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

const brandName = process.env.NEXT_PUBLIC_BRAND_NAME ?? "GIP Information Management System (GIMS)";
const brandShort = process.env.NEXT_PUBLIC_BRAND_SHORT ?? "GIMS";

export default function LoginPage() {
  const { status, user, login } = useAuth();
  const router = useRouter();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<string[]>([]);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (status !== "authenticated") return;
    router.replace(user?.must_change_password ? "/force-password-change" : "/dashboard");
  }, [status, user, router]);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setErrors([]);
    setSubmitting(true);
    try {
      const authenticated = await login(username, password);
      router.replace(authenticated.must_change_password ? "/force-password-change" : "/dashboard");
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        const fieldErrors = Object.values(error.errors).flat();
        setErrors(fieldErrors.length > 0 ? fieldErrors : [error.message]);
      } else if (error instanceof ApiError) {
        setErrors([error.message]);
      } else {
        setErrors(["Unable to reach the GIMS API. Check your connection and try again."]);
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

          <h2>Welcome</h2>
          <p>Welcome to the {brandName}</p>
        </div>

        <div className="form-panel">
          <form
            onSubmit={handleSubmit}
            style={{ width: "100%", display: "flex", flexDirection: "column", alignItems: "center" }}
          >
            <h1>GIMS</h1>
            <span>Please enter your credentials.</span>

            {errors.length > 0 && (
              <div
                style={{
                  marginBottom: 15,
                  color: "var(--company-primary)",
                  fontSize: "0.8rem",
                  fontWeight: 600,
                  textAlign: "center",
                }}
                role="alert"
              >
                {errors.map((message) => (
                  <div key={message}>{message}</div>
                ))}
              </div>
            )}

            <input
              type="text"
              name="username"
              placeholder="Username"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              required
              autoFocus
              autoComplete="username"
            />

            <input
              type="password"
              name="password"
              placeholder="Password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              autoComplete="current-password"
            />

            <button type="submit" className="btn-signin" disabled={submitting}>
              {submitting ? "Signing In…" : "Sign In"}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
