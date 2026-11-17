import Link from "next/link";

export default function NotFound() {
  return (
    <div className="d-flex align-items-center justify-content-center" style={{ minHeight: "100vh" }}>
      <div className="text-center" style={{ maxWidth: 480 }}>
        <i className="fas fa-compass fa-2x mb-3" style={{ color: "var(--company-primary)" }} />
        <h1 className="h4 fw-bold mb-2">This page is not migrated yet</h1>
        <p className="text-muted">
          The Next.js frontend is being built page by page. The Blade application still serves this route until
          parity is verified.
        </p>
        <Link href="/dashboard" className="btn btn-primary">
          Back to dashboard
        </Link>
      </div>
    </div>
  );
}
