"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";

const brandShort = process.env.NEXT_PUBLIC_BRAND_SHORT ?? "GIMS";

export function Topbar({ onToggleSidebar }: { onToggleSidebar: () => void }) {
  const { user, logout } = useAuth();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [unread, setUnread] = useState(0);
  const wrapperRef = useRef<HTMLDivElement | null>(null);

  // Refetch on navigation so the badge reflects notifications just marked read.
  useEffect(() => {
    let cancelled = false;
    api
      .get<{ unread_count?: number }>("/api/v1/notifications")
      .then((payload) => {
        if (!cancelled) setUnread(payload.unread_count ?? 0);
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [pathname]);

  useEffect(() => {
    if (!open) return;
    const close = (event: MouseEvent) => {
      if (!wrapperRef.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, [open]);

  return (
    <nav className="navbar navbar-dark fixed-top topbar" role="navigation" aria-label="Primary">
      <div className="container-fluid topbar__inner">
        <button
          className="btn topbar__icon-btn me-2"
          type="button"
          title="Toggle menu"
          aria-label="Toggle sidebar"
          onClick={onToggleSidebar}
        >
          <i className="fas fa-bars" />
        </button>

        <Link className="navbar-brand d-flex align-items-center topbar__brand" href="/dashboard">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/image/dole-emblem.png" alt={`${brandShort} Logo`} height={35} className="me-2" />
          {brandShort}
        </Link>

        <div className="topbar__actions ms-auto">
          <Link
            className="btn topbar__icon-btn topbar__notification position-relative"
            href="/notifications"
            title="Notifications"
            aria-label={`Notifications${unread > 0 ? ` (${unread} unread)` : ""}`}
          >
            <i className="fas fa-bell" />
            {unread > 0 && (
              <span className="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                {unread > 99 ? "99+" : unread}
              </span>
            )}
          </Link>

          <div className="dropdown topbar__user-dropdown" ref={wrapperRef}>
            <button
              className="btn topbar__user-trigger"
              type="button"
              aria-expanded={open}
              aria-label="Open user menu"
              onClick={() => setOpen((value) => !value)}
            >
              <span className="topbar__user-icon" aria-hidden="true">
                <i className="fas fa-user" />
              </span>
            </button>

            {open && (
              <ul className="dropdown-menu dropdown-menu-end topbar__user-menu show">
                <li className="topbar__menu-header">
                  <div className="topbar__menu-name">{user?.name}</div>
                  <div className="topbar__menu-role">{user ? user.role.replace("_", " ") : ""}</div>
                </li>
                <li>
                  <hr className="dropdown-divider" />
                </li>
                <li>
                  <Link className="dropdown-item" href="/profile" onClick={() => setOpen(false)}>
                    Profile
                  </Link>
                </li>
                <li>
                  <button type="button" className="dropdown-item topbar__menu-logout" onClick={() => logout()}>
                    Log Out
                  </button>
                </li>
              </ul>
            )}
          </div>
        </div>
      </div>
    </nav>
  );
}
