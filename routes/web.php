<?php

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
    Route::get('/', \App\Livewire\Dashboard::class)->name('dashboard');

    // --------------------------------------------------
    // Employees module
    // --------------------------------------------------
    Route::prefix('employees')->name('employees.')->group(function () {
        Route::get('/', \App\Livewire\Employees\EmployeeIndex::class)->name('index');
        Route::get('/create', \App\Livewire\Employees\EmployeeCreate::class)->name('create');
        Route::get('/directory', \App\Livewire\Employees\Directory::class)->name('directory');
        Route::get('/org-chart', \App\Livewire\Employees\OrgChart::class)->name('org-chart');
        Route::get('/{employee}/edit', \App\Livewire\Employees\EmployeeEdit::class)->name('edit');
        // Onboarding / offboarding checklists (per employee)
        Route::get('/{employee}/onboarding', \App\Livewire\Onboarding\OnboardingChecklist::class)->name('onboarding');
        Route::get('/{employee}/offboarding', \App\Livewire\Onboarding\OnboardingChecklist::class)->name('offboarding');
        Route::get('/offboarding-manager', \App\Livewire\Onboarding\OffboardingManager::class)->name('offboarding-manager');
    });

    // --------------------------------------------------
    // Time Off module
    // --------------------------------------------------
    Route::prefix('time-off')->name('time-off.')->group(function () {
        Route::get('/my', \App\Livewire\TimeOff\MyTimeOff::class)->name('my');
        Route::get('/team', \App\Livewire\TimeOff\TeamTimeOff::class)->name('team');
        Route::get('/employees', \App\Livewire\TimeOff\AllTimeOff::class)->name('employees');
        Route::get('/settings', \App\Livewire\TimeOff\TimeOffSettings::class)->name('settings');
    });

    // --------------------------------------------------
    // Attendance module
    // --------------------------------------------------
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/my', \App\Livewire\Attendance\MyAttendance::class)->name('my');
        Route::get('/team', \App\Livewire\Attendance\TeamAttendance::class)->name('team');
        Route::get('/employees', \App\Livewire\Attendance\AllAttendance::class)->name('employees');
        Route::get('/settings', \App\Livewire\Attendance\AttendanceSettings::class)->name('settings');
    });

    // --------------------------------------------------
    // Overtime module (NEW)
    // --------------------------------------------------
    Route::prefix('overtime')->name('overtime.')->group(function () {
        Route::get('/my', \App\Livewire\Overtime\MyOtRequests::class)->name('my');
        Route::get('/manage', \App\Livewire\Overtime\ManageOtRequests::class)->name('manage');
    });

    // --------------------------------------------------
    // Payroll module
    // --------------------------------------------------
    Route::prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/overview', \App\Livewire\Payroll\Overview::class)->name('overview');
        Route::get('/components', \App\Livewire\Payroll\Components::class)->name('components');
        Route::get('/process', \App\Livewire\Payroll\Process::class)->name('process');
        Route::get('/finance-approve', \App\Livewire\Payroll\FinanceApproval::class)->name('finance-approve');
        Route::get('/my-payslips', \App\Livewire\Payroll\MyPayslips::class)->name('payslips');
        Route::get('/incentives', \App\Livewire\Payroll\Incentives::class)->name('incentives');
        Route::get('/reimbursements', \App\Livewire\Payroll\Reimbursements::class)->name('reimbursements');
        Route::get('/payslips/{payslip}/download', [\App\Http\Controllers\PayslipController::class, 'download'])
            ->name('payslips.download')
            ->middleware('signed');
    });

    // --------------------------------------------------
    // Role-specific Dashboards
    // --------------------------------------------------
    Route::get('/dashboard/executive', \App\Livewire\ExecutiveDashboard::class)->name('dashboard.executive');
    Route::get('/dashboard/finance', \App\Livewire\FinanceDashboard::class)->name('dashboard.finance');
    Route::get('/dashboard/hr-admin', \App\Livewire\HrAdminDashboard::class)->name('dashboard.hr-admin');
    Route::get('/dashboard/manager', \App\Livewire\ManagerDashboard::class)->name('dashboard.manager');
    Route::get('/dashboard/department', \App\Livewire\DepartmentDashboard::class)->name('dashboard.department');

    // --------------------------------------------------
    // Performance module
    // --------------------------------------------------
    Route::prefix('performance')->name('performance.')->group(function () {
        Route::get('/my', \App\Livewire\Performance\MyReview::class)->name('my');
        Route::get('/team', \App\Livewire\Performance\TeamReviews::class)->name('team');
        Route::get('/employees', \App\Livewire\Performance\AllReviews::class)->name('employees');
        Route::get('/cycles', \App\Livewire\Performance\ReviewCycles::class)->name('cycles');
        Route::get('/goals', \App\Livewire\Performance\Goals::class)->name('goals');
    });

    // --------------------------------------------------

    // --------------------------------------------------
    // Operations module
    // --------------------------------------------------
    Route::prefix('operations')->name('operations.')->group(function () {
        Route::get('/assets', \App\Livewire\Operations\Assets::class)->name('assets');
        Route::get('/expenses', \App\Livewire\Operations\Expenses::class)->name('expenses');
    });

    // --------------------------------------------------
    // Documents module (NEW)
    // --------------------------------------------------
    Route::get('/documents', \App\Livewire\Documents\DocumentManager::class)->name('documents.index');
    Route::get('/documents/experience-letter/{employee}', [\App\Http\Controllers\DocumentController::class, 'experienceLetter'])->name('documents.experience-letter');
    Route::get('/documents/{document}/download', [\App\Http\Controllers\DocumentController::class, 'download'])
        ->name('documents.download')
        ->middleware('signed');

    // --------------------------------------------------
    // Settings (general — company settings)
    // --------------------------------------------------
    Route::view('settings/general', 'pages.settings.general')->name('settings.general');

});

// ======================================================
// Auth-only (no verified required) routes
// ======================================================
Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
