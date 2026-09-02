<script setup lang="ts">
/**
 * Who can log in, and which employee each of them is.
 *
 * Two registers that have to agree. `users` is identity — an address and a
 * password. `employees` is the HR record — a number, a department, a manager, a
 * leave balance. One column joins them, and almost everything the application
 * calls "mine" resolves through it: my leads, my tasks, my leave, my team.
 *
 * A login with no employee behind it can hold every permission in the system
 * and still see an empty screen, because every "own" scope resolves to nobody.
 * Nothing showed that link, so nothing showed when it was missing.
 */
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { api, ApiError } from "../lib/api";
import { currentOrgSlug } from "../stores/auth";
import { can } from "../stores/permissions";
import { notifySaved, notifyFailed } from "../lib/toast";
import EmployeePicker from "../components/EmployeePicker.vue";
import QRCode from "qrcode";

interface LinkedEmployee {
  id: number; employee_number: string | null; name: string;
  work_email: string | null; status: string;
}
interface Discovery {
  status: string; enabled: boolean | null; checked_at: string | null; error: string | null;
}
interface AuthPath {
  method: string; otp_preference: string | null; pending_enrollment: boolean;
  alt_username: string | null; discovery: Discovery; has_authenticator: boolean;
}
interface UserRow {
  id: number; name: string; email: string; status: string; sso_only: boolean;
  employee: LinkedEmployee | null;
  email_matches: boolean | null;
  auth: AuthPath;
  roles: { id: number; name: string; organization_id: number | null }[];
}
interface FreeEmployee {
  id: number; employee_number: string | null; name: string; work_email: string | null;
}
interface Totals {
  users: number; linked: number; without_employee: number;
  employees_no_login: number; without_role: number; not_active: number;
  sso: number; local: number; needs_attention: number;
}

const router = useRouter();
const org = () => currentOrgSlug.value;

const users    = ref<UserRow[]>([]);
const unlinked = ref<FreeEmployee[]>([]);
const totals   = ref<Totals | null>(null);
const loading  = ref(true);
const search   = ref("");
const error    = ref("");

/*
 * The chips the journey needs: which path somebody is on, and everything that
 * is on neither yet. "Needs attention" deliberately gathers five different
 * problems — never discovered, a lookup that errored, a local account that
 * never enrolled, no employee record, no role — because they have the same
 * consequence: the person cannot work.
 */
type Chip = "all" | "sso" | "local" | "pending" | "attention";

const chips: { key: Chip; label: string }[] = [
  { key: "all",       label: "All" },
  { key: "sso",       label: "SSO" },
  { key: "local",     label: "Local TOTP" },
  { key: "pending",   label: "Pending enrollment" },
  { key: "attention", label: "Needs attention" },
];

const chip = ref<Chip>("all");

const needsAttention = (u: UserRow) =>
  u.auth.method === "UNRESOLVED" || u.auth.discovery.status === "error"
  || u.auth.pending_enrollment || !u.employee || !u.roles.length;

const rows = computed(() => {
  switch (chip.value) {
    case "sso":       return users.value.filter((u) => u.auth.method === "SSO");
    case "local":     return users.value.filter((u) => u.auth.method === "LOCAL_TOTP" || u.auth.method === "EMAIL_OTP");
    case "pending":   return users.value.filter((u) => u.auth.pending_enrollment);
    case "attention": return users.value.filter(needsAttention);
    default:          return users.value;
  }
});

/** The pill each method wears, and what it says. */
const AUTH_LABEL: Record<string, string> = {
  SSO: "SSO / Entra", LOCAL_TOTP: "Authenticator", EMAIL_OTP: "Email OTP", UNRESOLVED: "Unresolved",
};
const AUTH_TONE: Record<string, string> = {
  SSO: "auth-sso", LOCAL_TOTP: "auth-local", EMAIL_OTP: "auth-email", UNRESOLVED: "auth-unknown",
};

const DISCOVERY_LABEL: Record<string, string> = {
  never: "Never checked",
  found_enabled: "Active in Office 365",
  found_disabled: "In Office 365, but disabled",
  not_found: "Not in Office 365",
  error: "Lookup failed",
};

async function load() {
  loading.value = true;
  error.value = "";
  try {
    const res = await api<{ data: UserRow[]; unlinked_employees: FreeEmployee[]; totals: Totals }>(
      "GET", "/api/v1/authorization/users",
      { org: org(), params: { search: search.value || undefined } },
    );
    users.value = res.data;
    unlinked.value = res.unlinked_employees;
    totals.value = res.totals;
  } catch (e) {
    error.value = e instanceof ApiError ? (e.firstError ?? e.message) : "Could not load the user directory.";
  } finally {
    loading.value = false;
  }
}

// ── Matching a login to an employee ─────────────────────────────────────────

/*
 * One panel for everything about one person: what Office 365 says, how they
 * sign in, which employee they are. They were three separate decisions and
 * they are made together, because each one constrains the next — there is no
 * point choosing an OTP preference for somebody Entra manages.
 */
const open    = ref<UserRow | null>(null);
const chosen  = ref<number | null>(null);
const draft   = ref({ auth_method: "", otp_preference: "", alt_username: "" });
const busy    = ref("");

function manage(u: UserRow) {
  endEnrolment();
  deprovisioning.value = false;
  // Otherwise the previous person's temporary password is still on screen
  // when the next row opens.
  resetProvisionPanel();
  recoveryCodes.value = [];
  open.value = u;
  chosen.value = u.employee?.id ?? null;
  draft.value = {
    auth_method: u.auth.method === "UNRESOLVED" ? "" : u.auth.method,
    otp_preference: u.auth.otp_preference ?? "AUTHENTICATOR",
    alt_username: u.auth.alt_username ?? "",
  };
}

/** Replace the row in place and keep the panel showing the same person. */
function absorb(updated: UserRow) {
  users.value = users.value.map((u) => (u.id === updated.id ? updated : u));
  if (open.value?.id === updated.id) open.value = updated;
}

async function act<T>(key: string, run: () => Promise<T>, done?: string): Promise<T | null> {
  busy.value = key;
  try {
    const result = await run();
    if (done) notifySaved(done);
    return result;
  } catch (e) {
    notifyFailed("Could not do that.", e instanceof ApiError ? (e.firstError ?? e.message) : undefined);
    return null;
  } finally {
    busy.value = "";
  }
}

async function saveLink(employeeId: number | null) {
  if (!open.value) return;
  const res = await act("link", () => api<{ data: UserRow }>(
    "PUT", `/api/v1/authorization/users/${open.value!.id}/employee`,
    { org: org(), body: { employee_id: employeeId } },
  ), employeeId ? "Matched to the employee record." : "Unlinked.");

  if (res) { absorb(res.data); void load(); }
}

/** Ask Microsoft again. Its own act, because it can change how somebody signs in. */
async function rediscover() {
  if (!open.value) return;
  const res = await act("discover", () => api<{ data: UserRow }>(
    "POST", `/api/v1/authorization/users/${open.value!.id}/discover`, { org: org() },
  ));

  if (res) {
    absorb(res.data);
    draft.value.auth_method = res.data.auth.method === "UNRESOLVED" ? "" : res.data.auth.method;
    void load();
  }
}

async function saveMethod() {
  if (!open.value || !draft.value.auth_method) return;
  const res = await act("method", () => api<{ data: UserRow }>(
    "PUT", `/api/v1/authorization/users/${open.value!.id}/auth-method`,
    { org: org(), body: {
      auth_method: draft.value.auth_method,
      otp_preference: draft.value.auth_method === "SSO" ? null : draft.value.otp_preference,
      alt_username: draft.value.alt_username || null,
    } },
  ), "Sign-in method saved.");

  if (res) { absorb(res.data); void load(); }
}

async function resetPassword() {
  if (!open.value) return;
  const res = await act("reset", () => api<{ message: string; data: UserRow }>(
    "POST", `/api/v1/authorization/users/${open.value!.id}/reset-password`, { org: org() },
  ));
  if (res) { notifySaved(res.message); absorb(res.data); }
}

/*
 * Whether the administrator is about to contradict Microsoft.
 *
 * Allowed — discovery can be behind a mailbox created this morning — but it
 * should be a deliberate act rather than something noticed later.
 */
/*
 * Enrolment.
 *
 * The secret and the recovery codes come back exactly once each, in the
 * responses that create them, and are held only in this component's memory
 * until the panel closes. Nothing writes them to storage and no endpoint
 * returns them again — an administrator who navigates away re-issues, which is
 * the right trade against a secret that can be fetched twice.
 */
const enrolment = ref<{ uri: string; secret: string } | null>(null);
const qrDataUrl = ref("");
const showSecret = ref(false);
const confirmCode = ref("");
const recoveryCodes = ref<string[]>([]);

function endEnrolment() {
  enrolment.value = null;
  qrDataUrl.value = "";
  showSecret.value = false;
  confirmCode.value = "";
}

async function issueAuthenticator(reissue = false) {
  if (!open.value) return;
  if (reissue && !confirm(
    `Re-issue an authenticator for ${open.value.name}? Their current app and every recovery code they hold stop working.`,
  )) return;

  const res = await act("issue", () => api<{ data: { uri: string; secret: string; user: UserRow } }>(
    "POST", `/api/v1/authorization/users/${open.value!.id}/authenticator`,
    { org: org(), body: reissue ? { confirm_reissue: true } : {} },
  ));

  if (!res) return;

  recoveryCodes.value = [];
  enrolment.value = { uri: res.data.uri, secret: res.data.secret };
  // Rendered locally: the URI carries the secret, so it must not be handed to
  // a third-party chart service to draw.
  qrDataUrl.value = await QRCode.toDataURL(res.data.uri, { width: 220, margin: 1 });
  absorb(res.data.user);
}

async function confirmAuthenticator() {
  if (!open.value || !confirmCode.value) return;

  const res = await act("confirm", () => api<{ data: { recovery_codes: string[]; user: UserRow } }>(
    "POST", `/api/v1/authorization/users/${open.value!.id}/authenticator/confirm`,
    { org: org(), body: { code: confirmCode.value } },
  ), "Authenticator enrolled.");

  if (!res) return;

  recoveryCodes.value = res.data.recovery_codes;
  absorb(res.data.user);
  endEnrolment();
  void load();
}

/*
 * Withdrawing access.
 *
 * The typed confirmation is not ceremony: the list is alphabetical, the rows
 * look alike, and this disables a Microsoft account and closes live sessions.
 * Typing the address means the person has read which row they are on.
 */
const deprovisioning = ref(false);
const confirmEmail = ref("");
const reason = ref("");

function resetProvisionPanel(): void {
  provisioning.value = false;
  provisionUpn.value = "";
  provisionResult.value = null;
}

function beginDeprovision() {
  deprovisioning.value = true;
  confirmEmail.value = "";
  reason.value = "";
}

async function deprovision() {
  if (!open.value) return;

  const res = await act("deprovision", () => api<{ message: string; data: UserRow }>(
    "POST", `/api/v1/authorization/users/${open.value!.id}/deprovision`,
    { org: org(), body: { confirm_email: confirmEmail.value, reason: reason.value || null } },
  ));

  if (!res) return;

  // The message says which halves happened — here, and in Office 365. A
  // partial result has to be read, not glanced at.
  notifySaved(res.message);
  absorb(res.data);
  deprovisioning.value = false;
  void load();
}

// ── Provisioning ────────────────────────────────────────────────────────────

const canProvision = computed(() => can("users.provision"));
const provisioning = ref(false);
const provisionUpn = ref("");
const provisionResult = ref<{ message: string; temporary_password: string | null } | null>(null);

async function provision() {
  if (!open.value) return;

  const res = await act("provision", () => api<{
    message: string;
    result: { message: string; temporary_password: string | null };
    data: UserRow;
  }>("POST", `/api/v1/authorization/users/${open.value!.id}/provision`, {
    org: org(),
    body: { upn: provisionUpn.value.trim() || null },
  }));

  if (!res) return;

  provisioning.value = false;
  // Held on screen rather than toasted: the temporary password is shown once
  // and a toast that vanishes after four seconds is not "once", it is "never".
  provisionResult.value = res.result;
  absorb(res.data);
  void load();
}

// Accounts Office 365 holds that nothing here explains.
interface DirectoryStranger {
  azure_id: string; display_name: string | null; upn: string | null; mail: string | null;
  job_title: string | null; department: string | null; enabled: boolean;
  suggested: { first_name: string; last_name: string | null };
}

const strangers        = ref<DirectoryStranger[]>([]);
const strangersLoading = ref(false);
const strangersLoaded  = ref(false);
const strangersMessage = ref("");
const creatingFor      = ref<string | null>(null);

/**
 * Asked for, not loaded on sight.
 *
 * It walks the whole tenant directory, which is a slow call to make on every
 * visit to a screen most people open to change one person's authenticator.
 */
async function loadStrangers(): Promise<void> {
  strangersLoading.value = true; strangersMessage.value = "";
  try {
    const res = await api<{ data: DirectoryStranger[]; message?: string }>(
      "GET", "/api/v1/authorization/directory/unmatched", { org: org() });
    strangers.value = res.data;
    strangersMessage.value = res.message ?? "";
    strangersLoaded.value = true;
  } catch (e) {
    notifyFailed(e instanceof Error ? e.message : "Could not read the directory.");
  } finally {
    strangersLoading.value = false;
  }
}

async function createEmployeeFrom(account: DirectoryStranger): Promise<void> {
  const address = account.upn ?? account.mail;
  if (!address) { notifyFailed("That account has no address to file under."); return; }

  if (!confirm(`Create an employee profile for ${account.display_name ?? address}?`)) return;

  creatingFor.value = account.azure_id;
  try {
    await api("POST", "/api/v1/authorization/directory/create-employee", {
      org: org(),
      body: {
        azure_id: account.azure_id,
        email: address,
        first_name: account.suggested.first_name || (account.display_name ?? address),
        last_name: account.suggested.last_name,
        job_title: account.job_title,
      },
    });
    notifySaved("Employee profile created, and the login linked to the directory account.");
    // It is no longer a stranger.
    strangers.value = strangers.value.filter(a => a.azure_id !== account.azure_id);
    void load();
  } catch (e) {
    notifyFailed(e instanceof Error ? e.message : "Could not create the profile.");
  } finally {
    creatingFor.value = null;
  }
}

async function reprovision() {
  if (!open.value) return;
  if (!confirm(`Restore access for ${open.value.name}?`)) return;

  const res = await act("reprovision", () => api<{ message: string; data: UserRow }>(
    "POST", `/api/v1/authorization/users/${open.value!.id}/reprovision`, { org: org() },
  ));

  if (!res) return;
  notifySaved(res.message);
  absorb(res.data);
  void load();
}

const overriding = computed(() => {
  const u = open.value;
  if (!u || !draft.value.auth_method) return false;
  if (u.auth.discovery.status === "found_enabled")  return draft.value.auth_method !== "SSO";
  if (["not_found", "found_disabled"].includes(u.auth.discovery.status)) return draft.value.auth_method === "SSO";
  return false;
});

const methodChanged = computed(() => {
  const u = open.value;
  if (!u) return false;
  return draft.value.auth_method !== (u.auth.method === "UNRESOLVED" ? "" : u.auth.method)
    || (draft.value.auth_method !== "SSO" && draft.value.otp_preference !== (u.auth.otp_preference ?? "AUTHENTICATOR"))
    || draft.value.alt_username !== (u.auth.alt_username ?? "");
});

const STATUS_PILL: Record<string, string> = {
  active: "pill-ok", pending: "pill-warn", suspended: "pill-warn", disabled: "pill-danger",
};

/** "2 hours ago" reads as recency; an ISO string does not. */
function ago(iso: string): string {
  const secs = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  const steps: [number, Intl.RelativeTimeFormatUnit][] =
    [[60, "second"], [3600, "minute"], [86400, "hour"], [604800, "day"], [2629800, "week"], [31557600, "month"]];
  const fmt = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

  let prev = 1;
  for (const [limit, unit] of steps) {
    if (secs < limit) return fmt.format(-Math.floor(secs / prev), unit);
    prev = limit;
  }
  return fmt.format(-Math.floor(secs / 31557600), "year");
}

const initials = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? "").join("");

onMounted(load);
</script>

<template>
  <div class="ud">
    <header class="ud-head">
      <h1>Users &amp; Access</h1>
      <p class="lead">
        Every login, how it signs in, and the employee record behind it. Office 365
        decides the first: everybody Entra knows signs in through Entra, everybody
        else needs a local username and a one-time code.
      </p>
    </header>

    <!-- One area, two registers. -->
    <nav class="ud-tabs">
      <button class="ud-tab" @click="router.push({ name: 'role-assignments' })">Roles</button>
      <button class="ud-tab active" aria-current="page">Users</button>
    </nav>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>

    <div v-if="totals" class="ud-kpis">
      <div class="ud-kpi">
        <span class="ud-kpi-label">Logins</span>
        <span class="ud-kpi-value">{{ totals.users }}</span>
        <span class="ud-kpi-sub">{{ totals.linked }} matched to an employee</span>
      </div>
      <div class="ud-kpi">
        <span class="ud-kpi-label">SSO-linked (O365)</span>
        <span class="ud-kpi-value">{{ totals.sso }}</span>
        <span class="ud-kpi-sub">signing in through Entra</span>
      </div>
      <div class="ud-kpi">
        <span class="ud-kpi-label">Local authenticator</span>
        <span class="ud-kpi-value">{{ totals.local }}</span>
        <span class="ud-kpi-sub">local username and a one-time code</span>
      </div>
      <!-- One tile for five different problems with the same consequence:
           never discovered, a lookup that errored, a local account that never
           enrolled, no employee record, no role. Each stops the person
           working, so each belongs behind the same number. -->
      <div class="ud-kpi">
        <span class="ud-kpi-label">Needs attention</span>
        <span :class="['ud-kpi-value', { warn: totals.needs_attention > 0 }]">{{ totals.needs_attention }}</span>
        <span class="ud-kpi-sub">unresolved, unenrolled, or missing a record or role</span>
      </div>
    </div>

    <div class="ud-toolbar">
      <input v-model="search" class="control ud-search" type="search"
             placeholder="Search by name or address" @keyup.enter="load" />
      <div class="ud-chips" role="group" aria-label="Filter by authentication path">
        <button v-for="c in chips" :key="c.key"
                :class="['ud-chip', { on: chip === c.key }]"
                :aria-pressed="chip === c.key" @click="chip = c.key">{{ c.label }}</button>
      </div>
    </div>

    <p v-if="loading" class="muted pad">Loading…</p>

    <template v-else>
      <div class="panel">
        <table class="tbl">
          <thead>
            <tr><th>Login</th><th>Employee record</th><th>Auth method</th><th>Roles</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-if="!rows.length">
              <td colspan="6" class="muted pad">
                {{ chip === "attention"
                   ? "Nothing needs attention: every login is discovered, enrolled, and joined to a record and a role."
                   : "No logins match that filter." }}
              </td>
            </tr>
            <tr v-for="u in rows" :key="u.id">
              <td>
                <span class="ud-person">
                  <span class="ud-avatar">{{ initials(u.name) }}</span>
                  <span class="ud-person-id">
                    <span class="ud-person-name">{{ u.name }}</span>
                    <span class="ud-person-mail">{{ u.email }}</span>
                  </span>
                </span>
              </td>

              <td>
                <template v-if="u.employee">
                  <span class="ud-emp">{{ u.employee.name }}</span>
                  <span v-if="u.employee.employee_number" class="ud-emp-no">{{ u.employee.employee_number }}</span>
                  <!-- Not necessarily wrong — people use personal addresses —
                       but it is the thing to look at when somebody says the
                       wrong name appears. -->
                  <span v-if="u.email_matches === false" class="ud-flag">address differs</span>
                </template>
                <span v-else class="ud-none">Not matched</span>
              </td>

              <td>
                <!-- The pill says the conclusion; hovering says what Graph
                     actually answered and when, because those are different
                     facts and the second is what you check the first against. -->
                <span :class="['ud-auth', AUTH_TONE[u.auth.method] ?? 'auth-unknown']"
                      :title="`${DISCOVERY_LABEL[u.auth.discovery.status] ?? u.auth.discovery.status}${
                        u.auth.discovery.checked_at ? ` · checked ${ago(u.auth.discovery.checked_at)}` : ''}`">
                  {{ u.auth.pending_enrollment ? "Pending enrollment" : (AUTH_LABEL[u.auth.method] ?? u.auth.method) }}
                </span>
                <!-- A refused lookup is our misconfiguration, not a fact about
                     the person, so it is marked rather than folded in. -->
                <span v-if="u.auth.discovery.status === 'error'" class="ud-flag" :title="u.auth.discovery.error ?? ''">
                  lookup failed
                </span>
              </td>

              <td>
                <span v-if="!u.roles.length" class="ud-none">None</span>
                <span v-else class="ud-roles">{{ u.roles.map((r) => r.name).join(", ") }}</span>
              </td>

              <td><span :class="['pill', STATUS_PILL[u.status] ?? 'pill-neutral']">{{ u.status }}</span>
                <span v-if="u.sso_only" class="ud-sso" title="Signs in through Microsoft; there is no password to reset">SSO</span>
              </td>

              <td class="ud-actions">
                <button class="btn-ghost btn-sm" @click="manage(u)">
                  Manage
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- The same question from the other side. Two screens would let the
           answers drift apart. -->
      <!-- The other direction: accounts Office 365 has that nothing here
           explains. Suggestions, never an import — creating employee records
           off a directory sync is how a shared mailbox ends up on the org
           chart. -->
      <div v-if="canProvision" class="panel ud-gap">
        <div class="panel-head" style="display:flex;align-items:center;justify-content:space-between">
          <h2>
            In Office 365, not here
            <span v-if="strangersLoaded">({{ strangers.length }})</span>
          </h2>
          <button class="btn-ghost" :disabled="strangersLoading" @click="loadStrangers">
            {{ strangersLoading ? "Checking…" : (strangersLoaded ? "Check again" : "Check Office 365") }}
          </button>
        </div>

        <p class="muted pad ud-gap-note">
          Accounts Microsoft knows that no login here matches — a starter IT set
          up first, a service account, or a leaver nobody told HR about.
          <span v-if="strangersMessage"><br />{{ strangersMessage }}</span>
        </p>

        <p v-if="strangersLoaded && !strangers.length" class="muted pad">
          Nothing unaccounted for. Every Office 365 account matches a login here.
        </p>

        <ul v-else-if="strangers.length" class="ud-free">
          <li v-for="a in strangers" :key="a.azure_id">
            <span class="ud-person">
              <span class="ud-avatar quiet">{{ initials(a.display_name ?? a.upn ?? "?") }}</span>
              <span class="ud-person-id">
                <span class="ud-person-name">
                  {{ a.display_name ?? a.upn }}
                  <span v-if="!a.enabled" class="pill">disabled in Entra</span>
                </span>
                <span class="ud-person-mail">
                  {{ a.upn }}
                  <template v-if="a.job_title"> · {{ a.job_title }}</template>
                  <template v-if="a.department"> · {{ a.department }}</template>
                </span>
              </span>
            </span>
            <button class="btn-ghost" :disabled="creatingFor === a.azure_id"
                    @click="createEmployeeFrom(a)">
              {{ creatingFor === a.azure_id ? "Creating…" : "Create employee profile" }}
            </button>
          </li>
        </ul>
      </div>

      <div v-if="unlinked.length" class="panel ud-gap">
        <div class="panel-head">
          <h2>Employees with no login ({{ unlinked.length }})</h2>
        </div>
        <p class="muted pad ud-gap-note">
          On the payroll and unable to sign in. Creating the account is a separate
          act — this names who is waiting for one.
        </p>
        <ul class="ud-free">
          <li v-for="e in unlinked" :key="e.id">
            <span class="ud-person">
              <span class="ud-avatar quiet">{{ initials(e.name) }}</span>
              <span class="ud-person-id">
                <span class="ud-person-name">{{ e.name }}</span>
                <span class="ud-person-mail">{{ e.work_email ?? "No work address on file" }}</span>
              </span>
            </span>
            <span v-if="e.employee_number" class="ud-emp-no">{{ e.employee_number }}</span>
          </li>
        </ul>
      </div>
    </template>

    <!-- ── Manage user ──────────────────────────────────────────────────
         One panel for everything about one person: what Office 365 says, how
         they sign in, and which employee they are. Those were three separate
         decisions, and each constrains the next. -->
    <div v-if="open" class="ud-drawer-backdrop" @click.self="open = null">
      <aside class="ud-drawer" role="dialog" aria-modal="true" aria-label="Manage user">
        <header class="ud-drawer-head">
          <h3 class="ud-drawer-title">Manage user</h3>
          <button class="ud-close" aria-label="Close" @click="open = null">×</button>
        </header>

        <div class="ud-drawer-id">
          <span class="ud-avatar lg">{{ initials(open.name) }}</span>
          <span class="ud-person-id">
            <span class="ud-person-name">{{ open.name }}</span>
            <span class="ud-person-mail">{{ open.email }}</span>
          </span>
          <span :class="['ud-auth', AUTH_TONE[open.auth.method] ?? 'auth-unknown']">
            {{ AUTH_LABEL[open.auth.method] ?? open.auth.method }}
          </span>
        </div>

        <!-- ── Office 365 discovery ───────────────────────────────────── -->
        <section class="ud-sec">
          <h4 class="ud-sec-title">Office 365 discovery</h4>

          <div :class="['ud-disc', `disc-${open.auth.discovery.status}`]">
            <p class="ud-disc-head">{{ DISCOVERY_LABEL[open.auth.discovery.status] ?? open.auth.discovery.status }}</p>
            <!-- The error is our misconfiguration, not a fact about them, and
                 it says which permission is missing rather than "failed". -->
            <p v-if="open.auth.discovery.error" class="ud-disc-body">{{ open.auth.discovery.error }}</p>
            <p v-else-if="open.auth.discovery.status === 'never'" class="ud-disc-body">
              Nobody has asked Microsoft about this login yet, so no method has been decided.
            </p>
            <p v-else class="ud-disc-body">
              accountEnabled: {{ open.auth.discovery.enabled === null ? "—" : open.auth.discovery.enabled }}
            </p>
            <p v-if="open.auth.discovery.checked_at" class="ud-disc-meta">
              Last checked {{ ago(open.auth.discovery.checked_at) }}
            </p>
          </div>

          <button class="btn-ghost" :disabled="busy === 'discover'" @click="rediscover">
            {{ busy === "discover" ? "Asking Microsoft…" : "Re-run discovery" }}
          </button>
        </section>

        <!-- ── Authentication method ──────────────────────────────────── -->
        <section class="ud-sec">
          <h4 class="ud-sec-title">Authentication method</h4>
          <p class="ud-sec-note">How this person proves who they are.</p>

          <div class="ud-choices">
            <label :class="['ud-choice', { on: draft.auth_method === 'SSO' }]">
              <input v-model="draft.auth_method" type="radio" value="SSO" />
              <span class="ud-choice-id">
                <span class="ud-choice-name">SSO (Entra ID / O365)</span>
                <span class="ud-choice-sub">Microsoft holds the password and the second factor</span>
              </span>
            </label>
            <label :class="['ud-choice', { on: draft.auth_method === 'LOCAL_TOTP' }]">
              <input v-model="draft.auth_method" type="radio" value="LOCAL_TOTP" />
              <span class="ud-choice-id">
                <span class="ud-choice-name">Local authenticator</span>
                <span class="ud-choice-sub">Local username and a one-time code</span>
              </span>
            </label>
          </div>

          <!-- Allowed, because discovery can be behind a mailbox created this
               morning — but it should be deliberate rather than noticed later. -->
          <p v-if="overriding" class="ud-warn">
            This contradicts what Microsoft said. It will be recorded as a manual
            override, and the next discovery run will disagree with it visibly.
          </p>

          <template v-if="draft.auth_method === 'LOCAL_TOTP'">
            <div class="ud-field">
              <span>Local username</span>
              <input v-model="draft.alt_username" class="control" placeholder="j.otieno"
                     autocomplete="off" />
            </div>

            <div class="ud-field">
              <span>One-time code by</span>
              <div class="ud-choices tight">
                <label :class="['ud-choice', { on: draft.otp_preference === 'AUTHENTICATOR' }]">
                  <input v-model="draft.otp_preference" type="radio" value="AUTHENTICATOR" />
                  <span class="ud-choice-name">Authenticator app</span>
                </label>
                <label :class="['ud-choice', { on: draft.otp_preference === 'EMAIL' }]">
                  <input v-model="draft.otp_preference" type="radio" value="EMAIL" />
                  <span class="ud-choice-name">Email</span>
                </label>
              </div>
            </div>
          </template>

          <button class="btn-primary" :disabled="busy === 'method' || !draft.auth_method || !methodChanged"
                  @click="saveMethod">
            {{ busy === "method" ? "Saving…" : "Save sign-in method" }}
          </button>
        </section>

        <!-- ── Password ───────────────────────────────────────────────── -->
        <section class="ud-sec">
          <h4 class="ud-sec-title">Password</h4>
          <p v-if="open.auth.method === 'SSO'" class="ud-sec-note">
            Managed in Microsoft Entra. There is no password here to reset.
          </p>
          <template v-else>
            <p class="ud-sec-note">Sends a link to {{ open.email }} to set a new one.</p>
            <button class="btn-ghost" :disabled="busy === 'reset'" @click="resetPassword">
              {{ busy === "reset" ? "Sending…" : "Send reset link" }}
            </button>
          </template>
        </section>

        <!-- ── Authenticator enrolment ────────────────────────────────── -->
        <section v-if="draft.auth_method === 'LOCAL_TOTP'" class="ud-sec">
          <h4 class="ud-sec-title">Authenticator enrolment</h4>

          <!-- The codes, once. They are hashed the moment they are made, so
               this is the only time anybody — us included — can read them. -->
          <div v-if="recoveryCodes.length" class="ud-codes">
            <p class="ud-codes-head">Recovery codes — write these down now</p>
            <p class="ud-codes-note">
              Each works once, in place of the app. They are stored hashed, so this
              is the only time they can be read. Closing this panel loses them.
            </p>
            <ul class="ud-codes-list">
              <li v-for="rc in recoveryCodes" :key="rc">{{ rc }}</li>
            </ul>
          </div>

          <template v-else-if="enrolment">
            <p class="ud-sec-note">
              Scan with Microsoft Authenticator, Google Authenticator or any TOTP app,
              then type the code it shows. Nothing works until that code is accepted.
            </p>

            <div class="ud-enrol">
              <img :src="qrDataUrl" alt="Authenticator QR code" class="ud-qr" />
              <div class="ud-enrol-side">
                <button class="link-btn" @click="showSecret = !showSecret">
                  {{ showSecret ? "Hide secret key" : "Show secret key" }}
                </button>
                <code v-if="showSecret" class="ud-secret">{{ enrolment.secret }}</code>

                <label class="ud-field">
                  <span>Code from the app</span>
                  <input v-model="confirmCode" class="control" inputmode="numeric"
                         autocomplete="one-time-code" maxlength="6" placeholder="123456" />
                </label>

                <div class="ud-drawer-actions">
                  <button class="btn-ghost" :disabled="busy === 'confirm'" @click="endEnrolment">Cancel</button>
                  <button class="btn-primary" :disabled="busy === 'confirm' || confirmCode.length < 6"
                          @click="confirmAuthenticator">
                    {{ busy === "confirm" ? "Checking…" : "Confirm" }}
                  </button>
                </div>
              </div>
            </div>
          </template>

          <template v-else-if="open.auth.has_authenticator">
            <p class="ud-sec-note">
              Enrolled. Re-issuing invalidates the app they already set up and every
              recovery code they hold, so it is a deliberate act.
            </p>
            <button class="btn-ghost" :disabled="busy === 'issue'" @click="issueAuthenticator(true)">
              {{ busy === "issue" ? "Issuing…" : "Re-issue authenticator" }}
            </button>
          </template>

          <template v-else>
            <p class="ud-sec-note">
              Not enrolled, so this login cannot be used yet. Issue a secret and have
              them scan it while you are with them.
            </p>
            <button class="btn-primary" :disabled="busy === 'issue'" @click="issueAuthenticator(false)">
              {{ busy === "issue" ? "Issuing…" : "Issue authenticator" }}
            </button>
          </template>
        </section>

        <!-- ── Office 365 account ──────────────────────────────────────────
             The gap this closes: a starter has a record and a login on their
             first morning, then waits on a separate request to IT for the
             mailbox they cannot do the job without. -->
        <section v-if="canProvision && open.status !== 'disabled'" class="ud-sec">
          <h4 class="ud-sec-title">Office 365 account</h4>

          <template v-if="open.auth.discovery.status === 'found_enabled'">
            <p class="ud-sec-note">Signing in through Entra already.</p>
          </template>

          <template v-else-if="provisionResult">
            <p class="ud-sec-note">{{ provisionResult.message }}</p>
            <div v-if="provisionResult.temporary_password" class="ud-warn">
              Temporary password — <strong>shown once</strong>, and stored nowhere.
              They set their own on first sign-in.
              <div style="font-family:var(--font-family-mono);font-size:var(--text-sm-size);margin-top:var(--space-2)">
                {{ provisionResult.temporary_password }}
              </div>
            </div>
          </template>

          <template v-else-if="!provisioning">
            <p class="ud-sec-note">
              No Office 365 account. Creating one uses the name, job title and
              department already on their employee record.
            </p>
            <button class="btn-ghost" @click="provisioning = true">Create in Office 365…</button>
          </template>

          <template v-else>
            <label class="ud-field">
              <span>Sign-in name</span>
              <input v-model="provisionUpn" class="control" :placeholder="open.email"
                     autocomplete="off" spellcheck="false" />
              <small class="ud-hint">Theirs by default. Change it for a second domain.</small>
            </label>

            <div class="ud-drawer-actions">
              <button class="btn-ghost" :disabled="busy === 'provision'" @click="provisioning = false">Cancel</button>
              <button class="btn-primary" :disabled="busy === 'provision'" @click="provision">
                {{ busy === "provision" ? "Creating…" : "Create account" }}
              </button>
            </div>
          </template>
        </section>

        <!-- ── Access ─────────────────────────────────────────────────────
             Last in the panel on purpose: everything above is adjustment,
             this is withdrawal. -->
        <section class="ud-sec ud-danger">
          <h4 class="ud-sec-title">Access</h4>

          <template v-if="open.status === 'disabled'">
            <p class="ud-sec-note">
              Deprovisioned. They cannot sign in here, and their Office 365
              account was disabled if they had one.
            </p>
            <button class="btn-ghost" :disabled="busy === 'reprovision'" @click="reprovision">
              {{ busy === "reprovision" ? "Restoring…" : "Restore access" }}
            </button>
          </template>

          <template v-else-if="!deprovisioning">
            <p class="ud-sec-note">
              Disables this login, closes the sessions they are holding now, and
              disables their Office 365 account — mail, Teams, files. Reversible.
            </p>
            <button class="btn-danger" @click="beginDeprovision">Deprovision…</button>
          </template>

          <template v-else>
            <p class="ud-warn">
              This disables <strong>{{ open.email }}</strong> here and in Office 365,
              and revokes the sessions Microsoft has already issued. Type the address
              to confirm.
            </p>

            <label class="ud-field">
              <span>Type {{ open.email }}</span>
              <input v-model="confirmEmail" class="control" autocomplete="off" spellcheck="false" />
            </label>

            <label class="ud-field">
              <span>Reason (optional, kept in the audit log)</span>
              <input v-model="reason" class="control" placeholder="Left the company, 1 Sep" />
            </label>

            <div class="ud-drawer-actions">
              <button class="btn-ghost" :disabled="busy === 'deprovision'" @click="deprovisioning = false">
                Cancel
              </button>
              <button class="btn-danger"
                      :disabled="busy === 'deprovision' || confirmEmail.trim().toLowerCase() !== open.email.toLowerCase()"
                      @click="deprovision">
                {{ busy === "deprovision" ? "Withdrawing…" : "Deprovision" }}
              </button>
            </div>
          </template>
        </section>

        <!-- ── Employee record ────────────────────────────────────────── -->
        <section class="ud-sec">
          <h4 class="ud-sec-title">Employee record</h4>
          <p class="ud-sec-note">
            Decides whose records this login sees as their own — leads, tasks, leave.
          </p>

          <div class="ud-field">
            <EmployeePicker v-model="chosen" placeholder="Search by name, number or email…" />
          </div>

          <div class="ud-drawer-actions">
            <button v-if="open.employee" class="btn-ghost" :disabled="busy === 'link'" @click="saveLink(null)">
              Unlink
            </button>
            <button class="btn-primary"
                    :disabled="busy === 'link' || !chosen || chosen === open.employee?.id"
                    @click="saveLink(chosen)">
              {{ busy === "link" ? "Saving…" : open.employee ? "Change record" : "Match record" }}
            </button>
          </div>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
@reference "../theme.css";

.ud { padding: var(--space-6); }

.ud-head h1 {
  @apply font-semibold;
  font-size: var(--display-xs-size); line-height: var(--display-xs-line);
  color: var(--color-text); margin: 0;
}
.lead {
  font-size: var(--text-sm-size); color: var(--color-text-light);
  margin: var(--space-2) 0 var(--space-4); max-width: 68ch;
}

.ud-tabs { @apply flex; gap: var(--space-1); margin-bottom: var(--space-5);
  border-bottom: 1px solid var(--color-border); }
.ud-tab {
  @apply cursor-pointer border-none bg-transparent;
  padding: var(--space-2) var(--space-3); margin-bottom: -1px;
  font-family: inherit; font-size: var(--text-sm-size); color: var(--color-text-muted);
  border-bottom: 2px solid transparent;
}
.ud-tab:hover { color: var(--color-text); }
.ud-tab.active { color: var(--color-text); font-weight: 600; border-bottom-color: var(--color-brand-base); }

/* Hairline-separated cells, matching the customer pages so the whole
   application reads as one place. */
.ud-kpis {
  @apply grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 1px; margin-bottom: var(--space-4);
  background: var(--color-border); border: 1px solid var(--color-border);
  border-radius: var(--radius-md); overflow: hidden;
}
@media (max-width: 60rem) { .ud-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 32rem) { .ud-kpis { grid-template-columns: 1fr; } }

.ud-kpi { @apply flex flex-col; gap: 2px; padding: var(--space-3) var(--space-4);
  background: var(--color-surface); }
.ud-kpi-label {
  font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase;
  letter-spacing: 0.07em; color: var(--color-text-light);
}
.ud-kpi-value { font-size: var(--text-xl-size); font-weight: 600; letter-spacing: -0.5px; color: var(--color-text); }
.ud-kpi-value.warn { color: var(--color-warning-text); }
.ud-kpi-sub { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.ud-toolbar { @apply flex flex-wrap items-center; gap: var(--space-4); margin-bottom: var(--space-4); }
.ud-search { max-width: 22rem; flex: 1; }

.panel {
  background: var(--color-surface); border: 1px solid var(--color-border);
  border-radius: var(--radius-xl); overflow: hidden;
}
.panel-head { padding: var(--space-4); border-bottom: 1px solid var(--color-border); }
.panel-head h2 { @apply font-semibold; font-size: var(--text-lg-size); color: var(--color-text); margin: 0; }

.tbl { @apply w-full; border-collapse: collapse; font-size: var(--text-sm-size); }
.tbl th {
  padding: var(--space-3) var(--space-4); text-align: left;
  background: var(--color-surface-sunken); color: var(--color-text-light);
  font-size: var(--text-xs-size); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;
  border-bottom: 1px solid var(--color-border);
}
.tbl td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border);
  color: var(--color-text); vertical-align: middle; }
.tbl tbody tr:last-child td { border-bottom: none; }

.ud-person { @apply flex items-center; gap: var(--space-3); min-width: 0; }
.ud-avatar {
  @apply flex shrink-0 items-center justify-center; width: 1.9rem; height: 1.9rem;
  border-radius: var(--radius-full); background: var(--color-brand-light);
  color: var(--color-brand-on-surface); font-size: var(--text-xs-size); font-weight: 600;
}
.ud-avatar.quiet { background: var(--color-surface-sunken); color: var(--color-text-muted); }
.ud-person-id   { @apply flex flex-col; gap: 1px; min-width: 0; }
.ud-person-name { font-weight: 600; color: var(--color-text); }
.ud-person-mail { font-size: var(--text-xs-size); color: var(--color-text-muted); }

.ud-emp    { color: var(--color-text); }
.ud-emp-no { font-family: var(--font-family-mono); font-size: var(--text-xs-size);
  color: var(--color-text-light); margin-left: var(--space-2); white-space: nowrap; }
.ud-roles  { font-size: var(--text-xs-size); color: var(--color-text-muted); }
.ud-none   { color: var(--color-text-light); }

.ud-flag {
  margin-left: var(--space-2); padding: 1px var(--space-2);
  border-radius: var(--radius-full); font-size: var(--text-xs-size);
  background: var(--color-warning-subtle); color: var(--color-warning-text);
}
.ud-sso {
  margin-left: var(--space-2); font-size: var(--text-xs-size);
  color: var(--color-text-light); letter-spacing: 0.06em;
}
.ud-actions { text-align: right; white-space: nowrap; }
.btn-sm { padding: 4px var(--space-3); font-size: var(--text-xs-size); }

.ud-gap { margin-top: var(--space-5); }
.ud-gap-note { padding-bottom: 0; }
.ud-free { @apply list-none; margin: 0; padding: var(--space-2) 0 0; }
.ud-free li {
  @apply flex items-center justify-between; gap: var(--space-3);
  padding: var(--space-3) var(--space-4); border-top: 1px solid var(--color-border);
}

.muted { font-size: var(--text-sm-size); color: var(--color-text-muted); }
.pad   { padding: var(--space-4); }
.alert {
  padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  background: var(--color-danger-subtle); color: var(--color-danger-text);
  font-size: var(--text-sm-size); margin-bottom: var(--space-4);
}

.control {
  @apply w-full; font-family: inherit; font-size: var(--text-sm-size);
  padding: 8px var(--space-3); border-radius: var(--radius-md);
  border: 1px solid var(--color-border-strong);
  background: var(--color-surface); color: var(--color-text);
}

.btn-primary, .btn-ghost {
  @apply inline-flex cursor-pointer items-center justify-center;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-primary { @apply border-none; background: var(--color-brand-base); color: var(--color-on-brand); }
.btn-ghost   { background: var(--color-surface); color: var(--color-text); border: 1px solid var(--color-border-strong); }
.btn-primary:disabled, .btn-ghost:disabled { @apply cursor-not-allowed opacity-50; }

.pill {
  @apply inline-flex items-center; padding: 2px var(--space-2);
  border-radius: var(--radius-full); border: 1px solid transparent;
  font-size: var(--text-xs-size); font-weight: 500; text-transform: capitalize;
}
.pill-ok      { background: var(--color-success-subtle); color: var(--color-success-text); }
.pill-warn    { background: var(--color-warning-subtle); color: var(--color-warning-text); }
.pill-danger  { background: var(--color-danger-subtle);  color: var(--color-danger-text); }
.pill-neutral { background: var(--color-surface-sunken);  color: var(--color-text-muted); }

.ud-close { @apply cursor-pointer border-none bg-transparent p-0;
  font-size: 1.5rem; line-height: 1; color: var(--color-text-muted); }
.ud-field { @apply flex w-full flex-col; gap: var(--space-1); }
.ud-field > span { font-size: var(--text-xs-size); color: var(--color-text-muted); }
/* Filter chips: which path, and what is on neither. */
.ud-chips { @apply flex flex-wrap; gap: var(--space-2); }
.ud-chip {
  @apply cursor-pointer; padding: 5px var(--space-3); border-radius: var(--radius-full);
  border: 1px solid var(--color-border-strong); background: var(--color-surface);
  font-family: inherit; font-size: var(--text-xs-size); color: var(--color-text-muted);
  transition: background-color 120ms ease, color 120ms ease, border-color 120ms ease;
}
.ud-chip:hover { background: var(--color-neutral-100); }
.ud-chip.on {
  background: var(--color-brand-base); border-color: var(--color-brand-base);
  color: var(--color-on-brand); font-weight: 600;
}

/* The path someone is on, carried by colour as well as by the word so a
   column of 89 reads at a glance. */
.ud-auth {
  @apply inline-flex items-center; padding: 2px var(--space-2);
  border-radius: var(--radius-full); border: 1px solid transparent;
  font-size: var(--text-xs-size); font-weight: 500; white-space: nowrap;
}
.auth-sso     { background: var(--color-success-subtle); color: var(--color-success-text); }
.auth-local   { background: var(--color-brand-subtle);   color: var(--color-brand-on-surface); }
.auth-email   { background: var(--color-surface-sunken); color: var(--color-text-muted); }
.auth-unknown { background: var(--color-warning-subtle); color: var(--color-warning-text); }
/* ── Manage user drawer ───────────────────────────────────────────────────
   Docked rather than centred: you work down a list, open one person, act, and
   carry on — and the row you came from stays visible beside it. */
.ud-drawer-backdrop { @apply fixed inset-0 flex justify-end z-50; background: rgb(0 0 0 / 0.32); }
.ud-drawer {
  @apply flex flex-col overflow-y-auto; width: min(34rem, 100%); height: 100%;
  gap: var(--space-5); padding: var(--space-5);
  background: var(--color-surface); box-shadow: var(--shadow-lg);
  animation: ud-drawer-in 160ms ease;
}
@keyframes ud-drawer-in { from { transform: translateX(12px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .ud-drawer { animation: none; } }

.ud-drawer-head  { @apply flex items-center justify-between; }
.ud-drawer-title { font-size: var(--text-lg-size); font-weight: 600; margin: 0; color: var(--color-text); }

.ud-drawer-id {
  @apply flex flex-wrap items-center; gap: var(--space-3);
  padding-bottom: var(--space-4); border-bottom: 1px solid var(--color-border);
}
.ud-avatar.lg { width: 2.6rem; height: 2.6rem; font-size: var(--text-sm-size); }

.ud-sec { @apply flex flex-col items-start; gap: var(--space-3); }
.ud-sec + .ud-sec { padding-top: var(--space-5); border-top: 1px solid var(--color-border); }
.ud-sec-title { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); margin: 0; }
.ud-sec-note  { font-size: var(--text-xs-size); color: var(--color-text-muted); margin: 0; }

/* What Graph actually said, coloured by which of the four answers it was. */
.ud-disc { @apply w-full; padding: var(--space-3) var(--space-4); border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface-sunken); }
.ud-disc-head { font-size: var(--text-sm-size); font-weight: 600; margin: 0; color: var(--color-text); }
.ud-disc-body { font-size: var(--text-xs-size); margin: var(--space-1) 0 0; color: var(--color-text-muted); line-height: 1.5; }
.ud-disc-meta { font-size: var(--text-xs-size); margin: var(--space-2) 0 0; color: var(--color-text-light); }
.disc-found_enabled  { background: var(--color-success-subtle); border-color: color-mix(in oklab, var(--color-success-text) 28%, transparent); }
.disc-found_enabled .ud-disc-head { color: var(--color-success-text); }
.disc-error          { background: var(--color-danger-subtle);  border-color: color-mix(in oklab, var(--color-danger-text) 28%, transparent); }
.disc-error .ud-disc-head { color: var(--color-danger-text); }
.disc-not_found, .disc-found_disabled { background: var(--color-warning-subtle); border-color: color-mix(in oklab, var(--color-warning-text) 28%, transparent); }
.disc-not_found .ud-disc-head, .disc-found_disabled .ud-disc-head { color: var(--color-warning-text); }

.ud-choices { @apply grid w-full; grid-template-columns: 1fr 1fr; gap: var(--space-2); }
.ud-choices.tight { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.ud-choice {
  @apply flex cursor-pointer items-start; gap: var(--space-2);
  padding: var(--space-3); border-radius: var(--radius-md);
  border: 1px solid var(--color-border); background: var(--color-surface);
  transition: border-color 120ms ease, background-color 120ms ease;
}
.ud-choice:hover { background: var(--color-neutral-100); }
.ud-choice.on { border-color: var(--color-brand-base); background: var(--color-brand-light); }
.ud-choice-id   { @apply flex flex-col; gap: 2px; min-width: 0; }
.ud-choice-name { font-size: var(--text-sm-size); font-weight: 600; color: var(--color-text); }
.ud-choice-sub  { font-size: var(--text-xs-size); color: var(--color-text-muted); line-height: 1.4; }

.ud-warn {
  @apply w-full; margin: 0; padding: var(--space-3) var(--space-4);
  border-radius: var(--radius-md); background: var(--color-warning-subtle);
  border: 1px solid color-mix(in oklab, var(--color-warning-text) 28%, transparent);
  color: var(--color-warning-text); font-size: var(--text-xs-size); line-height: 1.5;
}

.ud-drawer-actions { @apply flex w-full justify-end; gap: var(--space-2); }
/* Enrolment: the QR beside what is needed to finish. */
.ud-enrol { @apply flex w-full flex-wrap items-start; gap: var(--space-4); }
.ud-qr {
  width: 11rem; height: 11rem; flex-shrink: 0;
  border: 1px solid var(--color-border); border-radius: var(--radius-md);
  background: #fff; padding: var(--space-2);
}
.ud-enrol-side { @apply flex flex-col; gap: var(--space-3); flex: 1; min-width: 12rem; }
.ud-secret {
  @apply block break-all; font-family: var(--font-family-mono); font-size: var(--text-xs-size);
  padding: var(--space-2); border-radius: var(--radius-sm);
  background: var(--color-surface-sunken); color: var(--color-text);
}

/* Recovery codes. Loud on purpose: this is the only time they exist in
   readable form, and closing the panel destroys them. */
.ud-codes {
  @apply w-full; padding: var(--space-4); border-radius: var(--radius-md);
  background: var(--color-warning-subtle);
  border: 1px solid color-mix(in oklab, var(--color-warning-text) 30%, transparent);
}
.ud-codes-head { margin: 0; font-size: var(--text-sm-size); font-weight: 600; color: var(--color-warning-text); }
.ud-codes-note { margin: var(--space-1) 0 var(--space-3); font-size: var(--text-xs-size);
  color: var(--color-warning-text); line-height: 1.5; }
.ud-codes-list {
  @apply grid list-none; grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-1); margin: 0; padding: 0;
  font-family: var(--font-family-mono); font-size: var(--text-sm-size); color: var(--color-text);
}
/* Withdrawal, marked as such — the one section here that takes something away. */
.ud-danger { border-top-color: color-mix(in oklab, var(--color-danger-text) 25%, var(--color-border)); }
.btn-danger {
  @apply inline-flex cursor-pointer items-center justify-center border-none;
  gap: var(--space-2); padding: 8px var(--space-4); border-radius: var(--radius-md);
  background: var(--color-danger-text); color: var(--color-on-brand);
  font-family: inherit; font-size: var(--text-sm-size); font-weight: 500; white-space: nowrap;
}
.btn-danger:disabled { @apply cursor-not-allowed opacity-50; }
</style>
