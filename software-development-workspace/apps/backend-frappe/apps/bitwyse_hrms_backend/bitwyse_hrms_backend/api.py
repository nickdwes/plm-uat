"""Public API methods for external HRMS clients.

All endpoints follow a versioned, company-scoped response envelope defined in
``response.py``. Authentication is enforced via ``auth.py`` and company
isolation is applied through ``tenant_context.py``.

Endpoint contract:
  - All responses use the ``success_response`` / ``error_response`` envelope.
  - All list endpoints include ``meta.pagination`` with total count.
  - Guest access is denied everywhere except ``health``.
  - Company scope is derived from the caller's TenantContext.
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
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return a paginated, company-scoped employee list.

    Args:
        search: Optional search phrase matched against employee name,
            employee ID, and company fields.
        company: Optional company filter. Validated against the caller's
            TenantContext allowed companies.
        limit_start: Pagination start offset (default 0).
        limit_page_length: Records per page (default 20, max 100).

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list and ``meta``
            pagination block.

    Raises:
        frappe.PermissionError: If called by guest or unauthorised users,
            or if ``company`` is outside the caller's allowed scope.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)

    page_start, page_size = coerce_pagination(limit_start, limit_page_length)

    fields = [
        "name",
        "employee_name",
        "company",
        "status",
        "department",
        "designation",
        "date_of_joining",
    ]

    filters: dict[str, Any] = dict(scope)
    or_filters = None

    if search and search.strip():
        term = f"%{search.strip()}%"
        or_filters = [
            ["Employee", "employee_name", "like", term],
            ["Employee", "name", "like", term],
            ["Employee", "company", "like", term],
        ]

    items = frappe.get_list(
        "Employee",
        fields=fields,
        filters=filters or None,
        or_filters=or_filters,
        order_by="modified desc",
        limit_start=page_start,
        limit_page_length=page_size,
    )

    total: int = frappe.db.count("Employee", filters=filters or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )
