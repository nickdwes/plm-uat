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
  salary_mode: string | null;
  documents: EmployeeDocument[];
  onboarding_status: EmployeeOnboardingStatus;
  onboarding_checklist: OnboardingChecklistItem[];
}

export interface EmployeeDocument {
  name: string;
  file_name: string;
  file_url: string;
  creation: string;
}

export interface EmployeeOnboardingStatus {
  has_onboarding: boolean;
  status: string | null;
  onboarding_name: string | null;
}

export interface OnboardingChecklistItem {
  activity_name: string;
  status: string;
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
  default_salary_structure: string | null;
  default_base_pay: number | null;
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
  payroll_period: string | null;
  start_date: string;
  end_date: string;
  status: string;
  posting_date: string | null;
  salary_slip_based_on_timesheet: number;
  employee_count: number;
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

// ---------------------------------------------------------------------------
// Phase D – Leave Management types
// ---------------------------------------------------------------------------

export interface LeaveTypeRecord {
  name: string;
  max_leaves_allowed: number | null;
  is_carry_forward: number;
  allow_negative: number;
  include_holiday: number;
}

export interface LeaveTypeDetail extends LeaveTypeRecord {
  is_lwp: number;
  expire_carry_forwarded_leaves_after_days: number | null;
}

export interface LeavePolicyDetail {
  leave_type: string;
  annual_days: number;
}

export interface LeavePolicyRecord {
  name: string;
  leave_policy_name: string;
}

export interface LeavePolicyFull extends LeavePolicyRecord {
  leave_type_details: LeavePolicyDetail[];
}

export interface LeaveAllocationRecord {
  name: string;
  employee: string;
  employee_name: string;
  company: string;
  leave_type: string;
  from_date: string;
  to_date: string;
  new_leaves_allocated: number;
  carry_forwarded_leaves_count: number;
  total_leaves_allocated: number;
  status: string;
}

export interface LeaveRequestRecord {
  name: string;
  employee: string;
  employee_name: string;
  company: string;
  leave_type: string;
  from_date: string;
  to_date: string;
  total_leave_days: number;
  status: string;
  description: string | null;
  leave_approver: string | null;
}

export interface LeaveRequestDetail extends LeaveRequestRecord {
  posting_date: string | null;
  half_day: number;
  half_day_date: string | null;
  follow_via_email: number;
  docstatus: number;
}

export interface LeaveBalance {
  leave_type: string;
  allocated: number;
  taken: number;
  remaining: number;
}

export interface LeaveBalanceSummary {
  employee: string;
  as_of_date: string;
  balances: LeaveBalance[];
}

export interface LeaveCalendarEvent {
  name: string;
  employee: string;
  employee_name: string;
  leave_type: string;
  from_date: string;
  to_date: string;
  total_leave_days: number;
  half_day: number;
}

export interface LeaveAnomaly {
  type: "negative_balance" | "overlapping_requests";
  employee: string;
  employee_name: string;
  leave_type: string;
  details: string;
}

export interface LeaveLedgerEntry {
  name: string;
  employee: string;
  leave_type: string;
  from_date: string;
  to_date: string;
  leaves: number;
  transaction_type: string;
  transaction_name: string;
  is_expired: number;
  creation: string;
}

export interface TeamCoverageResult {
  from_date: string;
  to_date: string;
  department: string | null;
  headcount: number;
  on_leave_count: number;
  on_leave: LeaveCalendarEvent[];
}

export interface PendingApprovalItem {
  name: string;
  employee: string;
  employee_name: string;
  company: string;
  leave_type: string;
  from_date: string;
  to_date: string;
  total_leave_days: number;
  status: string;
  leave_approver: string | null;
  posting_date: string | null;
}

export interface DepartmentLeaveSummaryRow {
  department: string;
  employee_count: number;
  leave_days_taken: number;
}

// ---------------------------------------------------------------------------
// Phase H – Employee Onboarding types
// ---------------------------------------------------------------------------

export type OnboardingStatus =
  | "Draft"
  | "Submitted"
  | "Approved"
  | "Rejected"
  | "Onboarded";

export interface OnboardingQualification {
  qualification_type: "Academic" | "Professional";
  institution_name: string;
  course_or_certification: string;
  year_completed: string;
  supporting_document: string | null;
}

export interface OnboardingNextOfKin {
  full_name: string;
  relationship: string;
  phone_number: string;
  email: string | null;
  address: string | null;
}

export interface OnboardingEmergencyContact {
  full_name: string;
  relationship: string;
  phone_number: string;
  alternate_phone: string | null;
  address: string | null;
}

export interface OnboardingRecord {
  name: string;
  status: OnboardingStatus;
  company: string;
  department: string;
  designation: string | null;
  first_name: string | null;
  last_name: string | null;
  full_name: string;
  employment_type: string | null;
  date_of_hire: string | null;
  current_step: number;
  approved_by: string | null;
  approval_date: string | null;
  creation: string;
  modified: string;
}

export interface OnboardingDetail extends OnboardingRecord {
  middle_name: string | null;
  national_id_type: string | null;
  national_id_number: string | null;
  date_of_birth: string | null;
  gender: string | null;
  marital_status: string | null;
  phone_number: string | null;
  personal_email: string | null;
  work_email: string | null;
  physical_address: string | null;
  reporting_manager: string | null;
  work_location: string | null;
  probation_period_months: string | null;
  group_function: string | null;
  kra_pin: string | null;
  nssf_number: string | null;
  nhif_number: string | null;
  sha_number: string | null;
  bank_name: string | null;
  bank_branch: string | null;
  account_number: string | null;
  account_name: string | null;
  tax_obligations_flag: boolean;
  national_id_copy: string | null;
  kra_pin_certificate: string | null;
  nssf_proof: string | null;
  nhif_proof: string | null;
  academic_certificates: string | null;
  employment_contract: string | null;
  compliance_documents: string | null;
  approval_comments: string | null;
  rejection_reason: string | null;
  rejected_by: string | null;
  rejection_date: string | null;
  onboarded_by: string | null;
  onboarded_date: string | null;
  consent_captured: boolean;
  employee: string | null;
  step_labels: Record<string, string>;
  total_steps: number;
  qualifications: OnboardingQualification[];
  next_of_kin: OnboardingNextOfKin[];
  emergency_contacts: OnboardingEmergencyContact[];
}

export interface OnboardingDashboard {
  by_status: Record<OnboardingStatus, number>;
  total: number;
  pending_approval: Array<{
    name: string;
    first_name: string | null;
    last_name: string | null;
    full_name: string;
    company: string;
    department: string;
    date_of_hire: string | null;
    creation: string;
  }>;
}

// Secondment types

export type SecondmentStatus = "Active" | "Completed" | "Cancelled";

export interface SecondmentRecord {
  name: string;
  employee: string;
  home_company: string;
  home_department: string | null;
  host_company: string;
  host_department: string | null;
  reporting_manager: string | null;
  start_date: string;
  end_date: string;
  status: SecondmentStatus;
}

export interface SecondmentDetail extends SecondmentRecord {
  notes: string | null;
}

// Generic per-step payload used by the wizard orchestrator
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type OnboardingStepData = Record<string, any>;

// ---------------------------------------------------------------------------
// Organisation Compliance types
// ---------------------------------------------------------------------------

export type ComplianceStatus =
  | "Not Uploaded"
  | "Under Review"
  | "Pending Renewal"
  | "Valid"
  | "Expiring (6 months)"
  | "Expiring (3 months)"
  | "Expiring (1 month)"
  | "Expiring (this week)"
  | "Expired";

export interface ComplianceAuditEntry {
  at: string;
  by: string;
  action: string;
  note: string;
}

export interface ComplianceDocumentRecord {
  id: number;
  document_key: string;
  segment: string;
  document_label: string;
  is_perpetual: boolean;
  file_url: string | null;
  file_name: string | null;
  issue_date: string | null;
  expiry_date: string | null;
  anniversary_date: string | null;
  notes: string | null;
  owner_name: string | null;
  owner_email: string | null;
  status_override: string | null;
  renewal_guide: string | null;
  reminder_days_before: number;
  reminder_recipients: string | null;
  reminder_active: boolean;
  reminder_thresholds: number[];
  last_reminder_sent_at: string | null;
  audit_trail: ComplianceAuditEntry[];
  /** Computed by the API */
  status: ComplianceStatus;
  days_until_expiry: number | null;
}

export interface ComplianceSummary {
  total: number;
  not_uploaded: number;
  valid: number;
  expiring: number;
  expired: number;
  under_review: number;
  pending_renewal: number;
}

export interface ComplianceDocumentListData {
  data: ComplianceDocumentRecord[];
  summary: ComplianceSummary;
}
