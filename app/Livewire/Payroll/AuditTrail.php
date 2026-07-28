<?php

namespace App\Livewire\Payroll;

use App\Models\AuditLog;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Services\SpreadsheetService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Payroll/payslip slice of the audit trail — mirrors AuditLogViewer's
 * filter/paginate/expandable-diff/export pattern, pre-scoped to Payroll and
 * Payslip events, surfacing the role/reason/subject-employee columns those
 * events specifically carry (see AuditLog::record()).
 */
class AuditTrail extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = '';

    public string $from = '';

    public string $to = '';

    public ?int $expandedId = null;

    public function mount(): void
    {
        $this->authorize('view_payroll');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    /**
     * The full event history for one payroll/payslip record, oldest first, in
     * the shape <x-timeline> expects — every audit_logs row sharing the same
     * auditable_type + auditable_id as $log, however far back it goes.
     *
     * @return array<int, array{label: string, user: ?string, timestamp: ?string, icon: string, tone: string, note: ?string}>
     */
    public function timelineStepsFor(AuditLog $log): array
    {
        $icons = [
            'created' => 'plus-circle', 'updated' => 'pencil-square', 'deleted' => 'trash',
            'submitted_for_approval' => 'paper-airplane', 'approved' => 'check-badge',
            'rejected' => 'x-circle', 'locked' => 'lock-closed', 'unlocked' => 'lock-open',
            'downloaded' => 'arrow-down-tray', 'emailed' => 'envelope',
        ];
        $tones = [
            'created' => 'orange', 'approved' => 'emerald', 'unlocked' => 'emerald',
            'rejected' => 'rose', 'deleted' => 'rose', 'locked' => 'amber',
            'downloaded' => 'zinc', 'emailed' => 'zinc',
        ];

        return AuditLog::where('auditable_type', $log->auditable_type)
            ->where('auditable_id', $log->auditable_id)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (AuditLog $l) => [
                'label' => ucfirst(str_replace('_', ' ', $l->action)),
                'user' => $l->user?->name,
                'timestamp' => $l->created_at?->format('d M, H:i'),
                'icon' => $icons[$l->action] ?? 'clock',
                'tone' => $tones[$l->action] ?? 'orange',
                'note' => $l->reason,
            ])
            ->all();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'action', 'from', 'to');
        $this->resetPage();
    }

    private function baseQuery()
    {
        return AuditLog::query()
            ->with(['user', 'subjectEmployee.user'])
            ->whereIn('auditable_type', [Payroll::class, Payslip::class])
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$this->search.'%'))
                        ->orWhereHas('subjectEmployee.user', fn ($u) => $u->where('name', 'like', '%'.$this->search.'%'));
                });
            })
            ->orderByDesc('id');
    }

    public function export(SpreadsheetService $sheets)
    {
        $this->authorize('view_payroll');

        $rows = $this->baseQuery()->limit(5000)->get()->map(fn (AuditLog $log) => [
            $log->created_at?->format('Y-m-d H:i:s'),
            $log->user?->name ?? 'System',
            $log->role ?? '—',
            $log->subjectEmployee?->user?->name ?? '—',
            $log->action,
            class_basename($log->auditable_type),
            $log->auditable_id,
            $log->reason ?? '',
            $log->ip_address,
        ])->all();

        return $sheets->download(
            ['Time', 'User', 'Role', 'Employee', 'Action', 'Record Type', 'Record ID', 'Reason', 'IP'],
            $rows,
            'payroll-audit-trail-'.now()->format('Ymd_His').'.csv',
        );
    }

    public function render()
    {
        $logs = $this->baseQuery()->paginate(25);

        $actions = AuditLog::query()
            ->whereIn('auditable_type', [Payroll::class, Payslip::class])
            ->select('action')->distinct()->orderBy('action')->pluck('action');

        return view('livewire.payroll.audit-trail', compact('logs', 'actions'))
            ->layout('layouts.app', ['title' => 'Payroll Audit Trail']);
    }
}
