<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveAuditCategoriser;
use App\Services\SpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Activity Log / Audit Trail: the central, read-only view over every
 * recorded administrative and security event — who (and their role, and any
 * impersonator), what, which module, which employee, before → after, why,
 * from where, and the request it belonged to.
 *
 * Read-only by design: entries cannot be edited or deleted (the model
 * refuses), and this screen offers no way to try. Open to holders of View
 * Activity Log or Manage Settings.
 */
class AuditLogViewer extends Component
{
    use WithPagination;

    public string $search = '';

    public string $action = '';

    public string $model = '';

    public string $from = '';

    public string $to = '';

    /** Filters over the categorised columns (role, module, event). */
    public string $role = '';

    public string $module = '';

    public string $event = '';

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
        abort_unless(self::canView(), 403);
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user !== null && ($user->hasPermission('view_audit_log') || $user->canManageSettings());
    }

    public function updated(string $property): void
    {
        if ($property !== 'expandedId') {
            $this->resetPage();
        }
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset(['category', 'employeeId', 'leaveTypeId', 'performedBy', 'role', 'module', 'event']);
        $this->reset('search', 'action', 'model', 'from', 'to');
        $this->resetPage();
    }

    private function baseQuery(): Builder
    {
        return AuditLog::query()
            ->with(['user', 'impersonator', 'subjectEmployee.user'])
            ->when($this->action !== '', fn ($q) => $q->where('action', $this->action))
            ->when($this->model !== '', fn ($q) => $q->where('auditable_type', $this->model))
            ->when($this->role !== '', fn ($q) => $q->where('role', $this->role))
            // Older rows (before the categorised columns) carry no module, so
            // the module filter also matches the category.
            ->when($this->module !== '', fn ($q) => $q->where(fn ($m) => $m->where('module', $this->module)->orWhere('category', $this->module)))
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
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
        abort_unless(self::canView(), 403);

        $rows = $this->baseQuery()->limit(5000)->get()->map(fn (AuditLog $log) => [
            $log->created_at?->format('Y-m-d H:i:s'),
            $log->user?->name ?? 'System',
            $log->role,
            $log->impersonator?->name,
            $log->event ?? $log->action,
            $log->module ?? $log->category,
            class_basename($log->auditable_type).' #'.$log->auditable_id,
            $log->subjectEmployee?->user?->name,
            self::changedFields($log),
            $log->reason,
            $log->ip_address,
            self::device($log->user_agent),
            $log->request_id,
        ])->all();

        return $sheets->download(
            ['Time', 'Actor', 'Role', 'Impersonated by', 'Event', 'Module', 'Record', 'Employee', 'Changes', 'Reason', 'IP', 'Device', 'Reference'],
            $rows,
            'activity-log-'.now()->format('Ymd_His').'.csv',
        );
    }

    /**
     * The fields shown in the before → after table: only what changed when
     * both sides were recorded (generic observers store the whole original
     * row), otherwise every recorded field. Timestamps are never shown.
     *
     * @return array<int, string>
     */
    public static function diffKeys(AuditLog $log): array
    {
        $old = (array) ($log->old_values ?? []);
        $new = (array) ($log->new_values ?? []);

        $keys = ($old !== [] && $new !== [])
            ? array_keys(array_filter($new, fn ($value, $key) => ($old[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH))
            : array_keys($old + $new);

        return array_values(array_diff($keys, ['updated_at', 'created_at']));
    }

    /** A one-line "field: old → new" summary, for the export. */
    private static function changedFields(AuditLog $log): string
    {
        $render = fn ($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : json_encode($v);

        return collect(self::diffKeys($log))->take(12)->map(fn (string $key) => $key.': '
            .$render(($log->old_values ?? [])[$key] ?? null).' → '.$render(($log->new_values ?? [])[$key] ?? null))
            ->implode('; ');
    }

    /** "Chrome on Windows"-style label from a user agent, or null. */
    public static function device(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $browser = match (true) {
            Str::contains($userAgent, 'Edg/') => 'Edge',
            Str::contains($userAgent, 'OPR/') => 'Opera',
            Str::contains($userAgent, 'Chrome/') => 'Chrome',
            Str::contains($userAgent, 'Firefox/') => 'Firefox',
            Str::contains($userAgent, 'Safari/') => 'Safari',
            Str::contains($userAgent, ['Symfony', 'curl', 'Guzzle']) => 'Script',
            default => 'Browser',
        };

        $os = match (true) {
            Str::contains($userAgent, ['iPhone', 'iPad']) => 'iOS',
            Str::contains($userAgent, 'Android') => 'Android',
            Str::contains($userAgent, 'Windows') => 'Windows',
            Str::contains($userAgent, 'Mac OS') => 'macOS',
            Str::contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} on {$os}" : $browser;
    }

    /** "LEAVE_APPROVED" → "Leave approved"; falls back to the raw action. */
    public static function eventLabel(AuditLog $log): string
    {
        return $log->event
            ? Str::ucfirst(Str::lower(str_replace('_', ' ', $log->event)))
            : Str::ucfirst(str_replace(['.', '_'], ' ', (string) $log->action));
    }

    public function render()
    {
        $logs = $this->baseQuery()->paginate(25);

        $distinct = fn (string $column) => AuditLog::query()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column);

        return view('livewire.audit-log-viewer', [
            'logs' => $logs,
            'modelTypes' => $distinct('auditable_type'),
            'actions' => $distinct('action'),
            'roles' => $distinct('role'),
            'modules' => $distinct('module')->merge($distinct('category'))->unique()->sort()->values(),
            'events' => $distinct('event'),
            'categoriser' => app(LeaveAuditCategoriser::class),
            'categories' => LeaveAuditCategoriser::CATEGORIES,
            // Only people who appear in the log — not every account — keeps
            // the pickers short and the page fast as the company grows.
            'employees' => Employee::with('user')
                ->whereIn('id', AuditLog::query()->whereNotNull('subject_employee_id')->distinct()->pluck('subject_employee_id'))
                ->get()->sortBy(fn (Employee $e) => $e->user?->name ?? $e->employee_id)->values(),
            'leaveTypes' => LeaveType::orderBy('name')->get(),
            'actors' => User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
                ->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Activity Log']);
    }
}
