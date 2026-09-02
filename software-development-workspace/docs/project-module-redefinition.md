# Project Module — Redefinition

Redefining the HRMS Projects module against the old PLM's project-lifecycle
process. Direction: **modernize & improve** — keep the concepts the delivery
team already knows, fix what the old PLM did badly, drop what was redundant.

## The gap this closes

The HRMS Projects module was a deliberate *thin* port: `projects` + `tasks` +
billing milestones + comments/activities, plus the `project_issues` capability
and the Manager Dashboard added recently. The old PLM's project **detail** page
carried far more, none of which was ported:

| Old PLM section | In HRMS today | Verdict |
|---|---|---|
| Overview (progress, phase bars, RAG) | Partial (progress accessor, dashboard) | Extend |
| Project Phases (9-stage, task-derived progress) | ✗ | **Build** |
| Roles & Resources (role buckets + assignments) | `project_team` free-text role | Rework |
| Multiple Managers + Leads | single `project_lead_id` | Rework |
| Budget & Expenses (per-currency, real expenditure) | ✗ (only billing milestones) | **Build** |
| Vendors | ✗ | Build |
| Supplier Material Ordering & Delivery | ✗ | Build (later) |
| Payment Milestones | `project_contract_milestones` ✓ | Reuse |
| PM Tasks / Activities | `tasks` (category) ✓ | Reuse + defaults |
| Issues / Dependencies / Risks register | Issues only (`project_issues`) | Extend |
| Weekly Report / RAG / OKRs | ✗ | **Build** |

## Modernization decisions (what changes from the old PLM, and why)

1. **One phase model, not two.** The old PLM had a master phase catalog
   (`mdl_projects_phases`) *and* a per-project plan (`mdl_projects_plan_phases`
   + `_subs`) — two systems for one idea, and a `Projects.phase` pointer that
   duplicated state. We keep **one** per-project `project_phases` table seeded
   from a canonical 9-stage default, with optional sub-phases as a
   `parent_id`. Tasks attach to a phase. **Progress and status are derived
   from task completion**, never stored (same rule the old PLM used), computed
   in one query, not a per-row accessor.

2. **Real budget vs expenditure.** The old PLM stored budget and expense lines
   per currency as `(int)`-cast strings (decimals truncated). We keep the
   per-currency line model but store proper `decimal(15,2)`, and — the actual
   improvement — the financial panel becomes **Budget vs Actual Expenditure**
   (money going out), separate from the existing **billing milestones**
   (revenue coming in). Two honest, distinct financial views instead of the
   old system's conflation.

3. **Fix the weekly RAG.** The old PLM's "Last Week" RAG was a copy-paste bug
   (it recomputed from *this* week's stats, so both columns always matched).
   We compute each period correctly **and** persist a weekly snapshot
   (`project_weekly_snapshots`) so trend history survives — the old system
   recomputed live and kept nothing, so no real week-over-week trend ever
   existed.

4. **A real register for Issues / Dependencies / Risks.** In the old PLM,
   issues and dependencies hung off *tasks* (surfaced project-wide by joining
   task→project), and "Risks" had a table that was never wired into the UI. We
   give the **project** a first-class register: `project_issues` (already
   shipped) + `project_dependencies` + `project_risks`, each with its own
   lifecycle, shown together on the detail page and rolled into the weekly
   report.

5. **Multiple managers and leads.** Replace the single `project_lead_id` with a
   `project_people` assignment carrying a `role_kind` (manager | lead | member)
   — the old PLM's M:N managers/leads, plus role-bucket resourcing, unified in
   one table rather than three join tables.

6. **OKR flag stays, cleaned up.** The weekly "OKRs / this week's activities"
   selector keyed off a `show_on_weekly_dashboard` boolean on every task
   (default true). We keep a `weekly_okr` flag on project tasks but default it
   **false** and let a PM promote a task to the weekly report — so the report
   is curated, not every task by default.

## Figma alignment (PLM Redesign file, YvjPJbrD3dMehDh13sr0My)

Read via the Figma REST API. The "PLM Redesign" file is a **modern, minimal,
task-centric** vision — and it deliberately does NOT reproduce the old PLM's
heavy detail page:

- **Projects list**: Grid (priority-tagged cards with a progress bar, avatar
  stack, task count, due date) + List view (progress ring, assignees, due date,
  priority, View). Matches the current `ProjectsView.vue` closely.
- **Project detail**: a clean header (breadcrumb `Projects › #PRJ001`, title,
  "created by … 2 days ago", and Deadline / Status / Priority / Project Lead /
  Assignees), then a **horizontal tab bar** with only **Details · Tasks ·
  Comments · Activities**. Details renders Overview / Scope / Objectives prose.
- **Task drawer** (rich): tabs **Description · Sub-tasks · Dependencies ·
  Assisting · Comments · Resources · Activity** with Deadline/Parent/Status/
  Priority/Assignees — the task is where dependencies and resources live.
- Projects also surface as a **tab on the Account (customer) detail** page.

**The tension, stated plainly:** the Figma redesign covers roughly what the
HRMS module already has. The PM-heavy capabilities you prioritized — phases,
roles/resources, budget vs expenditure, the issues/dependencies/risks register,
and the weekly RAG report — are **not in the Figma**. So "Figma-led" would drop
exactly what you asked for.

**Reconciliation (the design decision):** take Figma's **visual shell and
design system** — the header layout, the **horizontal tab bar** (not the old
PLM's cramped left-nav section list), the card/list styling, the task drawer
with its Dependencies/Resources tabs — and **extend the tab bar** with the
PM-heavy capabilities as additional tabs styled to that system. So the detail
page becomes:

`Details · Tasks · Phases · Team & Roles · Budget · Register · Milestones ·
Weekly Report · Activities`

— Figma's look, the old PLM's depth, none of its clutter.

## The redefined entity model

Existing, reused as-is: `projects`, `tasks` (category=project), `project_team`
→ replaced, `project_contract_milestones`, `project_comments`,
`project_activities`, `project_attachments`, `project_issues`.

### projects (extend)
Add: `current_phase_id` (→ project_phases, nullable), `rag` (green|amber|red,
nullable — latest computed), `target_end_date` (the report's headline date,
distinct from `deadline`). Keep `status` but align the vocabulary — map the old
Pending/Ongoing/Completed/On Hold/Support onto the existing
kickoff/in_flight/on_hold/delivered/cancelled (Support → a `is_support` flag +
`has_sla`, since it is a maintenance track, not a lifecycle state).

### project_phases (new)
`id, organization_id, project_id, parent_id (nullable, sub-phase), name, slug,
position, due_on (nullable), created_by, timestamps`. Status & progress derived
from tasks whose `phase_id` points here (and to child sub-phases). Default 9:
Preparation, Planning, Design, Implementation, Testing, Operationalization,
Closure, Training, Support. `tasks.phase_id` (new nullable FK) attaches work to
a phase.

### project_roles (new) + project_people (new)
- `project_roles`: `id, organization_id, project_id, name, position` — the
  named buckets. Default 4: Project Management, Tech Lead, Implementation
  Engineers, Presales (the old default trio + Presales, which the old system
  made you add by hand — a small fix).
- `project_people`: `id, organization_id, project_id, employee_id,
  project_role_id (nullable), role_kind (manager|lead|member), is_lead` —
  one table for managers, leads and role assignments.

### project_budget_lines (new) + project_expenses (new)
Both: `id, organization_id, project_id, label, currency (char 3), amount
decimal(15,2), note, attachment_path (nullable), created_by, timestamps`.
Budget = planned; Expenses = actual spend. Per-currency totals and a
Budget-vs-Actual bar per currency.

### project_vendors (new) + supplier material (later)
- `project_vendors`: `id, organization_id, project_id, vendor_partner_id
  (→ vendor_partners, nullable), name, note`. Reuse the existing
  `VendorPartner` where possible instead of the old free-text `vendor` string.
- Supplier Material Ordering & Delivery: deferred to a later phase; model on
  the old `ordering_and_delivery` set (order + tracker + delivery update +
  logistics contact) when we reach it.

### project_dependencies (new) + project_risks (new)
- `project_dependencies`: `id, organization_id, project_id, task_id (nullable),
  title, description, kind (task|external|vendor|other), depends_on_task_id
  (nullable), status (pending|in_progress|resolved), owner_employee_id
  (nullable), due_on, timestamps`. For kind=task the effective status follows
  the linked task; otherwise its own.
- `project_risks`: `id, organization_id, project_id, title, description,
  likelihood (low|medium|high), impact (low|medium|high), status
  (open|mitigating|closed), mitigation, owner_employee_id, timestamps`. The
  register the old PLM had a table for but never surfaced.

### project_weekly_snapshots (new)
`id, organization_id, project_id, week_start (date), rag, progress_pct,
completed_tasks, total_tasks, open_issues, open_dependencies, notes (json:
okrs, risks digest), created_by, created_at`. One row per project per week,
written when a PM "publishes" the weekly report — giving real week-over-week
history instead of the old live-recompute.

## Computation rules (carried over, corrected)

- **Phase progress %** = completed tasks / all tasks under the phase (+ its
  sub-phases), 0 when none. **Phase status**: Completed when no pending/ongoing
  task remains; Ongoing when any ongoing/completed present; else Pending.
  (Same as old PLM `checkPhaseProgress`/`checkPhaseStatus`.)
- **Project progress %** = completed / (completed + pending + ongoing) tasks —
  the existing accessor already does done/total; align to this denominator.
- **Weekly RAG %** = completed / total project tasks with `starting_date ≤
  period_end`, computed **independently per week** (fixes the old copy-paste).
  RAG colour thresholds: ≥90 green, 60–89 amber, <60 red (configurable).
- **Overdue** stays derived: `deadline < today AND status ≠ completed` — never
  a stored status.
- **Budget vs Expenditure** bar per currency: the larger of budget/spend pinned
  to 100%, the other scaled — but on real decimals, and spend can exceed
  budget (over-run shown in red past 100%), which the old truncating version
  hid.

## Phased build plan

**Phase 1 — Lifecycle backbone. ✅ Done.** `project_phases` (+ sub-phases, `tasks.phase_id`),
`project_roles` + `project_people` (managers/leads/resourcing), the "generate
default phases/roles" actions, and the detail-page tabs for both. Dashboard's
phase-progress and roles surface here.

**Phase 2 — Register + money. ✅ Done.** `project_dependencies` + `project_risks`
(extending the shipped `project_issues` into a full Issues/Dependencies/Risks
register on a "Register" tab), `project_budget_lines` + `project_expenses` (real
budget vs actual, per currency, on a "Budget" tab), `project_vendors`. The
dashboard financial panel now reads real budget-vs-expenditure from these tables
rather than billing milestones.

**Phase 3 — Weekly Report / RAG / OKRs. ✅ Done.** `project_weekly_snapshots`,
the weekly-report builder (live RAG + progress with a this-vs-last-week delta
computed from stored snapshots — fixing the old copy-paste bug at the root,
OKRs from the new `weekly_okr` task flag, key-risks digest, live signal
counters), the "publish this week" action that writes/overwrites the week's
immutable snapshot and stamps `projects.rag`, and a published-history table on
a "Weekly Report" tab. A printable/exportable rendering of the report remains a
later refinement.

**Supplier Material Ordering & Delivery — ✅ Done.** The old four-table
ordering_and_delivery set, modernised: `project_material_orders` (supplier via
vendor_partners, LPO ref, status pending→ordered→in_transit→delivered→cancelled,
due date) with `project_material_order_trackers` (checkpoints),
`project_material_order_updates` (supplier update + delivery-plan status),
`project_material_order_contacts` (logistics), and order documents on
`project_attachments.material_order_id`. Surfaced on an "Orders" tab.

**Still later — PM-activity default generation**, and full inbound-email pull on
the Correspondence tab.

## Reuse vs build summary

- **Reuse:** projects, tasks, billing milestones, comments, activities,
  attachments, project_issues, vendor_partners, the Manager Dashboard endpoint
  (extend its service with phase/role/register/finance rollups).
- **Build:** project_phases, project_roles, project_people, project_budget_lines,
  project_expenses, project_vendors, project_dependencies, project_risks,
  project_weekly_snapshots — 9 tables across 3 phases, each with its detail-page
  tab and dashboard rollup, following the same controller/service/Vue
  conventions already used in the module.
