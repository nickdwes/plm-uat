# Assets — implementation plan

What gets built, in what order, and what has to be decided before each part.

Scope is the module in the designs, not the EAM brief. Where the two differ,
[assets-eam-coverage.md](assets-eam-coverage.md) says which sections are
deferred and what would bring them forward.

Two answers already given shape everything here: **physical, software and
subscription** classes only, and **cost tracked, no depreciation**.

Last reviewed: 2026-08-12.

---

## Done — Increment 0: the foundation

Committed and deployed. `asset_class`, parent/child, one expiry concept with
per-asset notice periods, seats for the classes bought in quantity, and
`asset_events` with an `AssetLifecycle` service that keeps the register in step
with the history.

Done first because the table was empty. Every one of those was a column that
day and a data migration once assets are on the register.

---

## Increment 1 — Maintenance, and what disposal actually needs

**Maintenance is a table.** The designs have a Maintenance tab listing type
(preventive or corrective), reason, date, cost and a status that runs *awaiting
procurement → awaiting HR → in progress → completed*. None of that exists.

It hangs off the asset and, where a repair began as a fault report, off the
issue that raised it — so "this laptop has been repaired three times" and "this
fault cost us KES 45,000" are both answerable.

**Disposal probably is not a table.** The Disposals tab lists asset id, serial,
category, purchase date and retired date. Every one of those is already on the
asset or derivable from the `disposed` event. Building a table to hold a
filtered list would be inventing a record to satisfy a screen.

A disposal record earns its place only if somebody needs the method (sold,
donated, scrapped, recycled), the proceeds, an approver and a certificate.

> **Decision needed.** Does disposal need approval and a certificate, or is the
> tab a view over retired assets? Environmental compliance and audit both argue
> for the record; nothing in the designs shows one.

---

## Increment 2 — The tabbed shell and the Overview

Seven tabs — Overview, My Assets, Assets Requests, Employee Assets, Assets
Inventory, Maintenance, Disposals — against two flat views today.

The Overview needs one summary endpoint, not five. Counted from the same rows
the tabs list, so a card and the list beneath it can never tell different
stories: total assets by state, value, licences expiring, returns due,
maintenance due, category spread, allocation by department, maintenance status,
and requests by month.

This is the increment where the class work becomes visible — *licences expiring*
and *seats unused* are the numbers a hardware-only register could never show.

---

## Increment 3 — The two wizards

**Add Asset**, six steps: general, procurement and financial, specifications,
maintenance schedule, documents, assignment. Saves as a draft throughout.

Most fields exist. Missing: purchase order number, delivery date, warranty
provider, MAC address, graphics, screen size, and the maintenance schedule
itself, which arrives with increment 1.

**Assign Asset**, three steps: review the request, pick from available
inventory, confirm. Ends at *pending acceptance* rather than *assigned* — the
asset is not handed over until the person says they have it.

> **Decision needed.** Asset documents — invoice, warranty, delivery note — go
> through `DocumentVault` on their own disk, like every other document class.
> That needs a disk and a container decided before the step is built.

---

## Increment 4 — The request chain, and acceptance

`asset_requests` today is pending → approved → rejected → fulfilled: one
decision by one person. The designs show a chain — *pending → HR review →
awaiting finance → approved*, with **Forward to Procurement** and **Decline with
a reason** at each stage.

Acceptance closes it. The designs' form is short and does real work: *I
acknowledge receipt of the asset in good working condition and agree to the
company asset policy*, with the policy linked and a confirm button. That is what
`handover_required` and `handover_signed_at` were always for.

> **Decision needed, and it blocks this increment.** There is no procurement
> role. The system has `org_hr`, `finance_officer`, `finance_executive`,
> `operations_executive`, `dept_manager`, `account_manager` and `group_admin`.
> Either procurement is one of those wearing a different hat, or a role is
> created. The chain cannot be built until somebody says who stands at each
> stage.

---

## Increment 5 — Renewals

Where the subscription work pays for itself: a licence nobody renewed is a
team locked out on a Monday, and a certificate nobody renewed is a public site
down.

The expiry sweep uses each asset's own notice period, and the notification
machinery already exists — this is a scheduled command and a report, not new
infrastructure.

Seats go here too. Seats paid for and unused is the one number in this module
that hands money back.

---

## Sequencing note

1 and 2 are independent; 2 is more visible and 1 is what makes 2 honest — the
Overview's maintenance chart is empty without it.

3 depends on 1 for the maintenance step. 4 is blocked on the procurement
decision and on nothing else. 5 depends only on increment 0, and could be
brought forward if renewals are pressing.

Nothing here depends on data being imported. The register is empty and every
increment has been built and tested that way.
