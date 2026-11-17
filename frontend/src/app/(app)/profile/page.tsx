"use client";

import { useCallback, useEffect, useState } from "react";

import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { User } from "@/lib/types";

interface ProfileValues {
  first_name: string;
  middle_name: string;
  last_name: string;
  suffix: string;
  username: string;
}

export default function ProfilePage() {
  const { refresh } = useAuth();
  const [user, setUser] = useState<User | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [values, setValues] = useState<ProfileValues>({
    first_name: "",
    middle_name: "",
    last_name: "",
    suffix: "",
    username: "",
  });
  const [profileErrors, setProfileErrors] = useState<Record<string, string[]>>({});
  const [profileStatus, setProfileStatus] = useState<string | null>(null);
  const [savingProfile, setSavingProfile] = useState(false);

  const [passwords, setPasswords] = useState({ current_password: "", password: "", password_confirmation: "" });
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string[]>>({});
  const [passwordStatus, setPasswordStatus] = useState<string | null>(null);
  const [savingPassword, setSavingPassword] = useState(false);

  const load = useCallback(async () => {
    setLoadError(null);
    try {
      const payload = await api.get<{ user: User }>("/api/v1/profile");
      setUser(payload.user);
      setValues({
        first_name: payload.user.first_name ?? "",
        middle_name: payload.user.middle_name ?? "",
        last_name: payload.user.last_name ?? "",
        suffix: payload.user.suffix ?? "",
        username: payload.user.username,
      });
    } catch (requestError) {
      setLoadError(requestError instanceof ApiError ? requestError.message : "Unable to load your profile.");
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const saveProfile = async (event: React.FormEvent) => {
    event.preventDefault();
    setSavingProfile(true);
    setProfileErrors({});
    setProfileStatus(null);
    try {
      const response = await api.patch<{ message: string; user: User }>("/api/v1/profile", values);
      setUser(response.user);
      setProfileStatus(response.message);
      await refresh();
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setProfileErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setProfileStatus(requestError.message);
      } else {
        setProfileStatus("Your profile could not be updated.");
      }
    } finally {
      setSavingProfile(false);
    }
  };

  const savePassword = async (event: React.FormEvent) => {
    event.preventDefault();
    setSavingPassword(true);
    setPasswordErrors({});
    setPasswordStatus(null);
    try {
      const response = await api.put<{ message: string }>("/api/v1/auth/password", passwords);
      setPasswordStatus(response.message);
      setPasswords({ current_password: "", password: "", password_confirmation: "" });
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setPasswordErrors(requestError.errors);
        if (Object.keys(requestError.errors).length === 0) setPasswordStatus(requestError.message);
      } else {
        setPasswordStatus("Your password could not be updated.");
      }
    } finally {
      setSavingPassword(false);
    }
  };

  if (loadError) {
    return (
      <div className="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span>{loadError}</span>
        <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
          Retry
        </button>
      </div>
    );
  }

  if (!user) {
    return (
      <div className="text-center text-muted py-5">
        <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
        <div>Loading your profile…</div>
      </div>
    );
  }

  const initials = user.name
    .split(" ")
    .map((part) => part.slice(0, 1))
    .slice(0, 2)
    .join("")
    .toUpperCase();

  const errorList = (errors: Record<string, string[]>) =>
    Object.keys(errors).length > 0 ? (
      <div className="alert alert-danger">
        <ul className="mb-0 ps-3">
          {Object.entries(errors).map(([key, messages]) =>
            messages.map((message) => <li key={`${key}-${message}`}>{message}</li>),
          )}
        </ul>
      </div>
    ) : null;

  return (
    <div className="container py-4">
      <div className="row justify-content-center">
        <div className="col-lg-10 col-xl-9">
          <div className="d-flex justify-content-between align-items-center mb-4">
            <h5 className="text-uppercase text-muted mb-0" style={{ letterSpacing: "0.05em", fontWeight: 600 }}>
              User Profile Management
            </h5>
            <a href="/dashboard" className="btn btn-brand btn-sm">
              <i className="fas fa-home me-1" /> Home
            </a>
          </div>

          <div className="file-panel p-4 mb-4">
            <div className="profile-header border-bottom-0 mb-0 pb-0">
              <div className="profile-avatar profile-avatar--large">{initials}</div>
              <div className="profile-info">
                <h1 className="text-uppercase fw-bold mb-1" style={{ fontSize: "2.2rem", letterSpacing: "-0.01em" }}>
                  {user.name}
                </h1>
                <div className="profile-meta">
                  <span>{user.username}</span>
                  <span className="dot" />
                  <span>
                    Role: <span className="text-uppercase fw-bold">{user.role}</span>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div className="d-flex flex-column gap-4">
            <div className="profile-card shadow-sm border-0">
              <div className="profile-card__body p-4">
                <section>
                  <header className="mb-4">
                    <h2 className="h5 fw-bold text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>
                      Profile Information
                    </h2>
                    <p className="text-sm text-muted mb-0">
                      Update your account&apos;s profile information and username.
                    </p>
                  </header>

                  <form onSubmit={saveProfile}>
                    {errorList(profileErrors)}
                    {profileStatus && (
                      <div className={`alert ${Object.keys(profileErrors).length === 0 ? "alert-success" : "alert-danger"}`}>
                        {profileStatus}
                      </div>
                    )}

                    <div className="row g-3">
                      <div className="col-md-3">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="first_name">
                          First Name
                        </label>
                        <input
                          id="first_name"
                          className="form-control field-input"
                          value={values.first_name}
                          onChange={(event) => setValues({ ...values, first_name: event.target.value })}
                          required
                          autoComplete="given-name"
                        />
                      </div>
                      <div className="col-md-3">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="middle_name">
                          Middle Name
                        </label>
                        <input
                          id="middle_name"
                          className="form-control field-input"
                          value={values.middle_name}
                          onChange={(event) => setValues({ ...values, middle_name: event.target.value })}
                          autoComplete="additional-name"
                        />
                      </div>
                      <div className="col-md-3">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="last_name">
                          Last Name
                        </label>
                        <input
                          id="last_name"
                          className="form-control field-input"
                          value={values.last_name}
                          onChange={(event) => setValues({ ...values, last_name: event.target.value })}
                          required
                          autoComplete="family-name"
                        />
                      </div>
                      <div className="col-md-3">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="suffix">
                          Suffix
                        </label>
                        <input
                          id="suffix"
                          className="form-control field-input"
                          value={values.suffix}
                          onChange={(event) => setValues({ ...values, suffix: event.target.value })}
                          autoComplete="honorific-suffix"
                        />
                      </div>
                      <div className="col-md-12">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="username">
                          Username
                        </label>
                        <input
                          id="username"
                          className="form-control field-input"
                          value={values.username}
                          onChange={(event) => setValues({ ...values, username: event.target.value })}
                          required
                          autoComplete="username"
                        />
                      </div>
                    </div>

                    <div className="d-flex justify-content-end mt-4">
                      <button type="submit" className="btn btn-brand px-4 py-2 rounded-2 shadow-sm" disabled={savingProfile}>
                        {savingProfile ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
                        Save Changes
                      </button>
                    </div>
                  </form>
                </section>
              </div>
            </div>

            <div className="profile-card shadow-sm border-0">
              <div className="profile-card__body p-4">
                <section>
                  <header className="mb-4">
                    <h2 className="h5 fw-bold text-uppercase mb-1" style={{ letterSpacing: "0.05em" }}>
                      Update Password
                    </h2>
                    <p className="text-sm text-muted mb-0">Update your password to stay secure.</p>
                  </header>

                  <form onSubmit={savePassword}>
                    {errorList(passwordErrors)}
                    {passwordStatus && (
                      <div className={`alert ${Object.keys(passwordErrors).length === 0 ? "alert-success" : "alert-danger"}`}>
                        {passwordStatus}
                      </div>
                    )}

                    <div className="row g-3">
                      <div className="col-md-4">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="current_password">
                          Current Password
                        </label>
                        <input
                          id="current_password"
                          type="password"
                          className="form-control field-input"
                          value={passwords.current_password}
                          onChange={(event) => setPasswords({ ...passwords, current_password: event.target.value })}
                          autoComplete="current-password"
                          required
                        />
                      </div>
                      <div className="col-md-4">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="new_password">
                          New Password
                        </label>
                        <input
                          id="new_password"
                          type="password"
                          className="form-control field-input"
                          value={passwords.password}
                          onChange={(event) => setPasswords({ ...passwords, password: event.target.value })}
                          autoComplete="new-password"
                          required
                        />
                      </div>
                      <div className="col-md-4">
                        <label className="form-label fw-bold text-sm mb-1" htmlFor="password_confirmation">
                          Confirm Password
                        </label>
                        <input
                          id="password_confirmation"
                          type="password"
                          className="form-control field-input"
                          value={passwords.password_confirmation}
                          onChange={(event) => setPasswords({ ...passwords, password_confirmation: event.target.value })}
                          autoComplete="new-password"
                          required
                        />
                      </div>
                    </div>

                    <div className="d-flex justify-content-end mt-4">
                      <button type="submit" className="btn btn-brand px-4 py-2 rounded-2 shadow-sm" disabled={savingPassword}>
                        {savingPassword ? <i className="fas fa-circle-notch fa-spin me-1" /> : null}
                        Update Password
                      </button>
                    </div>
                  </form>
                </section>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
