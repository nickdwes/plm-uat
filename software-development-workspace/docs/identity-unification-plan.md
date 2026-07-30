# Identity Unification Plan

One person identity that both signs in and is attributed on every action — the
Entra / Office 365 model — with sensitive HR data held separately from it.

Supersedes the earlier version of this plan. Nothing here is implemented.

## What changed, and why

The first version of this plan recommended `users` absorbing all ~70 columns of
`employees`, and listed "users becomes a sensitive table" as a consequence to
accept. That was wrong, for a reason worth recording so it is not re-proposed:

**`Employee` is a model nothing serializes by accident. `User` is a model
Laravel serializes constantly** — `Auth::user()`, `$request->user()`, Sanctum's
`tokenable`, notification `notifiable`, any `->with('user')`. Merging would put
salary, national ID, tax numbers, medical conditions and disabilities onto the
one object that leaks by convention.

The proposed mitigation was `$hidden` — a **denylist**, which fails open. Add a
column six months from now, forget to hide it, and it is in every payload. On a
79-column table where half is sensitive, that is the wrong default for the
lifetime of the system.

**The Office 365 analogy argues against it.** Microsoft does not put salary in
Entra. The directory object holds display name, job title, department, manager,
mail and `employeeId`. Sensitive HR data lives in the HR system and syncs only
the non-sensitive subset into the directory.

So "do it the way Office 365 does" means **one identity, not one table**.

## Target shape

One identity. Two tables. Same primary key.

```
users                       the identity — credentials + directory attributes
  └─ employee_profiles      the sensitive record, PK = FK = users.id
```

All 44 foreign keys currently pointing at `employees` repoint to `users`. One
record signs in, one record is attributed, one id throughout — the requirement
in full. The sensitive half requires an explicit join, which makes it an
**allowlist by construction** rather than a denylist somebody must maintain.

### Column allocation

The split follows the classification **already encoded in
`EmployeeResource`**, which gates fields behind `$canSensitive`. That is the
team's existing judgement about what is sensitive; re-deriving it from scratch
would risk disagreeing with the running system.

**`users` — identity and directory.** Safe to return from a user lookup.

| Group | Columns |
| --- | --- |
| Credentials | `email`, `password`, `remember_token`, `email_verified_at`, `azure_id`, `sso_only` |
| Lifecycle | `account_status`, `status_changed_at` |
| Name | `name` (derived display), `first_name`, `last_name`, `middle_name`, `preferred_name` |
| Directory | `employee_number`, `work_email`, `photo_url` |
| Placement | `primary_organization_id`, `department_id`, `designation_id`, `job_grade_id`, `reports_to` |
| Employment | `employment_type`, `status`, `date_hired`, `probation_end_date`, `contract_end_date`, `date_exited`, `exit_reason`, `is_shared` |
| Public profile | `professional_summary`, `about_me`, `hobbies_interests`, `job_description` |

**`employee_profiles` — sensitive.** Never returned without an explicit
permission check.

| Group | Columns |
| --- | --- |
| Personal | `date_of_birth`, `gender`, `personal_email`, `phone`, `linkedin_profile`, `address`, `city`, `country`, `residential_address` |
| Statutory | `national_id`, `passport_number`, `tax_id`, `nssf_number`, `nhif_number`, `sha_number` |
| Registration | `nssf_registered`, `nhif_registered`, `kra_registered` |
| Pay | `basic_salary`, `house_allowance`, `transport_allowance`, `medical_allowance`, `other_allowances`, `insurance_relief` |
| Medical | `blood_group`, `allergies`, `known_conditions`, `disabilities`, `medical_plan` |
| Benefits | `benefit_medical`, `benefit_life_insurance`, `benefit_pension`, `benefit_wiba`, `benefit_group_life`, `benefits_effective_date`, `pension_provider` |
| Right to work | `has_work_permit`, `work_permit_number`, `work_permit_expiry` |

### Why this is also faster

Most queries never need salary or medical data. Narrower `users` rows mean
smaller result sets and better cache locality on the hot path. Only payroll and
the HR detail view pay the join. The split improves the common case rather than
costing it.

## Multi-tenancy — settled

Plmv2 was built for a single organization, but is being taken multi-tenant so
each tenant is served independently. So:

- `users` keeps `primary_organization_id`, and org-scoped role assignments
  already carry tenancy through `user_roles.organization_id`.
- `employee_profiles` inherits tenancy from its owner — it has no
  `organization_id` of its own, because a person belongs to one identity and
  that identity is already placed.
- **Every ported Plmv2 table gets `organization_id`** and the
  `BelongsToOrganization` concern, matching every existing HRMS table.

This also settles it for the module ports: Projects, Assets and Travel are all
org-scoped.

## People who never sign in

Where the Entra analogy needs adapting. Directory objects in Entra are always
accounts; this system has people who are not:

- **Preboarding candidates** — `preboarding_records.employee_id` already points
  at `employees`, so a person exists before hire
- **Field or non-login staff** needing payroll and leave but no credentials

After unification each needs a `users` row, which today is impossible:
`password`, `email` and `name` are all `NOT NULL`, and `email` is unique.

Required:

- `password` nullable — an account that cannot sign in yet
- `email` nullable, uniqueness enforced by a **partial unique index** on
  non-null values
- `account_status` distinguishes them — already built

**Nullable `email` is the sharpest edge in this plan.** It is the login
identity, and Laravel's password-reset and unique-validation paths assume it is
present. Each needs to handle a person with no address.

## Already built

Three pieces this plan depended on are done:

- `account_status` on `users`, gating authorization and failing closed
- Scoped, time-bound role assignments — `scope`, `valid_from`, `valid_until`,
  `revoked_at`, `revoked_by`, `granted_by`
- `deny_assignments`

So the lifecycle half of this work is in place. What remains is the data model.

## Phases

### A — Create `employee_profiles`
New table, PK = FK to `users`. Nothing reads it yet. Reversible.

### B — Widen `users`
Add the directory columns, all nullable. Make `password` and `email` nullable
with a partial unique index. Application still works throughout.

### C — Backfill and reconcile
Copy the single existing employee into both tables. Derive `name` from the
structured fields. Verify every employee has a user and vice versa — trivial at
one record, and the last moment it will be.

### D — Repoint foreign keys
44 columns across 35 tables, in dependency order. **Each table its own commit
and its own tests.** This is the bulk of the work and the part that must not be
rushed.

### E — Retire `Employee`
Keep it as a model aliasing `users` through a compatibility period so the 59
referencing files migrate in batches. Drop the `employees` table only when
nothing references it.

### F — Rename columns
`employee_id` → `user_id`. Note this is **not backend-only**: `employee_id`
appears 9 times in the frontend's `types.ts` and across 13 files, so it is part
of the API contract. Either rename through to the SPA, or keep the contract
stable via the resource layer — a deliberate choice, not drift.

## Safeguards beyond the split

The table separation is the structural control. Four more, in order of value:

1. **Permission-gated resource layer.** Allowlist fields explicitly and gate on
   `employees.view_sensitive`, not role names. The current gate is
   `$isHR || $isSelf` using `isOrgHR()` — it predates the permission catalogue
   and should move onto it.
2. **Encrypted casts** for `national_id`, `tax_id`, `passport_number` and the
   medical fields, so a database dump or backup leak does not expose them in
   plaintext.
3. **Audit sensitive reads** — who viewed salary, and when. Detection, because
   prevention eventually fails. This also produces the access-review data the
   role model assumes.
4. **`$hidden` as defence-in-depth**, never as the primary control.

## Consequences to accept knowingly

**ABAC changes shape.** `reportsTo()` walks `employees.reports_to` and
ownership compares `User::employeeId()`. Afterwards the reporting line is
`users.reports_to` and ownership is a direct id comparison — simpler, but the
authorization tests must be rewritten alongside, not after. The mutation-testing
gate should be run over the change.

**Soft deletes need reconciling.** `employees` has `deleted_at`; `users` does
not. A leaver should be `account_status = disabled`, not soft-deleted — their
approvals and payroll runs must stay attributable. Decide whether `users` gains
`deleted_at` at all, and resist using it for people who have merely left.

**`employee_profiles` is the crown jewels in one table.** That is the point —
it is far easier to protect one table you know is sensitive than forty columns
scattered through the object every request already loads.
