<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DepartmentDashboard extends Component
{
    public $department;

    public $totalEmployees = 0;

    public $presentToday = 0;

    public $lateToday = 0;

    public $absentToday = 0;

    public function mount()
    {
        $user = Auth::user();
        // Get the department where the user is the head
        $this->department = Department::where('head_id', $user->id)->first();

        if ($this->department) {
            $this->loadStats();
        }
    }

    public function loadStats()
    {
        $employeeIds = Employee::where('department_id', $this->department->id)->pluck('id');
        $this->totalEmployees = count($employeeIds);

        $today = Carbon::today();

        $attendances = Attendance::whereIn('employee_id', $employeeIds)
            ->where('date', $today)
            ->get();

        $this->presentToday = $attendances->count();
        $this->lateToday = $attendances->where('is_late', true)->count();
        $this->absentToday = $this->totalEmployees - $this->presentToday;
    }

    public function render()
    {
        return view('livewire.department-dashboard')
            ->layout('layouts.app', ['title' => 'Department Dashboard']);
    }
}
