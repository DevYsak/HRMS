<?php

namespace App\Livewire\TimeOff;

use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeavePolicyEngine;
use App\Services\Leave\LeaveYearResolver;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The Leave V2 request drawer: Category → Details → Review → Submitted.
 *
 * Step 1 is the main category: Earned Leave (Paid Leave), Comp Off or Unpaid
 * Leave — and Special Leave only for an employee separately entitled to it.
 * Step 2, for Earned Leave, shows the one Earned Leave balance and asks for
 * the reason (Casual, Sick, Privilege, Personal, Other…). Reasons are labels
 * with their own validation; they never show or hold a balance — every reason
 * draws on the same Earned Leave pool. Then dates, half day, reason text and
 * attachment; step 3 reviews the balance before and after.
 *
 * Payroll treatment is never chosen here: the policy decides it
 * (LeaveRequestEvaluator), and LeaveService re-checks everything on submit.
 */
class RequestLeave extends Component
{
    use WithFileUploads;

    /** The main categories, in order; Special Leave appears only where separately entitled. */
    public const CATEGORIES = [LeaveCategory::EARNED, LeaveCategory::COMP_OFF, LeaveCategory::UNPAID, LeaveCategory::SPECIAL];

    public bool $open = false;

    /** 1 Category, 2 Details, 3 Review, 4 Submitted. */
    public int $step = 1;

    public string $category = '';

    /** The type requested: for Earned Leave, the reason (Sick, Casual…). */
    public ?int $leaveTypeId = null;

    public string $startDate = '';

    public string $endDate = '';

    public bool $isHalfDay = false;

    public string $halfDayPeriod = 'first_half';

    public string $reason = '';

    public string $remarks = '';

    /** @var TemporaryUploadedFile|null */
    public $attachment = null;

    public ?int $submittedId = null;

    #[On('open-request-leave')]
    public function openDrawer(?int $leaveTypeId = null, ?string $date = null): void
    {
        abort_unless(LeavePolicyEngine::enabled(), 404);

        $this->resetForm();
        $this->open = true;

        if ($date) {
            $this->startDate = $this->endDate = Carbon::parse($date)->toDateString();
        }

        if ($leaveTypeId) {
            foreach ($this->choices as $category => $choice) {
                $isPool = ($choice['balance_type_id'] ?? null) === $leaveTypeId;
                if ($isPool || collect($choice['types'])->contains('id', $leaveTypeId)) {
                    // "Request" on the Earned Leave balance opens it with the reason still to pick.
                    $this->chooseCategory($category);
                    $this->leaveTypeId = $isPool ? $this->leaveTypeId : $leaveTypeId;
                    $this->step = 2;
                    break;
                }
            }
        }
    }

    public function close(): void
    {
        $this->open = false;
        $this->resetForm();
    }

    public function chooseCategory(string $category): void
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            return;
        }

        $this->category = $category;
        $types = collect($this->choices[$category]['types'] ?? []);
        // One type (usually Comp Off and Unpaid): nothing to pick.
        $this->leaveTypeId = $types->count() === 1 ? $types->first()['id'] : null;
        $this->resetErrorBag();
    }

    public function updatedLeaveTypeId(): void
    {
        $this->resetErrorBag();
        if (! ($this->evaluation['allow_half_day'] ?? true)) {
            $this->isHalfDay = false;
        }
    }

    public function updatedStartDate(): void
    {
        if ($this->endDate === '' || ($this->startDate !== '' && $this->endDate < $this->startDate) || $this->isHalfDay) {
            $this->endDate = $this->startDate;
        }
    }

    public function updatedIsHalfDay(bool $value): void
    {
        if ($value) {
            $this->endDate = $this->startDate;
        }
    }

    public function next(): void
    {
        $this->resetErrorBag();

        if ($this->step === 1) {
            if ($this->category === '') {
                $this->addError('category', 'Choose a leave category.');

                return;
            }
            $this->step = 2;

            return;
        }

        if ($this->step === 2) {
            if ($this->selected === null) {
                $this->addError('leaveTypeId', $this->category === LeaveCategory::EARNED ? 'Choose a reason for your leave.' : 'Choose a leave type.');

                return;
            }
            $this->validate($this->detailRules(), [], $this->detailNames());
            $evaluation = $this->evaluation;

            if ($evaluation === null) {
                $this->addError('leaveTypeId', 'Choose a leave type.');

                return;
            }

            if ($evaluation['blocked']) {
                $this->addError('request', $evaluation['errors'][0]);

                return;
            }

            if ($evaluation['attachment_required'] && ! $this->attachment) {
                $this->addError('attachment', 'An attachment is required for this request.');

                return;
            }

            $this->step = 3;
        }
    }

    public function back(): void
    {
        $this->resetErrorBag();
        $this->step = max(1, $this->step - 1);
    }

    public function editStep(int $step): void
    {
        $this->step = max(1, min(2, $step));
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
    }

    public function submit(LeaveService $service): void
    {
        $this->resetErrorBag();
        $this->validate($this->detailRules(), [], $this->detailNames());

        $employee = Auth::user()->employee;
        $type = $this->leaveTypeId ? LeaveType::find($this->leaveTypeId) : null;

        if (! $employee || ! $type) {
            $this->addError('request', 'Your employee profile or the leave type could not be found. Contact HR.');

            return;
        }

        $attachments = $this->attachment ? [LeaveAttachment::storeUpload($this->attachment)] : [];

        try {
            $request = $service->submitRequest(
                $employee, $type, $this->startDate, $this->endDate, $this->reason,
                $this->isHalfDay, $this->isHalfDay ? $this->halfDayPeriod : null,
                // Ignored under the V2 engine: the policy decides payroll treatment.
                'paid', null, $this->remarks ?: null, $attachments,
            );
        } catch (\DomainException $e) {
            $this->addError('request', $e->getMessage());
            $this->step = 3;

            return;
        } catch (\Throwable $e) {
            Log::error('Leave V2 request submission failed.', ['employee_id' => $employee->id, 'message' => $e->getMessage()]);
            $this->addError('request', 'Unable to submit the leave request right now. Please try again.');

            return;
        }

        $this->submittedId = $request->id;
        $this->step = 4;
        $this->dispatch('leave-request-submitted', id: $request->id);
    }

    public function viewMyRequests(): void
    {
        $this->close();
        $this->dispatch('show-pending-upcoming');
    }

    /**
     * The main categories. Earned Leave carries its one balance and the
     * reasons the policy allows (labels — no balance beside them); Special
     * Leave and Comp Off list their own-balance types; Unpaid needs none.
     *
     * @return array<string, array{label: string, description: string, available: ?float, balance_type_id: ?int, balance: ?array<string, mixed>, types: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function choices(): array
    {
        $employee = Auth::user()->employee;
        if (! $employee) {
            return [];
        }

        $year = app(LeaveYearResolver::class)->forDate($this->startDate !== '' ? Carbon::parse($this->startDate) : Carbon::today());
        $balances = app(LeavePolicyEngine::class)->balances($employee, $year);
        $state = fn (array $row) => match (true) {
            $row['unlimited'] => 'unlimited',
            $row['available'] > 0 => 'available',
            default => 'not_available',
        };

        $choices = [];
        foreach (self::CATEGORIES as $category) {
            [$types, $balance] = match ($category) {
                LeaveCategory::EARNED => [
                    $balances['earned'] ? array_map(fn ($r) => $r + ['state' => 'reason'], $balances['reasons']) : [],
                    $balances['earned'],
                ],
                LeaveCategory::UNPAID => [array_map(fn ($r) => $r + ['state' => 'no_balance', 'description' => null], $balances['unpaid']['types']), null],
                // Special Leave / Comp Off types still offered for new requests.
                default => [array_values(array_map(fn ($r) => $r + ['state' => $state($r)], array_filter($balances[$category]['subtypes'], fn ($r) => $r['requestable']))), null],
            };

            if ($types === []) {
                continue;
            }

            $choices[$category] = [
                'label' => LeaveCategory::label($category),
                'description' => LeaveCategory::DESCRIPTIONS[$category],
                'available' => match ($category) {
                    LeaveCategory::UNPAID => null,
                    LeaveCategory::EARNED => $balance['unlimited'] ? null : $balance['available'],
                    default => $balances[$category]['available'],
                },
                'balance_type_id' => $balance['id'] ?? null,
                'balance' => $balance,
                'types' => $types,
            ];
        }

        return $choices;
    }

    /** The selected type: an Earned Leave reason (no balance of its own), or a type with its own balance. */
    #[Computed]
    public function selected(): ?array
    {
        return collect($this->choices[$this->category]['types'] ?? [])->firstWhere('id', $this->leaveTypeId);
    }

    /** What the request would do; null until a type and a start date are chosen. */
    #[Computed]
    public function evaluation(): ?array
    {
        $employee = Auth::user()->employee;
        $type = $this->leaveTypeId ? LeaveType::find($this->leaveTypeId) : null;

        if (! $employee || ! $type || ! $this->validDate($this->startDate)) {
            return null;
        }

        $end = $this->validDate($this->endDate) ? $this->endDate : $this->startDate;

        return app(LeavePolicyEngine::class)->evaluate($employee, $type, Carbon::parse($this->startDate), Carbon::parse($end), $this->isHalfDay);
    }

    #[Computed]
    public function submitted(): ?LeaveRequest
    {
        return $this->submittedId
            ? LeaveRequest::with('leaveType')->where('employee_id', Auth::user()->employee?->id)->find($this->submittedId)
            : null;
    }

    public function render()
    {
        return view('livewire.time-off.request-leave');
    }

    /** @return array<string, mixed> */
    private function detailRules(): array
    {
        return [
            'leaveTypeId' => ['required', 'integer', 'exists:leave_types,id'],
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'halfDayPeriod' => ['required_if:isHalfDay,true', 'in:first_half,second_half'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    private function detailNames(): array
    {
        return [
            'leaveTypeId' => 'leave type', 'startDate' => 'start date', 'endDate' => 'end date',
            'halfDayPeriod' => 'half', 'reason' => 'reason', 'remarks' => 'remarks', 'attachment' => 'attachment',
        ];
    }

    private function validDate(string $value): bool
    {
        return $value !== '' && strtotime($value) !== false;
    }

    private function resetForm(): void
    {
        $this->reset(['step', 'category', 'leaveTypeId', 'startDate', 'endDate', 'isHalfDay', 'halfDayPeriod', 'reason', 'remarks', 'attachment', 'submittedId']);
        $this->resetErrorBag();
        unset($this->choices, $this->selected, $this->evaluation, $this->submitted);
    }
}
