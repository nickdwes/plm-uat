"""Shadow payroll — dry-run computation engine.

A shadow payroll run computes every employee's gross, statutory deductions,
and net pay for a given payroll period **without writing any documents to the
database**.  It is used to:

1. Validate salary structures and leave impacts before a live run.
2. Let HR preview the total payroll cost and statutory obligations.
3. Flag anomalies (missing salary structures, LWP edge cases, zero-net
   employees) before committing.

The output mirrors the structure of a real payroll run so the frontend can
render it identically, with an extra ``shadow: true`` flag in the envelope.

This module is pure-Python: it queries Frappe read-only and returns plain
dicts.  No inserts, updates, or commits are performed.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import date
from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import check_rate_limit
from bitwyse_hrms_backend.payroll.statutory import compute_all_deductions
from bitwyse_hrms_backend.payroll.leave_integration import get_leave_pay_impact
from bitwyse_hrms_backend.rbac import hr_only
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.security import sanitise_company, sanitise_date, assert_date_order
from bitwyse_hrms_backend.tenant_context import require_company_access, resolve_tenant_context


# ---------------------------------------------------------------------------
# Result data classes
# ---------------------------------------------------------------------------


@dataclass
class ShadowSlip:
    """Shadow payslip for a single employee."""

    employee: str
    employee_name: str
    department: str | None
    designation: str | None
    gross_pay: float
    basic: float
    # Statutory deductions
    paye: float
    nssf_employee: float
    nssf_employer: float
    shif: float
    ahl_employee: float
    ahl_employer: float
    total_deductions: float
    net_pay: float
    # Leave impact
    unpaid_leave_days: float
    unpaid_leave_deduction: float
    encashment_addition: float
    # Anomalies
    anomalies: list[str] = field(default_factory=list)


@dataclass
class ShadowRunResult:
    """Aggregate result for a shadow payroll run."""

    company: str
    start_date: str
    end_date: str
    shadow: bool = True
    employee_count: int = 0
    total_gross: float = 0.0
    total_paye: float = 0.0
    total_nssf_employee: float = 0.0
    total_nssf_employer: float = 0.0
    total_shif: float = 0.0
    total_ahl_employee: float = 0.0
    total_ahl_employer: float = 0.0
    total_net: float = 0.0
    total_unpaid_deductions: float = 0.0
    anomaly_count: int = 0
    slips: list[dict] = field(default_factory=list)


# ---------------------------------------------------------------------------
# Public API endpoint
# ---------------------------------------------------------------------------


@frappe.whitelist()
@hr_only
def run_shadow_payroll(
    company: str,
    start_date: str,
    end_date: str,
    department: str | None = None,
) -> dict[str, Any]:
    """Execute a dry-run shadow payroll for all eligible employees.

    Computes PAYE, NSSF, SHIF, AHL, leave deductions, and net pay for each
    active employee in the company (optionally filtered to one department)
    without writing any documents.

    Args:
        company: Company to run shadow payroll for.
        start_date: Period start date (ISO 8601).
        end_date: Period end date (ISO 8601).
        department: Optional department filter.

    Returns:
        Envelope with ``data`` = :class:`ShadowRunResult` serialised to dict,
        plus ``shadow: true`` in the envelope metadata.

    Raises:
        frappe.PermissionError: If caller is not Company HR or above.
        frappe.ValidationError: If inputs are invalid.
    """
    check_rate_limit()

    safe_company = sanitise_company(company, required=True)
    safe_start = sanitise_date(start_date, field="start_date", required=True)
    safe_end = sanitise_date(end_date, field="end_date", required=True)
    assert_date_order(safe_start, safe_end)

    ctx = resolve_tenant_context()
    require_company_access(ctx, safe_company)

    employees = _fetch_employees(safe_company, department)
    result = _compute_shadow_run(safe_company, safe_start, safe_end, employees)

    response = success_response(asdict(result))
    response["shadow"] = True
    return response


# ---------------------------------------------------------------------------
# Internal computation helpers
# ---------------------------------------------------------------------------


def _fetch_employees(company: str, department: str | None) -> list[dict]:
    """Return active employees for the shadow run."""
    filters: dict = {"company": company, "status": "Active"}
    if department:
        filters["department"] = department

    return frappe.get_all(
        "Employee",
        filters=filters,
        fields=[
            "name",
            "employee_name",
            "department",
            "designation",
        ],
    )


def _get_gross(employee: str, start_date: str, end_date: str) -> tuple[float, float, list[str]]:
    """Return (gross_pay, basic, anomaly_list) for an employee.

    Looks for an active Salary Structure Assignment to derive the base pay.
    Returns 0.0 with an anomaly flag if no assignment is found.
    """
    anomalies: list[str] = []

    assignments = frappe.get_all(
        "Salary Structure Assignment",
        filters={
            "employee": employee,
            "from_date": ["<=", end_date],
            "docstatus": 1,
        },
        fields=["base", "from_date"],
        order_by="from_date desc",
        limit=1,
    )

    if not assignments:
        anomalies.append("No active salary structure assignment found.")
        return 0.0, 0.0, anomalies

    base = float(assignments[0].get("base") or 0)
    if base <= 0:
        anomalies.append(f"Salary base is {base} — check structure assignment.")

    return base, base, anomalies


def _compute_shadow_run(
    company: str,
    start_date: str,
    end_date: str,
    employees: list[dict],
) -> ShadowRunResult:
    """Compute shadow slips for all employees and aggregate totals."""
    # Determine working days in the period for daily rate
    period_days = max(1, _working_days(start_date, end_date))

    result = ShadowRunResult(company=company, start_date=start_date, end_date=end_date)

    for emp in employees:
        slip = _compute_slip(emp, start_date, end_date, period_days)
        result.slips.append(asdict(slip))
        result.employee_count += 1
        result.total_gross += slip.gross_pay
        result.total_paye += slip.paye
        result.total_nssf_employee += slip.nssf_employee
        result.total_nssf_employer += slip.nssf_employer
        result.total_shif += slip.shif
        result.total_ahl_employee += slip.ahl_employee
        result.total_ahl_employer += slip.ahl_employer
        result.total_net += slip.net_pay
        result.total_unpaid_deductions += slip.unpaid_leave_deduction
        if slip.anomalies:
            result.anomaly_count += 1

    # Round aggregate totals to 2dp
    for attr in (
        "total_gross", "total_paye", "total_nssf_employee", "total_nssf_employer",
        "total_shif", "total_ahl_employee", "total_ahl_employer",
        "total_net", "total_unpaid_deductions",
    ):
        setattr(result, attr, round(getattr(result, attr), 2))

    return result


def _compute_slip(
    emp: dict,
    start_date: str,
    end_date: str,
    period_days: int,
) -> ShadowSlip:
    """Compute a single shadow payslip."""
    anomalies: list[str] = []

    gross, basic, struct_anomalies = _get_gross(emp["name"], start_date, end_date)
    anomalies.extend(struct_anomalies)

    daily_rate = round(gross / period_days, 4) if period_days and gross else 0.0

    # Leave pay impact
    leave_impact = get_leave_pay_impact(emp["name"], start_date, end_date, daily_rate)
    adjusted_gross = max(0.0, gross - leave_impact.unpaid_leave_deduction + leave_impact.encashment_addition)

    # Statutory deductions
    deductions = compute_all_deductions(adjusted_gross, as_of=date.fromisoformat(end_date))
    net = max(0.0, adjusted_gross - deductions["total_deductions"])

    if net == 0.0 and adjusted_gross > 0:
        anomalies.append("Net pay is zero — verify deductions against gross.")

    return ShadowSlip(
        employee=emp["name"],
        employee_name=emp.get("employee_name", ""),
        department=emp.get("department"),
        designation=emp.get("designation"),
        gross_pay=round(adjusted_gross, 2),
        basic=round(basic, 2),
        paye=deductions["paye"],
        nssf_employee=deductions["nssf_employee"],
        nssf_employer=deductions["nssf_employer"],
        shif=deductions["shif"],
        ahl_employee=deductions["ahl_employee"],
        ahl_employer=deductions["ahl_employer"],
        total_deductions=deductions["total_deductions"],
        net_pay=round(net, 2),
        unpaid_leave_days=leave_impact.unpaid_leave_days,
        unpaid_leave_deduction=leave_impact.unpaid_leave_deduction,
        encashment_addition=leave_impact.encashment_addition,
        anomalies=anomalies,
    )


def _working_days(start_date: str, end_date: str) -> int:
    """Return calendar days in the period (inclusive).

    A simple calendar-day count is used for daily rate calculation.
    Holiday calendars are not applied at this layer (they are handled
    inside Frappe HRMS salary slip computation for the live run).
    """
    from frappe.utils import date_diff
    return date_diff(end_date, start_date) + 1
