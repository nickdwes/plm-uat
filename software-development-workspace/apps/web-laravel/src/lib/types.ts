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
  /** Sent when the relation is loaded — the organisation that employs them. */
  primary_organization?: { id: number; name: string; slug: string } | null;
  department: DeptRef | null;
  designation: DesignationRef | null;
  job_grade: JobGradeRef | null;
  date_of_joining: string | null;
  status: string;
  is_shared: boolean;
  /** Placed in the organisation asking, rather than employed by it. */
  is_visiting?: boolean;
  /** A second manager inside one organisation — not a cross-company placement. */
  has_dotted_line?: boolean;
  /** The organisation that employs them — sent only when it is not this one. */
  home_organization?: string | null;
  /** The department, title and share of the week their placement gives them here. */
  host_department?: string | null;
  host_title?: string | null;
  host_allocation_pct?: number;
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
// Work Location Check-In
// ---------------------------------------------------------------------------

export type WorkLocationType = "office" | "home" | "customer_site";

/** One clock in and its matching clock out. Several make a day. */
export interface AttendancePunch {
  id: number;
  in: string;
  out: string | null;
  minutes: number | null;
  location_type: WorkLocationType | null;
  /** Typed after the fact rather than clocked — different evidence. */
  is_manual: boolean;
  note: string | null;
}

/**
 * One employee, one day.
 *
 * A future day is a plan and a past day is a record, which is why planned and
 * actual sit side by side — the gap between them is the useful part.
 */
export interface AttendanceDay {
  id: number;
  work_date: string;
  status: string | null;
  employee: { id: number; name: string; department: string | null } | null;
  planned_location: WorkLocationType | null;
  actual_location: WorkLocationType | null;
  plan_was_kept: boolean | null;
  account: { id: number; name: string } | null;
  location_note: string | null;
  first_clock_in_at: string | null;
  last_clock_out_at: string | null;
  worked_minutes: number | null;
  overtime_minutes: number | null;
  is_clocked_in: boolean | null;
  notes: string | null;
  punches: AttendancePunch[];
}

/** Today, for the personal card. Kept as an alias so callers read plainly. */
export type WorkLocationToday = AttendanceDay;

/** Who is where today, on the HR dashboard. */
export type TeamLocationEntry = AttendanceDay;

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
  /** Quotable reference, derived from the id server-side. */
  reference: string;
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
  /** Present only when the listing eager-counted them. */
  attachments_count?: number;
  messages_count?: number;
}

/** How wide a leave listing is — decided server-side, echoed in meta.scope. */
export type LeaveScope = "own" | "team" | "all";

export interface LeaveSummary {
  pending_requests: number;
  on_leave_today: number;
  upcoming_this_week: number;
  rejected_this_month: number;
  availability: { available: number; headcount: number; percentage: number | null };
  scope: LeaveScope;
}

export interface LeaveAttachment {
  id: number;
  original_name: string;
  mime_type: string | null;
  size_bytes: number;
  uploaded_by: string | null;
  created_at: string;
}

export interface LeaveMessage {
  id: number;
  body: string;
  author: string | null;
  author_id: number;
  visible_to_employee: boolean;
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
  middle_name: string | null;
  last_name: string;
  full_name: string;
  email: string;
  phone: string | null;
  resume_url: string | null;
  social_links: Record<string, string>;
  keywords: string[];
  source: string | null;
  notes: string | null;
  is_archived: boolean;
  archived_at: string | null;
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
  employee_id: number | null;
  name: string;
  work_email: string | null;
  external_email: string | null;
  is_external: boolean;
  role: PanelistRole;
  invite_sent: boolean;
  invite_sent_at: string | null;
  confirmed: boolean;
  scoring_url: string | null;
  score_submitted: boolean;
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
  criterion_comments: Record<string, string> | null;
  technical_exercise_conducted: boolean | null;
  technical_exercise_score: number | null;
  technical_exercise_notes: string | null;
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
  teams_event_id: string | null;
  teams_join_url: string | null;
  candidate_invite_sent: boolean;
  candidate_invite_sent_at: string | null;
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
  color: string;
  shift_group: { id: number; name: string } | null;
  created_at: string;
}

export interface HandoverReport {
  id: number;
  schedule_id: number | null;
  report_date: string;
  shift_start: string | null;
  shift_end: string | null;
  shift_type: { id: number; name: string; color: string } | null;
  outgoing_employee: { id: number; full_name: string } | null;
  incoming_employee: { id: number; full_name: string } | null;
  tasks_completed: string | null;
  ongoing_issues: string | null;
  tasks_pending: string | null;
  equipment_notes: string | null;
  general_notes: string | null;
  acknowledged_at: string | null;
  acknowledged_by: { id: number; full_name: string } | null;
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

// ---------------------------------------------------------------------------
// Training & Competency
// ---------------------------------------------------------------------------

export type TrainingType = 'course' | 'workshop' | 'certification' | 'exam_voucher' | 'subscription' | 'on_job' | 'mentoring' | 'conference';
export type TrainingDelivery = 'in_person' | 'virtual' | 'e_learning' | 'blended' | 'self_study';
export type TrainingStatus = 'enrolled' | 'in_progress' | 'completed' | 'expired' | 'withdrawn' | 'failed';
export type TrainingAssignmentType = 'self_assigned' | 'mandatory' | 'manager_assigned' | 'approval_based';

export interface TrainingItem {
  id: number;
  uuid: string;
  title: string;
  description: string | null;
  type: TrainingType;
  delivery: TrainingDelivery;
  duration_hours: number;
  duration_minutes: number;
  is_long_course: boolean;
  provider_name: string;
  provider_url: string | null;
  cost_currency: string;
  estimated_cost: number;
  tags: string[];
  is_active: boolean;
  is_featured: boolean;
  requires_approval: boolean;
  is_budgeted: boolean;
  budget_amount: number | null;
  max_enrollments: number | null;
  thumbnail_url: string | null;
  competencies?: CompetencyItem[];
  training_records_count?: number;
  created_at: string;
}

export interface CompetencyItem {
  id: number;
  uuid: string;
  name: string;
  description: string | null;
  category: string | null;
  proficiency_levels: { level: number; descriptor: string }[];
  required_level?: number;
  created_at: string;
}

export interface RoleCompetencyProfile {
  id: number;
  uuid: string;
  job_title: string;
  description: string | null;
  department: { id: number; name: string } | null;
  competencies?: (CompetencyItem & { required_level: number })[];
  created_at: string;
}

export interface ProgressUpdate {
  id: number;
  progress_percent: number;
  notes: string | null;
  recorded_at: string;
}

export interface DailyCheckIn {
  id: number;
  check_in_date: string;
  notes: string | null;
  hours_spent: number;
}

export interface TrainingEvidenceFile {
  id: number;
  category: string | null;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  uploaded_by: number;
  created_at: string;
}

export interface EmployeeTrainingRecord {
  id: number;
  uuid: string;
  assignment_type: TrainingAssignmentType;
  status: TrainingStatus;
  progress_percent: number;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  score: number | null;
  is_priority: boolean;
  priority_reason: string | null;
  training_item: TrainingItem;
  employee: { id: number; name: string; email?: string } | null;
  evidence_files?: TrainingEvidenceFile[];
  progress_updates?: ProgressUpdate[];
  daily_check_ins?: DailyCheckIn[];
  created_at: string;
}

export interface TrainingDashboardStats {
  active_learners: number;
  completions_this_month: number;
  overdue: number;
  priority_active: number;
  completion_rate_percent: number;
  catalog_total: number;
  catalog_active: number;
}

// Phase 3: Training Assignment (Approval Workflow)
export interface TrainingAssignment {
  id: string;
  organization_id: number;
  training_item_id: string;
  employee_id: number;
  assigned_by_user_id: number;
  manager_id: number | null;
  status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
  rejection_reason: string | null;
  approval_notes: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  withdrawn_at: string | null;
  due_date: string | null;
  assignment_type: 'mandatory' | 'recommended';
  created_at: string;
  updated_at: string;
  training_item?: TrainingItem;
  employee?: { id: number; first_name: string; last_name: string; email: string };
  assigned_by_user?: { id: number; name: string; email: string };
  manager?: { id: number; name: string; email: string };
}

// Phase 3: Notifications
export interface Notification {
  id: string;
  organization_id: number;
  user_id: number;
  type: 'training_assigned' | 'training_approved' | 'training_rejected' | 'training_completed' | 'training_overdue' | string;
  title: string;
  message: string | null;
  data: Record<string, any> | null;
  notifiable_type: string | null;
  notifiable_id: string | null;
  read_at: string | null;
  sent_at: string | null;
  created_at: string;
  updated_at: string;
}


// Phase 4: Vendor Partner Compliance
export interface VendorRequirement {
  training_item_id: number;
  training_item_uuid: string;
  title: string;
  type: string;
  required_headcount: number;
  notes: string | null;
}

export interface VendorPartner {
  id: number;
  uuid: string;
  name: string;
  contact_email: string | null;
  contact_phone: string | null;
  website_url: string | null;
  notes: string | null;
  is_active: boolean;
  requirements?: VendorRequirement[];
  created_at: string;
  updated_at: string;
}

export interface VendorGap {
  training_item_id: number;
  training_item_uuid: string;
  title: string;
  type: string;
  required_headcount: number;
  actual_holders: number;
  expiring_soon: number;
  gap: number;
  met: boolean;
  notes: string | null;
}

export interface VendorComplianceStatus {
  vendor: {
    id: number;
    uuid: string;
    name: string;
    contact_email: string | null;
    website_url: string | null;
    is_active: boolean;
  };
  status: 'compliant' | 'at_risk' | 'non_compliant';
  certified_count: number;
  required_count: number;
  expiring_soon: number;
  gaps: VendorGap[];
}

// ── Vendor Module (registration + lifecycle) ─────────────────────────────────
export type VendorType = 'supplier' | 'service_provider' | 'contractor' | 'consultant' | 'distributor' | 'partner';
export type VendorOnboardingStatus = 'draft' | 'pending_review' | 'approved' | 'rejected' | 'suspended';

export interface Vendor {
  id: number;
  uuid: string;
  vendor_number: string | null;
  // Company
  name: string;
  registration_number: string | null;
  tax_id: string | null;
  vendor_type: VendorType;
  category: string | null;
  description: string | null;
  // Contact
  primary_contact_name: string | null;
  primary_contact_designation: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  website_url: string | null;
  logo_url: string | null;
  // Address
  physical_address: string | null;
  city: string | null;
  country: string | null;
  // Partnership & fiscal
  partnership_level: string | null;
  fiscal_year_start_month: number | null;
  renewal_anniversary_date: string | null;
  renewal_cost: number | null;
  renewal_cost_currency: string | null;
  renewal_reminder_days: number | null;
  // Status
  is_active: boolean;
  onboarding_status: VendorOnboardingStatus;
  approved_at: string | null;
  approved_by: number | null;
  rejection_reason: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface VendorMetrics {
  total: number;
  draft: number;
  pending_review: number;
  approved: number;
  rejected: number;
  suspended: number;
}

export type ProductType = 'product' | 'solution' | 'service' | 'platform';

export interface VendorProduct {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  name: string;
  sku: string | null;
  product_type: ProductType;
  technology_space: string;
  technology_subcategory: string | null;
  description: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export type IncentiveType = 'rebate' | 'nfr' | 'spiff' | 'mdf' | 'deal_registration' | 'co_op' | 'other';
export type ValueType = 'percentage' | 'fixed' | 'per_unit' | 'variable';

export interface VendorIncentive {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  name: string;
  incentive_type: IncentiveType;
  description: string | null;
  value: number | null;
  value_type: ValueType;
  currency: string | null;
  eligibility_criteria: string | null;
  start_date: string | null;
  end_date: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export type CertLevel = 'associate' | 'professional' | 'expert' | 'specialist' | 'practitioner' | 'foundation';

export interface VendorCertification {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  training_item_id: number | null;
  training_item: { id: number; title: string; type: string } | null;
  name: string;
  code: string | null;
  level: CertLevel;
  technology_space: string;
  technology_subcategory: string | null;
  description: string | null;
  validity_months: number | null;
  exam_cost_usd: number | null;
  training_cost_usd: number | null;
  prerequisites: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface VendorPartnershipTier {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  tier_name: string;
  tier_order: number;
  revenue_target: number | null;
  revenue_currency: string;
  min_certifications: number | null;
  min_headcount: number | null;
  requirements: string | null;
  benefits: string | null;
  is_current: boolean;
  created_at: string;
  updated_at: string;
}

export type PortalUserRole = 'admin' | 'user' | 'viewer';

export interface VendorPortalUser {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  user_id: number | null;
  display_name: string;
  email: string;
  role: PortalUserRole;
  portal_url: string | null;
  access_level: string | null;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export type CustomerType = 'enterprise' | 'sme' | 'government' | 'ngo' | 'education' | 'telco' | 'other';
export type ProjectStatus = 'active' | 'completed' | 'on_hold' | 'cancelled' | 'pipeline';

export interface VendorCustomer {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  name: string;
  customer_type: CustomerType;
  industry: string | null;
  country: string;
  contact_name: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  notes: string | null;
  is_active: boolean;
  projects_count: number;
  created_at: string;
  updated_at: string;
}

export interface VendorProject {
  id: number;
  uuid: string;
  vendor_partner_id: number;
  vendor_customer_id: number | null;
  customer_name: string | null;
  name: string;
  project_code: string | null;
  status: ProjectStatus;
  technology_space: string | null;
  technology_subcategory: string | null;
  description: string | null;
  start_date: string | null;
  end_date: string | null;
  contract_value: number | null;
  currency: string;
  notes: string | null;
  created_at: string;
  updated_at: string;
}

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------

/*
 * `in_service` is for things that are running and looked after rather than
 * held — a switch in a rack, a production virtual machine. Calling those
 * "available" says they are on a shelf and could be handed to somebody.
 */
export type AssetStatus =
  | "available" | "assigned" | "maintenance" | "retired"
  | "pending_acceptance" | "in_service" | "suspended";

export interface Asset {
  id: number;
  name: string;
  asset_tag: string;
  category: string;
  brand: string | null;
  model: string | null;
  serial_number: string | null;

  status: AssetStatus;
  lifecycle_stage: string | null;
  condition: string | null;

  assigned_date: string | null;
  location: string | null;
  holder: { id: number; full_name: string; employee_number: string } | null;
  department: { id: number; name: string } | null;

  /*
   * Looked after rather than carried. Decided by the category, so a racked
   * switch and a virtual machine both answer true while a laptop does not —
   * the screen uses it to choose which question to ask.
   */
  uses_custodian: boolean;
  custodian_since: string | null;
  custodian: { id: number; full_name: string; employee_number: string } | null;

  handover_required: boolean;
  handover_signed_at: string | null;

  purchase_date: string | null;
  purchase_value: number | null;
  vendor: string | null;
  invoice_number: string | null;
  warranty_period: string | null;
  warranty_expiry_date: string | null;
  procured_date: string | null;
  in_stock_date: string | null;
  next_maintenance: string | null;

  specification: {
    processor?: string;
    ram?: string;
    storage?: string;
    operating_system?: string;
  };

  notes: string | null;

  /** Derived server-side so every client agrees what "expired" means. */
  is_assigned: boolean;
  warranty_has_expired: boolean;
  maintenance_is_due: boolean;

  created_at: string | null;
  updated_at: string | null;
}

export interface AssetListMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
