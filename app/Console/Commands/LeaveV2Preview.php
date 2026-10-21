<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveLedgerEntry;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyRule;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveBalanceSource;
use App\Services\Leave\LeaveCategory;
use App\Services\Leave\LeaveCategoryBalances;
use App\Services\Leave\LeavePolicyAssignmentResolver;
use App\Services\Leave\LeavePolicyCap;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Leave V2 migration preview — READ ONLY.
 *
 *  1. Ledger reconciliation: every balance in the leave year, current figures
 *     next to the balance rebuilt from the ledger, with its V2 role. Only an
 *     exact zero difference RECONCILES; anything else is STOP. Casual, Sick,
 *     Annual… rows are never V2 balances: they show as legacy paid leave.
 *  2. Employees: Earned Leave (the one shared balance), Comp Off, Special
 *     Leave, legacy paid leave and how their rows reconcile.
 *  3. Policies: the Earned Leave entitlement against the annual cap.
 *  4. Earned Leave usage by reason — reporting only, never a balance.
 *  5. Requests vs ledger: every approved paid request whose expected ledger
 *     usage differs from the usage actually posted, with the reason.
 *
 * Nothing is written: the run is wrapped in a transaction that is always
 * rolled back, so even a mistaken model event cannot persist.
 */
#[Signature('leave:v2-preview
    {--year= : Leave year label, e.g. 2026/27 (defaults to the current leave year)}
    {--employee=* : Limit to these employee ids}
    {--csv= : Also write the balance rows to this CSV path}
    {--only-problems : List only balance rows that do not reconcile}')]
#[Description('Read-only Leave V2 reconciliation preview: Earned Leave, Comp Off, Special and legacy balances against the ledger')]
class LeaveV2Preview extends Command
{
    public const MISMATCH_LEGACY = 'expected legacy behaviour';

    public const MISMATCH_MISSING_LEDGER = 'missing historical ledger entry';

    public const MISMATCH_SPECIAL = 'special leave excluded from ledger usage';

    public const MISMATCH_CALENDAR_YEAR = 'old calendar-year bug';

    public const MISMATCH_COMPATIBILITY = 'another known compatibility reason';

    public const MISMATCH_UNEXPLAINED = 'UNEXPLAINED — investigate';

    public const ROLE_EARNED = 'Earned Leave balance';

    public const ROLE_SPECIAL = 'Special Leave';

    public const ROLE_COMP_OFF = 'Comp Off';

    public const ROLE_UNPAID = 'Unpaid (no balance needed)';

    public const ROLE_LEGACY = 'Legacy paid (historical only)';

    public const ROLE_OTHER_PAID = 'Other paid (active, uncategorised)';

    public const ROLE_NOT_LEAVE = 'Not leave (no V2 balance)';

    public function handle(LeaveYearResolver $years): int
    {
        $year = $this->option('year')
            ? LeaveYear::where('label', $this->option('year'))->first()
            : $years->current();

        if (! $year) {
            $this->error('Unknown leave year.');

            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $report = $this->collect($year);
        } finally {
            DB::rollBack();
        }

        $this->info("Leave V2 preview — {$year->label} ({$year->starts_on->toDateString()} – {$year->ends_on->toDateString()}). READ ONLY: nothing was written.");

        // 1. Ledger reconciliation
        $this->newLine();
        $this->line('<comment>Ledger reconciliation</comment>');
        $rows = $report['balances'];
        $shown = $this->option('only-problems') ? array_values(array_filter($rows, fn ($r) => $r['status'] !== 'RECONCILES')) : $rows;
        $this->table(
            ['Employee', 'Type', 'V2 role', 'Opening', 'Carry fwd', 'Accrued', 'Used', 'Pending', 'Available (now)', 'Ledger available', 'Difference', 'Status'],
            array_map(fn ($r) => [
                $r['employee'], $r['type'], $r['role'],
                $r['opening'], $r['carry_forward'], $r['accrued'], $r['used'], $r['pending'],
                $r['available_now'], $r['ledger_available'] ?? '—', $r['difference'] ?? '—', $r['status'],
            ], $shown),
        );

        // 2. Employees
        $this->line('<comment>Employees under V2</comment>');
        $this->table(
            ['Employee', 'Policy', 'Policy source', 'Earned Leave', 'Comp Off', 'Special Leave', 'Legacy paid leave', 'Ledger'],
            array_map(fn ($e) => [
                $e['employee'], $e['policy'] ?? 'none', $e['source'],
                $e['earned'], $e['comp_off'], $e['special'],
                $e['legacy_rows'] ? $e['legacy'].' ('.$e['legacy_rows'].' rows, historical)' : '0',
                $e['reconciled'].'/'.$e['rows'].' reconcile',
            ], $report['employees']),
        );

        // 3. Policies
        $this->line('<comment>Annual policy validation</comment>');
        foreach ($report['policies'] as $policy) {
            $this->line(sprintf('  %s — Earned Leave balance: %s · Allocated %s / %s%s', $policy['name'], $policy['earned_type'] ?? 'not set',
                $policy['allocated'] + 0, $policy['cap'] === null ? 'no cap set' : $policy['cap'] + 0, $policy['over'] ? '  ← OVER CAP' : ''));
            $this->line('    Reasons: '.($policy['reasons'] ?: 'none'));
        }

        // 4. Usage by reason (reporting only)
        $this->newLine();
        $this->line('<comment>Earned Leave usage by reason (reporting only — not balances)</comment>');
        if ($report['usage_by_reason'] === []) {
            $this->line('  No approved paid leave in this year.');
        } else {
            $this->table(
                ['Reason', 'Charged to Earned Leave (V2)', 'Historical (own legacy balance)', 'Maps to Earned Leave'],
                array_map(fn ($u) => [$u['reason'], $u['v2'], $u['historical'], $u['maps']], $report['usage_by_reason']),
            );
        }

        // 5. Requests vs ledger
        $this->newLine();
        $this->line('<comment>Requests vs ledger</comment>');
        $mismatches = $report['mismatches'];
        if ($mismatches !== []) {
            $this->table(
                ['Employee', 'Request ID', 'Leave type', 'Dates', 'Approved days', 'Paid days', 'Expected ledger usage', 'Actual ledger usage', 'Classification', 'Reason'],
                array_map(fn ($m) => [$m['employee'], $m['request'], $m['type'], $m['dates'], $m['approved_days'], $m['paid_days'], $m['expected'], $m['actual'], $m['classification'], $m['reason']], $mismatches),
            );
        }

        // Summary
        $counts = array_count_values(array_column($rows, 'status'));
        $roles = array_count_values(array_column($rows, 'role'));
        $this->newLine();
        $this->line('<comment>Summary</comment>');
        $this->line('  Ledger reconciliation: '.count($rows).' rows · '.collect($counts)->map(fn ($n, $s) => "{$s} {$n}")->implode(' · '));
        $this->line('  Earned Leave balances: '.($roles[self::ROLE_EARNED] ?? 0).' rows'
            .' · Comp Off: '.($roles[self::ROLE_COMP_OFF] ?? 0).' rows');
        foreach ($report['policies'] as $policy) {
            $this->line(sprintf('  Annual policy validation — %s: Earned Leave allocated %s / %s', $policy['name'], $policy['allocated'] + 0, $policy['cap'] === null ? 'no cap set' : $policy['cap'] + 0));
        }
        $this->line('  Special leave excluded: '.$report['special_types'].' types ('.($roles[self::ROLE_SPECIAL] ?? 0).' balance rows, outside Earned Leave and the annual cap)');
        $this->line('  Legacy paid balances: '.($roles[self::ROLE_LEGACY] ?? 0).' rows (historical only — Casual, Sick, Annual… kept readable, never added to Earned Leave)');
        $this->line('  Other paid (active, uncategorised): '.($roles[self::ROLE_OTHER_PAID] ?? 0).' rows');
        $this->line(sprintf('  Request-vs-ledger mismatches: %d (%s day(s)) — approved paid days %s vs ledger usage %s',
            count($mismatches), array_sum(array_column($mismatches, 'difference')) + 0,
            $report['requests']['approved_paid_days'], $report['requests']['ledger_usage_days']));
        foreach (collect($mismatches)->groupBy('classification') as $class => $group) {
            $this->line(sprintf('    %s: %d request(s), %s day(s)', $class, $group->count(), $group->sum('difference') + 0));
        }
        $this->line('  Uncategorised types (not offered in the V2 request flow until HR categorises them): '.($report['uncategorised'] ?: 'none'));
        $c = $report['combined'];
        $this->line(sprintf('  Combined Leave requests: %d (approved %d) · paid %s · unpaid %s · half days %d · header vs day rows mismatches: %d',
            $c['requests'], $c['approved'], $c['paid'] + 0, $c['unpaid'] + 0, $c['half_days'], count($c['mismatches'])));
        foreach ($c['mismatches'] as $m) {
            $this->line('    '.$m);
        }
        $this->newLine();
        $this->line('Rollback: V2 is additive. Turning LEAVE_POLICY_ENGINE_V2 off restores the current behaviour; the V2 columns and table can be dropped with `php artisan migrate:rollback --step=4` without touching existing rows.');

        if ($path = $this->option('csv')) {
            $fh = fopen($path, 'w');
            fputcsv($fh, array_keys($rows[0] ?? ['employee' => null]));
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->line("CSV written to {$path}");
        }

        // Mismatches with a known, explained cause are informational; a missing
        // or unexplained ledger entry blocks, like a balance that does not reconcile.
        // A Combined request whose header does not equal its day rows blocks too.
        $blocking = count($rows) - ($counts['RECONCILES'] ?? 0) + count($report['combined']['mismatches'])
            + count(array_filter($mismatches, fn ($m) => in_array($m['classification'], [self::MISMATCH_MISSING_LEDGER, self::MISMATCH_UNEXPLAINED], true)));

        return $blocking === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function collect(LeaveYear $year): array
    {
        $calculator = app(LeaveBalanceCalculator::class);
        $assignments = app(LeavePolicyAssignmentResolver::class);
        $sources = app(LeaveBalanceSource::class);
        $categoryBalances = app(LeaveCategoryBalances::class);
        $on = now()->lt($year->starts_on) ? $year->starts_on : (now()->gt($year->ends_on) ? $year->ends_on : now());
        $only = $this->option('employee');

        $balances = LeaveBalance::with(['employee.user', 'leaveType' => fn ($q) => $q->withTrashed()])
            ->where('leave_year_id', $year->id)
            ->when($only, fn ($q, $ids) => $q->whereIn('employee_id', $ids))
            ->orderBy('employee_id')->orderBy('leave_type_id')
            ->get();

        $ledgerSums = LeaveLedgerEntry::where('leave_year_id', $year->id)
            ->selectRaw('employee_id, leave_type_id, SUM(days) as total')
            ->groupBy('employee_id', 'leave_type_id')
            ->get()
            ->keyBy(fn ($r) => $r->employee_id.'-'.$r->leave_type_id);

        $resolved = [];
        $ruleDays = [];
        $policyFor = function (Employee $employee) use ($assignments, $sources, $on, &$resolved, &$ruleDays) {
            if (! isset($resolved[$employee->id])) {
                $policy = $assignments->resolve($employee, $on);
                $resolved[$employee->id] = $policy + ['pool' => $sources->poolOf($policy['policy'])];
                if ($policy['policy'] && ! isset($ruleDays[$policy['policy']->id])) {
                    $ruleDays[$policy['policy']->id] = LeavePolicyRule::where('leave_policy_id', $policy['policy']->id)->where('is_active', true)->pluck('fixed_days', 'leave_type_id');
                }
            }

            return $resolved[$employee->id];
        };

        $rows = [];
        $employees = [];
        foreach ($balances as $balance) {
            $s = $calculator->summary($balance);
            $type = $balance->leaveType;
            $context = $balance->employee ? $policyFor($balance->employee) : ['policy' => null, 'source_label' => '—', 'pool' => null];
            $rules = $context['policy'] ? ($ruleDays[$context['policy']->id] ?? collect()) : collect();
            $role = $type ? $this->role($type, $context['pool'], $rules, $categoryBalances, $sources) : self::ROLE_LEGACY;
            $ledger = $ledgerSums->get($balance->employee_id.'-'.$balance->leave_type_id);
            $ledgerAvailable = $balance->isLedgerBacked() ? round((float) ($ledger->total ?? 0), 2) : null;
            $difference = $ledgerAvailable !== null ? round($s['approved_available'] - $ledgerAvailable, 2) : null;
            $status = match (true) {
                $ledgerAvailable === null => 'STOP: not on ledger',
                abs($difference) > 0.001 => 'STOP: difference',
                default => 'RECONCILES',
            };

            $rows[] = [
                'employee' => ($balance->employee?->user?->name ?? '#'.$balance->employee_id).' ('.($balance->employee?->employee_id ?? '?').')',
                'type' => trim(($type?->code ?? '').' '.($type?->name ?? '#'.$balance->leave_type_id)).($type?->trashed() ? ' [retired]' : ''),
                'role' => $role,
                'opening' => $s['opening'],
                'carry_forward' => $s['carry_forward'],
                'accrued' => round($s['base'] + $s['accrued'], 2),
                'used' => $s['used'],
                'pending' => $s['pending'],
                'available_now' => $s['approved_available'],
                'ledger_available' => $ledgerAvailable,
                'difference' => $difference,
                'status' => $status,
            ];

            $key = $balance->employee_id;
            $employees[$key] ??= [
                'employee' => end($rows)['employee'],
                'policy' => $context['policy']?->name,
                'source' => $context['source_label'],
                'earned' => $context['pool'] ? 'no balance yet' : 'not set up',
                'comp_off' => 0.0, 'special' => 0.0, 'legacy' => 0.0, 'legacy_rows' => 0,
                'rows' => 0, 'reconciled' => 0,
            ];
            $e = &$employees[$key];
            $e['rows']++;
            $e['reconciled'] += $status === 'RECONCILES' ? 1 : 0;
            match ($role) {
                self::ROLE_EARNED => $e['earned'] = $s['available_to_request'],
                self::ROLE_COMP_OFF => $e['comp_off'] = round($e['comp_off'] + $s['available_to_request'], 2),
                self::ROLE_SPECIAL => $e['special'] = round($e['special'] + $s['available_to_request'], 2),
                self::ROLE_LEGACY => [$e['legacy'], $e['legacy_rows']] = [round($e['legacy'] + $s['approved_available'], 2), $e['legacy_rows'] + 1],
                default => null,
            };
            unset($e);
        }

        // Every policy an employee resolves to, plus any HR has given a cap, an
        // Earned Leave balance or rules.
        $resolvedIds = collect($resolved)->pluck('policy.id')->filter()->unique();
        $cap = app(LeavePolicyCap::class);
        $policies = LeavePolicy::where('is_active', true)->orderBy('name')->get()
            ->filter(fn (LeavePolicy $p) => $resolvedIds->contains($p->id) || $p->annual_paid_leave_cap !== null || $p->earned_leave_type_id !== null
                || LeavePolicyRule::where('leave_policy_id', $p->id)->where('is_active', true)->exists())
            ->map(fn (LeavePolicy $p) => ['name' => $p->name, 'earned_type' => $sources->poolOf($p)?->name, 'reasons' => $this->reasonsOf($p, $sources)] + $cap->summary($p))
            ->values()->all();

        $types = LeaveType::withTrashed()->get();

        return [
            'balances' => $rows,
            'employees' => array_values($employees),
            'policies' => $policies,
            'special_types' => $types->whereNull('deleted_at')->filter(fn ($t) => LeaveCategory::of($t) === LeaveCategory::SPECIAL && ! $t->consumes_earned_leave)->count(),
            'uncategorised' => $types->whereNull('deleted_at')
                ->filter(fn ($t) => LeaveCategory::of($t) === null && $t->isRequestable() && ! in_array((string) $t->category, LeaveCategory::NOT_REQUESTABLE, true))
                ->pluck('name')->unique()->implode(', '),
            'usage_by_reason' => $this->usageByReason($year, $only, $resolved),
            'mismatches' => $this->requestMismatches($year, $only),
            'requests' => $this->requestTotals($year, $only),
            'combined' => $this->combinedSummary($year, $only),
        ];
    }

    /**
     * Combined Leave requests in the year: totals from their day rows, and
     * any whose header (days, LWP) does not equal the sum of its day rows.
     *
     * @param  array<int, int|string>  $only
     * @return array{requests: int, approved: int, paid: float, unpaid: float, half_days: int, mismatches: array<int, string>}
     */
    private function combinedSummary(LeaveYear $year, array $only): array
    {
        $requests = LeaveRequest::with('dayAllocations')
            ->where('request_mode', LeaveRequest::MODE_COMBINED)
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->when($only, fn ($q, $ids) => $q->whereIn('employee_id', $ids))
            ->get();

        $mismatches = [];
        foreach ($requests as $request) {
            $paid = round((float) $request->dayAllocations->sum('paid_units'), 2);
            $unpaid = round((float) $request->dayAllocations->sum('unpaid_units'), 2);
            if (abs($paid - $request->paidDays()) > 0.001 || abs($unpaid - $request->unpaidUnits()) > 0.001) {
                $mismatches[] = sprintf('LR-%04d: header paid %s / unpaid %s, day rows paid %s / unpaid %s',
                    $request->id, $request->paidDays() + 0, $request->unpaidUnits() + 0, $paid + 0, $unpaid + 0);
            }
        }

        $approved = $requests->where('status', 'approved');

        return [
            'requests' => $requests->count(),
            'approved' => $approved->count(),
            'paid' => round((float) $approved->sum(fn (LeaveRequest $r) => $r->paidDays()), 2),
            'unpaid' => round((float) $approved->sum(fn (LeaveRequest $r) => $r->unpaidUnits()), 2),
            'half_days' => $approved->sum(fn (LeaveRequest $r) => $r->dayAllocations->filter(fn ($d) => in_array($d->allocation_type, ['half_paid', 'half_unpaid'], true))->count()),
            'mismatches' => $mismatches,
        ];
    }

    /** The Earned Leave reasons a policy allows, by name. */
    private function reasonsOf(LeavePolicy $policy, LeaveBalanceSource $sources): string
    {
        $pool = $sources->poolOf($policy);

        return LeavePolicyRule::with('leaveType')->where('leave_policy_id', $policy->id)->where('is_active', true)->get()
            ->pluck('leaveType')->filter()
            ->filter(fn (LeaveType $t) => $pool !== null && $t->id !== $pool->id && $sources->forPool($t, $pool)['source'] === LeaveBalanceSource::EARNED_POOL)
            ->sortBy('display_order')->pluck('name')->implode(', ');
    }

    /** How a balance row is treated under V2. Casual, Sick, Annual… rows are never a V2 balance. */
    private function role(LeaveType $type, ?LeaveType $pool, Collection $rules, LeaveCategoryBalances $categoryBalances, LeaveBalanceSource $sources): string
    {
        $category = LeaveCategory::of($type);

        return match (true) {
            $pool !== null && $type->id === $pool->id => self::ROLE_EARNED,
            $category === null && in_array((string) $type->category, LeaveCategory::NOT_REQUESTABLE, true) => self::ROLE_NOT_LEAVE,
            $category === LeaveCategory::COMP_OFF => self::ROLE_COMP_OFF,
            $category === LeaveCategory::UNPAID, ! $type->is_paid => self::ROLE_UNPAID,
            $category === LeaveCategory::SPECIAL && $sources->forPool($type, $pool)['source'] === LeaveBalanceSource::OWN => self::ROLE_SPECIAL,
            $categoryBalances->isOtherPaid($type) => self::ROLE_OTHER_PAID,
            default => self::ROLE_LEGACY,
        };
    }

    /**
     * Approved paid days this year by reason: charged to the Earned Leave
     * balance (V2), or historical — charged to the type's own legacy balance.
     *
     * @param  array<int, int|string>  $only
     * @param  array<int, array<string, mixed>>  $resolved
     * @return array<int, array{reason: string, v2: float, historical: float, maps: string}>
     */
    private function usageByReason(LeaveYear $year, array $only, array $resolved): array
    {
        $sources = app(LeaveBalanceSource::class);

        return $this->approvedPaid($year, $only)
            ->filter(fn (LeaveRequest $r) => $r->leaveType !== null && ! in_array((string) $r->leaveType->category, LeaveCategory::NOT_REQUESTABLE, true))
            ->groupBy('leave_type_id')
            ->map(function (Collection $requests) use ($resolved) {
                $type = $requests->first()->leaveType;
                $category = LeaveCategory::of($type);
                $pooled = $requests->filter(fn (LeaveRequest $r) => $r->balance_leave_type_id !== null && $r->balance_leave_type_id !== $r->leave_type_id);
                $pool = collect($resolved)->pluck('pool')->filter()->first();
                $maps = match (true) {
                    $pool !== null && $type->id === $pool->id => 'Yes (the Earned Leave balance)',
                    $type->trashed() || ($type->availability ?? LeaveType::AVAILABILITY_ACTIVE) === LeaveType::AVAILABILITY_HISTORICAL => 'No (historical only)',
                    $category === LeaveCategory::EARNED, $category === LeaveCategory::SPECIAL && $type->consumes_earned_leave => 'Yes (reason)',
                    $category === null => 'Not until HR categorises it',
                    default => 'No ('.LeaveCategory::label($category).')',
                };

                return [
                    'reason' => trim(($type->code ?? '').' '.$type->name).($type->trashed() ? ' [retired]' : ''),
                    'v2' => round((float) $pooled->sum(fn (LeaveRequest $r) => $r->paidDays()), 2),
                    'historical' => round((float) $requests->diff($pooled)->sum(fn (LeaveRequest $r) => $r->paidDays()), 2),
                    'maps' => $maps,
                ];
            })
            ->sortBy('reason')->values()->all();
    }

    /**
     * Approved paid requests whose expected ledger usage (their paid days)
     * differs from the usage actually posted for them, each with its reason.
     *
     * @param  array<int, int|string>  $only
     * @return array<int, array<string, mixed>>
     */
    private function requestMismatches(LeaveYear $year, array $only): array
    {
        $requests = $this->approvedPaid($year, $only);
        if ($requests->isEmpty()) {
            return [];
        }

        $usage = $this->activeUsage($year)->whereIn('source_id', $requests->pluck('id'))
            ->groupBy('source_id')
            ->map(fn (Collection $entries) => round(0.0 - (float) $entries->sum('days'), 2));

        $out = [];
        foreach ($requests as $request) {
            $expected = $request->paidDays();
            $actual = (float) ($usage->get($request->id) ?? 0.0);

            if (abs($expected - $actual) < 0.001) {
                continue;
            }

            [$class, $reason] = $this->classify($request, $year);
            $out[] = [
                'employee' => $request->employee?->user?->name ?? '#'.$request->employee_id,
                'request' => 'LR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT),
                'type' => trim(($request->leaveType?->code ?? '').' '.($request->leaveType?->name ?? '?')).($request->leaveType?->trashed() ? ' [retired]' : ''),
                'dates' => $request->start_date->format('d M Y').($request->start_date->ne($request->end_date) ? ' – '.$request->end_date->format('d M Y') : ''),
                'approved_days' => (float) $request->days,
                'paid_days' => $expected,
                'expected' => $expected,
                'actual' => $actual,
                'difference' => round($expected - $actual, 2),
                'classification' => $class,
                'reason' => $reason,
            ];
        }

        return $out;
    }

    /** @return array{0: string, 1: string} */
    private function classify(LeaveRequest $request, LeaveYear $year): array
    {
        $type = $request->leaveType;
        $legacyCategory = (string) $type?->category;

        if (in_array($legacyCategory, LeaveCategory::NOT_REQUESTABLE, true)) {
            return [self::MISMATCH_COMPATIBILITY, sprintf(
                '%s is stored as a leave type but is not leave: it never draws a leave balance, so no usage is posted.',
                $type?->name ?? 'This type',
            )];
        }

        $yearRow = LeaveBalance::where('employee_id', $request->employee_id)->where('leave_type_id', $request->balanceTypeId())
            ->where('leave_year_id', $year->id)->first();

        if ($yearRow === null) {
            $calendarRow = LeaveBalance::where('employee_id', $request->employee_id)->where('leave_type_id', $request->balanceTypeId())
                ->whereNull('leave_year_id')->where('year', $request->start_date->year)->first();

            if ($calendarRow !== null) {
                return [self::MISMATCH_CALENDAR_YEAR, sprintf(
                    'Usage sits on balance #%d keyed to calendar year %d with no leave year (used_days %s); that row is not on the ledger%s.',
                    $calendarRow->id, $calendarRow->year, (float) $calendarRow->used_days + 0,
                    $type?->trashed() ? ' and the type has since been retired' : '',
                )];
            }

            if (LeaveCategory::of($type) === LeaveCategory::SPECIAL) {
                return [self::MISMATCH_SPECIAL, 'Special leave with no balance row in this leave year; its usage is not tracked on the ledger.'];
            }

            return [self::MISMATCH_UNEXPLAINED, 'No balance row exists for this type in the leave year or its calendar year.'];
        }

        if (! $yearRow->isLedgerBacked()) {
            return [self::MISMATCH_LEGACY, sprintf('Balance #%d has not been moved onto the ledger yet; the usage is in used_days only.', $yearRow->id)];
        }

        $approved = $request->approved_at ?? $request->updated_at;
        if ($approved && Carbon::parse($approved)->lte($yearRow->ledger_migrated_at)) {
            // LeaveMovementService::reverseUsage relies on this: the usage is
            // inside the migrated opening figure, not a separate entry.
            return [self::MISMATCH_LEGACY, sprintf(
                'Approved before balance #%d moved onto the ledger (%s); the usage is inside its migrated opening figure, not a separate usage entry.',
                $yearRow->id, Carbon::parse($yearRow->ledger_migrated_at)->toDateString(),
            )];
        }

        return [self::MISMATCH_MISSING_LEDGER, sprintf(
            'Approved after balance #%d moved onto the ledger (%s) but no usage entry was posted for it.',
            $yearRow->id, Carbon::parse($yearRow->ledger_migrated_at)->toDateString(),
        )];
    }

    /** @param  array<int, int|string>  $only */
    private function approvedPaid(LeaveYear $year, array $only): Collection
    {
        return LeaveRequest::with(['employee.user', 'leaveType' => fn ($q) => $q->withTrashed()])
            ->where('status', 'approved')
            ->whereDate('start_date', '>=', $year->starts_on->toDateString())
            ->whereDate('start_date', '<=', $year->ends_on->toDateString())
            ->when($only, fn ($q, $ids) => $q->whereIn('employee_id', $ids))
            ->orderBy('start_date')
            ->get()
            ->filter(fn (LeaveRequest $r) => $r->effectiveLeaveStatus() === 'paid')
            ->values();
    }

    /** Usage entries for leave requests that have not been reversed. */
    private function activeUsage(LeaveYear $year): Collection
    {
        return LeaveLedgerEntry::where('entry_type', LeaveLedgerEntry::TYPE_USAGE)
            ->where('source_type', 'leave_request')
            ->whereNull('reverses_entry_id')
            ->whereNotIn('id', LeaveLedgerEntry::whereNotNull('reverses_entry_id')->select('reverses_entry_id'))
            ->get(['id', 'source_id', 'days', 'leave_year_id']);
    }

    /**
     * @param  array<int, int|string>  $only
     * @return array{approved_paid_days: float, ledger_usage_days: float}
     */
    private function requestTotals(LeaveYear $year, array $only): array
    {
        $approved = $this->approvedPaid($year, $only);

        return [
            'approved_paid_days' => round((float) $approved->sum(fn (LeaveRequest $r) => $r->paidDays()), 2),
            'ledger_usage_days' => round(0.0 - (float) $this->activeUsage($year)->where('leave_year_id', $year->id)
                ->when($only, fn ($c) => $c->whereIn('source_id', $approved->pluck('id')))
                ->sum('days'), 2),
        ];
    }
}
