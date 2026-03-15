"""Employee lifecycle workflow APIs with immutable audit trails.

Covers hire (already done via ``employee.create_employee``), transfer,
promotion, exit, and archive transitions. Each transition:
  1. Validates that the caller has HR access and company scope.
  2. Applies the state change to the Employee document.
  3. Writes a structured audit event via the ``audit`` module.
  4. Returns a success envelope.

Transitions do NOT accept free-form field updates — use
``employee.update_employee`` for profile edits.

Exit creates a ``Full and Final Statement`` placeholder (if the HRMS
module supports it) and marks relieving date. Archive sets status to
``Inactive``.
"""

from __future__ import annotations

from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import (
    check_rate_limit,
    require_hr_access,
)
from bitwyse_hrms_backend.audit import _write_audit_event
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.tenant_context import (
    require_company_access,
    resolve_tenant_context,
)


# ---------------------------------------------------------------------------
# Transfer
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def transfer_employee(
    employee_id: str,
    new_department: str | None = None,
    new_designation: str | None = None,
    new_branch: str | None = None,
    new_company: str | None = None,
    effective_date: str | None = None,
    reason: str | None = None,
) -> dict[str, Any]:
    """Transfer an employee to a new department, branch, or company.

    At least one of ``new_department``, ``new_designation``, ``new_branch``,
    or ``new_company`` must be provided.

    Args:
        employee_id: Employee to transfer.
        new_department: Target department name.
        new_designation: Target designation name.
        new_branch: Target branch name.
        new_company: Target company name (inter-company transfer).
        effective_date: ISO date (YYYY-MM-DD) the transfer takes effect.
        reason: Free-text reason for audit trail.

    Returns:
        dict[str, Any]: Envelope with the updated employee name and
            ``event`` = ``"transfer"``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If no transfer field was provided.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    doc = frappe.get_doc("Employee", employee_id)
    require_company_access(ctx, doc.company)

    changes: dict[str, Any] = {}

    if not any([new_department, new_designation, new_branch, new_company]):
        frappe.throw(
            _("At least one transfer target field must be provided."),
            frappe.ValidationError,
        )

    if new_department is not None:
        changes["department"] = {"before": doc.department, "after": new_department}
        doc.department = new_department
    if new_designation is not None:
        changes["designation"] = {"before": doc.designation, "after": new_designation}
        doc.designation = new_designation
    if new_branch is not None:
        changes["branch"] = {"before": doc.branch, "after": new_branch}
        doc.branch = new_branch
    if new_company is not None:
        # Inter-company transfer: validate access to target company too.
        require_company_access(ctx, new_company)
        changes["company"] = {"before": doc.company, "after": new_company}
        doc.company = new_company

    doc.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="employee.transfer",
        doctype="Employee",
        docname=employee_id,
        actor=frappe.session.user,
        payload={
            "changes": changes,
            "effective_date": effective_date,
            "reason": reason,
        },
    )

    return success_response(data={"name": doc.name, "event": "transfer"})


# ---------------------------------------------------------------------------
# Promotion
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def promote_employee(
    employee_id: str,
    new_designation: str | None = None,
    new_grade: str | None = None,
    effective_date: str | None = None,
    reason: str | None = None,
) -> dict[str, Any]:
    """Promote an employee to a new designation or grade.

    Args:
        employee_id: Employee to promote.
        new_designation: New designation name.
        new_grade: New grade name.
        effective_date: ISO date the promotion takes effect.
        reason: Free-text reason for audit trail.

    Returns:
        dict[str, Any]: Envelope with ``event`` = ``"promotion"``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If neither designation nor grade was provided.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    doc = frappe.get_doc("Employee", employee_id)
    require_company_access(ctx, doc.company)

    if not new_designation and not new_grade:
        frappe.throw(
            _("At least one of new_designation or new_grade must be provided."),
            frappe.ValidationError,
        )

    changes: dict[str, Any] = {}
    if new_designation:
        changes["designation"] = {"before": doc.designation, "after": new_designation}
        doc.designation = new_designation
    if new_grade:
        changes["grade"] = {"before": doc.grade, "after": new_grade}
        doc.grade = new_grade

    doc.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="employee.promotion",
        doctype="Employee",
        docname=employee_id,
        actor=frappe.session.user,
        payload={
            "changes": changes,
            "effective_date": effective_date,
            "reason": reason,
        },
    )

    return success_response(data={"name": doc.name, "event": "promotion"})


# ---------------------------------------------------------------------------
# Exit
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def exit_employee(
    employee_id: str,
    relieving_date: str,
    reason_for_leaving: str | None = None,
    held_on: str | None = None,
    notice_period: int | None = None,
) -> dict[str, Any]:
    """Mark an employee as having left the organisation.

    Sets status to ``Left``, records the relieving date, and writes an
    immutable exit audit event.

    Args:
        employee_id: Employee being exited.
        relieving_date: ISO date the employee is relieved.
        reason_for_leaving: Reason code or description (e.g. ``Resigned``).
        held_on: Optional ISO date the exit interview was held.
        notice_period: Notice period served in days.

    Returns:
        dict[str, Any]: Envelope with ``event`` = ``"exit"``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If relieving date is missing.
    """
    require_hr_access()
    check_rate_limit()

    if not relieving_date:
        frappe.throw(_("Relieving date is required for exit."), frappe.ValidationError)

    ctx = resolve_tenant_context()
    doc = frappe.get_doc("Employee", employee_id)
    require_company_access(ctx, doc.company)

    doc.status = "Left"
    doc.relieving_date = relieving_date
    if reason_for_leaving:
        doc.reason_for_leaving = reason_for_leaving
    if held_on:
        doc.held_on = held_on
    if notice_period is not None:
        doc.notice_period = notice_period

    doc.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="employee.exit",
        doctype="Employee",
        docname=employee_id,
        actor=frappe.session.user,
        payload={
            "relieving_date": relieving_date,
            "reason_for_leaving": reason_for_leaving,
            "held_on": held_on,
            "notice_period": notice_period,
        },
    )

    return success_response(data={"name": doc.name, "event": "exit"})


# ---------------------------------------------------------------------------
# Archive
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def archive_employee(
    employee_id: str,
    reason: str | None = None,
) -> dict[str, Any]:
    """Archive an employee record (sets status to ``Inactive``).

    Archival is a terminal state used after exit and final-settlement
    completion. It is distinct from ``Left`` so that reporting can
    distinguish active exits from fully closed records.

    Args:
        employee_id: Employee to archive.
        reason: Optional reason note for audit trail.

    Returns:
        dict[str, Any]: Envelope with ``event`` = ``"archive"``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    doc = frappe.get_doc("Employee", employee_id)
    require_company_access(ctx, doc.company)

    doc.status = "Inactive"
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="employee.archive",
        doctype="Employee",
        docname=employee_id,
        actor=frappe.session.user,
        payload={"reason": reason},
    )

    return success_response(data={"name": doc.name, "event": "archive"})
