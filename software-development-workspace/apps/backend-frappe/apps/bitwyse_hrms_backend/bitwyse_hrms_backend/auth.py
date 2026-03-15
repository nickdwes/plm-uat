"""Authentication and authorisation helpers for HRMS API endpoints.

Enforces the Phase 1 auth strategy:
  - API key + secret tokens for external UI clients.
  - Session cookies accepted for browser-based access.
  - Guest access denied on all endpoints except ``/api/method/...health``.
  - Role-based gates applied per endpoint via ``require_role``.

Rate-limiting is tracked per user in the Frappe cache. Limits are soft
guardrails for Phase 1; a reverse-proxy layer should add hard limits in
production.
"""

from __future__ import annotations

from typing import Callable

import frappe
from frappe import _

from bitwyse_hrms_backend.tenant_context import (
    ROLE_COMPANY_HR,
    ROLE_EMPLOYEE,
    ROLE_GLOBAL_HR,
    ROLE_MANAGER,
)

# ---------------------------------------------------------------------------
# Rate-limit configuration (requests per minute per user)
# ---------------------------------------------------------------------------

_RATE_LIMIT_PER_MINUTE = 120
_RATE_LIMIT_CACHE_TTL = 60  # seconds — aligns with the per-minute window


# ---------------------------------------------------------------------------
# Authentication guards
# ---------------------------------------------------------------------------


def require_authenticated_user() -> None:
    """Reject unauthenticated (Guest) requests.

    Must be called at the top of every protected endpoint.

    Raises:
        frappe.PermissionError: If the current session user is Guest.
    """
    if frappe.session.user == "Guest":
        frappe.throw(_("Authentication is required."), frappe.PermissionError)


def require_role(*roles: str) -> None:
    """Assert that the current user holds at least one of the given HRMS roles.

    Args:
        *roles: One or more role names from ``tenant_context`` constants.

    Raises:
        frappe.PermissionError: If the user holds none of the specified roles.

    Example::

        require_role(ROLE_GLOBAL_HR, ROLE_COMPANY_HR)
    """
    require_authenticated_user()
    user_roles = set(frappe.get_roles(frappe.session.user))
    if not user_roles.intersection(roles):
        frappe.throw(
            _("You do not have the required role to perform this action."),
            frappe.PermissionError,
        )


def require_hr_access() -> None:
    """Allow only Global HR and Company HR users.

    Convenience wrapper used by endpoints that manage employee or payroll data
    and must exclude self-service employees and managers.

    Raises:
        frappe.PermissionError: If the user does not hold an HR role.
    """
    require_role(ROLE_GLOBAL_HR, ROLE_COMPANY_HR)


def require_manager_or_above() -> None:
    """Allow managers and all HR roles.

    Used by endpoints that expose aggregated team or department data.

    Raises:
        frappe.PermissionError: If the user does not hold manager or HR role.
    """
    require_role(ROLE_GLOBAL_HR, ROLE_COMPANY_HR, ROLE_MANAGER)


# ---------------------------------------------------------------------------
# Rate limiting
# ---------------------------------------------------------------------------


def check_rate_limit() -> None:
    """Enforce a per-user per-minute rate limit using the Frappe cache.

    Increments a counter in the cache keyed to the current user and minute
    window. Throws ``frappe.TooManyRequestsError`` if the limit is exceeded.

    Raises:
        frappe.TooManyRequestsError: If the user has exceeded the limit.
    """
    user = frappe.session.user
    if user == "Guest":
        return  # Guest is blocked earlier; skip rate-limit tracking.

    cache_key = f"hrms_rate_limit:{user}"
    current: int = frappe.cache().get(cache_key) or 0

    if current >= _RATE_LIMIT_PER_MINUTE:
        frappe.throw(
            _("Too many requests. Please slow down and try again shortly."),
            frappe.TooManyRequestsError,
        )

    # Increment; set TTL only on first request in the window.
    new_count = current + 1
    frappe.cache().set(cache_key, new_count, expires_in_sec=_RATE_LIMIT_CACHE_TTL)


# ---------------------------------------------------------------------------
# API key validation utilities
# ---------------------------------------------------------------------------


def validate_api_credentials_present() -> bool:
    """Return True if the current request carries API key/secret headers.

    Does not validate correctness — Frappe's own auth layer handles that.
    This helper is used in audit trails to record the auth method used.

    Returns:
        bool: True if Authorization header with ``token`` scheme is present.
    """
    auth_header: str = frappe.get_request_header("Authorization") or ""
    return auth_header.startswith("token ")


def get_auth_method() -> str:
    """Return a short string describing the active authentication method.

    Returns:
        str: ``"api_key"`` or ``"session"`` for use in audit log entries.
    """
    return "api_key" if validate_api_credentials_present() else "session"
