# Plmv2 Integration Study

Assessment of integrating the HRMS with `Konvergenz-Devops-Repo/Plmv2`.

Findings and options, not a decision. Four things need deciding before any
integration code is written, and one of them determines the shape of everything
else.

## What Plmv2 is

A Laravel 12 / Livewire application — the commercial and delivery side of the
business:

- **Pipeline** — accounts, contacts, leads, opportunities, bids, proposals,
  negotiations, contracting
- **Delivery** — projects, tasks, subtasks, dependencies, comments,
  attachments, research
- **Operations** — assets and asset issues, contracts, time entries

Last commit 2 July 2026, 5 commits, 59 migrations, 305 files. Built from the
`laravel/livewire-starter-kit`.

## Stack comparison

| | Plmv2 | HRMS backend |
| --- | --- | --- |
| Framework | Laravel 12 | Laravel 11.31 |
| PHP | ^8.2 | 8.3 |
| UI | Livewire (server-rendered) + Flux/Volt | Vue 3 SPA |
| Styling | Tailwind 4 | Tailwind 4 |
| Permissions | `spatie/laravel-permission` v8 | Hand-rolled catalogue |
| Auth | Session only | Sanctum tokens + Azure SSO |
| API surface | **None** | `/api/v1/*` |
| Multi-tenancy | **None** | `organization_id` throughout |

The shared stack means models, migrations and policies port directly. The
divergences below are where the work is.

---

# The four decisions

## 1. Plmv2 has no API. The HRMS does.

Plmv2 exposes `routes/web.php` and `routes/auth.php` only — 131 lines total,
all session-authenticated Livewire. No Sanctum, no `apiResource`, no `/api`
prefix, no token guard.

The HRMS already has the opposite: a versioned `/api/v1` surface behind Sanctum,
plus Azure SSO.

**This settles the direction of integration.** The HRMS is the service; Plmv2 is
the client. Building an API on Plmv2 to be consumed by the HRMS would mean
constructing from scratch what already exists on the other side.

Two things are missing for Plmv2 to call the HRMS:

- **Service-to-service credentials.** HRMS tokens are Sanctum personal access
  tokens tied to a user, and `AuthenticatedSessionController` revokes all prior
  tokens on login — so a machine token issued to a real user would be destroyed
  the next time that person signs in. A dedicated service account, or client
  credentials, is needed.
- **An organization to act as.** Every HRMS API call resolves tenancy from the
  `X-Organization` header. Plmv2 has no organization concept at all, so it must
  be pinned to one — see decision 3.

## 2. Both systems implement leave, in tables with the same names

This is the sharpest collision. Plmv2's `rebuild_leave_tables` migration creates
`leave_types`, `leave_requests`, `leave_request_messages` and `leave_balances` —
the same names the HRMS uses, with near-identical columns.

| Concept | Plmv2 | HRMS |
| --- | --- | --- |
| Request | `employee_id`, `leave_type_id`, `start_date`, `end_date`, `half_day_start`, `requested_days`, `status`, `reason`, `attachment_path`, `submitted_by` | `employee_id`, `leave_type_id`, `from_date`, `to_date`, `is_half_day`, `half_day_period`, `days_requested`, `status`, `reason`, `scattered_dates` |
| Balance | `allocated_days`, `used_days`, `pending_days` | `entitled_days`, `used_days`, `pending_days`, `carried_days` |
| Approvals | `leave_approvals` (multi-stage) | `approved_by` on the request (single-step) |
| Messages | `leave_request_messages` | none |

Neither is a subset of the other. Plmv2 has multi-stage approvals and threaded
messages; the HRMS has scattered dates and carry-over.

**Two systems of record for one fact.** If an employee's leave can be approved
in either place, balances diverge and neither is trustworthy. The same applies
to `travel_requests`/`travel_expenses` and `time_entries`, which also exist on
both sides in some form.

Options:

- **HRMS owns leave.** Plmv2 drops its leave module and reads from the HRMS API.
  Cleanest, but Plmv2 loses multi-stage approvals and threaded messages unless
  the HRMS gains them — which is exactly what the PLM patterns proposal
  recommends adopting anyway.
- **Plmv2 owns leave.** Rejects the HRMS's stronger leave model and its
  multi-tenancy.
- **Both, synced.** Requires conflict resolution for approvals racing in two
  places. Not recommended.

The table-name collision also rules out sharing a database outright.

## 3. Multi-tenancy is a structural mismatch

The HRMS scopes everything by `organization_id`, resolved from the
`X-Organization` header, with roles assigned per organization.

Plmv2 has **no tenancy at all** — no `organization_id`, no tenant column
anywhere. The nearest thing is a free-text `company` string on `users`.

So any data flowing from Plmv2 into the HRMS arrives with no tenant. Either:

- Plmv2 is pinned to exactly one HRMS organization (simplest — correct if
  Plmv2 only ever serves one entity), or
- Plmv2 gains a tenancy concept, which is a substantial retrofit across all
  59 migrations.

Decide this before any data crosses. Retrofitting tenancy after records exist is
materially harder than before.

## 4. Identity does not line up

Both systems have `users`, but they model people differently.

**Plmv2** carries HR attributes directly on the user: `employee_id`,
`department`, `job_title`, `company`, `employee_type`, `status`, `date_joined`,
`reports_to`. Critically, `employee_id` is a **nullable string** — free text, no
foreign key, no uniqueness — and `reports_to` is a foreign key to **`users`**.

**HRMS** keeps a separate `employees` table linked to `users` via
`employees.user_id`, with `reports_to` a foreign key to **`employees`**.

Consequences:

- The reporting lines are different graphs. The ABAC checks in
  `docs/authorization.md` walk `employees.reports_to`; Plmv2's walks
  `users.reports_to`. They can disagree.
- `users.employee_id` being an unconstrained string makes it a weak join key.
  If it is intended as the link to HRMS employees, it needs a uniqueness
  constraint and validation — otherwise the mapping silently fails for typos
  and blanks.

**Recommendation:** treat the HRMS `employees` table as the system of record for
people, and make Plmv2's `users.employee_id` a validated reference to it.
Plmv2's HR attributes then become a cache, refreshed from the HRMS, not
independently editable.

---

# Also worth reconciling

**Permissions diverge.** Plmv2 uses `spatie/laravel-permission` v8; the HRMS
uses the hand-rolled catalogue in `App\Support\Authorization\Permission`. Both
express `resource.action` permissions, so the *vocabulary* can be shared even if
the storage is not. Worth aligning the permission strings now, while the HRMS
catalogue is new and Plmv2's role set is still small — it costs little today and
gets steadily more expensive.

Note that Spatie has no notion of org-scoped roles out of the box, which is
another reason tenancy (decision 3) should be settled first.

**Notifications.** Plmv2 has a dedicated `app/Notifications` directory, a
`notifications` table and `notification_preferences` on users. The HRMS has a
polymorphic `notifications` table that is currently unusable — `notifiable_id`
is `uuid` while every domain key is `bigint` — and holds zero rows. Before
building cross-system notifications, fix that (see
`docs/plm-borrowed-patterns.md`, item B1). Plmv2's per-user preference model is
worth borrowing regardless.

**Two-factor auth.** Plmv2 has `two_factor_secret`, `two_factor_confirmed_at`
and recovery codes on `users`. The HRMS has none, and also has no login rate
limiting and no working password reset. If identity is ever shared, the weaker
side sets the security floor.

---

# Suggested sequence

1. **Decide the tenancy question** (3). Everything else depends on it.
2. **Decide who owns leave** (2), and travel and time entries with it.
3. **Establish identity mapping** (4) — constrain `users.employee_id`, pick one
   reporting line as authoritative.
4. **Add service-to-service auth to the HRMS API** (1) — a service account or
   client credentials that login cannot revoke.
5. Only then write integration code.

Steps 1–3 are decisions and schema work, not integration code. Attempting step 5
first produces a sync layer that encodes whichever ambiguity was left unresolved.
