<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveType;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TimeOffSettings extends Component
{
    public $name = '';

    public $is_paid = true;

    public $color = '#1DB77A';

    public string $category = 'other';

    public bool $allow_carry_forward = false;

    public int $carry_forward_limit = 0;

    public bool $allow_encashment = false;

    public $editingId = null;

    public $showModal = false;

    public function openModal($id = null)
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->editingId = $id;

        if ($id) {
            $type = LeaveType::findOrFail($id);
            $this->name = $type->name;
            $this->is_paid = $type->is_paid;
            $this->color = $type->color;
            $this->category = $type->category ?? 'other';
            $this->allow_carry_forward = (bool) $type->allow_carry_forward;
            $this->carry_forward_limit = (int) $type->carry_forward_limit;
            $this->allow_encashment = (bool) $type->allow_encashment;
        } else {
            $this->reset([
                'name',
                'is_paid',
                'color',
                'category',
                'allow_carry_forward',
                'carry_forward_limit',
                'allow_encashment',
            ]);
            $this->is_paid = true;
            $this->color = '#1DB77A';
            $this->category = 'other';
        }

        $this->showModal = true;
    }

    public function save()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate([
            'name' => 'required|string|max:50',
            'is_paid' => 'required|boolean',
            'color' => 'required|string|size:7',
            'category' => 'required|in:annual,sick,mdl,comp_off,encashment,unpaid,other',
            'allow_carry_forward' => 'required|boolean',
            'carry_forward_limit' => 'required|integer|min:0',
            'allow_encashment' => 'required|boolean',
        ]);

        app(LeaveService::class)->saveLeaveType([
            'name' => $this->name,
            'is_paid' => $this->is_paid,
            'color' => $this->color,
            'category' => $this->category,
            'allow_carry_forward' => $this->allow_carry_forward,
            'carry_forward_limit' => $this->carry_forward_limit,
            'allow_encashment' => $this->allow_encashment,
        ], $this->editingId);

        $this->showModal = false;
        \Flux::toast('Leave type saved successfully.');
    }

    public function delete($id)
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        app(LeaveService::class)->deleteLeaveType(LeaveType::findOrFail($id));
        \Flux::toast('Leave type deleted.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        return view('livewire.time-off.time-off-settings', [
            'leaveTypes' => LeaveType::all(),
        ])->layout('layouts.app', ['title' => 'Leave Settings']);
    }
}
