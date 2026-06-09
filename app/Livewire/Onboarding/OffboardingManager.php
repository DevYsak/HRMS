<?php

namespace App\Livewire\Onboarding;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\OnboardingTask;
use App\Notifications\OffboardingDetailsNotification;
use App\Services\AssetAssignmentService;
use App\Services\OnboardingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class OffboardingManager extends Component
{
    public $search = '';

    public $selectedEmployeeId = null;

    // Exit Record Form
    public $lastWorkingDay;

    public $exitType = 'resignation';

    public $exitReason = '';

    public $noticePeriodServed = true;

    public $interviewNotes = '';

    public $finalSettlementAmount = 0;

    public $finalSettlementDone = false;

    // Assets
    public $assetConditions = [];

    public $activeTab = 'offboarding'; // offboarding | analytics

    public function selectEmployee($id)
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->selectedEmployeeId = $id;
        $employee = Employee::with('exitRecord', 'assets')->findOrFail($id);

        if ($employee->exitRecord) {
            $this->lastWorkingDay = $employee->exitRecord->last_working_day->format('Y-m-d');
            $this->exitType = $employee->exitRecord->exit_type;
            $this->exitReason = $employee->exitRecord->exit_reason;
            $this->noticePeriodServed = $employee->exitRecord->notice_period_served;
            $this->interviewNotes = $employee->exitRecord->interview_notes;
            $this->finalSettlementAmount = $employee->exitRecord->final_settlement_amount ?? 0;
            $this->finalSettlementDone = $employee->exitRecord->final_settlement_done;
        } else {
            $this->lastWorkingDay = now()->addDays(30)->format('Y-m-d');
            $this->exitType = 'resignation';
            $this->exitReason = '';
            $this->noticePeriodServed = true;
            $this->interviewNotes = '';
            $this->finalSettlementAmount = 0;
            $this->finalSettlementDone = false;
        }

        $this->assetConditions = [];
        foreach ($employee->assets as $asset) {
            if ($asset->status->value === 'assigned') {
                $this->assetConditions[$asset->id] = '';
            }
        }
    }

    public function processOffboarding(OnboardingService $onboardingService)
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'lastWorkingDay' => 'required|date',
            'exitType' => 'required|string',
            'exitReason' => 'nullable|string',
            'interviewNotes' => 'nullable|string',
            'finalSettlementAmount' => 'nullable|numeric|min:0',
        ]);

        $employee = Employee::findOrFail($this->selectedEmployeeId);

        $exitRecord = $employee->exitRecord ?? new ExitRecord(['employee_id' => $employee->id]);

        $exitRecord->last_working_day = $this->lastWorkingDay;
        $exitRecord->exit_type = $this->exitType;
        $exitRecord->exit_reason = $this->exitReason;
        $exitRecord->notice_period_served = $this->noticePeriodServed;
        $exitRecord->interview_notes = $this->interviewNotes;
        $exitRecord->final_settlement_amount = $this->finalSettlementAmount;
        $exitRecord->final_settlement_done = $this->finalSettlementDone;

        $exitRecord->processed_by = Auth::id();
        $exitRecord->processed_at = now();
        $exitRecord->save();

        // Check if last working day has passed
        if (now()->startOfDay()->greaterThan(Carbon::parse($this->lastWorkingDay)->endOfDay())) {
            $employee->update(['status' => 'inactive']);
        }

        // Assign Offboarding tasks
        $onboardingService->assignOffboardingTasks($employee, $this->lastWorkingDay);

        // Notify the employee about their offboarding details
        $employee->user->notify(new OffboardingDetailsNotification($exitRecord));

        \Flux::toast('Offboarding processed successfully.');
    }

    public function returnAsset($assetId, AssetAssignmentService $assetService)
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $asset = Asset::findOrFail($assetId);
        $condition = $this->assetConditions[$assetId] ?? 'Good';
        $assetService->returnAsset($asset, Auth::id(), $condition);

        \Flux::toast('Asset returned successfully.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $employees = Employee::with('user', 'department')
            ->whereHas('user', function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%');
            })
            ->where('status', '!=', 'inactive')
            ->get();

        $selectedEmployee = $this->selectedEmployeeId ? Employee::with('exitRecord', 'assets')->find($this->selectedEmployeeId) : null;

        $taskStats = null;
        if ($selectedEmployee) {
            $stats = OnboardingTask::where('employee_id', $selectedEmployee->id)
                ->where('phase', 'offboarding')
                ->selectRaw('count(*) as total, sum(is_completed) as completed')
                ->first();
            $taskStats = [
                'total' => $stats->total ?? 0,
                'completed' => $stats->completed ?? 0,
            ];
        }

        $analytics = null;
        $ownerBreakdown = null;
        $departmentBreakdown = null;

        if ($this->activeTab === 'analytics') {
            $analytics = OnboardingTask::where('phase', 'offboarding')
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(is_completed) as completed,
                    SUM(CASE WHEN is_completed = 0 AND (due_date IS NULL OR due_date >= CURDATE()) AND status != "blocked" THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN is_completed = 0 AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN status = "blocked" THEN 1 ELSE 0 END) as blocked,
                    SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as in_progress
                ')
                ->first();

            $ownerBreakdown = OnboardingTask::where('phase', 'offboarding')
                ->selectRaw('owner_role, COUNT(*) as total, SUM(is_completed) as completed')
                ->groupBy('owner_role')
                ->orderBy('owner_role')
                ->get();

            $departmentBreakdown = OnboardingTask::join('employees', 'onboarding_tasks.employee_id', '=', 'employees.id')
                ->join('departments', 'employees.department_id', '=', 'departments.id')
                ->where('onboarding_tasks.phase', 'offboarding')
                ->selectRaw('departments.name as department_name, COUNT(onboarding_tasks.id) as total, SUM(onboarding_tasks.is_completed) as completed')
                ->groupBy('departments.name')
                ->orderBy('departments.name')
                ->get();
        }

        return view('livewire.onboarding.offboarding-manager', [
            'employees' => $employees,
            'selectedEmployee' => $selectedEmployee,
            'taskStats' => $taskStats,
            'analytics' => $analytics,
            'ownerBreakdown' => $ownerBreakdown,
            'departmentBreakdown' => $departmentBreakdown,
        ])->layout('layouts.app', ['title' => 'Offboarding Management']);
    }
}
