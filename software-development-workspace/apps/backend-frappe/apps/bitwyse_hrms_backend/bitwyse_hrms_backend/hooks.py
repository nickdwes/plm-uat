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
}

# ---------------------------------------------------------------------------
# Scheduled tasks
# ---------------------------------------------------------------------------
# Placeholder schedule — payroll and leave jobs will be added in Phase C/D.

scheduler_events = {
    "daily": [],
    "weekly": [],
}
