/**
 * Shared entry-point names. Kept free of k6 imports so both the metrics module
 * and the HTML/Markdown report renderer can use it.
 */
export const ENDPOINTS = [
  "login",
  "auth_user",
  "dashboard",
  "interns_list",
  "interns_search",
  "intern_details",
  "batch_listing",
  "frontend_login",
  "frontend_dashboard",
];

export const ENDPOINT_LABELS = {
  login: "POST /api/v1/auth/login",
  auth_user: "GET /api/v1/auth/user",
  dashboard: "GET /api/v1/dashboard",
  interns_list: "GET /api/v1/interns",
  interns_search: "GET /api/v1/interns?search=",
  intern_details: "GET /api/v1/interns/{id}",
  batch_listing: "GET /api/v1/interns?status=active (batch/beneficiary rows)",
  frontend_login: "GET {FRONTEND_URL}/login",
  frontend_dashboard: "GET {FRONTEND_URL}/dashboard",
};
