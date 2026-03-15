"""Structured observability for HRMS API endpoints.

Provides:
- ``emit_log`` — write a structured JSON log entry to the Frappe error log.
- ``timed`` — decorator that records wall-clock time for each endpoint call.
- ``on_request_end`` — Frappe ``on_request`` hook; emits a summary log line
  for every request that touches an HRMS endpoint.
- ``on_exception`` — Frappe ``on_exception`` hook; captures unhandled errors
  with HRMS context attached.

Log entries are written via ``frappe.log_error`` (append-only, searchable in
the Frappe error log UI).  In a later phase these can be forwarded to an
external SIEM or APM by swapping the ``_write`` helper.

All functions are safe to call when Frappe is not fully initialised — they
silently swallow errors so they never break the request path.
"""

from __future__ import annotations

import json
import time
import traceback
from functools import wraps
from typing import Callable, TypeVar

import frappe

from bitwyse_hrms_backend.auth import get_auth_method
from bitwyse_hrms_backend.response import _generate_request_id

F = TypeVar("F", bound=Callable)

# Attribute name stored on frappe.local to accumulate per-request context.
_LOCAL_KEY = "_hrms_obs"


# ---------------------------------------------------------------------------
# Core log writer
# ---------------------------------------------------------------------------


def emit_log(
    event: str,
    level: str = "INFO",
    **context,
) -> None:
    """Write a structured HRMS log entry.

    Args:
        event: Dot-namespaced event name, e.g. ``"api.list_employees"``.
        level: Severity — ``"INFO"``, ``"WARN"``, or ``"ERROR"``.
        **context: Arbitrary key-value pairs merged into the log payload.
    """
    try:
        payload = _build_payload(event, level, context)
        _write(f"HRMS | {level} | {event}", payload)
    except Exception:  # pragma: no cover
        pass  # observability must never break the request path


def emit_error(event: str, exc: BaseException, **context) -> None:
    """Write a structured error log entry with a full traceback.

    Args:
        event: Event name, e.g. ``"api.unhandled_exception"``.
        exc: The caught exception.
        **context: Additional context merged into the payload.
    """
    try:
        context["exception_type"] = type(exc).__name__
        context["exception_message"] = str(exc)
        context["traceback"] = traceback.format_exc()
        payload = _build_payload(event, "ERROR", context)
        _write(f"HRMS | ERROR | {event}", payload)
    except Exception:  # pragma: no cover
        pass


def _build_payload(event: str, level: str, context: dict) -> dict:
    obs = getattr(frappe.local, _LOCAL_KEY, {})
    return {
        "event": event,
        "level": level,
        "request_id": obs.get("request_id") or getattr(
            frappe.local, "hrms_request_id", _generate_request_id()
        ),
        "user": getattr(frappe.session, "user", "unknown"),
        "auth_method": get_auth_method(),
        "ip": _get_ip(),
        **context,
    }


def _write(title: str, payload: dict) -> None:
    frappe.log_error(
        message=json.dumps(payload, default=str),
        title=title[:140],
    )


def _get_ip() -> str:
    try:
        return frappe.local.request_ip or ""
    except Exception:
        return ""


# ---------------------------------------------------------------------------
# Per-request timing context
# ---------------------------------------------------------------------------


def _init_request_context() -> None:
    """Initialise the per-request observability context on frappe.local."""
    try:
        if not hasattr(frappe.local, _LOCAL_KEY):
            setattr(frappe.local, _LOCAL_KEY, {
                "request_id": _generate_request_id(),
                "start_ts": time.monotonic(),
                "endpoint": None,
            })
    except Exception:
        pass


def _get_request_context() -> dict:
    return getattr(frappe.local, _LOCAL_KEY, {})


# ---------------------------------------------------------------------------
# Endpoint timing decorator
# ---------------------------------------------------------------------------


def timed(fn: F) -> F:
    """Decorator: record wall-clock time for an endpoint and emit an INFO log.

    Usage::

        @frappe.whitelist()
        @timed
        def list_employees(**kwargs):
            ...
    """
    @wraps(fn)
    def wrapper(*args, **kwargs):  # type: ignore[return]
        _init_request_context()
        ctx = _get_request_context()
        ctx["endpoint"] = fn.__qualname__

        t0 = time.monotonic()
        try:
            result = fn(*args, **kwargs)
            elapsed_ms = int((time.monotonic() - t0) * 1000)
            emit_log(
                f"api.{fn.__name__}",
                level="INFO",
                elapsed_ms=elapsed_ms,
                outcome="success",
            )
            return result
        except frappe.PermissionError as exc:
            elapsed_ms = int((time.monotonic() - t0) * 1000)
            emit_log(
                f"api.{fn.__name__}",
                level="WARN",
                elapsed_ms=elapsed_ms,
                outcome="permission_denied",
                detail=str(exc),
            )
            raise
        except Exception as exc:
            elapsed_ms = int((time.monotonic() - t0) * 1000)
            emit_error(
                f"api.{fn.__name__}.error",
                exc,
                elapsed_ms=elapsed_ms,
            )
            raise

    return wrapper  # type: ignore[return-value]


# ---------------------------------------------------------------------------
# Frappe lifecycle hooks
# ---------------------------------------------------------------------------


def on_request() -> None:
    """Frappe ``on_request`` hook — called at the start of every request.

    Initialises the per-request observability context so that downstream
    log entries can include a consistent ``request_id``.
    """
    _init_request_context()


def on_exception() -> None:
    """Frappe ``on_exception`` hook — captures unhandled errors.

    Emits a structured ERROR log entry whenever Frappe's exception handler
    fires (i.e. unhandled exceptions that escape endpoint logic).
    """
    try:
        import sys
        exc_type, exc_value, _tb = sys.exc_info()
        if exc_value is None:
            return

        # Skip expected Frappe control-flow exceptions
        _IGNORABLE = (
            frappe.PermissionError,
            frappe.AuthenticationError,
            frappe.ValidationError,
            frappe.DoesNotExistError,
        )
        if isinstance(exc_value, _IGNORABLE):
            return

        emit_error("request.unhandled_exception", exc_value)
    except Exception:  # pragma: no cover
        pass
