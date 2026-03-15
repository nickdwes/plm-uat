"""Leave-pay integration for payroll computation.

Queries the Leave Ledger and Leave Application tables to determine
unpaid leave deductions and leave encashment additions that must be
applied to an employee's salary slip for a given payroll period.

Phase D implementation replaces the Phase C stub.

Contract (stable across phases):
    get_leave_pay_impact(employee, start_date, end_date, daily_rate)
        -> LeavePayImpact
"""

from __future__ import annotations

from dataclasses import dataclass

import frappe
from frappe.utils import date_diff, getdate


@dataclass
class LeavePayImpact:
    """Leave-driven pay adjustments for a single employee period.

    Attributes:
        unpaid_leave_days: Number of unpaid (LWP) leave days in the period.
        unpaid_leave_deduction: KES amount to deduct for unpaid leave.
        encashment_days: Leave days being encashed in this period.
        encashment_addition: KES amount to add for leave encashment.
    """

    unpaid_leave_days: float = 0.0
    unpaid_leave_deduction: float = 0.0
    encashment_days: float = 0.0
    encashment_addition: float = 0.0


def get_leave_pay_impact(
    employee: str,
    start_date: str,
    end_date: str,
    daily_rate: float = 0.0,
) -> LeavePayImpact:
    """Return the leave-driven pay impact for an employee in a payroll period.

    Unpaid leave: Looks up approved Leave Applications with
    ``is_lwp = 1`` (leave without pay) that overlap the payroll period.

    Leave encashment: Looks up Leave Encashment documents submitted
    in this period for the employee.

    Args:
        employee: Frappe Employee name.
        start_date: Payroll period start ISO date.
        end_date: Payroll period end ISO date.
        daily_rate: Employee daily pay rate for deduction/encashment amounts.
            Pass 0 to get day counts only (amounts will be 0).

    Returns:
        LeavePayImpact: Populated with actual unpaid days and encashment.
    """
    unpaid_days = _get_lwp_days(employee, start_date, end_date)
    encashment_days, encashment_amount = _get_encashment(employee, start_date, end_date, daily_rate)

    unpaid_deduction = round(unpaid_days * daily_rate, 2) if daily_rate else 0.0

    return LeavePayImpact(
        unpaid_leave_days=unpaid_days,
        unpaid_leave_deduction=unpaid_deduction,
        encashment_days=encashment_days,
        encashment_addition=encashment_amount,
    )


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------


def _get_lwp_days(employee: str, start_date: str, end_date: str) -> float:
    """Return total leave-without-pay days overlapping a payroll period.

    Args:
        employee: Employee name.
        start_date: Period start ISO date.
        end_date: Period end ISO date.

    Returns:
        float: Total LWP days within the period boundary.
    """
    pd_start = getdate(start_date)
    pd_end = getdate(end_date)

    lwp_apps = frappe.get_all(
        "Leave Application",
        filters={
            "employee": employee,
            "status": "Approved",
            "from_date": ["<=", end_date],
            "to_date": [">=", start_date],
        },
        fields=["from_date", "to_date", "total_leave_days", "leave_type"],
    )

    # Only include leave types that are LWP.
    lwp_types: set[str] = set()
    if lwp_apps:
        all_types = list({a["leave_type"] for a in lwp_apps})
        rows = frappe.get_all(
            "Leave Type",
            filters={"name": ["in", all_types], "is_lwp": 1},
            fields=["name"],
        )
        lwp_types = {r["name"] for r in rows}

    total_lwp = 0.0
    for app in lwp_apps:
        if app["leave_type"] not in lwp_types:
            continue
        # Clamp application dates to the payroll period boundary.
        app_start = max(getdate(app["from_date"]), pd_start)
        app_end = min(getdate(app["to_date"]), pd_end)
        if app_end >= app_start:
            # +1 because date_diff is end - start (exclusive).
            days_in_period = date_diff(app_end, app_start) + 1
            total_lwp += days_in_period

    return float(total_lwp)


def _get_encashment(
    employee: str,
    start_date: str,
    end_date: str,
    daily_rate: float,
) -> tuple[float, float]:
    """Return leave encashment days and KES amount for a payroll period.

    Looks for submitted Leave Encashment documents whose ``encashment_date``
    falls within the payroll period.

    Args:
        employee: Employee name.
        start_date: Period start ISO date.
        end_date: Period end ISO date.
        daily_rate: Daily pay rate for amount computation.

    Returns:
        tuple[float, float]: ``(encashment_days, encashment_amount)``
    """
    encashments = frappe.get_all(
        "Leave Encashment",
        filters={
            "employee": employee,
            "docstatus": 1,  # submitted
            "encashment_date": ["between", [start_date, end_date]],
        },
        fields=["leave_days", "encashment_amount"],
    )

    total_days = sum(float(e.get("leave_days") or 0) for e in encashments)

    # Prefer the stored encashment_amount if available; otherwise compute
    # from daily rate × days.
    stored_amount = sum(float(e.get("encashment_amount") or 0) for e in encashments)
    computed_amount = round(total_days * daily_rate, 2) if daily_rate else 0.0
    amount = stored_amount if stored_amount else computed_amount

    return total_days, round(amount, 2)
