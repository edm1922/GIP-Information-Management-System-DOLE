/** Display helpers shared by the migrated pages. */

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

function parse(value: string | null | undefined): Date | null {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

/** `Jan 15, 2026` — mirrors the Blade `format('M d, Y')` output. */
export function formatDate(value: string | null | undefined): string {
  const date = parse(value);
  if (!date) return "—";
  return `${MONTHS[date.getMonth()]} ${String(date.getDate()).padStart(2, "0")}, ${date.getFullYear()}`;
}

/** `Jan 15, 2026 14:30`. */
export function formatDateTime(value: string | null | undefined): string {
  const date = parse(value);
  if (!date) return "—";
  return `${formatDate(value)} ${String(date.getHours()).padStart(2, "0")}:${String(date.getMinutes()).padStart(2, "0")}`;
}

/** `2026-01-15` from an ISO timestamp or date string. */
/**
 * `Jan 15, 2026 14:30` for API timestamps whose offset cannot be trusted.
 *
 * Audit-log rows are stored as local wall-clock time (the MySQL column default
 * fills `created_at`, since the model runs with `$timestamps = false`) while the
 * API serializes them with a `+00:00` suffix. Parsing that offset would shift
 * every row by the local UTC offset, so the wall clock is kept verbatim, exactly
 * as the Blade page renders it.
 */
export function formatWallClock(value: string | null | undefined): string {
  if (!value) return "—";
  const naive = value.replace(/(?:Z|[+-]\d{2}:?\d{2})$/, "");
  return formatDateTime(naive);
}

export function dateInputValue(value: string | null | undefined): string {
  const date = parse(value);
  if (!date) return "";
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** PHP `class_basename()` equivalent for audit-log model types. */
export function baselineName(className: string): string {
  const parts = className.split("\\");
  return parts[parts.length - 1] || className;
}

export function ucfirst(value: string | null | undefined): string {
  if (!value) return "";
  return value.charAt(0).toUpperCase() + value.slice(1);
}

export function humanize(value: string | null | undefined): string {
  return (value ?? "").replace(/_/g, " ");
}

/** Trims trailing `.0` so 8 shows as `8` and 7.5 as `7.5`. */
export function formatNumber(value: number | string | null | undefined): string {
  const numeric = Number(value ?? 0);
  if (!Number.isFinite(numeric)) return "0";
  return Number.isInteger(numeric) ? String(numeric) : String(Math.round(numeric * 10) / 10);
}

export function formatHours(value: number | string | null | undefined): string {
  return `${formatNumber(value)}h`;
}

export function reviewStatusLabel(status: string | null | undefined): string {
  switch (status) {
    case "pending_peso":
      return "Pending PESO Review";
    case "pending_admin":
      return "Pending Admin Approval";
    case "returned_to_peso":
      return "Returned to PESO Manager";
    case "returned_to_intern":
      return "Returned to Intern";
    case "approved":
      return "Approved";
    case "pending":
      return "Pending Review (legacy)";
    case "rejected":
      return "Rejected (legacy)";
    default:
      return status ? status.replace(/_/g, " ") : "Unknown";
  }
}

export function reviewBadgeClass(status: string): string {
  if (status === "approved") return "bg-success";
  if (status === "pending_admin") return "bg-info text-dark";
  if (status === "returned_to_peso" || status === "returned_to_intern" || status === "rejected") return "bg-danger";
  return "bg-warning text-dark";
}

export function statusBadgeClass(status: string | null | undefined): string {
  if (status === "active") return "bg-success";
  if (status === "completed") return "bg-primary";
  return "bg-secondary";
}

export function formatBytes(bytes: number | null | undefined): string {
  if (!bytes || bytes <= 0) return "—";
  const units = ["B", "KB", "MB", "GB"];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/** Rough `diffForHumans` equivalent for notification timestamps. */
export function timeAgo(value: string | null | undefined): string {
  const date = parse(value);
  if (!date) return "—";

  const seconds = Math.round((Date.now() - date.getTime()) / 1000);
  const future = seconds < 0;
  const magnitude = Math.abs(seconds);

  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ["year", 31536000],
    ["month", 2592000],
    ["week", 604800],
    ["day", 86400],
    ["hour", 3600],
    ["minute", 60],
  ];

  const formatter = new Intl.RelativeTimeFormat("en", { numeric: "auto" });
  for (const [unit, size] of units) {
    if (magnitude >= size) {
      return formatter.format(future ? Math.ceil(magnitude / size) : -Math.floor(magnitude / size), unit);
    }
  }
  return future ? "in a few seconds" : "just now";
}

export function daysFromHours(hours: number): string {
  const days = Math.floor(hours / 8);
  const leftover = Math.round((hours - days * 8) * 10) / 10;
  if (hours <= 0) return "";
  return `${days} day${days === 1 ? "" : "s"}${leftover > 0 ? ` ${leftover}h` : ""}`;
}
