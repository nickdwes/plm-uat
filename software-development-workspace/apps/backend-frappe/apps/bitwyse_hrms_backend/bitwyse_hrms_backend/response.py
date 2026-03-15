"""Structured API response helpers for the HRMS external API.

All public endpoints must return responses built through this module so that
the external UI receives a consistent envelope regardless of which endpoint
is called. The envelope shape is::

    {
        "api_version": "1",
        "request_id": "<uuid>",
        "data": <payload>,
        "meta": { ... }          # optional – present on list responses
        "error": null            # or structured error object on failure
    }

Pagination metadata shape (included in ``meta`` for list responses)::

    {
        "limit_start": 0,
        "limit_page_length": 20,
        "total": 150
    }

Error object shape::

    {
        "code": "PERMISSION_DENIED",
        "message": "Human-readable explanation."
    }
"""

from __future__ import annotations

import uuid
from typing import Any

import frappe

# ---------------------------------------------------------------------------
# API versioning
# ---------------------------------------------------------------------------

API_VERSION = "1"

# ---------------------------------------------------------------------------
# Error code constants
# ---------------------------------------------------------------------------

ERR_UNAUTHENTICATED = "UNAUTHENTICATED"
ERR_PERMISSION_DENIED = "PERMISSION_DENIED"
ERR_VALIDATION = "VALIDATION_ERROR"
ERR_NOT_FOUND = "NOT_FOUND"
ERR_RATE_LIMITED = "RATE_LIMITED"
ERR_INTERNAL = "INTERNAL_ERROR"


# ---------------------------------------------------------------------------
# Request ID
# ---------------------------------------------------------------------------


def _generate_request_id() -> str:
    """Generate a unique request correlation ID for this API call.

    The ID is persisted in the Frappe local context so it can be included in
    audit log entries and server-side logs from the same request.

    Returns:
        str: UUID4 string.
    """
    if not hasattr(frappe.local, "hrms_request_id"):
        frappe.local.hrms_request_id = str(uuid.uuid4())
    return frappe.local.hrms_request_id


# ---------------------------------------------------------------------------
# Envelope builders
# ---------------------------------------------------------------------------


def success_response(
    data: Any,
    meta: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Build a successful API response envelope.

    Args:
        data: The primary payload to return to the caller.
        meta: Optional metadata dict, typically pagination info.

    Returns:
        dict: Standardised response envelope with ``api_version``,
            ``request_id``, ``data``, ``meta``, and ``error`` keys.
    """
    return {
        "api_version": API_VERSION,
        "request_id": _generate_request_id(),
        "data": data,
        "meta": meta,
        "error": None,
    }


def error_response(
    message: str,
    code: str = ERR_INTERNAL,
) -> dict[str, Any]:
    """Build a structured error response envelope.

    This is used when an endpoint catches a known exception and wants to
    return a controlled error payload instead of letting Frappe serialize it.

    Args:
        message: Human-readable error description for the UI to display.
        code: Machine-readable error code constant from this module.

    Returns:
        dict: Standardised response envelope with ``error`` populated and
            ``data`` set to None.
    """
    return {
        "api_version": API_VERSION,
        "request_id": _generate_request_id(),
        "data": None,
        "meta": None,
        "error": {
            "code": code,
            "message": message,
        },
    }


def pagination_meta(
    limit_start: int,
    limit_page_length: int,
    total: int,
) -> dict[str, int]:
    """Build the pagination metadata block for list responses.

    Args:
        limit_start: The start offset that was applied to the query.
        limit_page_length: The page size that was applied.
        total: Total number of records matching the query (before pagination).

    Returns:
        dict: Pagination metadata with ``limit_start``, ``limit_page_length``,
            and ``total`` keys.
    """
    return {
        "limit_start": limit_start,
        "limit_page_length": limit_page_length,
        "total": total,
    }


# ---------------------------------------------------------------------------
# Pagination input helpers
# ---------------------------------------------------------------------------

DEFAULT_PAGE_LENGTH = 20
MAX_PAGE_LENGTH = 100


def coerce_pagination(
    limit_start: Any,
    limit_page_length: Any,
) -> tuple[int, int]:
    """Validate and normalise pagination inputs from API callers.

    Args:
        limit_start: Raw start offset from the request (any type).
        limit_page_length: Raw page length from the request (any type).

    Returns:
        tuple[int, int]: ``(start, page_size)`` as safe non-negative integers
            with page size capped at ``MAX_PAGE_LENGTH``.
    """
    try:
        start = max(int(limit_start), 0)
    except (TypeError, ValueError):
        start = 0

    try:
        size = int(limit_page_length)
    except (TypeError, ValueError):
        size = DEFAULT_PAGE_LENGTH

    size = max(size, 1)
    size = min(size, MAX_PAGE_LENGTH)
    return start, size
