# PLM rebuild — evidence-based plan

Supersedes the scoping in `plm-modules-implementation-plan.md`, which was
written from reading code. This one is written from the production database:
`plm.sql`, 235 tables, 214 MB. Where the two disagree, this document wins,
because the legacy code shows what was *built* and the data shows what was
*used* — and on this system those differ enormously.

## The headline

Sixty of the 235 legacy tables hold no data at all. Whole subsystems were
built and never once used. Porting the legacy feature set faithfully would
mean rebuilding things its own users rejected.

| Domain | Tables | Hold data | Notes |
| --- | --- | --- | --- |
| Projects | 37 | 30 | but the financial machinery is near-empty |
| Assets | 29 | 17 | disposal and repair workflows unused |
| Tasks | 14 | 14 | the only domain used in full |
| Accounts | 11 | 9 | customer master, genuinely used |
| Travel | 11 | 9 | reconciliation unused |

## What the data says, domain by domain

### Tasks is the product

| Table | Rows |
| --- | --- |
| `tasks_comments` | 63,788 |
| `tasks` | 9,055 |
| `tasks_sub` | 750 |
| `tasks_delegations` | 354 |
| `tasks_escalations` | 212 |
| `tasks_depencancies` | 203 |

Against 70 projects and 70 accounts. Whatever the system was designed to be,
what people used it for was tasks and the conversation around them — seven
comments per task. Any rebuild that treats Tasks as a sub-feature of Projects
inverts the actual usage.

Three findings shape the design:

**Most tasks are standalone.** The legacy `tasks` table carries twelve
nullable foreign keys — `project`, `opportunity`, `action_plan`,
`project_plan_phase`, `research`, `account`, `meeting`, `ticket`, `checklist`,
`pm_tasks` and more. In practice:

- 6,243 tasks (69%) link to nothing
- 2,094 link to one thing
- 718 link to two

Twelve columns to express a relationship that two thirds of rows do not have.

**The workflow is three states.** `Completed` (8,244), `Ongoing` (486),
`Pending` (325). Priority is High/Medium/Low. No approval states in practice,
despite `approved`, `approved_by` and `approved_on` columns existing.

**91% of tasks are complete.** This is as much a record of work done as a
tracker of work outstanding, which matters for how the list views are built:
the default view should not be "everything".

### Travel — the chain is real, reconciliation is not

102 requests, and **all 102 passed through all four approval stages**:
HOD → CTO → Finance → CFO, each with status, comment, approver and date.

That settles the open question. Porting Plmv2's single-step approve/reject
would remove a control finance applied to every trip without exception.

But `travel_r_reconsiliation` has **zero rows**. Post-trip receipt
reconciliation was built and never used. Earlier advice in this repository —
including mine — recommended porting it. The data says do not.

Per-diem tiers were used: `travel_r_d_tiers` and `travel_r_p_types` hold
configuration with amounts by tier and currency.

94 of 102 requests name a client, so the customer link is not optional in
practice.

### Assets — we lack the part they used

Our build has five tables. The legacy has 29, of which 17 hold data. The
unused twelve are the entire disposal workflow, the entire repair workflow,
`assets_issues` and `assets_locations`.

What they did use, and we lack, is **custom characteristics**:
`assets_characteristics`, `_options`, `_item_characteristics`,
`_item_characteristics_options` and `_characteristics_history` — a per-category
attribute system, so a laptop and a vehicle can carry different fields without
schema changes. 646 assets are described through it.

### Accounts — a customer master, lightly used

70 accounts with contacts, plus lookups for country, sector, subsector, type
and department. `accounts_u` and `accounts_users_login_history` suggest
customers had portal logins; that is out of scope here.

### Projects — a thin container with aspirational finance

70 projects. The surrounding machinery is mostly empty or near-empty:
`projects_budget` (229 bytes), `projects_expenses` (946 bytes),
`projects_bids` (7 KB), `projects_phases` (1.2 KB). Seven project tables hold
nothing at all, including the risk register.

`projects_opportunities` is the exception at 130 rows — the pipeline was real.

## What "improves on what is currently available"

Not more features. The legacy system's problem is that it has too many, and
the data shows which ones nobody wanted. Improvement here means fewer, better
modelled, and honestly scoped.

### 1. One parent link, not twelve

```
tasks
  taskable_type   -- Project | Opportunity | Account | Meeting | Ticket | null
  taskable_id
```

A single nullable polymorphic association replaces twelve nullable columns.
Standalone tasks — the majority — simply leave it null, rather than leaving
eleven columns null and one set.

The 718 tasks with two parents need a decision: in every case one is the
primary and the other is context. Inspect before migrating rather than
inventing a rule.

### 2. Assignment as a relation

Legacy has `assigned_to` as a single integer, then bolts on
`tasks_delegations`, `tasks_helping` and `tasks_collaborations` to express
everything a single column cannot.

```
task_assignments
  task_id
  employee_id
  role        -- owner | collaborator | delegate | helper
  assigned_by
  assigned_at
```

One table, four roles, and delegation history becomes a query rather than a
separate subsystem.

### 3. Status as an enum with history

Three states, recorded as `varchar` today with no history on the task itself.
An enum plus a `task_status_history` row per transition gives the audit trail
the rest of the HRMS already has, and makes "how long did this sit in
Ongoing?" answerable.

### 4. Comments are the primary artefact — design for them

63,788 comments is more rows than everything else combined. They need
pagination, indexing on `(task_id, created_at)`, and to be treated as a first
class activity stream rather than an afterthought on a detail page. Legacy
splits `tasks_comments` and `tasks_comments_replies`; one table with
`parent_id` handles both.

### 5. Drop what was never used

Not ported: travel reconciliation, asset disposal, asset repair workflow,
project risk register, bid negotiation notes, discount workings, order
logistics contacts, project tags.

If any of these are wanted, they should be specified fresh rather than
resurrected — a design nobody used is not a requirement.

### 6. Tenancy and identity, applied at port time

Every ported table gets `organization_id` and the `BelongsToOrganization`
concern. Every person reference points at `employees`, not `users`, per the
identity decision already taken. Both are free now and a data migration later.

### 7. Permissions from the catalogue

`projects.*`, `tasks.*`, `assets.*` and `travel.*` already exist in the
permission catalogue. Every controller gates on them from the first commit,
rather than joining the eleven controllers still gated by role name.

## Phasing

Ordered by dependency and by evidence of value, not by the original plan's
sequence.

**Phase A — Accounts (customer master).** 5 tables: `accounts`,
`account_contacts`, and lookups for type, sector, subsector. Blocks Travel.
Small, and the least ambiguous port in the set.

Customers are **per organisation**, so `accounts` carries `organization_id`
and the `BelongsToOrganization` concern like everything else.

Still open: what becomes of the existing empty `vendor_customers`, which models a
partner's customers and will otherwise sit alongside this as a second thing
called "customer".

**Phase B — Travel.** See "Travel design" below; the decisions that were open
when this document was first written have since been made, and they change the
shape enough to be worth setting out properly.

**Phase C — Asset characteristics.** Extend the existing Assets module with
the per-category attribute system. This is additive to something already
shipped and closes the real gap against the legacy.

**Phase D — Tasks.** The largest and highest-value phase. Tasks, assignments,
comments, subtasks, dependencies, escalations, categories, attachments.

**Phase E — Projects.** A container for tasks, with phases and milestones.
Explicitly excludes budget, expenses, bids and estimates until someone asks
for them with a use case — the legacy versions hold almost no data.

Opportunities are a separate decision. 130 rows says the pipeline was used;
whether it belongs in this system or a CRM proper is a product question.

## Before Phase A: the shared component layer

Phase 0 item 4 remains outstanding. Assets shipped bespoke; Travel would be
the second copy and Tasks the third. The list/filter/pagination shell, detail
shell, approval trail, status pill and comment thread should be extracted from
Assets before Travel starts.

The comment thread in particular is not optional: Tasks needs it at
63,788-row scale, and Assets already has two message tables that want the same
component.

## Migration of legacy data

Not addressed here, deliberately. Whether 9,055 tasks and 63,788 comments come
across, or the new system starts clean with the old one kept read-only, is a
business decision with very different costs. It should be decided before
Phase D, not during it.

## Travel design

### Decisions taken

| Question | Answer |
| --- | --- |
| Customer scope | Per organisation |
| Approval chain | Two stages: HOD (head of department), then Finance |
| Allowance model | Rate card by grade, destination and activity, effective-dated |
| Legacy data migration | Deferred |

The approval chain is **shorter than the legacy system's**, not longer. The old
system ran HOD → CTO → Finance → CFO on all 102 requests; the replacement drops
CTO and CFO. That is a deliberate reduction, and worth recording as such so it
is not later mistaken for an incomplete port.

### Approvals as rows

```
travel_approvals
  travel_request_id
  stage                 hod | finance
  sequence              1, 2
  approver_employee_id
  status                pending | approved | rejected
  comment
  decided_at
```

The legacy encoded four stages as sixteen columns on the request. Storing them
as rows means changing the chain — adding a stage, reordering, making one
conditional on amount — is configuration rather than a migration. Given the
chain has just changed once, it will change again.

The HOD approver resolves from `departments.lead_employee_id` for the
requester's department, which already exists. Finance resolves from the
permission catalogue rather than a named list, so it follows role assignment
like everything else.

### The rate card

Allowances vary by **grade**, **destination** and **activity**, and change over
time. All three vary independently, so they are three dimensions of one table
rather than three tables.

```
travel_expense_categories
  organization_id, name, code, requires_receipt, is_active
  -- transport, meals, accommodation, facilitation, incidentals

travel_destinations
  organization_id, name, country, city, is_active

travel_allowance_rates
  organization_id
  category_id           -> travel_expense_categories
  job_grade_id          nullable  -- null: applies to every grade
  destination_id        nullable  -- null: applies to every destination
  amount, currency
  unit                  per_day | per_trip
  effective_from        date
  effective_to          date, nullable
```

Grade comes from the employee's designation
(`employee_organization_map.designation_id` → `designations.job_grade_id`),
so nothing new is needed to answer "what is this person entitled to".

**Resolution.** For each category, take the effective-dated row matching the
traveller's grade and destination, preferring the most specific:

1. grade **and** destination both match
2. destination matches, grade null
3. grade matches, destination null
4. both null — the default

Nulls mean "applies to all" rather than "unset", which is what lets an
organisation state one meals rate globally and override it for two expensive
cities without enumerating every combination.

**Effective dating is the point.** A request approved in March must keep
March's rates when someone opens it in November. Rates are therefore never
edited in place: a change closes the current row with an `effective_to` and
inserts a new one.

### Snapshotting

```
travel_request_items
  travel_request_id
  category_id
  quantity              days, or 1 for per-trip
  rate_applied          the amount resolved at submission
  amount_claimed
  amount_approved       nullable, set by Finance
```

`rate_applied` is copied onto the item at submission and never recalculated.
Without it, correcting a rate silently rewrites the value of every historical
request that used it — the kind of change that is invisible until someone
audits a payment.

Claimed and approved are separate because Finance can approve less than was
asked for, which the legacy schema could not express.

### What is deliberately not built

Reconciliation. The legacy `travel_r_reconsiliation` table holds zero rows
across 102 requests, so the receipts-against-advance workflow was built and
never used. `requires_receipt` on the category is kept so the option exists,
but no reconciliation workflow is built until someone asks with a use case.
