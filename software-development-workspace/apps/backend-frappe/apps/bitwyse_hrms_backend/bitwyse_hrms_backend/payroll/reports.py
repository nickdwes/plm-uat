"""Statutory output packs and reconciliation reports for Kenya payroll.

Provides HR-facing reports that support monthly compliance operations:

  - P9 Report      : Annual employee tax deduction summary (KRA return input).
  - P10 Report     : Monthly PAYE reconciliation per company.
  - NSSF Schedule  : Monthly NSSF contribution listing.
  - SHIF Schedule  : Monthly SHIF contribution listing.
  - AHL Schedule   : Monthly AHL contribution listing.
  - Payroll Summary: Earnings vs deductions summary for a run or period.

All reports are company-scoped and require HR access. They return structured
data suitable for CSV generation on the frontend or direct KRA portal upload.

Phase 1 note: Reports aggregate from Salary Slip ``Salary Detail`` records.
In production, statutory components must be named consistently across Salary
Structures (e.g. exactly "PAYE", "NSSF", "SHIF", "AHL") for the aggregation
to be accurate. The component name constants below should match the live setup.
"""

from __future__ import annotations

from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import check_rate_limit, require_hr_access
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)

# ---------------------------------------------------------------------------
# Salary component name constants
# These must match the Salary Component names configured in Frappe HRMS.
# ---------------------------------------------------------------------------

COMPONENT_PAYE = "PAYE"
COMPONENT_NSSF = "NSSF"
COMPONENT_SHIF = "SHIF"
COMPONENT_NHIF = "NHIF"
COMPONENT_AHL = "Affordable Housing Levy"


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------


def _get_submitted_slips(
    company: str,
    start_date: str,
    end_date: str,
) -> list[dict]:
    """Return submitted/paid salary slips for a company within a date range.

    Args:
        company: Company name.
        start_date: Period start ISO date.
        end_date: Period end ISO date.

    Returns:
        list[dict]: Salary slip records with key identity fields.
    """
    return frappe.get_all(
        "Salary Slip",
        filters={
            "company": company,
            "docstatus": 1,  # submitted only
            "start_date": [">=", start_date],
            "end_date": ["<=", end_date],
        },
        fields=[
            "name",
            "employee",
            "employee_name",
            "department",
            "designation",
            "gross_pay",
            "net_pay",
            "start_date",
            "end_date",
        ],
        order_by="employee_name asc",
        ignore_permissions=True
    )


def _get_component_amount(slip_name: str, component_names: list[str]) -> float:
    """Return the sum of deduction amounts for matching components on a slip.

    Args:
        slip_name: Salary Slip name.
        component_names: List of salary component names to match.

    Returns:
        float: Summed amount across matching deduction lines.
    """
    rows = frappe.get_all(
        "Salary Detail",
        filters={
            "parent": slip_name,
            "parentfield": "deductions",
            "salary_component": ["in", component_names],
        },
        fields=["amount"],
        ignore_permissions=True
    )
    return sum(r.get("amount", 0) or 0 for r in rows)


# ---------------------------------------------------------------------------
# Report endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def payroll_summary_report(
    company: str,
    start_date: str,
    end_date: str,
) -> dict[str, Any]:
    """Return a payroll summary for a company and date range.

    Aggregates gross pay, total deductions, and net pay across all submitted
    salary slips in the period.

    Args:
        company: Company to report on.
        start_date: ISO period start date.
        end_date: ISO period end date.

    Returns:
        dict[str, Any]: Envelope with ``data`` containing totals and
            per-employee rows.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    slips = _get_submitted_slips(company, start_date, end_date)

    total_gross = sum(s.get("gross_pay") or 0 for s in slips)
    total_net = sum(s.get("net_pay") or 0 for s in slips)
    total_deductions = round(total_gross - total_net, 2)

    return success_response(
        data={
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
            "slip_count": len(slips),
            "total_gross_pay": round(total_gross, 2),
            "total_deductions": total_deductions,
            "total_net_pay": round(total_net, 2),
            "rows": slips,
        }
    )


@frappe.whitelist()
def paye_p10_report(
    company: str,
    start_date: str,
    end_date: str,
) -> dict[str, Any]:
    """Return a P10 monthly PAYE reconciliation for KRA submission.

    Lists each employee's gross taxable pay and PAYE withheld for the period.

    Args:
        company: Company to report on.
        start_date: ISO period start date.
        end_date: ISO period end date.

    Returns:
        dict[str, Any]: Envelope with per-employee PAYE rows and totals.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    slips = _get_submitted_slips(company, start_date, end_date)
    rows = []
    total_paye = 0.0

    for slip in slips:
        paye = _get_component_amount(slip["name"], [COMPONENT_PAYE])
        total_paye += paye
        rows.append(
            {
                "employee": slip["employee"],
                "employee_name": slip["employee_name"],
                "gross_pay": slip.get("gross_pay") or 0,
                "paye": round(paye, 2),
            }
        )

    return success_response(
        data={
            "report": "P10",
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
            "total_paye": round(total_paye, 2),
            "rows": rows,
        }
    )


@frappe.whitelist()
def paye_p9_report(
    company: str,
    tax_year: int,
) -> dict[str, Any]:
    """Return P9 annual PAYE summary per employee for the given tax year.

    The Kenya tax year runs January to December.

    Args:
        company: Company to report on.
        tax_year: Four-digit year (e.g. 2024).

    Returns:
        dict[str, Any]: Envelope with annual per-employee PAYE rows.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If tax_year is invalid.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    try:
        year = int(tax_year)
    except (TypeError, ValueError):
        frappe.throw(_("tax_year must be a valid integer year."), frappe.ValidationError)

    start_date = f"{year}-01-01"
    end_date = f"{year}-12-31"

    slips = _get_submitted_slips(company, start_date, end_date)

    # Aggregate per employee across all monthly slips in the year.
    employee_totals: dict[str, dict] = {}
    for slip in slips:
        emp = slip["employee"]
        if emp not in employee_totals:
            employee_totals[emp] = {
                "employee": emp,
                "employee_name": slip["employee_name"],
                "annual_gross": 0.0,
                "annual_paye": 0.0,
            }
        employee_totals[emp]["annual_gross"] += slip.get("gross_pay") or 0
        employee_totals[emp]["annual_paye"] += _get_component_amount(slip["name"], [COMPONENT_PAYE])

    rows = [
        {
            "employee": v["employee"],
            "employee_name": v["employee_name"],
            "annual_gross": round(v["annual_gross"], 2),
            "annual_paye": round(v["annual_paye"], 2),
        }
        for v in employee_totals.values()
    ]
    rows.sort(key=lambda r: r["employee_name"])

    return success_response(
        data={
            "report": "P9",
            "company": company,
            "tax_year": year,
            "row_count": len(rows),
            "total_annual_paye": round(sum(r["annual_paye"] for r in rows), 2),
            "rows": rows,
        }
    )


@frappe.whitelist()
def nssf_schedule(
    company: str,
    start_date: str,
    end_date: str,
) -> dict[str, Any]:
    """Return the monthly NSSF contribution schedule for a company.

    Args:
        company: Company to report on.
        start_date: ISO period start date.
        end_date: ISO period end date.

    Returns:
        dict[str, Any]: Envelope with per-employee NSSF rows and total.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    slips = _get_submitted_slips(company, start_date, end_date)
    rows = []
    total = 0.0

    for slip in slips:
        nssf = _get_component_amount(slip["name"], [COMPONENT_NSSF])
        total += nssf
        rows.append(
            {
                "employee": slip["employee"],
                "employee_name": slip["employee_name"],
                "gross_pay": slip.get("gross_pay") or 0,
                "employee_nssf": round(nssf, 2),
                "employer_nssf": round(nssf, 2),  # Mirrors employee contribution.
            }
        )

    return success_response(
        data={
            "report": "NSSF Schedule",
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
            "total_employee_nssf": round(total, 2),
            "total_employer_nssf": round(total, 2),
            "rows": rows,
        }
    )


@frappe.whitelist()
def shif_schedule(
    company: str,
    start_date: str,
    end_date: str,
) -> dict[str, Any]:
    """Return the monthly SHIF/NHIF contribution schedule for a company.

    Args:
        company: Company to report on.
        start_date: ISO period start date.
        end_date: ISO period end date.

    Returns:
        dict[str, Any]: Envelope with per-employee SHIF rows and total.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    slips = _get_submitted_slips(company, start_date, end_date)
    rows = []
    total = 0.0

    for slip in slips:
        shif = _get_component_amount(slip["name"], [COMPONENT_SHIF, COMPONENT_NHIF])
        total += shif
        rows.append(
            {
                "employee": slip["employee"],
                "employee_name": slip["employee_name"],
                "gross_pay": slip.get("gross_pay") or 0,
                "shif_contribution": round(shif, 2),
            }
        )

    return success_response(
        data={
            "report": "SHIF Schedule",
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
            "total_shif": round(total, 2),
            "rows": rows,
        }
    )


@frappe.whitelist()
def ahl_schedule(
    company: str,
    start_date: str,
    end_date: str,
) -> dict[str, Any]:
    """Return the monthly AHL contribution schedule for a company.

    Args:
        company: Company to report on.
        start_date: ISO period start date.
        end_date: ISO period end date.

    Returns:
        dict[str, Any]: Envelope with per-employee AHL rows and total.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    slips = _get_submitted_slips(company, start_date, end_date)
    rows = []
    total_employee = 0.0
    total_employer = 0.0

    for slip in slips:
        ahl_emp = _get_component_amount(slip["name"], [COMPONENT_AHL])
        total_employee += ahl_emp
        total_employer += ahl_emp  # Employer mirrors employee.
        rows.append(
            {
                "employee": slip["employee"],
                "employee_name": slip["employee_name"],
                "gross_pay": slip.get("gross_pay") or 0,
                "employee_ahl": round(ahl_emp, 2),
                "employer_ahl": round(ahl_emp, 2),
            }
        )

    return success_response(
        data={
            "report": "AHL Schedule",
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
            "total_employee_ahl": round(total_employee, 2),
            "total_employer_ahl": round(total_employer, 2),
            "rows": rows,
        }
    )
