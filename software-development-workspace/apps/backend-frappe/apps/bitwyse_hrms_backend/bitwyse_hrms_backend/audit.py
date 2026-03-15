"""Immutable audit event hooks for sensitive HRMS document mutations.

These functions are invoked by Frappe's ``doc_events`` hook mechanism defined
in ``hooks.py``. Each function writes a structured audit log entry.

Phase F upgrade: audit entries now target the ``HRMS Audit Log`` DocType
when available (installed via ``bench migrate``), with transparent fallback
to the Frappe error log for pre-migration environments.
"""

from __future__ import annotations

import frappe

from bitwyse_hrms_backend.audit_log import write_audit_event as _write_audit_event

def on_employee_insert(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires after a new Employee document is created.

    Args:
        doc: The Employee document that was inserted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="employee.created",
        doctype="Employee",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee_name": doc.employee_name,
            "company": doc.company,
            "status": doc.status,
            "department": getattr(doc, "department", None),
            "designation": getattr(doc, "designation", None),
        },
    )


def on_employee_update(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires after an existing Employee document is saved.

    Captures a diff of changed fields so that the audit trail records what
    actually changed rather than the full document state.

    Args:
        doc: The Employee document that was updated.
        method: Frappe event method name (unused).
    """
    changed_fields: dict = {}
    if doc.get_doc_before_save():
        before = doc.get_doc_before_save()
        tracked = [
            "employee_name", "company", "status", "department",
            "designation", "date_of_joining", "relieving_date",
        ]
        for field in tracked:
            old_val = getattr(before, field, None)
            new_val = getattr(doc, field, None)
            if old_val != new_val:
                changed_fields[field] = {"before": old_val, "after": new_val}

    if not changed_fields:
        # No tracked fields changed — skip audit to reduce noise.
        return

    _write_audit_event(
        event="employee.updated",
        doctype="Employee",
        docname=doc.name,
        actor=frappe.session.user,
        payload={"changed": changed_fields},
    )


# ---------------------------------------------------------------------------
# Department audit hooks
# ---------------------------------------------------------------------------


def on_department_insert(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires after a new Department document is created.

    Args:
        doc: The Department document that was inserted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="department.created",
        doctype="Department",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "department_name": doc.department_name,
            "company": getattr(doc, "company", None),
            "parent_department": getattr(doc, "parent_department", None),
        },
    )


def on_department_update(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires after an existing Department document is saved.

    Args:
        doc: The Department document that was updated.
        method: Frappe event method name (unused).
    """
    changed_fields: dict = {}
    if doc.get_doc_before_save():
        before = doc.get_doc_before_save()
        tracked = ["department_name", "company", "parent_department"]
        for field in tracked:
            old_val = getattr(before, field, None)
            new_val = getattr(doc, field, None)
            if old_val != new_val:
                changed_fields[field] = {"before": old_val, "after": new_val}

    if not changed_fields:
        return

    _write_audit_event(
        event="department.updated",
        doctype="Department",
        docname=doc.name,
        actor=frappe.session.user,
        payload={"changed": changed_fields},
    )


# ---------------------------------------------------------------------------
# Payroll audit hooks
# ---------------------------------------------------------------------------


def on_salary_slip_submit(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Salary Slip is submitted.

    Args:
        doc: The Salary Slip document that was submitted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="salary_slip.submitted",
        doctype="Salary Slip",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": doc.employee,
            "employee_name": doc.employee_name,
            "company": doc.company,
            "gross_pay": getattr(doc, "gross_pay", None),
            "net_pay": getattr(doc, "net_pay", None),
            "start_date": str(getattr(doc, "start_date", "")),
            "end_date": str(getattr(doc, "end_date", "")),
        },
    )


def on_salary_slip_cancel(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Salary Slip is cancelled.

    Args:
        doc: The Salary Slip document that was cancelled.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="salary_slip.cancelled",
        doctype="Salary Slip",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": doc.employee,
            "employee_name": doc.employee_name,
            "company": doc.company,
        },
    )


def on_payroll_entry_submit(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Payroll Entry is submitted.

    Args:
        doc: The Payroll Entry document that was submitted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="payroll_entry.submitted",
        doctype="Payroll Entry",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "company": doc.company,
            "start_date": str(getattr(doc, "start_date", "")),
            "end_date": str(getattr(doc, "end_date", "")),
            "payroll_frequency": getattr(doc, "payroll_frequency", None),
        },
    )


def on_payroll_entry_cancel(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Payroll Entry is cancelled.

    Args:
        doc: The Payroll Entry document that was cancelled.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="payroll_entry.cancelled",
        doctype="Payroll Entry",
        docname=doc.name,
        actor=frappe.session.user,
        payload={"company": doc.company},
    )


# ---------------------------------------------------------------------------
# Leave audit hooks
# ---------------------------------------------------------------------------


def on_leave_application_submit(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Leave Application is submitted (approved).

    Args:
        doc: The Leave Application document that was submitted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="leave_application.submitted",
        doctype="Leave Application",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": doc.employee,
            "employee_name": doc.employee_name,
            "leave_type": doc.leave_type,
            "from_date": str(getattr(doc, "from_date", "")),
            "to_date": str(getattr(doc, "to_date", "")),
            "total_leave_days": getattr(doc, "total_leave_days", None),
            "status": doc.status,
        },
    )


def on_leave_application_cancel(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Leave Application is cancelled.

    Args:
        doc: The Leave Application document that was cancelled.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="leave_application.cancelled",
        doctype="Leave Application",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": doc.employee,
            "leave_type": doc.leave_type,
            "from_date": str(getattr(doc, "from_date", "")),
            "to_date": str(getattr(doc, "to_date", "")),
        },
    )


def on_leave_application_status_change(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a submitted Leave Application status changes.

    Captures approve/reject transitions that happen after submission.

    Args:
        doc: The Leave Application document that was updated.
        method: Frappe event method name (unused).
    """
    changed_fields: dict = {}
    if doc.get_doc_before_save():
        before = doc.get_doc_before_save()
        if getattr(before, "status", None) != doc.status:
            changed_fields["status"] = {
                "before": getattr(before, "status", None),
                "after": doc.status,
            }

    if not changed_fields:
        return

    _write_audit_event(
        event="leave_application.status_changed",
        doctype="Leave Application",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": doc.employee,
            "leave_type": doc.leave_type,
            "changed": changed_fields,
        },
    )


def on_leave_allocation_submit(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires when a Leave Allocation is submitted.

    Args:
        doc: The Leave Allocation document that was submitted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="leave_allocation.submitted",
        doctype="Leave Allocation",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": doc.employee,
            "leave_type": doc.leave_type,
            "new_leaves_allocated": getattr(doc, "new_leaves_allocated", None),
            "total_leaves_allocated": getattr(doc, "total_leaves_allocated", None),
            "from_date": str(getattr(doc, "from_date", "")),
            "to_date": str(getattr(doc, "to_date", "")),
        },
    )
