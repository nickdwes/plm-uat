# Assets — where we are against the EAM brief

The Enterprise Asset Management brief asks for ITIL, ISO 55001, ISO 27001, ITAM,
SAM, HAM and FinOps alignment across seven asset classes, with a configurable
workflow engine, thirty-odd integrations, AI failure prediction and a million
managed assets.

This document says, section by section, what exists, what is partly there, what
is deferred and what will not be built as written. It is meant to be read by
whoever wrote the brief, not only by whoever is coding.

**The short version.** The designs describe a coherent asset module for a
company that owns laptops, monitors and a few dozen software licences. The brief
describes a product that competes with ServiceNow ITAM. We are building the
first, with the schema shaped so the second is not foreclosed.

Last reviewed: 2026-08-12.

---

## Built

| Brief section | What exists |
|---|---|
| Core Asset Master | 45 columns: identity, category, class, serial, brand, model, vendor, invoice, warranty, purchase value, dates, condition, location, department, custody |
| Asset Categories — physical | Laptops, monitors, phones, furniture and the rest, as `asset_class = physical` |
| Asset Categories — software, subscriptions | `asset_class = software \| subscription`, with seats, renewal cost, auto-renewal and expiry |
| Asset Lifecycle — deployment through disposal | `asset_events`: procured, in stock, assigned, reassigned, returned, under repair, repaired, lost, retired, disposed — each with date, person, location and vendor |
| Employee Asset Assignment | Assign, reassign and release, with custody ending on loss, retirement and disposal |
| Asset Incident Management | `asset_issues` with priority, status, messages and escalation |
| Subscription Management — renewals | One expiry concept across warranties, terms, certificates and domains, with per-asset notice periods |
| License and Entitlement — counts | Seats purchased against seats used; spare seats derived |
| Asset Mapping — parent/child | `parent_asset_id`, so a dock belongs to a laptop and a seat to a subscription |

## Partly built

| Brief section | What is missing |
|---|---|
| Asset Requisition | `asset_requests` approves in one step. The designs show HR Review → Awaiting Finance → Forward to Procurement, which is a chain, not a flag |
| Service Requests | Repair and replacement arrive as requests or issues. Relocation, loan, return and licence requests have no distinct handling |
| Asset Transfers | Reassignment between people works. Department, branch, country and project transfers have no approval or documentation |
| Core Asset Master — placement | Location is one free-text field. The brief asks for branch, building, floor, room and rack |
| Core Asset Master — roles | One custodian (`assigned_to`). The brief distinguishes asset owner, custodian, business owner and technical owner |

## Not built, and next in line

These are wanted, understood, and simply not done yet. Roughly in order.

1. **Maintenance records.** The designs have a Maintenance tab; there is no
   table behind it. Preventive and corrective schedules, cost, technician,
   history.
2. **Disposal records.** Same — a Disposals tab with nothing behind it. Method,
   approval, proceeds, certificate.
3. **The tabbed shell and Overview dashboard.** Seven tabs and five charts in
   the designs; the current views are two flat lists.
4. **Add Asset and Assign Asset wizards.** Six steps and three steps
   respectively.
5. **Acceptance signature.** `handover_required` and `handover_signed_at`
   exist as columns with no flow attached.

## Deferred, with the trigger that would change it

Each of these is a real requirement. None is being built now, and each has a
condition that would move it up.

| Item | Why not now | What would change it |
|---|---|---|
| Depreciation and finance posting | Needs a chart of accounts this system does not have. Nothing in the designs shows it | Finance asks for an asset register they can book from, or a fixed-asset audit |
| Cloud assets (Azure, AWS, GCP) | Nobody is managing cloud spend in this system yet, and a resource discovered by API is a different lifecycle from a laptop somebody signs for | A cloud bill nobody can attribute, or FinOps becoming somebody's job |
| Identity assets (accounts, secrets, keys) | Overlaps heavily with Entra, which is the system of record | A compliance requirement to evidence privileged account review |
| Property assets (buildings, leases) | Konvergenz leases; there is no portfolio to manage | Property acquisition, or lease accounting |
| Intellectual property | No process exists to attach it to | A patent or trademark filing |
| Naming standards engine | `asset_tag` is free text and nobody has complained | A tagging exercise, or an audit finding duplicates |
| QR / barcode / RFID / NFC | Needs hardware and a mobile scanner app | A physical stock-take being scheduled |
| Asset reviews (quarterly, annual) | The reminder machinery exists; nobody has defined a review cycle | A review cycle being agreed |
| Dependency graphs | Parent/child covers the real cases today | Assets whose failure takes down a service, i.e. servers |

## Will not be built as written

Not refusals — statements that the brief describes more than a module.

- **A configurable workflow engine.** Multi-level, parallel and conditional
  approvals with SLA timers, delegation and escalation, driving every process.
  This system already has approval chains (leave, travel, requisitions, tasks).
  Adding a general engine means either rewriting those onto it or running two
  systems. If workflow configurability is genuinely needed, it is its own
  project across the whole platform, not a feature of Assets.

- **Thirty-plus integrations.** Entra, Intune, SCCM, Jamf, three clouds, VMware,
  Cisco, Fortinet, Palo Alto, SIEM, CMDB, Power BI and the rest. Each is weeks
  of work and a permanent maintenance burden. They should be picked one at a
  time against a named need. Intune or Entra first, because they would populate
  the register automatically, which is the only integration that pays for itself
  immediately.

- **The AI capabilities.** Failure prediction, budget forecasting, duplicate
  detection, orphaned resource detection, natural-language queries. Several are
  genuinely useful and none of them work without data — there are currently
  **zero assets on the register**. Prediction trained on nothing predicts
  nothing. Revisit after a year of real history.

- **One million assets.** Konvergenz has on the order of hundreds. The schema
  will not fall over at ten thousand. Designing for a million now means
  sharding, partitioning and caching that would slow every screen we are
  actually building.

---

## How this document stays true

It is reviewed when a section moves. The reasoning behind each decision lives in
the commit that made it — `git log -- app/Models/Asset` reads as the argument,
this reads as the state. If the two disagree, the commits are right and this is
stale.
