"""Organisational hierarchy APIs for the HRMS external client.

Exposes company → division → department → team tree reads for all
authenticated users (scoped to their company) and admin write operations
for Company HR and above.

Frappe DocTypes used:
    - Company      (standard ERPNext/Frappe)
    - Department   (standard HRMS)
    - Branch       (treated as division-level grouping)
    - Cost Center  (used for team-level budget mapping where applicable)

All reads are scoped through ``tenant_context``. Admin writes validate
HR role before proceeding.
"""

from __future__ import annotations

from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import (
    check_rate_limit,
    require_authenticated_user,
    require_hr_access,
)
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)


# ---------------------------------------------------------------------------
# Read endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_companies() -> dict[str, Any]:
    """Return the list of companies accessible to the current user.

    Global HR receives all companies. All other roles receive only the
    company linked to their Employee record.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of companies,
            each containing ``name``, ``company_name``, and ``country``.

    Raises:
        frappe.PermissionError: If called by a guest.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()

    fields = ["name", "company_name", "country", "default_currency"]

    if ctx.is_global_hr:
        items = frappe.get_list("Company", fields=fields, order_by="company_name asc")
    elif ctx.allowed_companies:
        items = frappe.get_list(
            "Company",
            filters=[["Company", "name", "in", ctx.allowed_companies]],
            fields=fields,
            order_by="company_name asc",
        )
    else:
        items = []

    return success_response(data={"items": items})


@frappe.whitelist()
def list_departments(company: str | None = None) -> dict[str, Any]:
    """Return departments scoped to the caller's company access.

    Args:
        company: Optional company filter. Validated against the caller's
            TenantContext; omit to use the default allowed company.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of departments,
            each containing ``name``, ``department_name``, ``company``,
            and ``parent_department``.

    Raises:
        frappe.PermissionError: If the caller cannot access the given company.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)

    fields = ["name", "department_name", "company", "parent_department"]
    items = frappe.get_list(
        "Department",
        filters=scope or None,
        fields=fields,
        order_by="department_name asc",
    )
    return success_response(data={"items": items})


@frappe.whitelist()
def get_department(department_name: str) -> dict[str, Any]:
    """Return a single department record with child department names.

    Args:
        department_name: The Frappe name (primary key) of the department.

    Returns:
        dict[str, Any]: Envelope with ``data`` containing the department
            fields plus a ``children`` list of direct child department names.

    Raises:
        frappe.PermissionError: If the caller cannot access the department's
            company.
        frappe.DoesNotExistError: If the department does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()

    dept = frappe.get_value(
        "Department",
        department_name,
        ["name", "department_name", "company", "parent_department"],
        as_dict=True,
    )
    if not dept:
        frappe.throw(_("Department not found: {0}").format(department_name), frappe.DoesNotExistError)

    require_company_access(ctx, dept["company"])

    children = frappe.get_all(
        "Department",
        filters={"parent_department": department_name},
        fields=["name", "department_name"],
        order_by="department_name asc",
    )
    dept["children"] = children
    return success_response(data=dept)


@frappe.whitelist()
def list_branches(company: str | None = None) -> dict[str, Any]:
    """Return branches (division-level) scoped to the caller's company.

    Args:
        company: Optional company filter.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of branches.

    Raises:
        frappe.PermissionError: If the caller cannot access the given company.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)

    fields = ["name", "branch"]
    # Branch doctype does not have a direct company field in all HRMS versions;
    # filter by employees in that branch when company scope is needed.
    if scope.get("company"):
        # Return branches that have at least one active employee in the company.
        branch_names = frappe.get_all(
            "Employee",
            filters={"company": scope["company"], "status": "Active"},
            fields=["branch"],
            distinct=True,
        )
        names = [b["branch"] for b in branch_names if b.get("branch")]
        if not names:
            return success_response(data={"items": []})
        items = frappe.get_list(
            "Branch",
            filters=[["Branch", "name", "in", names]],
            fields=fields,
            order_by="branch asc",
        )
    else:
        items = frappe.get_list("Branch", fields=fields, order_by="branch asc")

    return success_response(data={"items": items})


# ---------------------------------------------------------------------------
# Admin write endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def create_department(
    department_name: str,
    company: str,
    parent_department: str | None = None,
) -> dict[str, Any]:
    """Create a new department under the given company.

    Args:
        department_name: Display name for the new department.
        company: Company this department belongs to.
        parent_department: Optional parent department name for nested
            hierarchies.

    Returns:
        dict[str, Any]: Envelope with ``data`` containing the new
            department's ``name`` and ``department_name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If required fields are missing.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    if not department_name or not department_name.strip():
        frappe.throw(_("Department name is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {
            "doctype": "Department",
            "department_name": department_name.strip(),
            "company": company,
            "parent_department": parent_department or None,
        }
    )
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    return success_response(
        data={"name": doc.name, "department_name": doc.department_name}
    )


@frappe.whitelist(methods=["POST"])
def update_department(
    department_name: str,
    new_name: str | None = None,
    parent_department: str | None = None,
) -> dict[str, Any]:
    """Update an existing department's display name or parent.

    Args:
        department_name: The Frappe name of the department to update.
        new_name: Optional new display name.
        parent_department: Optional new parent department name.

    Returns:
        dict[str, Any]: Envelope with updated department data.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.DoesNotExistError: If the department does not exist.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()

    dept = frappe.get_doc("Department", department_name)
    require_company_access(ctx, dept.company)

    if new_name and new_name.strip():
        dept.department_name = new_name.strip()
    if parent_department is not None:
        dept.parent_department = parent_department or None

    dept.save(ignore_permissions=True)
    frappe.db.commit()

    return success_response(
        data={
            "name": dept.name,
            "department_name": dept.department_name,
            "parent_department": dept.parent_department,
        }
    )
