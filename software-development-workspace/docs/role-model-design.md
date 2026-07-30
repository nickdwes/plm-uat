# Role Model Design

A versatile, watertight authorization model: role definitions, scoped
assignments, custom roles, and how it aligns with Azure RBAC and Entra.

Design, not implementation. Companion to `authorization.md`, which documents
what exists today.

## The core idea, borrowed from Azure

Azure RBAC reduces authorization to one triple:

```
role assignment = principal  +  role definition  +  scope
```

The role says **what**. The scope says **where**. The principal says **who**.
Nothing else grants access.

The system already has the bones of this — `user_roles(user_id, role_id,
organization_id)` is exactly principal + role + scope. The proposal is to
finish the shape rather than invent a new one.

## What changes, and why

Today, scope is baked into the permission string: `leave.view_own`,
`leave.view_team`, `leave.view`. Three permissions for one capability.

That does not scale. Every new resource multiplies by the number of scopes,
the catalogue grows combinatorially, and a custom role wanting "view leave for
my department" needs a permission nobody thought to define.

**Move scope out of the permission and into the assignment.** One permission —
`leave.view` — assigned at a scope. The ABAC layer then answers "does this
scope cover this record?", which is precisely what `ResourceAttributes` already
does.

| | Before | After |
| --- | --- | --- |
| Permissions for viewing leave | 3 | 1 |
| Scopes expressible | 3 fixed | 5, composable |
| New scope costs | a permission per resource | nothing |

---

# 1. Permissions — the atoms

`resource.verb`. Six verbs, deliberately few.

| Verb | Meaning | Example |
| --- | --- | --- |
| `view` | Read | `employees.view` |
| `create` | Bring into existence | `leave.create` |
| `update` | Modify | `assets.update` |
| `delete` | Remove | `projects.delete` |
| `execute` | Perform a state-changing action | `leave.approve`, `payroll.process` |
| `manage` | Configure the resource *type* | `leave.manage` (leave types, policies) |

The `execute` / `manage` split mirrors Azure's **DataActions** versus
**Actions** — operating on records versus configuring the thing itself.

It matters here: approving one leave request and redefining what leave types
exist are different powers. A line manager needs the first and must never have
the second. Collapsing them into "leave admin" is how over-permissioned roles
happen.

Named execute permissions stay explicit — `leave.approve`, `leave.reject`,
`payroll.process`, `payroll.approve`, `assets.assign` — because they are the
permissions worth auditing individually.

## 2. Scopes — where a permission reaches

Five levels, ordered. Each contains the ones below it.

| Scope | Covers | ABAC check |
| --- | --- | --- |
| `self` | Only the principal's own records | `isOwnedBy()` |
| `team` | Direct and indirect reports | `reportsTo()` |
| `department` | Everyone in the same department | department match |
| `organization` | The whole tenant | `inCurrentOrganization()` |
| `group` | Every organization | no tenancy filter |

`self`, `team` and `organization` are already implemented. `department` is a
new attribute check; `group` is the absence of one.

**An assignment carries exactly one scope.** A person needing `leave.view` at
`team` and `payroll.view` at `self` holds two assignments, which is correct —
they are two different grants and should be auditable separately.

---

# 3. Role matrix

Four built-in roles. Every cell is a permission *and* the scope it is granted
at.

### People and employment

| Resource | Employee | Line Manager | HR Admin | Group Admin |
| --- | --- | --- | --- | --- |
| `employees.view` | self | team | organization | group |
| `employees.view_sensitive` | self | — | organization | group |
| `employees.create` | — | — | organization | group |
| `employees.update` | self¹ | — | organization | group |
| `employees.delete` | — | — | — | group |
| `employees.exit` | — | — | organization | group |

¹ Contact details only, via `UpdateContactDetailsRequest`.

### Leave

| Resource | Employee | Line Manager | HR Admin | Group Admin |
| --- | --- | --- | --- | --- |
| `leave.view` | self | team | organization | group |
| `leave.create` | self | team² | organization | group |
| `leave.approve` | — | team | organization | group |
| `leave.reject` | — | team | organization | group |
| `leave.cancel` | self | team | organization | group |
| `leave.manage` | — | — | organization | group |

² Filing on behalf of a report.

### Payroll

| Resource | Employee | Line Manager | HR Admin | Group Admin |
| --- | --- | --- | --- | --- |
| `payroll.view` | self³ | — | organization | group |
| `payroll.create` | — | — | organization | group |
| `payroll.process` | — | — | organization | group |
| `payroll.approve` | — | — | — | group |
| `payroll.manage` | — | — | — | group |

³ Own payslip only.

**Note the deliberate gap.** HR Admin may process payroll but not approve it.
Approval sits at group level. That is separation of duties — the person who
prepares a payroll run must not be the person who releases it. This is the
single most valuable control in the matrix and should survive any
simplification.

### Projects, tasks, assets, travel

| Resource | Employee | Line Manager | HR Admin | Group Admin |
| --- | --- | --- | --- | --- |
| `projects.view` | self⁴ | team | organization | group |
| `projects.create` | — | department | organization | group |
| `projects.update` | self⁴ | team | organization | group |
| `tasks.view` | self | team | organization | group |
| `tasks.create` | self | team | organization | group |
| `tasks.update` | self | team | organization | group |
| `assets.view` | self⁵ | team | organization | group |
| `assets.request` | self | team | organization | group |
| `assets.approve` | — | team | organization | group |
| `assets.assign` | — | — | organization | group |
| `assets.manage` | — | — | organization | group |
| `travel.view` | self | team | organization | group |
| `travel.create` | self | team | organization | group |
| `travel.approve` | — | team | organization | group |
| `travel.reconcile` | — | — | organization | group |

⁴ Projects they lead or are assigned to. ⁵ Assets assigned to them.

---

# 4. Custom roles

A custom role is a role definition an administrator creates. Three fields
matter:

```
permissions            granted
excluded_permissions   carved out of the grant  (Azure: NotActions)
assignable_scopes      the scopes it may be assigned at
```

**Excluded permissions** are how you build "everything about leave except
approving it" without enumerating every permission. Exclusion always wins over
inclusion within a role definition.

**Assignable scopes** stop a custom role intended for departmental use being
assigned tenant-wide. A role definition that omits `group` from its assignable
scopes can never be granted group-wide, whatever an administrator does later.

### Rules that keep custom roles safe

1. **No privilege escalation.** An administrator may only grant permissions
   they themselves hold, at a scope no wider than their own. Without this,
   anyone who can create roles is effectively a group admin.
2. **Built-in roles are immutable.** Custom roles may be *derived* from them,
   but the originals never change — otherwise every tenant's `org_hr` means
   something different and the matrix above becomes fiction.
3. **A custom role cannot grant `manage` on authorization itself.**
   `roles.manage` and `permissions.assign` are group-scoped only. This is the
   boundary that keeps everything else enforceable.

---

# 5. Feature access

"Assign a user access to certain features" is a real requirement, and the
tempting implementation — per-user permission grants — is the wrong one.

Direct grants are invisible in the role matrix, unauditable at scale, and
impossible to review: after a year nobody can say why a person has an odd
permission. Azure deliberately has no such mechanism, and that restraint is
the point.

**Instead:** a feature is a named bundle of permissions, and access to it is
granted by a role. Wanting one person to have "Recruitment access" means a
`Recruiter` role, assigned at a scope. It shows up in the matrix, in access
reviews, and in the audit log.

The cost is more role definitions. That is the correct trade — role sprawl is
visible and reviewable; permission sprawl is neither.

---

# 6. Deny assignments

Roles are additive: hold two and you get the union. That makes "everything
except X" impossible to express by adding roles.

A **deny assignment** — principal + permission + scope — is evaluated last and
always wins.

```
1. Is the principal active?              no  -> deny
2. Does a deny assignment match?         yes -> deny
3. Does any role assignment grant it?    no  -> deny
4. Does the scope cover this record?     no  -> deny
                                             -> allow
```

Deny is for exceptions: a person under investigation, a contractor excluded
from payroll data. It should be rare, and its rarity is itself a signal — a
system with many deny assignments has the wrong roles.

---

# 7. Joiner, Mover, Leaver

The lifecycle is where most RBAC systems quietly fail. Three changes make it
work.

### Assignments are time-bound

```
user_roles
  + valid_from     when it takes effect
  + valid_until    when it lapses (null = indefinite)
  + revoked_at     explicit revocation
  + revoked_by     who revoked it
  + granted_by     who granted it
```

An expired or revoked assignment grants nothing. A revoked one is retained,
not deleted — "who had access last March" is an audit question that a deleted
row cannot answer.

### Authorization checks account status first

```
account_status: pending | active | suspended | disabled
```

**A non-active principal is denied everything, regardless of assignments.**
This closes a hole that exists today: `hasPermission()` currently reads role
assignments only, so a disabled leaver retaining an `org_hr` assignment would
still pass every check — including `payroll.approve`.

### Movers

Changing department or manager must expire the assignments tied to the old
position. The reporting-line side is already correct by construction:
`reportsTo()` computes live from `reports_to`, so a mover's former manager
loses access the moment the line changes, with no extra work.

What does *not* self-correct is role assignments. Moving from Finance to
Operations while keeping a Finance role is textbook privilege accumulation,
and only explicit expiry prevents it.

### Leavers

Disable, never delete. Their approvals, payroll runs and submitted requests
must remain attributable — `approved_by` pointing at a deleted row is an audit
failure. Setting `account_status = disabled` stops all access while
attribution survives, because attribution is a stored foreign key, not a live
permission check.

---

# 8. Where this differs from Microsoft, deliberately

| Azure / Entra | Here | Why |
| --- | --- | --- |
| Scope is a resource hierarchy (management group → subscription → resource) | Scope is a *relationship* (self, team, department, organization) | An HRMS's natural boundary is the org chart, not a resource tree |
| PIM: eligible vs active, just-in-time activation | Not proposed | Real value, real complexity. Revisit when there is an auditor asking |
| Access reviews | Recommended, not specified | Needs the assignment table to exist first |
| Administrative units | Approximated by `department` scope | Same idea, simpler |

**Just-in-time elevation is the one worth revisiting later.** Making
`payroll.approve` an *eligible* rather than *active* assignment — activated for
four hours, with a reason, logged — is the strongest control available for
that permission. It is also the most complex, and it needs the time-bound
assignment table to exist before it is possible. Building that table now keeps
the door open at no cost.

---

# 9. What to build now versus later

The distinction that matters: **schema is expensive to change, evaluation
logic is not.**

### Build now — schema

- `user_roles`: add `scope`, `valid_from`, `valid_until`, `revoked_at`,
  `revoked_by`, `granted_by`
- `users`: add `account_status`
- `roles`: add `is_custom`, `excluded_permissions`, `assignable_scopes`
- `deny_assignments` table

Adding these to an empty system costs a migration. Adding them once thousands
of assignments exist costs a backfill with no correct default.

### Build now — evaluation

- Account status check, first and fail-closed. **This is a live hole today** and
  is independent of everything else here.
- Scope resolution in `ResourceAttributes` (`department` is the only new one).

### Later

- Custom role management UI
- Deny assignment UI
- Access reviews
- Just-in-time elevation

The evaluation order in section 6 should be implemented in full from the start
even while deny assignments and custom roles are empty. An authorization
pipeline that gains a step later is one that was wrong until it did.
