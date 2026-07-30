# Implementation Plan — Projects, Assets and Travel into the HRMS

Bringing three Plmv2 modules into the HRMS build, and applying the Konvergenz
design system to the resulting application shell.

Scope is these three modules only. The wider 27-module picture is in
`plm-consolidation-plan.md`.

## What each module actually contains

Inspected from Plmv2's migrations and models, not assumed.

### Projects — thin container, heavy dependency

| Table | Columns |
| --- | --- |
| `projects` | name, description, priority (high/medium/low), deadline, lead_id → users, created_by → users, color, opportunity_id (nullable) |
| `project_assignees` | pivot to users |
| `project_comments` | |
| `project_activities` | activity feed |

**`Project hasMany Task`.** The project record itself carries almost nothing —
name, dates, a colour and a lead. All the actual work lives in tasks.

**Porting Projects without Tasks produces an empty shell.** Tasks brings its own
set: `tasks`, `task_assignees`, `subtasks`, `task_dependencies`, `task_comments`,
`task_attachments`. That is six more tables, and it roughly doubles this module.

The `opportunity_id` link is nullable, so Leads and Opportunities are **not**
required and can be deferred.

### Assets — self-contained and the richest of the three

| Table | Notable columns |
| --- | --- |
| `assets` | name, asset_tag, category, brand, model, serial_number, purchase_date/value, status, assigned_to, assigned_date, location, department, condition, lifecycle_stage, vendor, invoice_number, warranty_period/expiry, processor, ram, storage, operating_system, next_maintenance, handover_required, handover_signed_at, procured_date, in_stock_date |
| `asset_requests` | request_number, user_id, category, item, quantity, reason, urgency, status, approved_by, approved_at, asset_id |
| `asset_issues`, `asset_issue_messages`, `asset_request_messages` | issue tracking with threaded messages |

No dependency on any other Plmv2 module. Its `department` and `assigned_to`
map onto existing HRMS concepts, and `handover_required` / `handover_signed_at`
overlap the HRMS `HandoverReport` in the Roster module — worth reconciling
rather than duplicating.

**This is the cleanest module to port first.**

### Travel — self-contained, but weaker than the legacy version

| Table | Columns |
| --- | --- |
| `travel_requests` | request_number, user_id, destination, travel_mode, accommodation, departure_date, return_date, flexible_days, client_id, purpose, business_impact, advance_required, advance_amount, advance_justification, estimated_cost, priority, linked_task, status, submitted_at |
| `travel_expenses` | travel_request_id, category, description, estimated_amount, policy_status, type |

Approval is **single-step** — `status`, `approved_by`, `approved_at`.

The legacy system's travel module was materially richer: per-diem types with
tiers by destination, a CFO → CTO → finance approval chain, and expense
**reconciliation** against receipts after the trip. Plmv2 has none of that.

Two dependencies: `client_id` points at a Plmv2 `Account`, and `linked_task` at
a Task. Both need a decision (below).

---

# Decisions needed before code

Five, all small, all cheaper now than later.

### 1. Tasks — in or out?

Projects without Tasks is a container with nothing in it. Either accept Tasks as
part of the Projects port, or accept that Projects ships as a stub.

**Recommendation:** include Tasks. It is the module's substance.

### 2. Travel — port Plmv2's, or rebuild the legacy version?

Plmv2's is simpler and ports quickly. The legacy one has per-diem tiers, a
three-stage approval chain and post-trip reconciliation — the things that make a
travel module genuinely useful for finance.

**Recommendation:** port Plmv2's schema as the base, then extend it toward the
legacy feature set in a second pass. Do not port a single-step approval and
call it done if finance expects the chain.

### 3. `client_id` on travel requests

Points at a Plmv2 `Account`, which is not being ported. Options: drop the column,
make it free text, or map it to the HRMS `VendorCustomer`.

**Recommendation:** map to `VendorCustomer` if that is the customer record going
forward; otherwise drop it and re-add when Accounts lands.

### 4. Tenancy

Every ported table needs `organization_id` and the `BelongsToOrganization`
concern. Plmv2 has no tenancy at all, so this is added, not migrated.

Decide now whether ported data belongs to one organization or many. Retrofitting
after records exist is significantly harder.

### 5. Identity — `user_id` or `employee_id`?

Plmv2 keys everything to `users` (`lead_id`, `created_by`, `assigned_to`,
`user_id`). The HRMS keys people work to `employees`, with `users` as the login
identity.

**Recommendation:** repoint to `employees` during the port. Asset assignment,
travel requests and project leads are all employee facts, not login facts. Doing
this at port time is free; doing it later is a data migration.

---

# The application shell

The design system already exists — `src/tokens.css`, `src/theme.css`,
`docs/design-system.md`, and a published visual reference. Three modules is the
point at which the shell stops being an HR app with extras and becomes a
multi-domain application.

### Navigation restructure

`AppNav.vue` is already migrated to tokens and uses collapsible groups. Today
those groups are all HR. Adding Projects, Assets and Travel means a top-level
split — People / Work / Assets / Travel — rather than more children under
existing groups.

Worth doing before the modules land, so each new view has a home rather than
being appended.

### A shared module pattern

All three modules need the same shapes the HRMS already has elsewhere:

| Pattern | Reuse from |
| --- | --- |
| List + filter + pagination | `EmployeesView`, `VacanciesView` |
| Detail page with tabbed sections | `EmployeeDetailView` |
| Request → approve → audit trail | Leave approvals |
| Threaded messages | new — assets and travel both need it |
| Status pills | existing badge styles |

Building these as shared components first means three modules ship consistently
rather than diverging. The alternative — building Assets, then copying it twice
— is how the current 50-files-with-hardcoded-hex situation arose.

### Applying the design system

Every new view uses tokens from the outset: zero literal hex, `text-sm` as the
default size, the 4px spacing grid. Two constraints from the contrast audit
apply directly here, because all three modules are status-heavy:

- **Never white text on a success or amber fill** — 2.54:1 and 2.15:1, both fail.
- **Coloured text on its own tinted background is the riskiest pattern**, which
  is exactly what status pills are. Darken the text rather than lightening the
  background.

---

# Phased plan

## Phase 0 — Foundations (before any module)

1. Settle the five decisions above.
2. Extend the permission catalogue with `projects.*`, `tasks.*`, `assets.*`,
   `travel.*` — the RBAC/ABAC layer is new enough that adding resources is cheap.
3. Restructure `AppNav` for multi-domain navigation.
4. Build the shared list/detail/approval components.
5. Fix `notifications.notifiable_id` (uuid → bigint). All three modules want
   notifications, and the table is empty now, so it is free today.

## Phase 1 — Assets

The cleanest module and the one that proves the porting pattern end to end.

- Port 5 tables with `organization_id` added, `assigned_to` repointed to
  `employees`
- Reconcile `handover_*` against the existing `HandoverReport`
- Backend: model, policy, controller, API routes, tests
- Frontend: list view + detail view (2 Vue views, replacing 2 Livewire pages)

Ships independently. Nothing else depends on it.

## Phase 2 — Travel

- Port 2 tables, resolve `client_id`, repoint `user_id` to `employees`
- Reuse the approval components from Phase 0
- Frontend: 1–2 Vue views
- **Second pass:** extend toward the legacy feature set — per-diem tiers,
  approval chain, reconciliation — as a separate increment with its own review

## Phase 3 — Projects and Tasks

The largest phase; roughly the other two combined.

- Port `projects` + 3 supporting tables
- Port `tasks` + 5 supporting tables
- Drop `opportunity_id` for now, or keep it nullable and unused
- Frontend: projects list, project detail, tasks board (3 Vue views, replacing
  3 Livewire pages)

Task dependencies and subtasks make this the most complex data model of the
three — worth landing tasks before the project detail view that renders them.

---

# Sequencing note

You listed the order as Projects → Assets → Travel. I would invert it to
**Assets → Travel → Projects**, for two reasons:

- Assets is self-contained, so the first port is not simultaneously the first
  encounter with a dependency chain. Whatever the port pattern gets wrong,
  it gets wrong on the simplest module.
- Projects drags Tasks — six extra tables — so it is the largest phase, not the
  smallest. Starting there front-loads the risk.

If Projects is needed first for business reasons, that is a fine reason to keep
your order — but plan for Phase 3's effort at the front, not the back.

# What this does not include

- Leads, Opportunities and the wider pipeline. The `opportunity_id` link stays
  nullable and unused.
- Accounts. Referenced only by travel's `client_id`, resolved by decision 3.
- Any Plmv2 module beyond these three.
- Data migration from the running Plmv2 instance. This plan ports **schema and
  behaviour**; moving live records is separate work, and needs Plmv2's tenancy
  question answered first.
