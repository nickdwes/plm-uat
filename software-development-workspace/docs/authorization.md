# Authorization — RBAC and ABAC

How permission decisions are made in `apps/backend-laravel`. Read this before
adding an authorization check.

## Two questions, always in this order

Every decision is two separate questions. Answering only the first is the most
common authorization bug in this codebase.

| | Question | Answered by |
| --- | --- | --- |
| **RBAC** | May this role do this action *at all*? | `User::hasPermission()` |
| **ABAC** | Does that extend to *this particular record*? | `ResourceAttributes` |

A `dept_manager` holding `leave.approve` may approve leave. Whether they may
approve *this* request depends on whether the applicant is in their reporting
line, and whether the request belongs to the organization in context. Those
are attribute questions, and roles cannot answer them.

## Permissions are a closed catalogue

Permissions are `resource.action` strings stored on `roles.permissions`, and
every one the application checks must exist in
`App\Support\Authorization\Permission`.

```php
$user->hasPermission(Permission::LEAVE_APPROVE);
```

Do not pass raw strings. A typo in a free-form permission check reads as "no
such permission", which denies quietly — or, in a caller that inverts the
result, grants quietly. `Permission::assertKnown()` throws on an unrecognised
permission outside production so the mistake surfaces during development.

### Wildcards

A role may hold `leave.*`, granting every action on that resource, or `*`,
granting everything. **A wildcard never spans the dot**: `leave.*` does not
grant `leave_types.manage`. They are different resources, and the resemblance
is a coincidence of naming.

### Scope

Role assignments live in `user_roles` with a nullable `organization_id`:

- **null** — group scope. Applies in every organization.
- **set** — organization scope. Applies only there.

`permissionsFor($orgId)` returns the union of both. Nothing else grants
cross-tenant reach.

## Attributes

`App\Support\Authorization\ResourceAttributes` answers three questions.

**Ownership** — `isOwnedBy()`. Matches an `Employee` by id, or any model
carrying an `employee_id`, against the acting user's employee record. A user
with no linked employee record owns nothing.

**Reporting line** — `reportsTo()`. Walks `employees.reports_to` upward from
the subject, so cost is bounded by org depth rather than the size of a
manager's team. Nobody manages themselves, and a cycle in the data terminates
the walk rather than hanging.

**Tenancy** — `inCurrentOrganization()`. Matches `organization_id`, falling
back to `primary_organization_id` for `Employee`. A record carrying neither
**fails closed**: a record that cannot be placed in a tenant is a record we
cannot prove belongs to this one.

Tenancy is the check most easily forgotten and the one whose omission is least
visible — every role check still passes while records leak across
organizations. The combined helpers below apply it for you.

## Writing a policy

Extend `BasePolicy` and prefer the combined helpers, which apply tenancy
automatically.

```php
// Permission plus tenancy.
public function approve(User $user, LeaveRequest $request): bool
{
    return $this->canTouch($user, Permission::LEAVE_APPROVE, $request);
}

// Broad permission on any record, or a narrow one on the user's own.
public function view(User $user, Employee $employee): bool
{
    return $this->canTouchOwn(
        $user,
        Permission::EMPLOYEES_VIEW,
        Permission::EMPLOYEES_VIEW_OWN,
        $employee,
    );
}

// Broad permission, or a narrow one limited to the reporting line.
// $subject is the employee the record concerns — the reporting line is a
// property of people, not of leave requests.
public function viewTeamLeave(User $user, LeaveRequest $request): bool
{
    return $this->canTouchTeam(
        $user,
        Permission::LEAVE_VIEW,
        Permission::LEAVE_VIEW_TEAM,
        $request,
        $request->employee,
    );
}
```

A `_own` or `_team` suffix marks a permission whose scope is narrowed by an
attribute. Holding `leave.view_own` grants nothing until the ownership check
also passes.

## Seeded roles

| Role | Scope | Grants |
| --- | --- | --- |
| `group_admin` | group | `*` across organizations, employees, departments, payroll, leave, reports, settings, audit |
| `org_hr` | organization | `employees.*`, `payroll.*`, `leave.*`, `onboarding.*`, `departments.view/manage`, `reports.org` |
| `dept_manager` | organization | `employees.view`, `leave.view/approve/reject`, `reports.team` |
| `employee` | organization | `employees.view_own`, `leave.submit/cancel_own/view_own`, `payslip.view_own` |

The role name hierarchy predates this catalogue and is preserved:
`group_admin` implies `org_hr`, which implies `dept_manager`. The helpers
`isGlobalHR()`, `isCompanyHR()`, `isManager()` and `isEmployee()` behave
exactly as before.

## Performance

Role assignments are resolved once per request and memoized on the user.

Before, each helper issued its own query and they nested — `isManagerIn()`
fell through to `isOrgHR()` which fell through to `isGroupAdmin()`, three round
trips for one check, repeated across ~120 call sites. A test asserts the query
count stays at one.

If you grant or revoke a role mid-request, call `forgetCachedRoles()` or the
stale set is reused.

## Migration status

The permission layer is **additive**. Existing policies still use the role
helpers and behave identically; nothing has been switched over. Modules migrate
one at a time so each can be verified on its own rather than re-verifying every
screen at once.

Two gaps remain, both pre-existing:

- **46 checks sit outside policies**, mostly in `FormRequest::authorize()`.
  These bypass `Gate` entirely, so they are not reachable by a Gate resolver
  and each needs converting by hand.
- **31 of 51 controllers call `authorize()` nowhere at all.** The payroll
  authorization hole fixed earlier was one instance of that pattern; the
  others have not been audited.

Until a module is migrated, treat its role checks as the source of truth for
that module.

## Tests

`tests/Feature/Authorization/RbacAbacTest.php` — 23 tests covering wildcard
matching and its boundaries, org scoping, ownership, the reporting line
including cycles, tenancy fail-closed, regression on the role helpers, and the
query count.

The suite runs against Postgres, not SQLite: migrations use Postgres-specific
DDL (`ALTER TABLE ... DROP CONSTRAINT IF EXISTS`) that SQLite cannot parse, so
`phpunit.xml` points at a `hrms_test` database on the `db` service.

### Two kinds of test, deliberately

**Example tests** cover a specific case. **Catalogue-wide invariants** iterate
every permission in `Permission::all()` and assert a denial — no assignments
grants nothing, a disabled account holds nothing even with a global wildcard,
an exclusion always denies, a deny always overrides.

The invariants exist because example tests only prove the case you thought of.
Both bugs found while building this passed every example test written at the
time. A permission added later is covered the moment it joins the catalogue
rather than when someone remembers to write a test for it.

## Mutation testing

Ordinary tests confirm the code does what you expected. Mutation testing checks
the tests would *notice if it stopped*: it breaks the source deliberately —
flips a comparison, negates a condition, drops an array item — and reports any
change no test caught.

```
composer test:mutation
```

Configured in `infection.json5`, scoped to `app/Support/Authorization`.
Currently 126 mutants, all killed. Thresholds sit at 95% rather than 100%
because a gate that fails on the first harmless mutant gets disabled rather
than investigated.

It needs a coverage driver, which the application image does not carry. Build
one on top of it:

```dockerfile
FROM software-development-workspace-backend
USER root
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install pcov && docker-php-ext-enable pcov && apk del .build-deps
```

### A known limitation

The permission resolution logic lives on `App\Models\User`, so it sits outside
the configured source directory. Mutating all of `app/Models` to reach it would
bury the signal in unrelated models — a run against `User.php` alone scores
99%, and the single survivor is an unrelated `datetime` cast.

The clean fix is to extract permission resolution into
`App\Support\Authorization`, which would bring it under the same enforced gate.
Worth doing before that logic grows further.
