"""Input validation and request security helpers.

Provides:
- ``sanitise_str`` — strip control characters; enforce max length.
- ``sanitise_date`` — validate ISO-8601 date strings; reject injections.
- ``sanitise_int`` — coerce and clamp integer params.
- ``sanitise_company`` — assert the company param exists in Frappe.
- ``assert_no_ssrf`` — block requests to internal/private IP ranges.
- ``validate_request_origin`` — check Origin/Referer against an allow-list.
- ``require_content_type`` — reject requests without JSON content-type (POST).

None of these functions perform database writes; they are pure guards that
raise ``frappe.ValidationError`` or ``frappe.PermissionError`` on failure.
"""

from __future__ import annotations

import ipaddress
import re
import socket
import unicodedata
from datetime import date

import frappe
from frappe import _

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

_MAX_STR_LEN = 500
_MAX_SEARCH_LEN = 140
_DATE_RE = re.compile(r"^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$")

# RFC 1918 + loopback + link-local + IANA-reserved private ranges
_PRIVATE_NETWORKS = [
    ipaddress.ip_network("10.0.0.0/8"),
    ipaddress.ip_network("172.16.0.0/12"),
    ipaddress.ip_network("192.168.0.0/16"),
    ipaddress.ip_network("127.0.0.0/8"),
    ipaddress.ip_network("169.254.0.0/16"),
    ipaddress.ip_network("::1/128"),
    ipaddress.ip_network("fc00::/7"),
    ipaddress.ip_network("fe80::/10"),
]


# ---------------------------------------------------------------------------
# String sanitisation
# ---------------------------------------------------------------------------


def sanitise_str(
    value: object,
    field: str = "value",
    max_len: int = _MAX_STR_LEN,
    required: bool = False,
) -> str | None:
    """Coerce *value* to a clean string and enforce length limits.

    Strips leading/trailing whitespace and Unicode control characters
    (category ``Cc``). Returns ``None`` if *value* is falsy and not required.

    Args:
        value: Raw input from the request.
        field: Human-readable field name used in error messages.
        max_len: Maximum allowed character count after stripping.
        required: If ``True``, raises ``ValidationError`` for empty input.

    Returns:
        Cleaned string, or ``None`` if the value is absent.

    Raises:
        frappe.ValidationError: If value exceeds max_len or is required but empty.
    """
    if value is None or value == "":
        if required:
            frappe.throw(_("{0} is required.").format(field), frappe.ValidationError)
        return None

    cleaned = "".join(
        ch for ch in str(value) if unicodedata.category(ch) != "Cc"
    ).strip()

    if required and not cleaned:
        frappe.throw(_("{0} must not be blank.").format(field), frappe.ValidationError)

    if len(cleaned) > max_len:
        frappe.throw(
            _("{0} must not exceed {1} characters.").format(field, max_len),
            frappe.ValidationError,
        )

    return cleaned or None


def sanitise_search(value: object) -> str | None:
    """Sanitise a free-text search parameter (shorter max length).

    Returns:
        Cleaned search string, or ``None`` if absent.
    """
    return sanitise_str(value, field="search", max_len=_MAX_SEARCH_LEN)


# ---------------------------------------------------------------------------
# Date validation
# ---------------------------------------------------------------------------


def sanitise_date(value: object, field: str = "date", required: bool = False) -> str | None:
    """Validate that *value* is a well-formed ISO-8601 date string (YYYY-MM-DD).

    Args:
        value: Raw input.
        field: Field name for error messages.
        required: Raise if absent.

    Returns:
        Validated date string, or ``None`` if absent.

    Raises:
        frappe.ValidationError: If the value is present but not a valid date.
    """
    raw = sanitise_str(value, field=field, max_len=10, required=required)
    if raw is None:
        return None

    if not _DATE_RE.match(raw):
        frappe.throw(
            _("{0} must be a valid date in YYYY-MM-DD format.").format(field),
            frappe.ValidationError,
        )

    # Calendar validation via stdlib
    try:
        date.fromisoformat(raw)
    except ValueError:
        frappe.throw(
            _("{0} is not a valid calendar date.").format(field),
            frappe.ValidationError,
        )

    return raw


def assert_date_order(start: str, end: str) -> None:
    """Assert that *start* is not after *end*.

    Args:
        start: ISO-8601 start date.
        end: ISO-8601 end date.

    Raises:
        frappe.ValidationError: If start > end.
    """
    if date.fromisoformat(start) > date.fromisoformat(end):
        frappe.throw(
            _("Start date must not be after end date."),
            frappe.ValidationError,
        )


# ---------------------------------------------------------------------------
# Integer sanitisation
# ---------------------------------------------------------------------------


def sanitise_int(
    value: object,
    field: str = "value",
    min_val: int = 0,
    max_val: int = 10_000,
    default: int | None = None,
) -> int:
    """Coerce *value* to an integer within [min_val, max_val].

    Args:
        value: Raw input.
        field: Field name for error messages.
        min_val: Minimum allowed value (inclusive).
        max_val: Maximum allowed value (inclusive).
        default: Value returned when *value* is ``None``; if ``None`` the
            ``min_val`` is used as default.

    Returns:
        Validated integer.

    Raises:
        frappe.ValidationError: If the value cannot be coerced or is out of range.
    """
    if value is None:
        return default if default is not None else min_val

    try:
        coerced = int(value)
    except (TypeError, ValueError):
        frappe.throw(
            _("{0} must be an integer.").format(field),
            frappe.ValidationError,
        )

    if not (min_val <= coerced <= max_val):
        frappe.throw(
            _("{0} must be between {1} and {2}.").format(field, min_val, max_val),
            frappe.ValidationError,
        )

    return coerced


# ---------------------------------------------------------------------------
# Company validation
# ---------------------------------------------------------------------------


def sanitise_company(value: object, required: bool = False) -> str | None:
    """Validate that *value* is a non-empty string matching an existing Company.

    Args:
        value: Raw company parameter.
        required: Raise if absent.

    Returns:
        Validated company name, or ``None`` if absent.

    Raises:
        frappe.ValidationError: If company is required but missing, or does not exist.
    """
    name = sanitise_str(value, field="company", required=required)
    if name is None:
        return None

    if not frappe.db.exists("Company", name):
        frappe.throw(
            _("Company '{0}' does not exist.").format(name),
            frappe.ValidationError,
        )

    return name


# ---------------------------------------------------------------------------
# SSRF protection
# ---------------------------------------------------------------------------


def assert_no_ssrf(url: str) -> None:
    """Reject URLs that resolve to private/internal IP addresses.

    Performs a DNS lookup on the hostname and checks each resolved address
    against private network ranges. Used when the backend makes outbound
    HTTP calls (e.g. webhook delivery, OAuth token exchange).

    Args:
        url: The URL to validate.

    Raises:
        frappe.PermissionError: If the URL resolves to an internal address.
        frappe.ValidationError: If the URL is malformed.
    """
    from urllib.parse import urlparse

    try:
        parsed = urlparse(url)
        hostname = parsed.hostname
    except Exception:
        frappe.throw(_("Invalid URL."), frappe.ValidationError)

    if not hostname:
        frappe.throw(_("URL must include a hostname."), frappe.ValidationError)

    # Block raw IP literals directly
    try:
        addr = ipaddress.ip_address(hostname)
        _check_ip_private(addr, url)
        return
    except ValueError:
        pass  # not a bare IP — do DNS resolution

    try:
        results = socket.getaddrinfo(hostname, None)
    except socket.gaierror:
        frappe.throw(_("Could not resolve hostname: {0}").format(hostname), frappe.ValidationError)

    for _family, _type, _proto, _canonname, sockaddr in results:
        addr_str = sockaddr[0]
        try:
            _check_ip_private(ipaddress.ip_address(addr_str), url)
        except frappe.PermissionError:
            raise


def _check_ip_private(addr: ipaddress.IPv4Address | ipaddress.IPv6Address, url: str) -> None:
    for net in _PRIVATE_NETWORKS:
        if addr in net:
            frappe.throw(
                _("Requests to internal addresses are not allowed: {0}").format(url),
                frappe.PermissionError,
            )


# ---------------------------------------------------------------------------
# Origin / Referer allow-list
# ---------------------------------------------------------------------------


def validate_request_origin(allowed_origins: list[str] | None = None) -> None:
    """Check that the request Origin matches the configured allow-list.

    Only enforced when the ``Origin`` header is present (i.e. cross-origin
    browser requests). Same-origin requests (no header) are allowed through.

    Args:
        allowed_origins: List of allowed scheme+host origins, e.g.
            ``["https://hrms.bitwyse.co.ke"]``.  When ``None`` the value from
            ``frappe.conf.hrms_allowed_origins`` (list) is used instead.

    Raises:
        frappe.PermissionError: If the Origin is present but not allowed.
    """
    origin: str = frappe.get_request_header("Origin") or ""
    if not origin:
        return  # same-origin or non-browser request — allow

    allowed: list[str] = allowed_origins or frappe.conf.get("hrms_allowed_origins") or []
    if not allowed:
        return  # no allow-list configured — permissive mode

    if not any(origin.rstrip("/") == a.rstrip("/") for a in allowed):
        frappe.throw(
            _("Cross-origin request from '{0}' is not permitted.").format(origin),
            frappe.PermissionError,
        )
