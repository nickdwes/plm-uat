"""Input validators for Kenya-specific statutory fields and general HRMS data.

All validators raise ``frappe.ValidationError`` on failure and return
the cleaned/normalised value on success.  They are pure functions with no
side effects — safe to call before any database write.
"""

from __future__ import annotations

import re

import frappe
from frappe import _

# ---------------------------------------------------------------------------
# Regex patterns
# ---------------------------------------------------------------------------

# Kenya KRA PIN: letter + 9 digits + letter  e.g. A000000000Z or P051700001Z
_KRA_PIN_RE = re.compile(r"^[A-Z]\d{9}[A-Z]$")

# Kenya National ID: 7 or 8 digits
_NATIONAL_ID_RE = re.compile(r"^\d{7,8}$")

# Kenya Passport: varies — up to 9 alphanumeric characters
_PASSPORT_RE = re.compile(r"^[A-Z0-9]{6,9}$")

# NSSF: 6–10 digits
_NSSF_RE = re.compile(r"^\d{6,10}$")

# NHIF / SHA: 6–10 digits
_NHIF_RE = re.compile(r"^\d{6,10}$")

# Kenya phone: +254XXXXXXXXX or 07XXXXXXXX or 01XXXXXXXX
_PHONE_KE_RE = re.compile(r"^(\+254|0)[17]\d{8}$")

# Bank account: 5–20 alphanumeric characters
_BANK_ACCOUNT_RE = re.compile(r"^[A-Z0-9\-]{5,20}$", re.IGNORECASE)

# Email — relaxed RFC-style
_EMAIL_RE = re.compile(r"^[^\s@]+@[^\s@]+\.[^\s@]+$")


# ---------------------------------------------------------------------------
# Individual validators
# ---------------------------------------------------------------------------


def validate_kra_pin(pin: str | None) -> str | None:
    """Validate Kenya KRA PIN format (A000000000Z).

    Args:
        pin: Raw KRA PIN value from the request. May be ``None`` (not required).

    Returns:
        Upper-cased PIN string, or ``None`` if not provided.

    Raises:
        frappe.ValidationError: If the value is provided but malformed.
    """
    if not pin:
        return None
    clean = pin.strip().upper()
    if not _KRA_PIN_RE.match(clean):
        frappe.throw(
            _("KRA PIN must be in the format A000000000Z (letter + 9 digits + letter)."),
            frappe.ValidationError,
        )
    return clean


def validate_nssf_number(nssf: str | None) -> str | None:
    """Validate NSSF member number (6–10 digits)."""
    if not nssf:
        return None
    clean = nssf.strip()
    if not _NSSF_RE.match(clean):
        frappe.throw(
            _("NSSF number must be 6–10 digits."),
            frappe.ValidationError,
        )
    return clean


def validate_nhif_number(nhif: str | None) -> str | None:
    """Validate NHIF member number (6–10 digits)."""
    if not nhif:
        return None
    clean = nhif.strip()
    if not _NHIF_RE.match(clean):
        frappe.throw(
            _("NHIF number must be 6–10 digits."),
            frappe.ValidationError,
        )
    return clean


def validate_sha_number(sha: str | None) -> str | None:
    """Validate SHA (Social Health Authority) number (6–10 digits)."""
    if not sha:
        return None
    clean = sha.strip()
    if not _NHIF_RE.match(clean):
        frappe.throw(
            _("SHA number must be 6–10 digits."),
            frappe.ValidationError,
        )
    return clean


def validate_national_id(nid: str | None, id_type: str = "National ID") -> str | None:
    """Validate National ID or Passport number.

    Args:
        nid: The ID number string.
        id_type: ``"National ID"`` or ``"Passport"``.

    Returns:
        Cleaned string, or ``None`` if empty.
    """
    if not nid:
        return None
    clean = nid.strip().upper()
    if id_type == "Passport":
        if not _PASSPORT_RE.match(clean):
            frappe.throw(
                _("Passport number must be 6–9 alphanumeric characters."),
                frappe.ValidationError,
            )
    else:
        if not _NATIONAL_ID_RE.match(clean):
            frappe.throw(
                _("National ID must be 7 or 8 digits."),
                frappe.ValidationError,
            )
    return clean


def validate_phone(phone: str | None, field: str = "Phone number") -> str | None:
    """Validate a Kenya phone number (+254 or 07xx/01xx format).

    Args:
        phone: Raw phone number.
        field: Field label for error messages.
    """
    if not phone:
        return None
    clean = phone.strip().replace(" ", "").replace("-", "")
    if not _PHONE_KE_RE.match(clean):
        frappe.throw(
            _(
                "{0} must be a valid Kenya number: +254XXXXXXXXX, 07XXXXXXXX, or 01XXXXXXXX."
            ).format(field),
            frappe.ValidationError,
        )
    return clean


def validate_email(email: str | None, field: str = "Email") -> str | None:
    """Validate an email address."""
    if not email:
        return None
    clean = email.strip().lower()
    if not _EMAIL_RE.match(clean):
        frappe.throw(
            _("{0} '{1}' is not a valid email address.").format(field, clean),
            frappe.ValidationError,
        )
    return clean


def validate_bank_account(account: str | None) -> str | None:
    """Validate a bank account number (5–20 alphanumeric characters)."""
    if not account:
        return None
    clean = account.strip()
    if not _BANK_ACCOUNT_RE.match(clean):
        frappe.throw(
            _("Bank account number must be 5–20 alphanumeric characters."),
            frappe.ValidationError,
        )
    return clean


# ---------------------------------------------------------------------------
# Step-level composite validators
# ---------------------------------------------------------------------------


def validate_step_personal(data: dict) -> None:
    """Validate the Personal Details step payload.

    Raises ``frappe.ValidationError`` on the first failing field.
    """
    required = ["first_name", "last_name", "national_id_type",
                "national_id_number", "date_of_birth", "phone_number", "personal_email"]
    for field in required:
        if not data.get(field):
            frappe.throw(_("{0} is required.").format(field.replace("_", " ").title()),
                        frappe.ValidationError)

    validate_national_id(data.get("national_id_number"), data.get("national_id_type", "National ID"))
    validate_phone(data.get("phone_number"), "Phone Number")
    validate_email(data.get("personal_email"), "Personal Email")
    if data.get("work_email"):
        validate_email(data["work_email"], "Work Email")


def validate_step_employment(data: dict) -> None:
    """Validate the Employment Details step payload."""
    required = ["company", "department", "date_of_hire"]
    for field in required:
        if not data.get(field):
            frappe.throw(_("{0} is required.").format(field.replace("_", " ").title()),
                        frappe.ValidationError)


def validate_step_statutory(data: dict) -> None:
    """Validate the Statutory & Compliance step payload."""
    validate_kra_pin(data.get("kra_pin"))
    validate_nssf_number(data.get("nssf_number"))
    validate_nhif_number(data.get("nhif_number"))
    validate_sha_number(data.get("sha_number"))
    validate_bank_account(data.get("account_number"))


def validate_step_next_of_kin(data: dict) -> None:
    """Validate each next-of-kin entry."""
    for entry in data.get("next_of_kin", []):
        if not entry.get("full_name"):
            frappe.throw(_("Next of Kin: full_name is required."), frappe.ValidationError)
        if not entry.get("relationship"):
            frappe.throw(_("Next of Kin: relationship is required."), frappe.ValidationError)
        if entry.get("phone_number"):
            validate_phone(entry["phone_number"], "Next of Kin Phone")
        if entry.get("email"):
            validate_email(entry["email"], "Next of Kin Email")

    for entry in data.get("emergency_contacts", []):
        if not entry.get("full_name"):
            frappe.throw(_("Emergency Contact: full_name is required."), frappe.ValidationError)
        if entry.get("phone_number"):
            validate_phone(entry["phone_number"], "Emergency Contact Phone")


_STEP_VALIDATORS = {
    "personal": validate_step_personal,
    "employment": validate_step_employment,
    "statutory": validate_step_statutory,
    "next_of_kin": validate_step_next_of_kin,
}


def validate_onboarding_step(step: str, data: dict) -> None:
    """Dispatch to the appropriate step validator.

    Args:
        step: Step slug (``"personal"``, ``"employment"``, etc.).
        data: Payload dict for that step.
    """
    validator = _STEP_VALIDATORS.get(step)
    if validator:
        validator(data)


def assert_no_duplicate_employee(national_id: str, onboarding_id: str | None = None) -> None:
    """Raise if an active Employee with the same National ID already exists.

    Args:
        national_id: The national ID number to check.
        onboarding_id: Current onboarding record name to exclude from check.
    """
    if not national_id:
        return
    duplicates = frappe.db.get_all(
        "HRMS Onboarding",
        filters={
            "national_id_number": national_id,
            "status": ["in", ["Submitted", "Approved", "Onboarded"]],
        },
        fields=["name"],
        ignore_permissions=True,
    )
    for dup in duplicates:
        if dup["name"] != onboarding_id:
            frappe.throw(
                _("An onboarding record with National ID {0} already exists ({1}).").format(
                    national_id, dup["name"]
                ),
                frappe.DuplicateEntryError,
            )
