<?php

namespace App\Livewire\Attendance;

use App\Services\Biometric\BiometricApiService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class BiometricAttendance extends Component
{
    public string $date = '';

    public string $search = '';

    public string $department = '';

    // Fetched from biometric-app
    public array $employees = [];

    public array $stats = ['present' => 0, 'in_office' => 0, 'absent' => 0, 'total' => 0];

    public ?string $error = null;

    public ?string $fetchedAt = null;

    public function mount(): void
    {
        $this->date = now()->toDateString();
        $this->fetch();
    }

    public function updatedDate(): void
    {
        $this->fetch();
    }

    public function refresh(): void
    {
        $this->fetch();
    }

    public function render()
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $rows = collect($this->employees);

        if ($this->search) {
            $s = strtolower($this->search);
            $rows = $rows->filter(fn ($e) => str_contains(strtolower($e['name'] ?? ''), $s) ||
                str_contains($e['employee_code'] ?? '', $s)
            );
        }

        if ($this->department) {
            $rows = $rows->filter(fn ($e) => $e['department'] === $this->department);
        }

        $departments = collect($this->employees)
            ->pluck('department')
            ->unique()
            ->sort()
            ->values();

        return view('livewire.attendance.biometric-attendance', [
            'rows' => $rows->values(),
            'departments' => $departments,
        ])->layout('layouts.app', ['title' => 'Biometric Attendance']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function fetch(): void
    {
        $this->error = null;

        $result = app(BiometricApiService::class)->forDate($this->date);

        if (! $result['success']) {
            $this->error = $result['error'];
            $this->employees = [];
            $this->stats = ['present' => 0, 'in_office' => 0, 'absent' => 0, 'total' => 0];

            return;
        }

        $this->employees = $result['data']['employees'] ?? [];
        $this->fetchedAt = now()->format('h:i:s A');

        $this->stats = [
            'total' => count($this->employees),
            'present' => count(array_filter($this->employees, fn ($e) => in_array($e['summary']['status'] ?? '', ['checked_out', 'admin_corrected']))),
            'in_office' => count(array_filter($this->employees, fn ($e) => ($e['summary']['status'] ?? '') === 'in_office')),
            'absent' => count(array_filter($this->employees, fn ($e) => ($e['summary']['status'] ?? '') === 'absent')),
        ];
    }
}
