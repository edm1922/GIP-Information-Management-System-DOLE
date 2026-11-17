"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import { DocumentPreviewProvider } from "@/components/documents/DocumentPreview";
import { Sidebar } from "@/components/layout/Sidebar";
import { Topbar } from "@/components/layout/Topbar";
import { useAuth } from "@/lib/auth";

export function AppShell({ children }: { children: React.ReactNode }) {
  const { status, user } = useAuth();
  const router = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  useEffect(() => {
    if (status === "guest") {
      router.replace("/login");
      return;
    }
    if (status === "authenticated" && user?.must_change_password) {
      router.replace("/force-password-change");
    }
  }, [status, user, router]);

  if (status !== "authenticated" || user?.must_change_password) {
    return (
      <div className="d-flex align-items-center justify-content-center" style={{ minHeight: "100vh" }}>
        <div className="text-center text-muted">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" aria-hidden="true" />
          <div>Loading GIMS…</div>
        </div>
      </div>
    );
  }

  return (
    <DocumentPreviewProvider>
      <Topbar onToggleSidebar={() => setSidebarOpen((value) => !value)} />
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
      <main className="main-content">{children}</main>
    </DocumentPreviewProvider>
  );
}
