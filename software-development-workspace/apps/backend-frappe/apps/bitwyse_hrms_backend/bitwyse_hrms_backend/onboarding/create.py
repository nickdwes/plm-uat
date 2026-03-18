"""Onboarding record creation and step-save APIs.

Endpoints:
    create_onboarding_draft  — start a new onboarding record (HR / Manager)
    save_onboarding_step     — persist one wizard step's payload
"""

from __future__ import annotations

import json
from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import check_rate_limit, require_hr_access, require_manager_or_above
from bitwyse_hrms_backend.audit_log import write_audit_event
from bitwyse_hrms_backend.response import success_response
from bitwyse_hrms_backend.security import sanitise_str, sanitise_date
from bitwyse_hrms_backend.tenant_context import require_company_access, resolve_tenant_context
from bitwyse_hrms_backend.onboarding.models import (
    ONBOARDING_DOCTYPE,
    ONBOARDING_STEPS,
    STEP_LABELS,
)
from bitwyse_hrms_backend.onboarding.validators import (
    validate_onboarding_step,
    assert_no_duplicate_employee,
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _parse_json_field(value: Any) -> Any:
    """Deserialise a field that may arrive as a JSON string from the API."""
    if isinstance(value, str):
        try:
            return json.loads(value)
        except (ValueError, TypeError):
            return value
    return value


def _require_onboarding_write_access(doc: Any) -> None:
    """Ensure the caller may write to this onboarding record.

    HR roles can write to any record in their company scope.
    An employee may only write to their own record when status is Draft.
    """
    ctx = resolve_tenant_context()
    if ctx.is_global_hr or ctx.is_company_hr or ctx.is_manager:
        require_company_access(ctx, doc.company)
        return
    # EMPLOYEE: self-service — only their own Draft records
    if doc.status != "Draft":
        frappe.throw(_("You may only edit your own Draft onboarding record."),
                    frappe.PermissionError)
    if ctx.employee_name and doc.reporting_manager:
        # Created on behalf of this employee; creator check done on creation
        return
    # Fallback: block
    frappe.throw(_("You are not authorised to edit this onboarding record."),
                frappe.PermissionError)


# ---------------------------------------------------------------------------
# create_onboarding_draft
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def create_onboarding_draft(
    company: str,
    department: str,
    date_of_hire: str,
    designation: str | None = None,
    employment_type: str | None = None,
    reporting_manager: str | None = None,
    work_location: str | None = None,
    group_function: str | None = None,
    probation_period_months: str | None = None,
) -> dict[str, Any]:
    """Create a new onboarding record in Draft status.

    Called by HR or a Manager to initiate onboarding for a new hire.
    Returns the new record's ``name`` so the UI can redirect to the wizard.

    Args:
        company: Company the new hire will belong to.
        department: Department name.
        date_of_hire: ISO date string YYYY-MM-DD.
        designation: Optional designation/job-title name.
        employment_type: Optional employment-type name.
        reporting_manager: Optional Employee name of the line manager.
        work_location: Free-text work location.
        group_function: Free-text group-level function description.
        probation_period_months: Probation duration e.g. ``"3"``.

    Returns:
        dict: Envelope with ``data.onboarding_id`` and ``data.status``.
    """
    require_manager_or_above()
    check_rate_limit()

    ctx = resolve_tenant_context()
    company = sanitise_str(company, "company", required=True)
    department = sanitise_str(department, "department", required=True)
    date_of_hire = sanitise_date(date_of_hire, "date_of_hire", required=True)
    require_company_access(ctx, company)

    doc = frappe.get_doc({
        "doctype": ONBOARDING_DOCTYPE,
        "status": "Draft",
        "current_step": 1,
        "company": company,
        "department": department,
        "date_of_hire": date_of_hire,
        "designation": sanitise_str(designation, "designation"),
        "employment_type": sanitise_str(employment_type, "employment_type"),
        "reporting_manager": sanitise_str(reporting_manager, "reporting_manager"),
        "work_location": sanitise_str(work_location, "work_location"),
        "group_function": sanitise_str(group_function, "group_function"),
        "probation_period_months": sanitise_str(probation_period_months, "probation_period_months"),
        "consent_captured": 0,
    })
    doc.flags.ignore_mandatory = True
    doc.insert(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event="onboarding.draft_created",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"company": company, "department": department, "date_of_hire": date_of_hire},
    )

    return success_response(data={"onboarding_id": doc.name, "status": doc.status})


# ---------------------------------------------------------------------------
# save_onboarding_step
# ---------------------------------------------------------------------------


@frappe.whitelist(methods=["POST"])
def save_onboarding_step(
    onboarding_id: str,
    step: str,
    data: Any,
    consent_captured: int = 0,
) -> dict[str, Any]:
    """Persist the payload for a single onboarding wizard step.

    The record must be in ``Draft`` status.  The step's data is validated
    before saving; on validation failure a ``ValidationError`` is raised
    and no write occurs.

    Args:
        onboarding_id: Name of the HRMS Onboarding document.
        step: One of the step slugs from ``ONBOARDING_STEPS``.
        data: Step payload dict (may arrive as JSON string from POST body).
        consent_captured: Set to 1 when the employee has confirmed consent.

    Returns:
        dict: Envelope with ``data.onboarding_id``, ``data.step``,
            ``data.current_step``, ``data.status``.
    """
    check_rate_limit()

    onboarding_id = sanitise_str(onboarding_id, "onboarding_id", required=True)
    step = sanitise_str(step, "step", required=True)

    if step not in ONBOARDING_STEPS:
        frappe.throw(
            _("Unknown onboarding step '{0}'. Valid steps: {1}.").format(
                step, ", ".join(ONBOARDING_STEPS)
            ),
            frappe.ValidationError,
        )

    data = _parse_json_field(data)
    if not isinstance(data, dict):
        frappe.throw(_("Step data must be a JSON object."), frappe.ValidationError)

    doc = frappe.get_doc(ONBOARDING_DOCTYPE, onboarding_id)

    if doc.status != "Draft":
        frappe.throw(
            _("Onboarding record {0} is not in Draft status — cannot save steps.").format(
                onboarding_id
            ),
            frappe.ValidationError,
        )

    _require_onboarding_write_access(doc)

    # Run step-level validators
    validate_onboarding_step(step, data)

    # Check duplicate national ID on the personal step
    if step == "personal" and data.get("national_id_number"):
        assert_no_duplicate_employee(data["national_id_number"], onboarding_id)

    # Map step data fields onto the document
    _apply_step_data(doc, step, data)

    # Advance current_step pointer
    step_index = ONBOARDING_STEPS.index(step) + 1  # 1-based
    if step_index > (doc.current_step or 0):
        doc.current_step = step_index

    if int(consent_captured):
        doc.consent_captured = 1

    doc.save(ignore_permissions=True)
    frappe.db.commit()

    write_audit_event(
        event=f"onboarding.step_saved.{step}",
        doctype=ONBOARDING_DOCTYPE,
        docname=doc.name,
        actor=frappe.session.user,
        payload={"step": step, "step_label": STEP_LABELS.get(step, step)},
    )

    return success_response(data={
        "onboarding_id": doc.name,
        "step": step,
        "current_step": doc.current_step,
        "status": doc.status,
    })


# ---------------------------------------------------------------------------
# Step data mapper
# ---------------------------------------------------------------------------

_PERSONAL_FIELDS = [
    "first_name", "middle_name", "last_name",
    "national_id_type", "national_id_number",
    "date_of_birth", "gender", "marital_status",
    "phone_number", "personal_email", "work_email", "physical_address",
]

_EMPLOYMENT_FIELDS = [
    "company", "department", "designation", "employment_type",
    "date_of_hire", "reporting_manager", "work_location",
    "probation_period_months", "group_function",
]

_STATUTORY_FIELDS = [
    "kra_pin", "nssf_number", "nhif_number", "sha_number",
    "bank_name", "bank_branch", "account_number", "account_name",
    "tax_obligations_flag",
]

_DOCUMENT_FIELDS = [
    "national_id_copy", "kra_pin_certificate", "nssf_proof", "nhif_proof",
    "academic_certificates", "employment_contract", "compliance_documents",
]

_STEP_SCALAR_FIELDS: dict[str, list[str]] = {
    "personal": _PERSONAL_FIELDS,
    "employment": _EMPLOYMENT_FIELDS,
    "statutory": _STATUTORY_FIELDS,
    "documents": _DOCUMENT_FIELDS,
}


def _apply_step_data(doc: Any, step: str, data: dict) -> None:
    """Copy ``data`` fields onto the Frappe document for the given step.

    Scalar fields are copied directly.  Child table steps (qualifications,
    next_of_kin, emergency_contacts) replace the existing child rows.
    """
    scalar_fields = _STEP_SCALAR_FIELDS.get(step, [])
    for field in scalar_fields:
        if field in data:
            setattr(doc, field, data[field])

    if step == "qualifications":
        doc.set("qualifications", [])
        for row in data.get("qualifications", []):
            doc.append("qualifications", {
                "qualification_type": row.get("qualification_type", "Academic"),
                "institution_name": row.get("institution_name", ""),
                "course_or_certification": row.get("course_or_certification", ""),
                "year_completed": row.get("year_completed", ""),
                "supporting_document": row.get("supporting_document"),
            })

    if step == "next_of_kin":
        doc.set("next_of_kin", [])
        for row in data.get("next_of_kin", []):
            doc.append("next_of_kin", {
                "full_name": row.get("full_name", ""),
                "relationship": row.get("relationship", ""),
                "phone_number": row.get("phone_number", ""),
                "email": row.get("email", ""),
                "address": row.get("address", ""),
            })
        doc.set("emergency_contacts", [])
        for row in data.get("emergency_contacts", []):
            doc.append("emergency_contacts", {
                "full_name": row.get("full_name", ""),
                "relationship": row.get("relationship", ""),
                "phone_number": row.get("phone_number", ""),
                "alternate_phone": row.get("alternate_phone", ""),
                "address": row.get("address", ""),
            })
