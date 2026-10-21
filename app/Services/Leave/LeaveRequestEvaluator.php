<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeavePolicyRule;
use App\Models\LeaveType;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\LeaveService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What a leave request would do, before and when it is submitted (Leave V2).
 *
 * The one place that answers: how many days are charged and why, which
 * balance it draws on (LeaveBalanceSource: a Casual, Sick or Personal reason
 * charges the one shared Earned Leave balance; special leave and Comp Off
 * their own), how much is paid and how much becomes LWP, whether policy
 * blocks it, whether an attachment is needed, and who must approve it.
 *
 * Validation (half day, notice, attachment, consecutive days, approval) comes
 * from the reason's own rule — Sick may need a certificate after 2 days.
 * Balance handling (excess → block or LWP, negative limit) comes from the
 * balance it charges. The request drawer, the submit path and the Leave
 * Assistant all read this, so they cannot disagree.
 *
 * Read-only: it never writes a balance, a ledger entry or a request.
 */
class LeaveRequestEvaluator
{
    public const EXCESS_BLOCK = 'block';

    public const EXCESS_CONVERT_TO_LWP = 'convert_to_lwp';

    public const TREATMENT_EXCLUDED = 'excluded';

    public const TREATMENT_COUNTED = 'counted';

    public function __construct(
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveBalanceSource $sources,
    ) {}

    /**
     * @return array{
     *     category: ?string, leave_type_id: int, leave_type: string, reason_type: string,
     *     balance_source: string, balance_leave_type_id: ?int, balance_type: ?string,
     *     policy: ?string, policy_source: string, year: array{label: string, starts_on: string, ends_on: string},
     *     calendar_days: int, weekly_off_days: int, holiday_days: int, shutdown_days: int, holidays: array<int, string>,
     *     charged_days: float, uses_balance: bool, unlimited: bool, balance: ?array<string, mixed>,
     *     available: ?float, paid_days: float, unpaid_days: float, lwp_days: float, remaining_after: ?float,
     *     excess_handling: string, blocked: bool, errors: array<int, string>, warnings: array<int, string>,
     *     attachment_required: bool, attachment_rule: ?string, approval_steps: array<int, string>, allow_half_day: bool
     * }
     */
    public function evaluate(
        Employee $employee,
        LeaveType $type,
        CarbonInterface $start,
        CarbonInterface $end,
        bool $isHalfDay = false,
        ?int $excludeRequestId = null,
        ?CarbonInterface $today = null,
    ): array {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();
        $today = Carbon::parse($today ?? Carbon::today())->startOfDay();

        $settings = $this->rules->settings($employee, $type, $start);
        $policy = $this->assignments->resolve($employee, $start);
        $category = LeaveCategory::of($type);
        $year = $this->years->forDate($start);
        $errors = [];
        $warnings = [];

        // Which balance this charges, and that balance's own settings.
        $charge = $this->sources->forPool($type, $this->sources->poolOf($policy['policy']));
        $chargeType = $charge['type'];
        $balanceSettings = $chargeType && $chargeType->id !== $type->id
            ? $this->rules->settings($employee, $chargeType, $start)
            : $settings;

        if ($charge['source'] === LeaveBalanceSource::MISSING_POOL) {
            $errors[] = 'Earned Leave is not set up in your leave policy yet. Contact HR.';
        } elseif ($charge['source'] === LeaveBalanceSource::EARNED_POOL && $settings['rule_id'] === null) {
            $errors[] = "{$type->name} is not one of the leave reasons in your leave policy.";
        }

        if ($end->lt($start)) {
            $errors[] = 'The end date is before the start date.';
            $end = $start->copy();
        }

        if ($isHalfDay && ! $start->isSameDay($end)) {
            $errors[] = 'A half-day leave is a single day — set the end date to the start date.';
        }

        if ($isHalfDay && ! $settings['allow_half_day']) {
            $errors[] = "{$type->name} does not allow half-day requests.";
        }

        $days = $this->countDays($employee, $type, $settings, $start, $end, $isHalfDay);

        if ($days['charged'] <= 0) {
            $errors[] = 'These dates contain no working days — there is no leave to charge.';
        }

        // ── Policy timing and eligibility ────────────────────────────────────
        if ($settings['allow_past_dates'] === false && $start->lt($today)) {
            $errors[] = "{$type->name} cannot be requested for past dates.";
        } elseif ($settings['past_days_limit'] !== null && $start->lt($today->copy()->subDays($settings['past_days_limit']))) {
            $errors[] = sprintf('%s can be requested at most %d day(s) in the past.', $type->name, $settings['past_days_limit']);
        }

        if ($settings['future_days_limit'] !== null && $start->gt($today->copy()->addDays($settings['future_days_limit']))) {
            $errors[] = sprintf('%s can be requested at most %d day(s) ahead.', $type->name, $settings['future_days_limit']);
        }

        if ($settings['notice_days'] !== null && $start->gte($today) && $today->diffInDays($start) < $settings['notice_days']) {
            $errors[] = sprintf('%s needs %d day(s) notice.', $type->name, $settings['notice_days']);
        }

        if ($settings['max_consecutive_days'] !== null && $days['charged'] > $settings['max_consecutive_days']) {
            $errors[] = sprintf('%s allows at most %d consecutive day(s).', $type->name, $settings['max_consecutive_days']);
        }

        $status = $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;
        if ($settings['probation_restricted'] && $status === EmployeeStatus::Probation->value) {
            $errors[] = "{$type->name} is not available during probation.";
        }
        if ($settings['notice_period_restricted'] && $status === EmployeeStatus::NoticePeriod->value) {
            $errors[] = "{$type->name} is not available during the notice period.";
        }
        if (! $type->isGenderEligible($employee->gender)) {
            $errors[] = "{$type->name} is not available for this employee.";
        }

        // ── Balance: the one it charges (Earned Leave pool, or its own) ──────
        $usesBalance = $chargeType !== null;
        $unlimited = $usesBalance && $balanceSettings['accrual_method'] === LeavePolicyRule::ACCRUAL_UNLIMITED;
        $excess = $balanceSettings['excess_handling'] ?? self::EXCESS_BLOCK;
        $balanceName = $chargeType?->name ?? $type->name;
        $balance = null;
        $available = null;
        $paid = 0.0;
        $unpaid = 0.0;
        $lwp = 0.0;

        if ($category === LeaveCategory::UNPAID) {
            $unpaid = $days['charged'];
        } elseif (! $usesBalance) {
            // No balance to charge (Earned Leave not set up): blocked above.
        } elseif ($unlimited) {
            $paid = $days['charged'];
        } else {
            $row = app(LeaveService::class)->balanceForDate($employee->id, $chargeType->id, $start);
            $balance = $row ? $this->calculator->summary($row, $excludeRequestId) : null;
            $available = round((float) ($balance['available_to_request'] ?? 0), 2);
            $allowance = round($available + (float) ($balanceSettings['negative_balance_limit'] ?? 0), 2);

            if ($days['charged'] <= $allowance) {
                $paid = $days['charged'];
            } elseif ($excess === self::EXCESS_CONVERT_TO_LWP) {
                $paid = max(0.0, $allowance);
                $lwp = round($days['charged'] - $paid, 2);
                $warnings[] = sprintf('%s day(s) will be paid from %s and %s day(s) will be Leave Without Pay.', $paid + 0, $balanceName, $lwp + 0);
            } else {
                $errors[] = sprintf('You have only %s %s day(s) available.', max(0, $available) + 0, $balanceName);
            }
        }

        // ── Attachment ───────────────────────────────────────────────────────
        $attachmentAfter = $settings['attachment_after_days'];
        $attachmentRequired = $attachmentAfter !== null
            ? $days['charged'] >= $attachmentAfter
            : (bool) $settings['attachment_required'];
        $attachmentRule = match (true) {
            $attachmentAfter !== null => sprintf('Required for %s+ day(s)', $attachmentAfter + 0),
            (bool) $settings['attachment_required'] => 'Required',
            default => null,
        };

        return [
            'category' => $category,
            'leave_type_id' => $type->id,
            'leave_type' => $type->name,
            // The reason is the type requested; the balance is the one charged.
            'reason_type' => $type->name,
            'balance_source' => $charge['source'],
            'balance_leave_type_id' => $chargeType?->id,
            'balance_type' => $chargeType?->name,
            'policy' => $policy['policy']?->name,
            'policy_source' => $policy['source_label'],
            'year' => [
                'label' => $year->label,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
            ],
            'calendar_days' => $days['calendar'],
            'weekly_off_days' => $days['weekly_off'],
            'holiday_days' => $days['holiday'],
            'shutdown_days' => $days['shutdown'],
            'holidays' => $days['holiday_names'],
            'charged_days' => $days['charged'],
            'uses_balance' => $usesBalance && ! $unlimited,
            'unlimited' => $unlimited,
            'balance' => $balance,
            'available' => $available,
            'paid_days' => round($paid, 2),
            'unpaid_days' => round($unpaid, 2),
            'lwp_days' => round($lwp, 2),
            'remaining_after' => $available !== null ? round($available - $paid, 2) : null,
            'excess_handling' => $excess,
            'blocked' => $errors !== [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
            'attachment_required' => $attachmentRequired,
            'attachment_rule' => $attachmentRule,
            'approval_steps' => $this->approvalSteps($settings, $days['charged']),
            'allow_half_day' => (bool) $settings['allow_half_day'],
        ];
    }

    /**
     * The approval steps the policy requires for a request of this length.
     * Default: the reporting manager alone (canonical first approver).
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    public function approvalSteps(array $settings, float $days): array
    {
        $workflow = $settings['approval_workflow'] ?? null;
        $workflow = is_string($workflow) ? json_decode($workflow, true) : $workflow;
        $steps = is_array($workflow['steps'] ?? null) && $workflow['steps'] !== [] ? $workflow['steps'] : ['manager'];

        $conditions = collect(is_array($workflow['conditions'] ?? null) ? $workflow['conditions'] : [])
            ->filter(fn ($c) => is_array($c) && isset($c['min_days']) && is_array($c['steps'] ?? null) && $c['steps'] !== [])
            ->sortByDesc(fn ($c) => (float) $c['min_days']);

        foreach ($conditions as $condition) {
            if ($days >= (float) $condition['min_days']) {
                $steps = $condition['steps'];
                break;
            }
        }

        // Department Head as a separate stage is not routed yet; manager and HR are.
        $allowed = ['manager', 'hr'];

        return array_values(array_unique(array_filter($steps, fn ($s) => in_array($s, $allowed, true)))) ?: ['manager'];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{calendar: int, weekly_off: int, holiday: int, shutdown: int, holiday_names: array<int, string>, charged: float}
     */
    public function countDays(Employee $employee, LeaveType $type, array $settings, CarbonInterface $start, CarbonInterface $end, bool $isHalfDay = false): array
    {
        $workingDays = app(WorkingDayResolver::class);
        $holidayResolver = app(HolidayResolver::class);

        $holidays = $holidayResolver->keyedForEmployee($employee, Carbon::parse($start), Carbon::parse($end));

        $shutdown = DecemberMandatoryDay::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($d) => Carbon::parse($d->date)->toDateString());

        $calendar = (int) $start->diffInDays($end) + 1;
        $sandwich = (bool) $type->is_sandwich_applicable && $calendar >= max(1, (int) $type->sandwich_min_days);
        $weekendCounted = $sandwich || ($settings['weekend_treatment'] ?? null) === self::TREATMENT_COUNTED;
        $holidayCounted = ($settings['holiday_treatment'] ?? null) === self::TREATMENT_COUNTED;

        $weeklyOff = 0;
        $holidayCount = 0;
        $shutdownCount = 0;
        $charged = 0.0;
        $names = [];

        for ($day = Carbon::parse($start); $day->lte($end); $day = $day->addDay()) {
            $key = $day->toDateString();

            if ($shutdown->has($key)) {
                // Company shutdown is never drawn from a balance.
                $shutdownCount++;

                continue;
            }

            if ($holidays->has($key)) {
                $holidayCount++;
                $names[] = $holidays->get($key)->name;
                if ($holidayCounted) {
                    $charged++;
                }

                continue;
            }

            if ($workingDays->isWeeklyOff($day)) {
                $weeklyOff++;
                if ($weekendCounted) {
                    $charged++;
                }

                continue;
            }

            $charged++;
        }

        if ($isHalfDay) {
            $charged = $charged > 0 ? 0.5 : 0.0;
        }

        return [
            'calendar' => $calendar,
            'weekly_off' => $weeklyOff,
            'holiday' => $holidayCount,
            'shutdown' => $shutdownCount,
            'holiday_names' => $names,
            'charged' => (float) $charged,
        ];
    }
}
