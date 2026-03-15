"""Audit event writer — appends to HRMS Audit Log DocType when available.

Falls back to ``frappe.log_error`` when the DocType has not yet been
installed so that the app works safely before ``bench migrate`` is run.
"""

from __future__ import annotations

import json

import frappe

from bitwyse_hrms_backend.auth import get_auth_method
from bitwyse_hrms_backend.response import _generate_request_id

_DOCTYPE = "HRMS Audit Log"
_doctype_exists: bool | None = None  # cached after first check


def _audit_doctype_exists() -> bool:
    global _doctype_exists
    if _doctype_exists is None:
        try:
            _doctype_exists = bool(frappe.db.exists("DocType", _DOCTYPE))
        except Exception:
            _doctype_exists = False
    return _doctype_exists


def write_audit_event(
    event: str,
    doctype: str,
    docname: str,
    actor: str,
    payload: dict,
) -> None:
    """Write a structured audit log entry.

    Tries to insert into the ``HRMS Audit Log`` DocType first.  Falls back
    to ``frappe.log_error`` so that audit events are never silently dropped.

    Args:
        event: Short event identifier, e.g. ``"employee.created"``.
        doctype: Frappe DocType of the affected document.
        docname: Name (primary key) of the affected document.
        actor: User who triggered the event.
        payload: Additional context dict serialised into the log entry.
    """
    request_id = getattr(frappe.local, "hrms_request_id", None) or _generate_request_id()
    auth_method = get_auth_method()
    ip = _safe_ip()

    structured: dict = {
        "event": event,
        "doctype": doctype,
        "docname": docname,
        "actor": actor,
        "auth_method": auth_method,
        "request_id": request_id,
        "ip": ip,
        "data": payload,
    }

    if _audit_doctype_exists():
        try:
            doc = frappe.get_doc({
                "doctype": _DOCTYPE,
                "event": event,
                "reference_doctype": doctype,
                "reference_name": docname,
                "actor": actor,
                "auth_method": auth_method,
                "request_id": request_id,
                "ip_address": ip,
                "payload": json.dumps(payload, default=str),
            })
            doc.insert(ignore_permissions=True)
            frappe.db.commit()
            return
        except Exception:
            pass  # fall through to log_error fallback

    # Fallback: write to error log (Phase 1 behaviour)
    frappe.log_error(
        message=json.dumps(structured, default=str),
        title=f"HRMS Audit | {event}",
    )


def _safe_ip() -> str:
    try:
        return frappe.local.request_ip or ""
    except Exception:
        return ""


def _block_audit_log_mutation(doc, method=None) -> None:  # noqa: ANN001
    """Doc event: prevent mutation of existing HRMS Audit Log records.

    Fires via ``before_save`` hook.  New records (no ``creation`` in db) are
    allowed through; any attempt to re-save an existing record is blocked.
    """
    if doc.get("__islocal"):
        return  # new record — allow

    if frappe.db.exists(_DOCTYPE, doc.name):
        frappe.throw(
            frappe._("Audit log records are immutable and cannot be modified."),
            frappe.PermissionError,
        )
