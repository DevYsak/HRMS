<?php

use App\Http\Controllers\AdmsController;
use App\Http\Controllers\BiometricDashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentUploadController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\ReportController;
use App\Livewire\AiAssistantPage;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceSettings;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Attendance\BiometricSummary;
use App\Livewire\Attendance\TeamAttendance;
use App\Livewire\AuditLogViewer;
use App\Livewire\Dashboard;
use App\Livewire\DepartmentDashboard;
use App\Livewire\Documents\DocumentManager;
use App\Livewire\Employees\Directory;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Employees\EmployeeImport;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Employees\FinanceEmployeeProfile;
use App\Livewire\Employees\OrgChart;
use App\Livewire\Employees\ProbationConfirmation;
use App\Livewire\ExecutiveDashboard;
use App\Livewire\FinanceDashboard;
use App\Livewire\HrAdminDashboard;
use App\Livewire\ManagerDashboard;
use App\Livewire\NotificationsPage;
use App\Livewire\Onboarding\OffboardingChecklist;
use App\Livewire\Onboarding\OffboardingManager;
use App\Livewire\Onboarding\OnboardingChecklist;
use App\Livewire\Onboarding\OnboardingManager;
use App\Livewire\Operations\Assets;
use App\Livewire\Operations\Expenses;
use App\Livewire\Overtime\ManageOtRequests;
use App\Livewire\Overtime\MyOtRequests;
use App\Livewire\Payroll\Components;
use App\Livewire\Payroll\FinanceApproval;
use App\Livewire\Payroll\Incentives;
use App\Livewire\Payroll\MyPayslips;
use App\Livewire\Payroll\Overview;
use App\Livewire\Payroll\Process;
use App\Livewire\Payroll\Reimbursements;
use App\Livewire\Payroll\SalaryStructures;
use App\Livewire\Performance\AllReviews;
use App\Livewire\Performance\Dashboard as PerformanceDashboard;
use App\Livewire\Performance\EmployeeScorecard;
use App\Livewire\Performance\Goals;
use App\Livewire\Performance\KpiDashboard;
use App\Livewire\Performance\KpiTemplates;
use App\Livewire\Performance\ManagePips;
use App\Livewire\Performance\ManagePromotions;
use App\Livewire\Performance\MyKpis;
use App\Livewire\Performance\MyPip;
use App\Livewire\Performance\MyPromotions;
use App\Livewire\Performance\MyReview;
use App\Livewire\Performance\MyWarnings;
use App\Livewire\Performance\ReviewCycles;
use App\Livewire\Performance\TeamReviews;
use App\Livewire\Performance\WarningLetters;
use App\Livewire\Settings\ControlPanel;
use App\Livewire\Settings\DepartmentManager;
use App\Livewire\Settings\EmploymentTypeManager;
use App\Livewire\Settings\JobTitleManager;
use App\Livewire\Settings\MenuSettings;
use App\Livewire\Settings\NotificationSettings;
use App\Livewire\Settings\OnboardingTemplateManager;
use App\Livewire\Settings\OnboardingTemplateTaskManager;
use App\Livewire\Settings\RoleManager;
use App\Livewire\Settings\SalaryCycleManager;
use App\Livewire\Settings\WorkModeManager;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\BulkLeaveAssignment;
use App\Livewire\TimeOff\FinanceEncashments;
use App\Livewire\TimeOff\LeaveAllocationPolicies;
use App\Livewire\TimeOff\MyTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Livewire\TimeOff\TimeOffSettings;
use App\Livewire\Wfh\ManageWfhRequests;
use App\Livewire\Wfh\MyWfhRequests;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// ======================================================
// eSSL ADMS push receiver — no auth, device posts directly
// ======================================================
Route::prefix('iclock')->name('adms.')->group(function () {
    Route::get('/cdata', [AdmsController::class, 'options']);
    Route::post('/cdata', [AdmsController::class, 'upload']);
    Route::get('/getrequest', [AdmsController::class, 'getRequest']);
    Route::post('/devicecmd', [AdmsController::class, 'deviceCmd']);
});

// ======================================================
// Public routes
// ======================================================
Route::view('/welcome', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

// ======================================================
// Authenticated + Email Verified routes
// ======================================================
Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/', Dashboard::class)->name('dashboard');

    // AI Assistant full page
    Route::get('/ai-assistant', AiAssistantPage::class)->name('ai.assistant');

    // --------------------------------------------------
    // Employees module
    // --------------------------------------------------
    Route::prefix('employees')->name('employees.')->group(function () {
        // All authenticated users may browse the directory and org chart
        Route::get('/', EmployeeIndex::class)->name('index');
        Route::get('/directory', Directory::class)->name('directory');
        Route::get('/org-chart', OrgChart::class)->name('org-chart');

        // Finance read-only salary/bank profile
        Route::get('/{employee}/finance-profile', FinanceEmployeeProfile::class)
            ->name('finance-profile')
            ->middleware('role:view-finance-profile');

        // HR / admin only
        Route::middleware('role:manage-employees')->group(function () {
            Route::get('/create', EmployeeCreate::class)->name('create');
            Route::get('/import', EmployeeImport::class)->name('import');
            Route::get('/{employee}/edit', EmployeeEdit::class)->name('edit');
            Route::get('/{employee}/probation', ProbationConfirmation::class)->name('probation');
            Route::get('/{employee}/onboarding', OnboardingChecklist::class)->name('onboarding');
            Route::get('/{employee}/offboarding', OffboardingChecklist::class)->name('offboarding');
            Route::get('/onboarding-manager', OnboardingManager::class)->name('onboarding-manager');
            Route::get('/offboarding-manager', OffboardingManager::class)->name('offboarding-manager');
        });
    });

    // --------------------------------------------------
    // Time Off module
    // --------------------------------------------------
    Route::prefix('time-off')->name('time-off.')->group(function () {
        Route::get('/my', MyTimeOff::class)->name('my');
        Route::middleware('role:approve-leave')->group(function () {
            Route::get('/team', TeamTimeOff::class)->name('team');
            Route::get('/employees', AllTimeOff::class)->name('employees');
        });
        Route::get('/encashments', FinanceEncashments::class)->name('encashments')->middleware('role:approve-finance');
        Route::get('/bulk-assign', BulkLeaveAssignment::class)->name('bulk-assign')->middleware('role:manage-settings');
        Route::get('/leave-policies', LeaveAllocationPolicies::class)->name('leave-policies')->middleware('role:manage-settings');
        Route::get('/settings', TimeOffSettings::class)->name('settings')->middleware('role:manage-settings');
    });

    // --------------------------------------------------
    // Attendance module
    // --------------------------------------------------
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/my', AttendanceTracker::class)->name('my');
        Route::middleware('role:approve-leave')->group(function () {
            Route::get('/team', TeamAttendance::class)->name('team');
            Route::get('/employees', AllAttendance::class)->name('employees');
        });
        Route::get('/settings', AttendanceSettings::class)->name('settings')->middleware('role:manage-settings');
        Route::get('/biometric-summary', BiometricSummary::class)->name('biometric-summary')->middleware('role:approve-leave');

        // Standalone v2 dashboard (Python-style UI, live engine data via same-origin proxy)
        Route::middleware('role:approve-leave')->group(function () {
            Route::get('/dashboard-v2', [BiometricDashboardController::class, 'index'])->name('dashboard-v2');
            Route::get('/dashboard-v2/api/{path}', [BiometricDashboardController::class, 'proxy'])
                ->where('path', '.*')->name('dashboard-v2.proxy');
        });
    });

    // --------------------------------------------------
    // Overtime module
    // --------------------------------------------------
    Route::prefix('overtime')->name('overtime.')->group(function () {
        Route::get('/my', MyOtRequests::class)->name('my');
        Route::get('/manage', ManageOtRequests::class)->name('manage')->middleware('role:approve-ot');
    });

    // --------------------------------------------------
    // Work From Home module
    // --------------------------------------------------
    Route::prefix('wfh')->name('wfh.')->group(function () {
        Route::get('/my', MyWfhRequests::class)->name('my');
        Route::get('/manage', ManageWfhRequests::class)->name('manage')->middleware('role:approve-wfh');
    });

    // --------------------------------------------------
    // Payroll module
    // --------------------------------------------------
    Route::prefix('payroll')->name('payroll.')->group(function () {
        // All authenticated users may view their own payslips
        Route::get('/my-payslips', MyPayslips::class)->name('payslips');
        Route::get('/payslips/{payslip}/download', [PayslipController::class, 'download'])
            ->name('payslips.download');

        // Payroll administration — finance, HR Admin, Super Admin
        Route::middleware('role:run-payroll')->group(function () {
            Route::get('/overview', Overview::class)->name('overview');
            Route::get('/components', Components::class)->name('components');
            Route::get('/structures', SalaryStructures::class)->name('structures');
            Route::get('/process', Process::class)->name('process');
            Route::get('/incentives', Incentives::class)->name('incentives');
            Route::get('/reimbursements', Reimbursements::class)->name('reimbursements');
        });

        // Finance approval — Finance, Director, Super Admin
        Route::get('/finance-approve', FinanceApproval::class)->name('finance-approve')
            ->middleware('role:approve-finance');
    });

    // --------------------------------------------------
    // Role-specific Dashboards
    // --------------------------------------------------
    Route::get('/dashboard/executive', ExecutiveDashboard::class)->name('dashboard.executive');
    // Director landing — reuses the ExecutiveDashboard component (no new page/component).
    Route::get('/dashboard/director', ExecutiveDashboard::class)->name('dashboard.director');
    Route::get('/dashboard/finance', FinanceDashboard::class)->name('dashboard.finance');
    Route::get('/dashboard/hr-admin', HrAdminDashboard::class)->name('dashboard.hr-admin');
    Route::get('/dashboard/manager', ManagerDashboard::class)->name('dashboard.manager');
    Route::get('/dashboard/department', DepartmentDashboard::class)->name('dashboard.department');

    // --------------------------------------------------
    // Performance module
    // --------------------------------------------------
    Route::prefix('performance')->name('performance.')->group(function () {
        // Unified "My Performance" dashboard (Nexflow-style overview)
        Route::get('/dashboard', PerformanceDashboard::class)->name('dashboard');

        // All authenticated employees can view their own review, goals, and KPIs
        Route::get('/my', MyReview::class)->name('my');
        Route::get('/goals', Goals::class)->name('goals');
        Route::get('/my-kpis', MyKpis::class)->name('my-kpis');
        Route::get('/scorecard/{id}', EmployeeScorecard::class)->name('scorecard');

        // Managers, Directors, HR, Finance can see team reviews
        Route::get('/team', TeamReviews::class)->name('team')->middleware('role:review-performance');

        // HR / admin only — all reviews and cycle management
        Route::middleware('role:manage-employees')->group(function () {
            Route::get('/employees', AllReviews::class)->name('employees');
            Route::get('/cycles', ReviewCycles::class)->name('cycles');
            Route::get('/kpi-dashboard', KpiDashboard::class)->name('kpi-dashboard');
            Route::get('/kpi-templates', KpiTemplates::class)->name('kpi-templates');
            Route::get('/warnings/manage', WarningLetters::class)->name('warnings.manage');
        });

        // Employee warnings (can be accessed by employee)
        Route::get('/my-warnings', MyWarnings::class)->name('my-warnings');

        // Performance Improvement Plans
        Route::prefix('pip')->name('pip.')->group(function () {
            Route::get('/my', MyPip::class)->name('my');
            Route::get('/manage', ManagePips::class)->name('manage')->middleware('role:review-performance');
        });

        // Promotions & Rewards
        Route::prefix('promotions')->name('promotions.')->group(function () {
            Route::get('/my', MyPromotions::class)->name('my');
            Route::get('/manage', ManagePromotions::class)->name('manage')->middleware('role:review-performance');
        });
    });

    // --------------------------------------------------

    // --------------------------------------------------
    // Operations module
    // --------------------------------------------------
    Route::prefix('operations')->name('operations.')->group(function () {
        // All authenticated users can submit and view their own expense claims
        Route::get('/expenses', Expenses::class)->name('expenses');
        // Asset management is HR / admin only
        Route::get('/assets', Assets::class)->name('assets')->middleware('role:manage-employees');
    });

    // --------------------------------------------------
    // Documents module
    // --------------------------------------------------
    Route::get('/documents', DocumentManager::class)->name('documents.index');

    // Upload routes — standard controller POST (not Livewire) for reliable file handling
    Route::post('/documents/upload', [DocumentUploadController::class, 'store'])
        ->name('documents.upload')
        ->middleware('role:manage-documents');
    Route::post('/documents/upload-personal', [DocumentUploadController::class, 'storePersonal'])
        ->name('documents.upload-personal');

    Route::middleware('role:manage-documents')->group(function () {
        Route::get('/documents/experience-letter/{employee}', [DocumentController::class, 'experienceLetter'])->name('documents.experience-letter');
    });
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download')
        ->middleware('signed');
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('documents.view')
        ->middleware('signed');

    // --------------------------------------------------
    // Reports (downloadable exports)
    // --------------------------------------------------
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/payroll-summary.pdf', [ReportController::class, 'payrollSummaryPdf'])
            ->name('payroll-summary')
            ->middleware('role:run-payroll');
        Route::get('/attendance-summary.csv', [ReportController::class, 'attendanceSummaryCsv'])
            ->name('attendance-summary')
            ->middleware('role:approve-leave');
        Route::get('/ot-records.csv', [ReportController::class, 'otRecordsCsv'])
            ->name('ot-records')
            ->middleware('role:approve-ot');
        Route::get('/department-ot.csv', [ReportController::class, 'departmentOtCsv'])
            ->name('department-ot')
            ->middleware('role:approve-ot');
        Route::get('/monthly-ot.csv', [ReportController::class, 'monthlyOtCsv'])
            ->name('monthly-ot')
            ->middleware('role:approve-ot');
        Route::get('/nexflow-sync-log.csv', [ReportController::class, 'nexflowSyncLogCsv'])
            ->name('nexflow-sync-log')
            ->middleware('role:approve-ot');

        // Performance reports — HR admin / performance reviewer access
        Route::get('/performance-summary.csv', [ReportController::class, 'performanceSummaryCsv'])
            ->name('performance-summary')
            ->middleware('role:manage-employees');
        Route::get('/kpi-summary.csv', [ReportController::class, 'kpiSummaryCsv'])
            ->name('kpi-summary')
            ->middleware('role:manage-employees');
        Route::get('/department-performance.csv', [ReportController::class, 'departmentPerformanceCsv'])
            ->name('department-performance')
            ->middleware('role:manage-employees');
        Route::get('/promotion-pipeline.csv', [ReportController::class, 'promotionPipelineCsv'])
            ->name('promotion-pipeline')
            ->middleware('role:manage-employees');
        Route::get('/warning-letter-report.csv', [ReportController::class, 'warningLetterReportCsv'])
            ->name('warning-letter-report')
            ->middleware('role:manage-employees');
        Route::get('/pip-progress.csv', [ReportController::class, 'pipProgressReportCsv'])
            ->name('pip-progress')
            ->middleware('role:manage-employees');
        Route::get('/leave-utilization.csv', [ReportController::class, 'leaveUtilizationCsv'])
            ->name('leave-utilization')
            ->middleware('role:approve-leave');
        Route::get('/leave-encashment-report.csv', [ReportController::class, 'leaveEncashmentReportCsv'])
            ->name('leave-encashment-report')
            ->middleware('role:approve-leave');
        Route::get('/attendance-compliance.csv', [ReportController::class, 'attendanceComplianceCsv'])
            ->name('attendance-compliance')
            ->middleware('role:approve-leave');
        Route::get('/employee-lifecycle.csv', [ReportController::class, 'employeeLifecycleCsv'])
            ->name('employee-lifecycle')
            ->middleware('role:manage-employees');
    });

    // --------------------------------------------------
    // Notifications inbox (full page)
    // --------------------------------------------------
    Route::get('/notifications', NotificationsPage::class)->name('notifications.index');

    // --------------------------------------------------
    // Settings (general — company settings)
    // --------------------------------------------------
    Route::livewire('settings/general', 'pages::settings.general')->name('settings.general');

    // --------------------------------------------------
    // Phase 1A — Employee configuration settings
    // --------------------------------------------------
    Route::middleware('role:manage-settings')->prefix('settings')->name('settings.')->group(function () {
        Route::get('/control-panel', ControlPanel::class)->name('control-panel');
        Route::get('/departments', DepartmentManager::class)->name('departments');
        Route::get('/audit-log', AuditLogViewer::class)->name('audit-log');
        Route::get('/roles', RoleManager::class)->name('roles');
        Route::get('/employment-types', EmploymentTypeManager::class)->name('employment-types');
        Route::get('/work-modes', WorkModeManager::class)->name('work-modes');
        Route::get('/salary-cycles', SalaryCycleManager::class)->name('salary-cycles');
        Route::get('/job-titles', JobTitleManager::class)->name('job-titles');
        Route::get('/menu', MenuSettings::class)->name('menu');
        Route::get('/notifications', NotificationSettings::class)->name('notifications');
        Route::get('/onboarding-templates', OnboardingTemplateManager::class)->name('onboarding-templates');
        Route::get('/onboarding-templates/{template}/tasks', OnboardingTemplateTaskManager::class)->name('onboarding-template-tasks');
    });

});

// ======================================================
// Auth-only (no verified required) routes
// ======================================================
Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
