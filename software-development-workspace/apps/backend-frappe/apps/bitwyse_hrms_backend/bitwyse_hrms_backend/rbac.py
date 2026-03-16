"""Role-Based Access Control (RBAC) matrix for HRMS API endpoints.

Implements deny-by-default access control using a declarative matrix.
Every API endpoint must pass through one of the decorators defined here,
which map endpoint names to the minimum required HRMS role.

Design:
- Roles form a hierarchy: Global HR > Company HR > Manager > Employee.
- Decorators wrap the endpoint function and call ``require_role()`` before
  any business logic executes.
- The matrix is encoded as a module-level constant so it can be inspected
  and audited without executing any endpoint logic.

Usage::

    from bitwyse_hrms_backend.rbac import hr_only, manager_or_above, self_service

    @frappe.whitelist()
    @hr_only
    def create_employee(**kwargs):
        ...
"""

from __future__ import annotations

import functools
from typing import Callable, TypeVar

import frappe
from frappe import _

from bitwyse_hrms_backend.tenant_context import (
    ROLE_COMPANY_HR,
    ROLE_EMPLOYEE,
    ROLE_GLOBAL_HR,
    ROLE_MANAGER,
)

F = TypeVar("F", bound=Callable)

# ---------------------------------------------------------------------------
# Role hierarchy (ordered from most to least privileged)
# ---------------------------------------------------------------------------

ROLE_HIERARCHY: list[str] = [
    ROLE_GLOBAL_HR,
    ROLE_COMPANY_HR,
    ROLE_MANAGER,
    ROLE_EMPLOYEE,
]


def _roles_at_or_above(role: str) -> tuple[str, ...]:
    """Return all roles that are equal to or more privileged than *role*."""
    try:
        idx = ROLE_HIERARCHY.index(role)
    except ValueError:
        return (role,)
    return tuple(ROLE_HIERARCHY[:idx + 1])


# ---------------------------------------------------------------------------
# RBAC matrix
# ---------------------------------------------------------------------------
# Maps logical access level to the set of roles that satisfy it.
# Used by decorators below.

RBAC_MATRIX: dict[str, tuple[str, ...]] = {
    # Read/write of company-wide HR data (employees, payroll, leave admin)
    "global_hr_only": (ROLE_GLOBAL_HR,),
    # Create/update employees, run payroll, manage leave allocations
    "hr_only": _roles_at_or_above(ROLE_COMPANY_HR),
    # View team data, approve leave
    "manager_or_above": _roles_at_or_above(ROLE_MANAGER),
    # Read own data (payslip, leave balance, leave requests)
    "self_service": _roles_at_or_above(ROLE_EMPLOYEE),
}


# ---------------------------------------------------------------------------
# Decorator factory
# ---------------------------------------------------------------------------


def _rbac_decorator(level: str) -> Callable[[F], F]:
    """Return a decorator that enforces the given RBAC level.

    Args:
        level: Key in ``RBAC_MATRIX``.

    Returns:
        A decorator that wraps an endpoint function with the RBAC check.
    """
    allowed_roles = RBAC_MATRIX[level]

    def decorator(fn: F) -> F:
        @functools.wraps(fn)
        def wrapper(*args, **kwargs):  # type: ignore[return]
            _enforce_rbac(allowed_roles)
            return fn(*args, **kwargs)

        # Expose metadata for introspection / tests
        wrapper._rbac_level = level  # type: ignore[attr-defined]
        wrapper._rbac_roles = allowed_roles  # type: ignore[attr-defined]
        return wrapper  # type: ignore[return-value]

    return decorator


def _enforce_rbac(allowed_roles: tuple[str, ...]) -> None:
    """Check that the current user holds at least one allowed role.

    Raises:
        frappe.AuthenticationError: If the user is not logged in.
        frappe.PermissionError: If the user's roles do not overlap with
            *allowed_roles*.
    """
    if frappe.session.user == "Guest":
        frappe.throw(_("Authentication required."), frappe.AuthenticationError)

    user_roles = set(frappe.get_roles(frappe.session.user))

    # Superusers (Administrator / System Manager) bypass RBAC checks.
    if "Administrator" in user_roles or "System Manager" in user_roles:
        return

    if not user_roles.intersection(allowed_roles):
        frappe.throw(
            _(
                "Access denied. Required role: one of {0}."
            ).format(", ".join(allowed_roles)),
            frappe.PermissionError,
        )


# ---------------------------------------------------------------------------
# Public decorators
# ---------------------------------------------------------------------------

#: Only Global HR may call this endpoint.
global_hr_only = _rbac_decorator("global_hr_only")

#: Company HR and Global HR may call this endpoint.
hr_only = _rbac_decorator("hr_only")

#: Managers, Company HR, and Global HR may call this endpoint.
manager_or_above = _rbac_decorator("manager_or_above")

#: Any authenticated HRMS user (Employee and above) may call this endpoint.
self_service = _rbac_decorator("self_service")


# ---------------------------------------------------------------------------
# Inline role check (non-decorator use)
# ---------------------------------------------------------------------------


def assert_role(level: str) -> None:
    """Programmatically enforce an RBAC level without using a decorator.

    Useful inside conditional logic within an endpoint.

    Args:
        level: Key in ``RBAC_MATRIX`` (e.g. ``"hr_only"``).

    Raises:
        KeyError: If *level* is not a valid RBAC matrix key.
        frappe.PermissionError: If the current user does not satisfy the level.
    """
    _enforce_rbac(RBAC_MATRIX[level])


# ---------------------------------------------------------------------------
# RBAC audit helper
# ---------------------------------------------------------------------------


def get_user_rbac_level() -> str:
    """Return the highest RBAC level satisfied by the current user.

    Returns:
        One of ``"global_hr_only"``, ``"hr_only"``, ``"manager_or_above"``,
        ``"self_service"``, or ``"none"`` if no HRMS role is held.
    """
    user_roles = set(frappe.get_roles(frappe.session.user))

    if user_roles.intersection(RBAC_MATRIX["global_hr_only"]):
        return "global_hr_only"
    if user_roles.intersection(RBAC_MATRIX["hr_only"]):
        return "hr_only"
    if user_roles.intersection(RBAC_MATRIX["manager_or_above"]):
        return "manager_or_above"
    if user_roles.intersection(RBAC_MATRIX["self_service"]):
        return "self_service"
    return "none"
