"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { Suspense, useCallback, useEffect, useState } from "react";

import { Pagination } from "@/components/ui/Pagination";
import { ApiError, api } from "@/lib/api";
import type { InternFoldersResponse } from "@/lib/types";

const FOLDER_COLORS = ["#1E429B", "#CE2028", "#0f766e", "#7c3aed", "#b45309", "#0369a1"];

function FoldersView() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const query = searchParams.toString();

  const [data, setData] = useState<InternFoldersResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState({
    q: searchParams.get("q") ?? "",
    filter: searchParams.get("filter") ?? "",
  });

  const load = useCallback(async () => {
    setError(null);
    try {
      setData(await api.get<InternFoldersResponse>(`/api/v1/intern-documents/folders${query ? `?${query}` : ""}`));
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : "Unable to load intern folders.");
    }
  }, [query]);

  useEffect(() => {
    load();
  }, [load]);

  const go = (params: URLSearchParams) => {
    const search = params.toString();
    router.push(search ? `/intern-documents/folders?${search}` : "/intern-documents/folders");
  };

  const applyFilters = (event: React.FormEvent) => {
    event.preventDefault();
    const params = new URLSearchParams();
    if (draft.q) params.set("q", draft.q);
    if (draft.filter) params.set("filter", draft.filter);
    go(params);
  };

  if (error) {
    return (
      <div className="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
        <span>{error}</span>
        <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => load()}>
          Retry
        </button>
      </div>
    );
  }

  const search = data?.search ?? "";
  const filter = data?.filter ?? "";
  const hasFilters = Boolean(search || filter);

  return (
    <>
      <div className="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
          <h2 className="h4 mb-1 fw-bold">Intern Documents</h2>
          <p className="text-muted mb-0" style={{ fontSize: "0.85rem" }}>
            Browse each intern&apos;s folder — their Daily Time Records and 201 Files in one place.
          </p>
        </div>
        <a href="/intern-documents" className="btn btn-outline-secondary">
          <i className="fas fa-list me-1" /> All documents
        </a>
      </div>

      <div className="card shadow-sm mb-3">
        <div className="card-body">
          <form method="GET" className="row g-2 align-items-end" onSubmit={applyFilters}>
            <div className="col-md-5">
              <label className="form-label" htmlFor="q">
                Search intern
              </label>
              <input
                id="q"
                type="search"
                className="form-control"
                value={draft.q}
                placeholder="Name, username, or partner agency…"
                onChange={(event) => setDraft((current) => ({ ...current, q: event.target.value }))}
              />
            </div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="filter">
                Show
              </label>
              <select
                id="filter"
                className="form-select"
                value={draft.filter}
                onChange={(event) => setDraft((current) => ({ ...current, filter: event.target.value }))}
              >
                <option value="">All interns</option>
                <option value="pending">With pending documents</option>
                <option value="none">No documents yet</option>
              </select>
            </div>
            <div className="col-md-3 d-flex gap-2">
              <button className="btn btn-primary flex-grow-1" type="submit">
                <i className="fas fa-magnifying-glass me-1" /> Apply
              </button>
              {hasFilters && (
                <button type="button" className="btn btn-outline-secondary" onClick={() => router.push("/intern-documents/folders")}>
                  Clear
                </button>
              )}
            </div>
          </form>

          {data && (
            <div className="text-muted mt-2" style={{ fontSize: "0.8rem" }}>
              {data.interns.total} folder{data.interns.total === 1 ? "" : "s"}
              {search ? ` matching “${search}”` : ""}
              {filter === "pending" ? " with pending documents" : filter === "none" ? " with no documents yet" : ""}
            </div>
          )}
        </div>
      </div>

      {!data ? (
        <div className="text-center text-muted py-5">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
          <div>Loading folders…</div>
        </div>
      ) : (
        <>
          <div className="row g-3">
            {data.interns.data.length === 0 ? (
              <div className="col-12">
                <div className="card shadow-sm">
                  <div className="card-body text-center text-muted py-5">
                    <i className="fas fa-folder-open fa-2x mb-2 opacity-50" />
                    <p className="mb-2">
                      {hasFilters
                        ? "No intern folders match your search or filter."
                        : "No interns yet. Intern folders appear here once an intern record exists."}
                    </p>
                    {hasFilters && (
                      <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.push("/intern-documents/folders")}>
                        Clear search
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ) : (
              data.interns.data.map((intern, index) => {
                const color = FOLDER_COLORS[index % FOLDER_COLORS.length];
                const totalDocs = (intern.dtr_count ?? 0) + (intern.files_count ?? 0);

                return (
                  <div key={intern.id} className="col-sm-6 col-lg-4 col-xl-3">
                    <a href={`/intern-documents/folders/${intern.id}`} className="card shadow-sm h-100 text-decoration-none folder-card">
                      <div className="card-body">
                        <div className="d-flex align-items-start gap-3">
                          <div className="folder-icon" style={{ color }}>
                            <i className="fas fa-folder" />
                          </div>
                          <div className="flex-grow-1 min-w-0">
                            <div className="fw-bold text-dark text-truncate">{intern.name}</div>
                            <div className="text-muted small text-truncate">
                              {intern.partner_agency?.name ?? "No partner agency"}
                            </div>
                            <div className="text-muted" style={{ fontSize: "0.72rem" }}>
                              {totalDocs} document{totalDocs === 1 ? "" : "s"}
                            </div>
                          </div>
                          {(intern.pending_count ?? 0) > 0 && (
                            <span className="badge bg-warning text-dark" title={`${intern.pending_count} pending review`}>
                              {intern.pending_count}
                            </span>
                          )}
                        </div>

                        <div className="d-flex flex-wrap gap-2 mt-3" style={{ fontSize: "0.72rem" }}>
                          <span className="badge bg-light text-dark border">
                            <i className="fas fa-clock me-1" style={{ color }} />
                            DTR: {intern.dtr_count ?? 0}
                          </span>
                          <span className="badge bg-light text-dark border">
                            <i className="fas fa-folder-open me-1" style={{ color }} />
                            201: {intern.files_count ?? 0}
                          </span>
                        </div>
                      </div>
                    </a>
                  </div>
                );
              })
            )}
          </div>

          {data.interns.last_page > 1 && (
            <div className="mt-3">
              <Pagination
                page={data.interns}
                onPageChange={(page) => {
                  const params = new URLSearchParams(query);
                  params.set("page", String(page));
                  go(params);
                }}
                showSummary={false}
              />
            </div>
          )}
        </>
      )}

    </>
  );
}

export default function InternFolderBrowserPage() {
  return (
    <Suspense
      fallback={
        <div className="text-center text-muted py-5">
          <i className="fas fa-circle-notch fa-spin fa-2x mb-3" />
          <div>Loading folders…</div>
        </div>
      }
    >
      <FoldersView />
    </Suspense>
  );
}
