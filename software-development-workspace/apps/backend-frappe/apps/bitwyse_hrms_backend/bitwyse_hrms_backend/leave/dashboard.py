"""Manager leave dashboard APIs.

Provides manager-facing views for:
  - Pending leave approvals in the manager's team.
  - Team coverage view: who is on leave today and in the coming week.
  - Leave summary by department for HR dashboards.
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
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    resolve_tenant_context,
)


@frappe.whitelist()
def pending_approvals(company: str | None = None) -> dict[str, Any]:
    """Return open leave requests awaiting the caller's approval.

    For managers: requests where ``leave_approver`` matches the current user
    or requests from their department with no approver set.
    For HR: all open requests within company scope.

    Args:
        company: Optional company filter (HR only).

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of pending requests.

    Raises:
        frappe.PermissionError: If caller lacks manager or HR access.
    """
    require_manager_or_above()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)
    filters: dict[str, Any] = dict(scope)
    filters["status"] = "Open"

    if ctx.is_manager and not (ctx.is_global_hr or ctx.is_company_hr):
        # Managers see requests assigned to them or from their department.
        manager_email = frappe.db.get_value("Employee", ctx.employee_name, "company_email") or \
                        frappe.db.get_value("Employee", ctx.employee_name, "personal_email") or \
                        frappe.session.user
        or_filters = [
            ["Leave Application", "leave_approver", "=", manager_email],
        ]
        if ctx.department:
            or_filters.append(["Leave Application", "department", "=", ctx.department])
        items = frappe.get_list(
            "Leave Application",
            filters=filters,
            or_filters=or_filters,
            fields=[
                "name", "employee", "employee_name", "leave_type",
                "from_date", "to_date", "total_leave_days", "description",
            ],
            order_by="from_date asc",
        )
    else:
        items = frappe.get_list(
            "Leave Application",
            filters=filters,
            fields=[
                "name", "employee", "employee_name", "leave_type",
                "from_date", "to_date", "total_leave_days", "description",
                "leave_approver",
            ],
            order_by="from_date asc",
        )

    return success_response(data={"items": items, "count": len(items)})


@frappe.whitelist()
def team_coverage(
    company: str | None = None,
    department: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None,
) -> dict[str, Any]:
    """Return team leave coverage for a date range.

    Shows which employees are on approved leave in the period so the manager
    can assess coverage risk.

    Args:
        company: Optional company filter.
        department: Optional department filter.
        from_date: ISO range start (defaults to today).
        to_date: ISO range end (defaults to 7 days from today).

    Returns:
        dict[str, Any]: Envelope with ``data.on_leave`` list and
            ``data.coverage_summary`` (days with leave concentration).

    Raises:
        frappe.PermissionError: If caller lacks manager or HR access.
    """
    require_manager_or_above()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)

    today = frappe.utils.today()
    fd = from_date or today
    td = to_date or str(frappe.utils.add_days(today, 7))

    dept = department or (ctx.department if ctx.is_manager else None)

    filters: dict[str, Any] = dict(scope)
    filters["status"] = "Approved"
    filters["from_date"] = ["<=", td]
    filters["to_date"] = [">=", fd]
    if dept:
        filters["department"] = dept

    on_leave = frappe.get_all(
        "Leave Application",
        filters=filters,
        fields=[
            "employee", "employee_name", "leave_type",
            "from_date", "to_date", "total_leave_days",
        ],
        order_by="from_date asc",
    )

    # Total headcount for coverage percentage.
    headcount_filters: dict[str, Any] = dict(scope)
    headcount_filters["status"] = "Active"
    if dept:
        headcount_filters["department"] = dept
    headcount = frappe.db.count("Employee", filters=headcount_filters)

    return success_response(
        data={
            "from_date": fd,
            "to_date": td,
            "department": dept,
            "headcount": headcount,
            "on_leave_count": len(on_leave),
            "on_leave": on_leave,
        }
    )


@frappe.whitelist()
def department_leave_summary(
    company: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None,
) -> dict[str, Any]:
    """Return leave day totals grouped by department for HR dashboards.

    Args:
        company: Optional company filter.
        from_date: ISO range start (defaults to start of current month).
        to_date: ISO range end (defaults to end of current month).

    Returns:
        dict[str, Any]: Envelope with ``data.rows`` — list of
            ``{"department", "employee_count", "leave_days_taken"}`` dicts.

    Raises:
        frappe.PermissionError: If caller lacks manager or HR access.
    """
    require_manager_or_above()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)
    effective_company: str | None = scope.get("company")

    today = frappe.utils.today()
    fd = from_date or str(frappe.utils.get_first_day(today))
    td = to_date or str(frappe.utils.get_last_day(today))

    rows = frappe.db.sql(
        """
        SELECT
            la.department,
            COUNT(DISTINCT la.employee) AS employee_count,
            SUM(la.total_leave_days) AS leave_days_taken
        FROM `tabLeave Application` la
        WHERE
            la.status = 'Approved'
            AND la.from_date <= %(to_date)s
            AND la.to_date >= %(from_date)s
            %(company_clause)s
        GROUP BY la.department
        ORDER BY leave_days_taken DESC
        """,
        {
            "from_date": fd,
            "to_date": td,
            "company_clause": f"AND la.company = '{effective_company}'" if effective_company else "",
        },
        as_dict=True,
    )

    return success_response(
        data={
            "from_date": fd,
            "to_date": td,
            "rows": [
                {
                    "department": r.get("department") or "Unassigned",
                    "employee_count": int(r.get("employee_count") or 0),
                    "leave_days_taken": float(r.get("leave_days_taken") or 0),
                }
                for r in rows
            ],
        }
    )
