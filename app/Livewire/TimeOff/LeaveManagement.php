<?php

namespace App\Livewire\TimeOff;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\LeaveBulkRun;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\Office;
use App\Models\User;
use App\Services\Leave\ConexusLeavePolicyService;
use App\Services\Leave\EnsureEmployeeLeaveBalancesService;
use App\Services\Leave\LeaveManagementService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * HR → Leave Management (Phase 2D). One place to see every employee's leave
 * for a year and type, open an employee's leave detail, and run the
 * preview-first bulk operations. Exceptions are managed here; the normal
 * flow (provisioning, accrual, rollover) runs on its own.
 *
 * Every action authorises on the server — a hidden button is not a check.
 */
class LeaveManagement extends Component
{
    use WithPagination;

    public ?int $leaveYearId = null;

    public ?int $leaveTypeId = null;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $departmentId = null;

    public ?int $managerId = null;

    public ?int $officeId = null;

    public ?int $employmentTypeId = null;

    public ?string $status = null;

    public ?int $policyId = null;

    /** A card filter: missing | negative | pending | on_leave | upcoming | expiring | no_policy | review */
    #[Url(as: 'flag')]
    public ?string $flag = null;

    /** @var array<int, int> selected employee ids for bulk operations */
    public array $selected = [];

    // ── Bulk operations (preview first) ──────────────────────────────────
    public ?string $bulkMode = null;

    /** @var array<string, mixed>|null */
    public ?array $bulkPreview = null;

    /** Single-use token of the preview HR is confirming: a double click cannot run it twice. */
    public ?string $bulkToken = null;

    public string $bulkDays = '';

    public string $bulkAddOnType = 'management_grant';

    public string $bulkReason = '';

    public ?string $bulkExpiresOn = null;

    public function mount(LeaveYearResolver $years): void
    {
        $this->authorize('view_leave_management');

        $this->leaveYearId = $years->current()->id;
        // CSL is the Conexus leave balance; the retired Annual Leave is not the default.
        $this->leaveTypeId = LeaveType::where('code', ConexusLeavePolicyService::CSL_CODE)->value('id')
            ?? LeaveType::whereNull('deleted_at')->orderBy('id')->value('id');
    }

    public function updating(string $property): void
    {
        if (! in_array($property, ['selected', 'bulkDays', 'bulkAddOnType', 'bulkReason', 'bulkExpiresOn'], true)) {
            $this->resetPage();
        }
    }

    public function setFlag(?string $flag): void
    {
        $this->flag = $this->flag === $flag ? null : $flag;
        $this->resetPage();
    }

    #[Computed]
    public function year(): LeaveYear
    {
        return LeaveYear::findOrFail($this->leaveYearId);
    }

    #[Computed]
    public function cards(): array
    {
        return app(LeaveManagementService::class)->cards($this->year);
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        $type = LeaveType::withTrashed()->find($this->leaveTypeId);

        if ($type === null) {
            return collect();
        }

        return app(LeaveManagementService::class)->rows($this->year, $type, [
            'search' => $this->search,
            'department_id' => $this->departmentId,
            'manager_id' => $this->managerId,
            'office_id' => $this->officeId,
            'employment_type_id' => $this->employmentTypeId,
            'status' => $this->status ?: null,
            'policy_id' => $this->policyId,
            'flag' => $this->flag,
        ]);
    }

    public function toggleSelectPage(): void
    {
        $ids = $this->rows->pluck('employee_id')->all();
        $this->selected = count(array_diff($ids, $this->selected)) === 0 ? [] : $ids;
    }

    // ── Bulk provisioning ────────────────────────────────────────────────

    public function previewBulkProvision(): void
    {
        $this->authorize('bulk_allocate_leave');

        $result = app(EnsureEmployeeLeaveBalancesService::class)
            ->bulk($this->bulkEmployees(), $this->year, Auth::user(), dryRun: true);

        $this->bulkMode = 'provision';
        $this->bulkToken = (string) Str::uuid();
        $this->bulkPreview = [
            'summary' => $result['summary'],
            'rows' => $result['rows']->whereNotIn('status', [EnsureEmployeeLeaveBalancesService::INELIGIBLE, EnsureEmployeeLeaveBalancesService::NO_ENTITLEMENT])
                ->map(fn ($r) => array_intersect_key($r, array_flip(['employee', 'employee_code', 'leave_type', 'expected', 'current_base', 'status', 'message'])))
                ->values()->take(300)->all(),
        ];
    }

    public function confirmBulkProvision(): void
    {
        $this->authorize('bulk_allocate_leave');
        if (! $this->consumeToken()) {
            return;
        }

        $user = Auth::user();
        $run = LeaveBulkRun::create([
            'kind' => LeaveBulkRun::KIND_BULK_PROVISION, 'leave_year_id' => $this->leaveYearId, 'dry_run' => false,
            'status' => 'running', 'created_by' => $user->id, 'parameters' => ['employee_ids' => $this->bulkEmployees()->pluck('id')->all()],
        ]);

        $result = app(EnsureEmployeeLeaveBalancesService::class)
            ->bulk($this->bulkEmployees(), $this->year, $user, dryRun: false);

        $run->update(['status' => 'completed', 'summary' => $result['summary'], 'completed_at' => now()]);

        $this->closeBulk();
        session()->flash('success', "Provisioned {$result['summary']['valid']} missing entitlement(s); {$result['summary']['warnings']} warning(s) left for review.");
    }

    // ── Bulk add-on ──────────────────────────────────────────────────────

    public function previewBulkAddOn(): void
    {
        $this->authorize('bulk_allocate_leave');
        $this->authorize('add_leave_balance');

        $this->validate([
            'bulkDays' => ['required', 'numeric', 'min:0.5', 'max:60'],
            'bulkReason' => ['required', 'string', 'min:3', 'max:500'],
            'bulkAddOnType' => ['required', 'in:'.implode(',', LeaveBalanceService::ADD_ON_TYPES)],
            'bulkExpiresOn' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $type = LeaveType::findOrFail($this->leaveTypeId);
        $rows = $this->bulkEmployees()->map(function (Employee $e) use ($type) {
            $row = $this->rows->firstWhere('employee_id', $e->id);

            return [
                'employee' => $e->user?->name, 'employee_code' => $e->employee_id, 'leave_type' => $type->name,
                'current' => $row['available'] ?? null,
                'new' => $row && $row['available'] !== null ? round($row['available'] + (float) $this->bulkDays, 2) : null,
                'status' => $type->is_system_controlled ? 'skipped' : ($row && $row['has_balance'] ? 'valid' : 'no_balance'),
                'message' => $type->is_system_controlled ? 'System-controlled type.' : ($row && $row['has_balance'] ? '' : 'No balance this year — provision first.'),
            ];
        });

        $this->bulkMode = 'add_on';
        $this->bulkToken = (string) Str::uuid();
        $this->bulkPreview = [
            'summary' => [
                'selected' => $rows->count(),
                'valid' => $rows->where('status', 'valid')->count(),
                'skipped' => $rows->where('status', '!=', 'valid')->count(),
                'expected_days' => round($rows->where('status', 'valid')->count() * (float) $this->bulkDays, 2),
            ],
            'rows' => $rows->values()->take(300)->all(),
        ];
    }

    public function confirmBulkAddOn(): void
    {
        $this->authorize('bulk_allocate_leave');
        $this->authorize('add_leave_balance');
        if (! $this->consumeToken()) {
            return;
        }

        $this->validate([
            'bulkDays' => ['required', 'numeric', 'min:0.5', 'max:60'],
            'bulkReason' => ['required', 'string', 'min:3', 'max:500'],
            'bulkAddOnType' => ['required', 'in:'.implode(',', LeaveBalanceService::ADD_ON_TYPES)],
            'bulkExpiresOn' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $type = LeaveType::findOrFail($this->leaveTypeId);
        $run = LeaveBulkRun::create([
            'kind' => LeaveBulkRun::KIND_BULK_ADD_ON, 'leave_year_id' => $this->leaveYearId, 'dry_run' => false, 'status' => 'running',
            'created_by' => $user->id, 'parameters' => ['leave_type_id' => $type->id, 'days' => (float) $this->bulkDays, 'reason' => $this->bulkReason],
        ]);

        $done = 0;
        $failed = [];

        foreach ($this->bulkEmployees() as $employee) {
            $row = $this->rows->firstWhere('employee_id', $employee->id);
            if (! $row || ! $row['has_balance']) {
                continue;
            }

            // Nobody credits their own leave (LeaveBalanceService refuses it):
            // the actor's own row is skipped, not reported as a failure.
            if ((int) $employee->user_id === (int) $user->id) {
                continue;
            }

            try {
                DB::transaction(fn () => app(LeaveBalanceService::class)->adjust(
                    $employee, $type, 'credit', (float) $this->bulkDays, $this->bulkReason, 'Bulk add-on (run #'.$run->id.')', $user,
                    $this->year->legacyYear(), LeaveBalanceService::CATEGORY_ADD_ON, $this->bulkAddOnType,
                    expiresOn: $this->bulkExpiresOn ? Carbon::parse($this->bulkExpiresOn) : null,
                ));
                $done++;
            } catch (Throwable $e) {
                $failed[] = ($employee->user?->name ?? $employee->id).': '.$e->getMessage();
            }
        }

        $run->update([
            'status' => $failed ? 'completed_with_issues' : 'completed',
            'summary' => ['granted' => $done, 'failed' => count($failed), 'errors' => array_slice($failed, 0, 50)],
            'completed_at' => now(),
        ]);

        $this->closeBulk();
        session()->flash($failed ? 'error' : 'success', "Add-on granted to {$done} employee(s)".($failed ? '; '.count($failed).' failed: '.implode('; ', array_slice($failed, 0, 3)) : '.'));
    }

    public function closeBulk(): void
    {
        $this->bulkMode = null;
        $this->bulkPreview = null;
        $this->bulkToken = null;
    }

    /** Confirm only a fresh preview, once. Cache::add is atomic, so a second click fails here. */
    private function consumeToken(): bool
    {
        if ($this->bulkToken === null || ! cache()->add('leave-bulk-token:'.$this->bulkToken, true, now()->addHour())) {
            $this->closeBulk();
            session()->flash('error', 'This preview has already been applied or has expired. Preview again.');

            return false;
        }

        return true;
    }

    /** The selected employees, or everyone in the current filter when nothing is selected. */
    private function bulkEmployees(): Collection
    {
        $ids = $this->selected !== [] ? $this->selected : $this->rows->pluck('employee_id')->all();

        return Employee::with('user')->whereIn('id', $ids)->orderBy('id')->get();
    }

    public function render()
    {
        $rows = $this->rows;
        $page = $this->getPage();
        $perPage = 25;

        return view('livewire.time-off.leave-management', [
            'paged' => new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page),
            'leaveYears' => LeaveYear::orderByDesc('starts_on')->get(),
            'leaveTypes' => LeaveType::whereNull('deleted_at')->orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
            'offices' => Office::orderBy('name')->get(),
            'employmentTypes' => EmploymentType::orderBy('name')->get(),
            'policies' => LeavePolicy::orderBy('name')->get(),
            'managers' => User::whereIn('id', Employee::whereNotNull('manager_id')->distinct()->pluck('manager_id'))->orderBy('name')->get(),
            'addOnTypes' => LeaveBalanceService::ADD_ON_TYPES,
        ]);
    }
}
