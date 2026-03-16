"""Leave ledger, calendar availability, and anomaly detection APIs.

The leave ledger is Frappe HRMS's internal record of leave taken, allocated,
and encashed per employee. This module queries ledger entries and surfaces:

  - Balance summary per employee per leave type.
  - Calendar availability — which days in a range have approved leave.
  - Anomaly signals: over-consumption, missing allocations, overlapping
    requests, large single grants.

These signals feed both the manager dashboard and the payroll leave-pay
integration in ``payroll/leave_integration.py``.
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
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)


# ---------------------------------------------------------------------------
# Leave balance
# ---------------------------------------------------------------------------


@frappe.whitelist()
def get_leave_balance(
    employee: str,
    as_of_date: str | None = None,
) -> dict[str, Any]:
    """Return leave balance per leave type for an employee.

    Queries the ``Leave Ledger Entry`` to compute remaining balance:
      remaining = allocated - taken (approved applications)

    Args:
        employee: Frappe Employee name.
        as_of_date: Optional ISO date; defaults to today.

    Returns:
        dict[str, Any]: Envelope with ``data.balances`` — a list of
            ``{"leave_type", "allocated", "taken", "remaining"}`` dicts.

    Raises:
        frappe.PermissionError: If caller cannot access this employee.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    emp_company = frappe.db.get_value("Employee", employee, "company")
    if emp_company:
        require_company_access(ctx, emp_company)

    if ctx.is_employee_only and ctx.employee_name != employee:
        frappe.throw(_("You can only view your own leave balance."), frappe.PermissionError)

    date_filter = as_of_date or frappe.utils.today()

    # Sum ledger entries grouped by leave_type.
    # Leave Ledger Entry uses positive amounts for allocations and negative
    # amounts for leave taken (lwp/leave applications).
    ledger_rows = frappe.db.sql(
        """
        SELECT
            leave_type,
            SUM(CASE WHEN leaves > 0 THEN leaves ELSE 0 END) AS allocated,
            SUM(CASE WHEN leaves < 0 THEN ABS(leaves) ELSE 0 END) AS taken
        FROM `tabLeave Ledger Entry`
        WHERE
            employee = %(employee)s
            AND from_date <= %(as_of_date)s
            AND is_expired = 0
        GROUP BY leave_type
        ORDER BY leave_type ASC
        """,
        {"employee": employee, "as_of_date": date_filter},
        as_dict=True,
    )

    balances = [
        {
            "leave_type": row["leave_type"],
            "allocated": float(row.get("allocated") or 0),
            "taken": float(row.get("taken") or 0),
            "remaining": float(row.get("allocated") or 0) - float(row.get("taken") or 0),
        }
        for row in ledger_rows
    ]

    return success_response(
        data={
            "employee": employee,
            "as_of_date": date_filter,
            "balances": balances,
        }
    )


# ---------------------------------------------------------------------------
# Calendar availability
# ---------------------------------------------------------------------------


@frappe.whitelist()
def get_leave_calendar(
    company: str | None = None,
    department: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None,
) -> dict[str, Any]:
    """Return approved leave events for a team within a date range.

    Used by the manager view to show team coverage and identify days with
    high leave concentration.

    Args:
        company: Optional company filter.
        department: Optional department filter.
        from_date: ISO range start (defaults to start of current month).
        to_date: ISO range end (defaults to end of current month).

    Returns:
        dict[str, Any]: Envelope with ``data.events`` — list of approved
            leave applications with employee, dates, leave type.

    Raises:
        frappe.PermissionError: If guest or outside company scope.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)

    today = frappe.utils.today()
    month_start = frappe.utils.get_first_day(today)
    month_end = frappe.utils.get_last_day(today)

    fd = from_date or str(month_start)
    td = to_date or str(month_end)

    filters: dict[str, Any] = dict(scope)
    filters["status"] = "Approved"
    filters["from_date"] = ["<=", td]
    filters["to_date"] = [">=", fd]

    if department:
        filters["department"] = department
    elif ctx.is_manager and ctx.department:
        filters["department"] = ctx.department
    elif ctx.is_employee_only and ctx.employee_name:
        filters["employee"] = ctx.employee_name

    events = frappe.get_all(
        "Leave Application",
        filters=filters,
        fields=[
            "name",
            "employee",
            "employee_name",
            "leave_type",
            "from_date",
            "to_date",
            "total_leave_days",
            "half_day",
        ],
        order_by="from_date asc",
        ignore_permissions=True
    )

    return success_response(
        data={
            "from_date": fd,
            "to_date": td,
            "events": events,
        }
    )


# ---------------------------------------------------------------------------
# Anomaly detection
# ---------------------------------------------------------------------------


@frappe.whitelist()
def get_leave_anomalies(
    company: str | None = None,
    as_of_date: str | None = None,
) -> dict[str, Any]:
    """Return leave anomaly signals for HR review.

    Checks performed:
      1. Negative leave balance (over-consumption).
      2. Employees with approved leave but no active allocation for the type.
      3. Overlapping approved leave requests for the same employee.

    Args:
        company: Optional company filter.
        as_of_date: Optional ISO date for balance checks (defaults to today).

    Returns:
        dict[str, Any]: Envelope with ``data.anomalies`` — a list of anomaly
            dicts each with ``type``, ``employee``, ``employee_name``,
            ``leave_type``, and ``details``.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)
    effective_company: str | None = scope.get("company")
    date_filter = as_of_date or frappe.utils.today()

    anomalies: list[dict] = []

    # --- 1. Negative leave balance ---
    negative_balances = frappe.db.sql(
        """
        SELECT
            employee,
            leave_type,
            SUM(leaves) AS balance
        FROM `tabLeave Ledger Entry`
        WHERE
            %(company_filter)s
            from_date <= %(as_of_date)s
            AND is_expired = 0
        GROUP BY employee, leave_type
        HAVING balance < 0
        """,
        {
            "company_filter": f"company = '{effective_company}' AND " if effective_company else "",
            "as_of_date": date_filter,
        },
        as_dict=True,
    )
    for row in negative_balances:
        emp_name = frappe.db.get_value("Employee", row["employee"], "employee_name") or ""
        anomalies.append(
            {
                "type": "negative_balance",
                "employee": row["employee"],
                "employee_name": emp_name,
                "leave_type": row["leave_type"],
                "details": f"Balance: {row['balance']} days",
            }
        )

    # --- 2. Overlapping approved requests ---
    approved_apps = frappe.get_all(
        "Leave Application",
        filters={
            **({"company": effective_company} if effective_company else {}),
            "status": "Approved",
            "from_date": [">=", frappe.utils.add_months(date_filter, -3)],
        },
        fields=["name", "employee", "employee_name", "leave_type", "from_date", "to_date"],
        order_by="employee asc, from_date asc",
        ignore_permissions=True,
    )

    # Group by employee and detect overlaps.
    by_employee: dict[str, list] = {}
    for app in approved_apps:
        by_employee.setdefault(app["employee"], []).append(app)

    for emp, apps in by_employee.items():
        for i in range(len(apps)):
            for j in range(i + 1, len(apps)):
                a, b = apps[i], apps[j]
                if a["from_date"] <= b["to_date"] and b["from_date"] <= a["to_date"]:
                    anomalies.append(
                        {
                            "type": "overlapping_requests",
                            "employee": emp,
                            "employee_name": a.get("employee_name", ""),
                            "leave_type": f"{a['leave_type']} / {b['leave_type']}",
                            "details": (
                                f"Requests {a['name']} ({a['from_date']}–{a['to_date']}) "
                                f"and {b['name']} ({b['from_date']}–{b['to_date']}) overlap."
                            ),
                        }
                    )

    return success_response(
        data={
            "as_of_date": date_filter,
            "company": effective_company,
            "anomaly_count": len(anomalies),
            "anomalies": anomalies,
        }
    )


# ---------------------------------------------------------------------------
# Ledger entries (HR view)
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_leave_ledger_entries(
    employee: str,
    leave_type: str | None = None,
    from_date: str | None = None,
    to_date: str | None = None,
) -> dict[str, Any]:
    """Return raw leave ledger entries for an employee.

    Args:
        employee: Employee name to inspect.
        leave_type: Optional leave type filter.
        from_date: Optional ISO start date filter.
        to_date: Optional ISO end date filter.

    Returns:
        dict[str, Any]: Envelope with ``data.entries`` list.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    emp_company = frappe.db.get_value("Employee", employee, "company")
    if emp_company:
        require_company_access(ctx, emp_company)

    filters: dict[str, Any] = {"employee": employee}
    if leave_type:
        filters["leave_type"] = leave_type
    if from_date:
        filters["from_date"] = [">=", from_date]
    if to_date:
        filters["to_date"] = ["<=", to_date]

    entries = frappe.get_all(
        "Leave Ledger Entry",
        filters=filters,
        fields=[
            "name",
            "employee",
            "leave_type",
            "from_date",
            "to_date",
            "leaves",
            "transaction_type",
            "transaction_name",
            "is_expired",
            "creation",
        ],
        order_by="from_date desc",
        ignore_permissions=True
    )

    return success_response(data={"employee": employee, "entries": entries})
