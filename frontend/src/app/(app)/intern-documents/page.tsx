"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useState } from "react";

import { ReviewTable } from "@/components/documents/ReviewTable";
import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { InternDocument, Paginated } from "@/lib/types";

function DocumentsView() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const { isAdmin, isPesoManager } = useAuth();

  const query = searchParams.toString();
  const [documents, setDocuments] = useState<Paginated<InternDocument> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState({
    q: searchParams.get("q") ?? "",
    review_status: searchParams.get("review_status") ?? "",
  });

  const load = useCallback(async () => {
    setError(null);
    try {
      const payload = await api.get<{ documents: Paginated<InternDocument> }>(
        `/api/v1/intern-documents${query ? `?${query}` : ""}`,
      );
      setDocuments(payload.documents);
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load intern documents.");
    }
  }, [query]);

  useEffect(() => {
    load();
  }, [load]);

  const go = (params: URLSearchParams) => {
    const search = params.toString();
    router.push(search ? `/intern-documents?${search}` : "/intern-documents");
  };

  const applyFilters = (event: React.FormEvent) => {
    event.preventDefault();
    const params = new URLSearchParams();
    if (draft.q) params.set("q", draft.q);
    if (draft.review_status) params.set("review_status", draft.review_status);
    go(params);
  };

  const filtered = Boolean(searchParams.get("q") || searchParams.get("review_status"));

  return (
    <>
      <div className="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h2 className="h4 mb-1 fw-bold">Intern Documents</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Review and manage documents submitted by interns.
          </p>
        </div>
        <a href="/intern-documents/folders" className="btn btn-outline-primary">
          <i className="fas fa-folder-open me-1" /> Browse folders
        </a>
      </div>

      {error && (
        <div className="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
          <span>{error}</span>
          <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
            Retry
          </button>
        </div>
      )}

      <div className="card shadow-sm mb-3">
        <div className="card-body">
          <form className="row g-2 align-items-end" onSubmit={applyFilters}>
            <div className="col-md-5">
              <label className="form-label" htmlFor="q">
                Search
              </label>
              <input
                id="q"
                type="search"
                className="form-control"
                value={draft.q}
                placeholder="Intern name, username, or file name…"
                onChange={(event) => setDraft((current) => ({ ...current, q: event.target.value }))}
              />
            </div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="review_status">
                Review Status
              </label>
              <select
                id="review_status"
                className="form-select"
                value={draft.review_status}
                onChange={(event) => setDraft((current) => ({ ...current, review_status: event.target.value }))}
              >
                <option value="">All</option>
                {["pending", "approved", "rejected"].map((status) => (
                  <option key={status} value={status}>
                    {status.charAt(0).toUpperCase() + status.slice(1)}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-3 d-flex gap-2">
              <button className="btn btn-primary flex-grow-1" type="submit">
                <i className="fas fa-magnifying-glass me-1" /> Filter
              </button>
              {filtered && (
                <button type="button" className="btn btn-outline-secondary" onClick={() => router.push("/intern-documents")}>
                  Clear
                </button>
              )}
            </div>
          </form>
        </div>
      </div>

      {!documents ? (
        <div className="text-center text-muted py-5">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
          <div>Loading documents…</div>
        </div>
      ) : (
        <ReviewTable
          documents={documents.data}
          canReview={isAdmin || isPesoManager}
          onReviewed={load}
          footer={
            documents.last_page > 1 ? (
              <Pagination
                page={documents}
                onPageChange={(page) => {
                  const params = new URLSearchParams(query);
                  params.set("page", String(page));
                  go(params);
                }}
              />
            ) : null
          }
        />
      )}
    </>
  );
}

export default function InternDocumentsPage() {
  return (
    <Suspense
      fallback={
        <div className="text-center text-muted py-5">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
          <div>Loading documents…</div>
        </div>
      }
    >
      <DocumentsView />
    </Suspense>
  );
}
