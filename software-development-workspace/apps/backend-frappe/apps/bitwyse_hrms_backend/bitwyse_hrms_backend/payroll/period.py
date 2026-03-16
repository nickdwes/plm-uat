"""Payroll period control APIs for the HRMS external client.

A Payroll Period in Frappe HRMS defines the date range used to generate
salary slips. This module adds company-scoped controls for:
  - Listing and viewing payroll periods.
  - Creating periods per company with cutoff and processing dates.
  - Locking a period to prevent further slip generation or edits.
  - Tracking approval state before posting to accounts.

Locking and posting are one-way transitions:
    Open → Locked → Posted

A locked period can still generate correction slips if ``allow_rerun``
is explicitly set by Company HR or above.
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
from bitwyse_hrms_backend.audit import _write_audit_event
from bitwyse_hrms_backend.response import (
    coerce_pagination,
    pagination_meta,
    success_response,
)
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)

# ---------------------------------------------------------------------------
# Period status constants
# ---------------------------------------------------------------------------

STATUS_OPEN = "Active"      # Frappe Payroll Period uses "Active"
STATUS_LOCKED = "Locked"    # Custom extension field we manage
STATUS_POSTED = "Posted"    # After accounts posting


# ---------------------------------------------------------------------------
# Read endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_payroll_periods(
    company: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return paginated payroll periods scoped to the caller's company.

    Args:
        company: Optional company filter.
        limit_start: Pagination start offset.
        limit_page_length: Records per page (max 100).

    Returns:
        dict[str, Any]: Envelope with ``data.items`` and ``meta`` pagination.

    Raises:
        frappe.PermissionError: If guest or outside company scope.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    scope = company_scope_filters(ctx, company)
    page_start, page_size = coerce_pagination(limit_start, limit_page_length)

    fields = [
        "name",
        "period_name",
        "company",
        "start_date",
        "end_date",
        "status",
    ]
    items = frappe.get_list(
        "Payroll Period",
        filters=scope or None,
        fields=fields,
        order_by="start_date desc",
        limit_start=page_start,
        limit_page_length=page_size,
        ignore_permissions=True
    )
    total = frappe.db.count("Payroll Period", filters=scope or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )


@frappe.whitelist()
def get_payroll_period(period_name: str) -> dict[str, Any]:
    """Return full details of a single payroll period.

    Args:
        period_name: Frappe Payroll Period name (primary key).

    Returns:
        dict[str, Any]: Envelope with period data.

    Raises:
        frappe.PermissionError: If the caller cannot access the period's company.
        frappe.DoesNotExistError: If the period does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    period = frappe.get_value(
        "Payroll Period",
        period_name,
        ["name", "period_name", "company", "start_date", "end_date", "status"],
        as_dict=True,
    )
    if not period:
        frappe.throw(
            _("Payroll Period not found: {0}").format(period_name),
            frappe.DoesNotExistError,
        )
    require_company_access(ctx, period["company"])
    return success_response(data=period)


# ---------------------------------------------------------------------------
# Admin write endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def create_payroll_period(
    period_name: str,
    company: str,
    start_date: str,
    end_date: str,
) -> dict[str, Any]:
    """Create a new payroll period for a company.

    Args:
        period_name: Display name (e.g. ``"March 2025"``).
        company: Company this period belongs to.
        start_date: ISO date period starts.
        end_date: ISO date period ends.

    Returns:
        dict[str, Any]: Envelope with new period ``name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If required fields are missing.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    for label, val in [("Period name", period_name), ("Start date", start_date), ("End date", end_date)]:
        if not val or not str(val).strip():
            frappe.throw(_(f"{label} is required."), frappe.ValidationError)

    doc = frappe.get_doc(
        {
            "doctype": "Payroll Period",
            "period_name": period_name.strip(),
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
        }
    )
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="payroll_period.created",
        doctype="Payroll Period",
        docname=doc.name,
        actor=frappe.session.user,
        payload={"company": company, "start_date": start_date, "end_date": end_date},
    )

    return success_response(data={"name": doc.name, "period_name": doc.period_name})


@frappe.whitelist(methods=["POST"])
def lock_payroll_period(period_name: str) -> dict[str, Any]:
    """Lock a payroll period to prevent further salary slip creation.

    Locking is a one-way transition. Once locked, new slips cannot be
    generated for this period unless ``allow_rerun`` is invoked.

    Args:
        period_name: Payroll Period name to lock.

    Returns:
        dict[str, Any]: Envelope confirming the lock.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If the period is already locked or posted.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    period = frappe.get_doc("Payroll Period", period_name)
    require_company_access(ctx, period.company)

    if period.status in (STATUS_LOCKED, STATUS_POSTED):
        frappe.throw(
            _("Payroll Period {0} is already {1} and cannot be locked again.").format(
                period_name, period.status
            ),
            frappe.ValidationError,
        )

    period.status = STATUS_LOCKED
    period.save(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="payroll_period.locked",
        doctype="Payroll Period",
        docname=period_name,
        actor=frappe.session.user,
        payload={"company": period.company},
    )

    return success_response(data={"name": period_name, "status": STATUS_LOCKED})
