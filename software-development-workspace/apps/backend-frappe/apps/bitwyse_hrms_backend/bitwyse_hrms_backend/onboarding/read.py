"""Read endpoints for onboarding records and dashboard aggregates.

Endpoints:
    list_onboarding_records   — paginated list with role-scoped visibility
    get_onboarding_record     — full detail including child tables
    get_onboarding_dashboard  — aggregate counts for the HR dashboard
"""

from __future__ import annotations

from typing import Any

import frappe
from frappe import _

from bitwyse_hrms_backend.auth import check_rate_limit, require_authenticated_user
from bitwyse_hrms_backend.response import coerce_pagination, pagination_meta, success_response
from bitwyse_hrms_backend.security import sanitise_str, sanitise_int, sanitise_search
from bitwyse_hrms_backend.tenant_context import (
    company_scope_filters,
    require_company_access,
    resolve_tenant_context,
)
from bitwyse_hrms_backend.onboarding.models import (
    ONBOARDING_DOCTYPE,
    QUALIFICATION_DOCTYPE,
    NOK_DOCTYPE,
    EMERGENCY_DOCTYPE,
    ONBOARDING_STATUSES,
    STEP_LABELS,
)


# ---------------------------------------------------------------------------
# Field sets for list vs. detail responses
# ---------------------------------------------------------------------------

_LIST_FIELDS = [
    "name",
    "status",
    "company",
    "department",
    "designation",
    "first_name",
    "last_name",
    "employment_type",
    "date_of_hire",
    "current_step",
    "approved_by",
    "approval_date",
    "creation",
    "modified",
]

_DETAIL_SCALAR_FIELDS = _LIST_FIELDS + [
    "middle_name",
    "national_id_type",
    "national_id_number",
    "date_of_birth",
    "gender",
    "marital_status",
    "phone_number",
    "personal_email",
    "work_email",
    "physical_address",
    "reporting_manager",
    "work_location",
    "probation_period_months",
    "group_function",
    "kra_pin",
    "nssf_number",
    "nhif_number",
    "sha_number",
    "bank_name",
    "bank_branch",
    "account_number",
    "account_name",
    "tax_obligations_flag",
    "national_id_copy",
    "kra_pin_certificate",
    "nssf_proof",
    "nhif_proof",
    "academic_certificates",
    "employment_contract",
    "compliance_documents",
    "approval_comments",
    "rejection_reason",
    "rejected_by",
    "rejection_date",
    "onboarded_by",
    "onboarded_date",
    "consent_captured",
    "employee",
]

# Sensitive statutory / financial fields only returned to HR roles
_SENSITIVE_FIELDS = {
    "national_id_number", "kra_pin", "nssf_number", "nhif_number",
    "sha_number", "account_number", "tax_obligations_flag",
    "national_id_copy", "kra_pin_certificate",
}


# ---------------------------------------------------------------------------
# Visibility helper
# ---------------------------------------------------------------------------


def _apply_visibility(ctx, filters: dict) -> dict:
    """Restrict list results based on the caller's role."""
    if ctx.is_manager and ctx.department:
        filters["department"] = ctx.department
    elif ctx.is_employee_only and ctx.employee_name:
        # Employees only see their own onboarding record
        filters["reporting_manager"] = ctx.employee_name
    return filters


def _mask_sensitive(record: dict, ctx) -> dict:
    """Remove sensitive fields for non-HR callers."""
    if ctx.is_company_hr or ctx.is_global_hr:
        return record
    for field in _SENSITIVE_FIELDS:
        if field in record:
            record[field] = "***"
    return record


# ---------------------------------------------------------------------------
# list_onboarding_records
# ---------------------------------------------------------------------------


@frappe.whitelist()
def list_onboarding_records(
    company: str | None = None,
    status: str | None = None,
    department: str | None = None,
    search: str | None = None,
    limit_start: int = 0,
    limit_page_length: int = 20,
) -> dict[str, Any]:
    """Return a paginated, role-scoped list of onboarding records.

    Args:
        company: Filter by company (validated against tenant context).
        status: Filter by workflow status (Draft/Submitted/Approved/…).
        department: Filter by department name.
        search: Free-text search over first_name + last_name.
        limit_start: Pagination offset.
        limit_page_length: Page size (max 100).

    Returns:
        dict: Envelope with ``data.items`` list and pagination ``meta``.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    limit_start, limit_page_length = coerce_pagination(limit_start, limit_page_length)
    filters = company_scope_filters(ctx, sanitise_str(company, "company"))
    filters = _apply_visibility(ctx, filters)

    if status:
        status = sanitise_str(status, "status")
        if status not in ONBOARDING_STATUSES:
            frappe.throw(_("Invalid status filter '{0}'.").format(status), frappe.ValidationError)
        filters["status"] = status

    if department:
        filters["department"] = sanitise_str(department, "department")

    or_filters = None
    if search:
        q = sanitise_search(search)
        or_filters = [
            ["HRMS Onboarding", "first_name", "like", f"%{q}%"],
            ["HRMS Onboarding", "last_name", "like", f"%{q}%"],
        ]

    items = frappe.get_list(
        ONBOARDING_DOCTYPE,
        filters=filters,
        or_filters=or_filters,
        fields=_LIST_FIELDS,
        limit_start=limit_start,
        limit_page_length=limit_page_length,
        order_by="creation desc",
        ignore_permissions=True,
    )

    total = frappe.db.count(ONBOARDING_DOCTYPE, filters=filters)

    # Mask sensitive fields for non-HR callers
    items = [_mask_sensitive(dict(r), ctx) for r in items]

    # Enrich with full name
    for item in items:
        item["full_name"] = " ".join(
            p for p in [item.get("first_name"), item.get("last_name")] if p
        )

    return success_response(
        data={"items": items},
        meta=pagination_meta(limit_start, limit_page_length, total),
    )


# ---------------------------------------------------------------------------
# get_onboarding_record
# ---------------------------------------------------------------------------


@frappe.whitelist()
def get_onboarding_record(onboarding_id: str) -> dict[str, Any]:
    """Return full detail for a single onboarding record.

    Includes child tables: qualifications, next_of_kin, emergency_contacts.
    Sensitive statutory fields are masked for Manager and Employee callers.

    Args:
        onboarding_id: HRMS Onboarding document name.

    Returns:
        dict: Envelope with the full onboarding record.
    """
    require_authenticated_user()
    check_rate_limit()

    onboarding_id = sanitise_str(onboarding_id, "onboarding_id", required=True)
    ctx = resolve_tenant_context()

    try:
        doc = frappe.get_doc(ONBOARDING_DOCTYPE, onboarding_id)
    except frappe.DoesNotExistError:
        frappe.throw(_("Onboarding record {0} not found.").format(onboarding_id),
                    frappe.DoesNotExistError)

    require_company_access(ctx, doc.company)

    record: dict = {f: getattr(doc, f, None) for f in _DETAIL_SCALAR_FIELDS}
    record["full_name"] = " ".join(
        p for p in [doc.first_name, doc.middle_name, doc.last_name] if p
    )

    # Wizard progress meta
    record["step_labels"] = STEP_LABELS
    record["total_steps"] = len(STEP_LABELS)

    # Child tables
    record["qualifications"] = [
        {
            "qualification_type": r.qualification_type,
            "institution_name": r.institution_name,
            "course_or_certification": r.course_or_certification,
            "year_completed": r.year_completed,
            "supporting_document": r.supporting_document,
        }
        for r in (doc.qualifications or [])
    ]
    record["next_of_kin"] = [
        {
            "full_name": r.full_name,
            "relationship": r.relationship,
            "phone_number": r.phone_number,
            "email": r.email,
            "address": r.address,
        }
        for r in (doc.next_of_kin or [])
    ]
    record["emergency_contacts"] = [
        {
            "full_name": r.full_name,
            "relationship": r.relationship,
            "phone_number": r.phone_number,
            "alternate_phone": r.alternate_phone,
            "address": r.address,
        }
        for r in (doc.emergency_contacts or [])
    ]

    record = _mask_sensitive(record, ctx)

    return success_response(data=record)


# ---------------------------------------------------------------------------
# get_onboarding_dashboard
# ---------------------------------------------------------------------------


@frappe.whitelist()
def get_onboarding_dashboard(company: str | None = None) -> dict[str, Any]:
    """Return aggregate onboarding counts for the HR dashboard widget.

    Args:
        company: Optional company filter.

    Returns:
        dict: Envelope with count breakdowns by status and recent pending list.
    """
    require_authenticated_user()
    check_rate_limit()

    ctx = resolve_tenant_context()
    filters = company_scope_filters(ctx, sanitise_str(company, "company"))

    counts_by_status: dict[str, int] = {}
    for s in ONBOARDING_STATUSES:
        f = dict(filters)
        f["status"] = s
        counts_by_status[s] = frappe.db.count(ONBOARDING_DOCTYPE, filters=f)

    pending = frappe.get_list(
        ONBOARDING_DOCTYPE,
        filters={**filters, "status": "Submitted"},
        fields=["name", "first_name", "last_name", "company", "department",
                "date_of_hire", "creation"],
        limit_page_length=10,
        order_by="creation asc",
        ignore_permissions=True,
    )
    for p in pending:
        p["full_name"] = " ".join(x for x in [p.get("first_name"), p.get("last_name")] if x)

    return success_response(data={
        "by_status": counts_by_status,
        "total": sum(counts_by_status.values()),
        "pending_approval": pending,
    })
