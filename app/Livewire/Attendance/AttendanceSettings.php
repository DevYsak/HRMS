<?php

namespace App\Livewire\Attendance;

use App\Models\AttendanceSetting;
use App\Models\Office;
use Livewire\Component;

class AttendanceSettings extends Component
{
    public $settings;
    public $offices;

    protected $rules = [
        'settings.shift_start' => 'required',
        'settings.shift_end' => 'required',
        'settings.late_grace_period' => 'required|integer|min:0',
        'settings.requires_location' => 'boolean',
        'settings.requires_qr' => 'boolean',
    ];

    public function mount()
    {
        $this->settings = AttendanceSetting::first();
        $this->offices = Office::all();
    }

    public function save()
    {
        $this->validate();
        $this->settings->save();
        \Flux::toast('Attendance settings updated.');
    }

    public function updateOffice($id, $lat, $lng, $radius)
    {
        $office = Office::findOrFail($id);
        $office->update([
            'latitude'  => $lat  !== '' && $lat  !== null ? (float) $lat  : null,
            'longitude' => $lng  !== '' && $lng  !== null ? (float) $lng  : null,
            'radius'    => $radius !== '' && $radius !== null ? (float) $radius : null,
        ]);
        $this->offices = Office::all();
        \Flux::toast('Office geofence updated.');
    }

    public function render()
    {
        return view('livewire.attendance.attendance-settings')
            ->layout('layouts.app', ['title' => 'Attendance Settings']);
    }
}
