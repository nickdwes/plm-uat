"""Public API methods for external HRMS clients.

All endpoints follow a versioned, company-scoped response envelope defined in
``response.py``. Authentication is enforced via ``auth.py`` and company
isolation is applied through ``tenant_context.py``.

Phase F additions:
  - ``@timed`` observability decorator on all top-level endpoints.
  - ``sanitise_*`` input validation applied to health + dashboard + list_employees.
  - ``@rbac.*`` decorators applied to dashboard and employee list.
"""

from __future__ import annotations

from typing import Any

import frappe

from bitwyse_hrms_backend.auth import check_rate_limit, require_authenticated_user
from bitwyse_hrms_backend.observability import timed
from bitwyse_hrms_backend.rbac import manager_or_above, self_service
from bitwyse_hrms_backend.response import (
    coerce_pagination,
    pagination_meta,
    success_response,
)
from bitwyse_hrms_backend.security import sanitise_company, sanitise_int, sanitise_search, sanitise_str
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

# Phase C – payroll re-exports
from bitwyse_hrms_backend.payroll.period import (  # noqa: F401
    create_payroll_period,
    get_payroll_period,
    list_payroll_periods,
    lock_payroll_period,
)
from bitwyse_hrms_backend.payroll.run import (  # noqa: F401
    cancel_payroll_run,
    create_payroll_run,
    get_payroll_run,
    list_payroll_runs,
    list_run_exceptions,
    submit_payroll_run,
)
from bitwyse_hrms_backend.payroll.payslip import (  # noqa: F401
    compute_statutory_preview,
    get_payslip,
    get_statutory_rates,
    list_payslips,
)
from bitwyse_hrms_backend.payroll.reports import (  # noqa: F401
    ahl_schedule,
    nssf_schedule,
    paye_p10_report,
    paye_p9_report,
    payroll_summary_report,
    shif_schedule,
)

# Phase D – leave management re-exports
from bitwyse_hrms_backend.leave.policy import (  # noqa: F401
    create_leave_allocation,
    create_leave_policy,
    get_leave_policy,
    get_leave_type,
    list_leave_allocations,
    list_leave_policies,
    list_leave_types,
)
from bitwyse_hrms_backend.leave.request import (  # noqa: F401
    approve_leave_request,
    cancel_leave_request,
    escalate_leave_request,
    get_leave_request,
    list_leave_requests,
    reject_leave_request,
    submit_leave_request,
)
from bitwyse_hrms_backend.leave.ledger import (  # noqa: F401
    get_leave_anomalies,
    get_leave_balance,
    get_leave_calendar,
    list_leave_ledger_entries,
)
from bitwyse_hrms_backend.leave.dashboard import (  # noqa: F401
    department_leave_summary,
    pending_approvals,
    team_coverage,
)

# Phase D dashboard alias used by LeaveView frontend
from bitwyse_hrms_backend.leave.dashboard import pending_approvals as list_pending_approvals  # noqa: F401
from bitwyse_hrms_backend.leave.ledger import get_leave_balance as get_leave_balances  # noqa: F401


@frappe.whitelist(allow_guest=True)
@timed
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
@timed
@self_service
def hrms_dashboard_summary(company: str | None = None) -> dict[str, Any]:
    """Return high-level HRMS counters scoped to the caller's company access.

    Args:
        company: Optional company to filter by. When omitted, the caller's
            default company from their TenantContext is used. Global HR may
            pass any company or omit for a site-wide aggregate.

    Returns:
        dict[str, Any]: Envelope with ``data`` containing employee, salary
            structure, and payroll period counts for the effective scope.

    Raises:
        frappe.PermissionError: If called by guest or unauthorised users.
    """
    check_rate_limit()

    safe_company = sanitise_company(company)

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, safe_company)

    employee_filters = dict(scope)
    ss_filters = dict(scope)
    pp_filters = dict(scope)

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
@timed
@manager_or_above
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
        search: Optional free-text search phrase (max 140 chars).
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
    safe_search = sanitise_search(search)
    safe_company = sanitise_company(company)
    safe_department = sanitise_str(department, field="department")
    safe_status = sanitise_str(status, field="status", max_len=32)
    safe_start = sanitise_int(limit_start, field="limit_start", min_val=0, max_val=100_000)
    safe_length = sanitise_int(limit_page_length, field="limit_page_length", min_val=1, max_val=100, default=20)

    return _hr_list_employees(
        search=safe_search,
        company=safe_company,
        department=safe_department,
        status=safe_status,
        limit_start=safe_start,
        limit_page_length=safe_length,
    )

