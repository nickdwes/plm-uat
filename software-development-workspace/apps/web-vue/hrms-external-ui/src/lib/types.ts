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

// ---------------------------------------------------------------------------
// Phase B – Org hierarchy types
// ---------------------------------------------------------------------------

export interface CompanyRecord {
  name: string;
  company_name: string;
  country: string | null;
  default_currency: string | null;
}

export interface DepartmentRecord {
  name: string;
  department_name: string;
  company: string;
  parent_department: string | null;
}

export interface DepartmentDetail extends DepartmentRecord {
  children: Pick<DepartmentRecord, "name" | "department_name">[];
}

export interface BranchRecord {
  name: string;
  branch: string;
}

// ---------------------------------------------------------------------------
// Phase B – Employee detail types
// ---------------------------------------------------------------------------

export interface EmployeeDetail extends EmployeeRecord {
  first_name: string | null;
  last_name: string | null;
  middle_name: string | null;
  gender: string | null;
  date_of_birth: string | null;
  personal_email: string | null;
  company_email: string | null;
  cell_number: string | null;
  reports_to: string | null;
  grade: string | null;
  notice_period: number | null;
  relieving_date: string | null;
  held_on: string | null;
  user_id: string | null;
  employment_type: string | null;
  branch: string | null;
  documents: EmployeeDocument[];
  onboarding_status: OnboardingStatus;
}

export interface EmployeeDocument {
  name: string;
  file_name: string;
  file_url: string;
  creation: string;
}

export interface OnboardingStatus {
  has_onboarding: boolean;
  status: string | null;
  onboarding_name: string | null;
}

// ---------------------------------------------------------------------------
// Phase B – Role / grade / designation types
// ---------------------------------------------------------------------------

export interface DesignationRecord {
  name: string;
  designation_name: string;
}

export interface GradeRecord {
  name: string;
  grade_name: string;
  default_leave_policy: string | null;
}

export interface GradeDetail extends GradeRecord {
  salary_ranges: SalaryRange[];
}

export interface SalaryRange {
  from_amount: number;
  to_amount: number;
  currency: string;
}

export interface EmploymentTypeRecord {
  name: string;
  employee_type_name: string;
}

// ---------------------------------------------------------------------------
// Phase B – Lifecycle event response
// ---------------------------------------------------------------------------

export interface LifecycleEventResult {
  name: string;
  event: "transfer" | "promotion" | "exit" | "archive";
}
