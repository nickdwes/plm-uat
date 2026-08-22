# Employee view — adoption plan

Adopting the HR portal's Employees screens from the PLM Redesign file into the
built application: the directory and the individual profile.

Design source: `PLM Redesign`, page **HR portal**. Directory `3277:138791`;
profile tab bar `3633:57699`; profile states `3320:52643` and the fifteen
frames beside it at `y=6489`.

Last reviewed: 2026-08-22.

---

## What the design asks for

**Directory** — a heading and lead, `Import` / `Export` / `+ Add Employee`, a
five-figure strip (Total, Active, On Probation, On Leave, New this month),
status tabs (All / Active / On Probation / On Leave / Exited), search, a
Filter control, and a table of avatar-with-email, ID, Department, Role, Emp
Type, Status, Date Joined, Actions, paginated.

**Profile** — breadcrumb `Employees › #KNS123`, a header carrying photo, name,
status pill and `Role • Employment type`, then nine tabs:

`Profile Overview` · `Job Details` · `Emergency Contacts` · `Dependents` ·
`Attendance Management` · `Leave Management` · `Payroll` · `Performance` ·
`Documents`

Profile Overview itself is four cards: Personal Information, Statutory IDs,
Health Information, Current Leave Balance.

## What exists today

| | Built | Design |
|---|---|---|
| Directory header | `<h2>Employees</h2>` | title, lead, three buttons |
| Directory figures | none | five |
| Status filter | a `<select>` | five tabs |
| Directory columns | ID, Name, Department, Designation, Joined, Status | + avatar, email, Emp Type, Actions |
| Profile tabs | Overview, Career, Profile, Benefits, Documents | nine, listed above |

The five built tabs are not a subset of the nine — they are a different
carve-up of roughly the same material. `Career` and `Benefits` have no
counterpart in the design; `Job Details`, `Emergency Contacts`, `Dependents`,
`Attendance`, `Leave`, `Payroll` and `Performance` have none in the build.

---

## The finding that shapes the order

**Every field the design's Profile Overview prints already exists on
`employees`** — `blood_group`, `allergies`, `known_conditions`, `disabilities`,
`national_id`, `passport_number`, `tax_id`, `nssf_number`, `nhif_number`,
personal and work email, phone, date of birth, date hired, employment type,
status, `photo_url`. Nothing is missing. Profile Overview is presentation work
only.

**Every table behind the other tabs exists and is empty.**

| Table | Rows |
|---|---|
| `employee_emergency_contacts` | 0 |
| `employee_dependents` | 0 |
| `employee_qualifications` | 0 |
| `employee_documents` | 0 |
| `attendance_days` | 0 |
| `leave_balances` | 0 |
| `payroll_run_items` | 0 |
| `performance_reviews` | 0 |
| `assets` | 0 |

So six of the nine tabs would render an empty state on day one. That is not an
argument against building them, but it is an argument about ordering: a tab
that can only ever say "nothing recorded" teaches people the screen is broken.

**Two of the five headline figures have nothing behind them.** Employee status
holds only `active` (88) and `exited` (35) — there is no probation status, and
`probation_end_date` is populated for nobody, so *On Probation* cannot be
counted. *New this month* is derivable from `date_hired` and is currently 0,
which is true rather than broken. *On Leave* is already computable: the
`LeaveSummary` and `WeekAvailability` services built for the leave screens
answer exactly this.

**There is no employee import or export route.** Both buttons in the design are
new backend work, not wiring.

---

## Phase 1 — The directory

Self-contained, no schema change, and the screen most people open first.

- Header: title, lead, and the three buttons. `Import`/`Export` are rendered
  but only if phase 4 lands; until then they are absent rather than inert.
- The figures strip. **Four figures, not five** — Total, Active, On Leave,
  New this month. *On Probation* is omitted until there is a probation state
  to count; a card reading 0 asserts that nobody is on probation, which is a
  different claim from not knowing.
- Status tabs replacing the select. `On Probation` likewise omitted.
- Table gains avatar, work email under the name, Employment Type, and an
  explicit Actions column.
- `On Leave` count and the tab reuse `LeaveVisibility::employeeIds` and the
  same "approved and covering today" predicate as `LeaveSummary`, so the leave
  screen and the directory cannot disagree about who is away.

Backend: one `GET /api/v1/employees/summary` returning the four counts, scoped
the way `EmployeeService::list` already scopes — employed here **or placed
here**, so a secondee counts once in the organisation that hosts them.

## Phase 2 — The profile shell and Profile Overview

- Breadcrumb, header with photo, status pill, `Role • Employment type`.
- Nine-tab bar, horizontally scrollable — nine tabs do not fit 1440px in the
  design either; its own frame is clipped at `Performa…`.
- Profile Overview built as the four cards. Pure presentation over fields that
  already exist.
- **Current Leave Balance** reads the existing `/api/v1/leave/balance`
  endpoint. It shows *available* days rather than entitled-minus-used, for the
  same reason the leave cards do: days already requested are spoken for.

Retiring the old tabs: `Overview` becomes `Profile Overview`. The contents of
`Career`, `Profile` and `Benefits` are redistributed — career history and
reporting line into `Job Details`, the personal detail into `Profile
Overview`, benefits into `Payroll`. Nothing is deleted before its new home
exists.

## Phase 3 — The tabs with data behind them

In this order, because it is the order in which they stop being empty:

1. **Job Details** — department, designation, grade, manager, employment type,
   dates, and the organisation/placement card already built for the movement
   feature. Data exists today.
2. **Leave Management** — balances, history and the calendar, reusing the leave
   endpoints delivered in the leave rebuild. Data exists today.
3. **Documents** — `employee_documents` and its encrypted-vault download path
   already work; the current Documents tab is kept largely as-is.
4. **Emergency Contacts** and **Dependents** — tables and API exist, both
   empty. Cheap to build, and they are how they get populated.

## Phase 4 — The tabs that need a module behind them

**Attendance Management**, **Payroll** and **Performance** are windows onto
three other modules. Each should show that employee's slice of the module that
owns it, not a second implementation of it. Build after phase 3 and only where
the owning module is live; a tab that reimplements payroll is a second payroll.

**Import / Export.** Export is the smaller half and worth doing first: a CSV of
the directory as filtered, built from the same query so the file and the screen
agree. Import is a bigger piece — it needs a column mapping, a rehearsal pass
and an idempotency key, and it should follow the habits the PLM importers
already have (`--apply` gating, `plm_import_map`-style resolution, nothing
guessed).

---

## Decisions taken

- **Four figures, not five.** *On Probation* is omitted rather than shown as 0.
  Adding a `probation` status and populating `probation_end_date` is a real
  change to how the company records employment, and it is the user's call, not
  a rendering detail.
- **Nine tabs, adopted whole.** The build's five are re-carved rather than
  extended, because a screen with fourteen tabs is worse than either design.
- **No tab ships as an empty shell without an empty state that says why.**
  "No emergency contacts recorded" with an Add button is a screen; a blank
  panel is a bug report.
- **Nothing reimplements another module.** Attendance, Payroll and Performance
  tabs read their owner's endpoints.

## Deliberately out of scope

- **Employee Assets.** Nine frames exist (`4444:216043` onwards) and `assets`
  is empty; it belongs with the assets module plan, not here.
- **The design's fabricated data.** Its rows mix `@kns.co.ke` with `@abc.com`
  and `@xyz.com` addresses, and its Health Information card shows "Tiberbu
  Healthnet" as a blood group and "Product Manager" as a known condition.
  Placeholder text, not a specification.
- **Changing what `status` means.** Adding probation is phase-4-or-later and
  needs a decision first.

## Verification

1. The four directory figures reconcile against the table beneath them at each
   status tab — the failure this is guarding against is a headline counted over
   a different population from the rows.
2. A seconded employee appears once in the host organisation's directory and
   once in their home organisation's, with the host's job title in both counts
   and the table, per `Employee::attachPlacements`.
3. Profile Overview renders with every field null — the state most of the 123
   imported people are actually in — without printing "null", "—" everywhere,
   or an empty card with a heading and nothing under it.
4. `/employees/me` for a user with no linked employee record 404s cleanly and
   the screen says so. Admin is currently in exactly this state.
5. Frontend `vue-tsc`, `npm test`, `npm run build`; backend suite green.

## Known blockers carried in from elsewhere

- **Designations are empty**, so `Role` is blank in the directory and the
  profile header for all 88 active people. `import:plm-designations` is built
  and waiting on `PLM_DB_*` credentials.
- **Photos.** `photo_url` exists; nothing populates it. The design leans on
  avatars throughout, so the initials fallback used on the leave screens is
  what will actually render until there is an upload path.
