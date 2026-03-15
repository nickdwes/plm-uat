"""Public API methods for external HRMS clients.

All endpoints follow a versioned, company-scoped response envelope defined in
``response.py``. Authentication is enforced via ``auth.py`` and company
isolation is applied through ``tenant_context.py``.

Endpoint contract:
  - All responses use the ``success_response`` / ``error_response`` envelope.
  - All list endpoints include ``meta.pagination`` with total count.
  - Guest access is denied everywhere except ``health``.
  - Company scope is derived from the caller's TenantContext.

Phase B re-exports:
  - Org hierarchy: list_companies, list_departments, get_department,
    list_branches, create_department, update_department
  - Employee: list_employees (replaces top-level), get_employee,
    create_employee, update_employee
  - Roles: list_designations, create_designation, list_grades, get_grade,
    create_grade, list_employment_types
  - Lifecycle: transfer_employee, promote_employee, exit_employee,
    archive_employee
"""

from __future__ import annotations

from typing import Any

import frappe

from bitwyse_hrms_backend.auth import check_rate_limit, require_authenticated_user
from bitwyse_hrms_backend.response import (
    coerce_pagination,
    pagination_meta,
    success_response,
)
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    resolve_tenant_context,
)

# Phase B – re-export domain endpoints so Frappe can discover them at the
# ``bitwyse_hrms_backend.api.*`` path used by the external UI.
from bitwyse_hrms_backend.hr.org import (  # noqa: F401
    create_department,
    get_department,
    list_branches,
    list_companies,
    list_departments,
    update_department,
)
from bitwyse_hrms_backend.hr.employee import (  # noqa: F401
    create_employee,
    get_employee,
    update_employee,
    list_employees as _hr_list_employees,
)
from bitwyse_hrms_backend.hr.roles import (  # noqa: F401
    create_designation,
    create_grade,
    get_grade,
    list_designations,
    list_employment_types,
    list_grades,
)
from bitwyse_hrms_backend.hr.lifecycle import (  # noqa: F401
    archive_employee,
    exit_employee,
    promote_employee,
    transfer_employee,
)


@frappe.whitelist(allow_guest=True)
def health() -> dict[str, str]:
    """Return a lightweight backend health response.

    This is the only endpoint accessible without authentication. It is
    intended for load-balancer and uptime-monitor connectivity checks.

    Returns:
        dict[str, str]: Service name and status.
    """
    return {
        "service": "bitwyse_hrms_backend",
        "status": "ok",
        "api_version": "1",
    }


@frappe.whitelist()
def hrms_dashboard_summary(company: str | None = None) -> dict[str, Any]:
    """Return high-level HRMS counters scoped to the caller's company access.

    Args:
        company: Optional company to filter by. When omitted, the caller's
            default company from their TenantContext is used. Global HR may
            pass any company or omit for a site-wide aggregate.

    Returns:
        dict[str, Any]: Envelope with ``data`` containing:
            - company: Effective company name (or None for global aggregate).
            - total_employees: Employee count within scope.
            - total_salary_structures: Salary Structure count within scope.
            - total_payroll_periods: Payroll Period count within scope.

    Raises:
        frappe.PermissionError: If called by guest or unauthorised users.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)

    employee_filters = dict(scope)
    ss_filters = dict(scope)
    pp_filters = dict(scope)

    # For global HR with no company filter, aggregate across all companies.
    effective_company: str | None = scope.get("company")

    data = {
        "company": effective_company,
        "total_employees": frappe.db.count("Employee", filters=employee_filters or None),
        "total_salary_structures": frappe.db.count(
            "Salary Structure", filters=ss_filters or None
        ),
        "total_payroll_periods": frappe.db.count(
            "Payroll Period", filters=pp_filters or None
        ),
    }
    return success_response(data)


@frappe.whitelist()
def list_employees(
    search: str | None = None,
    company: str | None = None,
    department: str | None = None,
    status: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return a paginated, role-scoped employee list.

    Delegates to ``hr.employee.list_employees`` which applies manager-based
    visibility on top of company scoping.

    Args:
        search: Optional search phrase.
        company: Optional company filter.
        department: Optional department filter.
        status: Optional status filter (``Active``, ``Left``, etc.).
        limit_start: Pagination start offset.
        limit_page_length: Records per page (max 100).

    Returns:
        dict[str, Any]: Envelope with ``data.items`` and ``meta`` pagination.

    Raises:
        frappe.PermissionError: If called by guest or outside company scope.
    """
    return _hr_list_employees(
        search=search,
        company=company,
        department=department,
        status=status,
        limit_start=limit_start,
        limit_page_length=limit_page_length,
    )
