# Patterns Worth Borrowing from the Legacy PLM System

A migration proposal, not a decision. Each item lists what exists today, what
the legacy system does differently, the change, and the risk.

Source: `plm.kns.co.ke` — a Fat-Free Framework PHP monolith, 27 modules,
the predecessor to this HRMS. Cloned locally for reference only; nothing in
this proposal depends on that codebase remaining available.

Nothing here has been implemented.

## Summary

| # | Change | Value | Risk |
| --- | --- | --- | --- |
| B1 | Fix `notifications.notifiable_id` type | High | **None** — table is empty |
| B2 | Route recruitment events through `NotificationService` | High | Low |
| A2 | Leave filed on behalf of an employee | Medium | Low |
| A4 | Handover attached to the leave request | Medium | Low |
| A3 | Recall from approved leave | High | Medium |
| A1 | Per-day leave rows | Medium | **High** — touches balance maths |
| A5 | Threaded messages on a leave request | Low | Low |

Recommended order is B1 → B2 → A2 → A4 → A3, deferring A1 and A5.

---

# Proposal A — Leave

## Current state

`leave_requests` already covers more than the legacy system in places:

| Column | Note |
| --- | --- |
| `from_date`, `to_date` | Contiguous range |
| `days_requested` | `numeric(6,1)` — supports halves |
| `is_half_day`, `half_day_period` | **Request-level**, not per-date |
| `scattered_dates` | `json` array — non-contiguous days |
| `status`, `rejection_reason`, `actioned_at`, `approved_by` | Single-step approval |

`leave_balances` is keyed `(employee_id, leave_type_id, year)` and tracks
`entitled_days`, `used_days`, `pending_days`, `carried_days`.

So half-days and scattered dates are **already supported**. The gaps are
narrower than a first read of the legacy schema suggests.

## A1 — Per-day leave rows

**Legacy:** a `leave_days` table, one row per date, each carrying its own
`period`. The request is a header; the days are the truth.

**Here:** the shape is split across three columns — `from_date`/`to_date` for
ranges, `scattered_dates` JSON for non-contiguous days, and a single
`is_half_day`/`half_day_period` pair that applies to the whole request.

**Consequences today:**

- A request cannot be half-day on one date and full-day on another.
- "Who is on leave on 3 March?" cannot be answered with an index. Contiguous
  requests need a range predicate; scattered ones need a JSON scan. Any
  team-calendar or coverage view pays this cost on every query.
- Partial cancellation has nowhere to record itself.

**Change:** add `leave_request_days` — `leave_request_id`, `date`,
`period` (`full` | `am` | `pm`), `status` (`taken` | `recalled` | `cancelled`),
unique on `(leave_request_id, date)`. Backfill by expanding each existing
request; keep the current columns as a denormalised summary during transition.

**Risk: high.** Balance arithmetic in the leave service currently derives from
`days_requested`. Introducing a second source of truth for "how many days" is
exactly the kind of change that silently corrupts balances. It needs the
service refactored in the same change, plus reconciliation across every
existing request before the old columns can be trusted as a cache.

**Recommendation: defer.** Only worth doing when a team-calendar or coverage
view makes the query cost real. A3 does not depend on it — recalls can
reference dates directly.

## A2 — Filed on behalf

**Legacy:** an `on_behalf` relation alongside `employee`.

**Here:** no equivalent. Every request is implicitly self-filed.

**Why it matters:** someone signed off sick cannot file their own leave. HR
files it, and today that is indistinguishable from the employee doing it —
which is wrong in an audit and wrong on screen.

**Change:** add `filed_by_employee_id` (nullable, FK to `employees`). Null
means self-filed. Set it in the submit path when the authenticated user is
not the subject. Surface it in the UI as "Filed by X on behalf of Y".

**Risk: low.** Additive, nullable, no backfill — existing rows are correctly
self-filed by definition.

## A3 — Recall from approved leave

**Legacy:** a `recalled_days` table hanging off the leave request.

**Here:** no equivalent. Approved leave can only be deleted wholesale, which
destroys the record that it was ever taken.

**Why it matters:** recalling someone from approved leave is a routine HR
event, and the days must return to the balance. Without it the options are to
cancel the request — losing the fact that part of it was served — or to adjust
the balance by hand, leaving no audit trail.

**Change:** add `leave_recalls` — `leave_request_id`, `recalled_from`,
`recalled_to`, `days_credited` (`numeric(6,1)`), `reason`, `recalled_by`,
`created_at`. Crediting `leave_balances.used_days` happens in the same
transaction as the insert.

**Risk: medium.** The write itself is simple; the care is in the balance
credit. It must be transactional and must not double-credit if a recall is
edited. Worth a unique constraint on `(leave_request_id, recalled_from)` and a
service method that is the only path to the credit.

**Recommendation: adopt.** Highest-value item in Proposal A. Does not require
A1.

## A4 — Handover attached to the leave request

**Legacy:** `handover_note` and `handover_attachement` (sic) live on the leave
record itself.

**Here:** a `Roster\HandoverController` exists, but handover is not attached to
leave. The two are unconnected.

**Change:** add `handover_note` (text) and `handover_attachment_path`
(nullable) to `leave_requests`; surface both on the approval screen so the
approver sees the cover arrangement at the point of decision.

**Risk: low.** Additive and nullable. The only real question is whether it
should instead relate to the existing Handover model — worth checking that
module's intent before choosing.

## A5 — Threaded messages on a request

**Legacy:** `leave_messages` plus `leave_messages_replies`.

**Here:** a single `rejection_reason` text column.

**Change:** `leave_request_messages` — request, author, body, `created_at`.
One level, no replies-to-replies; the legacy two-table split buys little.

**Risk: low.** Additive.

**Recommendation: defer.** Genuinely useful, but it is a feature rather than a
correctness fix, and it competes with A3 for the same reviewer attention.

---

# Proposal B — Notifications

This is the more consequential half, and cheaper than expected.

## Correction to an earlier claim

An earlier summary suggested this system needed the legacy polymorphic
notification pattern. **It already has it, and a better version** —
`notifications` carries UUID keys, organization scoping, soft deletes,
`read_at`/`sent_at`, a `data` JSON payload, and `notifiable_type` /
`notifiable_id`. There is a `NotificationService` with create, batch, unread,
read-marking and count operations, plus domain helpers for training events.

The problem is not a missing pattern. It is that the pattern is unusable and
partly bypassed.

## B1 — `notifiable_id` is the wrong type

**Finding:** `notifications.notifiable_id` is `uuid`. Every domain primary key
in this schema is `bigint` — `applications`, `leave_requests`, `employees`.

A polymorphic link therefore cannot point at any entity in the system. This is
not theoretical: the table holds **0 rows**, and `notifiable_id` has never been
populated.

**Change:** alter `notifiable_id` to `bigint`, or drop and re-add it. The
notification's own `id` stays UUID — only the target reference changes.

**Risk: none, today.** The table is empty, so there is no backfill and no data
to corrupt. This gets more expensive the moment real notifications land.
It should go first.

## B2 — Recruitment bypasses the notification system

**Finding:** `ApplicationService` writes directly to
`RecruitmentNotification::create([...])`. It does not use `NotificationService`
at all.

`recruitment_notifications` is a parallel table — `application_id`,
`event_type` (constrained to `new_application`, `stage_change`,
`interview_scheduled`, `offer_extended`, `hired`, `rejected`), `payload`,
`sent_at`. It has no `user_id` and no `read_at`.

**Consequence:** recruitment events cannot appear in the user-facing
notification feed. There is no recipient to address them to and no way to mark
one read. The two systems cannot be queried together, so "everything awaiting
me" is not answerable.

**Change:** route recruitment events through `NotificationService::create()`,
with `notifiable_type = Application::class` and `notifiable_id` = the
application id (which requires B1). Keep `recruitment_notifications` as the
outbound-delivery log it effectively is — its `sent_at` and `payload` are a
reasonable record of what was dispatched externally — but stop treating it as
the notification system.

**Risk: low.** Additive to the existing table's behaviour. Recruitment keeps
its audit log; users gain a feed that includes recruitment.

---

# Deliberately not borrowed

**Permissions as an email allowlist.** The legacy `modules/leave/config.json`
carries a `can_access_leave` field holding three literal email addresses.
A `usergroups` model exists, so this is drift rather than design. The Laravel
policy layer here is already better — see the payroll authorization fix.

**Denormalised duplicate columns.** The legacy leave record stores both
`approved_by` and `approved_by_email`, both `employee` and `employee_email`.
Two sources of truth that drift the moment someone's address changes.

**Money as text.** Travel costs stored `VARCHAR`, reconciliation amounts
`INT`. Any currency added here should be integer minor units or `numeric`.

**Typos frozen into schema.** `user_login_attemtps`, `reconsiliation`. Cheap
to avoid, permanent once shipped.

**Runtime-writable config files.** The legacy app refuses to boot unless its
`config.json` is writable, conflating configuration with mutable state.

---

# Also worth noting

Two legacy ideas outside the scope of this proposal, recorded so they are not
lost:

**`clock_in` has an `automatic_end` flag** and a confirmation-key/expiry pair.
The work-location check-in feature here has the same check-in/check-out spine
but no answer to the obvious failure mode: people forget to check out. Worth
revisiting when that feature sees real use.

**Login attempt tracking.** The legacy `login_attempts` table records IP,
username, a counter and last-attempt time. This system has no rate limiting on
`api/login`, and `routes/auth.php` — which defines forgot-password and
reset-password — is never registered in `bootstrap/app.php`, so those endpoints
do not exist. Related, and worth folding into whatever addresses password
reset.
