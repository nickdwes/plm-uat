// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

export interface AuthUser {
  id: number;
  name: string;
  email: string;
}

export interface Organization {
  id: number;
  name: string;
  slug: string;
  logo_url?: string | null;
  primary_color?: string | null;
}

export interface LoginResponse {
  token: string;
  user: AuthUser;
  organizations: Organization[];
}

// ---------------------------------------------------------------------------
// Laravel pagination envelope
// ---------------------------------------------------------------------------

/** Pagination metadata returned in the `meta` key by Laravel API resource collections. */
export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/** Response shape for JsonResource::collection($paginator). */
export interface Paginator<T> {
  data: T[];
  meta: PaginationMeta;
  links: { first: string | null; last: string | null; prev: string | null; next: string | null };
}

// ---------------------------------------------------------------------------
// Employees
// ---------------------------------------------------------------------------

export interface EmployeeRef {
  id: number;
  name?: string;
}

export interface DeptRef {
  id: number;
  name: string;
}

export interface DesignationRef {
  id: number;
  name: string;
}

export interface JobGradeRef {
  id: number;
  name: string;
}

export interface EmergencyContact {
  id: number;
  name: string;
  relationship: string;
  phone: string;
  email: string | null;
  is_primary: boolean;
}

export interface EmployeeDependent {
  id: number;
  employee_id: number;
  first_name: string;
  last_name: string;
  full_name?: string;
  middle_name: string | null;
  relationship: string;
  date_of_birth: string | null;
  gender: string | null;
  national_id: string | null;
  passport_number: string | null;
  school_institution: string | null;
  disability: string | null;
  notes: string | null;
  created_at: string;
}

export interface EmployeeVehicle {
  id: number;
  employee_id: number;
  registration_number: string;
  make: string | null;
  model: string | null;
  color: string | null;
  year: number | null;
  parking_slot: string | null;
  notes: string | null;
  created_at: string;
}

export interface Employee {
  id: number;
  employee_number: string;
  first_name: string;
  last_name: string;
  full_name: string;
  display_name: string;
  primary_organization_id: number;
  department: DeptRef | null;
  designation: DesignationRef | null;
  job_grade: JobGradeRef | null;
  date_of_joining: string | null;
  status: string;
  is_shared: boolean;
  email: string | null;
  work_email?: string | null;
  personal_email?: string | null;
  phone: string | null;
  linkedin_profile?: string | null;
  address?: string | null;
  residential_address?: string | null;
  city?: string | null;
  country?: string | null;
  gender?: string | null;
  date_of_birth?: string | null;
  // Statutory IDs (sensitive — only returned when HR or self)
  national_id?: string | null;
  passport_number?: string | null;
  tax_id?: string | null;
  nssf_number?: string | null;
  nhif_number?: string | null;
  sha_number?: string | null;
  // Health (sensitive)
  blood_group?: string | null;
  allergies?: string | null;
  known_conditions?: string | null;
  disabilities?: string | null;
  // Compensation (sensitive)
  basic_salary?: number | null;
  house_allowance?: number | null;
  transport_allowance?: number | null;
  medical_allowance?: number | null;
  other_allowances?: number | null;
  insurance_relief?: number | null;
  // Relations (conditional)
  emergency_contacts?: EmergencyContact[];
  manager?: { id: number; full_name: string; work_email: string | null } | null;
  date_exited: string | null;
  exit_reason: string | null;
  created_at: string;
}


// ---------------------------------------------------------------------------
// Leave
// ---------------------------------------------------------------------------

export interface LeaveTypeRef {
  id: number;
  name: string;
  code: string | null;
}

export type LeaveStatus = "pending" | "approved" | "rejected" | "cancelled";

export interface LeaveRequest {
  id: number;
  employee_id: number;
  organization_id: number;
  employee: { id: number; first_name: string; last_name: string; employee_number: string } | null;
  leave_type: LeaveTypeRef | null;
  from_date: string;
  to_date: string;
  days_requested: number;
  is_half_day: boolean;
  half_day_period: "morning" | "afternoon" | null;
  scattered_dates: string[] | null;
  reason: string | null;
  status: LeaveStatus;
  rejection_reason: string | null;
  approved_by: { id: number; full_name: string } | null;
  actioned_at: string | null;
  created_at: string;
}

export interface LeaveBalance {
  leave_type: string;
  entitled_days: number;
  used_days: number;
  pending_days: number;
  carried_days: number;
  available_days: number;
}

export interface LeaveBalanceResponse {
  data: LeaveBalance[];
  year: number;
}

// ---------------------------------------------------------------------------
// Payroll
// ---------------------------------------------------------------------------

export type PayrollStatus = "draft" | "processing" | "approved" | "paid";

export interface PayrollPeriod {
  id: number;
  organization_id: number;
  name: string;
  start_date: string;
  end_date: string;
  payment_date: string | null;
  status: PayrollStatus;
  runs_count: number | null;
  created_at: string;
}

export interface PayrollRun {
  id: number;
  employee_id: number;
  employee_name: string | null;
  basic_salary: number;
  gross_pay: number;
  total_deductions: number;
  net_pay: number;
  status: string;
}

export interface PayrollPeriodDetail extends PayrollPeriod {
  runs: PayrollRun[];
}

export interface PayHistoryItem {
  id: number;
  basic_salary: number;
  gross_pay: number;
  total_deductions: number;
  net_pay: number;
  status: string;
  period: PayrollPeriod;
}

// ---------------------------------------------------------------------------
// Recruitment
// ---------------------------------------------------------------------------

export type StageType = "application" | "screening" | "interview" | "decision" | "offer" | "hired" | "rejected";
export type VacancyStatus = "draft" | "open" | "closed" | "cancelled";
export type ApplicationStatus = "active" | "shortlisted" | "rejected" | "hired" | "withdrawn";

export interface FormFieldSchema {
  key: string;
  label: string;
  type: "text" | "email" | "number" | "url" | "date" | "select" | "textarea" | "file";
  required: boolean;
  options?: string[];          // for select type
  placeholder?: string;
}

export interface WorkflowStage {
  id: number;
  workflow_id: number;
  name: string;
  order_index: number;
  stage_type: StageType;
  coordinator_ids: number[];
  required_actions: string[];
}

export interface RecruitmentWorkflow {
  id: number;
  name: string;
  description: string | null;
  is_default: boolean;
  stages: WorkflowStage[];
  created_at: string;
}

export interface VacancyTemplate {
  id: number;
  name: string;
  job_title: string;
  location: string | null;
  default_positions: number;
  is_active: boolean;
  application_form_schema: FormFieldSchema[];
  screening_rules: unknown[];
  workflow: RecruitmentWorkflow | null;
  department: { id: number; name: string } | null;
  designation: { id: number; title: string } | null;
  created_at: string;
}

export interface Vacancy {
  id: number;
  uuid: string;
  name: string;
  job_title: string;
  location: string | null;
  open_positions: number;
  status: VacancyStatus;
  description: string | null;
  closing_date: string | null;
  application_form_schema: FormFieldSchema[];
  template_id: number | null;
  workflow: RecruitmentWorkflow | null;
  department: { id: number; name: string } | null;
  designation: { id: number; title: string } | null;
  hiring_manager: { id: number; name: string } | null;
  applications_count?: number;
  created_at: string;
}

export interface Candidate {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  email: string;
  phone: string | null;
  resume_url: string | null;
  social_links: Record<string, string>;
  keywords: string[];
  source: string | null;
  notes: string | null;
  applications?: CandidateApplication[];
  created_at: string;
}

export interface CandidateApplication {
  id: number;
  status: ApplicationStatus;
  form_data: Record<string, unknown>;
  applied_at: string;
  screening_score: number | null;
  vacancy: { id: number; name: string } | null;
  current_stage: WorkflowStage | null;
  stage_history: StageHistory[];
}

export interface StageHistory {
  id: number;
  from_stage: { id: number; name: string } | null;
  to_stage: { id: number; name: string; stage_type: StageType };
  moved_by: { id: number; name: string };
  notes: string | null;
  moved_at: string;
}

export interface Application {
  id: number;
  status: ApplicationStatus;
  screening_score: number | null;
  form_data: Record<string, unknown>;
  applied_at: string;
  candidate: Candidate | null;
  current_stage: WorkflowStage | null;
  stage_history: StageHistory[];
  vacancy_id: number;
  vacancy: { id: number; name: string } | null;
  created_at: string;
}

export interface RecruitmentStats {
  open: number;
  draft: number;
  applications: number;
  shortlisted: number;
  hired: number;
}

// ---------------------------------------------------------------------------
// Org Structure Hierarchy
// ---------------------------------------------------------------------------

export interface OrgLead {
  id: number;
  first_name: string;
  last_name: string;
  employee_number: string;
}

export interface OrgPractice {
  id: number;
  business_unit_id: number;
  name: string;
  code: string | null;
  description: string | null;
  lead_employee_id: number | null;
  lead?: OrgLead | null;
  is_active: boolean;
}

export interface OrgBusinessUnit {
  id: number;
  department_id: number;
  name: string;
  code: string | null;
  description: string | null;
  lead_employee_id: number | null;
  lead?: OrgLead | null;
  is_active: boolean;
  practices: OrgPractice[];
}

export interface OrgDepartment {
  id: number;
  functional_unit_id: number | null;
  name: string;
  code: string | null;
  cost_center: string | null;
  description: string | null;
  lead_employee_id: number | null;
  lead?: OrgLead | null;
  headcount: number;
  is_active: boolean;
  business_units: OrgBusinessUnit[];
}

export interface OrgFunctionalUnit {
  id: number;
  name: string;
  code: string | null;
  description: string | null;
  departments: OrgDepartment[];
}

export interface OrgStructureData {
  functional_units: OrgFunctionalUnit[];
  unassigned_departments: OrgDepartment[];
}

// ---------------------------------------------------------------------------
// Compliance
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

// ── Interviews ────────────────────────────────────────────────────────────────

export type InterviewType = 'phone' | 'video' | 'onsite' | 'panel' | 'technical' | 'hr';
export type InterviewStatus = 'scheduled' | 'confirmed' | 'completed' | 'cancelled' | 'rescheduled';
export type PanelistRole = 'lead' | 'panelist' | 'observer';
export type Recommendation = 'hire' | 'hold' | 'reject';

export interface InterviewPanelist {
  id: number;
  employee_id: number;
  name: string;
  work_email: string | null;
  role: PanelistRole;
  invite_sent: boolean;
  invite_sent_at: string | null;
  confirmed: boolean;
}

export interface InterviewScore {
  id: number;
  interview_id: number;
  panelist_id: number;
  panelist_name: string;
  personal_presentation: number | null;
  training_certifications: number | null;
  role_knowledge: number | null;
  problem_solving: number | null;
  teamskills_leadership: number | null;
  attitude_adaptability: number | null;
  personal_values: number | null;
  interests_goals: number | null;
  total: number | null;
  average_score: number | null;
  strengths: string | null;
  weaknesses: string | null;
  remarks: string | null;
  recommendation: Recommendation | null;
  submitted: boolean;
  submitted_at: string | null;
}

export interface Interview {
  id: number;
  application_id: number;
  vacancy_id: number;
  title: string;
  interview_type: InterviewType;
  scheduled_at: string;
  duration_minutes: number;
  location: string | null;
  agenda: string | null;
  status: InterviewStatus;
  overall_notes: string | null;
  overall_score: number | null;
  recommendation: Recommendation | null;
  panelists: InterviewPanelist[];
  scores: InterviewScore[];
  created_at: string;
}

export interface EmployeeSearchResult {
  id: number;
  name: string;
  work_email: string | null;
  designation: string | null;
}

// ---------------------------------------------------------------------------
// Roster
// ---------------------------------------------------------------------------

export type RosterPrimaryDetail = "total_shift_hours" | "allocated_hours";

export interface RosterConfig {
  id: number;
  organization_id: number;
  primary_detail_shown: RosterPrimaryDetail;
  swap_shifts_enabled: boolean;
  manager_approval_required: boolean;
  updated_at: string | null;
}

export interface ShiftGroup {
  id: number;
  organization_id: number;
  name: string;
  description: string | null;
  created_at: string;
}

export interface ShiftType {
  id: number;
  organization_id: number;
  name: string;
  start_time: string;
  end_time: string;
  break_start: string | null;
  break_duration_minutes: number;
  duration_minutes: number;
  net_minutes: number;
  is_overnight: boolean;
  is_active: boolean;
  shift_group: { id: number; name: string } | null;
  created_at: string;
}

export type RosterScheduleStatus = "draft" | "published";

export interface RosterSchedule {
  id: number;
  organization_id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: RosterScheduleStatus;
  published_at: string | null;
  created_by: { id: number; name: string } | null;
  created_at: string;
}

export type AssignmentType = "shift" | "day_off" | "leave";

export interface RosterAssignment {
  id: number;
  schedule_id: number;
  employee: { id: number; full_name: string; employee_number: string } | null;
  shift_type: {
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    duration_minutes: number;
    is_overnight: boolean;
  } | null;
  assignment_date: string;
  type: AssignmentType;
  notes: string | null;
  created_at: string;
}

export type SwapStatus = "pending" | "accepted" | "rejected" | "approved" | "cancelled";

export interface ShiftSwapRequest {
  id: number;
  organization_id: number;
  status: SwapStatus;
  requester: { id: number; full_name: string } | null;
  requested_employee: { id: number; full_name: string } | null;
  requester_assignment: { id: number; assignment_date: string; shift_name: string | null } | null;
  requested_assignment: { id: number; assignment_date: string; shift_name: string | null } | null;
  requester_notes: string | null;
  responder_notes: string | null;
  manager_notes: string | null;
  approved_by: { id: number; full_name: string } | null;
  responded_at: string | null;
  approved_at: string | null;
  created_at: string;
}
