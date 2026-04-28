<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PayslipController;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceSettings;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Attendance\TeamAttendance;
use App\Livewire\Dashboard;
use App\Livewire\DepartmentDashboard;
use App\Livewire\Documents\DocumentManager;
use App\Livewire\Employees\Directory;
use App\Livewire\Employees\EmployeeCreate;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Employees\OrgChart;
use App\Livewire\ExecutiveDashboard;
use App\Livewire\FinanceDashboard;
use App\Livewire\HrAdminDashboard;
use App\Livewire\ManagerDashboard;
use App\Livewire\Onboarding\OffboardingManager;
use App\Livewire\Onboarding\OnboardingChecklist;
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
use App\Livewire\Performance\AllReviews;
use App\Livewire\Performance\Goals;
use App\Livewire\Performance\MyReview;
use App\Livewire\Performance\ReviewCycles;
use App\Livewire\Performance\TeamReviews;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\MyTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Livewire\TimeOff\TimeOffSettings;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

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

    // --------------------------------------------------
    // Employees module
    // --------------------------------------------------
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', EmployeeIndex::class)->name('index');
        Route::get('/create', EmployeeCreate::class)->name('create');
        Route::get('/directory', Directory::class)->name('directory');
        Route::get('/org-chart', OrgChart::class)->name('org-chart');
        Route::get('/{employee}/edit', EmployeeEdit::class)->name('edit');
        // Onboarding / offboarding checklists (per employee)
        Route::get('/{employee}/onboarding', OnboardingChecklist::class)->name('onboarding');
        Route::get('/{employee}/offboarding', OnboardingChecklist::class)->name('offboarding');
        Route::get('/offboarding-manager', OffboardingManager::class)->name('offboarding-manager');
    });

    // --------------------------------------------------
    // Time Off module
    // --------------------------------------------------
    Route::prefix('time-off')->name('time-off.')->group(function () {
        Route::get('/my', MyTimeOff::class)->name('my');
        Route::get('/team', TeamTimeOff::class)->name('team');
        Route::get('/employees', AllTimeOff::class)->name('employees');
        Route::get('/settings', TimeOffSettings::class)->name('settings');
    });

    // --------------------------------------------------
    // Attendance module
    // --------------------------------------------------
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/my', AttendanceTracker::class)->name('my');
        Route::get('/team', TeamAttendance::class)->name('team');
        Route::get('/employees', AllAttendance::class)->name('employees');
        Route::get('/settings', AttendanceSettings::class)->name('settings');
    });

    // --------------------------------------------------
    // Overtime module (NEW)
    // --------------------------------------------------
    Route::prefix('overtime')->name('overtime.')->group(function () {
        Route::get('/my', MyOtRequests::class)->name('my');
        Route::get('/manage', ManageOtRequests::class)->name('manage');
    });

    // --------------------------------------------------
    // Payroll module
    // --------------------------------------------------
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/overview', Overview::class)->name('overview');
        Route::get('/components', Components::class)->name('components');
        Route::get('/process', Process::class)->name('process');
        Route::get('/finance-approve', FinanceApproval::class)->name('finance-approve');
        Route::get('/my-payslips', MyPayslips::class)->name('payslips');
        Route::get('/incentives', Incentives::class)->name('incentives');
        Route::get('/reimbursements', Reimbursements::class)->name('reimbursements');
        Route::get('/payslips/{payslip}/download', [PayslipController::class, 'download'])
            ->name('payslips.download')
            ->middleware('signed');
    });

    // --------------------------------------------------
    // Role-specific Dashboards
    // --------------------------------------------------
    Route::get('/dashboard/executive', ExecutiveDashboard::class)->name('dashboard.executive');
    Route::get('/dashboard/finance', FinanceDashboard::class)->name('dashboard.finance');
    Route::get('/dashboard/hr-admin', HrAdminDashboard::class)->name('dashboard.hr-admin');
    Route::get('/dashboard/manager', ManagerDashboard::class)->name('dashboard.manager');
    Route::get('/dashboard/department', DepartmentDashboard::class)->name('dashboard.department');

    // --------------------------------------------------
    // Performance module
    // --------------------------------------------------
    Route::prefix('performance')->name('performance.')->group(function () {
        Route::get('/my', MyReview::class)->name('my');
        Route::get('/team', TeamReviews::class)->name('team');
        Route::get('/employees', AllReviews::class)->name('employees');
        Route::get('/cycles', ReviewCycles::class)->name('cycles');
        Route::get('/goals', Goals::class)->name('goals');
    });

    // --------------------------------------------------

    // --------------------------------------------------
    // Operations module
    // --------------------------------------------------
    Route::prefix('operations')->name('operations.')->group(function () {
        Route::get('/assets', Assets::class)->name('assets');
        Route::get('/expenses', Expenses::class)->name('expenses');
    });

    // --------------------------------------------------
    // Documents module (NEW)
    // --------------------------------------------------
    Route::get('/documents', DocumentManager::class)->name('documents.index');
    Route::get('/documents/experience-letter/{employee}', [DocumentController::class, 'experienceLetter'])->name('documents.experience-letter');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download')
        ->middleware('signed');

    // --------------------------------------------------
    // Settings (general — company settings)
    // --------------------------------------------------
    Route::livewire('settings/general', 'pages::settings.general')->name('settings.general');

});

// ======================================================
// Auth-only (no verified required) routes
// ======================================================
Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
