"""Payslip (Salary Slip) read APIs with field-level access controls.

Employees may retrieve their own payslips. Managers may see payslips for
their direct reports. Company HR and Global HR see all slips for their
company/all companies respectively.

Sensitive fields (bank account, ID numbers) are stripped for non-HR roles
to enforce the principle of least privilege.

Also exposes a ``compute_statutory_preview`` endpoint that runs the statutory
engine on a given gross pay and date — useful for HR to validate deductions
before running payroll.
"""

from __future__ import annotations

from datetime import date
from typing import Any

import frappe
from frappe import _
from frappe.utils import getdate

from bitwyse_hrms_backend.auth import (
    check_rate_limit,
    require_authenticated_user,
    require_hr_access,
)
from bitwyse_hrms_backend.payroll.statutory import (
    compute_all_deductions,
    statutory_rates_as_dict,
)
from bitwyse_hrms_backend.response import (
    coerce_pagination,
    pagination_meta,
    success_response,
)
from bitwyse_hrms_backend.tenant_context import (
    ROLE_EMPLOYEE,
    ROLE_MANAGER,
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)

# ---------------------------------------------------------------------------
# Field sets
# ---------------------------------------------------------------------------

_SLIP_LIST_FIELDS = [
    "name",
    "employee",
    "employee_name",
    "company",
    "start_date",
    "end_date",
    "posting_date",
    "gross_pay",
    "net_pay",
    "status",
    "payroll_entry",
]

_SLIP_DETAIL_FIELDS = _SLIP_LIST_FIELDS + [
    "department",
    "designation",
    "bank_account_no",      # stripped for non-HR
    "mode_of_payment",
    "payment_days",
    "total_working_days",
    "leave_without_pay",
    "absent_days",
]

# Fields removed from payslip detail for EMPLOYEE and MANAGER roles.
_SENSITIVE_FIELDS = {"bank_account_no", "mode_of_payment"}


# ---------------------------------------------------------------------------
# Visibility helpers
# ---------------------------------------------------------------------------


def _apply_slip_visibility(
    ctx,  # TenantContext
    base_filters: dict[str, Any],
) -> dict[str, Any]:
    """Augment base filters with employee or manager visibility constraints.

    Args:
        ctx: Resolved TenantContext.
        base_filters: Company-scoped filter dict.

    Returns:
        dict: Filters with additional employee or department scope.
    """
    filters = dict(base_filters)
    if ctx.is_employee_only and ctx.employee_name:
        filters["employee"] = ctx.employee_name
    elif ctx.is_manager and ctx.department:
        filters["department"] = ctx.department
    return filters


def _strip_sensitive_fields(slip: dict, ctx) -> dict:
    """Remove sensitive payslip fields for non-HR callers.

    Args:
        slip: Salary Slip field dict.
        ctx: TenantContext of the caller.

    Returns:
        dict: The slip dict with sensitive fields removed if applicable.
    """
    if ctx.is_global_hr or ctx.is_company_hr:
        return slip
    for f in _SENSITIVE_FIELDS:
        slip.pop(f, None)
    return slip


# ---------------------------------------------------------------------------
# Read endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_payslips(
    company: str | None = None,
    employee: str | None = None,
    payroll_entry: str | None = None,
    start_date: str | None = None,
    end_date: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return a paginated, role-scoped payslip list.

    Args:
        company: Optional company filter.
        employee: Optional employee filter (HR only; employees get own slips).
        payroll_entry: Optional payroll run filter.
        start_date: Optional ISO date — filter slips with ``end_date >=``.
        end_date: Optional ISO date — filter slips with ``start_date <=``.
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
    filters = _apply_slip_visibility(ctx, scope)

    if payroll_entry:
        filters["payroll_entry"] = payroll_entry

    # HR roles may request a specific employee's slips.
    if employee and (ctx.is_global_hr or ctx.is_company_hr):
        filters["employee"] = employee

    conditions: list = []
    if start_date:
        conditions.append(["Salary Slip", "end_date", ">=", start_date])
    if end_date:
        conditions.append(["Salary Slip", "start_date", "<=", end_date])

    page_start, page_size = coerce_pagination(limit_start, limit_page_length)
    items = frappe.get_list(
        "Salary Slip",
        filters=filters or None,
        or_filters=conditions if conditions else None,
        fields=_SLIP_LIST_FIELDS,
        order_by="posting_date desc",
        limit_start=page_start,
        limit_page_length=page_size,
    )
    total = frappe.db.count("Salary Slip", filters=filters or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )


@frappe.whitelist()
def get_payslip(slip_name: str) -> dict[str, Any]:
    """Return full details of a single payslip.

    Sensitive fields are stripped for employee and manager callers.
    Earnings and deductions child tables are included.

    Args:
        slip_name: Frappe Salary Slip name (primary key).

    Returns:
        dict[str, Any]: Envelope with payslip data including ``earnings``
            and ``deductions`` line items.

    Raises:
        frappe.PermissionError: If caller cannot access this payslip.
        frappe.DoesNotExistError: If the payslip does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    slip = frappe.get_value("Salary Slip", slip_name, _SLIP_DETAIL_FIELDS, as_dict=True)
    if not slip:
        frappe.throw(
            _("Payslip not found: {0}").format(slip_name),
            frappe.DoesNotExistError,
        )

    require_company_access(ctx, slip["company"])

    # Enforce employee self-service and manager visibility.
    if ctx.is_employee_only and ctx.employee_name != slip.get("employee"):
        frappe.throw(
            _("You can only access your own payslips."),
            frappe.PermissionError,
        )
    if ctx.is_manager and ctx.department and slip.get("department") != ctx.department:
        frappe.throw(
            _("You do not have access to this payslip."),
            frappe.PermissionError,
        )

    slip = _strip_sensitive_fields(dict(slip), ctx)

    # Attach earnings and deductions line items.
    slip["earnings"] = frappe.get_all(
        "Salary Detail",
        filters={"parent": slip_name, "parentfield": "earnings"},
        fields=["salary_component", "amount", "year_to_date"],
        order_by="idx asc",
    )
    slip["deductions"] = frappe.get_all(
        "Salary Detail",
        filters={"parent": slip_name, "parentfield": "deductions"},
        fields=["salary_component", "amount", "year_to_date"],
        order_by="idx asc",
    )

    return success_response(data=slip)


# ---------------------------------------------------------------------------
# Statutory preview (HR only)
# ---------------------------------------------------------------------------


@frappe.whitelist()
def compute_statutory_preview(
    gross_pay: float,
    payroll_date: str,
    insurance_premiums: float = 0.0,
) -> dict[str, Any]:
    """Compute a statutory deduction preview for a given gross pay and date.

    Used by HR to validate expected deductions before running payroll.
    Does not create or modify any documents.

    Args:
        gross_pay: Monthly gross pay in KES.
        payroll_date: ISO date string for rate selection.
        insurance_premiums: Monthly insurance premiums for PAYE relief.

    Returns:
        dict[str, Any]: Envelope with full statutory deduction breakdown.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
        frappe.ValidationError: If inputs are invalid.
    """
    require_hr_access()
    check_rate_limit()

    try:
        gross = float(gross_pay)
        premiums = float(insurance_premiums)
    except (TypeError, ValueError):
        frappe.throw(_("gross_pay and insurance_premiums must be numeric."), frappe.ValidationError)

    if gross < 0:
        frappe.throw(_("gross_pay must be non-negative."), frappe.ValidationError)

    try:
        pd = getdate(payroll_date)
    except Exception:
        frappe.throw(_("payroll_date must be a valid ISO date (YYYY-MM-DD)."), frappe.ValidationError)

    result = compute_all_deductions(gross, pd, premiums)

    data = {
        "gross_pay": result.gross_pay,
        "paye": {
            "gross_taxable": result.paye.gross_taxable,
            "tax_before_relief": result.paye.tax_before_relief,
            "personal_relief": result.paye.personal_relief,
            "insurance_relief": result.paye.insurance_relief,
            "paye_payable": result.paye.paye_payable,
        },
        "nssf": {
            "employee_contribution": result.nssf.employee_contribution,
            "employer_contribution": result.nssf.employer_contribution,
            "tier1_employee": result.nssf.tier1_employee,
            "tier2_employee": result.nssf.tier2_employee,
        },
        "shif": {
            "employee_contribution": result.shif.employee_contribution,
            "employer_contribution": result.shif.employer_contribution,
            "label": result.shif.label,
        },
        "ahl": {
            "employee_contribution": result.ahl.employee_contribution,
            "employer_contribution": result.ahl.employer_contribution,
            "active": result.ahl.active,
        },
        "total_employee_deductions": result.total_employee_deductions,
        "total_employer_contributions": result.total_employer_contributions,
        "net_pay": result.net_pay,
    }
    return success_response(data=data)


@frappe.whitelist()
def get_statutory_rates(payroll_date: str) -> dict[str, Any]:
    """Return the statutory rate configuration applicable on a given date.

    Args:
        payroll_date: ISO date string.

    Returns:
        dict[str, Any]: Envelope with the active rate table.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
        frappe.ValidationError: If the date is invalid or before known rates.
    """
    require_hr_access()
    check_rate_limit()

    try:
        pd = getdate(payroll_date)
    except Exception:
        frappe.throw(_("payroll_date must be a valid ISO date."), frappe.ValidationError)

    try:
        rates = statutory_rates_as_dict(pd)
    except ValueError as exc:
        frappe.throw(str(exc), frappe.ValidationError)

    return success_response(data=rates)
