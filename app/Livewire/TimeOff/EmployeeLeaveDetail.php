<?php

namespace App\Livewire\TimeOff;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeLeaveOverride;
use App\Models\LeaveBalance;
use App\Models\LeaveCarryForwardTransaction;
use App\Models\LeaveEncashment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Models\User;
use App\Notifications\LeaveBalanceChangedNotification;
use App\Services\Leave\EmployeeLeaveOverrideService;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveCarryForwardService;
use App\Services\Leave\LeaveManagementService;
use App\Services\Leave\LeaveStatementService;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveBalanceService;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * HR → Leave Management → one employee (Phase 2D).
 *
 * Every leave type with every bucket; Add / Deduct / Correct Balance,
 * employee override and apply-on-behalf, each behind its own permission,
 * each needing a reason, each audited and posted through the ledger by the
 * service it calls. History and the month-wise statement read the ledger.
 */
class EmployeeLeaveDetail extends Component
{
    use WithFileUploads;

    public Employee $employee;

    #[Url(as: 'year')]
    public ?int $leaveYearId = null;

    #[Url(as: 'tab')]
    public string $tab = 'balances';

    // History / statement filters.
    public ?int $historyTypeId = null;

    public ?string $historyMonth = null;

    public ?string $historyEntryType = null;

    public ?string $historyFrom = null;

    public ?string $historyTo = null;

    public ?int $statementTypeId = null;

    // ── Action form ──────────────────────────────────────────────────────
    /** add | deduct | correct | override | apply */
    public ?string $action = null;

    public ?int $formTypeId = null;

    public string $days = '';

    public string $addOnType = 'management_grant';

    public ?string $effectiveDate = null;

    public ?string $expiresOn = null;

    public string $targetBalance = '';

    public string $overrideMode = 'add';

    public bool $overrideThisYearOnly = true;

    public string $reason = '';

    public string $internalNote = '';

    public bool $notifyEmployee = true;

    public ?string $startDate = null;

    public ?string $endDate = null;

    public bool $isHalfDay = false;

    public string $halfDayPeriod = 'first_half';

    public string $paymentStatus = 'paid';

    // Carry forward (HR enters it directly — not a balance adjustment).
    public ?int $cfFromYearId = null;

    public ?int $cfToYearId = null;

    public string $carryDays = '';

    /** Opens the Carry Forward dialog for this leave type on arrival (from Leave Management). */
    #[Url(as: 'cf')]
    public ?int $openCarryForwardFor = null;

    /** Carry-forward transaction being reversed, and why. */
    public ?int $reverseTxId = null;

    public string $reverseReason = '';

    /** @var TemporaryUploadedFile|null */
    public $attachment = null;

    public function mount(Employee $employee, LeaveYearResolver $years): void
    {
        $this->authorize('view_leave_management');

        $this->employee = $employee->load(['user', 'department', 'leavePolicy', 'manager']);
        $this->leaveYearId ??= $years->current()->id;

        if ($this->openCarryForwardFor && Auth::user()->can('manage_leave_carry_forward')) {
            $this->openAction('carry_forward', $this->openCarryForwardFor);
            $this->openCarryForwardFor = null;
        }
    }

    /**
     * The eligible balance for the Carry Forward dialog, recalculated as HR
     * changes the years or the leave type.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function carryInfo(): ?array
    {
        if ($this->action !== 'carry_forward' || ! $this->formTypeId || ! $this->cfFromYearId || ! $this->cfToYearId) {
            return null;
        }

        $type = LeaveType::withTrashed()->find($this->formTypeId);
        $from = LeaveYear::find($this->cfFromYearId);
        $to = LeaveYear::find($this->cfToYearId);

        if (! $type || ! $from || ! $to || $from->starts_on->gte($to->starts_on)) {
            return null;
        }

        return app(LeaveCarryForwardService::class)->eligibilityFor($this->employee, $type, $from, $to);
    }

    /** Every carry-forward decision for this employee, with its audit trail. */
    #[Computed]
    public function carryHistory(): Collection
    {
        return app(LeaveCarryForwardService::class)->historyFor($this->employee);
    }

    #[Computed]
    public function carryAudit(): Collection
    {
        return AuditLog::with('user')
            ->where('auditable_type', LeaveCarryForwardTransaction::class)
            ->where('subject_employee_id', $this->employee->id)
            ->latest('id')->limit(100)->get();
    }

    #[Computed]
    public function year(): LeaveYear
    {
        return LeaveYear::findOrFail($this->leaveYearId);
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function balances(): Collection
    {
        return app(LeaveManagementService::class)->employeeDetail($this->employee, $this->year);
    }

    #[Computed]
    public function history(): Collection
    {
        return app(LeaveStatementService::class)->timeline($this->employee, $this->year, [
            'leave_type_id' => $this->historyTypeId,
            'month' => $this->historyMonth,
            'entry_type' => $this->historyEntryType,
            'from' => $this->historyFrom,
            'to' => $this->historyTo,
        ]);
    }

    #[Computed]
    public function statement(): Collection
    {
        // An employee may have no balance in this year yet: no type to infer,
        // so an empty statement rather than an error.
        $typeId = $this->statementTypeId ?? data_get($this->balances->first(), 'leave_type.id');
        $type = $typeId ? LeaveType::withTrashed()->find($typeId) : null;

        return $type ? app(LeaveStatementService::class)->monthly($this->employee, $type, $this->year) : collect();
    }

    #[Computed]
    public function overrides(): Collection
    {
        return EmployeeLeaveOverride::with(['leaveType', 'leaveYear', 'creator'])
            ->where('employee_id', $this->employee->id)->latest('id')->get();
    }

    #[Computed]
    public function requests(): Collection
    {
        return LeaveRequest::with('leaveType')->where('employee_id', $this->employee->id)
            ->whereDate('start_date', '>=', $this->year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $this->year->ends_on->toDateString())
            ->latest('start_date')->limit(50)->get();
    }

    /**
     * Encashment history for this employee: every request and where it stands.
     * The paid days themselves are ENCASHMENT ledger entries (see History).
     *
     * @return Collection<int, LeaveEncashment>
     */
    #[Computed]
    public function encashments(): Collection
    {
        return LeaveEncashment::with(['leaveType', 'reviewer', 'financeReviewer'])
            ->where('employee_id', $this->employee->id)
            ->latest()->limit(50)->get();
    }

    /** Current approved-available figure for the chosen type, for the add/deduct/correct preview. */
    #[Computed]
    public function currentAvailable(): ?float
    {
        $balance = $this->formTypeId ? $this->balanceFor($this->formTypeId) : null;

        return $balance ? app(LeaveBalanceCalculator::class)->summary($balance)['approved_available'] : null;
    }

    public function openAction(string $action, ?int $leaveTypeId = null): void
    {
        $this->authorize($this->permissionFor($action));

        $this->resetErrorBag();
        $this->reset(['days', 'targetBalance', 'reason', 'internalNote', 'expiresOn', 'startDate', 'endDate', 'isHalfDay', 'attachment']);
        $this->action = $action;

        // No type passed and no balance to take one from: refuse with a
        // message shown on the page, never a 500. Nothing is provisioned here.
        $this->formTypeId = $leaveTypeId ?? data_get($this->balances->first(), 'leave_type.id');

        if (! $this->formTypeId) {
            $this->action = null;
            $this->addError('formTypeId', 'No leave balance exists for this employee for the selected leave year.');

            return;
        }

        $this->effectiveDate = Carbon::today()->between($this->year->starts_on, $this->year->ends_on)
            ? Carbon::today()->toDateString() : $this->year->starts_on->toDateString();
        $this->notifyEmployee = true;

        if ($action === 'carry_forward') {
            $years = app(LeaveYearResolver::class);
            $this->cfToYearId = $this->leaveYearId;
            $this->cfFromYearId = $years->previous($this->year)->id;
            $this->carryDays = '';
            unset($this->carryInfo);
        }
    }

    public function closeAction(): void
    {
        $this->action = null;
    }

    public function submitAction(): void
    {
        if ($this->action === null) {
            return;
        }

        $this->authorize($this->permissionFor($this->action));

        /** @var User $hr */
        $hr = Auth::user();

        // HR never manages their own leave from here.
        if ($hr->employee?->id === $this->employee->id) {
            $this->addError('reason', 'You cannot change your own leave balance.');

            return;
        }

        try {
            match ($this->action) {
                'add' => $this->doAdd($hr),
                'deduct' => $this->doDeduct($hr),
                'correct' => $this->doCorrect($hr),
                'override' => $this->doOverride($hr),
                'apply' => $this->doApply($hr),
                'carry_forward' => $this->doCarryForward($hr),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (\DomainException|\InvalidArgumentException|\RuntimeException $e) {
            $this->addError('form', $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $this->addError('form', 'The change could not be saved: '.$e->getMessage());

            return;
        }

        $this->action = null;
        unset($this->balances, $this->history, $this->statement, $this->overrides, $this->requests, $this->carryHistory, $this->carryAudit, $this->carryInfo);
    }

    public function startReverseCarryForward(int $transactionId): void
    {
        $this->authorize('manage_leave_carry_forward');

        $this->reverseTxId = $transactionId;
        $this->reverseReason = '';
        $this->resetErrorBag();
    }

    /** HR never changes their own leave from this screen — another HR user must. */
    private function refuseOwnRecord(): void
    {
        abort_if((int) $this->employee->user_id === (int) Auth::id(), 403, 'You cannot change your own leave.');
    }

    public function reverseCarryForward(): void
    {
        $this->authorize('manage_leave_carry_forward');
        $this->refuseOwnRecord();
        $this->validate(['reverseReason' => ['required', 'string', 'min:3', 'max:500']]);

        $tx = LeaveCarryForwardTransaction::where('employee_id', $this->employee->id)->findOrFail($this->reverseTxId);

        try {
            app(LeaveCarryForwardService::class)->reverse($tx, Auth::user(), $this->reverseReason);
            session()->flash('success', 'Carry forward reversed; the history keeps both entries.');
            $this->reverseTxId = null;
        } catch (\RuntimeException|\DomainException $e) {
            $this->addError('reverseReason', $e->getMessage());
        }

        unset($this->balances, $this->carryHistory, $this->carryAudit, $this->history);
    }

    private function doCarryForward(User $hr): void
    {
        $this->validate([
            'formTypeId' => ['required', 'exists:leave_types,id'],
            'cfFromYearId' => ['required', 'exists:leave_years,id'],
            'cfToYearId' => ['required', 'exists:leave_years,id', 'different:cfFromYearId'],
            'carryDays' => ['required', 'numeric', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['cfFromYearId' => 'from year', 'cfToYearId' => 'to year', 'carryDays' => 'carry forward days']);

        $type = LeaveType::findOrFail($this->formTypeId);
        $from = LeaveYear::findOrFail($this->cfFromYearId);
        $to = LeaveYear::findOrFail($this->cfToYearId);

        app(LeaveCarryForwardService::class)->applyForEmployee($this->employee, $type, $from, $to, (float) $this->carryDays, $this->reason, $hr);

        session()->flash('success', "Carried forward {$this->carryDays} day(s) of {$type->name} from {$from->label} into {$to->label}.");
    }

    public function revokeOverride(int $overrideId, string $why = 'Revoked by HR'): void
    {
        $this->authorize('override_leave_policy');
        $this->refuseOwnRecord();

        $override = EmployeeLeaveOverride::where('employee_id', $this->employee->id)->findOrFail($overrideId);

        try {
            app(EmployeeLeaveOverrideService::class)->revoke($override, Auth::user(), $why);
            session()->flash('success', 'Override revoked; the entitlement was recalculated.');
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        unset($this->balances, $this->overrides);
    }

    private function doAdd(User $hr): void
    {
        $this->validate([
            'formTypeId' => ['required', 'exists:leave_types,id'],
            'days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'addOnType' => ['required', 'in:'.implode(',', LeaveBalanceService::ADD_ON_TYPES)],
            'effectiveDate' => ['required', 'date'],
            'expiresOn' => ['nullable', 'date', 'after_or_equal:effectiveDate'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internalNote' => ['nullable', 'string', 'max:1000'],
        ]);

        // A plain correction lands in the adjustment bucket; every other kind
        // is its own add-on lot (with its own optional expiry).
        $category = in_array($this->addOnType, ['manual_adjustment', 'opening_balance_correction'], true)
            ? LeaveBalanceService::CATEGORY_ADJUSTMENT
            : LeaveBalanceService::CATEGORY_ADD_ON;

        $type = LeaveType::findOrFail($this->formTypeId);
        app(LeaveBalanceService::class)->adjust(
            $this->employee, $type, 'credit', (float) $this->days, $this->reason, '', $hr, $this->year->legacyYear(),
            $category, $this->addOnType, Carbon::parse($this->effectiveDate),
            $this->expiresOn ? Carbon::parse($this->expiresOn) : null, $this->internalNote ?: null,
        );

        $this->notify($type, 'add', (float) $this->days);
        session()->flash('success', "Added {$this->days} day(s) of {$type->name}.");
    }

    private function doDeduct(User $hr): void
    {
        $this->validate([
            'formTypeId' => ['required', 'exists:leave_types,id'],
            'days' => ['required', 'numeric', 'min:0.5', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internalNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $type = LeaveType::findOrFail($this->formTypeId);
        app(LeaveBalanceService::class)->adjust(
            $this->employee, $type, 'debit', (float) $this->days, $this->reason, '', $hr, $this->year->legacyYear(),
            internalNote: $this->internalNote ?: null,
        );

        $this->notify($type, 'deduct', (float) $this->days);
        session()->flash('success', "Deducted {$this->days} day(s) of {$type->name}.");
    }

    private function doCorrect(User $hr): void
    {
        $this->validate([
            'formTypeId' => ['required', 'exists:leave_types,id'],
            'targetBalance' => ['required', 'numeric', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internalNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $type = LeaveType::findOrFail($this->formTypeId);
        app(LeaveBalanceService::class)->setCorrectBalance(
            $this->employee, $type, (float) $this->targetBalance, $this->reason, '', $hr, $this->year->legacyYear(), $this->internalNote ?: null,
        );

        $this->notify($type, 'correct', 0);
        session()->flash('success', "{$type->name} balance corrected to {$this->targetBalance} day(s).");
    }

    private function doOverride(User $hr): void
    {
        $this->validate([
            'formTypeId' => ['required', 'exists:leave_types,id'],
            'overrideMode' => ['required', 'in:add,set'],
            'days' => ['required', 'numeric', $this->overrideMode === 'add' ? 'min:-365' : 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $type = LeaveType::findOrFail($this->formTypeId);
        app(EmployeeLeaveOverrideService::class)->create(
            $this->employee, $type, $this->overrideMode, (float) $this->days, $this->reason, $hr,
            $this->overrideThisYearOnly ? $this->year : null,
            $this->overrideThisYearOnly ? null : $this->year->starts_on->copy(),
        );

        session()->flash('success', "Override saved for {$type->name}; the entitlement was recalculated.");
    }

    private function doApply(User $hr): void
    {
        $this->validate([
            'formTypeId' => ['required', 'exists:leave_types,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'halfDayPeriod' => ['required_if:isHalfDay,true', 'in:first_half,second_half'],
            'paymentStatus' => ['required', 'in:paid,unpaid'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'internalNote' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $attachments = [];
        if ($this->attachment) {
            $attachments[] = [
                'type' => 'supporting_document',
                'path' => $this->attachment->store('leave-attachments', 'public'),
                'original_name' => $this->attachment->getClientOriginalName(),
                'mime_type' => $this->attachment->getMimeType(),
                'size' => $this->attachment->getSize(),
            ];
        }

        $type = LeaveType::findOrFail($this->formTypeId);
        app(LeaveService::class)->applyOnBehalf(
            $hr, $this->employee, $type, $this->startDate, $this->isHalfDay ? $this->startDate : $this->endDate, $this->reason,
            $this->isHalfDay, $this->isHalfDay ? $this->halfDayPeriod : null, $this->paymentStatus,
            $this->internalNote ?: null, $this->notifyEmployee, $attachments,
        );

        session()->flash('success', "{$type->name} applied on behalf of {$this->employee->user?->name}; it now follows the approval chain.");
    }

    private function notify(LeaveType $type, string $kind, float $days): void
    {
        if (! $this->notifyEmployee || ! $this->employee->user) {
            return;
        }

        $balance = $this->balanceFor($type->id);
        $available = $balance ? app(LeaveBalanceCalculator::class)->summary($balance->fresh())['approved_available'] : 0.0;

        $this->employee->user->notify(new LeaveBalanceChangedNotification($type->name, $kind, $days, $available, $this->reason));
    }

    private function balanceFor(int $leaveTypeId): ?LeaveBalance
    {
        return LeaveBalance::where('employee_id', $this->employee->id)->where('leave_type_id', $leaveTypeId)
            ->where('year', $this->year->legacyYear())->first();
    }

    private function permissionFor(string $action): string
    {
        return match ($action) {
            'add' => 'add_leave_balance',
            'deduct' => 'deduct_leave_balance',
            'correct' => 'correct_leave_balance',
            'override' => 'override_leave_policy',
            'apply' => 'apply_leave_on_behalf',
            'carry_forward' => 'manage_leave_carry_forward',
            default => abort(404),
        };
    }

    public function render()
    {
        return view('livewire.time-off.employee-leave-detail', [
            'leaveYears' => LeaveYear::orderByDesc('starts_on')->get(),
            'leaveTypes' => LeaveType::whereNull('deleted_at')->orderBy('name')->get(),
            'addOnTypes' => LeaveBalanceService::ADD_ON_TYPES,
            'entryTypes' => LeaveStatementService::LABELS,
        ]);
    }
}
