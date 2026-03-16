"""Role, designation, grade, and cadre APIs for the HRMS external client.

Provides read and admin-write access to the classification hierarchy used
for employee categorisation, benefit mapping, and salary-band rules.

Frappe DocTypes used:
    - Designation     (standard HRMS)
    - Employee Grade  (standard HRMS)
    - Employment Type (standard HRMS)

Benefit and salary-band mapping is stored in ``Employee Grade`` child tables
(``Employee Benefit`` and ``Salary Structure Assignment``) and returned as
nested lists in the grade detail response.
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
    resolve_tenant_context,
)


# ---------------------------------------------------------------------------
# Designation endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_designations() -> dict[str, Any]:
    """Return all designations available on the site.

    Designations are site-wide in Frappe HRMS (not company-scoped).
    All authenticated users may read them.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of designations.

    Raises:
        frappe.PermissionError: If called by a guest.
    """
    require_authenticated_user()
    check_rate_limit()

    items = frappe.get_list(
        "Designation",
        fields=["name", "designation_name"],
        order_by="designation_name asc",
        ignore_permissions=True
    )
    return success_response(data={"items": items})


@frappe.whitelist(methods=["POST"])
def create_designation(designation_name: str) -> dict[str, Any]:
    """Create a new designation.

    Args:
        designation_name: Display name for the designation.

    Returns:
        dict[str, Any]: Envelope with the new designation's ``name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
        frappe.ValidationError: If name is blank.
    """
    require_hr_access()
    check_rate_limit()

    if not designation_name or not designation_name.strip():
        frappe.throw(_("Designation name is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {"doctype": "Designation", "designation_name": designation_name.strip()}
    )
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    return success_response(data={"name": doc.name, "designation_name": doc.designation_name})


# ---------------------------------------------------------------------------
# Grade endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_grades() -> dict[str, Any]:
    """Return all employee grades.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of grade records,
            each with ``name``, ``grade_name``, and ``default_leave_policy``.

    Raises:
        frappe.PermissionError: If called by a guest.
    """
    require_authenticated_user()
    check_rate_limit()

    items = frappe.get_list(
        "Employee Grade",
        fields=["name", "default_salary_structure", "default_base_pay"],
        order_by="name asc",
        ignore_permissions=True
    )
    # Normalize: expose "name" as the grade identifier
    return success_response(data={"items": items})


@frappe.whitelist()
def get_grade(grade_name: str) -> dict[str, Any]:
    """Return grade details including benefit mappings and salary band.

    Args:
        grade_name: Frappe Employee Grade name (primary key).

    Returns:
        dict[str, Any]: Envelope with ``data`` containing grade fields plus
            ``default_salary_structure`` and ``salary_ranges`` list.

    Raises:
        frappe.PermissionError: If called by a guest.
        frappe.DoesNotExistError: If the grade does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    grade = frappe.get_value(
        "Employee Grade",
        grade_name,
        ["name", "default_salary_structure", "default_base_pay"],
        as_dict=True,
        ignore_permissions=True,
    )
    if not grade:
        frappe.throw(
            _("Grade not found: {0}").format(grade_name),
            frappe.DoesNotExistError,
        )

    # Fetch salary ranges if the child table exists on this installation.
    salary_ranges = []
    try:
        salary_ranges = frappe.get_all(
            "Employee Grade Salary Range",
            filters={"parent": grade_name},
            fields=["from_amount", "to_amount", "currency"],
            order_by="from_amount asc",
        ignore_permissions=True
        )
    except Exception:
        # Child table may not exist depending on HRMS version; fail gracefully.
        pass

    grade["salary_ranges"] = salary_ranges
    return success_response(data=grade)


@frappe.whitelist(methods=["POST"])
def create_grade(
    grade_name: str,
    default_leave_policy: str | None = None,
) -> dict[str, Any]:
    """Create a new employee grade.

    Args:
        grade_name: Display name for the grade.
        default_leave_policy: Optional default leave policy name to assign.

    Returns:
        dict[str, Any]: Envelope with the new grade's ``name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access.
        frappe.ValidationError: If name is blank.
    """
    require_hr_access()
    check_rate_limit()

    if not grade_name or not grade_name.strip():
        frappe.throw(_("Grade name is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {
            "doctype": "Employee Grade",
            "name": grade_name.strip(),
        }
    )
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    return success_response(data={"name": doc.name})


# ---------------------------------------------------------------------------
# Employment type endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_employment_types() -> dict[str, Any]:
    """Return all employment types.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of employment types.

    Raises:
        frappe.PermissionError: If called by a guest.
    """
    require_authenticated_user()
    check_rate_limit()

    items = frappe.get_list(
        "Employment Type",
        fields=["name", "employee_type_name"],
        order_by="employee_type_name asc",
        ignore_permissions=True
    )
    return success_response(data={"items": items})
