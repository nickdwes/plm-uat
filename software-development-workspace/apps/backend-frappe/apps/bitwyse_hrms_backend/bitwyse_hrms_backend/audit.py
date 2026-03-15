"""Immutable audit event hooks for sensitive HRMS document mutations.

These functions are invoked by Frappe's ``doc_events`` hook mechanism defined
in ``hooks.py``. Each function writes a structured audit log entry to the
Frappe error log table (used as an append-only audit trail for Phase 1).

Phase 1 audit events:
  - Employee insert (new hire created)
  - Employee update (profile change, status change, transfer, etc.)

A dedicated Audit Log DocType will replace the error-log approach in a
future phase once the schema is finalised.
"""

from __future__ import annotations

import json

import frappe

from bitwyse_hrms_backend.auth import get_auth_method
from bitwyse_hrms_backend.response import _generate_request_id


def _write_audit_event(
    event: str,
    doctype: str,
    docname: str,
    actor: str,
    payload: dict,
) -> None:
    """Write a structured audit log entry.

    Args:
        event: Short event identifier, e.g. ``"employee.created"``.
        doctype: Frappe DocType of the affected document.
        docname: Name (primary key) of the affected document.
        actor: User who triggered the event.
        payload: Additional context serialised into the log entry.
    """
    entry = {
        "event": event,
        "doctype": doctype,
        "docname": docname,
        "actor": actor,
        "auth_method": get_auth_method(),
        "request_id": getattr(frappe.local, "hrms_request_id", _generate_request_id()),
        "data": payload,
    }
    frappe.log_error(
        message=json.dumps(entry, default=str),
        title=f"HRMS Audit | {event}",
    )


def on_employee_insert(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires after a new Employee document is created.

    Args:
        doc: The Employee document that was inserted.
        method: Frappe event method name (unused).
    """
    _write_audit_event(
        event="employee.created",
        doctype="Employee",
        docname=doc.name,
        actor=frappe.session.user,
        payload={
            "employee_name": doc.employee_name,
            "company": doc.company,
            "status": doc.status,
            "department": getattr(doc, "department", None),
            "designation": getattr(doc, "designation", None),
        },
    )


def on_employee_update(doc, method=None) -> None:  # noqa: ANN001
    """Audit hook: fires after an existing Employee document is saved.

    Captures a diff of changed fields so that the audit trail records what
    actually changed rather than the full document state.

    Args:
        doc: The Employee document that was updated.
        method: Frappe event method name (unused).
    """
    changed_fields: dict = {}
    if doc.get_doc_before_save():
        before = doc.get_doc_before_save()
        tracked = [
            "employee_name", "company", "status", "department",
            "designation", "date_of_joining", "relieving_date",
        ]
        for field in tracked:
            old_val = getattr(before, field, None)
            new_val = getattr(doc, field, None)
            if old_val != new_val:
                changed_fields[field] = {"before": old_val, "after": new_val}

    if not changed_fields:
        # No tracked fields changed — skip audit to reduce noise.
        return

    _write_audit_event(
        event="employee.updated",
        doctype="Employee",
        docname=doc.name,
        actor=frappe.session.user,
        payload={"changed": changed_fields},
    )
