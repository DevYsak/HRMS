<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveAuditCategoriser;
use App\Services\SpreadsheetService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Filterable, paginated viewer over the audit trail (who / when / IP / browser /
 * old → new values). Closes the Phase 2 · Feature 11 gap — previously the log
 * was only visible as a small dashboard widget.
 */
class AuditLogViewer extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = '';

    public string $model = '';

    public string $from = '';

    public string $to = '';

    /**
     * Leave-specific filters. A generic action/model pair cannot answer "show
     * me every carry forward" — the actions are all called "created" — so the
     * category is derived and filtered through LeaveAuditCategoriser.
     */
    public string $category = '';

    public ?int $employeeId = null;

    public ?int $leaveTypeId = null;

    public ?int $performedBy = null;

    public ?int $expandedId = null;

    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedEmployeeId(): void
    {
        $this->resetPage();
    }

    public function updatedLeaveTypeId(): void
    {
        $this->resetPage();
    }

    public function updatedPerformedBy(): void
    {
        $this->resetPage();
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function updatedModel(): void
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

    public function clearFilters(): void
    {
        $this->reset(['category', 'employeeId', 'leaveTypeId', 'performedBy']);
        $this->reset('search', 'action', 'model', 'from', 'to');
        $this->resetPage();
    }

    private function baseQuery()
    {
        return AuditLog::query()
            ->with('user')
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->when($this->model !== '', fn ($q) => $q->where('auditable_type', $this->model))
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->search !== '', fn ($q) => $q->whereHas('user', function ($u): void {
                $u->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            }))
            // Recorded on every leave entry, so this reaches the person the
            // action was about rather than the person who performed it.
            ->when($this->employeeId, fn ($q) => $q->where('subject_employee_id', $this->employeeId))
            ->when($this->performedBy, fn ($q) => $q->where('user_id', $this->performedBy))
            ->when($this->leaveTypeId, fn ($q) => $q->where('new_values->leave_type_id', $this->leaveTypeId))
            ->when($this->category !== '', fn ($q) => app(LeaveAuditCategoriser::class)->scopeToCategory($q, $this->category))
            ->orderByDesc('id');
    }

    public function export(SpreadsheetService $sheets)
    {
        $this->authorize('manage-settings');

        $rows = $this->baseQuery()->limit(5000)->get()->map(fn (AuditLog $log) => [
            $log->created_at?->format('Y-m-d H:i:s'),
            $log->user?->name ?? 'System',
            $log->action,
            class_basename($log->auditable_type),
            $log->auditable_id,
            $log->ip_address,
        ])->all();

        return $sheets->download(
            ['Time', 'User', 'Action', 'Model', 'Record', 'IP'],
            $rows,
            'audit-log-'.now()->format('Ymd_His').'.csv',
        );
    }

    public function render()
    {
        $logs = $this->baseQuery()->paginate(25);

        $modelTypes = AuditLog::query()->select('auditable_type')->distinct()
            ->orderBy('auditable_type')->pluck('auditable_type');
        $actions = AuditLog::query()->select('action')->distinct()
            ->orderBy('action')->pluck('action');

        $categoriser = app(LeaveAuditCategoriser::class);
        $categories = LeaveAuditCategoriser::CATEGORIES;
        $employees = Employee::with('user')->orderBy('id')->get();
        $leaveTypes = LeaveType::orderBy('name')->get();
        $actors = User::orderBy('name')->get(['id', 'name']);

        return view('livewire.audit-log-viewer', compact(
            'logs', 'modelTypes', 'actions', 'categoriser', 'categories', 'employees', 'leaveTypes', 'actors'
        ))
            ->layout('layouts.app', ['title' => 'Audit Log']);
    }
}
