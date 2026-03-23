"""after_install hook — runs setup_pilot_tenant on first app install.

Called automatically by Frappe when:
    bench --site <site> install-app bitwyse_hrms_backend

Also exposes ``after_migrate`` to re-sync roles/components on each migration
(idempotent, so safe to run repeatedly).
"""

from __future__ import annotations

import frappe


def after_install() -> None:
    """Bootstrap HRMS roles, leave types, and salary components after install."""
    try:
        from bitwyse_hrms_backend.setup.fixtures import setup_pilot_tenant

        result = setup_pilot_tenant()
        created = len(result.get("created", []))
        skipped = len(result.get("skipped", []))
        frappe.logger().info(
            f"[bitwyse_hrms_backend] after_install: created {created} items, "
            f"skipped {skipped} existing items."
        )
    except Exception as exc:  # pragma: no cover
        frappe.logger().warning(
            f"[bitwyse_hrms_backend] after_install setup warning: {exc}"
        )

    try:
        from bitwyse_hrms_backend.onboarding.models import ensure_onboarding_doctypes

        dt_result = ensure_onboarding_doctypes()
        frappe.logger().info(
            f"[bitwyse_hrms_backend] onboarding DocTypes: "
            f"created {dt_result['created']}, skipped {dt_result['skipped']}"
        )
    except Exception as exc:  # pragma: no cover
        frappe.logger().warning(
            f"[bitwyse_hrms_backend] onboarding DocType install warning: {exc}"
        )


def after_migrate() -> None:
    """Re-sync roles and components after each bench migrate (idempotent)."""
    after_install()
