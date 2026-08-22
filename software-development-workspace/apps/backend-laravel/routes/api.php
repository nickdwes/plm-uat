<?php

use App\Http\Controllers\Recruitment\TalentRequisitionController;
use App\Http\Controllers\HR\ComplianceController;
use App\Http\Controllers\Benefits\BenefitController;
use App\Http\Controllers\HR\DocumentController;
use App\Http\Controllers\HR\EmployeeController;
use App\Http\Controllers\HR\EmployeeMovementController;
use App\Http\Controllers\HR\EmployeeEmergencyContactController;
use App\Http\Controllers\HR\EmployeeDependentController;
use App\Http\Controllers\HR\EmployeeVehicleController;
use App\Http\Controllers\HR\DashboardController;
use App\Http\Controllers\HR\OnboardingController;
use App\Http\Controllers\HR\OrgManagementController;
use App\Http\Controllers\HR\OrganizationController;
use App\Http\Controllers\HR\PerformanceController;
use App\Http\Controllers\HR\PreboardingController;
use App\Http\Controllers\Leave\LeaveController;
use App\Http\Controllers\Leave\LeaveAdminController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\Payroll\PaymentFileController;
use App\Http\Controllers\Support\AutomationController;
use App\Http\Controllers\Support\CatalogueController;
use App\Http\Controllers\Support\ChangeController;
use App\Http\Controllers\Support\KnowledgeController;
use App\Http\Controllers\Support\MailWebhookController;
use App\Http\Controllers\Support\PortalController;
use App\Http\Controllers\Support\ReportController;
use App\Http\Controllers\Support\MailboxController;
use App\Http\Controllers\Support\RoutingController;
use App\Http\Controllers\Support\SlaController;
use App\Http\Controllers\Support\TicketController;
use App\Http\Controllers\Support\WorkspaceController;
use App\Http\Controllers\Payroll\PayrollRunItemController;
use App\Http\Controllers\Payroll\RecurringDeductionController;
use App\Http\Controllers\Payroll\PayrollConfigController;
use App\Http\Controllers\OKR\OkrController;
use App\Http\Controllers\Recruitment\ApplicationController;
use App\Http\Controllers\Recruitment\CandidateController;
use App\Http\Controllers\Recruitment\InterviewController;
use App\Http\Controllers\Recruitment\VacancyController;
use App\Http\Controllers\Recruitment\VacancyTemplateController;
use App\Http\Controllers\Recruitment\WorkflowController;
use App\Http\Controllers\Training\TrainingItemController;
use App\Http\Controllers\Training\CompetencyController;
use App\Http\Controllers\Training\TrainingRecordController;
use App\Http\Controllers\Training\TrainingDashboardController;
use App\Http\Controllers\Training\TrainingAssignmentController;
use App\Http\Controllers\Training\VendorComplianceController;
use App\Http\Controllers\Vendor\VendorController;
use App\Http\Controllers\Vendor\VendorProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Attendance\AttendanceAnalyticsController;
use App\Http\Controllers\Attendance\AttendanceCommitmentController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\EnablementDayController;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Pipeline\LeadController;
use App\Http\Controllers\Pipeline\OpportunityController;
use App\Http\Controllers\Pipeline\OpportunityPipelineController;
use App\Http\Controllers\Pipeline\ResearchController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Project\ProjectMilestoneController;
use App\Http\Controllers\Task\LeaveHandoverController;
use App\Http\Controllers\Task\TaskBoardController;
use App\Http\Controllers\Task\TaskController;
use App\Http\Controllers\Task\TaskRecurrenceController;
use App\Http\Controllers\Asset\AssetController;
use App\Http\Controllers\Asset\AssetRegisterController;
use App\Http\Controllers\Travel\DeliveryController;
use App\Http\Controllers\Travel\TravelRequestController;
use App\Http\Controllers\Travel\TravelReconciliationController;
use App\Http\Controllers\Travel\TravelSettingsController;
use App\Http\Controllers\Authorization\RoleController;
use App\Http\Controllers\Goals\GoalController;
use App\Http\Controllers\Roster\HandoverController;
use App\Http\Controllers\Roster\RosterConfigController;
use App\Http\Controllers\Roster\RosterScheduleController;
use App\Http\Controllers\Roster\RosterShiftSwapController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\AzureSsoController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Integration\IntegrationConnectionController;
use App\Http\Controllers\Integration\LicenceSyncController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Public\PublicJobController;
use App\Http\Controllers\Public\PublicPreboardingController;
use App\Http\Controllers\PublicHolidayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth (no CSRF — Bearer token based) ──────────────────────────────────────
Route::post('/login',  [AuthenticatedSessionController::class, 'store'])->middleware('guest');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');

/*
 * Forgotten passwords.
 *
 * These controllers have existed since the project was scaffolded and were
 * never routed — `routes/auth.php` defined them and nothing ever registered
 * that file, so the only way back into a locked-out account was for somebody
 * with database access to rewrite the hash by hand.
 *
 * Throttled hard. The request endpoint sends mail to an address the caller
 * chooses, which is a way to have this server post to somebody's inbox on
 * repeat; the reset endpoint is a token guess. Laravel's own broker throttle
 * (60s, per config/auth.php) stops a person double-clicking, not a script.
 */
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1']);

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware(['guest', 'throttle:6,1']);

// Azure AD SSO
Route::prefix('auth/azure')->middleware('throttle:20,1')->group(function () {
    Route::get('/redirect',  [AzureSsoController::class, 'redirect']);
    Route::get('/callback',  [AzureSsoController::class, 'callback']);
    Route::post('/exchange', [AzureSsoController::class, 'exchange']);
});

/*
 * The customer portal.
 *
 * Unauthenticated by design — access is a signed, revocable link emailed to the
 * person the ticket belongs to. Throttled hard because the URL is public by
 * construction and the token is the only thing in front of somebody's
 * correspondence.
 */
Route::prefix('v1/support/portal')->middleware('throttle:60,1')->group(function () {
    Route::get('/ticket',     [PortalController::class, 'ticket']);
    Route::get('/tickets',    [PortalController::class, 'tickets']);
    Route::post('/reply',     [PortalController::class, 'reply']);
    Route::get('/catalogue',  [PortalController::class, 'catalogue']);
    Route::post('/request',   [PortalController::class, 'submit']);
    Route::get('/help',       [PortalController::class, 'help']);
    Route::get('/article',    [PortalController::class, 'article']);
    Route::post('/article/rate', [PortalController::class, 'rateArticle']);
    Route::get('/survey',     [PortalController::class, 'survey']);
    Route::post('/survey',    [PortalController::class, 'respond']);
});

/*
 * Microsoft Graph change notifications.
 *
 * Necessarily unauthenticated — Graph carries no bearer token — so the
 * controller checks each notification against the mailbox's clientState before
 * queueing anything. Throttled because the URL is public by construction.
 */
Route::prefix('v1')->group(function () {
    Route::post('/support/mail/webhook', MailWebhookController::class)
        ->middleware('throttle:600,1');
});

// Current authenticated user.
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ── Public Job Portal (no auth, rate-limited) ─────────────────────────────────
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/jobs',                      [PublicJobController::class, 'index']);
    Route::get('/jobs/{uuid}',               [PublicJobController::class, 'show']);
    Route::post('/jobs/{uuid}/apply',        [PublicJobController::class, 'apply'])->middleware('throttle:5,1');

    // Pre-hire one-time form access (token-based, no HR auth required)
    Route::prefix('preboarding/form')->middleware('throttle:30,1')->group(function () {
        Route::get('/{token}',                         [PublicPreboardingController::class, 'show']);
        Route::post('/{token}/sections/{section}',     [PublicPreboardingController::class, 'submitSection']);
        Route::post('/{token}/attachments',            [PublicPreboardingController::class, 'uploadAttachment'])->middleware('throttle:10,1');
    });

    /*
     * Candidate CV download.
     *
     * Signed rather than authenticated, for two reasons: the SPA links to CVs
     * with a plain anchor, which carries no bearer token, and external
     * interview panellists need the link from an email without an account.
     *
     * The signature is what grants access, so it is issued only inside a
     * response the caller was already authorised to receive, and it expires.
     * This is the presigned-URL pattern, and it replaces URLs that were
     * unauthenticated, permanent and served straight off the storage symlink.
     */
    Route::get('/candidates/{candidate}/resume', [CandidateController::class, 'downloadSignedResume'])
        ->middleware('signed')
        ->name('candidates.resume.signed');

    // Interview scoring form (public, token-based)
    Route::prefix('scoring')->middleware('throttle:30,1')->group(function () {
        Route::get('/{token}',    [\App\Http\Controllers\Recruitment\PublicScoringController::class, 'show']);
        Route::post('/{token}',   [\App\Http\Controllers\Recruitment\PublicScoringController::class, 'submit'])->middleware('throttle:10,1');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// API v1 — all routes require Sanctum auth + org context resolution
// ─────────────────────────────────────────────────────────────────────────────
Route::prefix('v1')->middleware(['auth:sanctum', 'org.context'])->group(function () {

    // ── Dashboard KPIs ────────────────────────────────────────────────────────
    Route::get('/dashboard', [DashboardController::class, 'summary']);

    // ── Employees ─────────────────────────────────────────────────────────────
    Route::prefix('employees')->group(function () {
        Route::get('/',                  [EmployeeController::class, 'index']);
        Route::post('/',                 [EmployeeController::class, 'store']);

        // Before /{employee}, like the rest — a literal segment declared after
        // a route parameter is never reached.
        Route::get('/summary',           [EmployeeController::class, 'summary']);

        // Self-service "My Info" (must be registered before /{employee} to avoid shadowing)
        Route::get('/me',                [EmployeeController::class, 'me']);
        Route::put('/me/contact',        [EmployeeController::class, 'updateOwnContact']);

        // Typeahead lookup. Also registered before /{employee} — a literal
        // segment declared after a route parameter is never reached.
        Route::get('/search',            [EmployeeController::class, 'search']);

        Route::get('/{employee}',        [EmployeeController::class, 'show']);
        Route::put('/{employee}',        [EmployeeController::class, 'update']);
        Route::post('/{employee}/exit',  [EmployeeController::class, 'exit']);

        // Profile photo
        Route::post('/{employee}/photo', [EmployeeController::class, 'uploadPhoto']);

        // Qualifications & certifications
        Route::post('/{employee}/qualifications',                  [EmployeeController::class, 'storeQualification']);
        Route::delete('/{employee}/qualifications/{qualification}', [EmployeeController::class, 'destroyQualification']);

        // External secondments
        /*
         * Moving somebody between the organisations in the group. The preflight
         * is a separate read because it is asked before anybody commits to
         * anything — usually several times, while the holdings are cleared.
         */
        Route::get('/{employee}/movements',           [EmployeeMovementController::class, 'index']);
        Route::get('/{employee}/movements/preflight', [EmployeeMovementController::class, 'preflight']);
        Route::post('/{employee}/movements',          [EmployeeMovementController::class, 'store']);

        Route::post('/{employee}/secondments',               [EmployeeController::class, 'storeSecondment']);
        Route::delete('/{employee}/secondments/{secondment}', [EmployeeController::class, 'destroySecondment']);

        // Emergency contacts (HR or self)
        Route::get('/{employee}/emergency-contacts',              [EmployeeEmergencyContactController::class, 'index']);
        Route::post('/{employee}/emergency-contacts',             [EmployeeEmergencyContactController::class, 'store']);
        Route::put('/{employee}/emergency-contacts/{contact}',    [EmployeeEmergencyContactController::class, 'update']);
        Route::delete('/{employee}/emergency-contacts/{contact}', [EmployeeEmergencyContactController::class, 'destroy']);

        // Dependents (HR or self)
        Route::get('/{employee}/dependents',              [EmployeeDependentController::class, 'index']);
        Route::post('/{employee}/dependents',             [EmployeeDependentController::class, 'store']);
        Route::put('/{employee}/dependents/{dependent}',  [EmployeeDependentController::class, 'update']);
        Route::delete('/{employee}/dependents/{dependent}', [EmployeeDependentController::class, 'destroy']);

        // Vehicles (HR or self)
        Route::get('/{employee}/vehicles',              [EmployeeVehicleController::class, 'index']);
        Route::post('/{employee}/vehicles',             [EmployeeVehicleController::class, 'store']);
        Route::put('/{employee}/vehicles/{vehicle}',    [EmployeeVehicleController::class, 'update']);
        Route::delete('/{employee}/vehicles/{vehicle}', [EmployeeVehicleController::class, 'destroy']);

        // Onboarding Step 5 — inductions, document checklist, assets (HR only)
        Route::get('/{employee}/onboarding',  [OnboardingController::class, 'show']);
        Route::put('/{employee}/onboarding',  [OnboardingController::class, 'sync']);

        // Private document file storage (HR or self)
        Route::get('/{employee}/documents',                          [DocumentController::class, 'index']);
        Route::post('/{employee}/documents',                         [DocumentController::class, 'store']);
        Route::get('/{employee}/documents/{document}/download',      [DocumentController::class, 'download']);
        Route::delete('/{employee}/documents/{document}',            [DocumentController::class, 'destroy']);
    });

    // ── Work Location Check-In (self-service + team view) ────────────────────
    /*
     * Attendance — where people work, and whether the day happened.
     *
     * The calendar and the list read the same endpoint, because they are two
     * pictures of the same days and two endpoints would eventually disagree
     * about one of them.
     */
    Route::prefix('attendance')->group(function () {
        Route::get('/',        [AttendanceController::class, 'index']);
        // Before /{attendanceDay}, or the router reads these as ids.
        Route::get('/summary', [AttendanceController::class, 'summary']);
        Route::get('/policy',  [AttendanceController::class, 'policy']);
        Route::put('/policy',  [AttendanceController::class, 'updatePolicy']);

        // Saying where you are working, on the day or in advance.
        Route::post('/record',     [AttendanceController::class, 'record']);
        Route::post('/clock-in',   [AttendanceController::class, 'clockIn']);
        Route::post('/clock-out',  [AttendanceController::class, 'clockOut']);
        // Entered after the fact, and marked as such.
        Route::post('/add-hours',  [AttendanceController::class, 'addHours']);

        /*
         * What somebody committed to for a day, and how it went.
         *
         * Not a timesheet. Three questions: what did you say you would finish,
         * did you finish it, and if not, why not — and the third is enforced,
         * because it is the only one people skip and the only one anybody else
         * needs.
         */
        Route::post('/commitments',        [AttendanceCommitmentController::class, 'store']);
        Route::put('/commitments/{commitment}',    [AttendanceCommitmentController::class, 'update']);
        Route::delete('/commitments/{commitment}', [AttendanceCommitmentController::class, 'destroy']);
        Route::post('/commitments/{commitment}/report', [AttendanceCommitmentController::class, 'report']);

        /*
         * What the workforce actually did. Scoped like everything else — a
         * manager's report covers their own reports and nobody else's.
         */
        Route::prefix('analytics')->group(function () {
            Route::get('/people',       [AttendanceAnalyticsController::class, 'people']);
            Route::get('/trends',       [AttendanceAnalyticsController::class, 'trends']);
            Route::get('/distribution', [AttendanceAnalyticsController::class, 'distribution']);
            /*
             * Delivery against the due date — what replaced the timesheet.
             * Reports the day work was promised, the day the person said it was
             * done and the day it was approved, side by side.
             */
            Route::get('/delivery',     [AttendanceAnalyticsController::class, 'delivery']);
        });

        /*
         * The one day a week for training, certification, research and
         * improvement. Asked for, not taken — it displaces work other people
         * are waiting on, so somebody accountable decides.
         */
        Route::prefix('enablement')->group(function () {
            // What is in the way, before committing to ask.
            Route::post('/check',   [EnablementDayController::class, 'check']);
            Route::post('/request', [EnablementDayController::class, 'request']);
            Route::get('/pending',  [EnablementDayController::class, 'pending']);
            Route::post('/{attendanceDay}/decide',   [EnablementDayController::class, 'decide']);
            Route::delete('/{attendanceDay}',        [EnablementDayController::class, 'withdraw']);
        });

        Route::get('/{attendanceDay}', [AttendanceController::class, 'show']);
    });

    // ── Authorization administration ──────────────────────────────────────────
    // Role-centric, with a tenant on every assignment so one identity can hold
    // different rights in different organizations.
    Route::prefix('authorization')->group(function () {
        Route::get('/options',            [RoleController::class, 'options']);
        Route::get('/roles',              [RoleController::class, 'index']);
        Route::get('/roles/{role}',       [RoleController::class, 'show']);
        Route::get('/users/{user}/assignments', [RoleController::class, 'assignmentsFor']);
        Route::post('/assignments',       [RoleController::class, 'grant']);
        Route::delete('/assignments/{assignment}', [RoleController::class, 'revoke']);
    });

    // ── Accounts (customer master) ────────────────────────────────────────────
    //
    // Exists ahead of Travel: a travel request names the customer it is for,
    // and 94 of 102 legacy requests did.
    Route::prefix('accounts')->group(function () {
        Route::get('/',                [AccountController::class, 'index']);
        Route::post('/',               [AccountController::class, 'store']);

        // Before /{account}: a literal segment declared after a route
        // parameter is never reached.
        Route::get('/lookups',         [AccountController::class, 'lookups']);

        Route::get('/{account}',       [AccountController::class, 'show']);
        Route::put('/{account}',       [AccountController::class, 'update']);
        Route::delete('/{account}',    [AccountController::class, 'destroy']);

        // Who works the account, beyond the manager who owns it.
        Route::post('/{account}/team',           [AccountController::class, 'storeTeamMember']);
        Route::delete('/{account}/team/{member}',[AccountController::class, 'destroyTeamMember']);

        // The timeline, and the only thing that moves last_contact_at.
        Route::get('/{account}/activities',      [AccountController::class, 'activities']);
        Route::post('/{account}/activities',     [AccountController::class, 'storeActivity']);

        // Trips taken for this customer — the link existed, nothing read it
        // from this side.
        Route::get('/{account}/travels',         [AccountController::class, 'travels']);

        // Deal counts for the Overview. Counted, never stored.
        Route::get('/{account}/summary',         [AccountController::class, 'summary']);

        // Paperwork. Never publicly addressable; served through download.
        Route::post('/{account}/attachments',    [AccountController::class, 'storeAttachment']);
        Route::get('/{account}/attachments/{attachment}/download', [AccountController::class, 'downloadAttachment']);
        Route::delete('/{account}/attachments/{attachment}',       [AccountController::class, 'destroyAttachment']);

        // What we have agreed to do for them, and until when.
        Route::post('/{account}/contracts',             [AccountController::class, 'storeContract']);
        Route::put('/{account}/contracts/{contract}',   [AccountController::class, 'updateContract']);
        Route::delete('/{account}/contracts/{contract}',[AccountController::class, 'destroyContract']);
        Route::post('/{account}/contracts/{contract}/complete', [AccountController::class, 'completeContract']);

        Route::post('/{account}/contacts',              [AccountController::class, 'storeContact']);
        Route::put('/{account}/contacts/{contact}',     [AccountController::class, 'updateContact']);
        Route::delete('/{account}/contacts/{contact}',  [AccountController::class, 'destroyContact']);
    });

    // ── Pipeline (leads and opportunities) ────────────────────────────────────
    //
    // Reachable both on their own and filtered by ?account_id=, which is how
    // the customer record's Leads and Opportunities tabs read them.
    Route::prefix('leads')->group(function () {
        Route::get('/',            [LeadController::class, 'index']);
        Route::post('/',           [LeadController::class, 'store']);

        // Before /{lead}: a literal segment declared after a route parameter
        // is never reached.
        Route::get('/board',       [LeadController::class, 'board']);

        Route::get('/{lead}',      [LeadController::class, 'show']);
        Route::put('/{lead}',      [LeadController::class, 'update']);
        Route::delete('/{lead}',   [LeadController::class, 'destroy']);

        // Becomes a deal. The link lives on the opportunity, not in the
        // lead's status.
        Route::post('/{lead}/convert', [LeadController::class, 'convert']);

        // Dragging a card. Its own action so a move never has to send back
        // every field on the lead.
        Route::post('/{lead}/status',   [LeadController::class, 'moveToStatus']);

        // Grading the five dimensions, which is a different act from editing.
        Route::post('/{lead}/qualify',  [LeadController::class, 'qualify']);

        Route::post('/{lead}/stakeholders',               [LeadController::class, 'storeStakeholder']);
        Route::delete('/{lead}/stakeholders/{stakeholder}',[LeadController::class, 'destroyStakeholder']);

        Route::get('/{lead}/activities',  [LeadController::class, 'activities']);
        Route::post('/{lead}/activities', [LeadController::class, 'storeActivity']);

        Route::post('/{lead}/attachments', [LeadController::class, 'storeAttachment']);
        Route::get('/{lead}/attachments/{attachment}/download', [LeadController::class, 'downloadAttachment']);
        Route::delete('/{lead}/attachments/{attachment}',       [LeadController::class, 'destroyAttachment']);

        Route::post('/{lead}/team',           [LeadController::class, 'storeTeamMember']);
        Route::delete('/{lead}/team/{member}',[LeadController::class, 'destroyTeamMember']);
    });

    Route::prefix('opportunities')->group(function () {
        Route::get('/',       [OpportunityController::class, 'index']);
        Route::post('/',      [OpportunityController::class, 'store']);

        // Before /{opportunity}: a literal segment declared after a route
        // parameter is never reached.
        Route::get('/phases', [OpportunityController::class, 'phases']);

        Route::get('/{opportunity}',    [OpportunityController::class, 'show']);
        Route::put('/{opportunity}',    [OpportunityController::class, 'update']);
        Route::delete('/{opportunity}', [OpportunityController::class, 'destroy']);

        Route::post('/{opportunity}/phase', [OpportunityController::class, 'moveToPhase']);

        Route::post('/{opportunity}/assignees',            [OpportunityController::class, 'storeAssignee']);
        Route::delete('/{opportunity}/assignees/{assignee}',[OpportunityController::class, 'destroyAssignee']);

        /*
         * The pipeline. Every stage of a deal in one place — the screen shows
         * one rail, and fetching eleven endpoints as somebody clicks down it
         * would make a slow connection look broken.
         */
        Route::get('/{opportunity}/pipeline', [OpportunityPipelineController::class, 'show']);

        Route::post('/{opportunity}/stages/{phase}/complete', [OpportunityPipelineController::class, 'completeStage']);
        Route::post('/{opportunity}/stages/{phase}/reopen',   [OpportunityPipelineController::class, 'reopenStage']);

        Route::post('/{opportunity}/indicators',   [OpportunityPipelineController::class, 'updateIndicators']);
        Route::post('/{opportunity}/qualify',      [OpportunityPipelineController::class, 'qualify']);
        Route::post('/{opportunity}/mark-qualified', [OpportunityPipelineController::class, 'markQualified']);

        Route::post('/{opportunity}/estimates',              [OpportunityPipelineController::class, 'storeEstimate']);
        Route::delete('/{opportunity}/estimates/{estimate}', [OpportunityPipelineController::class, 'destroyEstimate']);

        Route::post('/{opportunity}/budget/confirm', [OpportunityPipelineController::class, 'confirmBudget']);
        Route::post('/{opportunity}/budget/revoke',  [OpportunityPipelineController::class, 'revokeBudget']);

        Route::post('/{opportunity}/objectives',                [OpportunityPipelineController::class, 'storeObjective']);
        Route::put('/{opportunity}/objectives/{objective}',     [OpportunityPipelineController::class, 'updateObjective']);
        Route::delete('/{opportunity}/objectives/{objective}',  [OpportunityPipelineController::class, 'destroyObjective']);

        Route::post('/{opportunity}/bids',        [OpportunityPipelineController::class, 'storeBid']);
        Route::put('/{opportunity}/bids/{bid}',   [OpportunityPipelineController::class, 'updateBid']);
        Route::delete('/{opportunity}/bids/{bid}',[OpportunityPipelineController::class, 'destroyBid']);

        Route::post('/{opportunity}/contracts', [OpportunityPipelineController::class, 'storeContract']);
        Route::post('/{opportunity}/projects',  [OpportunityPipelineController::class, 'storeProject']);

        // One upload path for every stage; see the controller.
        Route::post('/{opportunity}/documents',              [OpportunityPipelineController::class, 'storeDocument']);
        Route::get('/{opportunity}/documents/{document}/download', [OpportunityPipelineController::class, 'downloadDocument']);
        Route::delete('/{opportunity}/documents/{document}', [OpportunityPipelineController::class, 'destroyDocument']);
    });

    /*
     * Research — work done to find out whether the thing can be built.
     *
     * Filtered by ?opportunity_id= for the deal's Research tab, and by
     * ?account_id= for a customer's.
     */
    Route::prefix('research')->group(function () {
        Route::get('/',              [ResearchController::class, 'index']);
        Route::post('/',             [ResearchController::class, 'store']);
        Route::get('/{research}',    [ResearchController::class, 'show']);
        Route::put('/{research}',    [ResearchController::class, 'update']);
        Route::delete('/{research}', [ResearchController::class, 'destroy']);

        Route::post('/{research}/team',           [ResearchController::class, 'storeTeamMember']);
        Route::delete('/{research}/team/{member}',[ResearchController::class, 'destroyTeamMember']);
    });

    /*
     * Tasks — across the whole system, not one project's.
     *
     * A quarter of the legacy's 9,055 tasks carried a project; the rest were
     * support, training, meetings and internal work. The project-nested
     * endpoints below remain as a filtered view of the same table.
     */
    Route::prefix('tasks')->group(function () {
        Route::get('/',       [TaskBoardController::class, 'index']);
        Route::post('/',      [TaskBoardController::class, 'store']);

        // Before /{task}: what has been asked of me, and what I have asked of
        // others. The screen the legacy never had — 261 of its 354 delegations
        // were never answered because nothing showed them.
        Route::get('/inbox',  [TaskBoardController::class, 'inbox']);

        // The counts above the board. Derived, never stored.
        Route::get('/summary', [TaskBoardController::class, 'summary']);

        /*
         * Schedules for work that comes back round. Before /{task}, or the
         * router reads "recurrences" as a task id.
         */
        Route::get('/recurrences',     [TaskRecurrenceController::class, 'index']);
        Route::post('/recurrences',    [TaskRecurrenceController::class, 'store']);
        Route::put('/recurrences/{recurrence}',    [TaskRecurrenceController::class, 'update']);
        Route::delete('/recurrences/{recurrence}', [TaskRecurrenceController::class, 'destroy']);
        // Raise what it owes now rather than waiting for the nightly run.
        Route::post('/recurrences/{recurrence}/run', [TaskRecurrenceController::class, 'run']);

        Route::get('/{task}', [TaskBoardController::class, 'show']);
        Route::put('/{task}',    [TaskBoardController::class, 'update']);
        Route::delete('/{task}', [TaskBoardController::class, 'destroy']);

        // Signing a task off, or sending it back. Only the named approver.
        Route::post('/{task}/approval', [TaskBoardController::class, 'decideApproval']);

        // Asking somebody, rather than assigning them.
        Route::post('/{task}/requests',                    [TaskBoardController::class, 'storeRequest']);
        Route::post('/{task}/requests/{taskRequest}/respond', [TaskBoardController::class, 'respondToRequest']);

        // Documents the task cannot close without.
        Route::post('/{task}/required-documents',             [TaskBoardController::class, 'storeRequiredDocument']);
        Route::post('/{task}/required-documents/{document}/fulfil', [TaskBoardController::class, 'fulfilRequiredDocument']);
        Route::get('/{task}/required-documents/{document}/download', [TaskBoardController::class, 'downloadRequiredDocument']);

        // Sub-tasks and what the task is waiting on.
        Route::post('/{task}/subtasks',     [TaskBoardController::class, 'storeSubtask']);
        Route::post('/{task}/dependencies', [TaskBoardController::class, 'storeDependencyLink']);

        // Where people talk. The legacy took 64,578 of these.
        Route::post('/{task}/comments',                [TaskBoardController::class, 'storeComment']);
        Route::post('/{task}/comments/{comment}/pin',  [TaskBoardController::class, 'pinComment']);
        Route::post('/{task}/comments/{comment}/react',[TaskBoardController::class, 'reactToComment']);

        Route::post('/{task}/attachments',                       [TaskBoardController::class, 'storeAttachment']);
        Route::get('/{task}/attachments/{attachment}/download',  [TaskBoardController::class, 'downloadAttachment']);

        // What is in the way, and who it has been put in front of.
        Route::post('/{task}/issues',                   [TaskBoardController::class, 'storeIssue']);
        Route::put('/{task}/issues/{issue}',            [TaskBoardController::class, 'updateIssue']);
        Route::post('/{task}/issues/{issue}/escalate',  [TaskBoardController::class, 'escalateIssue']);
    });

    /*
     * Projects — the work a won deal starts.
     *
     * Filtered by ?opportunity_id= for the deal that produced it, and
     * ?state=overdue for the question a status filter cannot answer.
     */
    Route::prefix('projects')->group(function () {
        Route::get('/',             [ProjectController::class, 'index']);
        Route::post('/',            [ProjectController::class, 'store']);
        Route::get('/{project}',    [ProjectController::class, 'show']);
        Route::put('/{project}',    [ProjectController::class, 'update']);
        Route::delete('/{project}', [ProjectController::class, 'destroy']);

        Route::post('/{project}/team',           [ProjectController::class, 'storeTeamMember']);
        Route::delete('/{project}/team/{member}',[ProjectController::class, 'destroyTeamMember']);

        // ── Tasks ─────────────────────────────────────────────────────────
        Route::get('/{project}/tasks',           [TaskController::class, 'index']);
        Route::post('/{project}/tasks',          [TaskController::class, 'store']);
        // Before /{task}: a literal segment after a route parameter is never
        // reached.
        Route::post('/{project}/tasks/reorder',  [TaskController::class, 'reorder']);
        Route::get('/{project}/tasks/{task}',    [TaskController::class, 'show']);
        Route::put('/{project}/tasks/{task}',    [TaskController::class, 'update']);
        Route::delete('/{project}/tasks/{task}', [TaskController::class, 'destroy']);

        Route::post('/{project}/tasks/{task}/members',           [TaskController::class, 'storeMember']);
        Route::delete('/{project}/tasks/{task}/members/{member}',[TaskController::class, 'destroyMember']);

        Route::post('/{project}/tasks/{task}/dependencies',              [TaskController::class, 'storeDependency']);
        Route::delete('/{project}/tasks/{task}/dependencies/{dependency}',[TaskController::class, 'destroyDependency']);

        Route::get('/{project}/activities', [TaskController::class, 'activities']);
        Route::get('/{project}/comments',  [TaskController::class, 'comments']);
        Route::post('/{project}/comments', [TaskController::class, 'storeComment']);

        // ── Contract milestones ───────────────────────────────────────────
        // What the customer agreed to pay, and against which task.
        Route::get('/{project}/milestones',              [ProjectMilestoneController::class, 'index']);
        Route::post('/{project}/milestones',             [ProjectMilestoneController::class, 'store']);
        Route::put('/{project}/milestones/{milestone}',  [ProjectMilestoneController::class, 'update']);
        Route::delete('/{project}/milestones/{milestone}',[ProjectMilestoneController::class, 'destroy']);
        Route::post('/{project}/milestones/{milestone}/transition', [ProjectMilestoneController::class, 'transition']);

        Route::post('/{project}/attachments', [TaskController::class, 'storeAttachment']);
        Route::get('/{project}/attachments/{attachment}/download', [TaskController::class, 'downloadAttachment']);
    });

    // ── Travel ────────────────────────────────────────────────────────────────
    //
    // One tenant owns a request end to end: whose budget pays, whose rate card
    // costs it, whose approvers act on it.
    Route::prefix('travel-requests')->group(function () {
        Route::get('/',                     [TravelRequestController::class, 'index']);
        Route::post('/',                    [TravelRequestController::class, 'store']);
        Route::get('/{travelRequest}',      [TravelRequestController::class, 'show']);
        Route::put('/{travelRequest}',      [TravelRequestController::class, 'update']);
        Route::delete('/{travelRequest}',   [TravelRequestController::class, 'destroy']);

        // Submission fixes the approval chain that will apply, so it is an
        // action rather than a status field on update.
        // Estimated expenses. The traveller states the amount; the rate card
        // decides whether it is within policy, and going over is flagged
        // rather than blocked.
        Route::post('/{travelRequest}/items',         [TravelRequestController::class, 'storeItem']);
        Route::put('/{travelRequest}/items/{item}',   [TravelRequestController::class, 'updateItem']);
        Route::delete('/{travelRequest}/items/{item}',[TravelRequestController::class, 'destroyItem']);

        Route::post('/{travelRequest}/itinerary',              [TravelRequestController::class, 'storeItinerary']);
        Route::delete('/{travelRequest}/itinerary/{itinerary}', [TravelRequestController::class, 'destroyItinerary']);

        Route::post('/{travelRequest}/bookings',           [TravelRequestController::class, 'storeBooking']);
        Route::delete('/{travelRequest}/bookings/{booking}',[TravelRequestController::class, 'destroyBooking']);

        // ── Reconciliation ────────────────────────────────────────────────
        //
        // Who may supply evidence is decided by the expense category, not by a
        // blanket permission: the traveller has the Uber receipt, finance has
        // the airline invoice, and a per diem needs neither.
        Route::get('/{travelRequest}/outstanding', [TravelReconciliationController::class, 'outstanding']);
        Route::post('/{travelRequest}/items/{item}/evidence', [TravelReconciliationController::class, 'storeEvidence']);
        Route::get('/{travelRequest}/evidence/{evidence}/download', [TravelReconciliationController::class, 'downloadEvidence']);
        Route::delete('/{travelRequest}/evidence/{evidence}', [TravelReconciliationController::class, 'destroyEvidence']);
        Route::post('/{travelRequest}/close', [TravelReconciliationController::class, 'close']);

        // Transport legs. One per journey and mode, costed from distance and
        // fuel rather than from a grade allowance.
        Route::post('/{travelRequest}/legs',       [TravelRequestController::class, 'storeLeg']);
        Route::put('/{travelRequest}/legs/{leg}',  [TravelRequestController::class, 'updateLeg']);
        Route::delete('/{travelRequest}/legs/{leg}',[TravelRequestController::class, 'destroyLeg']);

        // ── Delivery ──────────────────────────────────────────────────────
        //
        // What is in the vehicle, who is carrying it, and the note it closes
        // against. Receiving and the note arrive after approval, so those are
        // gated on seeing the delivery rather than editing it.
        Route::post('/{travelRequest}/manifest',        [DeliveryController::class, 'storeItem']);
        Route::put('/{travelRequest}/manifest/{item}',  [DeliveryController::class, 'updateItem']);
        Route::delete('/{travelRequest}/manifest/{item}',[DeliveryController::class, 'destroyItem']);

        Route::post('/{travelRequest}/couriers',            [DeliveryController::class, 'storeCourier']);
        Route::delete('/{travelRequest}/couriers/{courier}',[DeliveryController::class, 'destroyCourier']);

        Route::post('/{travelRequest}/attachments', [DeliveryController::class, 'storeAttachment']);
        Route::get('/{travelRequest}/attachments/{attachment}/download', [DeliveryController::class, 'downloadAttachment']);
        Route::get('/{travelRequest}/delivery-gaps', [DeliveryController::class, 'outstanding']);

        Route::post('/{travelRequest}/submit', [TravelRequestController::class, 'submit']);

        // One endpoint for all four stages. Which stage is being decided is a
        // property of the request, not something the caller states — naming it
        // would let a later approver decide an earlier stage.
        Route::post('/{travelRequest}/decision', [TravelRequestController::class, 'decide']);
    });

    // ── Travel configuration ──────────────────────────────────────────────────
    //
    // Destinations, expense categories, the allowance rate card and the
    // approval chain. Gated on travel.manage_rates, held by Finance.
    // What a requester needs to fill the form in. Reading what is on offer
    // is not the same authority as setting it.
    Route::get('/travel/lookups', [TravelSettingsController::class, 'lookups']);

    Route::prefix('travel/settings')->group(function () {
        Route::get('/destinations',                 [TravelSettingsController::class, 'destinations']);
        Route::post('/destinations',                [TravelSettingsController::class, 'storeDestination']);
        Route::put('/destinations/{destination}',   [TravelSettingsController::class, 'updateDestination']);
        Route::delete('/destinations/{destination}',[TravelSettingsController::class, 'destroyDestination']);

        Route::get('/categories',              [TravelSettingsController::class, 'categories']);
        Route::post('/categories',             [TravelSettingsController::class, 'storeCategory']);
        Route::put('/categories/{category}',   [TravelSettingsController::class, 'updateCategory']);
        Route::delete('/categories/{category}',[TravelSettingsController::class, 'destroyCategory']);

        // Rates are superseded rather than edited: a request approved in March
        // must still show March's rates in November.
        // Grades, so the rate card can be priced by seniority without giving
        // finance the org-chart permission.
        Route::get('/job-grades',      [TravelSettingsController::class, 'jobGrades']);

        Route::get('/rates',           [TravelSettingsController::class, 'rates']);
        Route::post('/rates',          [TravelSettingsController::class, 'storeRate']);
        Route::put('/rates/{rate}',    [TravelSettingsController::class, 'updateRate']);
        Route::delete('/rates/{rate}', [TravelSettingsController::class, 'destroyRate']);

        // Saved as a set: the chain only makes sense whole, and saving stage
        // by stage can leave it briefly empty.
        // Transport: the arithmetic, not the journey. Fuel price is entered
        // per request because a pump price held centrally is stale in a week.
        Route::get('/mileage-rates',   [TravelSettingsController::class, 'mileageRates']);
        Route::post('/mileage-rates',  [TravelSettingsController::class, 'storeMileageRate']);

        Route::get('/vehicle-types',                 [TravelSettingsController::class, 'vehicleTypes']);
        Route::post('/vehicle-types',                [TravelSettingsController::class, 'storeVehicleType']);
        Route::put('/vehicle-types/{vehicleType}',   [TravelSettingsController::class, 'updateVehicleType']);
        Route::delete('/vehicle-types/{vehicleType}',[TravelSettingsController::class, 'destroyVehicleType']);

        Route::get('/bus-fares',            [TravelSettingsController::class, 'busFares']);
        Route::post('/bus-fares',           [TravelSettingsController::class, 'saveBusFare']);
        Route::delete('/bus-fares/{busFare}',[TravelSettingsController::class, 'destroyBusFare']);

        Route::get('/approval-stages',  [TravelSettingsController::class, 'stages']);
        Route::put('/approval-stages',  [TravelSettingsController::class, 'saveStages']);
    });

    // ── Assets ────────────────────────────────────────────────────────────────
    /*
     * Where the register is told to read somebody else's estate.
     *
     * Under the same org-context group as everything else, so a connection
     * belongs to the organisation in context — a tenant describes one company's
     * estate and importing it into another mixes two registers.
     */
    Route::prefix('integrations/connections')->group(function () {
        Route::get('/',                       [IntegrationConnectionController::class, 'index']);
        Route::post('/',                      [IntegrationConnectionController::class, 'store']);
        Route::put('/{connection}',           [IntegrationConnectionController::class, 'update']);
        Route::delete('/{connection}',        [IntegrationConnectionController::class, 'destroy']);

        // Credential checks and subscription discovery, kept apart from the
        // sync itself — finding out what a principal may do should never
        // write anything to the register.
        Route::post('/{connection}/test',          [IntegrationConnectionController::class, 'test']);
        Route::post('/{connection}/discover',      [IntegrationConnectionController::class, 'discover']);
        Route::put('/{connection}/subscriptions',  [IntegrationConnectionController::class, 'setSubscriptions']);

        // Pulling the picture in. Separate permission from configuring where
        // it comes from — see IntegrationConnectionPolicy.
        Route::post('/{connection}/sync', [LicenceSyncController::class, 'sync']);

        // Azure writes into the asset register itself, so the preview is a
        // separate call and always available before the commit.
        Route::post('/{connection}/licences/preview', [LicenceSyncController::class, 'previewLicenceAssets']);
        Route::post('/{connection}/licences/import',  [LicenceSyncController::class, 'importLicenceAssets']);

        Route::post('/{connection}/azure/preview', [LicenceSyncController::class, 'previewAzure']);
        Route::post('/{connection}/azure/import',  [LicenceSyncController::class, 'importAzure']);
    });

    // Read from the last snapshot, not from Microsoft: opening the page should
    // not depend on the tenant being reachable.
    Route::get('/integrations/licence-statistics', [LicenceSyncController::class, 'statistics']);

    Route::prefix('assets')->group(function () {
        /*
         * Before /{asset}, or the router reads "summary" as an id — the same
         * trap the preboarding routes carry a note about.
         */
        Route::get('/summary',   [AssetController::class, 'summary']);

        /*
         * The tab lists. `asset_requests` and `asset_issues` have had models,
         * messages and escalation for weeks with no route to reach them — a
         * request could be written and never seen by anybody.
         */
        Route::get('/requests',  [AssetRegisterController::class, 'requests']);
        // The way in. Every stage after this one existed before this did.
        Route::post('/requests', [AssetRegisterController::class, 'storeRequest']);
        Route::get('/issues',    [AssetRegisterController::class, 'issues']);
        Route::get('/disposals', [AssetRegisterController::class, 'disposals']);
        Route::get('/maintenance', [AssetRegisterController::class, 'maintenance']);
        Route::get('/renewals',    [AssetRegisterController::class, 'renewals']);
        Route::get('/software',    [AssetRegisterController::class, 'softwareDashboard']);

        /*
         * The chain. Each stage is a different person answering a different
         * question, so each is its own act rather than a status field somebody
         * sets.
         */
        Route::post('/requests/{assetRequest}/advance', [AssetRegisterController::class, 'advanceRequest']);
        Route::post('/requests/{assetRequest}/decline', [AssetRegisterController::class, 'declineRequest']);
        Route::post('/requests/{assetRequest}/forward', [AssetRegisterController::class, 'forwardRequest']);
        Route::post('/requests/{assetRequest}/fulfil',  [AssetRegisterController::class, 'fulfilRequest']);

        Route::get('/',            [AssetController::class, 'index']);
        Route::post('/',           [AssetController::class, 'store']);
        Route::get('/{asset}',     [AssetController::class, 'show']);
        Route::put('/{asset}',     [AssetController::class, 'update']);
        Route::delete('/{asset}',  [AssetController::class, 'destroy']);

        // Custody is its own permission, so it gets its own endpoints rather
        // than being a field on update.
        Route::post('/{asset}/assign',  [AssetController::class, 'assign']);
        Route::post('/{asset}/release', [AssetController::class, 'release']);

        /*
         * Custodianship, not assignment. Racked equipment and cloud resources
         * have somebody answerable for them and nobody carrying them — see the
         * migration for why the two are kept apart.
         */
        Route::post('/{asset}/custodian',   [AssetController::class, 'setCustodian']);
        Route::delete('/{asset}/custodian', [AssetController::class, 'clearCustodian']);

        // Seats on a licence batch. Consuming one is a different act from
        // correcting a renewal date, so it is a different endpoint.
        // Only the recipient can do this, which is why it is not an update.
        Route::post('/{asset}/accept',             [AssetController::class, 'accept']);

        /*
         * Photographs. The condition something was in at handover is what gets
         * argued about when it comes back.
         */
        Route::post('/{asset}/documents',             [AssetController::class, 'storeDocument']);
        Route::get('/{asset}/documents/{document}',   [AssetController::class, 'downloadDocument']);
        Route::delete('/{asset}/documents/{document}',[AssetController::class, 'destroyDocument']);

        // What runs on what. Directional, because that is the whole point.
        Route::post('/{asset}/relationships',                  [AssetController::class, 'storeRelationship']);
        Route::delete('/{asset}/relationships/{relationship}', [AssetController::class, 'destroyRelationship']);

        Route::post('/{asset}/photos',                [AssetController::class, 'storePhoto']);
        Route::get('/{asset}/photos/{photo}',         [AssetController::class, 'showPhoto']);
        Route::delete('/{asset}/photos/{photo}',      [AssetController::class, 'destroyPhoto']);

        Route::post('/{asset}/seats',              [AssetController::class, 'allocateSeat']);
        Route::delete('/{asset}/seats/{allocation}', [AssetController::class, 'releaseSeat']);

        // Reminders and who receives them — one decision, one call.
        Route::put('/{asset}/renewal-watchers', [AssetController::class, 'renewalWatchers']);
    });

    // ── Pre-Onboarding (Preboarding) ──────────────────────────────────────────
    Route::prefix('preboarding')->group(function () {
        Route::get('/',                                          [PreboardingController::class, 'index']);
        Route::post('/',                                         [PreboardingController::class, 'store']);
        // Before /{preboarding}, or the router reads it as an id.
        Route::get('/summary',                                   [PreboardingController::class, 'summary']);
        Route::get('/{preboarding}',                             [PreboardingController::class, 'show']);
        Route::put('/{preboarding}',                             [PreboardingController::class, 'update']);
        Route::delete('/{preboarding}',                          [PreboardingController::class, 'destroy']);

        // Welcome & announcement
        Route::patch('/{preboarding}/welcome',                   [PreboardingController::class, 'sendWelcome']);
        Route::patch('/{preboarding}/announcement',              [PreboardingController::class, 'sendAnnouncement']);

        // Sections (HR submits / approves data)
        Route::put('/{preboarding}/sections/{section}',          [PreboardingController::class, 'updateSection']);
        Route::patch('/{preboarding}/sections/{section}/approve',      [PreboardingController::class, 'approveSection']);
        Route::patch('/{preboarding}/sections/{section}/not-applicable',[PreboardingController::class, 'sectionNotApplicable']);

        // References
        Route::post('/{preboarding}/references',                         [PreboardingController::class, 'storeReference']);
        Route::patch('/{preboarding}/references/{reference}/approve',    [PreboardingController::class, 'approveReference']);
        Route::patch('/{preboarding}/references/{reference}/invite',     [PreboardingController::class, 'inviteReference']);
        Route::delete('/{preboarding}/references/{reference}',           [PreboardingController::class, 'destroyReference']);

        // Attachments
        Route::post('/{preboarding}/attachments',                               [PreboardingController::class, 'storeAttachment']);
        Route::get('/{preboarding}/attachments/{attachment}/download',          [PreboardingController::class, 'downloadAttachment']);
        Route::delete('/{preboarding}/attachments/{attachment}',                [PreboardingController::class, 'destroyAttachment']);

        // Convert preboarding record to employee
        Route::post('/{preboarding}/convert',                    [PreboardingController::class, 'convert']);

        // Link management
        Route::post('/{preboarding}/resend-link',                [PreboardingController::class, 'resendLink']);
        Route::post('/{preboarding}/send-link-email',            [PreboardingController::class, 'sendLinkEmail']);
        Route::put('/{preboarding}/form-config',                 [PreboardingController::class, 'updateFormConfig']);
    });

    // ── Leave ─────────────────────────────────────────────────────────────────
    Route::prefix('leave')->group(function () {
        Route::get('/balance',             [LeaveController::class, 'balance']);   // before /{id} to prevent shadowing
        Route::get('/summary',             [LeaveController::class, 'summary']);   // likewise — /{id} would swallow it
        Route::get('/availability',        [LeaveController::class, 'availability']);
        Route::post('/calculate',          [LeaveController::class, 'calculate']); // preview day count
        Route::get('/',                    [LeaveController::class, 'index']);
        Route::post('/',                   [LeaveController::class, 'store']);
        Route::get('/{leaveRequest}',      [LeaveController::class, 'show']);
        Route::post('/{leaveRequest}/approve', [LeaveController::class, 'approve']);

        /*
         * Who covers what while they are away.
         *
         * Under leave rather than under tasks, because it is a question about
         * one absence rather than about one task — and because the person going
         * away answers it, not whoever administers tasks.
         */
        Route::get('/{leaveRequest}/handover',    [LeaveHandoverController::class, 'show']);
        Route::post('/{leaveRequest}/handover',   [LeaveHandoverController::class, 'store']);
        Route::delete('/{leaveRequest}/handover/{handover}', [LeaveHandoverController::class, 'destroy']);
        Route::post('/{leaveRequest}/reject',  [LeaveController::class, 'reject']);

        // Supporting paperwork. Downloads stream through DocumentVault after
        // the policy has run; no storage path ever leaves the server.
        Route::get('/{leaveRequest}/attachments',  [\App\Http\Controllers\Leave\LeaveAttachmentController::class, 'index']);
        Route::post('/{leaveRequest}/attachments', [\App\Http\Controllers\Leave\LeaveAttachmentController::class, 'store']);
        Route::get('/{leaveRequest}/attachments/{attachment}/download', [\App\Http\Controllers\Leave\LeaveAttachmentController::class, 'download']);
        Route::delete('/{leaveRequest}/attachments/{attachment}',       [\App\Http\Controllers\Leave\LeaveAttachmentController::class, 'destroy']);

        // The conversation, which is not the rejection reason.
        Route::get('/{leaveRequest}/messages',  [\App\Http\Controllers\Leave\LeaveMessageController::class, 'index']);
        Route::post('/{leaveRequest}/messages', [\App\Http\Controllers\Leave\LeaveMessageController::class, 'store']);
        Route::delete('/{leaveRequest}',   [LeaveController::class, 'destroy']);
    });

    // ── Leave Admin (types & balances) ───────────────────────────────────────
    Route::prefix('leave-types')->group(function () {
        Route::get('/',                [LeaveAdminController::class, 'indexTypes']);
        Route::post('/',               [LeaveAdminController::class, 'storeType']);
        Route::put('/{leaveType}',     [LeaveAdminController::class, 'updateType']);
        Route::delete('/{leaveType}',  [LeaveAdminController::class, 'destroyType']);
    });
    Route::prefix('leave-balances')->group(function () {
        Route::get('/',                  [LeaveAdminController::class, 'balances']);
        Route::post('/init',             [LeaveAdminController::class, 'initBalances']);
        Route::put('/{leaveBalance}',    [LeaveAdminController::class, 'updateBalance']);
    });

    // ── Payroll ───────────────────────────────────────────────────────────────
    /*
     * Service desk. Replacing the old PLM tickets module — see
     * App\Services\Support\TicketService for why every write goes through one
     * door rather than three.
     */
    Route::prefix('support')->group(function () {
        Route::get('/taxonomy',                 [TicketController::class, 'taxonomy']);
        Route::get('/tickets',                  [TicketController::class, 'index']);
        Route::post('/tickets',                 [TicketController::class, 'store']);
        Route::get('/tickets/{ticket}',         [TicketController::class, 'show']);
        Route::post('/tickets/{ticket}/reply',  [TicketController::class, 'reply']);
        Route::post('/tickets/{ticket}/status', [TicketController::class, 'changeStatus']);
        Route::post('/tickets/{ticket}/classify', [TicketController::class, 'reclassify']);
        Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign']);
        Route::post('/tickets/{ticket}/time',   [TicketController::class, 'logTime']);
        Route::post('/tickets/{ticket}/merge',  [TicketController::class, 'merge']);
        Route::post('/tickets/{ticket}/detach', [TicketController::class, 'detach']);
        Route::post('/tickets/{ticket}/split',  [TicketController::class, 'split']);

        // The catalogue, portal links and the satisfaction survey.
        // Rules that do the obvious thing so nobody has to.
        Route::get('/automations',               [AutomationController::class, 'index']);
        Route::post('/automations',              [AutomationController::class, 'save']);
        Route::get('/automations/{automation}/rehearse', [AutomationController::class, 'rehearse']);
        Route::get('/tickets/{ticket}/automation-runs',  [AutomationController::class, 'forTicket']);

        /*
         * The service review. Its own permission: writing the monthly report
         * should not require the one that edits the priority matrix.
         */
        Route::get('/reports/service-review', [ReportController::class, 'pack']);
        Route::get('/reports/export',         [ReportController::class, 'export']);

        // Problems, changes and sign-off.
        Route::get('/changes',                   [ChangeController::class, 'index']);
        Route::post('/problems',                 [ChangeController::class, 'saveProblem']);
        Route::post('/problems/{problem}/tickets', [ChangeController::class, 'linkTicket']);
        Route::post('/changes',                  [ChangeController::class, 'saveChange']);
        Route::post('/changes/{change}/submit',  [ChangeController::class, 'submit']);
        Route::post('/changes/{change}/outcome', [ChangeController::class, 'recordOutcome']);
        Route::post('/approvals/{approval}',     [ChangeController::class, 'decide']);
        Route::post('/approval-stages',          [ChangeController::class, 'saveStage']);

        // The knowledge base, and the kit a ticket was about.
        Route::get('/articles',                  [KnowledgeController::class, 'index']);
        Route::get('/articles/{article}',        [KnowledgeController::class, 'show']);
        Route::post('/articles',                 [KnowledgeController::class, 'save']);
        Route::post('/articles/{article}/status', [KnowledgeController::class, 'transition']);
        Route::get('/tickets/{ticket}/suggested-articles', [KnowledgeController::class, 'suggest']);
        Route::post('/tickets/{ticket}/attach-article',    [KnowledgeController::class, 'attach']);
        Route::post('/tickets/{ticket}/draft-article',     [KnowledgeController::class, 'draftFromTicket']);
        Route::post('/tickets/{ticket}/assets',            [KnowledgeController::class, 'linkAsset']);
        Route::delete('/tickets/{ticket}/assets/{asset}',  [KnowledgeController::class, 'unlinkAsset']);
        Route::get('/assets/{asset}/tickets',    [KnowledgeController::class, 'assetHistory']);

        Route::get('/catalogue',                 [CatalogueController::class, 'index']);
        Route::post('/catalogue/services',       [CatalogueController::class, 'saveService']);
        Route::post('/tickets/{ticket}/portal-link', [CatalogueController::class, 'portalLink']);
        Route::post('/portal-links/account',     [CatalogueController::class, 'accountLink']);
        Route::post('/tickets/{ticket}/survey',  [CatalogueController::class, 'sendSurvey']);
        Route::post('/tickets/{ticket}/broadcast', [TicketController::class, 'broadcast']);
        Route::post('/tickets/bulk',            [TicketController::class, 'bulk']);

        // The service desk analyst's work: placing, moving and escalating.
        Route::get('/desk',                          [RoutingController::class, 'deskBoard']);
        Route::get('/tickets/{ticket}/suggest',      [RoutingController::class, 'suggest']);
        Route::post('/tickets/{ticket}/reroute',     [RoutingController::class, 'reroute']);
        Route::post('/tickets/{ticket}/reassign',    [RoutingController::class, 'reassign']);
        Route::post('/tickets/{ticket}/escalate',    [RoutingController::class, 'escalate']);
        Route::post('/tickets/{ticket}/escalations/{escalation}/resolve',
            [RoutingController::class, 'resolveEscalation']);

        // The structure that decides where a ticket goes.
        Route::get('/contracts',                     [RoutingController::class, 'contracts']);
        Route::post('/contracts/{contract}/support', [RoutingController::class, 'saveContractSupport']);
        Route::post('/contracts/{contract}/engineers', [RoutingController::class, 'saveContractEngineer']);
        Route::delete('/contracts/{contract}/engineers/{engineer}', [RoutingController::class, 'removeContractEngineer']);
        Route::post('/engineers',                    [RoutingController::class, 'saveEngineer']);
        Route::post('/service-categories',           [RoutingController::class, 'saveCategory']);

        // Teams, routing, canned answers and saved queues.
        Route::get('/workspace',                [WorkspaceController::class, 'index']);
        Route::post('/workspace/teams',         [WorkspaceController::class, 'storeTeam']);
        Route::put('/workspace/teams/{team}',   [WorkspaceController::class, 'updateTeam']);
        Route::get('/workspace/workload',       [WorkspaceController::class, 'workload']);
        Route::post('/workspace/teams/{team}/members', [WorkspaceController::class, 'saveMember']);
        Route::delete('/workspace/teams/{team}/members/{member}', [WorkspaceController::class, 'removeMember']);
        Route::post('/workspace/rules',         [WorkspaceController::class, 'saveRule']);
        Route::delete('/workspace/rules/{rule}', [WorkspaceController::class, 'destroyRule']);
        Route::post('/workspace/canned',        [WorkspaceController::class, 'saveCanned']);
        Route::post('/workspace/canned/{canned}/use', [WorkspaceController::class, 'useCanned']);
        Route::delete('/workspace/canned/{canned}', [WorkspaceController::class, 'destroyCanned']);
        Route::post('/workspace/views',         [WorkspaceController::class, 'saveView']);
        Route::delete('/workspace/views/{view}', [WorkspaceController::class, 'destroyView']);

        // Mail ingestion setup, and the queue of held messages.
        Route::get('/mailboxes',                    [MailboxController::class, 'index']);
        Route::post('/mailboxes',                   [MailboxController::class, 'store']);
        Route::post('/mailboxes/{mailbox}/subscribe', [MailboxController::class, 'subscribe']);
        Route::get('/mail/quarantine',              [MailboxController::class, 'quarantine']);
        Route::post('/mail/quarantine/{email}/release', [MailboxController::class, 'release']);
        Route::get('/sender-rules',                 [MailboxController::class, 'rules']);
        Route::post('/sender-rules',                [MailboxController::class, 'storeRule']);
        Route::delete('/sender-rules/{rule}',       [MailboxController::class, 'destroyRule']);

        // Service levels and the calendars they are measured against.
        Route::get('/sla',                          [SlaController::class, 'index']);
        Route::post('/sla/calendars',               [SlaController::class, 'storeCalendar']);
        Route::put('/sla/calendars/{calendar}/windows', [SlaController::class, 'updateWindows']);
        Route::post('/sla/calendars/{calendar}/holidays', [SlaController::class, 'storeHoliday']);
        Route::delete('/sla/calendars/{calendar}/holidays/{holiday}', [SlaController::class, 'destroyHoliday']);
        Route::post('/sla/policies',                [SlaController::class, 'storeSla']);
        Route::put('/sla/policies/{sla}',           [SlaController::class, 'updateSla']);
        Route::post('/sla/policies/{sla}/targets',  [SlaController::class, 'saveTarget']);
        Route::delete('/sla/policies/{sla}/targets/{target}', [SlaController::class, 'destroyTarget']);
    });

    Route::prefix('payroll')->group(function () {
        Route::get('/history',                              [PayrollController::class, 'history']);
        Route::get('/p9',                                   [PayrollController::class, 'p9']);
        // The years as records with a state, the monthly form, and finishing one.
        Route::get('/p9/reports',                           [PayrollController::class, 'p9Reports']);
        Route::get('/p9/employee/{employee}',               [PayrollController::class, 'p9Months']);
        Route::post('/p9/{year}/complete',                  [PayrollController::class, 'completeP9']);
        Route::get('/p9/{year}/delivery/recipients',        [PayrollController::class, 'p9Recipients']);
        Route::post('/p9/{year}/delivery/send',             [PayrollController::class, 'sendP9']);
        Route::get('/',                                     [PayrollController::class, 'index']);
        Route::post('/',                                    [PayrollController::class, 'store']);
        // Ad-hoc lines on one person's run — overtime, a bonus, an advance
        // recovery. Every write recomputes the run; a line that does not move
        // the net pay is decoration.
        // Standing orders against pay — set up once, recovered by every run
        // after. Balances are derived from what came off approved payroll and
        // are never accepted from the client.
        Route::get('/recurring-deductions',                 [RecurringDeductionController::class, 'index']);
        Route::get('/recurring-deduction-codes',            [RecurringDeductionController::class, 'codes']);
        Route::post('/recurring-deductions',                [RecurringDeductionController::class, 'store']);
        Route::put('/recurring-deductions/{recurringDeduction}',    [RecurringDeductionController::class, 'update']);
        Route::delete('/recurring-deductions/{recurringDeduction}', [RecurringDeductionController::class, 'destroy']);

        Route::get('/run-item-codes',                       [PayrollRunItemController::class, 'codes']);
        Route::get('/runs/{run}/items',                     [PayrollRunItemController::class, 'index']);
        Route::post('/runs/{run}/items',                    [PayrollRunItemController::class, 'store']);
        Route::put('/runs/{run}/items/{item}',              [PayrollRunItemController::class, 'update']);
        Route::delete('/runs/{run}/items/{item}',           [PayrollRunItemController::class, 'destroy']);

        Route::get('/{payrollPeriod}',                      [PayrollController::class, 'show']);
        Route::post('/{payrollPeriod}/process',             [PayrollController::class, 'process']);
        Route::post('/{payrollPeriod}/approve',             [PayrollController::class, 'approve']);
        Route::post('/{payrollPeriod}/mark-paid',           [PayrollController::class, 'markPaid']);
        Route::get('/{payrollPeriod}/slip/{employee}',      [PayrollController::class, 'slip']);
        // Always encrypted; there is no unprotected route to these bytes.
        Route::get('/{payrollPeriod}/slip/{employee}/pdf',  [PayrollController::class, 'slipPdf']);

        // Sending them out. Recipients first, because the point of showing who
        // cannot be sent one is to find them while it is still cheap.
        // The payroll as a file a bank will accept. Preview first: the whole
        // risk of a payment file is that it looks complete.
        // The month's PAYE return. Data rather than KRA's spreadsheet — see
        // P10Report for why guessing at their template is not worth doing.
        Route::get('/{payrollPeriod}/p10',               [PaymentFileController::class, 'p10']);
        Route::get('/{payrollPeriod}/p10.csv',           [PaymentFileController::class, 'p10Csv']);

        Route::get('/{payrollPeriod}/payment-file',      [PaymentFileController::class, 'preview']);
        Route::post('/{payrollPeriod}/payment-file',     [PaymentFileController::class, 'download']);

        Route::get('/{payrollPeriod}/delivery/recipients',  [PayrollController::class, 'deliveryRecipients']);
        Route::post('/{payrollPeriod}/delivery/send',       [PayrollController::class, 'sendPayslips']);
    });

    // ── Payroll Configuration ─────────────────────────────────────────────────
    Route::prefix('payroll-config')->group(function () {
        Route::get('/',                                         [PayrollConfigController::class, 'index']);
        // How payslips are locked. There is no switch for whether.
        Route::get('/document-settings',                        [PayrollConfigController::class, 'documentSettings']);
        Route::put('/document-settings',                        [PayrollConfigController::class, 'updateDocumentSettings']);
        Route::put('/tax-bands',                                [PayrollConfigController::class, 'updateTaxBands']);
        Route::put('/statutory-rates',                          [PayrollConfigController::class, 'updateStatutoryRates']);
        Route::post('/earning-codes',                           [PayrollConfigController::class, 'storeEarningCode']);
        Route::put('/earning-codes/{earningCode}',              [PayrollConfigController::class, 'updateEarningCode']);
        Route::delete('/earning-codes/{earningCode}',           [PayrollConfigController::class, 'destroyEarningCode']);
        Route::post('/deduction-codes',                         [PayrollConfigController::class, 'storeDeductionCode']);
        Route::put('/deduction-codes/{deductionCode}',          [PayrollConfigController::class, 'updateDeductionCode']);
        Route::delete('/deduction-codes/{deductionCode}',       [PayrollConfigController::class, 'destroyDeductionCode']);
        Route::post('/seed-defaults',                           [PayrollConfigController::class, 'seedDefaults']);
    });

    // ── Organisation management ───────────────────────────────────────────────
    Route::prefix('org')->group(function () {
        Route::get('/departments',                 [OrgManagementController::class, 'departments']);
        Route::post('/departments',                [OrgManagementController::class, 'storeDepartment']);
        Route::put('/departments/{department}',    [OrgManagementController::class, 'updateDepartment']);
        Route::delete('/departments/{department}', [OrgManagementController::class, 'destroyDepartment']);

        // Org structure hierarchy
        Route::get('/structure',                              [OrgManagementController::class, 'orgStructure']);
        // The same organisation seen as a reporting line rather than a
        // structure — the two disagree often enough to be worth both.
        Route::get('/org-chart',                              [OrgManagementController::class, 'orgChart']);
        Route::get('/functional-units',                       [OrgManagementController::class, 'functionalUnits']);
        Route::post('/functional-units',                      [OrgManagementController::class, 'storeFunctionalUnit']);
        Route::put('/functional-units/{functionalUnit}',      [OrgManagementController::class, 'updateFunctionalUnit']);
        Route::delete('/functional-units/{functionalUnit}',   [OrgManagementController::class, 'destroyFunctionalUnit']);

        Route::post('/business-units',                        [OrgManagementController::class, 'storeBusinessUnit']);
        Route::put('/business-units/{businessUnit}',          [OrgManagementController::class, 'updateBusinessUnit']);
        Route::delete('/business-units/{businessUnit}',       [OrgManagementController::class, 'destroyBusinessUnit']);

        Route::post('/practices',                             [OrgManagementController::class, 'storePractice']);
        Route::put('/practices/{practice}',                   [OrgManagementController::class, 'updatePractice']);
        Route::delete('/practices/{practice}',                [OrgManagementController::class, 'destroyPractice']);

        Route::get('/designations',                [OrgManagementController::class, 'designations']);
        Route::post('/designations',               [OrgManagementController::class, 'storeDesignation']);
        Route::put('/designations/{designation}',  [OrgManagementController::class, 'updateDesignation']);
        Route::delete('/designations/{designation}',[OrgManagementController::class, 'destroyDesignation']);

        Route::get('/job-grades',                  [OrgManagementController::class, 'jobGrades']);
        Route::post('/job-grades',                 [OrgManagementController::class, 'storeJobGrade']);
        Route::put('/job-grades/{jobGrade}',        [OrgManagementController::class, 'updateJobGrade']);
        Route::delete('/job-grades/{jobGrade}',    [OrgManagementController::class, 'destroyJobGrade']);

        /*
         * Asset categories. Configured per organisation rather than compiled
         * into the frontend, which is what they were.
         */
        // What a form should ask for a given class and category — class-level
        // questions and the category's own, already ordered.
        Route::get('/asset-form-fields',                 [OrgManagementController::class, 'assetFormFields']);

        Route::get('/asset-categories',                  [OrgManagementController::class, 'assetCategories']);
        Route::post('/asset-categories',                 [OrgManagementController::class, 'storeAssetCategory']);
        Route::put('/asset-categories/{assetCategory}',  [OrgManagementController::class, 'updateAssetCategory']);
        Route::delete('/asset-categories/{assetCategory}',[OrgManagementController::class, 'destroyAssetCategory']);

        // What each kind of thing is worth capturing beyond the common fields.
        Route::get('/asset-categories/{assetCategory}/fields',           [OrgManagementController::class, 'categoryFields']);
        Route::post('/asset-categories/{assetCategory}/fields',          [OrgManagementController::class, 'storeCategoryField']);
        Route::put('/asset-categories/{assetCategory}/fields/{field}',   [OrgManagementController::class, 'updateCategoryField']);
        Route::delete('/asset-categories/{assetCategory}/fields/{field}',[OrgManagementController::class, 'destroyCategoryField']);

        Route::get('/job-categories',                    [OrgManagementController::class, 'jobCategories']);
        Route::post('/job-categories',                   [OrgManagementController::class, 'storeJobCategory']);
        Route::put('/job-categories/{jobCategory}',      [OrgManagementController::class, 'updateJobCategory']);
        Route::delete('/job-categories/{jobCategory}',   [OrgManagementController::class, 'destroyJobCategory']);

        // ── Compliance documents ──────────────────────────────────────────────
        Route::get('/compliance',                        [ComplianceController::class, 'index']);
        Route::put('/compliance/{key}',                  [ComplianceController::class, 'update']);
        Route::put('/compliance/{key}/guide',            [ComplianceController::class, 'updateGuide']);
        Route::put('/compliance/{key}/reminder',         [ComplianceController::class, 'updateReminder']);
        Route::post('/compliance/{key}/upload',          [ComplianceController::class, 'uploadFile']);
        Route::delete('/compliance/{key}/file',          [ComplianceController::class, 'deleteFile']);
    });

    // ── Performance, training & promotions ────────────────────────────────────
    Route::prefix('performance')->group(function () {
        Route::get('/reviews',                           [PerformanceController::class, 'reviews']);
        Route::post('/reviews',                          [PerformanceController::class, 'storeReview']);
        Route::put('/reviews/{performanceReview}',       [PerformanceController::class, 'updateReview']);
        Route::delete('/reviews/{performanceReview}',    [PerformanceController::class, 'destroyReview']);

        Route::get('/training',                          [PerformanceController::class, 'training']);
        Route::post('/training',                         [PerformanceController::class, 'storeTraining']);
        Route::delete('/training/{trainingRecord}',      [PerformanceController::class, 'destroyTraining']);

        Route::get('/promotions',                        [PerformanceController::class, 'promotions']);
        Route::post('/promotions',                       [PerformanceController::class, 'storePromotion']);
    });

    // ── Public Holidays ───────────────────────────────────────────────────────
    Route::prefix('public-holidays')->group(function () {
        Route::get('/',                    [PublicHolidayController::class, 'index']);
        Route::post('/import',             [PublicHolidayController::class, 'import']);
        Route::post('/',                   [PublicHolidayController::class, 'store']);
        Route::put('/{publicHoliday}',     [PublicHolidayController::class, 'update']);
        Route::delete('/{publicHoliday}',  [PublicHolidayController::class, 'destroy']);
    });

    // ── Benefits ──────────────────────────────────────────────────────────────
    Route::prefix('benefits')->group(function () {
        Route::get('/definitions',                   [BenefitController::class, 'indexDefinitions']);
        Route::post('/definitions',                  [BenefitController::class, 'storeDefinition']);
        Route::put('/definitions/{benefit}',         [BenefitController::class, 'updateDefinition']);
        Route::delete('/definitions/{benefit}',      [BenefitController::class, 'destroyDefinition']);

        Route::get('/plans',                         [BenefitController::class, 'indexPlans']);
        Route::post('/plans',                        [BenefitController::class, 'storePlan']);
        Route::put('/plans/{plan}',                  [BenefitController::class, 'updatePlan']);
        Route::delete('/plans/{plan}',               [BenefitController::class, 'destroyPlan']);

        Route::get('/assignments',                   [BenefitController::class, 'indexAssignments']);
        Route::post('/assignments',                  [BenefitController::class, 'storeAssignment']);
        Route::delete('/assignments/{assignment}',   [BenefitController::class, 'destroyAssignment']);

        Route::get('/dependents',                    [BenefitController::class, 'indexDependents']);
        Route::post('/dependents',                   [BenefitController::class, 'storeDependent']);
        Route::delete('/dependents/{dependent}',     [BenefitController::class, 'destroyDependent']);

        Route::get('/approvals',                     [BenefitController::class, 'indexApprovals']);
        Route::post('/approvals',                    [BenefitController::class, 'storeApproval']);
        Route::patch('/approvals/{approval}/action', [BenefitController::class, 'actionApproval']);
    });

    // ── Recruitment ───────────────────────────────────────────────────────────
    /*
     * Talent requisitions — asking for a role, and the decision on it.
     *
     * Ahead of the recruitment group, because this is now where the journey
     * starts: a vacancy is what an approved requisition becomes.
     */
    Route::prefix('requisitions')->group(function () {
        Route::get('/',        [TalentRequisitionController::class, 'index']);
        Route::post('/',       [TalentRequisitionController::class, 'store']);
        // Before /{requisition}, or the router reads these as ids.
        Route::get('/summary', [TalentRequisitionController::class, 'summary']);
        Route::get('/inbox',   [TalentRequisitionController::class, 'inbox']);
        Route::get('/policy',  [TalentRequisitionController::class, 'policyFor']);
        // The templates a requisition can inherit from — job details, the
        // application form, screening rules and the candidate pipeline.
        Route::get('/templates', [TalentRequisitionController::class, 'templates']);

        Route::get('/{requisition}',  [TalentRequisitionController::class, 'show']);
        Route::put('/{requisition}',  [TalentRequisitionController::class, 'update']);

        Route::post('/{requisition}/submit', [TalentRequisitionController::class, 'submit']);
        // Approve, hold or decline — authorised by the chain, not a role.
        Route::post('/{requisition}/decide', [TalentRequisitionController::class, 'decide']);
        Route::post('/{requisition}/resume', [TalentRequisitionController::class, 'resume']);
        Route::post('/{requisition}/close',  [TalentRequisitionController::class, 'close']);
    });

    Route::prefix('recruitment')->group(function () {
        // Dashboard stats
        Route::get('/stats', [VacancyController::class, 'stats']);

        // Workflows
        Route::get('/workflows',                              [WorkflowController::class, 'index']);
        Route::post('/workflows',                             [WorkflowController::class, 'store']);
        Route::get('/workflows/{workflow}',                   [WorkflowController::class, 'show']);
        Route::put('/workflows/{workflow}',                   [WorkflowController::class, 'update']);
        Route::put('/workflows/{workflow}/stages',            [WorkflowController::class, 'stagesUpdate']);
        Route::delete('/workflows/{workflow}',                [WorkflowController::class, 'destroy']);

        // Vacancy Templates
        Route::get('/templates',                              [VacancyTemplateController::class, 'index']);
        Route::post('/templates',                             [VacancyTemplateController::class, 'store']);
        Route::get('/templates/{vacancyTemplate}',            [VacancyTemplateController::class, 'show']);
        Route::put('/templates/{vacancyTemplate}',            [VacancyTemplateController::class, 'update']);
        Route::delete('/templates/{vacancyTemplate}',         [VacancyTemplateController::class, 'destroy']);
        Route::post('/templates/{vacancyTemplate}/duplicate', [VacancyTemplateController::class, 'duplicate']);

        // Vacancies
        Route::get('/vacancies',                              [VacancyController::class, 'index']);
        Route::post('/vacancies',                             [VacancyController::class, 'store']);
        Route::get('/vacancies/{vacancy}',                    [VacancyController::class, 'show']);
        Route::put('/vacancies/{vacancy}',                    [VacancyController::class, 'update']);
        Route::patch('/vacancies/{vacancy}/close',            [VacancyController::class, 'close']);

        // Applications (nested under vacancy)
        Route::get('/vacancies/{vacancy}/applications',       [ApplicationController::class, 'index']);
        Route::post('/vacancies/{vacancy}/applications',      [ApplicationController::class, 'store']);

        // Applications (standalone)
        Route::get('/applications/{application}',             [ApplicationController::class, 'show']);
        // The skills they claimed against what the role asked for, and what
        // the panel made of them.
        Route::get('/applications/{application}/suitability',  [ApplicationController::class, 'suitability']);
        /*
         * The supporting document an applicant attached alongside their CV.
         *
         * It was stored and never served: the portal seals it onto the private
         * disk, and the only thing that ever looked for it checked the public
         * disk, where it has not been since CVs were moved off the storage
         * symlink. Written, encrypted, and unreachable.
         */
        Route::get('/applications/{application}/attachment',  [ApplicationController::class, 'downloadAttachment']);
        Route::patch('/applications/{application}/stage',     [ApplicationController::class, 'moveStage']);
        Route::post('/applications/bulk-action',              [ApplicationController::class, 'bulkAction']);

        // Candidates
        Route::get('/candidates/export',                      [CandidateController::class, 'exportCsv']);
        Route::post('/candidates/bulk-action',                [CandidateController::class, 'bulkAction']);
        Route::get('/candidates',                             [CandidateController::class, 'index']);
        Route::post('/candidates',                            [CandidateController::class, 'store']);
        Route::get('/candidates/{candidate}',                 [CandidateController::class, 'show']);
        Route::put('/candidates/{candidate}',                 [CandidateController::class, 'update']);
        Route::delete('/candidates/{candidate}',              [CandidateController::class, 'destroy']);
        Route::post('/candidates/{candidate}/resume',         [CandidateController::class, 'uploadResume']);
        Route::get('/candidates/{candidate}/resume',          [CandidateController::class, 'downloadResume']);

        // Interviews
        Route::get('/employees-search',                                        [InterviewController::class, 'employees']);
        Route::get('/applications/{applicationId}/interviews',                 [InterviewController::class, 'index']);
        Route::post('/applications/{applicationId}/interviews',                [InterviewController::class, 'store']);
        Route::get('/interviews/{interview}',                                  [InterviewController::class, 'show']);
        Route::put('/interviews/{interview}',                                  [InterviewController::class, 'update']);
        Route::post('/interviews/{interview}/send-invites',                    [InterviewController::class, 'sendInvites']);
        Route::post('/interviews/{interview}/send-candidate-invite',           [InterviewController::class, 'sendCandidateInvite']);
        Route::post('/interviews/{interview}/create-teams-event',              [InterviewController::class, 'createTeamsEvent']);
        Route::post('/interviews/{interview}/scores',                          [InterviewController::class, 'saveScore']);
    });

    // ── Training & Competency ──────────────────────────────────────────────────
    Route::prefix('training')->group(function () {
        Route::get('/dashboard',                [TrainingDashboardController::class, 'summary']);

        // Catalog (training items)
        Route::prefix('catalog')->group(function () {
            Route::get('/',                [TrainingItemController::class, 'index']);
            Route::post('/',               [TrainingItemController::class, 'store']);
            Route::get('/stats',           [TrainingItemController::class, 'stats']);
            Route::get('/{trainingItem}',  [TrainingItemController::class, 'show']);
            Route::put('/{trainingItem}',  [TrainingItemController::class, 'update']);
            Route::delete('/{trainingItem}', [TrainingItemController::class, 'destroy']);
        });

        // Competencies & Role Profiles
        Route::prefix('competencies')->group(function () {
            Route::get('/',                        [CompetencyController::class, 'indexCompetencies']);
            Route::post('/',                       [CompetencyController::class, 'storeCompetency']);
            Route::get('/{competencyItem}',        [CompetencyController::class, 'showCompetency']);
            Route::put('/{competencyItem}',        [CompetencyController::class, 'updateCompetency']);
            Route::delete('/{competencyItem}',     [CompetencyController::class, 'destroyCompetency']);
        });
        Route::prefix('role-profiles')->group(function () {
            Route::get('/',                       [CompetencyController::class, 'indexProfiles']);
            Route::post('/',                      [CompetencyController::class, 'storeProfile']);
            Route::get('/{roleProfile}',          [CompetencyController::class, 'showProfile']);
            Route::put('/{roleProfile}',          [CompetencyController::class, 'updateProfile']);
            Route::delete('/{roleProfile}',       [CompetencyController::class, 'destroyProfile']);
        });

        // Training records (enrollment & progress)
        Route::prefix('records')->group(function () {
            Route::get('/',                        [TrainingRecordController::class, 'index']);
            Route::get('/my',                      [TrainingRecordController::class, 'myRecords']);
            Route::post('/',                       [TrainingRecordController::class, 'store']);
            Route::post('/bulk-assign',            [TrainingRecordController::class, 'bulkAssign']);
            Route::get('/{trainingRecord}',        [TrainingRecordController::class, 'show']);
            Route::patch('/{trainingRecord}/progress', [TrainingRecordController::class, 'logProgress']);
            Route::post('/{trainingRecord}/checkin',  [TrainingRecordController::class, 'checkIn']);
            Route::post('/{trainingRecord}/evidence', [TrainingRecordController::class, 'uploadEvidence']);
            Route::get('/{trainingRecord}/evidence/{evidenceFile}/download', [TrainingRecordController::class, 'downloadEvidence']);
            Route::patch('/{trainingRecord}/priority', [TrainingRecordController::class, 'setPriority']);
            Route::patch('/{trainingRecord}/withdraw', [TrainingRecordController::class, 'withdraw']);
        });

        // Training assignments (approval workflow - Phase 3)
        Route::prefix('assignments')->group(function () {
            Route::post('/',                  [TrainingAssignmentController::class, 'bulkCreate']);
            Route::get('/',                   [TrainingAssignmentController::class, 'index']);
            Route::get('/my-pending',         [TrainingAssignmentController::class, 'myPending']);
            Route::get('/stats',              [TrainingAssignmentController::class, 'stats']);
            Route::get('/{assignment}',       [TrainingAssignmentController::class, 'show']);
            Route::patch('/{assignment}/approve', [TrainingAssignmentController::class, 'approve']);
            Route::patch('/{assignment}/reject',  [TrainingAssignmentController::class, 'reject']);
        });

        // Vendor compliance (Phase 4)
        Route::prefix('vendor-compliance')->group(function () {
            Route::get('/summary',                          [VendorComplianceController::class, 'summary']);
            Route::post('/bulk-assign',                     [VendorComplianceController::class, 'bulkAssign']);
            Route::get('/',                                 [VendorComplianceController::class, 'index']);
            Route::post('/',                                [VendorComplianceController::class, 'store']);
            Route::get('/{vendorPartner}',                  [VendorComplianceController::class, 'show']);
            Route::put('/{vendorPartner}',                  [VendorComplianceController::class, 'update']);
            Route::delete('/{vendorPartner}',               [VendorComplianceController::class, 'destroy']);
            Route::put('/{vendorPartner}/requirements',     [VendorComplianceController::class, 'syncRequirements']);
            Route::get('/{vendorPartner}/status',           [VendorComplianceController::class, 'vendorStatus']);
        });
    });

    // ── OKRs ──────────────────────────────────────────────────────────────────
    Route::prefix('okr')->group(function () {
        Route::get('/cycles',                                          [OkrController::class, 'indexCycles']);
        Route::post('/cycles',                                         [OkrController::class, 'storeCycle']);
        Route::get('/cycles/{okrCycle}',                               [OkrController::class, 'showCycle']);
        Route::put('/cycles/{okrCycle}',                               [OkrController::class, 'updateCycle']);

        Route::post('/cycles/{okrCycle}/objectives',                   [OkrController::class, 'storeObjective']);
        Route::put('/objectives/{okrObjective}',                       [OkrController::class, 'updateObjective']);
        Route::delete('/objectives/{okrObjective}',                    [OkrController::class, 'destroyObjective']);

        Route::post('/objectives/{okrObjective}/key-results',          [OkrController::class, 'storeKeyResult']);
        Route::put('/key-results/{okrKeyResult}',                      [OkrController::class, 'updateKeyResult']);
        Route::delete('/key-results/{okrKeyResult}',                   [OkrController::class, 'destroyKeyResult']);
        Route::put('/key-results/{okrKeyResult}/achievement',          [OkrController::class, 'updateAchievement']);
        Route::post('/key-results/{okrKeyResult}/dept-scores',         [OkrController::class, 'upsertDeptScores']);
    });

    // ── Goals & Individual OKRs ───────────────────────────────────────────────
    Route::prefix('goals')->group(function () {
        Route::get('/',                                    [GoalController::class, 'index']);
        Route::post('/',                                   [GoalController::class, 'store']);
        Route::get('/{goal}',                              [GoalController::class, 'show']);
        Route::put('/{goal}',                              [GoalController::class, 'update']);
        Route::delete('/{goal}',                           [GoalController::class, 'destroy']);
        Route::put('/{goal}/completion',                   [GoalController::class, 'updateCompletion']);
        Route::put('/{goal}/status',                       [GoalController::class, 'updateStatus']);
        Route::post('/{goal}/key-results',                 [GoalController::class, 'storeKeyResult']);
        Route::put('/key-results/{keyResult}',             [GoalController::class, 'updateKeyResult']);
        Route::delete('/key-results/{keyResult}',          [GoalController::class, 'destroyKeyResult']);
    });

    // ── Roster ────────────────────────────────────────────────────────────────
    Route::prefix('roster')->group(function () {

        // ── General Configuration ─────────────────────────────────────────────
        Route::get('/config',  [RosterConfigController::class, 'showConfig']);
        Route::put('/config',  [RosterConfigController::class, 'updateConfig']);

        // ── Shift Groups ──────────────────────────────────────────────────────
        Route::prefix('shift-groups')->group(function () {
            Route::get('/',                    [RosterConfigController::class, 'indexShiftGroups']);
            Route::post('/',                   [RosterConfigController::class, 'storeShiftGroup']);
            Route::put('/{shiftGroup}',        [RosterConfigController::class, 'updateShiftGroup']);
            Route::delete('/{shiftGroup}',     [RosterConfigController::class, 'destroyShiftGroup']);
        });

        // ── Shift Types ───────────────────────────────────────────────────────
        Route::prefix('shift-types')->group(function () {
            Route::get('/',                    [RosterConfigController::class, 'indexShiftTypes']);
            Route::post('/',                   [RosterConfigController::class, 'storeShiftType']);
            Route::put('/{shiftType}',         [RosterConfigController::class, 'updateShiftType']);
            Route::delete('/{shiftType}',      [RosterConfigController::class, 'destroyShiftType']);
        });

        // ── Handover Reports ──────────────────────────────────────────────────
        Route::prefix('handovers')->group(function () {
            Route::get('/',                        [HandoverController::class, 'index']);
            Route::post('/',                       [HandoverController::class, 'store']);
            Route::put('/{handover}',              [HandoverController::class, 'update']);
            Route::post('/{handover}/acknowledge', [HandoverController::class, 'acknowledge']);
        });

        // ── Shift Swap Requests (before /{schedule} to avoid route collision) ─
        Route::prefix('swaps')->group(function () {
            Route::get('/',                    [RosterShiftSwapController::class, 'index']);
            Route::post('/',                   [RosterShiftSwapController::class, 'store']);
            Route::post('/{swap}/respond',     [RosterShiftSwapController::class, 'respond']);
            Route::post('/{swap}/approve',     [RosterShiftSwapController::class, 'approve']);
            Route::delete('/{swap}',           [RosterShiftSwapController::class, 'cancel']);
        });

        // ── Schedules ─────────────────────────────────────────────────────────
        Route::get('/',                        [RosterScheduleController::class, 'index']);
        Route::post('/',                       [RosterScheduleController::class, 'store']);
        Route::get('/{schedule}',              [RosterScheduleController::class, 'show']);
        Route::put('/{schedule}',              [RosterScheduleController::class, 'update']);
        Route::post('/{schedule}/publish',     [RosterScheduleController::class, 'publish']);
        Route::delete('/{schedule}',           [RosterScheduleController::class, 'destroy']);

        // ── Assignments (nested under schedule) ───────────────────────────────
        Route::get('/{schedule}/assignments',                            [RosterScheduleController::class, 'assignments']);
        Route::post('/{schedule}/assignments',                           [RosterScheduleController::class, 'assign']);
        Route::post('/{schedule}/assignments/bulk',                      [RosterScheduleController::class, 'bulkAssign']);
        Route::delete('/{schedule}/assignments/{assignment}',            [RosterScheduleController::class, 'removeAssignment']);
    });

    // ── Vendor Module ─────────────────────────────────────────────────────────
    Route::prefix('vendors')->group(function () {
        Route::get('/metrics',                  [VendorController::class, 'metrics']);
        Route::get('/',                         [VendorController::class, 'index']);
        Route::post('/',                        [VendorController::class, 'store']);
        Route::get('/{vendor}',                 [VendorController::class, 'show']);
        Route::put('/{vendor}',                 [VendorController::class, 'update']);
        Route::delete('/{vendor}',              [VendorController::class, 'destroy']);
        Route::post('/{vendor}/submit',         [VendorController::class, 'submitForReview']);
        Route::post('/{vendor}/approve',        [VendorController::class, 'approve']);
        Route::post('/{vendor}/reject',         [VendorController::class, 'reject']);
        Route::post('/{vendor}/suspend',        [VendorController::class, 'suspend']);
        Route::post('/{vendor}/reactivate',     [VendorController::class, 'reactivate']);

        // ── Vendor sub-resources (products, incentives, professional certs) ──
        Route::get('/{vendor}/products',                          [VendorProfileController::class, 'indexProducts']);
        Route::post('/{vendor}/products',                         [VendorProfileController::class, 'storeProduct']);
        Route::put('/{vendor}/products/{product}',                [VendorProfileController::class, 'updateProduct']);
        Route::delete('/{vendor}/products/{product}',             [VendorProfileController::class, 'destroyProduct']);

        Route::get('/{vendor}/incentives',                        [VendorProfileController::class, 'indexIncentives']);
        Route::post('/{vendor}/incentives',                       [VendorProfileController::class, 'storeIncentive']);
        Route::put('/{vendor}/incentives/{incentive}',            [VendorProfileController::class, 'updateIncentive']);
        Route::delete('/{vendor}/incentives/{incentive}',         [VendorProfileController::class, 'destroyIncentive']);

        Route::get('/{vendor}/certifications',                    [VendorProfileController::class, 'indexCertifications']);
        Route::post('/{vendor}/certifications',                   [VendorProfileController::class, 'storeCertification']);
        Route::put('/{vendor}/certifications/{certification}',    [VendorProfileController::class, 'updateCertification']);
        Route::delete('/{vendor}/certifications/{certification}', [VendorProfileController::class, 'destroyCertification']);

        // ── Partnership tiers ──────────────────────────────────────────────────
        Route::get('/{vendor}/tiers',                           [VendorProfileController::class, 'indexTiers']);
        Route::post('/{vendor}/tiers',                          [VendorProfileController::class, 'storeTier']);
        Route::put('/{vendor}/tiers/{tier}',                    [VendorProfileController::class, 'updateTier']);
        Route::delete('/{vendor}/tiers/{tier}',                 [VendorProfileController::class, 'destroyTier']);
        Route::post('/{vendor}/tiers/{tier}/set-current',       [VendorProfileController::class, 'setCurrentTier']);

        // ── Portal users ───────────────────────────────────────────────────────
        Route::get('/{vendor}/portal-users',                     [VendorProfileController::class, 'indexPortalUsers']);
        Route::post('/{vendor}/portal-users',                    [VendorProfileController::class, 'storePortalUser']);
        Route::put('/{vendor}/portal-users/{portalUser}',        [VendorProfileController::class, 'updatePortalUser']);
        Route::delete('/{vendor}/portal-users/{portalUser}',     [VendorProfileController::class, 'destroyPortalUser']);

        // ── Customers ──────────────────────────────────────────────────────────
        Route::get('/{vendor}/customers',                        [VendorProfileController::class, 'indexCustomers']);
        Route::post('/{vendor}/customers',                       [VendorProfileController::class, 'storeCustomer']);
        Route::put('/{vendor}/customers/{customer}',             [VendorProfileController::class, 'updateCustomer']);
        Route::delete('/{vendor}/customers/{customer}',          [VendorProfileController::class, 'destroyCustomer']);

        // ── Customer Projects ──────────────────────────────────────────────────
        Route::get('/{vendor}/projects',                         [VendorProfileController::class, 'indexProjects']);
        Route::post('/{vendor}/projects',                        [VendorProfileController::class, 'storeProject']);
        Route::put('/{vendor}/projects/{project}',               [VendorProfileController::class, 'updateProject']);
        Route::delete('/{vendor}/projects/{project}',            [VendorProfileController::class, 'destroyProject']);
    });

    // ── Notifications (Phase 3) ────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get('/count',            [NotificationController::class, 'unreadCount']);
        Route::get('/',                 [NotificationController::class, 'all']);
        Route::patch('/{id}/read',      [NotificationController::class, 'markAsRead']);
        Route::patch('/mark-all-read',  [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}',          [NotificationController::class, 'delete']);
    });
});

// ── Organisations (list & create — no org context required) ──────────────────
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/organizations',                          [OrganizationController::class, 'index']);
    Route::get('/organizations/{organization}',           [OrganizationController::class, 'show']);
    Route::post('/organizations',                         [OrganizationController::class, 'store']);
    Route::put('/organizations/{organization}',           [OrganizationController::class, 'update']);
    Route::post('/organizations/{organization}/logo',     [OrganizationController::class, 'uploadLogo']);
});

