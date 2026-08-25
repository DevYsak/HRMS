<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceScoreSetting;
use App\Models\AttendanceSetting;
use App\Models\Office;
use App\Models\ShiftSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AttendanceSettings extends Component
{
    public $settings;

    /** Engine policy thresholds (plain array so tests + binding both work). */
    public array $policy = [];

    /** Score-engine weights (Rule 11) — HR-editable, DB-driven. */
    public array $weights = [];

    public $offices;

    public $shifts;

    // Shift form fields (shared for create & edit)
    public string $shiftName = '';

    public string $shiftStart = '';

    public string $shiftEnd = '';

    public int $shiftGrace = 5;

    public float $shiftStandardHours = 9.0;

    public string $shiftDescription = '';

    public ?int $editingShiftId = null;

    public bool $showShiftModal = false;

    /** Non-working weekdays as Carbon dayOfWeek numbers (0 = Sunday … 6 = Saturday). */
    public array $weeklyOffDays = [];

    /** The 10 configurable score-weight keys (Rule 11). */
    private const WEIGHT_KEYS = [
        'late_arrival_penalty', 'late_per_30m_penalty', 'early_exit_penalty',
        'missing_punch_penalty', 'auto_punch_out_penalty', 'regularization_penalty',
        'break_violation_penalty', 'short_hours_penalty', 'overtime_bonus', 'holiday_work_bonus',
    ];

    protected $rules = [
        'settings.shift_start' => 'required',
        'settings.shift_end' => 'required',
        'settings.ot_rate_per_hour' => 'required|numeric|min:0',
        'settings.requires_location' => 'boolean',
        'settings.requires_qr' => 'boolean',
        'settings.requires_photo' => 'boolean',
        'policy.late_grace_period' => 'required|integer|min:0|max:120',
        'policy.late_warning_threshold' => 'required|integer|min:1|max:31',
        'policy.auto_checkout_buffer_minutes' => 'required|integer|min:0|max:240',
        'policy.ot_auto_close_time' => 'required',
    ];

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->settings = AttendanceSetting::firstOrCreate([]);
        $this->policy = [
            'late_grace_period' => (int) ($this->settings->late_grace_period ?? 15),
            'late_warning_threshold' => (int) ($this->settings->late_warning_threshold ?? 3),
            'auto_checkout_buffer_minutes' => (int) ($this->settings->auto_checkout_buffer_minutes ?? 30),
            'ot_auto_close_time' => substr((string) ($this->settings->ot_auto_close_time ?? '23:59:00'), 0, 5),
        ];

        $this->weeklyOffDays = AttendanceSetting::weeklyOffDays();

        $scores = AttendanceScoreSetting::current();
        $this->weights = collect(self::WEIGHT_KEYS)->mapWithKeys(fn ($k) => [$k => (float) $scores->{$k}])->all();

        $this->offices = Office::all();
        $this->loadShifts();
    }

    // ─── Global Settings ──────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate();

        // At least one working day must remain, or every employee would be
        // permanently off and attendance would stop meaning anything.
        $offDays = array_values(array_unique(array_map('intval', $this->weeklyOffDays)));
        if (count($offDays) >= 7) {
            \Flux::toast('At least one day must remain a working day.', variant: 'danger');

            return;
        }

        $this->settings->fill([
            'weekly_off_days' => $offDays !== [] ? $offDays : null,
            'late_grace_period' => $this->policy['late_grace_period'],
            'late_warning_threshold' => $this->policy['late_warning_threshold'],
            'auto_checkout_buffer_minutes' => $this->policy['auto_checkout_buffer_minutes'],
            'ot_auto_close_time' => $this->policy['ot_auto_close_time'].':00',
        ])->save();

        \Flux::toast('Attendance settings updated.');
    }

    /** Persist the Rule 11 score-engine weights. */
    public function saveScoreSettings(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate(collect(self::WEIGHT_KEYS)
            ->mapWithKeys(fn ($k) => ["weights.{$k}" => 'required|numeric|min:0|max:100'])->all());

        AttendanceScoreSetting::current()->update($this->weights);
        \Flux::toast('Attendance score weights updated.');
    }

    public function updateOffice($id, $lat, $lng, $radius): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $office = Office::findOrFail($id);
        $office->update([
            'latitude' => $lat !== '' && $lat !== null ? (float) $lat : null,
            'longitude' => $lng !== '' && $lng !== null ? (float) $lng : null,
            'radius' => $radius !== '' && $radius !== null ? (float) $radius : null,
        ]);
        $this->offices = Office::all();
        \Flux::toast('Office geofence updated.');
    }

    // ─── Shift Management ─────────────────────────────────────────────────────

    public function loadShifts(): void
    {
        $this->shifts = ShiftSetting::withCount('employees')->get();
    }

    public function openNewShift(): void
    {
        $this->reset(['shiftName', 'shiftStart', 'shiftEnd', 'shiftDescription', 'editingShiftId']);
        $this->shiftGrace = 5;
        $this->shiftStandardHours = 9.0;
        $this->showShiftModal = true;
    }

    public function editShift(int $id): void
    {
        $shift = ShiftSetting::findOrFail($id);

        $this->editingShiftId = $id;
        $this->shiftName = $shift->name;
        $this->shiftStart = substr($shift->start_time, 0, 5); // HH:MM for <input type="time">
        $this->shiftEnd = substr($shift->end_time, 0, 5);
        $this->shiftGrace = $shift->grace_minutes;
        $this->shiftStandardHours = (float) $shift->standard_hours;
        $this->shiftDescription = $shift->description ?? '';
        $this->showShiftModal = true;
    }

    public function saveShift(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate([
            'shiftName' => 'required|string|max:100',
            'shiftStart' => 'required|date_format:H:i',
            'shiftEnd' => 'required|date_format:H:i',
            'shiftGrace' => 'required|integer|min:0|max:60',
            'shiftStandardHours' => 'required|numeric|min:1|max:24',
        ]);

        $data = [
            'name' => $this->shiftName,
            'start_time' => $this->shiftStart.':00',
            'end_time' => $this->shiftEnd.':00',
            'grace_minutes' => $this->shiftGrace,
            'standard_hours' => $this->shiftStandardHours,
            'ot_threshold_hours' => $this->shiftStandardHours,
            'description' => $this->shiftDescription ?: null,
        ];

        if ($this->editingShiftId) {
            ShiftSetting::findOrFail($this->editingShiftId)->update($data);
            \Flux::toast('Shift updated successfully.');
        } else {
            ShiftSetting::create($data);
            \Flux::toast('Shift created successfully.');
        }

        $this->showShiftModal = false;
        $this->reset(['shiftName', 'shiftStart', 'shiftEnd', 'shiftDescription', 'editingShiftId']);
        $this->shiftGrace = 5;
        $this->shiftStandardHours = 9.0;
        $this->loadShifts();
    }

    public function deleteShift(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $shift = ShiftSetting::withCount('employees')->findOrFail($id);

        if ($shift->employees_count > 0) {
            \Flux::toast("Cannot delete: {$shift->employees_count} employee(s) are assigned to this shift.", variant: 'danger');

            return;
        }

        $shift->delete();
        $this->loadShifts();
        \Flux::toast('Shift deleted.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        return view('livewire.attendance.attendance-settings')
            ->layout('layouts.app', ['title' => 'Attendance Settings']);
    }
}
