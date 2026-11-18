export type Role = "admin" | "peso_manager" | "intern";

export interface User {
  id: number;
  username: string;
  email: string | null;
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  suffix: string | null;
  name: string;
  role: Role;
  is_active: boolean;
  must_change_password: boolean;
  last_active_at: string | null;
  intern_id?: number | null;
}

export interface AuthResponse {
  message: string;
  user: User;
}

export interface CalendarDay {
  day: number;
  current_month: boolean;
  today: boolean;
}

export interface DashboardCalendar {
  data: CalendarDay[];
  date: string;
  pill_date: string;
  is_current_month: boolean;
  month_name: string;
  year: number | string;
}

/** A day cell from `InternWorkCalendar::month()` (partials/intern-work-calendar.blade.php). */
export interface WorkCalendarCell {
  date: string;
  day: number;
  in_month: boolean;
  weekend: boolean;
  today: boolean;
  /** `null` when the intern claimed nothing that day. */
  status: "approved" | "pending" | "rejected" | null;
  hours: number;
  in_contract: boolean;
  documents: Array<{
    id: number;
    type: string | null;
    name: string | null;
    status: string | null;
    hours: number;
  }>;
}

export interface WorkCalendar {
  /** `YYYY-MM` of the displayed month. */
  month: string;
  /** `November 2026`. */
  label: string;
  prev_month: string;
  next_month: string;
  cells: WorkCalendarCell[];
  stats: {
    working_days: number;
    covered_days: number;
    hours: number;
  };
}

export interface InternStatusRow {
  status: string;
  count: number;
}

export interface PartnerAgencyRow {
  name: string;
  count: number;
}

export interface InternsTrendRow {
  month: number;
  count: number;
}

export interface Company {
  id: number;
  name: string;
  code: string | null;
  is_active: boolean;
  is_partner_agency: boolean;
  interns_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface DocumentType {
  id: number;
  name: string;
  code: string;
  has_expiry: boolean;
  max_pages: number;
  is_intern_document: boolean;
  is_optional: boolean;
  intern_documents_count?: number;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface DtrDay {
  id: number;
  document_id: number;
  intern_id: number;
  work_date: string | null;
  hours: number;
}

/** Shown in the reviewer's claimed-days calendar. */
export interface DtrDayClaim {
  date: string;
  hours: number;
  outside_contract: boolean;
}

export type ReviewStatus = "pending" | "approved" | "rejected";

export interface InternDocument {
  id: number;
  intern_id: number;
  /** Present when the query eager-loaded `intern.user` (list, folder, self-service). */
  intern?: Intern | null;
  document_type_id: number;
  document_type?: DocumentType | null;
  uploaded_by: number | null;
  uploader?: User | null;
  original_filename: string;
  page_count: number | null;
  file_size_bytes: number | null;
  mime_type: string | null;
  status: string | null;
  document_period: string | null;
  review_status: ReviewStatus;
  reviewed_by: number | null;
  reviewer?: User | null;
  reviewed_at: string | null;
  review_remarks: string | null;
  metadata: unknown;
  dtr_days?: DtrDay[];
  dtr_summary?: string | null;
  outside_contract_days_count?: number;
  dtr_days_payload?: DtrDayClaim[];
  preview_url: string;
  download_url: string;
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

export interface Intern {
  id: number;
  user_id: number | null;
  user?: User | null;
  partner_agency_id: number | null;
  partner_agency?: Company | null;
  name: string;
  contract_start_date: string | null;
  contract_end_date: string | null;
  contract_months: number | null;
  required_hours: number;
  rendered_hours: number;
  hours_remaining: number;
  days_remaining_from_hours: number;
  days_remaining: number | null;
  progress_percent: number;
  status: string;
  intern_type: string | null;
  batch: string | null;
  beneficiary: string | null;
  school: string | null;
  course: string | null;
  contact_number: string | null;
  supervisor: string | null;
  is_completed: boolean;
  is_active: boolean;
  dtr_count?: number;
  files_count?: number;
  pending_count?: number;
  intern_documents_count?: number;
  intern_documents?: InternDocument[];
  created_at: string | null;
  updated_at: string | null;
  deleted_at: string | null;
}

/** Dashboard/list rows are the same resource, so the alias keeps call sites readable. */
export type InternSummary = Intern;

export interface InternDocumentSummary {
  id: number;
  title?: string | null;
  review_status: ReviewStatus;
  document_type?: { id: number; name: string } | null;
  created_at: string | null;
}

export interface DashboardResponse {
  year: number | string;
  month: string | null;
  calendar: DashboardCalendar;
  show_intern_stats: boolean;
  is_intern_user: boolean;
  intern_self: InternSummary | null;
  intern_documents: InternDocument[];
  intern_stats: {
    total_docs: number;
    approved_docs: number;
    pending_docs: number;
    rejected_docs: number;
  };
  /** Only present for intern accounts that have an internship record. */
  work_calendar: WorkCalendar | null;
  total_interns: number;
  active_interns: number;
  inactive_interns: number;
  completed_interns: number;
  expiring_soon_count: number;
  intern_status_distribution: InternStatusRow[];
  partner_agency_distribution: PartnerAgencyRow[];
  interns_trend: InternsTrendRow[];
  expiring_contracts: InternSummary[];
}

/** Laravel paginator JSON (resource collection wrapped with response()->getData(true)). */
export interface PaginationLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface Paginated<T> {
  current_page: number;
  data: T[];
  first_page_url: string;
  from: number | null;
  last_page: number;
  last_page_url: string;
  links: PaginationLink[];
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
}

export interface InternsIndexResponse {
  interns: Paginated<Intern>;
  agencies: Company[];
  show_archived: boolean;
}

export interface InternFoldersResponse {
  interns: Paginated<Intern>;
  search: string;
  filter: string;
}

export interface InternFolderResponse {
  intern: Intern;
  dtrs: InternDocument[];
  files: InternDocument[];
}

export interface ChecklistItem {
  type: DocumentType;
  required: boolean;
  status: "missing" | "approved" | "rejected" | "pending";
  latest_document: InternDocument | null;
}

export interface NotificationData {
  message?: string;
  url_route?: string;
  url_params?: Record<string, string | number>;
  url?: string;
  is_expired?: boolean;
  department_name?: string;
  expiry_date?: string;
  [key: string]: unknown;
}

export interface NotificationItem {
  id: string;
  type: string;
  data: NotificationData;
  read_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface NotificationsResponse extends Paginated<NotificationItem> {
  unread_count: number;
}

export interface AuditLog {
  id: number;
  user_id: number | null;
  action: string;
  model_type: string | null;
  model_id: number | null;
  target_name: string | null;
  description: string | null;
  clean_description: string | null;
  changes: { before?: Record<string, unknown> | null; after?: Record<string, unknown> | null } | null;
  ip_address: string | null;
  user_agent: string | null;
  user?: User | null;
  created_at: string | null;
}

export interface AuditLogsResponse {
  logs: Paginated<AuditLog>;
  users: User[];
}

export interface MyInternshipResponse {
  unassigned: boolean;
  is_dtr_tab: boolean;
  intern?: Intern;
  documents?: InternDocument[];
  document_types?: DocumentType[];
  taken_days?: string[];
  checklist?: ChecklistItem[];
}

export interface InternApplication {
  id: number;
  reference: string;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  suffix: string | null;
  name: string;
  email: string;
  contact_number: string | null;
  school: string | null;
  course: string | null;
  partner_agency_id: number | null;
  partner_agency?: Company | null;
  intern_type: string | null;
  preferred_start_date: string | null;
  notes: string | null;
  status: "pending" | "approved" | "rejected";
  reviewer: string | null;
  reviewed_at: string | null;
  rejection_reason: string | null;
  approved_user_id: number | null;
  approved_intern_id: number | null;
  approved_username: string | null;
  credential_email_status: "pending" | "sent" | "failed" | null;
  credential_email_sent_at: string | null;
  credential_email_error: string | null;
  credential_email_attempts: number;
  suggested_username?: string;
  created_at: string | null;
}

export interface InternApplicationsResponse {
  applications: Paginated<InternApplication>;
  pending_count: number;
}

export interface InternApplicationResponse {
  application: InternApplication;
  suggested_username?: string;
}

export interface RegistrationLink {
  id: number;
  url: string;
  expires_at: string | null;
  revoked_at: string | null;
  uses_count: number;
  is_active: boolean;
  created_by: string | null;
  created_at: string | null;
}

export interface RegistrationLinkResponse {
  link: RegistrationLink | null;
  history: RegistrationLink[];
}

export interface PublicRegistrationMeta {
  registration: {
    brand: { name: string; short_name: string };
    expires_at: string | null;
    intern_types: string[];
    agencies: Array<{ id: number; name: string }>;
  };
}