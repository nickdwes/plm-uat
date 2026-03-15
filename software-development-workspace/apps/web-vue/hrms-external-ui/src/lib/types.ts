// ---------------------------------------------------------------------------
// API envelope types (Phase A – versioned structured responses)
// ---------------------------------------------------------------------------

/** Error object returned inside the API envelope when a call fails. */
export interface ApiError {
  code: string;
  message: string;
}

/** Pagination metadata block returned on list responses. */
export interface PaginationMeta {
  limit_start: number;
  limit_page_length: number;
  total: number;
}

/**
 * Standard API response envelope returned by all HRMS endpoints.
 * ``data`` is typed per-endpoint; ``meta`` is present on list responses.
 */
export interface ApiEnvelope<T> {
  api_version: string;
  request_id: string;
  data: T | null;
  meta: PaginationMeta | null;
  error: ApiError | null;
}

// ---------------------------------------------------------------------------
// Domain response types
// ---------------------------------------------------------------------------

export interface DashboardSummary {
  company: string | null;
  total_employees: number;
  total_salary_structures: number;
  total_payroll_periods: number;
}

export interface EmployeeRecord {
  name: string;
  employee_name: string;
  company: string;
  status: string;
  department: string | null;
  designation: string | null;
  date_of_joining: string | null;
}

export interface EmployeeListData {
  items: EmployeeRecord[];
}
