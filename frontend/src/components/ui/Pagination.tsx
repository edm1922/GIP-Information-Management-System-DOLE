"use client";

import type { Paginated } from "@/lib/types";

interface PaginationProps<T> {
  page: Paginated<T>;
  onPageChange: (page: number) => void;
  /** Left-hand summary text ("Showing 1–15 of 40"). Hidden when there is one page. */
  showSummary?: boolean;
}

function pageWindow(current: number, last: number): (number | "gap")[] {
  if (last <= 7) {
    return Array.from({ length: last }, (_value, index) => index + 1);
  }

  const pages = new Set<number>([1, last, current, current - 1, current + 1, current - 2, current + 2]);
  const sorted = [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b);

  const result: (number | "gap")[] = [];
  sorted.forEach((page, index) => {
    if (index > 0 && page - sorted[index - 1] > 1) result.push("gap");
    result.push(page);
  });
  return result;
}

/** Bootstrap 5 pagination matching the Blade `pagination::bootstrap-5` view. */
export function Pagination<T>({ page, onPageChange, showSummary = true }: PaginationProps<T>) {
  if (page.last_page <= 1 && !showSummary) return null;

  return (
    <div className="d-flex justify-content-between align-items-center flex-wrap gap-2">
      {showSummary && page.total > 0 ? (
        <div className="text-muted" style={{ fontSize: "0.8rem" }}>
          Showing {page.from ?? 0}–{page.to ?? 0} of {page.total}
        </div>
      ) : (
        <span />
      )}

      {page.last_page > 1 && (
        <ul className="pagination pagination-sm mb-0">
          <li className={`page-item ${page.current_page <= 1 ? "disabled" : ""}`}>
            <button
              type="button"
              className="page-link"
              aria-label="Previous"
              onClick={() => page.current_page > 1 && onPageChange(page.current_page - 1)}
            >
              &lsaquo;
            </button>
          </li>

          {pageWindow(page.current_page, page.last_page).map((entry, index) =>
            entry === "gap" ? (
              <li key={`gap-${index}`} className="page-item disabled">
                <span className="page-link">…</span>
              </li>
            ) : (
              <li key={entry} className={`page-item ${entry === page.current_page ? "active" : ""}`}>
                <button type="button" className="page-link" onClick={() => onPageChange(entry)}>
                  {entry}
                </button>
              </li>
            ),
          )}

          <li className={`page-item ${page.current_page >= page.last_page ? "disabled" : ""}`}>
            <button
              type="button"
              className="page-link"
              aria-label="Next"
              onClick={() => page.current_page < page.last_page && onPageChange(page.current_page + 1)}
            >
              &rsaquo;
            </button>
          </li>
        </ul>
      )}
    </div>
  );
}
