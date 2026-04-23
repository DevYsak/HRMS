<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveType;
use Livewire\Component;

class TimeOffSettings extends Component
{
    public $name = '';
    public $is_paid = true;
    public $color = '#1DB77A';
    public $editingId = null;
    public $showModal = false;

    public function openModal($id = null)
    {
        $this->editingId = $id;

        if ($id) {
            $type = LeaveType::findOrFail($id);
            $this->name = $type->name;
            $this->is_paid = $type->is_paid;
            $this->color = $type->color;
        } else {
            $this->reset(['name', 'is_paid', 'color']);
            $this->is_paid = true;
            $this->color = '#1DB77A';
        }

        $this->showModal = true;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:50',
            'is_paid' => 'required|boolean',
            'color' => 'required|string|size:7',
        ]);

        LeaveType::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $this->name,
                'is_paid' => $this->is_paid,
                'color' => $this->color,
            ]
        );

        $this->showModal = false;
        \Flux::toast('Leave type saved successfully.');
    }

    public function delete($id)
    {
        LeaveType::findOrFail($id)->delete();
        \Flux::toast('Leave type deleted.');
    }

    public function render()
    {
        return view('livewire.time-off.time-off-settings', [
            'leaveTypes' => LeaveType::all(),
        ])->layout('layouts.app', ['title' => 'Leave Settings']);
    }
}
