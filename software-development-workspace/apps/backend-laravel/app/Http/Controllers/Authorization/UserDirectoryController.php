<?php

namespace App\Http\Controllers\Authorization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\Authenticator;
use App\Services\Identity\Deprovisioner;
use App\Services\Identity\Provisioner;
use App\Services\Identity\DirectoryDiscovery;
use App\Support\Authorization\Permission;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Who can log in, and which employee each of them is.
 *
 * Two registers that have to agree. `users` is identity — an address and a
 * password. `employees` is the HR record — a number, a department, a manager,
 * a leave balance. `employees.user_id` is the only thing joining them, and
 * almost everything the application calls "mine" is resolved through it:
 * my leads, my tasks, my leave, my team. A login with no employee behind it
 * can hold every permission in the system and still see an empty screen,
 * because every "own" scope resolves to nobody.
 *
 * Nothing showed that link, so nothing showed when it was missing.
 *
 * Live at the time of writing: 89 logins, 123 employee records, 88 joined. The
 * 35 unjoined are all `exited` — leavers keeping their record for history — so
 * there is no gap, which is exactly the sort of thing that has to be looked at
 * rather than assumed. What the page does surface is that **88 of the 89 logins
 * are `pending`**, set by the staff importer and never activated. Nothing in
 * the login path reads `account_status` today, so they can all still sign in;
 * the flag is a marker rather than a gate. That is worth someone's decision:
 * either it should gate, or it should be cleared.
 */
class UserDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->guard($request, Permission::ROLES_VIEW);

        $users = User::query()
            ->with([
                'employee:id,user_id,employee_number,first_name,last_name,work_email,status',
                'roles:id,name,display_name',
            ])
            ->when($request->string('search')->toString(), function ($q, $term) {
                $like = '%' . $term . '%';
                $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
            })
            ->orderBy('name')
            ->get();

        /*
         * Employees with no login, offered alongside rather than on a page of
         * their own: "who is missing" is the same question as "who is here",
         * asked from the other side, and two screens would let the answers
         * drift apart.
         */
        $unlinked = Employee::query()
            ->whereNull('user_id')
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get(['id', 'employee_number', 'first_name', 'last_name', 'work_email']);

        return response()->json([
            'data' => $users->map(fn (User $u) => $this->payload($u)),

            'unlinked_employees' => $unlinked->map(fn (Employee $e) => [
                'id'              => $e->id,
                'employee_number' => $e->employee_number,
                'name'            => trim($e->first_name . ' ' . $e->last_name),
                'work_email'      => $e->work_email,
            ]),

            'totals' => [
                'users'             => $users->count(),
                'linked'            => $users->filter(fn (User $u) => $u->employee !== null)->count(),
                'without_employee'  => $users->filter(fn (User $u) => $u->employee === null)->count(),

                // The two paths, and everything that is on neither yet.
                'sso'   => $users->filter(fn (User $u) => $u->auth_method === User::AUTH_SSO)->count(),
                'local' => $users->filter(fn (User $u) => in_array($u->auth_method, [User::AUTH_LOCAL_TOTP, User::AUTH_EMAIL_OTP], true))->count(),

                /*
                 * One tile for everything that stops somebody working:
                 * never asked about, a lookup that errored, a local account
                 * that never enrolled, no employee record, or no role. They
                 * are different problems with the same consequence.
                 */
                'needs_attention' => $users->filter(fn (User $u) => $u->auth_method === User::AUTH_UNRESOLVED
                    || $u->discovery_status === User::DISCOVERY_ERROR
                    || $u->isPendingEnrollment()
                    || $u->employee === null
                    || $u->roles->isEmpty())->count(),
                'employees_no_login' => $unlinked->count(),
                /*
                 * The third register. Login → employee → role: the first two
                 * are joined for 88 of 89 people, and the third is joined for
                 * one. A login with an employee record and no role signs in
                 * successfully and can then do nothing, which is a harder
                 * failure to diagnose than being unable to sign in at all.
                 */
                'without_role'      => $users->filter(fn (User $u) => $u->roles->isEmpty())->count(),
                'not_active'        => $users->filter(fn (User $u) => $u->account_status !== User::STATUS_ACTIVE)->count(),
            ],
        ]);
    }

    /**
     * Say which employee a login belongs to, or that it belongs to none.
     *
     * Gated on assigning rather than viewing: this decides whose records a
     * person sees as their own, which is the same kind of decision as granting
     * a role and a larger one than reading the list.
     */
    public function linkEmployee(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $data = $request->validate([
            'employee_id' => ['present', 'nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
        ]);

        $employeeId = $data['employee_id'] ?? null;

        if ($employeeId !== null) {
            $employee = Employee::findOrFail($employeeId);

            /*
             * One employee per login, checked here as well as by the unique
             * index — a constraint violation is a 500 with a Postgres string
             * in it, and the person doing this deserves to be told which
             * account already holds the record.
             */
            $taken = Employee::where('user_id', $user->id)->where('id', '!=', $employee->id)->first();

            if ($employee->user_id !== null && $employee->user_id !== $user->id) {
                $holder = User::find($employee->user_id);

                throw ValidationException::withMessages([
                    'employee_id' => sprintf(
                        '%s is already the employee record for %s. Unlink that login first.',
                        trim($employee->first_name . ' ' . $employee->last_name),
                        $holder?->email ?? 'another login',
                    ),
                ]);
            }

            // Moving a login from one employee to another: the old record
            // loses it, so the login is never claimed twice.
            $taken?->update(['user_id' => null]);

            $employee->update(['user_id' => $user->id]);
        } else {
            Employee::where('user_id', $user->id)->update(['user_id' => null]);
        }

        return response()->json(['data' => $this->payload($user->fresh(['employee', 'roles']))]);
    }

    /**
     * Ask Office 365 about this person again.
     *
     * Its own action rather than something that happens quietly on load: it
     * calls out to Microsoft, it can change how somebody signs in, and both of
     * those deserve to be deliberate and to appear in the audit log.
     */
    public function rediscover(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $before = $user->auth_method;

        try {
            $user = DirectoryDiscovery::forOrganization(app('current.organization_id'))->reconcile($user);
        } catch (RuntimeException $e) {
            // Configuration problems are the caller's to read, not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLogger::record(
            action: 'identity.discovered',
            auditable: $user,
            description: sprintf(
                'Office 365 discovery for %s: %s',
                $user->email,
                $user->discovery_status,
            ),
            changes: ['auth_method' => ['from' => $before, 'to' => $user->auth_method]],
        );

        return response()->json(['data' => $this->payload($user->fresh(['employee', 'roles']))]);
    }

    /**
     * Override the method discovery chose.
     *
     * Allowed, because discovery can be wrong or behind — a mailbox created
     * this morning, a tenant mid-migration — and an administrator who can see
     * the tenant is better placed than a cached lookup. It is recorded as an
     * override rather than a discovery, so the audit log says which of the two
     * decided, and the next discovery run will disagree with it visibly rather
     * than silently.
     */
    public function setAuthMethod(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $data = $request->validate([
            'auth_method'    => ['required', Rule::in([User::AUTH_SSO, User::AUTH_LOCAL_TOTP, User::AUTH_EMAIL_OTP])],
            'otp_preference' => ['nullable', Rule::in([User::OTP_EMAIL, User::OTP_AUTHENTICATOR])],
            'alt_username'   => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'alt_username')->ignore($user->id)],
        ]);

        $before = $user->auth_method;

        /*
         * Moving somebody onto a local method does not enrol them. The secret
         * is issued from the enrolment screen and confirmed by a code they
         * read back — switching the method here only says which door they
         * should be using.
         */
        $user->update([
            'auth_method'    => $data['auth_method'],
            'otp_preference' => $data['otp_preference'] ?? $user->otp_preference,
            'alt_username'   => $data['alt_username'] ?? $user->alt_username,
            'sso_only'       => $data['auth_method'] === User::AUTH_SSO,
        ]);

        AuditLogger::record(
            action: 'identity.method_overridden',
            auditable: $user,
            description: sprintf('%s switched from %s to %s by hand', $user->email, $before, $data['auth_method']),
            changes: ['auth_method' => ['from' => $before, 'to' => $data['auth_method']]],
        );

        return response()->json(['data' => $this->payload($user->fresh(['employee', 'roles']))]);
    }

    /**
     * Send this person a link to set a new password.
     *
     * Only for the local path. An SSO account has no password here to reset —
     * Entra holds it — and offering the button anyway would send a link that
     * changes a credential the login does not use, which is worse than not
     * offering it: it looks like it worked.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        if ($user->auth_method === User::AUTH_SSO) {
            throw ValidationException::withMessages([
                'auth_method' => sprintf(
                    '%s signs in through Microsoft Entra, so their password is managed there. '
                    . 'Switch them to a local method first if that is really what you mean.',
                    $user->email,
                ),
            ]);
        }

        Password::sendResetLink(['email' => $user->email]);

        /*
         * Recorded on the way out rather than on the way back: the broker
         * deliberately does not say whether the address existed, and an audit
         * line that only appears on success would leak that.
         */
        AuditLogger::record(
            action: 'identity.password_reset_sent',
            auditable: $user,
            description: "Password reset link sent to {$user->email}",
        );

        return response()->json([
            'message' => "A reset link is on its way to {$user->email}.",
            'data'    => $this->payload($user->fresh(['employee', 'roles'])),
        ]);
    }

    /**
     * Issue a fresh authenticator secret for somebody.
     *
     * The secret and the QR are returned **once**, in this response, and never
     * stored anywhere they can be read back — the column is encrypted and
     * hidden, and there is no endpoint that returns it again. An administrator
     * who navigates away has to re-issue, which is the correct trade: a secret
     * that can be fetched twice is a secret that can be fetched by the wrong
     * person twice.
     *
     * Re-issuing to somebody already enrolled invalidates their app and their
     * recovery codes, so the caller has to say they mean it.
     */
    public function beginEnrollment(Request $request, User $user, Authenticator $authenticator): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $data = $request->validate([
            'confirm_reissue' => ['nullable', 'boolean'],
        ]);

        if ($user->hasConfirmedAuthenticator() && ! ($data['confirm_reissue'] ?? false)) {
            throw ValidationException::withMessages([
                'confirm_reissue' => sprintf(
                    '%s is already enrolled. Re-issuing invalidates their authenticator app and every '
                    . 'recovery code they hold — send `confirm_reissue` to go ahead.',
                    $user->email,
                ),
            ]);
        }

        $enrollment = $authenticator->beginEnrollment($user);

        AuditLogger::record(
            action: 'identity.authenticator_issued',
            auditable: $user,
            // Never the secret. An audit log is read by more people than the
            // credential should be.
            description: "Authenticator secret issued for {$user->email}",
        );

        return response()->json([
            'data' => [
                'uri'    => $enrollment['uri'],
                'secret' => $enrollment['secret'],
                'user'   => $this->payload($user->fresh(['employee', 'roles'])),
            ],
        ]);
    }

    /**
     * Turn it on, by proving the app was set up.
     *
     * The recovery codes come back once. They are hashed the moment they are
     * generated, so this response is the only time anybody can read them —
     * including us.
     */
    public function confirmEnrollment(Request $request, User $user, Authenticator $authenticator): JsonResponse
    {
        $this->guard($request, Permission::ROLES_ASSIGN);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        try {
            $codes = $authenticator->confirmEnrollment($user, $data['code']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        AuditLogger::record(
            action: 'identity.authenticator_confirmed',
            auditable: $user,
            description: "{$user->email} completed authenticator enrolment",
        );

        return response()->json([
            'data' => [
                'recovery_codes' => $codes,
                'user'           => $this->payload($user->fresh(['employee', 'roles'])),
            ],
        ]);
    }

    /**
     * Take somebody's access away, here and in Office 365.
     *
     * Its own permission, not `roles.assign`: granting a role changes what
     * somebody may do inside this application, while this disables their
     * Microsoft account — mail, Teams, files — and closes the sessions they are
     * holding right now.
     *
     * The typed confirmation is not ceremony. The list is alphabetical and the
     * rows look alike; requiring the address back means the person doing it has
     * read which row they are on.
     */
    public function deprovision(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::USERS_DEPROVISION);

        $data = $request->validate([
            'confirm_email' => ['required', 'string'],
            'reason'        => ['nullable', 'string', 'max:500'],
        ]);

        if (strcasecmp(trim($data['confirm_email']), $user->email) !== 0) {
            throw ValidationException::withMessages([
                'confirm_email' => "That does not match. Type {$user->email} to confirm.",
            ]);
        }

        try {
            $result = Deprovisioner::forOrganization(app('current.organization_id'))
                ->disable($user, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirm_email' => $e->getMessage()]);
        }

        AuditLogger::record(
            action: 'identity.deprovisioned',
            auditable: $user,
            description: sprintf(
                '%s deprovisioned — directory: %s%s',
                $user->email,
                $result['directory'],
                // `nullable` leaves the key absent when nothing was sent.
                ($data['reason'] ?? null) ? '. Reason: ' . $data['reason'] : '',
            ),
            changes: ['account_status' => ['from' => 'active', 'to' => User::STATUS_DISABLED]],
        );

        return response()->json([
            'message' => $result['message'],
            'result'  => $result,
            'data'    => $this->payload($user->fresh(['employee', 'roles'])),
        ]);
    }

    /** Put them back. A leaver is sometimes a leaver by mistake. */
    public function reprovision(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::USERS_DEPROVISION);

        $result = Deprovisioner::forOrganization(app('current.organization_id'))->restore($user);

        AuditLogger::record(
            action: 'identity.reprovisioned',
            auditable: $user,
            description: sprintf('%s restored — directory: %s', $user->email, $result['directory']),
        );

        return response()->json([
            'message' => $result['message'],
            'result'  => $result,
            'data'    => $this->payload($user->fresh(['employee', 'roles'])),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Provisioning
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Give somebody onboarded here an Office 365 account.
     *
     * The gap this closes: a starter gets an employee record and a login on
     * their first morning, and then waits on a separate request to IT for the
     * mailbox they cannot do the job without. Everything the directory needs
     * is already on the record.
     */
    public function provision(Request $request, User $user): JsonResponse
    {
        $this->guard($request, Permission::USERS_PROVISION);

        $data = $request->validate([
            // Offered because a sign-in name is not always the work address —
            // a second domain, or a house convention the record does not know.
            'upn'            => ['nullable', 'email', 'max:255'],
            'usage_location' => ['nullable', 'string', 'size:2'],
        ]);

        try {
            $result = Provisioner::forOrganization(app('current.organization_id'))
                ->provision($user, $data['upn'] ?? null, $data['usage_location'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['upn' => $e->getMessage()]);
        }

        AuditLogger::record(
            action: 'identity.provisioned',
            auditable: $user,
            description: sprintf(
                '%s %s in Office 365 as %s',
                $user->email,
                $result['status'] === 'created' ? 'provisioned' : 'linked to an existing account',
                $result['upn'],
            ),
        );

        return response()->json([
            'message' => $result['message'],
            // The temporary password rides in the response and nowhere else —
            // the same rule the TOTP secret follows.
            'result'  => $result,
            'data'    => $this->payload($user->fresh(['employee', 'roles'])),
        ]);
    }

    /**
     * Office 365 accounts that nothing here explains.
     *
     * A starter IT set up first, a service account, or a leaver nobody told
     * HR about. Suggestions rather than an import: creating employee records
     * off a directory sync is how a shared mailbox ends up on the org chart.
     */
    public function unmatchedDirectoryAccounts(Request $request): JsonResponse
    {
        $this->guard($request, Permission::USERS_PROVISION);

        try {
            $accounts = Provisioner::forOrganization(app('current.organization_id'))
                ->unmatched(app('current.organization_id'));
        } catch (RuntimeException $e) {
            return response()->json(['data' => [], 'message' => $e->getMessage()], 200);
        }

        return response()->json([
            'data' => $accounts,
            'meta' => ['count' => count($accounts)],
        ]);
    }

    /**
     * Accept one of those suggestions: an employee record, and a login linked
     * to the directory account that prompted it.
     */
    public function createFromDirectory(Request $request): JsonResponse
    {
        $this->guard($request, Permission::USERS_PROVISION);

        $data = $request->validate([
            'azure_id'    => ['required', 'string', 'max:64'],
            'email'       => ['required', 'email', 'max:255'],
            'first_name'  => ['required', 'string', 'max:100'],
            'last_name'   => ['nullable', 'string', 'max:100'],
            'job_title'   => ['nullable', 'string', 'max:150'],
            'date_hired'  => ['nullable', 'date'],
            'employment_type' => ['nullable', 'string', 'in:permanent,contract,intern,probation'],
            // A login is not always wanted: a service account belongs on the
            // register without becoming somebody who can sign in here.
            'create_login' => ['sometimes', 'boolean'],
        ]);

        $orgId = app('current.organization_id');

        // Whoever is already here, by either identity. Suggesting an account
        // twice is possible — two people can accept the same suggestion in
        // different tabs — and the second one must not make a duplicate.
        $existing = User::where('azure_id', $data['azure_id'])
            ->orWhere('email', $data['email'])
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => "That account is already on the register as {$existing->email}.",
            ]);
        }

        $created = DB::transaction(function () use ($data, $orgId, $request) {
            $employee = Employee::create([
                'employee_number'         => $this->nextEmployeeNumber($orgId),
                'organization_id'         => $orgId,
                'primary_organization_id' => $orgId,
                'first_name'              => $data['first_name'],
                'last_name'               => $data['last_name'] ?? '',
                'work_email'              => $data['email'],
                'employment_type'         => $data['employment_type'] ?? 'permanent',
                'date_hired'              => $data['date_hired'] ?? now()->toDateString(),
                'status'                  => 'active',
            ]);

            $user = null;

            if ($data['create_login'] ?? true) {
                $user = User::create([
                    'name'     => trim($data['first_name'] . ' ' . ($data['last_name'] ?? '')),
                    'email'    => $data['email'],
                    // Never used: this account signs in through Entra. A
                    // random one rather than a null so nothing can guess it
                    // if the auth method is ever changed by hand.
                    'password' => Hash::make(Str::random(48)),
                    'azure_id' => $data['azure_id'],
                    'auth_method'      => User::AUTH_SSO,
                    'sso_only'         => true,
                    'discovery_status' => User::DISCOVERY_ENABLED,
                    'account_enabled'  => true,
                    'account_status'   => User::STATUS_PENDING,
                    'last_discovery_at' => now(),
                ]);

                $employee->update(['user_id' => $user->id]);
            }

            return ['employee' => $employee, 'user' => $user];
        });

        AuditLogger::record(
            action: 'identity.imported_from_directory',
            auditable: $created['employee'],
            description: sprintf(
                'Created employee %s from the Office 365 account %s',
                $created['employee']->employee_number, $data['email'],
            ),
            organizationId: $orgId,
        );

        return response()->json([
            'message' => 'Employee profile created from the directory account.',
            'data'    => [
                'employee_id'     => $created['employee']->id,
                'employee_number' => $created['employee']->employee_number,
                'user_id'         => $created['user']?->id,
            ],
        ], 201);
    }

    /**
     * The next number in this organisation's sequence.
     *
     * Read from what exists rather than a counter, because employees arrive
     * by import as well as by hand and a counter would fall behind the first
     * time somebody bulk-loaded a payroll.
     */
    private function nextEmployeeNumber(int $orgId): string
    {
        $highest = Employee::withoutGlobalScope('organization')
            ->where('primary_organization_id', $orgId)
            ->selectRaw("MAX(CAST(REGEXP_REPLACE(employee_number, '[^0-9]', '', 'g') AS INTEGER)) AS n")
            ->value('n');

        return 'EMP-' . str_pad((string) (((int) $highest) + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    private function payload(User $u): array
    {
        return [
            'id'     => $u->id,
            'name'   => $u->name,
            'email'  => $u->email,
            'status' => $u->account_status ?? User::STATUS_ACTIVE,

            /*
             * Which way in this person has, and where that answer came from.
             * `pending_enrollment` is a state of its own: a local account with
             * a secret drawn but never confirmed can sign in with nothing.
             */
            'auth' => [
                'method'             => $u->auth_method ?? User::AUTH_UNRESOLVED,
                'otp_preference'     => $u->otp_preference,
                'pending_enrollment' => $u->isPendingEnrollment(),
                'alt_username'       => $u->alt_username,
                'discovery'          => [
                    'status'      => $u->discovery_status ?? User::DISCOVERY_NEVER,
                    'enabled'     => $u->account_enabled,
                    'checked_at'  => $u->last_discovery_at?->toIso8601String(),
                    // Kept distinct from an answer: a refused lookup is our
                    // misconfiguration, not a fact about the person.
                    'error'       => $u->discovery_error,
                ],
                'has_authenticator' => $u->hasConfirmedAuthenticator(),
            ],
            // An SSO-only account has no password to reset, which changes what
            // can be done about somebody who cannot get in.
            'sso_only' => (bool) $u->sso_only,

            'employee' => $u->employee ? [
                'id'              => $u->employee->id,
                'employee_number' => $u->employee->employee_number,
                'name'            => trim($u->employee->first_name . ' ' . $u->employee->last_name),
                'work_email'      => $u->employee->work_email,
                'status'          => $u->employee->status,
            ] : null,

            /*
             * Whether the two registers disagree about the same person. A login
             * whose address is not the employee's work address is not
             * necessarily wrong — people use personal addresses — but it is the
             * thing to look at when somebody says the wrong name appears.
             */
            'email_matches' => $u->employee?->work_email
                ? strcasecmp($u->email, $u->employee->work_email) === 0
                : null,

            'roles' => $u->roles->map(fn ($r) => [
                'id'    => $r->id,
                'name'  => $r->display_name ?: $r->name,
                'organization_id' => $r->pivot->organization_id,
            ])->values(),
        ];
    }

    private function guard(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
