"""Leave policy engine APIs for the HRMS external client.

A Leave Policy in Frappe HRMS defines the entitlement rules for each leave
type — annual leave, sick leave, maternity/paternity, compassionate, etc.
Policies are assigned per employee grade (cadre) or directly per employee.

This module exposes:
  - Read: list and detail views for leave types and policies.
  - Read: leave allocation listing per employee.
  - Admin write: create leave policy, assign policy to employee/grade.
  - Admin write: create leave allocations (annual grant).

Accrual, carryover, and cutoff rules are defined within the Leave Policy
document itself (Frappe HRMS manages the accrual engine via scheduled jobs).
This API layer surfaces the configuration and allocations; it does NOT
reimplement the accrual engine.
"""

from __future__ import annotations

from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import (
    check_rate_limit,
    require_authenticated_user,
    require_hr_access,
)
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
# Leave type endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_leave_types() -> dict[str, Any]:
    """Return all leave types configured on the site.

    Leave types are site-wide in Frappe HRMS (not company-scoped).
    All authenticated users may read them.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of leave types,
            each with ``name``, ``max_days_allowed``, ``is_carry_forward``,
            ``is_paid_leave``, and ``allow_negative``.

    Raises:
        frappe.PermissionError: If called by a guest.
    """
    require_authenticated_user()
    check_rate_limit()

    items = frappe.get_list(
        "Leave Type",
        fields=[
            "name",
            "max_days_allowed",
            "is_carry_forward",
            "is_paid_leave",
            "allow_negative",
            "include_holiday",
        ],
        order_by="name asc",
    )
    return success_response(data={"items": items})


@frappe.whitelist()
def get_leave_type(leave_type: str) -> dict[str, Any]:
    """Return full details of a single leave type.

    Args:
        leave_type: Frappe Leave Type name.

    Returns:
        dict[str, Any]: Envelope with leave type fields.

    Raises:
        frappe.PermissionError: If called by a guest.
        frappe.DoesNotExistError: If the leave type does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    lt = frappe.get_value(
        "Leave Type",
        leave_type,
        [
            "name",
            "max_days_allowed",
            "is_carry_forward",
            "is_paid_leave",
            "allow_negative",
            "include_holiday",
            "is_lwp",
            "expire_carry_forwarded_leaves_after_days",
        ],
        as_dict=True,
    )
    if not lt:
        frappe.throw(
            _("Leave Type not found: {0}").format(leave_type),
            frappe.DoesNotExistError,
        )
    return success_response(data=lt)


# ---------------------------------------------------------------------------
# Leave policy endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_leave_policies() -> dict[str, Any]:
    """Return all leave policies on the site.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of policies.

    Raises:
        frappe.PermissionError: If called by a guest.
    """
    require_authenticated_user()
    check_rate_limit()

    items = frappe.get_list(
        "Leave Policy",
        fields=["name", "leave_policy_name"],
        order_by="leave_policy_name asc",
    )
    return success_response(data={"items": items})


@frappe.whitelist()
def get_leave_policy(policy_name: str) -> dict[str, Any]:
    """Return full leave policy including leave type entitlement details.

    Args:
        policy_name: Frappe Leave Policy name.

    Returns:
        dict[str, Any]: Envelope with policy fields and ``leave_type_details``
            list (annual_days, leave_type, etc. per leave type in the policy).

    Raises:
        frappe.PermissionError: If called by a guest.
        frappe.DoesNotExistError: If the policy does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    policy = frappe.get_value(
        "Leave Policy",
        policy_name,
        ["name", "leave_policy_name"],
        as_dict=True,
    )
    if not policy:
        frappe.throw(
            _("Leave Policy not found: {0}").format(policy_name),
            frappe.DoesNotExistError,
        )

    # Fetch leave entitlement details from the child table.
    details = frappe.get_all(
        "Leave Policy Detail",
        filters={"parent": policy_name},
        fields=["leave_type", "annual_days"],
        order_by="leave_type asc",
    )
    policy["leave_type_details"] = details
    return success_response(data=policy)


@frappe.whitelist(methods=["POST"])
def create_leave_policy(
    leave_policy_name: str,
    leave_type_details: list | None = None,
) -> dict[str, Any]:
    """Create a new leave policy with optional entitlement details.

    Args:
        leave_policy_name: Display name for the policy.
        leave_type_details: Optional list of ``{"leave_type": str,
            "annual_days": int}`` dicts defining entitlements.

    Returns:
        dict[str, Any]: Envelope with the new policy's ``name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
        frappe.ValidationError: If name is blank.
    """
    require_hr_access()
    check_rate_limit()

    if not leave_policy_name or not leave_policy_name.strip():
        frappe.throw(_("Leave policy name is required."), frappe.ValidationError)

    doc_data: dict[str, Any] = {
        "doctype": "Leave Policy",
        "leave_policy_name": leave_policy_name.strip(),
    }

    if leave_type_details:
        doc_data["leave_policy_details"] = [
            {
                "doctype": "Leave Policy Detail",
                "leave_type": row.get("leave_type"),
                "annual_days": row.get("annual_days", 0),
            }
            for row in leave_type_details
            if row.get("leave_type")
        ]

    doc = frappe.get_doc(doc_data)
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    return success_response(data={"name": doc.name, "leave_policy_name": doc.leave_policy_name})


# ---------------------------------------------------------------------------
# Leave allocation endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_leave_allocations(
    employee: str | None = None,
    company: str | None = None,
    leave_type: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return leave allocations scoped to the caller's company.

    Args:
        employee: Optional employee filter.
        company: Optional company filter.
        leave_type: Optional leave type filter.
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
    page_start, page_size = coerce_pagination(limit_start, limit_page_length)

    filters: dict[str, Any] = dict(scope)

    # Self-service employees see only their own allocations.
    if ctx.is_employee_only and ctx.employee_name:
        filters["employee"] = ctx.employee_name
    elif employee and (ctx.is_global_hr or ctx.is_company_hr):
        filters["employee"] = employee

    if leave_type:
        filters["leave_type"] = leave_type

    items = frappe.get_list(
        "Leave Allocation",
        filters=filters or None,
        fields=[
            "name",
            "employee",
            "employee_name",
            "company",
            "leave_type",
            "from_date",
            "to_date",
            "new_leaves_allocated",
            "carry_forwarded_leaves_count",
            "total_leaves_allocated",
            "status",
        ],
        order_by="from_date desc",
        limit_start=page_start,
        limit_page_length=page_size,
    )
    total = frappe.db.count("Leave Allocation", filters=filters or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )


@frappe.whitelist(methods=["POST"])
def create_leave_allocation(
    employee: str,
    leave_type: str,
    from_date: str,
    to_date: str,
    new_leaves_allocated: float,
    carry_forward: int = 0,
) -> dict[str, Any]:
    """Create a leave allocation for an employee.

    Args:
        employee: Employee name (primary key).
        leave_type: Leave Type name.
        from_date: ISO start date of the allocation period.
        to_date: ISO end date of the allocation period.
        new_leaves_allocated: Number of new leave days to grant.
        carry_forward: 1 to carry forward unused leaves, 0 otherwise.

    Returns:
        dict[str, Any]: Envelope with the new allocation's ``name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If required fields are missing.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    emp_company = frappe.db.get_value("Employee", employee, "company")
    if emp_company:
        require_company_access(ctx, emp_company)

    for label, val in [
        ("Employee", employee),
        ("Leave type", leave_type),
        ("From date", from_date),
        ("To date", to_date),
    ]:
        if not val or not str(val).strip():
            frappe.throw(_(f"{label} is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {
            "doctype": "Leave Allocation",
            "employee": employee,
            "leave_type": leave_type,
            "from_date": from_date,
            "to_date": to_date,
            "new_leaves_allocated": new_leaves_allocated,
            "carry_forward": carry_forward,
        }
    )
    doc.insert(ignore_permissions=True)
    doc.submit()
    frappe.db.commit()

    return success_response(
        data={
            "name": doc.name,
            "employee": doc.employee,
            "leave_type": doc.leave_type,
            "total_leaves_allocated": getattr(doc, "total_leaves_allocated", new_leaves_allocated),
        }
    )
