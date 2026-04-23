<?php

namespace App\Livewire\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyAttendance extends Component
{
    public $todayAttendance = null;

    public $settings = null;

    public $history = [];

    public $stats = [
        'present' => 0,
        'late'    => 0,
        'hours'   => 0,
    ];

    // Regularisation form fields
    public string $regDate     = '';
    public string $regCheckIn  = '';
    public string $regCheckOut = '';
    public string $regReason   = '';

    public function mount()
    {
        $this->settings = AttendanceSetting::first();
        $this->loadToday();
        $this->loadHistory();
    }

    public function loadToday()
    {
        $this->todayAttendance = Attendance::where('employee_id', Auth::user()->employee->id)
            ->where('date', Carbon::today())
            ->first();
    }

    public function loadHistory()
    {
        $employee      = Auth::user()->employee;
        $this->history = Attendance::where('employee_id', $employee->id)
            ->whereMonth('date', Carbon::now()->month)
            ->latest()
            ->get();

        $this->stats = [
            'present' => $this->history->where('status', '!=', 'absent')->count(),
            'late'    => $this->history->where('status', 'late')->count(),
            'hours'   => $this->history->sum('total_hours'),
        ];
    }

    public function checkIn($lat, $lng)
    {
        if ($this->todayAttendance) {
            \Flux::toast('You are already clocked in for today.', variant: 'warning');

            return;
        }

        $employee = Auth::user()->employee;
        $office   = $employee->office;

        $isVerified = true;
        $status     = 'on_time';

        // Geo-fencing check (Optional based on PRD)
        if ($office && $office->latitude && $office->longitude) {
            $distance = $this->calculateDistance($lat, $lng, $office->latitude, $office->longitude);
            if ($distance > $office->radius) {
                $isVerified = false;
                $status     = 'remote';
            }
        }

        $now        = Carbon::now();
        $shiftStart = Carbon::createFromTimeString($this->settings->shift_start);

        // Late check
        $isLate      = false;
        $lateMinutes = 0;
        if ($now->greaterThan($shiftStart->addMinutes($this->settings->late_grace_period))) {
            $isLate      = true;
            $lateMinutes = $shiftStart->diffInMinutes($now);
            if ($status !== 'remote') {
                $status = 'late';
            }
        }

        Attendance::create([
            'employee_id'  => $employee->id,
            'date'         => Carbon::today(),
            'check_in'     => $now,
            'check_in_lat' => $lat,
            'check_in_lng' => $lng,
            'status'       => $status,
            'is_late'      => $isLate,
            'late_minutes' => $lateMinutes,
            'is_verified'  => $isVerified,
        ]);

        $this->loadToday();
        \Flux::toast('Clocked in successfully at '.$now->format('H:i'));
    }

    public function checkOut($lat, $lng)
    {
        if (! $this->todayAttendance || $this->todayAttendance->check_out) {
            return;
        }

        $now = Carbon::now();

        // Calculate gross hours minus break minutes
        $grossMinutes = $this->todayAttendance->check_in->diffInMinutes($now);
        $netMinutes   = max(0, $grossMinutes - $this->todayAttendance->break_minutes);
        $totalHours   = $netMinutes / 60;

        $this->todayAttendance->update([
            'check_out'     => $now,
            'check_out_lat' => $lat,
            'check_out_lng' => $lng,
            'total_hours'   => round($totalHours, 2),
        ]);

        // Comp Off auto-credit logic
        if ($totalHours >= 4.0) { // Assuming 4 hours minimum for a comp-off
            $today = $now->toDateString();
            $isHoliday = \App\Models\PublicHoliday::where('date', $today)->exists();
            $isMdl = \App\Models\DecemberMandatoryDay::where('date', $today)->exists();

            if ($isHoliday || $isMdl) {
                // Find or create 'Comp Off' leave type
                $leaveType = \App\Models\LeaveType::firstOrCreate(
                    ['name' => 'Comp Off'],
                    ['is_paid' => true, 'description' => 'Compensatory Off']
                );

                // Add 1 day to the balance
                $balance = \App\Models\LeaveBalance::firstOrCreate(
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
        \Flux::toast('Clocked out successfully. Net hours: '.round($totalHours, 2));
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

        $now          = Carbon::now();
        $breakMins    = (int) $this->todayAttendance->break_start->diffInMinutes($now);
        $totalBreak   = ($this->todayAttendance->break_minutes ?? 0) + $breakMins;

        $this->todayAttendance->update([
            'break_end'     => $now,
            'break_start'   => null, // reset so another break can be started
            'break_minutes' => $totalBreak,
        ]);

        $this->loadToday();
        \Flux::toast("Break ended. Duration: {$breakMins}m. Total break today: {$totalBreak}m.");
    }

    /**
     * Submit a regularisation request for a past attendance correction.
     */
    public function submitRegularisation()
    {
        $sevenDaysAgo = Carbon::today()->subDays(7)->format('Y-m-d');

        $this->validate([
            'regDate'     => ['required', 'date', 'before_or_equal:today', 'after_or_equal:' . $sevenDaysAgo],
            'regCheckIn'  => ['required', 'date_format:H:i'],
            'regCheckOut' => ['required', 'date_format:H:i', 'after:regCheckIn'],
            'regReason'   => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $employee   = Auth::user()->employee;
        $attendance = Attendance::where('employee_id', $employee->id)
            ->where('date', $this->regDate)
            ->first();

        AttendanceRegularisation::create([
            'employee_id'         => $employee->id,
            'attendance_id'       => $attendance?->id,
            'work_date'           => $this->regDate,
            'requested_check_in'  => $this->regDate.' '.$this->regCheckIn.':00',
            'requested_check_out' => $this->regDate.' '.$this->regCheckOut.':00',
            'reason'              => $this->regReason,
            'status'              => 'pending',
        ]);

        $this->reset(['regDate', 'regCheckIn', 'regCheckOut', 'regReason']);
        $this->dispatch('flux:modal:close', name: 'regularisation-modal');
        \Flux::toast('Regularisation request submitted successfully.');
    }

    protected function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        // Haversine formula to calculate distance in meters
        $earthRadius = 6371000;
        $dLat        = deg2rad($lat2 - $lat1);
        $dLon        = deg2rad($lon2 - $lon1);
        $a           = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function render()
    {
        return view('livewire.attendance.my-attendance')
            ->layout('layouts.app', ['title' => 'My Attendance']);
    }
}
