<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Office;
use App\Models\ShiftSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AttendanceSettings extends Component
{
    public $settings;

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

    protected $rules = [
        'settings.shift_start' => 'required',
        'settings.shift_end' => 'required',
        'settings.requires_location' => 'boolean',
        'settings.requires_qr' => 'boolean',
    ];

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->settings = AttendanceSetting::first();
        $this->offices = Office::all();
        $this->loadShifts();
    }

    // ─── Global Settings ──────────────────────────────────────────────────────

    public function save(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate();
        $this->settings->save();
        \Flux::toast('Attendance settings updated.');
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
