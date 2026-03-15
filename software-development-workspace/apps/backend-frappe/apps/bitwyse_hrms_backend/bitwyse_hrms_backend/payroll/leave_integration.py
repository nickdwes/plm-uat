"""Leave-pay integration stub for Phase C payroll.

This module provides the interface that payroll computation will call to
apply leave impacts (unpaid leave deductions, leave encashment additions)
to salary slip calculations.

Phase C stub: Functions here return zero-impact results so payroll can run
without Phase D leave ledger. Phase D will replace these stubs with real
leave ledger queries.

The contract is stable — callers in ``run.py`` and ``payslip.py`` import
from here so Phase D only needs to update this file.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass
class LeavePayImpact:
    """Leave-driven pay adjustments for a single employee period.

    Attributes:
        unpaid_leave_days: Number of unpaid leave days to deduct.
        unpaid_leave_deduction: KES amount to deduct for unpaid leave.
        encashment_days: Leave days being encashed.
        encashment_addition: KES amount to add for leave encashment.
    """

    unpaid_leave_days: float = 0.0
    unpaid_leave_deduction: float = 0.0
    encashment_days: float = 0.0
    encashment_addition: float = 0.0


def get_leave_pay_impact(
    employee: str,
    start_date: str,
    end_date: str,
    daily_rate: float = 0.0,
) -> LeavePayImpact:
    """Return the leave-driven pay impact for an employee in a period.

    Phase C stub — always returns zero impact. Phase D will implement
    the real leave ledger query here.

    Args:
        employee: Frappe Employee name.
        start_date: Payroll period start ISO date.
        end_date: Payroll period end ISO date.
        daily_rate: Employee daily pay rate for deduction computation.

    Returns:
        LeavePayImpact: Zero-impact result until Phase D is complete.
    """
    # Phase D TODO: query LeaveAllocation, LeaveApplication ledger and
    # return actual unpaid days + encashment amounts.
    return LeavePayImpact()
