"""Pilot tenant setup — creates HRMS roles, default leave types, and salary components.

Run via ``bench execute bitwyse_hrms_backend.setup.fixtures.setup_pilot_tenant``
or automatically via the ``after_install`` hook on first app install.

All operations are idempotent: documents are only created when they do not
already exist, so it is safe to run multiple times.
"""

from __future__ import annotations

import frappe
from frappe import _

# ---------------------------------------------------------------------------
# HRMS Roles
# ---------------------------------------------------------------------------

HRMS_ROLES: list[dict] = [
    {
        "role_name": "HRMS Global HR",
        "desk_access": 1,
        "description": "Full cross-company HRMS access; can view all companies.",
    },
    {
        "role_name": "HRMS Company HR",
        "desk_access": 1,
        "description": "Full HRMS access scoped to assigned companies.",
    },
    {
        "role_name": "HRMS Manager",
        "desk_access": 1,
        "description": "Team-level read access; can approve leave for direct reports.",
    },
    {
        "role_name": "HRMS Employee",
        "desk_access": 0,
        "description": "Self-service access: own payslips, leave balances, and requests.",
    },
]

# ---------------------------------------------------------------------------
# Default leave types (Kenya-standard)
# ---------------------------------------------------------------------------

DEFAULT_LEAVE_TYPES: list[dict] = [
    {
        "leave_type_name": "Annual Leave",
        "max_days_allowed": 21,
        "is_paid_leave": 1,
        "is_carry_forward": 1,
        "expire_carry_forwarded_leaves_after_days": 90,
        "is_lwp": 0,
        "allow_negative": 0,
        "include_holiday": 0,
    },
    {
        "leave_type_name": "Sick Leave",
        "max_days_allowed": 14,
        "is_paid_leave": 1,
        "is_carry_forward": 0,
        "is_lwp": 0,
        "allow_negative": 0,
        "include_holiday": 1,
    },
    {
        "leave_type_name": "Maternity Leave",
        "max_days_allowed": 90,
        "is_paid_leave": 1,
        "is_carry_forward": 0,
        "is_lwp": 0,
        "allow_negative": 0,
        "include_holiday": 1,
    },
    {
        "leave_type_name": "Paternity Leave",
        "max_days_allowed": 14,
        "is_paid_leave": 1,
        "is_carry_forward": 0,
        "is_lwp": 0,
        "allow_negative": 0,
        "include_holiday": 0,
    },
    {
        "leave_type_name": "Compassionate Leave",
        "max_days_allowed": 5,
        "is_paid_leave": 1,
        "is_carry_forward": 0,
        "is_lwp": 0,
        "allow_negative": 0,
        "include_holiday": 0,
    },
    {
        "leave_type_name": "Leave Without Pay",
        "max_days_allowed": None,
        "is_paid_leave": 0,
        "is_carry_forward": 0,
        "is_lwp": 1,
        "allow_negative": 1,
        "include_holiday": 0,
    },
    {
        "leave_type_name": "Study Leave",
        "max_days_allowed": 10,
        "is_paid_leave": 1,
        "is_carry_forward": 0,
        "is_lwp": 0,
        "allow_negative": 0,
        "include_holiday": 0,
    },
]

# ---------------------------------------------------------------------------
# Default salary components (Kenya statutory)
# ---------------------------------------------------------------------------

DEFAULT_SALARY_COMPONENTS: list[dict] = [
    # ── Earnings ──────────────────────────────────────────────────────────
    {
        "salary_component": "Basic Salary",
        "abbr": "BS",
        "type": "Earning",
        "is_tax_applicable": 1,
        "depends_on_payment_days": 1,
        "description": "Fixed basic salary — base for statutory calculations.",
    },
    {
        "salary_component": "House Allowance",
        "abbr": "HsA",
        "type": "Earning",
        "is_tax_applicable": 1,
        "depends_on_payment_days": 0,
        "description": "Housing allowance — taxable per KRA guidelines.",
    },
    {
        "salary_component": "Transport Allowance",
        "abbr": "TrA",
        "type": "Earning",
        "is_tax_applicable": 1,
        "depends_on_payment_days": 0,
        "description": "Commuter transport allowance.",
    },
    # ── Deductions ────────────────────────────────────────────────────────
    {
        "salary_component": "PAYE",
        "abbr": "PAYE",
        "type": "Deduction",
        "is_tax_applicable": 0,
        "statistical_component": 0,
        "description": "Pay As You Earn income tax (KRA iTax bands).",
    },
    {
        "salary_component": "NSSF",
        "abbr": "NSSF",
        "type": "Deduction",
        "is_tax_applicable": 0,
        "description": "National Social Security Fund — employee contribution.",
    },
    {
        "salary_component": "SHIF",
        "abbr": "SHIF",
        "type": "Deduction",
        "is_tax_applicable": 0,
        "description": "Social Health Insurance Fund — 2.75% of gross (min KES 300), eff. Oct 2023.",
    },
    {
        "salary_component": "Affordable Housing Levy",
        "abbr": "AHL",
        "type": "Deduction",
        "is_tax_applicable": 0,
        "description": "Affordable Housing Levy — 1.5% employee + 1.5% employer, eff. Mar 2023.",
    },
    {
        "salary_component": "Leave Without Pay Deduction",
        "abbr": "LWP",
        "type": "Deduction",
        "is_tax_applicable": 0,
        "depends_on_payment_days": 0,
        "description": "Deduction for unpaid leave days in the payroll period.",
    },
]

# ---------------------------------------------------------------------------
# Public setup entry points
# ---------------------------------------------------------------------------


def setup_pilot_tenant() -> dict[str, list[str]]:
    """Create all HRMS roles, leave types, and salary components.

    Idempotent: skips any document that already exists.

    Returns:
        dict with keys ``created`` and ``skipped`` listing document names.
    """
    created: list[str] = []
    skipped: list[str] = []

    for item in [
        *_create_roles(),
        *_create_leave_types(),
        *_create_salary_components(),
    ]:
        (created if item[0] == "created" else skipped).append(item[1])

    frappe.db.commit()

    return {"created": created, "skipped": skipped}


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------


def _create_roles() -> list[tuple[str, str]]:
    results = []
    for role in HRMS_ROLES:
        name = role["role_name"]
        if frappe.db.exists("Role", name):
            results.append(("skipped", f"Role:{name}"))
            continue
        doc = frappe.get_doc({"doctype": "Role", **role})
        doc.insert(ignore_permissions=True)
        results.append(("created", f"Role:{name}"))
    return results


def _create_leave_types() -> list[tuple[str, str]]:
    results = []
    for lt in DEFAULT_LEAVE_TYPES:
        name = lt["leave_type_name"]
        if frappe.db.exists("Leave Type", name):
            results.append(("skipped", f"LeaveType:{name}"))
            continue
        doc = frappe.get_doc({"doctype": "Leave Type", **lt})
        doc.insert(ignore_permissions=True)
        results.append(("created", f"LeaveType:{name}"))
    return results


def _create_salary_components() -> list[tuple[str, str]]:
    results = []
    for sc in DEFAULT_SALARY_COMPONENTS:
        name = sc["salary_component"]
        if frappe.db.exists("Salary Component", name):
            results.append(("skipped", f"SalaryComponent:{name}"))
            continue
        doc = frappe.get_doc({"doctype": "Salary Component", **sc})
        doc.insert(ignore_permissions=True)
        results.append(("created", f"SalaryComponent:{name}"))
    return results
