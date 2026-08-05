<?php

use App\Http\Controllers\HR\ComplianceController;
use App\Http\Controllers\Benefits\BenefitController;
use App\Http\Controllers\HR\DocumentController;
use App\Http\Controllers\HR\EmployeeController;
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
use App\Http\Controllers\WorkLocationController;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Asset\AssetController;
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
use App\Http\Controllers\Public\PublicJobController;
use App\Http\Controllers\Public\PublicPreboardingController;
use App\Http\Controllers\PublicHolidayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth (no CSRF — Bearer token based) ──────────────────────────────────────
Route::post('/login',  [AuthenticatedSessionController::class, 'store'])->middleware('guest');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');

// Azure AD SSO
Route::prefix('auth/azure')->middleware('throttle:20,1')->group(function () {
    Route::get('/redirect',  [AzureSsoController::class, 'redirect']);
    Route::get('/callback',  [AzureSsoController::class, 'callback']);
    Route::post('/exchange', [AzureSsoController::class, 'exchange']);
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
    Route::prefix('work-location')->group(function () {
        Route::get('/today',      [WorkLocationController::class, 'today']);
        Route::get('/history',    [WorkLocationController::class, 'history']);
        Route::get('/team',       [WorkLocationController::class, 'team']);
        Route::post('/check-in',  [WorkLocationController::class, 'checkIn']);
        Route::post('/check-out', [WorkLocationController::class, 'checkOut']);
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
        Route::get('/{account}',       [AccountController::class, 'show']);
        Route::put('/{account}',       [AccountController::class, 'update']);
        Route::delete('/{account}',    [AccountController::class, 'destroy']);

        Route::post('/{account}/contacts',              [AccountController::class, 'storeContact']);
        Route::put('/{account}/contacts/{contact}',     [AccountController::class, 'updateContact']);
        Route::delete('/{account}/contacts/{contact}',  [AccountController::class, 'destroyContact']);
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
        Route::get('/approval-stages',  [TravelSettingsController::class, 'stages']);
        Route::put('/approval-stages',  [TravelSettingsController::class, 'saveStages']);
    });

    // ── Assets ────────────────────────────────────────────────────────────────
    Route::prefix('assets')->group(function () {
        Route::get('/',            [AssetController::class, 'index']);
        Route::post('/',           [AssetController::class, 'store']);
        Route::get('/{asset}',     [AssetController::class, 'show']);
        Route::put('/{asset}',     [AssetController::class, 'update']);
        Route::delete('/{asset}',  [AssetController::class, 'destroy']);

        // Custody is its own permission, so it gets its own endpoints rather
        // than being a field on update.
        Route::post('/{asset}/assign',  [AssetController::class, 'assign']);
        Route::post('/{asset}/release', [AssetController::class, 'release']);
    });

    // ── Pre-Onboarding (Preboarding) ──────────────────────────────────────────
    Route::prefix('preboarding')->group(function () {
        Route::get('/',                                          [PreboardingController::class, 'index']);
        Route::post('/',                                         [PreboardingController::class, 'store']);
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
        Route::post('/calculate',          [LeaveController::class, 'calculate']); // preview day count
        Route::get('/',                    [LeaveController::class, 'index']);
        Route::post('/',                   [LeaveController::class, 'store']);
        Route::get('/{leaveRequest}',      [LeaveController::class, 'show']);
        Route::post('/{leaveRequest}/approve', [LeaveController::class, 'approve']);
        Route::post('/{leaveRequest}/reject',  [LeaveController::class, 'reject']);
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
    Route::prefix('payroll')->group(function () {
        Route::get('/history',                              [PayrollController::class, 'history']);
        Route::get('/p9',                                   [PayrollController::class, 'p9']);
        Route::get('/',                                     [PayrollController::class, 'index']);
        Route::post('/',                                    [PayrollController::class, 'store']);
        Route::get('/{payrollPeriod}',                      [PayrollController::class, 'show']);
        Route::post('/{payrollPeriod}/process',             [PayrollController::class, 'process']);
        Route::post('/{payrollPeriod}/approve',             [PayrollController::class, 'approve']);
        Route::post('/{payrollPeriod}/mark-paid',           [PayrollController::class, 'markPaid']);
        Route::get('/{payrollPeriod}/slip/{employee}',      [PayrollController::class, 'slip']);
    });

    // ── Payroll Configuration ─────────────────────────────────────────────────
    Route::prefix('payroll-config')->group(function () {
        Route::get('/',                                         [PayrollConfigController::class, 'index']);
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

