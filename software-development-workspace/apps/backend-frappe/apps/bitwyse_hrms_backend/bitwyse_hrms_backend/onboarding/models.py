"""DocType constants, schemas, and install helpers for the Onboarding module.

DocTypes created here:
  - HRMS Onboarding              (main onboarding record)
  - HRMS Onboarding Qualification (child: academic & professional certs)
  - HRMS Onboarding Next Of Kin  (child: next-of-kin entries)
  - HRMS Onboarding Emerg Contact (child: emergency contacts)
  - HRMS Secondment               (secondment assignment record)

``ensure_onboarding_doctypes()`` is called from ``setup.install`` on every
install / migrate and is fully idempotent.
"""

from __future__ import annotations

import frappe

# ---------------------------------------------------------------------------
# DocType name constants
# ---------------------------------------------------------------------------

ONBOARDING_DOCTYPE = "HRMS Onboarding"
QUALIFICATION_DOCTYPE = "HRMS Onboarding Qualification"
NOK_DOCTYPE = "HRMS Onboarding Next Of Kin"
EMERGENCY_DOCTYPE = "HRMS Onboarding Emerg Contact"
SECONDMENT_DOCTYPE = "HRMS Secondment"

# Workflow status values
ONBOARDING_STATUSES = ["Draft", "Submitted", "Approved", "Rejected", "Onboarded"]

# Named wizard steps (used as step slugs in API + UI)
ONBOARDING_STEPS = [
    "personal",
    "employment",
    "statutory",
    "qualifications",
    "next_of_kin",
    "documents",
    "review",
]

STEP_LABELS = {
    "personal": "Personal Details",
    "employment": "Employment Details",
    "statutory": "Statutory & Compliance",
    "qualifications": "Qualifications",
    "next_of_kin": "Next of Kin",
    "documents": "Documents",
    "review": "Review & Submit",
}

# ---------------------------------------------------------------------------
# DocType field specifications
# ---------------------------------------------------------------------------

def _text_field(fieldname: str, label: str, **kw) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Data",
            "in_list_view": kw.get("in_list_view", 0), "reqd": kw.get("reqd", 0),
            "read_only": kw.get("read_only", 0), "in_global_search": kw.get("in_global_search", 0)}


def _select_field(fieldname: str, label: str, options: list[str], **kw) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Select",
            "options": "\n".join(options), "reqd": kw.get("reqd", 0),
            "in_list_view": kw.get("in_list_view", 0)}


def _link_field(fieldname: str, label: str, options: str, **kw) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Link",
            "options": options, "reqd": kw.get("reqd", 0),
            "in_list_view": kw.get("in_list_view", 0)}


def _date_field(fieldname: str, label: str, **kw) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Date",
            "reqd": kw.get("reqd", 0)}


def _check_field(fieldname: str, label: str) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Check"}


def _int_field(fieldname: str, label: str, **kw) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Int",
            "default": kw.get("default", 0), "reqd": kw.get("reqd", 0)}


def _long_text_field(fieldname: str, label: str, **kw) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Long Text",
            "reqd": kw.get("reqd", 0)}


def _section(label: str) -> dict:
    import re
    slug = re.sub(r'[^a-z0-9]+', '_', label.lower()).strip('_')
    return {"fieldname": slug + "_section",
            "label": label, "fieldtype": "Section Break"}


def _child_table_field(fieldname: str, label: str, options: str) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Table",
            "options": options}


def _attach_field(fieldname: str, label: str) -> dict:
    return {"fieldname": fieldname, "label": label, "fieldtype": "Attach"}


# ---------------------------------------------------------------------------
# DocType specs
# ---------------------------------------------------------------------------

_MAIN_FIELDS: list[dict] = [
    _section("Status and Workflow"),
    _select_field("status", "Status", ONBOARDING_STATUSES, reqd=1, in_list_view=1),
    _int_field("current_step", "Current Step (1–7)", default=1),
    _check_field("consent_captured", "Personal Data Consent Captured"),
    _link_field("employee", "Employee (created on approval)", "Employee"),

    _section("Organisation"),
    _link_field("company", "Company", "Company", reqd=1, in_list_view=1),
    _link_field("department", "Department", "Department", reqd=1),
    _link_field("designation", "Designation", "Designation"),
    _link_field("employment_type", "Employment Type", "Employment Type"),
    _date_field("date_of_hire", "Date of Hire", reqd=1),
    _link_field("reporting_manager", "Reporting Manager", "Employee"),
    _text_field("work_location", "Work Location"),
    _text_field("probation_period_months", "Probation Period (Months)"),
    _text_field("group_function", "Group-Level Function (if applicable)"),

    _section("Personal Details"),
    _text_field("first_name", "First Name", reqd=1, in_global_search=1),
    _text_field("middle_name", "Middle Name"),
    _text_field("last_name", "Last Name", reqd=1, in_global_search=1),
    _select_field("national_id_type", "ID Type", ["National ID", "Passport"], reqd=1),
    _text_field("national_id_number", "National ID / Passport Number", reqd=1),
    _date_field("date_of_birth", "Date of Birth", reqd=1),
    _select_field("gender", "Gender", ["Male", "Female", "Other"]),
    _select_field("marital_status", "Marital Status", ["Single", "Married", "Divorced", "Widowed"]),
    _text_field("phone_number", "Phone Number", reqd=1),
    _text_field("personal_email", "Personal Email", reqd=1),
    _text_field("work_email", "Work Email"),
    _long_text_field("physical_address", "Physical Address"),

    _section("Statutory and Compliance Kenya"),
    _text_field("kra_pin", "KRA PIN"),
    _text_field("nssf_number", "NSSF Number"),
    _text_field("nhif_number", "NHIF Number"),
    _text_field("sha_number", "SHA Number"),
    _text_field("bank_name", "Bank Name"),
    _text_field("bank_branch", "Bank Branch"),
    _text_field("account_number", "Account Number"),
    _text_field("account_name", "Account Name"),
    _check_field("tax_obligations_flag", "Has Additional Tax Obligations"),

    _section("Qualifications"),
    _child_table_field("qualifications", "Qualifications", QUALIFICATION_DOCTYPE),

    _section("Next of Kin"),
    _child_table_field("next_of_kin", "Next of Kin", NOK_DOCTYPE),

    _section("Emergency Contacts"),
    _child_table_field("emergency_contacts", "Emergency Contacts", EMERGENCY_DOCTYPE),

    _section("Documents"),
    _attach_field("national_id_copy", "National ID / Passport Copy"),
    _attach_field("kra_pin_certificate", "KRA PIN Certificate"),
    _attach_field("nssf_proof", "NSSF Proof"),
    _attach_field("nhif_proof", "NHIF Proof"),
    _attach_field("academic_certificates", "Academic Certificates (zipped)"),
    _attach_field("employment_contract", "Signed Employment Contract"),
    _attach_field("compliance_documents", "Other Compliance Documents"),

    _section("Approval"),
    _text_field("approved_by", "Approved By"),
    _date_field("approval_date", "Approval Date"),
    _long_text_field("approval_comments", "Approval Comments"),
    _text_field("rejected_by", "Rejected By"),
    _date_field("rejection_date", "Rejection Date"),
    _long_text_field("rejection_reason", "Rejection Reason"),
    _text_field("onboarded_by", "Marked Onboarded By"),
    _date_field("onboarded_date", "Onboarded Date"),
]

_QUALIFICATION_FIELDS: list[dict] = [
    _select_field("qualification_type", "Type", ["Academic", "Professional"], reqd=1, in_list_view=1),
    _text_field("institution_name", "Institution / Body", reqd=1, in_list_view=1),
    _text_field("course_or_certification", "Course / Certification", reqd=1, in_list_view=1),
    _text_field("year_completed", "Year Completed"),
    _attach_field("supporting_document", "Supporting Document"),
]

_NOK_FIELDS: list[dict] = [
    _text_field("full_name", "Full Name", reqd=1, in_list_view=1),
    _text_field("relationship", "Relationship", reqd=1, in_list_view=1),
    _text_field("phone_number", "Phone Number", reqd=1),
    _text_field("email", "Email"),
    _long_text_field("address", "Address"),
]

_EMERGENCY_FIELDS: list[dict] = [
    _text_field("full_name", "Full Name", reqd=1, in_list_view=1),
    _text_field("relationship", "Relationship", reqd=1, in_list_view=1),
    _text_field("phone_number", "Phone Number", reqd=1),
    _text_field("alternate_phone", "Alternate Phone"),
    _long_text_field("address", "Address"),
]

_SECONDMENT_FIELDS: list[dict] = [
    _link_field("employee", "Employee", "Employee", reqd=1, in_list_view=1),
    _link_field("home_company", "Home Company", "Company", reqd=1),
    _link_field("home_department", "Home Department", "Department"),
    _link_field("host_company", "Host Company", "Company", reqd=1, in_list_view=1),
    _link_field("host_department", "Host Department", "Department"),
    _link_field("reporting_manager", "Reporting Manager During Secondment", "Employee"),
    _date_field("start_date", "Start Date", reqd=1),
    _date_field("end_date", "End Date", reqd=1),
    _select_field("status", "Status", ["Active", "Completed", "Cancelled"], reqd=1, in_list_view=1),
    _long_text_field("notes", "Notes"),
]

_DOCTYPE_SPECS: list[tuple[str, list[dict], str | None]] = [
    (QUALIFICATION_DOCTYPE, _QUALIFICATION_FIELDS, ONBOARDING_DOCTYPE),
    (NOK_DOCTYPE, _NOK_FIELDS, ONBOARDING_DOCTYPE),
    (EMERGENCY_DOCTYPE, _EMERGENCY_FIELDS, ONBOARDING_DOCTYPE),
    (ONBOARDING_DOCTYPE, _MAIN_FIELDS, None),
    (SECONDMENT_DOCTYPE, _SECONDMENT_FIELDS, None),
]


# ---------------------------------------------------------------------------
# Idempotent installer
# ---------------------------------------------------------------------------


def ensure_onboarding_doctypes() -> dict:
    """Create all onboarding DocTypes if they do not yet exist.

    Returns:
        dict with ``created`` and ``skipped`` lists of DocType names.
    """
    created: list[str] = []
    skipped: list[str] = []

    for doctype_name, fields, parent_doctype in _DOCTYPE_SPECS:
        if frappe.db.exists("DocType", doctype_name):
            skipped.append(doctype_name)
            continue

        is_child = parent_doctype is not None
        try:
            doc = frappe.get_doc({
                "doctype": "DocType",
                "name": doctype_name,
                "module": "Bitwyse HRMS Backend",
                "custom": 1,
                "is_submittable": 0,
                "is_child_table": 1 if is_child else 0,
                "istable": 1 if is_child else 0,
                "track_changes": 1,
                "fields": fields,
                "permissions": [] if is_child else [
                    {"role": "HRMS Global HR", "read": 1, "write": 1, "create": 1, "delete": 0},
                    {"role": "HRMS Company HR", "read": 1, "write": 1, "create": 1, "delete": 0},
                    {"role": "HRMS Manager", "read": 1, "write": 0, "create": 0, "delete": 0},
                    {"role": "HRMS Employee", "read": 1, "write": 1, "create": 1, "delete": 0},
                ],
            })
            doc.insert(ignore_permissions=True)
            frappe.db.commit()
            created.append(doctype_name)
        except Exception as exc:
            frappe.logger().warning(
                f"[onboarding] Could not create DocType {doctype_name}: {exc}"
            )
            skipped.append(doctype_name)

    return {"created": created, "skipped": skipped}
