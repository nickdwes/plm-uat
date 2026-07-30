# PLM Consolidation Plan

Merging the HRMS build with Plmv2 to reach the module coverage of the legacy
`plm.kns.co.ke` system.

Based on a full inventory of all three codebases. Scope is argued from the gap
analysis rather than assumed up front.

## The headline

**The two live codebases are largely complementary, not competing.** The HRMS
owns the people domain and exceeds the legacy system there by a wide margin.
Plmv2 owns the commercial and delivery domain and likewise exceeds legacy.

The genuine overlap is **five modules**, and only two of them are contentious.

That makes this a merge, not a rewrite — but a merge with one large caveat: the
two systems have incompatible UI architectures, so roughly half of Plmv2 ports
and half must be rebuilt.

## The three codebases

| | Legacy `plm.kns.co.ke` | Plmv2 | HRMS (your build) |
| --- | --- | --- | --- |
| Stack | Fat-Free PHP | Laravel 12 + Livewire | Laravel 11 + Vue 3 SPA |
| Models | ~180 across 27 modules | 40 | **92** |
| Controllers / pages | 27 modules | 26 Livewire pages | 51 controllers, 49 Vue views |
| Migrations | none (in-model schema) | 59 | Laravel migrations |
| API | none | **none** | `/api/v1` + Sanctum |
| Multi-tenancy | none | **none** | `organization_id` throughout |
| AuthZ | email allowlists | Spatie v8 | RBAC + ABAC catalogue |
| SSO | Graph subscriptions | none | Azure SSO |
| Status | superseded | active | active |

---

# Three-way module matrix

Legend: ✅ covered · ⚠️ partial · ❌ absent · ✅✅ exceeds legacy

| Legacy module | Plmv2 | HRMS | Verdict |
| --- | --- | --- | --- |
| **human_resource** | ⚠️ HR fields on `users` | ✅✅ `Employee` + 8 related | **HRMS** |
| **org** | ❌ | ✅✅ `Organization`, functional units, business units, practices | **HRMS** |
| **bu** | ❌ | ✅ `OrgBusinessUnit`, `OrgPractice` | **HRMS** |
| **talent** | ❌ | ✅✅ Goals, OKR cycles, performance reviews | **HRMS** |
| **projects** | ✅✅ Leads → opportunities → bids/proposals/negotiations → projects, research | ❌ | **Plmv2** |
| **accounts** | ✅ `Account` + contacts, sectors, types | ⚠️ `VendorCustomer` (different angle) | **Plmv2** |
| **tasks** | ✅ Task, subtask, dependencies, comments, attachments | ❌ | **Plmv2** |
| **asset_management** | ✅ Assets, requests, issues, messages | ⚠️ `OnboardingAsset` only | **Plmv2** |
| **leave** | ✅ + multi-stage approvals, threaded messages | ✅ + scattered dates, carry-over | **overlap — decide** |
| **work_space** | ⚠️ `TimeEntry` | ⚠️ `WorkLocationCheckIn` | **overlap — merge** |
| **notifications** | ✅ + per-user preferences | ⚠️ present but broken | **overlap — HRMS + borrow** |
| **user** | ✅ Spatie v8 | ✅ RBAC + ABAC catalogue | **overlap — HRMS** |
| **dashboards** | ⚠️ one page | ✅ HR dashboard | **overlap — both extend** |
| **travel_request** | ⚠️ request + expenses | ❌ | **Plmv2 + gaps** |
| **contract_management** | ⚠️ `Contract` only | ❌ | **Plmv2 + gaps** |
| **microsoftgraph** | ❌ | ⚠️ SSO only, no subscriptions | **gap** |
| **change_request** | ❌ | ❌ | **gap — 21 models** |
| **tickets** | ❌ | ❌ | **gap — 14 models** |
| **meetings** | ❌ | ❌ | **gap** |
| **tender_checklist** | ❌ | ❌ | **gap** |
| **software_test_case** | ❌ | ❌ | **gap** |
| **requests** | ❌ | ❌ | **gap** |
| **ordering_and_delivery** | ❌ | ❌ | **gap** |
| **feedback** | ❌ | ❌ | **gap** |
| **todo** | ❌ | ❌ | **gap** |
| **konvergenz_campaigns** | ❌ | ❌ | **gap** |
| **cron_jobs** | n/a | n/a | not needed — Laravel scheduler |

### Totals

- **HRMS already covers 4** legacy modules, three of them far beyond the original.
- **Plmv2 already covers 4** more, two far beyond the original.
- **5 overlap** and need reconciliation.
- **2 are partial** in Plmv2 and need completing.
- **11 exist in neither** and are net-new builds.

So a merge of the two gets you to roughly **13 of 27** legacy modules, most of
them better than the original. The remaining 11 are new work, and two of them
are large.

---

# Direction: HRMS is the base

Plmv2's domain should be ported **into** the HRMS, not the reverse. This is not
a preference — four properties only exist on one side, and each would have to be
built from scratch on the other:

1. **Multi-tenancy.** The HRMS scopes everything by `organization_id`. Plmv2 has
   no tenant concept at all. Retrofitting tenancy across 59 migrations after
   data exists is materially harder than porting into a system that already has
   it.
2. **API.** The HRMS has `/api/v1` behind Sanctum. Plmv2 has no API surface
   whatsoever — 131 lines of session-authenticated routes.
3. **Authorization.** The HRMS has the RBAC + ABAC layer with a permission
   catalogue, ownership, reporting line and tenancy checks. Spatie has no
   org-scoped roles out of the box.
4. **Identity.** Azure SSO lives on the HRMS side.

The HRMS is also simply larger — 92 models against 40.

## What ports and what rebuilds

| Layer | Ports? | Notes |
| --- | --- | --- |
| Models | **Yes** | Same Eloquent. Add `organization_id` + the tenancy trait |
| Migrations | **Yes** | Rename where they collide, add tenant columns |
| Business logic | **Mostly** | Where it sits in models/services |
| Livewire pages | **No** | 26 pages must be rebuilt as Vue views |
| Blade views | **No** | Replaced by Vue components |
| Spatie roles | **No** | Map onto the HRMS permission catalogue |

**Roughly half of Plmv2 is a rebuild.** The domain layer transfers; the entire
presentation layer does not. Any estimate that treats this as "copy the modules
across" is wrong by about half.

---

# The five overlaps

## 1. Leave — the contentious one

Both implement leave, in tables with **identical names** (`leave_types`,
`leave_requests`, `leave_request_messages`, `leave_balances`) and near-identical
columns. Neither is a superset.

| | Plmv2 has | HRMS has |
| --- | --- | --- |
| Approvals | multi-stage (`leave_approvals`) | single `approved_by` |
| Messages | threaded | `rejection_reason` only |
| Dates | contiguous + `half_day_start` | + `scattered_dates`, carry-over |
| Tenancy | none | org-scoped |

**Recommendation:** HRMS schema wins (it has tenancy and the richer date model);
absorb Plmv2's multi-stage approvals and threaded messages into it. That is
exactly what `docs/plm-borrowed-patterns.md` already proposes on independent
grounds, so the two efforts converge.

## 2. Time tracking

HRMS `WorkLocationCheckIn` (office/home/customer site) versus Plmv2 `TimeEntry`
(with location and attendance type, linked to tasks). These are the same concept
approached from different ends — attendance versus billable time.

**Recommendation:** merge into one model with both facets. Adopt legacy's
`automatic_end` while doing it — it solves the failure mode both versions share,
that people forget to check out.

## 3. Notifications

HRMS has the better design (polymorphic, org-scoped, soft deletes) but it is
**currently unusable**: `notifiable_id` is `uuid` while every domain key is
`bigint`, and the table holds zero rows.

**Recommendation:** fix that first — it is free while the table is empty — then
adopt Plmv2's per-user `notification_preferences`.

## 4. Authorization

Spatie v8 versus the HRMS catalogue. Both express `resource.action`, so the
vocabulary transfers even though the storage does not.

**Recommendation:** HRMS layer wins on org-scoping. Map Plmv2's roles onto the
catalogue and extend it with the commercial permissions (`opportunities.*`,
`projects.*`, `accounts.*`, `assets.*`).

## 5. Dashboards

Both have one. Not contentious — merge the widgets.

---

# Phased plan

Each phase should land and be verifiable on its own.

### Phase 0 — Foundations (prerequisite)

Small, high-leverage, and blocks everything after it.

1. Fix `notifications.notifiable_id` (free now, expensive later).
2. Extend the permission catalogue with commercial resources.
3. Decide the tenancy answer for Plmv2 data: is it one organization or many?
4. Constrain identity — one reporting line is authoritative
   (`employees.reports_to`), and Plmv2's free-text `users.employee_id` becomes a
   validated reference.

### Phase 1 — Port the uncontested Plmv2 domain

Models and migrations only, each gaining `organization_id`. No UI yet.

`accounts` → `tasks` → `assets` → `projects` (largest, and depends on accounts).

Verifiable by tests against the ported models before any screen exists.

### Phase 2 — Rebuild the Plmv2 UI as Vue

26 Livewire pages become Vue views against the new API endpoints. This is the
single largest block of work in the plan.

Sequence by dependency: accounts → leads → opportunities → projects → tasks →
assets.

### Phase 3 — Reconcile the overlaps

Leave first (absorb multi-stage approvals and threaded messages), then time
tracking, then dashboards. Each needs a data migration plan, not just a schema
change.

### Phase 4 — Complete the partials

`travel_request` — add per-diem tiers, the CFO/CTO/finance chain, reconciliation.
`contract_management` — add OEM, SLA levels, packages, health checks.

### Phase 5 — Net-new modules, by value

Nothing here exists in either codebase. Ranked by size and likely value:

| Module | Size | Note |
| --- | --- | --- |
| `change_request` | 21 models | 4-stage governance workflow. Largest single build |
| `tickets` | 14 models | Support desk with email threading, SLA, surveys |
| `meetings` | 5 models | Agendas, members, tasks |
| `tender_checklist` | 5 models | Sections, items, owners |
| `software_test_case` | 4 models | Categories, steps, results |
| `requests` | 4 models | Equipment requests, policies |
| `ordering_and_delivery` | 4 models | Order tracking, logistics |
| `feedback`, `todo`, `campaigns` | 1 each | Small |
| `microsoftgraph` | 2 models | Graph subscriptions — extends existing SSO |

None of this ports from legacy: it is Fat-Free PHP with schema declared in
models. The legacy code is a **specification**, not a source. Read it for the
domain rules — especially `change_request`'s approval chain and `travel_request`'s
per-diem logic — and rebuild.

---

# Honest assessment

**Phases 0–1 are weeks.** The domain layer genuinely ports.

**Phase 2 is the hidden cost.** Rebuilding 26 Livewire pages as Vue views is
comparable to building the HRMS frontend again, and it is unavoidable — Livewire
and a Vue SPA cannot coexist in one interface without running two applications.

**Phase 5 is quarters, not weeks.** `change_request` and `tickets` alone are 35
models of new domain logic.

Reaching full legacy parity is a large programme. Reaching *useful* parity —
phases 0 through 3, giving one tenant-aware system with people, commercial
pipeline, projects, tasks and assets — is a much smaller and more defensible
goal.

## Not yet decided

- Whether Plmv2 serves one organization or many (blocks phase 0).
- Whether legacy parity is genuinely required, or whether the 11 missing modules
  were simply present rather than used. Worth checking usage on the live system
  before committing to rebuild any of them.
- What happens to the running Plmv2 during the merge — frozen, or developed in
  parallel and reconciled later.
