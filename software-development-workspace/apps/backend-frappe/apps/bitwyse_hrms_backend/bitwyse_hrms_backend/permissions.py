"""Frappe permission query conditions for company-scoped DocType access.

Frappe calls the functions in this module when building SQL for ``get_list``
and ``get_doc`` calls, allowing row-level visibility to be enforced at the
database layer rather than only in API code.
"""

from __future__ import annotations

import frappe

from bitwyse_hrms_backend.tenant_context import (
    ROLE_GLOBAL_HR,
    resolve_tenant_context,
)


def get_employee_permission_query(user: str | None = None) -> str:
    """Return a SQL WHERE fragment restricting Employee visibility by company.

    Frappe passes the logged-in user name. Global HR sees all; everyone else
    is restricted to their own company.

    Args:
        user: Frappe user name supplied by the framework. When None, the
            current session user is used.

    Returns:
        str: A SQL condition string, e.g. ``"`tabEmployee`.`company` = 'Acme'"``.
            Returns an empty string to allow unrestricted access for Global HR.
    """
    effective_user = user or frappe.session.user

    if effective_user == "Guest":
        # No row is visible to guests.
        return "1=0"

    user_roles = set(frappe.get_roles(effective_user))
    if ROLE_GLOBAL_HR in user_roles:
        # Global HR sees all employees across all companies.
        return ""

    # Resolve the company linked to this user's Employee record.
    employee_company: str | None = frappe.db.get_value(
        "Employee",
        {"user_id": effective_user, "status": "Active"},
        "company",
    )

    if not employee_company:
        # User has no linked employee — show nothing.
        return "1=0"

    safe_company = employee_company.replace("'", "\\'")
    return f"`tabEmployee`.`company` = '{safe_company}'"
