"""HRMS Audit Log module.

Provides a dedicated, append-only Audit Log DocType for recording all
sensitive HRMS operations, replacing the Phase 1 approach of writing to
the Frappe Error Log.

Exports:
    write_audit_event — drop-in replacement for the private ``_write_audit_event``
        in ``audit.py``.  Falls back to ``frappe.log_error`` if the DocType
        has not yet been installed (safe during bench migrate).
"""

from bitwyse_hrms_backend.audit_log.writer import write_audit_event

__all__ = ["write_audit_event"]
