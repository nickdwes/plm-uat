"""Onboarding workflow state-machine transitions.

Endpoints:
    submit_onboarding   — Draft → Submitted      (Employee or HR)
    approve_onboarding  — Submitted → Approved   (Company HR / Global HR)
    reject_onboarding   — Submitted → Rejected   (Company HR / Global HR)
    mark_onboarded      — Approved → Onboarded   (HR; triggers Employee creation)

State machine:
    Draft ──submit──► Submitted ──approve──► Approved ──mark_onboarded──► Onboarded
                         │
                         └──reject──► Rejected (terminal — HR can reopen)
    reopen_onboarding  — Rejected → Draft (HR only)
"""

from __future__ import annotations

from datetime import date
from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import check_rate_limit, require_hr_access
from bitwyse_hrms_backend.audit_log import write_audit_event
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.security import sanitise_str
from bitwyse_hrms_backend.tenant_context import require_company_access, resolve_tenant_context
from bitwyse_hrms_backend.onboarding.models import ONBOARDING_DOCTYPE, ONBOARDING_STEPS
from bitwyse_hrms_backend.onboarding.validators import assert_no_duplicate_employee


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _get_onboarding(onboarding_id: str) -> Any:
    check_rate_limit()
    onboarding_id = sanitise_str(onboarding_id, "onboarding_id", required=True)
    try:
        return frappe.get_doc(ONBOARDING_DOCTYPE, onboarding_id)
    except frappe.DoesNotExistError:
        frappe.throw(_("Onboarding record {0} not found.").format(onboarding_id),
                    frappe.DoesNotExistError)


def _assert_all_steps_complete(doc: Any) -> None:
    """Raise if any required wizard steps are missing data."""
    required_personal = ["first_name", "last_name", "national_id_number",
                         "date_of_birth", "phone_number", "personal_email"]
    missing = [f for f in required_personal if not getattr(doc, f, None)]
    if missing:
        frappe.throw(
            _("Cannot submit: required personal fields are missing — {0}.").format(
                ", ".join(missing)
            ),
            frappe.ValidationError,
        )
    if not doc.consent_captured:
        frappe.throw(
            _("Cannot submit: personal data consent has not been captured."),
            frappe.ValidationError,
        )


# ---------------------------------------------------------------------------
# submit_onboarding
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def submit_onboarding(onboarding_id: str) -> dict[str, Any]:
    """Move an onboarding record from Draft to Submitted.

    Can be called by the employee self-service user or by HR.
    Validates that all required personal steps have been completed and
    that consent has been captured before allowing submission.

    Args:
        onboarding_id: Name of the HRMS Onboarding document.

    Returns:
        dict: Envelope with ``data.onboarding_id`` and ``data.status``.
    """
    doc = _get_onboarding(onboarding_id)
    if doc.status != "Draft":
        frappe.throw(
            _("Only Draft records can be submitted. Current status: {0}.").format(doc.status),
            frappe.ValidationError,
        )

    _assert_all_steps_complete(doc)
    assert_no_duplicate_employee(doc.national_id_number, onboarding_id)

    doc.status = "Submitted"
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="onboarding.submitted",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"company": doc.company, "department": doc.department},
    )

    return success_response(data={"onboarding_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# approve_onboarding
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def approve_onboarding(
    onboarding_id: str,
    comments: str | None = None,
) -> dict[str, Any]:
    """Approve a Submitted onboarding record (Company HR / Global HR only).

    Args:
        onboarding_id: Name of the onboarding record.
        comments: Optional approval comments.

    Returns:
        dict: Envelope with ``data.onboarding_id``, ``data.status``.
    """
    require_hr_access()
    doc = _get_onboarding(onboarding_id)
    ctx = resolve_tenant_context()
    require_company_access(ctx, doc.company)

    if doc.status != "Submitted":
        frappe.throw(
            _("Only Submitted records can be approved. Current status: {0}.").format(doc.status),
            frappe.ValidationError,
        )

    doc.status = "Approved"
    doc.approved_by = frappe.session.user
    doc.approval_date = str(date.today())
    if comments:
        doc.approval_comments = sanitise_str(comments, "comments", max_len=1000)
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="onboarding.approved",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"comments": doc.approval_comments},
    )

    return success_response(data={"onboarding_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# reject_onboarding
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def reject_onboarding(
    onboarding_id: str,
    reason: str,
) -> dict[str, Any]:
    """Reject a Submitted onboarding record (Company HR / Global HR only).

    Args:
        onboarding_id: Name of the onboarding record.
        reason: Mandatory rejection reason for the audit trail and
            notification to the submitter.

    Returns:
        dict: Envelope with ``data.onboarding_id``, ``data.status``.
    """
    require_hr_access()
    doc = _get_onboarding(onboarding_id)
    ctx = resolve_tenant_context()
    require_company_access(ctx, doc.company)

    if doc.status != "Submitted":
        frappe.throw(
            _("Only Submitted records can be rejected. Current status: {0}.").format(doc.status),
            frappe.ValidationError,
        )

    reason = sanitise_str(reason, "reason", required=True, max_len=1000)

    doc.status = "Rejected"
    doc.rejected_by = frappe.session.user
    doc.rejection_date = str(date.today())
    doc.rejection_reason = reason
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="onboarding.rejected",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"reason": reason},
    )

    return success_response(data={"onboarding_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# reopen_onboarding
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def reopen_onboarding(onboarding_id: str) -> dict[str, Any]:
    """Reopen a Rejected record back to Draft (HR only).

    Allows the onboarding data to be corrected and re-submitted.

    Args:
        onboarding_id: Name of the onboarding record.

    Returns:
        dict: Envelope with ``data.onboarding_id``, ``data.status``.
    """
    require_hr_access()
    doc = _get_onboarding(onboarding_id)
    ctx = resolve_tenant_context()
    require_company_access(ctx, doc.company)

    if doc.status != "Rejected":
        frappe.throw(
            _("Only Rejected records can be reopened. Current status: {0}.").format(doc.status),
            frappe.ValidationError,
        )

    doc.status = "Draft"
    doc.rejection_reason = None
    doc.rejected_by = None
    doc.rejection_date = None
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="onboarding.reopened",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={},
    )

    return success_response(data={"onboarding_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# mark_onboarded — creates the Employee record
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def mark_onboarded(onboarding_id: str) -> dict[str, Any]:
    """Mark an Approved onboarding record as Onboarded and create the Employee.

    This is the final workflow step.  It:
      1. Creates a Frappe/HRMS Employee document from the onboarding data.
      2. Updates the onboarding record's ``employee`` link field.
      3. Transitions status to ``Onboarded``.

    Args:
        onboarding_id: Name of the Approved onboarding record.

    Returns:
        dict: Envelope with ``data.onboarding_id``, ``data.status``,
            ``data.employee_id``.
    """
    require_hr_access()
    doc = _get_onboarding(onboarding_id)
    ctx = resolve_tenant_context()
    require_company_access(ctx, doc.company)

    if doc.status != "Approved":
        frappe.throw(
            _("Only Approved records can be marked as Onboarded. Current status: {0}.").format(
                doc.status
            ),
            frappe.ValidationError,
        )

    employee_id = _create_employee_from_onboarding(doc)

    doc.status = "Onboarded"
    doc.employee = employee_id
    doc.onboarded_by = frappe.session.user
    doc.onboarded_date = str(date.today())
    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="onboarding.onboarded",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"employee_id": employee_id},
    )

    return success_response(data={
        "onboarding_id": doc.name,
        "status": doc.status,
        "employee_id": employee_id,
    })


def _create_employee_from_onboarding(doc: Any) -> str:
    """Create a Frappe Employee document from the onboarding record.

    Returns:
        str: The newly created Employee ``name`` (ID).
    """
    full_name_parts = [p for p in [doc.first_name, doc.middle_name, doc.last_name] if p]
    full_name = " ".join(full_name_parts)

    employee = frappe.get_doc({
        "doctype": "Employee",
        "first_name": doc.first_name or "",
        "middle_name": doc.middle_name or "",
        "last_name": doc.last_name or "",
        "employee_name": full_name,
        "company": doc.company,
        "department": doc.department,
        "designation": doc.designation,
        "employment_type": doc.employment_type,
        "date_of_joining": doc.date_of_hire,
        "reports_to": doc.reporting_manager,
        "branch": doc.work_location,
        "gender": doc.gender,
        "date_of_birth": doc.date_of_birth,
        "marital_status": doc.marital_status,
        "personal_email": doc.personal_email,
        "company_email": doc.work_email,
        "cell_number": doc.phone_number,
        "status": "Active",
    })
    employee.insert(ignore_permissions=True)
    frappe.db.commit()
    return employee.name
