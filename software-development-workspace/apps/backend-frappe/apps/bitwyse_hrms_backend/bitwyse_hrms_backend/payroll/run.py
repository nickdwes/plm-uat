"""Payroll run (Payroll Entry) APIs for the HRMS external client.

A Payroll Run in Frappe HRMS corresponds to a ``Payroll Entry`` document.
This module provides:
  - List and detail reads (company-scoped, HR and above).
  - Create a new payroll run (draft state, triggers salary slip generation).
  - Submit a run (moves slips to Submitted state, locks edits).
  - Cancel a run (reverts slips; allowed only before posting to accounts).
  - Exception report: employees in this run with anomalies.

The statutory deduction computation (PAYE, NSSF, SHIF, AHL) is done by
Frappe HRMS via Salary Structure formulas. This API layer validates inputs
and orchestrates the Frappe document lifecycle — it does NOT recompute
deductions independently for the run (that would duplicate Frappe's engine).
The ``statutory`` module is used for validation and payslip annotation.

Leave-pay integration (Phase D):
  Unpaid leave deductions are applied via the ``leave_pay_impact`` hook
  imported from ``payroll.leave_integration``. In Phase C this is a stub.
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
# Field sets
# ---------------------------------------------------------------------------

_RUN_LIST_FIELDS = [
    "name",
    "company",
    "payroll_frequency",
    "start_date",
    "end_date",
    "status",
    "posting_date",
    "salary_slip_based_on_timesheet",
]

_RUN_DETAIL_FIELDS = _RUN_LIST_FIELDS + [
    "cost_center",
    "payroll_payable_account",
    "bank_account",
    "docstatus",
]


# ---------------------------------------------------------------------------
# Read endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_payroll_runs(
    company: str | None = None,
    status: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return paginated payroll run list scoped to the caller's company.

    Args:
        company: Optional company filter.
        status: Optional status filter (``Draft``, ``Submitted``, etc.).
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

    filters: dict[str, Any] = dict(scope)
    if status:
        filters["status"] = status

    items = frappe.get_list(
        "Payroll Entry",
        filters=filters or None,
        fields=_RUN_LIST_FIELDS,
        order_by="posting_date desc",
        limit_start=page_start,
        limit_page_length=page_size,
        ignore_permissions=True
    )
    total = frappe.db.count("Payroll Entry", filters=filters or None)

    return success_response(
        data={"items": items},
        meta=pagination_meta(page_start, page_size, total),
    )


@frappe.whitelist()
def get_payroll_run(run_name: str) -> dict[str, Any]:
    """Return full details of a single payroll run.

    Args:
        run_name: Frappe Payroll Entry name (primary key).

    Returns:
        dict[str, Any]: Envelope with run data and slip count.

    Raises:
        frappe.PermissionError: If caller cannot access the run's company.
        frappe.DoesNotExistError: If the run does not exist.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    run = frappe.get_value("Payroll Entry", run_name, _RUN_DETAIL_FIELDS, as_dict=True)
    if not run:
        frappe.throw(
            _("Payroll Run not found: {0}").format(run_name),
            frappe.DoesNotExistError,
        )
    require_company_access(ctx, run["company"])

    slip_count = frappe.db.count("Salary Slip", filters={"payroll_entry": run_name})
    exception_count = frappe.db.count(
        "Salary Slip",
        filters={"payroll_entry": run_name, "status": "Cancelled"},
    )
    run["slip_count"] = slip_count
    run["exception_count"] = exception_count

    return success_response(data=run)


@frappe.whitelist()
def list_run_exceptions(run_name: str) -> dict[str, Any]:
    """Return salary slips in this run that have anomalous or error status.

    A slip is flagged as an exception when its status is ``Cancelled`` or
    when its net pay is negative.

    Args:
        run_name: Payroll Entry name.

    Returns:
        dict[str, Any]: Envelope with ``data.items`` list of exception slips.

    Raises:
        frappe.PermissionError: If caller cannot access the run's company.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    run_company = frappe.db.get_value("Payroll Entry", run_name, "company")
    if not run_company:
        frappe.throw(_("Payroll Run not found: {0}").format(run_name), frappe.DoesNotExistError)
    require_company_access(ctx, run_company)

    cancelled_slips = frappe.get_all(
        "Salary Slip",
        filters={"payroll_entry": run_name, "status": "Cancelled"},
        fields=["name", "employee", "employee_name", "net_pay", "status"],
        ignore_permissions=True
    )
    negative_pay_slips = frappe.get_all(
        "Salary Slip",
        filters={"payroll_entry": run_name, "net_pay": ["<", 0]},
        fields=["name", "employee", "employee_name", "net_pay", "status"],
        ignore_permissions=True
    )

    # Deduplicate by slip name.
    seen: set[str] = set()
    exceptions: list[dict] = []
    for slip in cancelled_slips + negative_pay_slips:
        if slip["name"] not in seen:
            seen.add(slip["name"])
            slip["exception_reason"] = (
                "Cancelled" if slip["status"] == "Cancelled" else "Negative net pay"
            )
            exceptions.append(slip)

    return success_response(data={"items": exceptions, "run_name": run_name})


# ---------------------------------------------------------------------------
# Admin write endpoints
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def create_payroll_run(
    company: str,
    payroll_frequency: str,
    start_date: str,
    end_date: str,
    posting_date: str,
    cost_center: str | None = None,
    department: str | None = None,
    branch: str | None = None,
) -> dict[str, Any]:
    """Create a new payroll run (Payroll Entry in Draft state).

    Frappe HRMS generates salary slips from this entry when it is submitted.
    The draft is returned for HR review before submission.

    Args:
        company: Company for which payroll is being run.
        payroll_frequency: ``"Monthly"``, ``"Bimonthly"``, etc.
        start_date: ISO payroll period start date.
        end_date: ISO payroll period end date.
        posting_date: ISO date for accounting entries.
        cost_center: Optional cost centre for payroll accounting.
        department: Optional department filter for this run.
        branch: Optional branch filter for this run.

    Returns:
        dict[str, Any]: Envelope with new Payroll Entry ``name``.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If required fields are missing.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    require_company_access(ctx, company)

    for label, val in [
        ("Company", company),
        ("Payroll frequency", payroll_frequency),
        ("Start date", start_date),
        ("End date", end_date),
        ("Posting date", posting_date),
    ]:
        if not val or not str(val).strip():
            frappe.throw(_(f"{label} is required."), frappe.ValidationError)

    doc_data: dict[str, Any] = {
        "doctype": "Payroll Entry",
        "company": company,
        "payroll_frequency": payroll_frequency,
        "start_date": start_date,
        "end_date": end_date,
        "posting_date": posting_date,
    }
    if cost_center:
        doc_data["cost_center"] = cost_center
    if department:
        doc_data["department"] = department
    if branch:
        doc_data["branch"] = branch

    doc = frappe.get_doc(doc_data)
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    _write_audit_event(
        event="payroll_run.created",
        doctype="Payroll Entry",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "company": company,
            "start_date": start_date,
            "end_date": end_date,
            "payroll_frequency": payroll_frequency,
        },
    )

    return success_response(data={"name": doc.name, "status": "Draft"})


@frappe.whitelist(methods=["POST"])
def submit_payroll_run(run_name: str) -> dict[str, Any]:
    """Submit a payroll run, triggering salary slip finalisation.

    Args:
        run_name: Payroll Entry name to submit.

    Returns:
        dict[str, Any]: Envelope confirming submission.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If the run is not in Draft state.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    doc = frappe.get_doc("Payroll Entry", run_name)
    require_company_access(ctx, doc.company)

    if doc.docstatus != 0:
        frappe.throw(
            _("Only Draft payroll runs can be submitted."),
            frappe.ValidationError,
        )

    doc.submit()
    frappe.db.commit()

    _write_audit_event(
        event="payroll_run.submitted",
        doctype="Payroll Entry",
        docname=run_name,
        actor=frappe.session.user,
        payload={"company": doc.company},
    )

    return success_response(data={"name": run_name, "status": "Submitted"})


@frappe.whitelist(methods=["POST"])
def cancel_payroll_run(run_name: str, reason: str | None = None) -> dict[str, Any]:
    """Cancel a submitted payroll run before it is posted to accounts.

    Args:
        run_name: Payroll Entry name to cancel.
        reason: Optional reason for audit trail.

    Returns:
        dict[str, Any]: Envelope confirming cancellation.

    Raises:
        frappe.PermissionError: If caller lacks HR access or company scope.
        frappe.ValidationError: If the run is already posted.
    """
    require_hr_access()
    check_rate_limit()

    ctx = resolve_tenant_context()
    doc = frappe.get_doc("Payroll Entry", run_name)
    require_company_access(ctx, doc.company)

    if doc.docstatus == 2:
        frappe.throw(
            _("Payroll Run {0} is already cancelled.").format(run_name),
            frappe.ValidationError,
        )

    doc.cancel()
    frappe.db.commit()

    _write_audit_event(
        event="payroll_run.cancelled",
        doctype="Payroll Entry",
        docname=run_name,
        actor=frappe.session.user,
        payload={"company": doc.company, "reason": reason},
    )

    return success_response(data={"name": run_name, "status": "Cancelled"})
