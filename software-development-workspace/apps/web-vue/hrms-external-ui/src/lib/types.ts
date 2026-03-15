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

// ---------------------------------------------------------------------------
// Phase C – Payroll types
// ---------------------------------------------------------------------------

export interface PayrollPeriodRecord {
  name: string;
  period_name: string;
  company: string;
  start_date: string;
  end_date: string;
  status: string;
}

export interface PayrollRunRecord {
  name: string;
  company: string;
  payroll_frequency: string;
  start_date: string;
  end_date: string;
  status: string;
  posting_date: string | null;
  salary_slip_based_on_timesheet: number;
}

export interface PayrollRunDetail extends PayrollRunRecord {
  cost_center: string | null;
  payroll_payable_account: string | null;
  bank_account: string | null;
  docstatus: number;
  slip_count: number;
  exception_count: number;
}

export interface PayrollRunException {
  name: string;
  employee: string;
  employee_name: string;
  net_pay: number;
  status: string;
  exception_reason: string;
}

// Payslip types

export interface PayslipRecord {
  name: string;
  employee: string;
  employee_name: string;
  company: string;
  start_date: string;
  end_date: string;
  posting_date: string | null;
  gross_pay: number;
  net_pay: number;
  status: string;
  payroll_entry: string | null;
}

export interface SalaryDetailLine {
  salary_component: string;
  amount: number;
  year_to_date: number | null;
}

export interface PayslipDetail extends PayslipRecord {
  department: string | null;
  designation: string | null;
  bank_account_no?: string;
  mode_of_payment?: string;
  payment_days: number | null;
  total_working_days: number | null;
  leave_without_pay: number | null;
  absent_days: number | null;
  earnings: SalaryDetailLine[];
  deductions: SalaryDetailLine[];
}

// Statutory types

export interface StatutoryBreakdown {
  gross_pay: number;
  paye: {
    gross_taxable: number;
    tax_before_relief: number;
    personal_relief: number;
    insurance_relief: number;
    paye_payable: number;
  };
  nssf: {
    employee_contribution: number;
    employer_contribution: number;
    tier1_employee: number;
    tier2_employee: number;
  };
  shif: {
    employee_contribution: number;
    employer_contribution: number;
    label: string;
  };
  ahl: {
    employee_contribution: number;
    employer_contribution: number;
    active: boolean;
  };
  total_employee_deductions: number;
  total_employer_contributions: number;
  net_pay: number;
}

export interface StatutoryRatesConfig {
  effective_from: string;
  personal_relief: number;
  insurance_relief_rate: number;
  insurance_relief_max: number;
  paye_bands: { upper_limit: number | null; rate: number }[];
  nssf: {
    tier1_limit: number;
    tier2_limit: number;
    rate: number;
    legacy_flat: number;
    use_legacy: boolean;
  };
  shif: { rate: number; minimum: number; label: string };
  ahl: { employee_rate: number; employer_rate: number; active: boolean };
}

// Report types

export interface PayrollSummaryReport {
  company: string;
  start_date: string;
  end_date: string;
  slip_count: number;
  total_gross_pay: number;
  total_deductions: number;
  total_net_pay: number;
  rows: PayslipRecord[];
}

export interface PayeRow {
  employee: string;
  employee_name: string;
  gross_pay: number;
  paye: number;
}

export interface P10Report {
  report: "P10";
  company: string;
  start_date: string;
  end_date: string;
  total_paye: number;
  rows: PayeRow[];
}

export interface P9Row {
  employee: string;
  employee_name: string;
  annual_gross: number;
  annual_paye: number;
}

export interface P9Report {
  report: "P9";
  company: string;
  tax_year: number;
  row_count: number;
  total_annual_paye: number;
  rows: P9Row[];
}
