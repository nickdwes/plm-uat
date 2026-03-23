"""Frappe app hooks for bitwyse_hrms_backend."""

app_name = "bitwyse_hrms_backend"
app_title = "Bitwyse HRMS Backend"
app_publisher = "Bitwyse"
app_description = "API-focused Frappe app for external HRMS UI clients."
app_email = "engineering@bitwyse.co.ke"
app_license = "MIT"

# ---------------------------------------------------------------------------
# Permission query rules
# ---------------------------------------------------------------------------
# Applied automatically by Frappe when get_list / get_doc is called.
# Each entry restricts which records a user may read for that DocType.

permission_query_conditions = {
    "Employee": "bitwyse_hrms_backend.permissions.get_employee_permission_query",
}

# ---------------------------------------------------------------------------
# Document events
# ---------------------------------------------------------------------------
# Audit hooks fire on sensitive document mutations.

doc_events = {
    "Employee": {
        "on_update": "bitwyse_hrms_backend.audit.on_employee_update",
        "after_insert": "bitwyse_hrms_backend.audit.on_employee_insert",
    },
    "Department": {
        "on_update": "bitwyse_hrms_backend.audit.on_department_update",
        "after_insert": "bitwyse_hrms_backend.audit.on_department_insert",
    },
    "Salary Slip": {
        "on_submit": "bitwyse_hrms_backend.audit.on_salary_slip_submit",
        "on_cancel": "bitwyse_hrms_backend.audit.on_salary_slip_cancel",
    },
    "Payroll Entry": {
        "on_submit": "bitwyse_hrms_backend.audit.on_payroll_entry_submit",
        "on_cancel": "bitwyse_hrms_backend.audit.on_payroll_entry_cancel",
    },
    "Leave Application": {
        "on_submit": "bitwyse_hrms_backend.audit.on_leave_application_submit",
        "on_cancel": "bitwyse_hrms_backend.audit.on_leave_application_cancel",
        "on_update_after_submit": "bitwyse_hrms_backend.audit.on_leave_application_status_change",
    },
    "Leave Allocation": {
        "on_submit": "bitwyse_hrms_backend.audit.on_leave_allocation_submit",
    },
    "HRMS Audit Log": {
        # Prevent any direct mutations to audit log records after creation.
        "before_save": "bitwyse_hrms_backend.audit_log.writer._block_audit_log_mutation",
    },
    # Phase H – Onboarding
    "HRMS Onboarding": {
        "after_insert": "bitwyse_hrms_backend.audit.on_onboarding_insert",
        "on_update": "bitwyse_hrms_backend.audit.on_onboarding_status_change",
    },
    "HRMS Secondment": {
        "after_insert": "bitwyse_hrms_backend.audit.on_secondment_insert",
        "on_update": "bitwyse_hrms_backend.audit.on_secondment_update",
    },
}

# ---------------------------------------------------------------------------
# Observability hooks
# ---------------------------------------------------------------------------
# on_request fires at the start of every HTTP request.
# on_exception fires on any unhandled exception.

on_request = "bitwyse_hrms_backend.observability.on_request"
on_exception = "bitwyse_hrms_backend.observability.on_exception"

# ---------------------------------------------------------------------------
# Install / migrate hooks
# ---------------------------------------------------------------------------
# After install: create HRMS roles, leave types, and salary components.
# After migrate: re-sync the same fixtures (idempotent).

after_install = "bitwyse_hrms_backend.setup.install.after_install"
after_migrate = "bitwyse_hrms_backend.setup.install.after_migrate"

# ---------------------------------------------------------------------------
# Scheduled tasks
# ---------------------------------------------------------------------------

scheduler_events = {
    "daily": [
        # Auto-complete secondments past their end_date
        "bitwyse_hrms_backend.onboarding.secondment.auto_complete_expired_secondments",
    ],
    "weekly": [],
}
