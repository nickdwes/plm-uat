# Bitwyse HRMS Backend — API Reference

All endpoints are exposed via the Frappe `api/method` route:

```
POST https://<frappe-host>/api/method/bitwyse_hrms_backend.api.<endpoint>
```

## Authentication

| Method | Header |
|---|---|
| API key + secret | `Authorization: token <api_key>:<api_secret>` |
| Session cookie | Frappe session (browser login) |

Guest access is denied on all endpoints except `health`.

## Response Envelope

Every endpoint returns:

```json
{
  "api_version": "1",
  "request_id": "<uuid>",
  "data": { ... },
  "meta": { "limit_start": 0, "limit_page_length": 20, "total": 142 },
  "error": null
}
```

On error, `data` is `null` and `error` contains `{ "code": "...", "message": "..." }`.

---

## System

### `health` *(guest)*
Returns backend status. Used by load-balancer health checks.

**Response:** `{ "service": "bitwyse_hrms_backend", "status": "ok", "api_version": "1" }`

### `hrms_dashboard_summary`
**Role:** Employee and above | **Params:** `company?`

Returns `total_employees`, `total_salary_structures`, `total_payroll_periods` scoped to the caller's company.

---

## Organisation

| Endpoint | Role | Key Params |
|---|---|---|
| `list_companies` | HR only | — |
| `list_departments` | HR only | `company?`, pagination |
| `get_department` | HR only | `department_name` |
| `create_department` | HR only | `company`, `department_name`, `parent_department?` |
| `update_department` | HR only | `department_name`, `update_fields` |
| `list_branches` | HR only | `company?`, pagination |

---

## Employees

### `list_employees`
**Role:** Manager and above | **Params:** `search?`, `company?`, `department?`, `status?`, `limit_start?`, `limit_page_length?`

Visibility: Managers → own department only; Company HR → full company; Global HR → all companies.

### `get_employee`
**Role:** Manager and above | **Params:** `employee_id`

Returns profile + onboarding checklist. PII fields (email, mobile, salary mode) only visible to HR roles.

### `create_employee`
**Role:** HR only | **Params:** `company`, `employee_name`, `date_of_joining`, `department?`, `designation?`, `branch?`, `employment_type?`, `grade?`

### `update_employee`
**Role:** HR only | **Params:** `employee_id`, `update_fields` (dict)

---

## Lifecycle

| Endpoint | Key Params |
|---|---|
| `transfer_employee` | `employee_id`, `effective_date`, `company?`, `department?`, `branch?`, `reason?` |
| `promote_employee` | `employee_id`, `effective_date`, `designation?`, `grade?`, `reason?` |
| `exit_employee` | `employee_id`, `relieving_date`, `reason?` |
| `archive_employee` | `employee_id` |

All lifecycle endpoints require **HR only** and write audit events.

---

## Roles & Reference Data

| Endpoint | Role | Notes |
|---|---|---|
| `list_designations` / `create_designation` | HR only | — |
| `list_grades` / `get_grade` / `create_grade` | HR only | Includes salary range |
| `list_employment_types` | HR only | — |

---

## Payroll Periods

| Endpoint | Key Params |
|---|---|
| `list_payroll_periods` | `company?`, pagination |
| `get_payroll_period` | `period_name` |
| `create_payroll_period` | `company`, `period_name`, `start_date`, `end_date` |
| `lock_payroll_period` | `period_name` — one-way, irreversible |

**Role:** HR only for all.

---

## Payroll Runs

| Endpoint | Key Params |
|---|---|
| `list_payroll_runs` | `company?`, `status?`, pagination |
| `get_payroll_run` | `run_name` |
| `create_payroll_run` | `company`, `start_date`, `end_date`, `payroll_frequency?`, `department?` |
| `submit_payroll_run` | `run_name` |
| `cancel_payroll_run` | `run_name` |
| `list_run_exceptions` | `run_name` |

**Role:** HR only for all.

---

## Shadow Payroll (Dry Run)

### `run_shadow_payroll`
**Role:** HR only | **Params:** `company`, `start_date`, `end_date`, `department?`

Computes gross, all Kenya statutory deductions (PAYE/NSSF/SHIF/AHL), leave deductions, and net pay for every active employee **without writing any documents**. The response envelope includes `"shadow": true`.

**Aggregate data returned:**
`employee_count`, `total_gross`, `total_paye`, `total_nssf_employee`, `total_nssf_employer`, `total_shif`, `total_ahl_employee`, `total_ahl_employer`, `total_net`, `total_unpaid_deductions`, `anomaly_count`

**Per-employee slip:** same fields as above plus `unpaid_leave_days`, `encashment_addition`, `anomalies[]`

---

## Payslips

| Endpoint | Role | Key Params |
|---|---|---|
| `list_payslips` | Employee+ (own only for Employee) | `company?`, `payroll_run?`, `employee?`, pagination |
| `get_payslip` | Employee+ | `payslip_name` — deduction details stripped for non-HR |
| `compute_statutory_preview` | Employee+ | `gross_pay`, `as_of_date?` |
| `get_statutory_rates` | Employee+ | `as_of_date?` |

---

## Payroll Reports *(HR only)*

| Endpoint | Description |
|---|---|
| `payroll_summary_report` | Aggregate earnings/deductions per payroll run |
| `paye_p10_report` | KRA P10 monthly PAYE summary |
| `paye_p9_report` | KRA P9 annual employee tax deduction card |
| `nssf_schedule` | NSSF remittance schedule |
| `shif_schedule` | SHIF remittance schedule |
| `ahl_schedule` | Affordable Housing Levy remittance schedule |

---

## Leave Types & Policies

| Endpoint | Role | Key Params |
|---|---|---|
| `list_leave_types` / `get_leave_type` | Employee+ | `company?` / `leave_type_name` |
| `list_leave_policies` / `get_leave_policy` / `create_leave_policy` | HR only | — |
| `list_leave_allocations` / `create_leave_allocation` | HR only | `employee`, `leave_type`, `from_date`, `to_date`, `new_leaves_allocated` |

---

## Leave Requests

### `list_leave_requests`
**Role:** Employee+ | **Params:** `company?`, `employee?`, `status?`, `from_date?`, `to_date?`, pagination

Visibility mirrors employee list scoping (own → dept → company → all).

### `submit_leave_request`
**Role:** Employee+ | **Params:** `leave_type`, `from_date`, `to_date`, `description?`, `half_day?`

State: `Draft → Open`

### `approve_leave_request` / `reject_leave_request`
**Role:** Manager+ | **Params:** `leave_request_id`, `comment?`

### `cancel_leave_request`
**Role:** Employee+ | **Params:** `leave_request_id`

Employees may cancel own Open requests; HR may cancel Approved.

### `escalate_leave_request`
**Role:** Manager+ | **Params:** `leave_request_id`, `to_approver`

### `get_leave_request`
**Role:** Employee+ | **Params:** `leave_request_id`

---

## Leave Ledger & Dashboard

| Endpoint | Role | Key Params |
|---|---|---|
| `get_leave_balances` | Employee+ | `employee?`, `company?`, `as_of_date?` |
| `get_leave_calendar` | Employee+ | `from_date`, `to_date`, `company?`, `department?` |
| `get_leave_anomalies` | HR only | `company?` — negative balances + overlaps |
| `list_leave_ledger_entries` | HR only | `employee`, `from_date?`, `to_date?` |
| `list_pending_approvals` | Manager+ | `company?`, `department?` |
| `team_coverage` | Manager+ | `from_date`, `to_date`, `company?`, `department?` |
| `department_leave_summary` | HR only | `company?`, `from_date`, `to_date` |

---

## Error Codes

| HTTP | `error.code` | Meaning |
|---|---|---|
| 401 | `AuthenticationError` | Not logged in |
| 403 | `PermissionError` | Insufficient role or wrong company scope |
| 400 | `ValidationError` | Invalid input (date format, missing required field, etc.) |
| 404 | `DoesNotExistError` | Document not found |
| 429 | `TooManyRequestsError` | Rate limit exceeded (120 req/min per user) |
| 500 | `InternalError` | Unhandled server error |
