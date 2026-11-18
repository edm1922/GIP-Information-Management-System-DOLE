"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";

import { useAuth } from "@/lib/auth";

function NavLink({ href, active, icon, label }: { href: string; active: boolean; icon: string; label: string }) {
  return (
    <li className="nav-item">
      <Link className={`nav-link ${active ? "active" : ""}`} href={href}>
        <i className={`fas ${icon}`} /> {label}
      </Link>
    </li>
  );
}

function Section({ children }: { children: React.ReactNode }) {
  return <li className="sidebar-section-label">{children}</li>;
}

export function Sidebar({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { user, isAdmin, isIntern } = useAuth();
  const pathname = usePathname() ?? "";
  const [settingsOpen, setSettingsOpen] = useState(pathname.startsWith("/settings"));
  const [reportsOpen, setReportsOpen] = useState(pathname.startsWith("/reports/audit-log"));

  const badgeClass = user?.role === "admin" ? "danger" : user?.role === "peso_manager" ? "warning" : "info";
  const staff = isAdmin || user?.role === "peso_manager";

  return (
    <>
      <div
        className={`sidebar-backdrop ${open ? "show" : ""}`}
        id="sidebar-backdrop"
        onClick={onClose}
        aria-hidden="true"
      />

      <div
        className={`sidebar-drawer d-flex flex-column ${open ? "open" : ""}`}
        id="sidebar-drawer"
        style={{ overflowY: "hidden" }}
      >
        <div className="sidebar-user-info">
          <div className="sidebar-user-avatar">
            <i className="fas fa-user-circle" />
          </div>
          <div className="sidebar-user-details">
            <div className="sidebar-user-name">{user?.name}</div>
            <div className="sidebar-user-role">
              <span className={`badge bg-${badgeClass}`}>{user?.role.replace("_", " ")}</span>
            </div>
          </div>
        </div>

        <div className="flex-grow-1" style={{ overflowY: "auto" }}>
          <ul className="nav flex-column">
            <Section>MAIN</Section>
            <NavLink href="/dashboard" active={pathname === "/dashboard"} icon="fa-home" label="Dashboard" />

            {isIntern ? (
              <>
                <Section>INTERNSHIP</Section>
                <NavLink
                  href="/my-internship"
                  active={pathname === "/my-internship"}
                  icon="fa-clock"
                  label="Daily Time Record"
                />
                <NavLink
                  href="/my-internship/201-files"
                  active={pathname === "/my-internship/201-files"}
                  icon="fa-folder-open"
                  label="201 Files"
                />
              </>
            ) : staff ? (
              <>
                <Section>INTERNSHIP PROGRAM</Section>
                <NavLink href="/interns" active={pathname.startsWith("/interns") && !pathname.startsWith("/interns/applications")} icon="fa-user-graduate" label="Interns" />
                <NavLink
                  href="/interns/applications"
                  active={pathname.startsWith("/interns/applications")}
                  icon="fa-clipboard-check"
                  label="Applications"
                />
                <NavLink
                  href="/intern-documents"
                  active={pathname.startsWith("/intern-documents")}
                  icon="fa-file-signature"
                  label="Intern Documents"
                />
                <NavLink
                  href="/reports/intern"
                  active={pathname === "/reports/intern"}
                  icon="fa-chart-bar"
                  label="Intern Reports"
                />
              </>
            ) : null}

            {isAdmin && (
              <>
                <Section>ADMINISTRATION</Section>
                <NavLink
                  href="/settings/users"
                  active={pathname.startsWith("/settings/users")}
                  icon="fa-user-cog"
                  label="User Management"
                />

                <li className="nav-item">
                  <button
                    type="button"
                    className={`nav-link ${pathname.startsWith("/settings") ? "active" : ""}`}
                    onClick={() => setSettingsOpen((value) => !value)}
                    style={{ display: "flex", justifyContent: "space-between", alignItems: "center", width: "100%" }}
                  >
                    <span>
                      <i className="fas fa-cogs" /> Settings
                    </span>
                    <i
                      className={`fas ${settingsOpen ? "fa-chevron-down" : "fa-chevron-right"}`}
                      style={{ fontSize: "0.7rem" }}
                    />
                  </button>
                  {settingsOpen && (
                    <ul className="nav flex-column ms-3">
                      <NavLink
                        href="/settings/document-types"
                        active={pathname.startsWith("/settings/document-types")}
                        icon="fa-file-alt"
                        label="Document Types"
                      />
                      <NavLink
                        href="/settings/companies"
                        active={pathname.startsWith("/settings/companies")}
                        icon="fa-handshake"
                        label="Partner Agencies"
                      />
                    </ul>
                  )}
                </li>

                <li className="nav-item">
                  <button
                    type="button"
                    className={`nav-link ${pathname === "/reports/audit-log" ? "active" : ""}`}
                    onClick={() => setReportsOpen((value) => !value)}
                    style={{ display: "flex", justifyContent: "space-between", alignItems: "center", width: "100%" }}
                  >
                    <span>
                      <i className="fas fa-chart-bar" /> Reports
                    </span>
                    <i
                      className={`fas ${reportsOpen ? "fa-chevron-down" : "fa-chevron-right"}`}
                      style={{ fontSize: "0.7rem" }}
                    />
                  </button>
                  {reportsOpen && (
                    <ul className="nav flex-column ms-3">
                      <NavLink
                        href="/reports/audit-log"
                        active={pathname === "/reports/audit-log"}
                        icon="fa-history"
                        label="Activity Logs"
                      />
                    </ul>
                  )}
                </li>
              </>
            )}
          </ul>
        </div>
      </div>
    </>
  );
}
