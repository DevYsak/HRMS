<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AttendanceTracker extends Component
{
    public $todayAttendance;

    public $attendanceSettings;

    public $shift;

    public $stats = [
        'present' => 0,
        'late' => 0,
        'hours' => '0h 0m',
        'leaves' => 0,
    ];

    public $shiftLabel;

    public $calendarMonth;

    public $calendarDays = [];

    public $monthHolidays = [];

    public $history = [];

    // Regularisation form fields
    public string $regDate = '';

    public string $regCheckIn = '';

    public string $regCheckOut = '';

    public string $regReason = '';

    public function mount()
    {
        $this->calendarMonth = Carbon::now()->startOfMonth();
        $this->attendanceSettings = AttendanceSetting::first();
        $this->loadData();
    }

    public function loadData()
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return;
        }

        $this->shift = $employee->shift ?? ShiftSetting::first();
        $this->shiftLabel = $this->buildShiftLabel();

        // 1. Setup boundaries
        $start = $this->calendarMonth->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $gridStart = $start->copy()->startOfWeek();
        $gridEnd = $end->copy()->endOfWeek();

        // 2. Fetch all required data in consolidated queries
        $allAttendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get();

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $gridEnd->toDateString())
            ->where('end_date', '>=', $gridStart->toDateString())
            ->get();

        $holidays = PublicHoliday::whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get();

        // 3. Process data in memory
        $attendanceMap = $allAttendances->keyBy(fn ($a) => $a->date->toDateString());
        $holidayMap = $holidays->keyBy(fn ($h) => Carbon::parse($h->date)->toDateString());

        $leaveMap = [];
        foreach ($leaves as $l) {
            $period = CarbonPeriod::create($l->start_date, $l->end_date);
            foreach ($period as $d) {
                $leaveMap[$d->toDateString()] = $l;
            }
        }

        // 4. Assign Today's Attendance
        $this->todayAttendance = $attendanceMap->get(Carbon::today()->toDateString());

        // 5. Calculate Stats (Current month only)
        $monthItems = $allAttendances->filter(fn ($a) => $a->date->month === $start->month && $a->date->year === $start->year);
        $totalMinutes = $monthItems->sum(function ($a) {
            if ($a->check_in && $a->check_out) {
                return $a->check_in->diffInMinutes($a->check_out) - ($a->break_minutes ?? 0);
            }

            return 0;
        });

        $this->stats = [
            'present' => $monthItems->where('status', 'on_time')->count() + $monthItems->where('status', 'late')->count(),
            'late' => $monthItems->where('is_late', true)->count(),
            'hours' => floor($totalMinutes / 60).'h '.($totalMinutes % 60).'m',
            'leaves' => $leaves->filter(fn ($l) => ($l->start_date->month === $start->month && $l->start_date->year === $start->year) ||
                ($l->end_date->month === $start->month && $l->end_date->year === $start->year)
            )->count(),
        ];

        // 6. Build Calendar Days
        $gridPeriod = CarbonPeriod::create($gridStart, $gridEnd);
        $this->calendarDays = [];

        foreach ($gridPeriod as $d) {
            $dateKey = $d->toDateString();
            $status = 'absent';

            if (isset($attendanceMap[$dateKey])) {
                $att = $attendanceMap[$dateKey];
                $status = ($att->status === 'late' || $att->is_late) ? 'late' : 'present';
            } elseif (isset($leaveMap[$dateKey])) {
                $status = 'leave';
            } elseif (isset($holidayMap[$dateKey])) {
                $status = 'holiday';
            } elseif ($d->isWeekend()) {
                $status = 'weekend';
            } elseif ($d->isFuture()) {
                $status = 'future';
            }

            $this->calendarDays[] = [
                'date' => $dateKey,
                'day' => $d->day,
                'in_month' => $d->month === $start->month,
                'status' => $status,
                'is_today' => $d->isToday(),
                'is_holiday' => isset($holidayMap[$dateKey]),
            ];
        }

        // 7. Load UI specific lists
        $this->monthHolidays = $holidays->filter(fn ($h) => Carbon::parse($h->date)->month === $start->month &&
            Carbon::parse($h->date)->year === $start->year
        )->values();

        $this->history = $allAttendances->filter(fn ($a) => $a->date->month === $start->month && $a->date->year === $start->year
        )->sortByDesc('date')->values();
    }

    public function previousMonth()
    {
        $this->calendarMonth->subMonth();
        $this->loadData();
    }

    public function nextMonth()
    {
        $this->calendarMonth->addMonth();
        $this->loadData();
    }

    public function startBreak()
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out || $this->todayAttendance->break_start) {
            return;
        }

        $this->todayAttendance->update(['break_start' => Carbon::now()]);
        $this->loadData();
        $this->dispatch('toast', message: 'Break started', variant: 'success');
    }

    public function endBreak()
    {
        if (! $this->todayAttendance || ! $this->todayAttendance->break_start) {
            return;
        }

        $now = Carbon::now();
        $breakMins = $this->todayAttendance->break_start->diffInMinutes($now);
        $totalBreak = ($this->todayAttendance->break_minutes ?? 0) + $breakMins;

        $this->todayAttendance->update([
            'break_end' => $now,
            'break_start' => null,
            'break_minutes' => $totalBreak,
        ]);

        $this->loadData();
        $this->dispatch('toast', message: 'Break ended', variant: 'success');
    }

    public function checkIn($lat = null, $lng = null)
    {
        if ($this->todayAttendance) {
            return;
        }

        $employee = Auth::user()->employee;
        $now = Carbon::now();
        $shiftStart = Carbon::createFromTimeString($this->shift->start_time ?? '09:00:00');

        $isLate = false;
        $lateMinutes = 0;
        $grace = $this->attendanceSettings->late_grace_period ?? 15;

        if ($now->greaterThan($shiftStart->copy()->addMinutes($grace))) {
            $isLate = true;
            $lateMinutes = $shiftStart->diffInMinutes($now);
        }

        $this->todayAttendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => Carbon::today(),
            'check_in' => $now,
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
            'status' => $isLate ? 'late' : 'on_time',
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
        ]);

        $this->loadData();
        $this->dispatch('toast', message: 'Clocked in successfully', variant: 'success');
    }

    public function checkOut($lat = null, $lng = null)
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out) {
            return;
        }

        $now = Carbon::now();
        $totalHours = $this->todayAttendance->check_in->diffInMinutes($now) / 60;

        $this->todayAttendance->update([
            'check_out' => $now,
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'total_hours' => round($totalHours, 2),
        ]);

        $this->loadData();
        $this->dispatch('toast', message: 'Clocked out successfully', variant: 'success');
    }

    public function openRegularisation($date)
    {
        $this->regDate = $date;
        $attendance = Attendance::where('employee_id', Auth::user()->employee->id)->where('date', $date)->first();
        if ($attendance) {
            $this->regCheckIn = $attendance->check_in?->format('H:i') ?? '';
            $this->regCheckOut = $attendance->check_out?->format('H:i') ?? '';
        }
        $this->dispatch('flux:modal:open', name: 'regularisation-modal');
    }

    public function submitRegularisation()
    {
        $this->validate([
            'regDate' => 'required|date',
            'regCheckIn' => 'required',
            'regCheckOut' => 'required',
            'regReason' => 'required|min:5',
        ]);

        $employee = Auth::user()->employee;
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->regDate)
            ->first();

        AttendanceRegularisation::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance?->id,
            'work_date' => $this->regDate,
            'requested_check_in' => $this->regDate.' '.$this->regCheckIn.':00',
            'requested_check_out' => $this->regDate.' '.$this->regCheckOut.':00',
            'reason' => $this->regReason,
            'status' => 'pending',
        ]);

        $this->reset(['regDate', 'regCheckIn', 'regCheckOut', 'regReason']);
        $this->dispatch('flux:modal:close', name: 'regularisation-modal');
        $this->dispatch('toast', message: 'Regularisation request submitted', variant: 'success');
    }

    protected function buildShiftLabel(): ?string
    {
        $employee = Auth::user()->employee;
        $shiftSetting = $employee->shift ?? null;

        if ($shiftSetting) {
            $name = $shiftSetting->name;
            $start = Carbon::parse($shiftSetting->start_time)->format('g:i A');
            $end = Carbon::parse($shiftSetting->end_time)->format('g:i A');

            return sprintf('%s: %s – %s IST | Grace: 5 mins', $name, $start, $end);
        }

        // Fallback to code mapping if relationship is missing
        $shiftCode = $employee->getAttribute('shift') ?? null;
        if ($shiftCode) {
            $code = strtolower((string) $shiftCode);
            if ($code === 'it') {
                return 'IT Shift: 10:30 AM – 7:30 PM IST | Grace: 5 mins';
            }
            if ($code === 'uk') {
                return 'UK Sales Shift: 1:00 PM – 10:00 PM IST | Grace: 5 mins';
            }
        }

        return 'Shift: 10:30 AM – 7:30 PM IST | Grace: 5 mins';
    }

    public function render()
    {
        return view('attendance.my')
            ->layout('layouts.app', ['title' => 'My Attendance']);
    }
}
