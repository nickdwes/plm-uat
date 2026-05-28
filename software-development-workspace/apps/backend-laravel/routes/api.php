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
use App\Http\Controllers\OKR\OkrController;
use App\Http\Controllers\Recruitment\ApplicationController;
use App\Http\Controllers\Recruitment\CandidateController;
use App\Http\Controllers\Recruitment\InterviewController;
use App\Http\Controllers\Recruitment\VacancyController;
use App\Http\Controllers\Recruitment\VacancyTemplateController;
use App\Http\Controllers\Recruitment\WorkflowController;
use App\Http\Controllers\Goals\GoalController;
use App\Http\Controllers\Roster\RosterConfigController;
use App\Http\Controllers\Roster\RosterScheduleController;
use App\Http\Controllers\Roster\RosterShiftSwapController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Public\PublicJobController;
use App\Http\Controllers\PublicHolidayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ── Auth (no CSRF — Bearer token based) ──────────────────────────────────────
Route::post('/login',  [AuthenticatedSessionController::class, 'store'])->middleware('guest');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:sanctum');

// Current authenticated user.
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// ── Public Job Portal (no auth, rate-limited) ─────────────────────────────────
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/jobs',                      [PublicJobController::class, 'index']);
    Route::get('/jobs/{uuid}',               [PublicJobController::class, 'show']);
    Route::post('/jobs/{uuid}/apply',        [PublicJobController::class, 'apply'])->middleware('throttle:5,1');
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
        Route::get('/{employee}',        [EmployeeController::class, 'show']);
        Route::put('/{employee}',        [EmployeeController::class, 'update']);
        Route::post('/{employee}/exit',  [EmployeeController::class, 'exit']);

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
        Route::get('/candidates',                             [CandidateController::class, 'index']);
        Route::post('/candidates',                            [CandidateController::class, 'store']);
        Route::get('/candidates/{candidate}',                 [CandidateController::class, 'show']);
        Route::put('/candidates/{candidate}',                 [CandidateController::class, 'update']);
        Route::post('/candidates/{candidate}/resume',         [CandidateController::class, 'uploadResume']);

        // Interviews
        Route::get('/employees-search',                                        [InterviewController::class, 'employees']);
        Route::get('/applications/{applicationId}/interviews',                 [InterviewController::class, 'index']);
        Route::post('/applications/{applicationId}/interviews',                [InterviewController::class, 'store']);
        Route::get('/interviews/{interview}',                                  [InterviewController::class, 'show']);
        Route::put('/interviews/{interview}',                                  [InterviewController::class, 'update']);
        Route::post('/interviews/{interview}/send-invites',                    [InterviewController::class, 'sendInvites']);
        Route::post('/interviews/{interview}/scores',                          [InterviewController::class, 'saveScore']);
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
});

// ── Organisations (list & create — no org context required) ──────────────────
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    Route::get('/organizations',                          [OrganizationController::class, 'index']);
    Route::get('/organizations/{organization}',           [OrganizationController::class, 'show']);
    Route::post('/organizations',                         [OrganizationController::class, 'store']);
    Route::put('/organizations/{organization}',           [OrganizationController::class, 'update']);
    Route::post('/organizations/{organization}/logo',     [OrganizationController::class, 'uploadLogo']);
});

