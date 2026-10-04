<?php

namespace App\Livewire\Overtime;

use App\Models\AuditLog;
use App\Models\OtWindow;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * OT windows — the company-approved periods in which employees may submit
 * overtime pre-approval requests (OvertimeService::submitRequest refuses a
 * request outside one). The rule existed with no screen to open a window,
 * so the spec §3.4 request flow could not start. HR and Directors open and
 * close windows here; every change is audit logged.
 */
class OtWindows extends Component
{
    public string $title = '';

    public string $reason = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public function mount(): void
    {
        $this->authorizeWindows();
        $this->startsAt = now()->toDateString();
        $this->endsAt = now()->endOfMonth()->toDateString();
    }

    /** Opening OT to the company is an HR / Director decision. */
    private function authorizeWindows(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
    }

    public function open(): void
    {
        $this->authorizeWindows();

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after_or_equal:startsAt'],
        ]);

        $window = OtWindow::create([
            'title' => $this->title,
            'reason' => $this->reason !== '' ? $this->reason : null,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        AuditLog::record($window, 'created', null, $window->only(['title', 'starts_at', 'ends_at']));

        $this->reset(['title', 'reason']);
        \Flux::toast('OT window opened — employees can request overtime for these dates.');
    }

    public function close(int $id): void
    {
        $this->authorizeWindows();

        $window = OtWindow::findOrFail($id);
        $window->update(['is_active' => false]);

        AuditLog::record($window, 'closed', ['is_active' => true], ['is_active' => false]);

        \Flux::toast('OT window closed. Requests already submitted are unaffected.');
    }

    public function render()
    {
        return view('livewire.overtime.ot-windows', [
            'windows' => OtWindow::with('createdBy')->latest('starts_at')->limit(50)->get(),
            'openToday' => OtWindow::activeToday(),
        ])->layout('layouts.app', ['title' => 'OT Windows']);
    }
}
