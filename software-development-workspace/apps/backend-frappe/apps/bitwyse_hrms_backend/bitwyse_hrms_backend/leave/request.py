"""Leave request workflow APIs for the HRMS external client.

Covers the full lifecycle of a leave application:
  - Employee submits a request.
  - Manager or HR approves / rejects.
  - HR may escalate (re-assign approver).
  - Employee or HR cancels an approved or pending request.

State machine:
    Draft → Open (on submit)
    Open  → Approved  (by approver)
    Open  → Rejected  (by approver)
    Open  → Cancelled (by employee or HR)
    Approved → Cancelled (by HR only, before payroll cut-off)

Visibility:
  - EMPLOYEE: sees own requests only.
  - MANAGER:  sees requests where they are the approver, or in their dept.
  - COMPANY_HR / GLOBAL_HR: see all requests within company scope.

All state transitions write audit events.
"""

from __future__ import annotations

from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import (
    check_rate_limit,
    require_authenticated_user,
    require_manager_or_above,
)
from bitwyse_hrms_backend.audit import _write_audit_event
from bitwyse_hrms_backend.response import (
    coerce_pagination,
    pagination_meta,
    success_response,
)
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)

# ---------------------------------------------------------------------------
# Field sets
# ---------------------------------------------------------------------------

_REQUEST_LIST_FIELDS = [
    "name",
    "employee",
    "employee_name",
    "company",
    "leave_type",
    "from_date",
    "to_date",
    "total_leave_days",
    "status",
    "description",
    "leave_approver",
]

_REQUEST_DETAIL_FIELDS = _REQUEST_LIST_FIELDS + [
    "posting_date",
    "half_day",
    "half_day_date",
    "follow_via_email",
    "docstatus",
]


# ---------------------------------------------------------------------------
# Visibility helpers
# ---------------------------------------------------------------------------


def _apply_request_visibility(ctx, base_filters: dict[str, Any]) -> dict[str, Any]:
    """Add role-based visibility constraints to leave request filters.

    Args:
        ctx: Resolved TenantContext.
        base_filters: Company-scoped filter dict.

    Returns:
        dict: Augmented filters.
    """
    filters = dict(base_filters)
    if ctx.is_employee_only and ctx.employee_name:
        filters["employee"] = ctx.employee_name
    elif ctx.is_manager and ctx.department:
        filters["department"] = ctx.department
    return filters


# ---------------------------------------------------------------------------
# Read endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_leave_requests(
    employee: str | None = None,
    company: str | None = None,
    leave_type: str | None = None,
    status: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return paginated leave requests scoped to the caller's visibility.

    Args:
        employee: Optional employee filter (HR only; employees see own).
        company: Optional company filter.
        leave_type: Optional leave type filter.
        status: Optional status filter (``Open``, ``Approved``, etc.).
        from_date: Optional ISO date — filter requests with ``to_date >=``.
        to_date: Optional ISO date — filter requests with ``from_date <=``.
        limit_start: Pagination start offset.
        limit_page_length: Records per page (max 100).

    Returns:
        dict[str, Any]: Envelope with ``data.items`` and ``meta`` pagination.

    Raises:
        frappe.PermissionError: If guest or outside company scope.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)
    filters = _apply_request_visibility(ctx, scope)

    if employee and (ctx.is_global_hr or ctx.is_company_hr):
        filters["employee"] = employee
    if leave_type:
        filters["leave_type"] = leave_type
    if status:
        filters["status"] = status

    date_conditions: list = []
    if from_date:
        date_conditions.append(["Leave Application", "to_date", ">=", from_date])
    if to_date:
        date_conditions.append(["Leave Application", "from_date", "<=", to_date])

    page_start, page_size = coerce_pagination(limit_start, limit_page_length)

    items = frappe.get_list(
        "Leave Application",
        filters=filters or None,
        or_filters=date_conditions if date_conditions else None,
        fields=_REQUEST_LIST_FIELDS,
        order_by="from_date desc",
        limit_start=page_start,
        limit_page_length=page_size,
    )
    total = frappe.db.count("Leave Application", filters=filters or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )


@frappe.whitelist()
def get_leave_request(request_name: str) -> dict[str, Any]:
    """Return full details of a single leave request.

    Args:
        request_name: Frappe Leave Application name (primary key).

    Returns:
        dict[str, Any]: Envelope with leave application fields.

    Raises:
        frappe.PermissionError: If caller cannot access this request.
        frappe.DoesNotExistError: If the request does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    req = frappe.get_value("Leave Application", request_name, _REQUEST_DETAIL_FIELDS, as_dict=True)
    if not req:
        frappe.throw(
            _("Leave request not found: {0}").format(request_name),
            frappe.DoesNotExistError,
        )

    require_company_access(ctx, req["company"])

    if ctx.is_employee_only and ctx.employee_name != req.get("employee"):
        frappe.throw(_("You can only access your own leave requests."), frappe.PermissionError)

    return success_response(data=req)


# ---------------------------------------------------------------------------
# Employee write endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def submit_leave_request(
    employee: str,
    leave_type: str,
    from_date: str,
    to_date: str,
    description: str | None = None,
    half_day: int = 0,
    half_day_date: str | None = None,
    leave_approver: str | None = None,
) -> dict[str, Any]:
    """Submit a leave request on behalf of an employee.

    Employees may submit for themselves. HR may submit on behalf of any
    employee in their company scope.

    Args:
        employee: Employee name.
        leave_type: Leave Type name.
        from_date: ISO leave start date.
        to_date: ISO leave end date.
        description: Optional reason for the leave.
        half_day: 1 if this is a half-day leave, 0 otherwise.
        half_day_date: ISO date of the half day (required when half_day=1).
        leave_approver: Optional email of the approving user.

    Returns:
        dict[str, Any]: Envelope with the new request ``name`` and ``status``.

    Raises:
        frappe.PermissionError: If caller cannot act on behalf of the employee.
        frappe.ValidationError: If required fields are missing.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()

    # Employees can only submit for themselves.
    if ctx.is_employee_only and ctx.employee_name != employee:
        frappe.throw(
            _("You can only submit leave requests for yourself."),
            frappe.PermissionError,
        )

    emp_company = frappe.db.get_value("Employee", employee, "company")
    if emp_company:
        require_company_access(ctx, emp_company)

    for label, val in [("Employee", employee), ("Leave type", leave_type),
                        ("From date", from_date), ("To date", to_date)]:
        if not val or not str(val).strip():
            frappe.throw(_(f"{label} is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {
            "doctype": "Leave Application",
            "employee": employee,
            "leave_type": leave_type,
            "from_date": from_date,
            "to_date": to_date,
            "description": description or "",
            "half_day": half_day,
            "half_day_date": half_day_date or None,
            "leave_approver": leave_approver or None,
            "status": "Open",
        }
    )
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="leave_request.submitted",
        doctype="Leave Application",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": employee,
            "leave_type": leave_type,
            "from_date": from_date,
            "to_date": to_date,
        },
    )

    return success_response(data={"name": doc.name, "status": doc.status})


@frappe.whitelist(methods=["POST"])
def cancel_leave_request(
    request_name: str,
    reason: str | None = None,
) -> dict[str, Any]:
    """Cancel a pending or approved leave request.

    Employees may cancel their own Open requests. HR may cancel any request
    including Approved ones (e.g. before payroll cut-off).

    Args:
        request_name: Leave Application name to cancel.
        reason: Optional cancellation reason for audit trail.

    Returns:
        dict[str, Any]: Envelope confirming cancellation.

    Raises:
        frappe.PermissionError: If caller cannot cancel this request.
        frappe.ValidationError: If the request is already cancelled.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    req = frappe.get_doc("Leave Application", request_name)
    require_company_access(ctx, req.company)

    if ctx.is_employee_only:
        if ctx.employee_name != req.employee:
            frappe.throw(_("You can only cancel your own leave requests."), frappe.PermissionError)
        if req.status == "Approved":
            frappe.throw(
                _("Approved leave requests can only be cancelled by HR."),
                frappe.PermissionError,
            )

    if req.docstatus == 2:
        frappe.throw(
            _("Leave request {0} is already cancelled.").format(request_name),
            frappe.ValidationError,
        )

    if req.docstatus == 1:
        req.cancel()
    else:
        req.status = "Cancelled"
        req.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="leave_request.cancelled",
        doctype="Leave Application",
        docname=request_name,
        actor=frappe.session.user,
        payload={"employee": req.employee, "reason": reason},
    )

    return success_response(data={"name": request_name, "status": "Cancelled"})


# ---------------------------------------------------------------------------
# Approval endpoints (manager / HR)
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def approve_leave_request(
    request_name: str,
    remarks: str | None = None,
) -> dict[str, Any]:
    """Approve an Open leave request.

    Args:
        request_name: Leave Application name to approve.
        remarks: Optional approval remarks for audit trail.

    Returns:
        dict[str, Any]: Envelope confirming approval.

    Raises:
        frappe.PermissionError: If caller lacks manager or HR access.
        frappe.ValidationError: If the request is not in Open status.
    """
    require_manager_or_above()
    check_rate_limit()

    ctx = resolve_tenant_context()
    req = frappe.get_doc("Leave Application", request_name)
    require_company_access(ctx, req.company)

    if req.status != "Open":
        frappe.throw(
            _("Only Open leave requests can be approved. Current status: {0}").format(req.status),
            frappe.ValidationError,
        )

    req.status = "Approved"
    req.save(ignore_permissions=True)

    # Submit the document to lock it and trigger leave ledger entry.
    if req.docstatus == 0:
        req.submit()
    frappe.db.commit()

    _write_audit_event(
        event="leave_request.approved",
        doctype="Leave Application",
        docname=request_name,
        actor=frappe.session.user,
        payload={"employee": req.employee, "remarks": remarks},
    )

    return success_response(data={"name": request_name, "status": "Approved"})


@frappe.whitelist(methods=["POST"])
def reject_leave_request(
    request_name: str,
    remarks: str | None = None,
) -> dict[str, Any]:
    """Reject an Open leave request.

    Args:
        request_name: Leave Application name to reject.
        remarks: Rejection reason (communicated in audit trail).

    Returns:
        dict[str, Any]: Envelope confirming rejection.

    Raises:
        frappe.PermissionError: If caller lacks manager or HR access.
        frappe.ValidationError: If the request is not in Open status.
    """
    require_manager_or_above()
    check_rate_limit()

    ctx = resolve_tenant_context()
    req = frappe.get_doc("Leave Application", request_name)
    require_company_access(ctx, req.company)

    if req.status != "Open":
        frappe.throw(
            _("Only Open leave requests can be rejected. Current status: {0}").format(req.status),
            frappe.ValidationError,
        )

    req.status = "Rejected"
    req.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="leave_request.rejected",
        doctype="Leave Application",
        docname=request_name,
        actor=frappe.session.user,
        payload={"employee": req.employee, "remarks": remarks},
    )

    return success_response(data={"name": request_name, "status": "Rejected"})


@frappe.whitelist(methods=["POST"])
def escalate_leave_request(
    request_name: str,
    new_approver: str,
    reason: str | None = None,
) -> dict[str, Any]:
    """Re-assign the approver of a pending leave request (escalation).

    Args:
        request_name: Leave Application name to escalate.
        new_approver: Email of the new approver.
        reason: Optional escalation reason for audit trail.

    Returns:
        dict[str, Any]: Envelope confirming escalation.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
        frappe.ValidationError: If the request is not Open.
    """
    from bitwyse_hrms_backend.auth import require_hr_access
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    req = frappe.get_doc("Leave Application", request_name)
    require_company_access(ctx, req.company)

    if req.status != "Open":
        frappe.throw(
            _("Only Open requests can be escalated. Current status: {0}").format(req.status),
            frappe.ValidationError,
        )

    old_approver = req.leave_approver
    req.leave_approver = new_approver
    req.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="leave_request.escalated",
        doctype="Leave Application",
        docname=request_name,
        actor=frappe.session.user,
        payload={
            "employee": req.employee,
            "old_approver": old_approver,
            "new_approver": new_approver,
            "reason": reason,
        },
    )

    return success_response(
        data={"name": request_name, "leave_approver": new_approver}
    )
