"""Employee domain APIs for the HRMS external client.

Covers employee create, read, update, search, and filter with
manager-based visibility boundaries and onboarding status tracking.

Visibility rules:
  - GLOBAL_HR: sees all employees across all companies.
  - COMPANY_HR: sees all employees within their company.
  - MANAGER: sees employees in their own department only.
  - EMPLOYEE: sees only their own record (self-service).

Document metadata (uploaded attachments) is exposed as a list of
lightweight descriptors — actual file retrieval goes through Frappe's
standard file serving.
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

_EMPLOYEE_LIST_FIELDS = [
    "name",
    "employee_name",
    "company",
    "status",
    "department",
    "designation",
    "branch",
    "date_of_joining",
    "employment_type",
]

_EMPLOYEE_DETAIL_FIELDS = _EMPLOYEE_LIST_FIELDS + [
    "first_name",
    "last_name",
    "middle_name",
    "gender",
    "date_of_birth",
    "personal_email",
    "company_email",
    "cell_number",
    "reports_to",
    "grade",
    "notice_period",
    "relieving_date",
    "held_on",
    "user_id",
]


# ---------------------------------------------------------------------------
# Visibility helpers
# ---------------------------------------------------------------------------


def _apply_visibility_filters(
    ctx,  # TenantContext
    base_filters: dict[str, Any],
) -> dict[str, Any]:
    """Augment base filters with manager or employee visibility constraints.

    Args:
        ctx: Resolved TenantContext.
        base_filters: Company-scoped filter dict already built from
            ``company_scope_filters``.

    Returns:
        dict: Filters with additional department or employee-name scope
            applied where the role requires it.
    """
    filters = dict(base_filters)
    if ctx.is_manager and ctx.department:
        filters["department"] = ctx.department
    elif ctx.is_employee_only and ctx.employee_name:
        filters["name"] = ctx.employee_name
    return filters


# ---------------------------------------------------------------------------
# Read endpoints
# ---------------------------------------------------------------------------


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

    Args:
        search: Optional search phrase matched against employee name and ID.
        company: Optional company filter (validated against caller scope).
        department: Optional department filter.
        status: Optional status filter (``Active``, ``Left``, etc.).
        limit_start: Pagination start offset.
        limit_page_length: Records per page (max 100).

    Returns:
        dict[str, Any]: Envelope with ``data.items`` and ``meta`` pagination.

    Raises:
        frappe.PermissionError: If guest or outside allowed company scope.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)
    filters = _apply_visibility_filters(ctx, scope)

    if department:
        filters["department"] = department
    if status:
        filters["status"] = status

    page_start, page_size = coerce_pagination(limit_start, limit_page_length)

    or_filters = None
    if search and search.strip():
        term = f"%{search.strip()}%"
        or_filters = [
            ["Employee", "employee_name", "like", term],
            ["Employee", "name", "like", term],
        ]

    items = frappe.get_list(
        "Employee",
        fields=_EMPLOYEE_LIST_FIELDS,
        filters=filters or None,
        or_filters=or_filters,
        order_by="modified desc",
        limit_start=page_start,
        limit_page_length=page_size,
        ignore_permissions=True
    )

    total = frappe.db.count("Employee", filters=filters or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )


@frappe.whitelist()
def get_employee(employee_id: str) -> dict[str, Any]:
    """Return the full profile of a single employee.

    Args:
        employee_id: Frappe Employee name (primary key).

    Returns:
        dict[str, Any]: Envelope with ``data`` containing the employee
            record fields plus ``documents`` metadata list.

    Raises:
        frappe.PermissionError: If the caller cannot access this employee.
        frappe.DoesNotExistError: If the employee does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()

    emp = frappe.get_value(
        "Employee",
        employee_id,
        _EMPLOYEE_DETAIL_FIELDS,
        as_dict=True,
    )
    if not emp:
        frappe.throw(
            _("Employee not found: {0}").format(employee_id),
            frappe.DoesNotExistError,
        )

    # Enforce visibility.
    require_company_access(ctx, emp["company"])
    if ctx.is_manager and ctx.department and emp.get("department") != ctx.department:
        frappe.throw(
            _("You do not have access to this employee record."),
            frappe.PermissionError,
        )
    if ctx.is_employee_only and ctx.employee_name != employee_id:
        frappe.throw(
            _("You can only access your own employee record."),
            frappe.PermissionError,
        )

    # Attach document metadata.
    emp["documents"] = _get_employee_documents(employee_id)
    emp["onboarding_status"] = _get_onboarding_status(employee_id)

    return success_response(data=emp)


# ---------------------------------------------------------------------------
# Write endpoints (HR and above only)
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def create_employee(
    first_name: str,
    last_name: str,
    company: str,
    date_of_joining: str,
    department: str | None = None,
    designation: str | None = None,
    branch: str | None = None,
    employment_type: str | None = None,
    gender: str | None = None,
    date_of_birth: str | None = None,
    personal_email: str | None = None,
    company_email: str | None = None,
    cell_number: str | None = None,
    reports_to: str | None = None,
    grade: str | None = None,
) -> dict[str, Any]:
    """Create a new employee record.

    Args:
        first_name: Employee first name.
        last_name: Employee last name.
        company: Company the employee belongs to.
        date_of_joining: ISO date string (YYYY-MM-DD).
        department: Optional department name.
        designation: Optional designation name.
        branch: Optional branch name.
        employment_type: Optional employment type string.
        gender: Optional gender value.
        date_of_birth: Optional ISO date string.
        personal_email: Optional personal email address.
        company_email: Optional company email address.
        cell_number: Optional mobile number.
        reports_to: Optional Employee name this record reports to.
        grade: Optional employee grade name.

    Returns:
        dict[str, Any]: Envelope with ``data`` containing the new
            employee's ``name`` and ``employee_name``.

    Raises:
        frappe.PermissionError: If the caller lacks HR access or company scope.
        frappe.ValidationError: If required fields are missing.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    if not first_name or not first_name.strip():
        frappe.throw(_("First name is required."), frappe.ValidationError)
    if not last_name or not last_name.strip():
        frappe.throw(_("Last name is required."), frappe.ValidationError)
    if not date_of_joining:
        frappe.throw(_("Date of joining is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {
            "doctype": "Employee",
            "first_name": first_name.strip(),
            "last_name": last_name.strip(),
            "company": company,
            "date_of_joining": date_of_joining,
            "status": "Active",
            "department": department or None,
            "designation": designation or None,
            "branch": branch or None,
            "employment_type": employment_type or None,
            "gender": gender or None,
            "date_of_birth": date_of_birth or None,
            "personal_email": personal_email or None,
            "company_email": company_email or None,
            "cell_number": cell_number or None,
            "reports_to": reports_to or None,
            "grade": grade or None,
        }
    )
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    return success_response(
        data={"name": doc.name, "employee_name": doc.employee_name}
    )


@frappe.whitelist(methods=["POST"])
def update_employee(
    employee_id: str,
    department: str | None = None,
    designation: str | None = None,
    branch: str | None = None,
    employment_type: str | None = None,
    personal_email: str | None = None,
    company_email: str | None = None,
    cell_number: str | None = None,
    reports_to: str | None = None,
    grade: str | None = None,
) -> dict[str, Any]:
    """Update mutable fields on an existing employee record.

    Immutable fields (name, company, date_of_joining) are excluded.
    Use lifecycle endpoints for status changes.

    Args:
        employee_id: Frappe Employee name to update.
        department: New department name.
        designation: New designation name.
        branch: New branch name.
        employment_type: New employment type.
        personal_email: New personal email.
        company_email: New company email.
        cell_number: New mobile number.
        reports_to: New reporting manager employee name.
        grade: New grade name.

    Returns:
        dict[str, Any]: Envelope with the updated employee name.

    Raises:
        frappe.PermissionError: If the caller lacks HR access or company scope.
        frappe.DoesNotExistError: If the employee does not exist.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()

    doc = frappe.get_doc("Employee", employee_id)
    require_company_access(ctx, doc.company)

    # Only set fields that were explicitly provided (not None means caller sent it).
    if department is not None:
        doc.department = department or None
    if designation is not None:
        doc.designation = designation or None
    if branch is not None:
        doc.branch = branch or None
    if employment_type is not None:
        doc.employment_type = employment_type or None
    if personal_email is not None:
        doc.personal_email = personal_email or None
    if company_email is not None:
        doc.company_email = company_email or None
    if cell_number is not None:
        doc.cell_number = cell_number or None
    if reports_to is not None:
        doc.reports_to = reports_to or None
    if grade is not None:
        doc.grade = grade or None

    doc.save(ignore_permissions=True)
    frappe.db.commit()

    return success_response(data={"name": doc.name, "employee_name": doc.employee_name})


# ---------------------------------------------------------------------------
# Document metadata
# ---------------------------------------------------------------------------


def _get_employee_documents(employee_id: str) -> list[dict]:
    """Return lightweight metadata for files attached to an employee record.

    Args:
        employee_id: Frappe Employee name.

    Returns:
        list[dict]: Each item has ``name``, ``file_name``, ``file_url``,
            and ``creation`` fields.
    """
    return frappe.get_all(
        "File",
        filters={
            "attached_to_doctype": "Employee",
            "attached_to_name": employee_id,
        },
        fields=["name", "file_name", "file_url", "creation"],
        order_by="creation desc",
        ignore_permissions=True
    )


# ---------------------------------------------------------------------------
# Onboarding status
# ---------------------------------------------------------------------------


def _get_onboarding_status(employee_id: str) -> dict[str, Any]:
    """Return a summary of the employee's onboarding activity.

    Checks for an Employee Onboarding document linked to this employee.

    Args:
        employee_id: Frappe Employee name.

    Returns:
        dict: ``{"has_onboarding": bool, "status": str | None,
            "onboarding_name": str | None}``
    """
    onboarding = frappe.db.get_value(
        "Employee Onboarding",
        {"employee": employee_id},
        ["name", "status"],
        as_dict=True,
    )
    if onboarding:
        return {
            "has_onboarding": True,
            "status": onboarding.get("status"),
            "onboarding_name": onboarding.get("name"),
        }
    return {"has_onboarding": False, "status": None, "onboarding_name": None}
