"""Tenant context resolution for multi-company HRMS isolation.

Provides company-scoped access control on a single Frappe site. All API
endpoints must obtain a TenantContext and apply it when filtering data so
that no user can observe records from a company they are not authorised for.

Role hierarchy (highest to lowest privilege):
    GLOBAL_HR   – reads/writes across all companies; used by group HR.
    COMPANY_HR  – full read/write within their assigned company only.
    MANAGER     – read/write within their department/team only.
    EMPLOYEE    – self-service access to their own records only.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import frappe
from frappe import _

# ---------------------------------------------------------------------------
# Role constants
# ---------------------------------------------------------------------------

ROLE_GLOBAL_HR = "HRMS Global HR"
ROLE_COMPANY_HR = "HRMS Company HR"
ROLE_MANAGER = "HRMS Manager"
ROLE_EMPLOYEE = "HRMS Employee"

# Ordered from highest to lowest privilege for single-role resolution.
ROLE_PRIORITY: list[str] = [
    ROLE_GLOBAL_HR,
    ROLE_COMPANY_HR,
    ROLE_MANAGER,
    ROLE_EMPLOYEE,
]


# ---------------------------------------------------------------------------
# TenantContext dataclass
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class TenantContext:
    """Resolved access scope for the current request.

    Attributes:
        user: Frappe user name (email).
        primary_role: Highest-privilege HRMS role held by the user.
        allowed_companies: Companies the user may access.
            Empty list means no company is accessible.
            None is reserved for GLOBAL_HR (unrestricted).
        employee_name: Frappe Employee docname linked to this user, if any.
        department: Department name linked to this user's employee record.
    """

    user: str
    primary_role: str | None
    allowed_companies: list[str] | None  # None → unrestricted (Global HR)
    employee_name: str | None
    department: str | None

    @property
    def is_global_hr(self) -> bool:
        """Return True if the user holds the Global HR role."""
        return self.primary_role == ROLE_GLOBAL_HR

    @property
    def is_company_hr(self) -> bool:
        """Return True if the user holds the Company HR role."""
        return self.primary_role == ROLE_COMPANY_HR

    @property
    def is_manager(self) -> bool:
        """Return True if the user holds the Manager role."""
        return self.primary_role == ROLE_MANAGER

    @property
    def is_employee_only(self) -> bool:
        """Return True if the user holds only the Employee self-service role."""
        return self.primary_role == ROLE_EMPLOYEE

    def can_access_company(self, company: str) -> bool:
        """Return True if this context permits access to the given company.

        Args:
            company: Company name to check.

        Returns:
            bool: True if access is allowed.
        """
        if self.is_global_hr:
            return True
        return self.allowed_companies is not None and company in self.allowed_companies


# ---------------------------------------------------------------------------
# Context resolution
# ---------------------------------------------------------------------------


def resolve_tenant_context() -> TenantContext:
    """Build a TenantContext for the currently authenticated Frappe user.

    Reads the user's roles, linked Employee record, and company assignments
    to derive the minimal access scope for this request.

    Returns:
        TenantContext: Fully resolved context for the current user.

    Raises:
        frappe.PermissionError: If the session user is Guest.
    """
    user = frappe.session.user
    if user == "Guest":
        frappe.throw(_("Authentication is required."), frappe.PermissionError)

    user_roles: set[str] = set(frappe.get_roles(user))

    # Superusers (Administrator / System Manager) get unrestricted Global HR access.
    if "Administrator" in user_roles or "System Manager" in user_roles:
        return TenantContext(
            user=user,
            primary_role=ROLE_GLOBAL_HR,
            allowed_companies=None,
            employee_name=None,
            department=None,
        )

    # Resolve highest-privilege HRMS role.
    primary_role: str | None = None
    for role in ROLE_PRIORITY:
        if role in user_roles:
            primary_role = role
            break

    # Look up the Employee record linked to this user.
    employee_name: str | None = frappe.db.get_value(
        "Employee", {"user_id": user, "status": "Active"}, "name"
    )
    department: str | None = None
    employee_company: str | None = None

    if employee_name:
        emp_data = frappe.db.get_value(
            "Employee",
            employee_name,
            ["department", "company"],
            as_dict=True,
        )
        if emp_data:
            department = emp_data.get("department")
            employee_company = emp_data.get("company")

    # Determine allowed companies based on role.
    allowed_companies: list[str] | None
    if primary_role == ROLE_GLOBAL_HR:
        allowed_companies = None  # unrestricted
    elif primary_role in (ROLE_COMPANY_HR, ROLE_MANAGER, ROLE_EMPLOYEE):
        # Scope to the company on the user's own Employee record.
        allowed_companies = [employee_company] if employee_company else []
    else:
        # No recognised HRMS role — no company access.
        allowed_companies = []

    return TenantContext(
        user=user,
        primary_role=primary_role,
        allowed_companies=allowed_companies,
        employee_name=employee_name,
        department=department,
    )


# ---------------------------------------------------------------------------
# Scoped filter helpers
# ---------------------------------------------------------------------------


def company_scope_filters(
    ctx: TenantContext,
    requested_company: str | None = None,
) -> dict[str, Any]:
    """Build Frappe filter kwargs that enforce company-level isolation.

    Args:
        ctx: Resolved TenantContext for the current user.
        requested_company: Optional company name supplied by the caller.
            When provided, access is validated against the context before use.

    Returns:
        dict: Filter dictionary safe to pass into ``frappe.get_list`` as
            ``filters``.

    Raises:
        frappe.PermissionError: If the requested company is not in the
            allowed scope for this context.
    """
    if ctx.is_global_hr:
        # Global HR may optionally filter by a specific company.
        if requested_company:
            return {"company": requested_company}
        return {}

    if not ctx.allowed_companies:
        frappe.throw(
            _("Your account is not linked to any company. Contact your HR administrator."),
            frappe.PermissionError,
        )

    effective_company: str
    if requested_company:
        if not ctx.can_access_company(requested_company):
            frappe.throw(
                _("You do not have access to company: {0}").format(requested_company),
                frappe.PermissionError,
            )
        effective_company = requested_company
    else:
        effective_company = ctx.allowed_companies[0]

    return {"company": effective_company}


def require_company_access(ctx: TenantContext, company: str) -> None:
    """Assert that the context has access to the given company.

    Args:
        ctx: Resolved TenantContext for the current user.
        company: The company name to validate.

    Raises:
        frappe.PermissionError: If access is denied.
    """
    if not ctx.can_access_company(company):
        frappe.throw(
            _("You do not have access to company: {0}").format(company),
            frappe.PermissionError,
        )
