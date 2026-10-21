<?php

namespace App\Services\Leave;

use App\Enums\EmployeeStatus;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\WorkingDayResolver;
use App\Services\LeaveService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Combined Leave (Leave V2): ONE request over a date range where each
 * chargeable date is Full Day – Paid, Full Day – Unpaid, Half Day – Paid or
 * Half Day – Unpaid. Internally request_mode = combined, so it never collides
 * with statutory Special Leave.
 *
 *     Earned 8 · 10 Nov full paid, 11 full paid, 13 half paid, 15 full unpaid, 16 full unpaid
 *     → paid 2.5, unpaid 2, Earned after approval 5.5 (one −2.5 ledger debit)
 *
 * Read-only: it classifies dates, totals the allocation, checks it against
 * the policy and the balance the reason charges (LeaveBalanceSource — a
 * Casual or Sick reason charges the shared Earned Leave). LeaveService
 * stores it; the existing single debit and reservation use the header totals.
 */
class CombinedLeaveService
{
    /** HR's Combined Leave rules, as stored on leave_policies.combined_leave_rules. */
    public const RULE_DEFAULTS = [
        'enabled' => false,
        'allow_half_days' => true,
        'allow_half_paid' => true,
        'allow_half_unpaid' => true,
        'allow_sessions' => true,
        'count_weekly_offs' => false,
        'count_holidays' => false,
        'allow_mixed' => true,
        'allow_per_date_allocation' => true,
        // Stored for the future; V1 keeps one reason for the whole request.
        'allow_per_date_reason' => false,
        'excess_handling' => LeaveRequestEvaluator::EXCESS_BLOCK,
        'max_range_days' => 31,
        'max_consecutive_working_days' => null,
        // NULL = every reason the policy allows.
        'reason_type_ids' => null,
    ];

    public function __construct(
        private readonly LeavePolicyAssignmentResolver $assignments,
        private readonly LeaveBalanceSource $sources,
        private readonly LeaveRuleResolver $rules,
        private readonly LeaveBalanceCalculator $calculator,
        private readonly LeaveYearResolver $years,
        private readonly WorkingDayResolver $workingDays,
        private readonly HolidayResolver $holidays,
    ) {}

    /** @return array<string, mixed> */
    public static function rulesOf(?LeavePolicy $policy): array
    {
        $stored = $policy?->combined_leave_rules;

        return array_merge(self::RULE_DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::RULE_DEFAULTS) : []);
    }

    /** @return array{policy: ?LeavePolicy, rules: array<string, mixed>, enabled: bool} */
    public function rulesFor(Employee $employee, ?CarbonInterface $on = null): array
    {
        $policy = $this->assignments->policyFor($employee, $on);
        $rules = self::rulesOf($policy ? LeavePolicy::find($policy->id) : null);

        return ['policy' => $policy, 'rules' => $rules, 'enabled' => LeavePolicyEngine::enabled() && (bool) $rules['enabled']];
    }

    /**
     * The reasons a Combined Leave request can be given: the Earned Leave
     * reasons and the special leave the employee holds or is granted — read
     * from the policy and Leave Settings, never a fixed list. Only types
     * that are active, requestable and eligible for the employee.
     *
     * @return array<int, array{id: int, name: string, code: ?string, category: ?string}>
     */
    public function reasons(Employee $employee, LeaveYear $year): array
    {
        $balances = app(LeaveCategoryBalances::class)->forEmployee($employee, $year);
        $allowed = $this->rulesFor($employee, $year->starts_on->max(Carbon::today())->min($year->ends_on))['rules']['reason_type_ids'];

        return collect($balances['reasons'])
            ->map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'category' => LeaveCategory::EARNED])
            ->merge(collect($balances['special']['subtypes'])->filter(fn ($r) => $r['requestable'])
                ->map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'code' => $r['code'], 'category' => LeaveCategory::SPECIAL]))
            ->filter(fn ($r) => ! is_array($allowed) || $allowed === [] || in_array($r['id'], array_map('intval', $allowed), true))
            ->values()->all();
    }

    /**
     * One row per date of the range: what the date is for this employee and
     * whether leave can be charged on it.
     *
     * @param  array<string, mixed>  $rules
     * @return array<int, array{date: string, day: string, status: string, label: string, chargeable: bool, exclusion: ?string}>
     */
    public function dates(Employee $employee, CarbonInterface $start, CarbonInterface $end, array $rules): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();
        if ($end->lt($start)) {
            return [];
        }

        $holidays = $this->holidays->keyedForEmployee($employee, $start, $end);
        $shutdown = DecemberMandatoryDay::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->flip();

        $rows = [];
        for ($day = $start->copy(); $day->lte($end); $day = $day->addDay()) {
            $key = $day->toDateString();
            [$status, $label] = match (true) {
                $shutdown->has($key) => [LeaveRequestDay::STATUS_SHUTDOWN, 'Company shutdown'],
                $holidays->has($key) => [LeaveRequestDay::STATUS_HOLIDAY, $holidays->get($key)->name ?? 'Holiday'],
                $this->workingDays->isWeeklyOff($day) => [LeaveRequestDay::STATUS_WEEKLY_OFF, 'Weekly off'],
                default => [LeaveRequestDay::STATUS_WORKING, 'Working day'],
            };
            // Weekly offs and holidays are charged only when the policy says so;
            // a company shutdown never is.
            $chargeable = match ($status) {
                LeaveRequestDay::STATUS_WORKING => true,
                LeaveRequestDay::STATUS_WEEKLY_OFF => (bool) $rules['count_weekly_offs'],
                LeaveRequestDay::STATUS_HOLIDAY => (bool) $rules['count_holidays'],
                default => false,
            };

            $rows[] = [
                'date' => $key,
                'day' => $day->format('l'),
                'status' => $status,
                'label' => $label,
                'chargeable' => $chargeable,
                'exclusion' => $chargeable ? null : ($status === LeaveRequestDay::STATUS_WEEKLY_OFF ? 'Weekly off — no leave charged' : $label.' — no leave charged'),
            ];
        }

        return $rows;
    }

    /**
     * The default allocation: every chargeable date a full paid day.
     *
     * @param  array<int, array<string, mixed>>  $dates
     * @return array<string, array{type: string, session: ?string}>
     */
    public function defaultAllocations(array $dates): array
    {
        $allocations = [];
        foreach ($dates as $row) {
            if ($row['chargeable']) {
                $allocations[$row['date']] = ['type' => LeaveRequestDay::FULL_PAID, 'session' => null];
            }
        }

        return $allocations;
    }

    /**
     * Everything the drawer, the submit path and the approval need to know.
     *
     * @param  array<string, array{type?: string, session?: ?string}>  $allocations  date => allocation
     * @return array<string, mixed>
     */
    public function evaluate(
        Employee $employee,
        ?LeaveType $reason,
        CarbonInterface $start,
        CarbonInterface $end,
        array $allocations,
        ?int $excludeRequestId = null,
        ?CarbonInterface $today = null,
    ): array {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();
        $today = Carbon::parse($today ?? Carbon::today())->startOfDay();
        $context = $this->rulesFor($employee, $start);
        $rules = $context['rules'];
        $errors = [];
        $warnings = [];

        if (! $context['enabled']) {
            $errors[] = 'Combined Leave is not enabled in your leave policy.';
        }
        if ($end->lt($start)) {
            $errors[] = 'The end date is before the start date.';
            $end = $start->copy();
        }

        $calendar = (int) $start->diffInDays($end) + 1;
        if ($rules['max_range_days'] !== null && $calendar > (int) $rules['max_range_days']) {
            $errors[] = sprintf('Combined Leave can cover at most %d calendar days.', (int) $rules['max_range_days']);
        }

        $year = $this->years->forDate($start);
        if ($reason === null) {
            $errors[] = 'Choose a reason / leave type.';
        } elseif (! in_array($reason->id, array_column($this->reasons($employee, $year), 'id'), true)) {
            $errors[] = "{$reason->name} cannot be used for Combined Leave.";
        }

        // ── Rows ─────────────────────────────────────────────────────────────
        $rows = [];
        $paid = 0.0;
        $unpaid = 0.0;
        $halves = 0;
        $types = [];
        foreach ($this->dates($employee, $start, $end, $rules) as $date) {
            $choice = $allocations[$date['date']] ?? null;
            $type = $date['chargeable'] ? ($choice['type'] ?? LeaveRequestDay::FULL_PAID) : LeaveRequestDay::EXCLUDED;
            if (! in_array($type, array_merge(LeaveRequestDay::ALLOCATIONS, [LeaveRequestDay::EXCLUDED]), true)) {
                $type = LeaveRequestDay::FULL_PAID;
            }
            $session = LeaveRequestDay::isHalf($type) ? ($choice['session'] ?? null) : null;

            if (LeaveRequestDay::isHalf($type)) {
                $halves++;
                if (! $rules['allow_half_days']
                    || ($type === LeaveRequestDay::HALF_PAID && ! $rules['allow_half_paid'])
                    || ($type === LeaveRequestDay::HALF_UNPAID && ! $rules['allow_half_unpaid'])) {
                    $errors[] = LeaveRequestDay::LABELS[$type].' is not allowed by your leave policy.';
                }
                if ($rules['allow_sessions'] && ! in_array($session, ['first_half', 'second_half'], true)) {
                    $errors[] = 'Choose first half or second half for each half day.';
                }
                $session = $rules['allow_sessions'] ? $session : null;
            }

            [$p, $u] = LeaveRequestDay::UNITS[$type];
            $paid += $p;
            $unpaid += $u;
            if ($type !== LeaveRequestDay::EXCLUDED) {
                $types[$type] = true;
            }

            $rows[] = $date + [
                'allocation' => $type,
                'allocation_label' => LeaveRequestDay::LABELS[$type],
                'session' => $session,
                'paid_units' => $p,
                'unpaid_units' => $u,
            ];
        }

        $working = count(array_filter($rows, fn ($r) => $r['chargeable']));
        $charged = round($paid + $unpaid, 2);
        if ($working === 0) {
            $errors[] = 'These dates contain no working days — there is no leave to charge.';
        }

        $hasPaid = $paid > 0;
        $hasUnpaid = $unpaid > 0;
        if (! $rules['allow_mixed'] && $hasPaid && $hasUnpaid) {
            $errors[] = 'Your leave policy does not allow paid and unpaid days in one request.';
        }
        if (! $rules['allow_per_date_allocation'] && count($types) > 1) {
            $errors[] = 'Your leave policy needs the same allocation on every date of the request.';
        }
        if ($rules['max_consecutive_working_days'] !== null && $working > (int) $rules['max_consecutive_working_days']) {
            $errors[] = sprintf('Combined Leave allows at most %d working day(s).', (int) $rules['max_consecutive_working_days']);
        }

        // ── The reason's own rules ───────────────────────────────────────────
        $settings = $reason ? $this->rules->settings($employee, $reason, $start) : null;
        if ($reason && $settings) {
            if ($halves > 0 && ! $settings['allow_half_day']) {
                $errors[] = "{$reason->name} does not allow half days.";
            }
            if ($settings['allow_past_dates'] === false && $start->lt($today)) {
                $errors[] = "{$reason->name} cannot be requested for past dates.";
            }
            if ($settings['notice_days'] !== null && $start->gte($today) && $today->diffInDays($start) < $settings['notice_days']) {
                $errors[] = sprintf('%s needs %d day(s) notice.', $reason->name, $settings['notice_days']);
            }
            if ($settings['max_consecutive_days'] !== null && $charged > $settings['max_consecutive_days']) {
                $errors[] = sprintf('%s allows at most %d consecutive day(s).', $reason->name, $settings['max_consecutive_days']);
            }
            $status = $employee->status instanceof EmployeeStatus ? $employee->status->value : (string) $employee->status;
            if ($settings['probation_restricted'] && $status === EmployeeStatus::Probation->value) {
                $errors[] = "{$reason->name} is not available during probation.";
            }
            if (! $reason->isGenderEligible($employee->gender)) {
                $errors[] = "{$reason->name} is not available for this employee.";
            }
        }

        // ── Balance: the one the reason charges (Earned Leave for a reason) ──
        $charge = $reason ? $this->sources->for($employee, $reason, $start) : ['source' => LeaveBalanceSource::NONE, 'type' => null];
        $chargeType = $charge['type'];
        $balanceSettings = $chargeType ? $this->rules->settings($employee, $chargeType, $start) : null;
        $excess = $rules['excess_handling'] ?? LeaveRequestEvaluator::EXCESS_BLOCK;
        $summary = null;
        if ($chargeType) {
            $row = app(LeaveService::class)->balanceForDate($employee->id, $chargeType->id, $start);
            $summary = $row ? $this->calculator->summary($row, $excludeRequestId) : null;
        }
        $available = round((float) ($summary['available_to_request'] ?? 0), 2);
        $allowance = round($available + (float) ($balanceSettings['negative_balance_limit'] ?? 0), 2);
        $suggestion = null;

        if ($hasPaid && $charge['source'] === LeaveBalanceSource::MISSING_POOL) {
            $errors[] = 'Earned Leave is not set up in your leave policy yet. Contact HR.';
        } elseif ($hasPaid && $chargeType && $paid > $allowance + 0.001) {
            $short = round($paid - max(0, $allowance), 2);
            $errors[] = sprintf('Only %s days of %s are available. Change %s day(s) to Unpaid Leave or reduce the request.',
                max(0, $allowance) + 0, $chargeType->name, $short + 0);
            if ($excess === LeaveRequestEvaluator::EXCESS_CONVERT_TO_LWP) {
                // Proposed, never applied silently: the employee reviews it.
                $suggestion = $this->convertExcess($rows, $short);
            }
        }

        // ── Attachment and approval from the reason ──────────────────────────
        $attachmentAfter = $settings['attachment_after_days'] ?? null;
        $attachmentRequired = $attachmentAfter !== null ? $charged >= $attachmentAfter : (bool) ($settings['attachment_required'] ?? false);

        return [
            'request_mode' => 'combined',
            'reason' => $reason?->name,
            'reason_leave_type_id' => $reason?->id,
            'category' => $reason ? LeaveCategory::of($reason) : null,
            'policy' => $context['policy']?->name,
            'rules' => $rules,
            'rows' => $rows,
            'calendar_days' => $calendar,
            'excluded_days' => count(array_filter($rows, fn ($r) => ! $r['chargeable'])),
            'working_days' => $working,
            'paid_units' => round($paid, 2),
            'unpaid_units' => round($unpaid, 2),
            'half_day_count' => $halves,
            'half_day_units' => round($halves * 0.5, 2),
            'charged_units' => $charged,
            'balance_source' => $charge['source'],
            'balance_leave_type_id' => $hasPaid ? $chargeType?->id : null,
            'balance_type' => $chargeType?->name,
            'approved_available' => round((float) ($summary['approved_available'] ?? 0), 2),
            'pending_reserved' => round((float) ($summary['pending'] ?? 0), 2),
            'available' => $available,
            'remaining_after' => round($available - $paid, 2),
            'excess_handling' => $excess,
            'suggestion' => $suggestion,
            'attachment_required' => $attachmentRequired,
            'attachment_rule' => $attachmentAfter !== null ? sprintf('Required for %s+ day(s)', $attachmentAfter + 0) : null,
            'approval_steps' => $settings ? app(LeaveRequestEvaluator::class)->approvalSteps($settings, $charged) : ['manager'],
            'blocked' => $errors !== [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => $warnings,
        ];
    }

    /**
     * At approval: the request's paid units must still fit the balance it
     * charges (net of other pending requests, its own reservation excluded).
     * Never converted here — payroll treatment is the employee's to review.
     *
     * @throws \DomainException
     */
    public function assertPaidUnitsCovered(LeaveRequest $request): void
    {
        $paid = $request->paidDays();
        if ($paid <= 0 || ! $request->balance_leave_type_id) {
            return;
        }

        $employee = $request->employee;
        $type = LeaveType::withTrashed()->find($request->balance_leave_type_id);
        $start = Carbon::parse($request->start_date);
        $row = app(LeaveService::class)->balanceForDate($employee->id, $type->id, $start);
        $available = $row ? (float) $this->calculator->summary($row, $request->id)['available_to_request'] : 0.0;
        $limit = (float) ($this->rules->settings($employee, $type, $start)['negative_balance_limit'] ?? 0);

        if ($paid > $available + $limit + 0.001) {
            throw new \DomainException(sprintf(
                'Only %s days of %s are available for this request (%s paid). Ask the employee to change days to Unpaid Leave or reduce the request.',
                max(0, round($available + $limit, 2)) + 0, $type->name, $paid + 0,
            ));
        }
    }

    /**
     * The proposed conversion when paid days exceed the balance: the latest
     * paid units become unpaid until the paid total fits.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{type: string, session: ?string}>
     */
    public function convertExcess(array $rows, float $short): array
    {
        $allocations = [];
        foreach ($rows as $row) {
            if ($row['chargeable']) {
                $allocations[$row['date']] = ['type' => $row['allocation'], 'session' => $row['session']];
            }
        }

        foreach (array_reverse($rows) as $row) {
            if ($short <= 0.001) {
                break;
            }
            $type = $allocations[$row['date']]['type'] ?? null;
            if ($type === LeaveRequestDay::FULL_PAID) {
                // A full paid day becomes a full unpaid day, even when only half
                // a day is short: there is no "half paid + half unpaid" date.
                $allocations[$row['date']]['type'] = LeaveRequestDay::FULL_UNPAID;
                $short -= 1.0;
            } elseif ($type === LeaveRequestDay::HALF_PAID) {
                $allocations[$row['date']]['type'] = LeaveRequestDay::HALF_UNPAID;
                $short -= 0.5;
            }
        }

        return $allocations;
    }
}
