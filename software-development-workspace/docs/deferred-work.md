# Deferred work

Everything known to be missing, wrong or postponed, in one place.

This exists because the alternative was worse: these items lived in chat
messages and commit bodies, which meant the only way to answer "what is
outstanding" was to remember. Anything found and consciously not fixed belongs
here on the day it is found.

Per-module coverage against a brief lives beside the module —
see [assets-eam-coverage.md](assets-eam-coverage.md). This file is
cross-cutting: defects, risks and work that spans modules.

Last reviewed: 2026-08-12.

---

## Risks

**Nothing is pushed.** As of this writing the working branch is ~88 commits
ahead of `main` and has never been pushed. Azure DevOps has no credentials in
the build environment; `origin` points at a personal home-directory backup that
would carry far more than this project, including a production database dump.
Everything else in this file is recoverable work. This is not — it exists on one
disk.

**The branch name no longer means anything.** `fix/candidate-cv-privacy`
currently carries the tasks module, attendance, talent requisition, onboarding,
storage encryption and assets.

---

## Known defects

| What | Where | Note |
|---|---|---|
| Form requests trusting their controllers | `app/Http/Requests` | Thirteen requests authorise with `user() !== null`, deferring to the controller's policy check. The structural test permits this. Nobody has verified each controller actually calls `authorize()` — if one does not, that route is open to any signed-in user |
| Breeze auth tests fail | `tests/Feature/Auth` | Seven failures, pre-existing. They test a session flow replaced by Sanctum bearer tokens. Recommended action is deletion, so that a red suite means something |
| Overdue work needs the day to exist | `AttendanceController::overdueFor` | Overdue tasks are shown against an attendance day, and the day is created when somebody reports their location. Anybody who never opens their day never sees the prompt — which is exactly the person you would want prompted |
| Orphaned migration ledger row | production `migrations` | `2026_04_16_000001_enhance_org_compliance_documents` ran in production and its file is not in this repository. `migrate` ignores it; `migrate:rollback` past that point will fail on a missing file. Left deliberately — deleting the row erases the only evidence it ran |

---

## Modules the legacy had and this does not

Found by diffing the PLM dump against the current schema. Row counts are from
the legacy database, and are the reason for the ordering — several legacy
modules were built and never used.

| Module | Legacy volume | Status |
|---|---|---|
| Support / ticketing | 5,700 tickets, 55 MB of email | Deliberately out of scope for now. The last unbuilt module of consequence |
| SLAs and contract management | 55 contracts, 34 SLAs, plus packages, levels and health checks | **Genuine modelling gap.** `account_contracts` exists; there is no SLA concept at all. Support will need it the day it is built |
| Meetings | 13 agendas, with members, tasks and comments | Absent. Tasks carry a `meeting` category but there are no agendas or minutes |
| Ordering and delivery | 27 orders with tracker and logistics contacts | Partial overlap with Travel's deliveries, which are about dispatch rather than a customer order pipeline |
| Timesheets (`work_space`) | 38,629 rows | Deliberately replaced by attendance and task reporting. See below |
| Change requests, test cases, feedback surveys, talent agendas | **0 rows each** | Built in the legacy and never used. Not worth rebuilding |

### The timesheet narratives

`work_space` holds 14,565 sentences describing what was delivered against a
task, 94% of them linked to a task. The `hours` column is populated on **five
rows out of 38,629** — the organisation had already abandoned hour-logging in
practice.

Those narratives are the only part worth keeping, and they belong in Tasks as
delivery history rather than in Attendance as timesheets. They survive the model
change precisely because they never depended on hours. Not imported; no decision
taken.

---

## Feature work not started

- **Onboarding increment 4** — embedded Attendance, Leave, Payroll and
  Performance tabs on the employee profile. Dependents and Vehicles are still
  separate pages rather than tabs.
- **Talent Requisition 2–4** — Create Job Opening wizard with the application
  form builder; candidate journey with Kanban and weighted screening;
  interviews through to pre-onboarding.
- **Assets** — maintenance, disposals, the tabbed shell, the two wizards and
  the acceptance signature. See the coverage document.
- **Configuration** — roles exist with almost no holders.

---

## Standing constraints

- **No data has been imported.** By instruction. Every module has been built
  against an empty database, so "0 rows" is the expected state and not a bug.
- **Entra step-up** is behind a flag until a conditional access policy exists.
- **SharePoint Graph batch job** is designed and not scheduled.
- **The security runbook** has steps that run at migration time, not before.
