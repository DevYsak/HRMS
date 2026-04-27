<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\DecemberMandatoryDay;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyAttendance extends Component
{
    public $todayAttendance = null;

    public $settings = null;

    public $history = [];

    // Employee shift (ShiftSetting) if assigned
    public $employeeShift = null;

    // Human-friendly shift label for display (e.g. "IT Shift: 10:30 AM – 7:30 PM IST | Grace: 5 mins")
    public $shiftLabel = null;

    public $stats = [
        'present' => 0,
        'late' => 0,
        'hours' => 0,
    ];

    // Calendar state
    public $calendarMonth = null; // Carbon start of month

    public $calendarDays = [];

    public $selectedDay = null;

    public $selectedDayDetails = [];

    // Holidays/MDLs visible for the current month
    public $monthHolidays = [];

    public $monthMdls = [];

    // Regularisation form fields
    public string $regDate = '';

    public string $regCheckIn = '';

    public string $regCheckOut = '';

    public string $regReason = '';

    public function mount()
    {
        $this->settings = AttendanceSetting::first();
        $this->calendarMonth = Carbon::now()->startOfMonth();
        $this->employeeShift = Auth::user()->employee->shift ?? null;
        $this->shiftLabel = $this->buildShiftLabel();
        $this->loadToday();
        $this->loadHistory();
        $this->loadCalendar();
    }

    public function loadToday()
    {
        $this->todayAttendance = Attendance::where('employee_id', Auth::user()->employee->id)
            ->where('date', Carbon::today())
            ->first();
    }

    public function loadHistory($month = null, $year = null): void
    {
        $employee = Auth::user()->employee;

        $month = $month ?? ($this->calendarMonth?->month ?? Carbon::now()->month);
        $year = $year ?? ($this->calendarMonth?->year ?? Carbon::now()->year);

        $this->history = Attendance::where('employee_id', $employee->id)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->latest()
            ->get();

        $this->stats = [
            'present' => $this->history->where('status', '!=', 'absent')->count(),
            'late' => $this->history->where('status', 'late')->count(),
            'hours' => $this->history->sum('total_hours'),
        ];
    }

    /**
     * Build calendar grid for the current `calendarMonth`.
     */
    public function loadCalendar(): void
    {
        $employee = Auth::user()->employee;
        $start = Carbon::parse($this->calendarMonth)->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $gridStart = $start->copy()->startOfWeek();
        $gridEnd = $end->copy()->endOfWeek();

        // Preload attendances, leaves and holidays for the grid range
        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        $leaves = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_date', '<=', $gridEnd->toDateString())
            ->where('end_date', '>=', $gridStart->toDateString())
            ->get();

        $holidays = PublicHoliday::select('date', 'name')
            ->whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->distinct()
            ->orderBy('date')
            ->get()
            ->keyBy(fn ($h) => $h->date->toDateString());

        $mdls = DecemberMandatoryDay::whereBetween('date', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->get()
            ->keyBy(fn ($m) => $m->date->toDateString());

        // Expose month-level lists for the mini-list view (distinct date,name)
        $this->monthHolidays = PublicHoliday::select('date', 'name')
            ->whereMonth('date', $start->month)
            ->whereYear('date', $start->year)
            ->distinct()
            ->orderBy('date')
            ->get();
        $this->monthMdls = DecemberMandatoryDay::whereMonth('date', $start->month)->whereYear('date', $start->year)->get();

        // Build quick lookup for leaves by date
        $leaveMap = [];
        foreach ($leaves as $l) {
            $period = CarbonPeriod::create($l->start_date, $l->end_date);
            foreach ($period as $d) {
                $leaveMap[$d->toDateString()] = $l;
            }
        }

        $period = CarbonPeriod::create($gridStart, $gridEnd);
        $this->calendarDays = [];

        foreach ($period as $d) {
            $dateKey = $d->toDateString();

            if (isset($attendances[$dateKey])) {
                $att = $attendances[$dateKey];
                $status = $att->status ?? ($att->is_late ? 'late' : 'on_time');
            } elseif (isset($leaveMap[$dateKey])) {
                $status = 'leave';
            } elseif (isset($mdls[$dateKey])) {
                $status = 'mdl';
            } elseif (isset($holidays[$dateKey])) {
                $status = 'holiday';
            } elseif ($d->isWeekend()) {
                $status = 'weekend';
            } elseif ($d->lessThanOrEqualTo(Carbon::today())) {
                $status = 'absent';
            } else {
                $status = 'future';
            }

            $this->calendarDays[] = [
                'date' => $dateKey,
                'carbon' => $d->copy(),
                'in_month' => $d->month === $start->month,
                'status' => $status,
                'attendance' => $attendances[$dateKey] ?? null,
                'holiday' => $holidays[$dateKey] ?? null,
                'mdl' => $mdls[$dateKey] ?? null,
            ];
        }
    }

    public function previousMonth(): void
    {
        $this->calendarMonth = Carbon::parse($this->calendarMonth)->subMonth()->startOfMonth();
        $this->loadCalendar();
        $this->loadHistory();
    }

    public function nextMonth(): void
    {
        $this->calendarMonth = Carbon::parse($this->calendarMonth)->addMonth()->startOfMonth();
        $this->loadCalendar();
        $this->loadHistory();
    }

    public function showDay($date): void
    {
        $this->selectedDay = $date;
        $employee = Auth::user()->employee;
        $attendance = Attendance::where('employee_id', $employee->id)->where('date', $date)->first();

        if ($attendance) {
            $formattedHours = '--';
            if ($attendance->check_in && $attendance->check_out) {
                $formattedHours = $this->formatDuration($attendance->check_in, $attendance->check_out);
            } elseif ($attendance->check_in) {
                $formattedHours = $attendance->check_in->format('H:i');
            }

            $this->selectedDayDetails = [
                'check_in' => $attendance->check_in?->format('H:i') ?? '--:--',
                'check_out' => $attendance->check_out?->format('H:i') ?? '--:--',
                'break_minutes' => $attendance->break_minutes ?? 0,
                'total_hours' => $formattedHours,
                'status' => strtoupper($attendance->status ?? 'ABSENT'),
            ];
        } else {
            $isMdl = DecemberMandatoryDay::isMandatory(Carbon::parse($date));
            $isHoliday = PublicHoliday::isHoliday(Carbon::parse($date));
            $leave = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->first();

            $statusLabel = $leave ? 'LEAVE' : ($isMdl ? 'MDL' : ($isHoliday ? 'HOLIDAY' : 'ABSENT'));

            $this->selectedDayDetails = [
                'check_in' => '--:--',
                'check_out' => '--:--',
                'break_minutes' => 0,
                'total_hours' => 0,
                'status' => $statusLabel,
            ];
        }

        $this->dispatch('flux:modal:open', name: 'day-details');
    }

    public function openRegularisation($date): void
    {
        $this->regDate = $date;
        $attendance = Attendance::where('employee_id', Auth::user()->employee->id)->where('date', $date)->first();
        if ($attendance) {
            $this->regCheckIn = $attendance->check_in?->format('H:i') ?? '';
            $this->regCheckOut = $attendance->check_out?->format('H:i') ?? '';
        }

        $this->dispatch('flux:modal:open', name: 'regularisation-modal');
    }

    public function checkIn($lat, $lng)
    {
        if ($this->todayAttendance) {
            \Flux::toast('You are already clocked in for today.', variant: 'warning');

            return;
        }

        $employee = Auth::user()->employee;
        $office = $employee->office;

        $isVerified = true;
        $status = 'on_time';

        // Geo-fencing check (Optional based on PRD)
        if ($office && $office->latitude && $office->longitude) {
            $distance = $this->calculateDistance($lat, $lng, $office->latitude, $office->longitude);
            if ($distance > $office->radius) {
                $isVerified = false;
                $status = 'remote';
            }
        }

        $now = Carbon::now();
        $shiftStart = Carbon::createFromTimeString($this->settings->shift_start);

        // Late check
        $isLate = false;
        $lateMinutes = 0;
        if ($now->greaterThan($shiftStart->addMinutes($this->settings->late_grace_period))) {
            $isLate = true;
            $lateMinutes = $shiftStart->diffInMinutes($now);
            if ($status !== 'remote') {
                $status = 'late';
            }
        }

        Attendance::create([
            'employee_id' => $employee->id,
            'date' => Carbon::today(),
            'check_in' => $now,
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
            'status' => $status,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'is_verified' => $isVerified,
        ]);

        $this->loadToday();
        $this->loadHistory();
        $this->loadCalendar();
        \Flux::toast('Clocked in successfully at '.$now->format('H:i'));
    }

    public function checkOut($lat, $lng)
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out) {
            return;
        }

        $now = Carbon::now();

        // Calculate total hours as clock_out - clock_in (per spec: break NOT deducted)
        $grossMinutes = $this->todayAttendance->check_in->diffInMinutes($now);
        $totalHours = $grossMinutes / 60;

        $this->todayAttendance->update([
            'check_out' => $now,
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'total_hours' => round($totalHours, 2),
        ]);

        // Comp Off auto-credit logic
        if ($totalHours >= 4.0) { // Assuming 4 hours minimum for a comp-off
            $today = $now->toDateString();
            $isHoliday = PublicHoliday::where('date', $today)->exists();
            $isMdl = DecemberMandatoryDay::where('date', $today)->exists();

            if ($isHoliday || $isMdl) {
                // Find or create 'Comp Off' leave type
                $leaveType = LeaveType::firstOrCreate(
                    ['name' => 'Comp Off'],
                    ['is_paid' => true, 'description' => 'Compensatory Off']
                );

                // Add 1 day to the balance
                $balance = LeaveBalance::firstOrCreate(
                    [
                        'employee_id' => $this->todayAttendance->employee_id,
                        'leave_type_id' => $leaveType->id,
                        'year' => $now->year,
                    ],
                    ['allocated_days' => 0, 'used_days' => 0]
                );

                $balance->increment('allocated_days', 1);
                \Flux::toast('You worked on a holiday/MDL. 1 day of Comp Off has been credited!');
            }
        }

        $this->loadToday();
        $this->loadHistory();
        $this->loadCalendar();
        $formatted = $this->formatDuration($this->todayAttendance->check_in, $now);
        \Flux::toast('Clocked out successfully. Net hours: '.$formatted);
    }

    /**
     * Start a break — records break_start timestamp.
     */
    public function startBreak()
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out) {
            return;
        }

        if ($this->todayAttendance->break_start) {
            \Flux::toast('A break is already in progress.', variant: 'warning');

            return;
        }

        $this->todayAttendance->update(['break_start' => Carbon::now()]);
        $this->loadToday();
        $this->loadCalendar();
        \Flux::toast('Break started at '.Carbon::now()->format('H:i'));
    }

    /**
     * End a break — records break_end and accumulates break_minutes.
     */
    public function endBreak()
    {
        if (! $this->todayAttendance || ! $this->todayAttendance->break_start) {
            return;
        }

        $now = Carbon::now();
        $breakMins = (int) $this->todayAttendance->break_start->diffInMinutes($now);
        $totalBreak = ($this->todayAttendance->break_minutes ?? 0) + $breakMins;

        $this->todayAttendance->update([
            'break_end' => $now,
            'break_start' => null, // reset so another break can be started
            'break_minutes' => $totalBreak,
        ]);

        $this->loadToday();
        $this->loadCalendar();
        \Flux::toast("Break ended. Duration: {$breakMins}m. Total break today: {$totalBreak}m.");
    }

    /**
     * Submit a regularisation request for a past attendance correction.
     */
    public function submitRegularisation()
    {
        $sevenDaysAgo = Carbon::today()->subDays(7)->format('Y-m-d');

        $this->validate([
            'regDate' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$sevenDaysAgo],
            'regCheckIn' => ['required', 'date_format:H:i'],
            'regCheckOut' => ['required', 'date_format:H:i', 'after:regCheckIn'],
            'regReason' => ['required', 'string', 'min:10', 'max:500'],
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
        \Flux::toast('Regularisation request submitted successfully.');
    }

    protected function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        // Haversine formula to calculate distance in meters
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Format a duration between two Carbon instances as "Xh Ym".
     */
    protected function formatDuration(?Carbon $start, ?Carbon $end): string
    {
        if (! $start || ! $end) {
            return '0h 0m';
        }

        $diff = $end->diff($start);
        $hours = $diff->h + ($diff->d * 24);
        $minutes = $diff->i;

        return $hours.'h '.$minutes.'m';
    }

    /**
     * Build a human-friendly shift label using shift settings or a shift code.
     */
    protected function buildShiftLabel(): ?string
    {
        $employee = Auth::user()->employee;

        // Prefer explicit ShiftSetting relation
        $shiftSetting = $employee->shift ?? null;
        if ($shiftSetting && $shiftSetting->start_time && $shiftSetting->end_time) {
            $label = $shiftSetting->name ?? 'Shift';
            $start = Carbon::parse($shiftSetting->start_time)->format('g:i A');
            $end = Carbon::parse($shiftSetting->end_time)->format('g:i A');

            return sprintf('%s: %s – %s IST | Grace: 5 mins', $label, $start, $end);
        }

        // Fallback: look for a raw shift code on the employee record or user employment_details
        $shiftCode = $employee->getAttribute('shift') ?? $employee->getAttribute('shift_code') ?? null;
        if (! $shiftCode && isset($employee->user) && isset($employee->user->employment_details)) {
            $details = $employee->user->employment_details;
            if (is_array($details) && isset($details['shift'])) {
                $shiftCode = $details['shift'];
            } elseif (is_string($details)) {
                $decoded = json_decode($details, true);
                if (is_array($decoded) && isset($decoded['shift'])) {
                    $shiftCode = $decoded['shift'];
                }
            }
        }

        if ($shiftCode) {
            $code = strtolower((string) $shiftCode);
            if ($code === 'it') {
                return 'IT Shift: 10:30 AM – 7:30 PM IST | Grace: 5 mins';
            }
            if ($code === 'uk') {
                return 'UK Sales Shift: 1:00 PM – 10:00 PM IST | Grace: 5 mins';
            }

            // Try to find a ShiftSetting matching the code/name
            $found = ShiftSetting::where('name', 'like', "%{$shiftCode}%")->first();
            if ($found) {
                $start = Carbon::parse($found->start_time)->format('g:i A');
                $end = Carbon::parse($found->end_time)->format('g:i A');

                return sprintf('%s: %s – %s IST | Grace: 5 mins', $found->name, $start, $end);
            }
        }

        return null;
    }

    public function render()
    {
        return view('livewire.attendance.my-attendance')
            ->layout('layouts.app', ['title' => 'My Attendance']);
    }
}
