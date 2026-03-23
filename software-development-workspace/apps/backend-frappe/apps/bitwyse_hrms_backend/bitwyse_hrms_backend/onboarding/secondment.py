"""Secondment management APIs.

A secondment is a temporary assignment of an employee to a host organisation.
On the end date the employee automatically reverts (status becomes Completed).
A scheduler job handles auto-completion; HR can also call end_secondment manually.

Endpoints:
    create_secondment   — start a new secondment
    list_secondments    — list by employee or company
    get_secondment      — full detail
    end_secondment      — manually close an active secondment
"""

from __future__ import annotations

from datetime import date
from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import check_rate_limit, require_hr_access
from bitwyse_hrms_backend.audit_log import write_audit_event
from bitwyse_hrms_backend.response import coerce_pagination, pagination_meta, success_response
from bitwyse_hrms_backend.security import sanitise_date, sanitise_str
from bitwyse_hrms_backend.tenant_context import require_company_access, resolve_tenant_context
from bitwyse_hrms_backend.onboarding.models import SECONDMENT_DOCTYPE


# ---------------------------------------------------------------------------
# create_secondment
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def create_secondment(
    employee_id: str,
    host_company: str,
    start_date: str,
    end_date: str,
    host_department: str | None = None,
    reporting_manager: str | None = None,
    notes: str | None = None,
) -> dict[str, Any]:
    """Create a new secondment record for an active employee.

    The employee's home company and department are automatically captured.
    Only one Active secondment per employee is allowed at a time.

    Args:
        employee_id: Frappe Employee name (ID) to second.
        host_company: Company the employee is being seconded to.
        start_date: ISO date for secondment start.
        end_date: ISO date for secondment end.
        host_department: Optional department within the host company.
        reporting_manager: Employee ID of the reporting manager at the host.
        notes: Free-text notes.

    Returns:
        dict: Envelope with ``data.secondment_id``.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    employee_id = sanitise_str(employee_id, "employee_id", required=True)
    host_company = sanitise_str(host_company, "host_company", required=True)
    start_date = sanitise_date(start_date, "start_date", required=True)
    end_date = sanitise_date(end_date, "end_date", required=True)

    if end_date <= start_date:
        frappe.throw(_("end_date must be after start_date."), frappe.ValidationError)

    # Load the employee to capture home details and check permissions
    try:
        emp = frappe.get_doc("Employee", employee_id)
    except frappe.DoesNotExistError:
        frappe.throw(_("Employee {0} not found.").format(employee_id), frappe.DoesNotExistError)

    require_company_access(ctx, emp.company)

    # Block if there is already an active secondment for this employee
    existing = frappe.db.exists(
        SECONDMENT_DOCTYPE,
        {"employee": employee_id, "status": "Active"},
    )
    if existing:
        frappe.throw(
            _("Employee {0} already has an active secondment ({1}).").format(
                employee_id, existing
            ),
            frappe.ValidationError,
        )

    doc = frappe.get_doc({
        "doctype": SECONDMENT_DOCTYPE,
        "employee": employee_id,
        "home_company": emp.company,
        "home_department": emp.department,
        "host_company": host_company,
        "host_department": sanitise_str(host_department, "host_department"),
        "reporting_manager": sanitise_str(reporting_manager, "reporting_manager"),
        "start_date": start_date,
        "end_date": end_date,
        "status": "Active",
        "notes": sanitise_str(notes, "notes", max_len=1000),
    })
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="secondment.created",
        doctype=SECONDMENT_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee": employee_id,
            "host_company": host_company,
            "start_date": start_date,
            "end_date": end_date,
        },
    )

    return success_response(data={"secondment_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# list_secondments
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_secondments(
    employee_id: str | None = None,
    company: str | None = None,
    status: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return a paginated list of secondments.

    Args:
        employee_id: Filter by employee.
        company: Filter by home or host company.
        status: Filter by status (Active/Completed/Cancelled).
        limit_start: Pagination offset.
        limit_page_length: Page size.

    Returns:
        dict: Envelope with ``data.items`` and pagination ``meta``.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    limit_start, limit_page_length = coerce_pagination(limit_start, limit_page_length)

    filters: dict = {}
    if employee_id:
        filters["employee"] = sanitise_str(employee_id, "employee_id")
    if company:
        c = sanitise_str(company, "company")
        require_company_access(ctx, c)
        # Match either home or host company — use OR filter via sql or two queries
        # Use simple approach: filter home_company OR host_company
        # frappe.get_list doesn't natively support OR across fields, so we use two queries
        home_items = frappe.get_list(
            SECONDMENT_DOCTYPE,
            filters={**filters, "home_company": c},
            fields=["name"],
            ignore_permissions=True,
        )
        host_items = frappe.get_list(
            SECONDMENT_DOCTYPE,
            filters={**filters, "host_company": c},
            fields=["name"],
            ignore_permissions=True,
        )
        names = list({r["name"] for r in home_items + host_items})
        if not names:
            return success_response(
                data={"items": []},
                meta=pagination_meta(limit_start, limit_page_length, 0),
            )
        filters = {"name": ["in", names]}

    if status:
        filters["status"] = sanitise_str(status, "status")

    fields = [
        "name", "employee", "home_company", "home_department",
        "host_company", "host_department", "start_date", "end_date",
        "status", "reporting_manager",
    ]
    items = frappe.get_list(
        SECONDMENT_DOCTYPE,
        filters=filters,
        fields=fields,
        limit_start=limit_start,
        limit_page_length=limit_page_length,
        order_by="start_date desc",
        ignore_permissions=True,
    )
    total = frappe.db.count(SECONDMENT_DOCTYPE, filters=filters, ignore_permissions=True)

    return success_response(
        data={"items": [dict(r) for r in items]},
        meta=pagination_meta(limit_start, limit_page_length, total),
    )


# ---------------------------------------------------------------------------
# get_secondment
# ---------------------------------------------------------------------------


@frappe.whitelist()
def get_secondment(secondment_id: str) -> dict[str, Any]:
    """Return full detail for a single secondment record."""
    require_hr_access()
    check_rate_limit()

    secondment_id = sanitise_str(secondment_id, "secondment_id", required=True)
    ctx = resolve_tenant_context()

    try:
        doc = frappe.get_doc(SECONDMENT_DOCTYPE, secondment_id)
    except frappe.DoesNotExistError:
        frappe.throw(_("Secondment {0} not found.").format(secondment_id),
                    frappe.DoesNotExistError)

    require_company_access(ctx, doc.home_company)

    return success_response(data={
        "name": doc.name,
        "employee": doc.employee,
        "home_company": doc.home_company,
        "home_department": doc.home_department,
        "host_company": doc.host_company,
        "host_department": doc.host_department,
        "reporting_manager": doc.reporting_manager,
        "start_date": str(doc.start_date) if doc.start_date else None,
        "end_date": str(doc.end_date) if doc.end_date else None,
        "status": doc.status,
        "notes": doc.notes,
    })


# ---------------------------------------------------------------------------
# end_secondment
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def end_secondment(secondment_id: str) -> dict[str, Any]:
    """Manually end an active secondment, reverting the employee to home org.

    Args:
        secondment_id: HRMS Secondment document name.

    Returns:
        dict: Envelope with ``data.secondment_id`` and ``data.status``.
    """
    require_hr_access()
    check_rate_limit()

    secondment_id = sanitise_str(secondment_id, "secondment_id", required=True)
    ctx = resolve_tenant_context()

    try:
        doc = frappe.get_doc(SECONDMENT_DOCTYPE, secondment_id)
    except frappe.DoesNotExistError:
        frappe.throw(_("Secondment {0} not found.").format(secondment_id),
                    frappe.DoesNotExistError)

    require_company_access(ctx, doc.home_company)

    if doc.status != "Active":
        frappe.throw(
            _("Only Active secondments can be ended. Current status: {0}.").format(doc.status),
            frappe.ValidationError,
        )

    doc.status = "Completed"
    doc.end_date = str(date.today())
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="secondment.ended",
        doctype=SECONDMENT_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"employee": doc.employee, "host_company": doc.host_company},
    )

    return success_response(data={"secondment_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# Scheduled: auto-complete expired secondments
# ---------------------------------------------------------------------------


def auto_complete_expired_secondments() -> None:
    """Mark secondments past their end_date as Completed.

    Intended to be called by the Frappe scheduler (daily).
    """
    today = str(date.today())
    expired = frappe.get_list(
        SECONDMENT_DOCTYPE,
        filters={"status": "Active", "end_date": ["<", today]},
        fields=["name", "employee", "host_company"],
        ignore_permissions=True,
    )
    for row in expired:
        doc = frappe.get_doc(SECONDMENT_DOCTYPE, row["name"])
        doc.status = "Completed"
        doc.save(ignore_permissions=True)
        write_audit_event(
            event="secondment.auto_completed",
            doctype=SECONDMENT_DOCTYPE,
            docname=doc.name,
            actor="system",
            payload={"employee": row["employee"], "host_company": row["host_company"]},
        )
    if expired:
        frappe.db.commit()
