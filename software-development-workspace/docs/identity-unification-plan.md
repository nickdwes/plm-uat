# Identity Unification Plan

Merging `employees` and `users` into a single person identity, so one record
both signs in and is attributed on every action — the Entra / Office 365 model.

Four decisions, then a phased execution. Nothing here is implemented.

## Why now

The schema is large but the data is not: **1 employee, 2 users**. Every cost
below is a code and schema refactor today. Once real staff records exist it
becomes a data migration with downtime, and the FK repointing has to preserve
live relationships instead of simply being redefined.

## What has to move

| | `employees` | `users` |
| --- | --- | --- |
| Columns | **~70** | 9 |
| Content | employment, statutory (NSSF/NHIF/KRA), salary and allowances, benefits flags, medical, work permit, profile, contact | name, email, password, remember_token, azure_id, sso_only |
| Referenced by | **35 tables, 44 FK columns** | 13 tables |

The 44 references break down as 31 columns literally named `employee_id`, plus
13 role-named ones: `reports_to`, `approved_by`, `manager_id`, `reviewer_id`,
`panelist_id`, `requester_id`, `incoming_employee_id`, `outgoing_employee_id`,
`acknowledged_by_id`, `created_by_id`, `requested_employee_id`.

---

# Decision 1 — Which table survives?

**Recommended: `users` absorbs `employees`.**

Laravel, Sanctum, Breeze and the existing SSO all assume a `users` table, and 13
tables already point at it. Making `employees` the authenticatable model is
possible — `config/auth.php` accepts any Authenticatable — but it means
rewriting the auth provider, the Sanctum tokenable relation, the Azure SSO
callback and every `Auth::user()` assumption, to arrive at the same place.

Result: one `users` table of roughly 79 columns. Large, but it is one person
record, and it can be split into `users` + `user_profiles` later without
touching foreign keys.

## Two column collisions to settle

**Email.** `users.email` is the unique login identity. `employees` carries
`work_email` and `personal_email`. Keep all three: `email` stays the credential
(the UPN, in Entra terms), the other two remain contact attributes. They will
often duplicate, and that is acceptable — a login address and a contact address
are different facts that happen to coincide.

**Name.** `users.name` is a single NOT NULL string; `employees` has
`first_name`, `last_name`, `middle_name`, `preferred_name`. Keep the structured
fields as the source of truth and maintain `name` as a derived display value,
set on save. Laravel scaffolding and the SSO path both read `name`, so removing
it invites avoidable breakage.

---

# Decision 2 — People who never sign in

This is where the Entra analogy needs care. Directory objects in Entra always
exist as accounts. This system has people who are not accounts:

- **Preboarding candidates** — `preboarding_records.employee_id` already points
  at `employees`, so a person exists before they are hired
- **Field or non-login staff** who need payroll and leave records but no credentials

After unification each of these needs a `users` row. Today that is impossible:
`password`, `email` and `name` are all NOT NULL, and `email` is unique.

**Required changes:**

- `password` becomes nullable — an account that cannot sign in yet
- `email` becomes nullable, with uniqueness enforced only where not null
  (a partial unique index)
- add an explicit `account_status`: `none` | `invited` | `active` | `disabled`

`sso_only` already exists and hints at this problem being half-solved.

Making `email` nullable is the sharpest edge in this plan: it is the login
identity, and Laravel's password-reset and unique-validation paths assume it is
present. Every one of those needs to handle a person with no address.

---

# Decision 3 — Rename `employee_id` to `user_id`?

**Recommended: rename, and accept the ripple.**

Leaving 31 columns named `employee_id` pointing at a `users` table is the kind
of inconsistency that costs someone an afternoon every few months, indefinitely.

But it is not confined to the backend. `employee_id` appears **9 times in the
frontend's `types.ts` and across 13 frontend files** — it is part of the API
contract, so renaming means:

- 31 column renames plus 13 role-named FK columns
- API resources and request validation
- Frontend TypeScript interfaces and every component reading them

**Alternative:** rename the columns but keep the API field names as
`employee_id` via the resource layer. The database is consistent, the contract
unbroken, the frontend untouched. The cost is a translation layer that must be
remembered.

Given the SPA is mid-migration to the design system already, keeping the API
contract stable is worth considering — but it should be a deliberate choice,
not drift.

---

# Decision 4 — Sequencing

**Recommended: five phases, each independently verifiable.** A single migration
plus a 59-file code change is atomic but unreviewable, and unreviewable is how
authorization and payroll bugs reach production.

### Phase A — Widen `users`
Add the ~70 employee columns to `users`, all nullable. Add `account_status`.
Make `password` and `email` nullable with a partial unique index. Nothing reads
them yet; `employees` is untouched and the application still works.

### Phase B — Backfill and reconcile
Copy the single existing employee row onto its user. Derive `name`. Verify every
employee has a user and vice versa — trivial at this size, and the last moment
it will be.

### Phase C — Repoint foreign keys
Table by table, in dependency order. 44 columns across 35 tables. Each table is
its own commit and its own test. This is the bulk of the work and the part that
must not be rushed.

### Phase D — Retire `Employee`
Keep `Employee` as a model aliasing `users` through a compatibility period so
the 59 referencing files can be updated in batches rather than at once. Drop the
`employees` table only when nothing references it.

### Phase E — Rename columns
Per decision 3, with the API contract choice applied consistently.

---

# Consequences worth accepting deliberately

**ABAC changes shape.** `ResourceAttributes::reportsTo()` walks
`employees.reports_to` and `isOwnedBy()` compares against `User::employeeId()`.
After unification the reporting line is `users.reports_to` and ownership is a
direct id comparison — simpler, but the authorization tests must be rewritten
alongside, not after.

**`users` becomes a sensitive table.** It will hold salary, allowances, national
ID, tax and NSSF/NHIF numbers, medical conditions, allergies and disabilities —
alongside credentials. Anything currently selecting `users.*` will begin
returning that data. Every such query, resource and serialization needs an
explicit column list, and `$hidden` needs to grow substantially. This is the
most likely source of an accidental data exposure in the whole plan.

**Soft deletes need reconciling.** `employees` has `deleted_at`; `users` does
not. Decide whether deactivating a person soft-deletes the account or only sets
`account_status`.

---

# Recommendation on scope

Phases A and B are small, safe and reversible. Phase C is the real work.

Because Projects, Assets and Travel are about to be ported, there is a genuine
argument for doing A–C **before** those modules land, so their foreign keys are
written against the unified table once rather than repointed later. The ports
add roughly 15 more tables referencing people; doing identity first avoids
adding to the 44.

The counter-argument is that this delays visible progress on the modules
themselves, and unification ships nothing a user can see.
