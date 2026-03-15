"""Kenya statutory deduction engine with effective-date versioned rate tables.

Implements the following Kenya statutory deductions as of 2024/2025:
  - PAYE  : Progressive income tax (KRA bands + personal/insurance relief).
  - NSSF  : National Social Security Fund (Tier I + Tier II under 2013 Act).
  - SHIF  : Social Health Insurance Fund (replaces NHIF from Oct 2023).
  - AHL   : Affordable Housing Levy (effective Mar 2023).

Rate tables are versioned by ``effective_from`` date so that historical
payroll recalculations use the correct rates. The engine selects the table
whose ``effective_from`` is the latest date on or before the payroll date.

All monetary amounts are in KES unless stated otherwise.

Public API:
    get_statutory_rates(payroll_date) -> StatutoryRates
    compute_paye(gross_pay, payroll_date, reliefs) -> PayeResult
    compute_nssf(gross_pay, payroll_date) -> NssfResult
    compute_shif(gross_pay, payroll_date) -> ShifResult
    compute_ahl(gross_pay, payroll_date) -> AhlResult
    compute_all_deductions(gross_pay, payroll_date, reliefs) -> StatutoryDeductions
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any


# ---------------------------------------------------------------------------
# Versioned rate table definitions
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class PayeBand:
    """A single PAYE tax band.

    Attributes:
        upper_limit: Upper monthly income limit for this band (None = unbounded).
        rate: Marginal tax rate as a decimal (e.g. 0.10 for 10%).
    """

    upper_limit: float | None
    rate: float


@dataclass(frozen=True)
class NssfConfig:
    """NSSF contribution configuration.

    Attributes:
        tier1_limit: Lower Earnings Limit (LEL) for Tier I contributions.
        tier2_limit: Upper Earnings Limit (UEL) for Tier II contributions.
        rate: Employee (and employer) contribution rate as a decimal.
        legacy_flat: Legacy flat contribution per side (pre-2013 Act).
            Used when ``use_legacy=True``.
        use_legacy: If True, use the flat legacy rate instead of tiered.
    """

    tier1_limit: float
    tier2_limit: float
    rate: float
    legacy_flat: float
    use_legacy: bool


@dataclass(frozen=True)
class ShifConfig:
    """SHIF / NHIF configuration.

    Attributes:
        rate: Contribution rate as a decimal of gross pay.
        minimum: Minimum monthly contribution in KES.
        label: ``"SHIF"`` or ``"NHIF"`` for display/reporting.
    """

    rate: float
    minimum: float
    label: str


@dataclass(frozen=True)
class AhlConfig:
    """Affordable Housing Levy configuration.

    Attributes:
        employee_rate: Employee AHL rate as a decimal.
        employer_rate: Employer AHL rate as a decimal.
        active: Whether AHL is active for this period.
    """

    employee_rate: float
    employer_rate: float
    active: bool


@dataclass(frozen=True)
class StatutoryRates:
    """Complete statutory rate configuration for a payroll period.

    Attributes:
        effective_from: The date from which this rate set applies.
        personal_relief: Monthly personal relief deducted from PAYE (KES).
        insurance_relief_rate: Insurance premium relief rate (15% of premiums).
        insurance_relief_max: Maximum monthly insurance relief (KES).
        paye_bands: Ordered list of PAYE tax bands (ascending upper_limit).
        nssf: NSSF configuration.
        shif: SHIF/NHIF configuration.
        ahl: AHL configuration.
    """

    effective_from: date
    personal_relief: float
    insurance_relief_rate: float
    insurance_relief_max: float
    paye_bands: tuple[PayeBand, ...]
    nssf: NssfConfig
    shif: ShifConfig
    ahl: AhlConfig


# ---------------------------------------------------------------------------
# Rate tables (chronological order — oldest first)
# ---------------------------------------------------------------------------

_RATE_TABLES: list[StatutoryRates] = [
    # -----------------------------------------------------------------------
    # Pre-SHIF / Pre-AHL baseline (used for historical payslips before Oct 2023)
    # -----------------------------------------------------------------------
    StatutoryRates(
        effective_from=date(2021, 1, 1),
        personal_relief=2400.0,
        insurance_relief_rate=0.15,
        insurance_relief_max=5000.0,
        paye_bands=(
            PayeBand(upper_limit=24000.0, rate=0.10),
            PayeBand(upper_limit=32333.0, rate=0.25),
            PayeBand(upper_limit=500000.0, rate=0.30),
            PayeBand(upper_limit=800000.0, rate=0.325),
            PayeBand(upper_limit=None, rate=0.35),
        ),
        nssf=NssfConfig(
            tier1_limit=7000.0,
            tier2_limit=36000.0,
            rate=0.06,
            legacy_flat=200.0,
            use_legacy=True,  # Most employers still use legacy flat rate.
        ),
        shif=ShifConfig(rate=0.0, minimum=150.0, label="NHIF"),
        ahl=AhlConfig(employee_rate=0.0, employer_rate=0.0, active=False),
    ),
    # -----------------------------------------------------------------------
    # AHL introduced (March 2023) + NHIF still active
    # -----------------------------------------------------------------------
    StatutoryRates(
        effective_from=date(2023, 3, 1),
        personal_relief=2400.0,
        insurance_relief_rate=0.15,
        insurance_relief_max=5000.0,
        paye_bands=(
            PayeBand(upper_limit=24000.0, rate=0.10),
            PayeBand(upper_limit=32333.0, rate=0.25),
            PayeBand(upper_limit=500000.0, rate=0.30),
            PayeBand(upper_limit=800000.0, rate=0.325),
            PayeBand(upper_limit=None, rate=0.35),
        ),
        nssf=NssfConfig(
            tier1_limit=7000.0,
            tier2_limit=36000.0,
            rate=0.06,
            legacy_flat=200.0,
            use_legacy=True,
        ),
        shif=ShifConfig(rate=0.0, minimum=150.0, label="NHIF"),
        ahl=AhlConfig(employee_rate=0.015, employer_rate=0.015, active=True),
    ),
    # -----------------------------------------------------------------------
    # SHIF replaces NHIF (October 2023) — 2.75% of gross, min KES 300
    # -----------------------------------------------------------------------
    StatutoryRates(
        effective_from=date(2023, 10, 1),
        personal_relief=2400.0,
        insurance_relief_rate=0.15,
        insurance_relief_max=5000.0,
        paye_bands=(
            PayeBand(upper_limit=24000.0, rate=0.10),
            PayeBand(upper_limit=32333.0, rate=0.25),
            PayeBand(upper_limit=500000.0, rate=0.30),
            PayeBand(upper_limit=800000.0, rate=0.325),
            PayeBand(upper_limit=None, rate=0.35),
        ),
        nssf=NssfConfig(
            tier1_limit=7000.0,
            tier2_limit=36000.0,
            rate=0.06,
            legacy_flat=200.0,
            use_legacy=True,
        ),
        shif=ShifConfig(rate=0.0275, minimum=300.0, label="SHIF"),
        ahl=AhlConfig(employee_rate=0.015, employer_rate=0.015, active=True),
    ),
]


# ---------------------------------------------------------------------------
# Rate selection
# ---------------------------------------------------------------------------


def get_statutory_rates(payroll_date: date) -> StatutoryRates:
    """Return the statutory rate configuration applicable on a given date.

    Selects the rate table with the latest ``effective_from`` that is on or
    before ``payroll_date``.

    Args:
        payroll_date: The date of the payroll period end or processing date.

    Returns:
        StatutoryRates: The applicable rate configuration.

    Raises:
        ValueError: If no rate table covers the given date.
    """
    applicable = [r for r in _RATE_TABLES if r.effective_from <= payroll_date]
    if not applicable:
        raise ValueError(
            f"No statutory rate table found for payroll date {payroll_date}. "
            "The earliest available table is "
            f"{_RATE_TABLES[0].effective_from.isoformat()}."
        )
    return max(applicable, key=lambda r: r.effective_from)


# ---------------------------------------------------------------------------
# Result dataclasses
# ---------------------------------------------------------------------------


@dataclass
class PayeResult:
    """PAYE calculation result.

    Attributes:
        gross_taxable: Gross pay used as the PAYE base.
        tax_before_relief: Tax computed from bands before relief.
        personal_relief: Personal relief applied.
        insurance_relief: Insurance relief applied.
        paye_payable: Final PAYE after all reliefs (floor 0).
    """

    gross_taxable: float
    tax_before_relief: float
    personal_relief: float
    insurance_relief: float
    paye_payable: float


@dataclass
class NssfResult:
    """NSSF contribution result.

    Attributes:
        employee_contribution: Employee-side NSSF deduction.
        employer_contribution: Employer-side NSSF contribution.
        tier1_employee: Tier I portion of employee contribution.
        tier2_employee: Tier II portion of employee contribution.
    """

    employee_contribution: float
    employer_contribution: float
    tier1_employee: float
    tier2_employee: float


@dataclass
class ShifResult:
    """SHIF/NHIF contribution result.

    Attributes:
        employee_contribution: Employee-side deduction.
        employer_contribution: Employer-side contribution (mirrors employee).
        label: ``"SHIF"`` or ``"NHIF"`` per the applicable period.
    """

    employee_contribution: float
    employer_contribution: float
    label: str


@dataclass
class AhlResult:
    """Affordable Housing Levy result.

    Attributes:
        employee_contribution: Employee-side AHL deduction.
        employer_contribution: Employer-side AHL contribution.
        active: Whether AHL applied for this period.
    """

    employee_contribution: float
    employer_contribution: float
    active: bool


@dataclass
class StatutoryDeductions:
    """Aggregated statutory deductions for a single employee payroll period.

    Attributes:
        gross_pay: The gross pay used as the computation base.
        paye: PAYE result.
        nssf: NSSF result.
        shif: SHIF/NHIF result.
        ahl: AHL result.
        total_employee_deductions: Sum of all employee-side statutory deductions.
        total_employer_contributions: Sum of all employer-side contributions.
        net_pay: gross_pay minus total_employee_deductions.
    """

    gross_pay: float
    paye: PayeResult
    nssf: NssfResult
    shif: ShifResult
    ahl: AhlResult
    total_employee_deductions: float
    total_employer_contributions: float
    net_pay: float


# ---------------------------------------------------------------------------
# Computation functions
# ---------------------------------------------------------------------------


def compute_paye(
    gross_taxable: float,
    payroll_date: date,
    insurance_premiums: float = 0.0,
) -> PayeResult:
    """Compute Kenya PAYE for a given monthly gross taxable income.

    Args:
        gross_taxable: Monthly gross taxable pay in KES.
        payroll_date: Date used to select the applicable rate table.
        insurance_premiums: Monthly insurance premiums paid by the employee
            (for insurance relief calculation).

    Returns:
        PayeResult: Detailed PAYE computation breakdown.
    """
    rates = get_statutory_rates(payroll_date)
    tax = 0.0
    remaining = gross_taxable

    for band in rates.paye_bands:
        if remaining <= 0:
            break
        if band.upper_limit is None:
            taxable_in_band = remaining
        else:
            # Width of this band relative to the start of gross income.
            band_width = band.upper_limit - (gross_taxable - remaining)
            taxable_in_band = min(remaining, band_width)
        tax += taxable_in_band * band.rate
        remaining -= taxable_in_band

    insurance_relief = min(
        insurance_premiums * rates.insurance_relief_rate,
        rates.insurance_relief_max,
    )
    paye_payable = max(tax - rates.personal_relief - insurance_relief, 0.0)

    return PayeResult(
        gross_taxable=gross_taxable,
        tax_before_relief=round(tax, 2),
        personal_relief=rates.personal_relief,
        insurance_relief=round(insurance_relief, 2),
        paye_payable=round(paye_payable, 2),
    )


def compute_nssf(gross_pay: float, payroll_date: date) -> NssfResult:
    """Compute NSSF contributions for a given gross pay.

    Args:
        gross_pay: Monthly gross pay in KES.
        payroll_date: Date used to select the applicable rate table.

    Returns:
        NssfResult: Employee and employer NSSF contribution breakdown.
    """
    rates = get_statutory_rates(payroll_date)
    cfg = rates.nssf

    if cfg.use_legacy:
        employee = cfg.legacy_flat
        employer = cfg.legacy_flat
        tier1 = employee
        tier2 = 0.0
    else:
        # Tier I: on earnings up to LEL.
        tier1_base = min(gross_pay, cfg.tier1_limit)
        tier1 = tier1_base * cfg.rate

        # Tier II: on earnings between LEL and UEL.
        tier2_base = max(min(gross_pay, cfg.tier2_limit) - cfg.tier1_limit, 0.0)
        tier2 = tier2_base * cfg.rate

        employee = tier1 + tier2
        employer = employee

    return NssfResult(
        employee_contribution=round(employee, 2),
        employer_contribution=round(employer, 2),
        tier1_employee=round(tier1, 2),
        tier2_employee=round(tier2, 2),
    )


def compute_shif(gross_pay: float, payroll_date: date) -> ShifResult:
    """Compute SHIF (or NHIF) contribution for a given gross pay.

    Args:
        gross_pay: Monthly gross pay in KES.
        payroll_date: Date used to select the applicable rate table.

    Returns:
        ShifResult: Employee and employer SHIF/NHIF contribution.
    """
    rates = get_statutory_rates(payroll_date)
    cfg = rates.shif

    if cfg.rate > 0:
        # SHIF — percentage-based with minimum floor.
        raw = gross_pay * cfg.rate
        contribution = max(raw, cfg.minimum)
    else:
        # NHIF legacy — use fixed minimum as a placeholder;
        # in practice NHIF used banded tables which should be configured
        # via Salary Components in Frappe for historical periods.
        contribution = cfg.minimum

    return ShifResult(
        employee_contribution=round(contribution, 2),
        employer_contribution=round(contribution, 2),
        label=cfg.label,
    )


def compute_ahl(gross_pay: float, payroll_date: date) -> AhlResult:
    """Compute Affordable Housing Levy for a given gross pay.

    Args:
        gross_pay: Monthly gross pay in KES.
        payroll_date: Date used to select the applicable rate table.

    Returns:
        AhlResult: Employee and employer AHL contributions.
    """
    rates = get_statutory_rates(payroll_date)
    cfg = rates.ahl

    if not cfg.active:
        return AhlResult(employee_contribution=0.0, employer_contribution=0.0, active=False)

    employee = round(gross_pay * cfg.employee_rate, 2)
    employer = round(gross_pay * cfg.employer_rate, 2)
    return AhlResult(employee_contribution=employee, employer_contribution=employer, active=True)


def compute_all_deductions(
    gross_pay: float,
    payroll_date: date,
    insurance_premiums: float = 0.0,
) -> StatutoryDeductions:
    """Compute all Kenya statutory deductions for a single employee period.

    Args:
        gross_pay: Monthly gross pay in KES.
        payroll_date: Payroll period date for rate selection.
        insurance_premiums: Monthly insurance premiums for PAYE relief.

    Returns:
        StatutoryDeductions: Aggregated deductions and net pay.
    """
    nssf = compute_nssf(gross_pay, payroll_date)
    shif = compute_shif(gross_pay, payroll_date)
    ahl = compute_ahl(gross_pay, payroll_date)

    # PAYE is computed on gross minus NSSF employee contribution.
    gross_taxable = max(gross_pay - nssf.employee_contribution, 0.0)
    paye = compute_paye(gross_taxable, payroll_date, insurance_premiums)

    total_employee = round(
        paye.paye_payable + nssf.employee_contribution + shif.employee_contribution + ahl.employee_contribution,
        2,
    )
    total_employer = round(
        nssf.employer_contribution + shif.employer_contribution + ahl.employer_contribution,
        2,
    )
    # Net pay is floored at 0. When statutory minimums exceed gross (e.g. very
    # low earners), the excess deduction must be handled manually by HR.
    net_pay = round(max(gross_pay - total_employee, 0.0), 2)

    return StatutoryDeductions(
        gross_pay=gross_pay,
        paye=paye,
        nssf=nssf,
        shif=shif,
        ahl=ahl,
        total_employee_deductions=total_employee,
        total_employer_contributions=total_employer,
        net_pay=net_pay,
    )


# ---------------------------------------------------------------------------
# Statutory rates API endpoint helper
# ---------------------------------------------------------------------------


def statutory_rates_as_dict(payroll_date: date) -> dict:
    """Serialise the statutory rate configuration for a date as a plain dict.

    Used by the API layer to return current rates to authorised HR clients.

    Args:
        payroll_date: Date to look up.

    Returns:
        dict: Serialisable rate configuration.
    """
    r = get_statutory_rates(payroll_date)
    return {
        "effective_from": r.effective_from.isoformat(),
        "personal_relief": r.personal_relief,
        "insurance_relief_rate": r.insurance_relief_rate,
        "insurance_relief_max": r.insurance_relief_max,
        "paye_bands": [
            {"upper_limit": b.upper_limit, "rate": b.rate} for b in r.paye_bands
        ],
        "nssf": {
            "tier1_limit": r.nssf.tier1_limit,
            "tier2_limit": r.nssf.tier2_limit,
            "rate": r.nssf.rate,
            "legacy_flat": r.nssf.legacy_flat,
            "use_legacy": r.nssf.use_legacy,
        },
        "shif": {
            "rate": r.shif.rate,
            "minimum": r.shif.minimum,
            "label": r.shif.label,
        },
        "ahl": {
            "employee_rate": r.ahl.employee_rate,
            "employer_rate": r.ahl.employer_rate,
            "active": r.ahl.active,
        },
    }
