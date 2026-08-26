<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveCarryOverService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Command;

/**
 * Why the Carry Forward page is empty.
 *
 * Read-only. Carry forward reads the PREVIOUS leave year, and every condition
 * it depends on either holds or it does not — this reports each one rather
 * than leaving the page to say "0" and mean any of four different things.
 *
 * It deliberately does not create anything. A missing 2025/26 balance is a
 * missing history, and inventing a zero row to make the screen populate would
 * turn "we have no data" into "this employee earned nothing", which is a
 * different and much worse claim.
 */
class CarryForwardAudit extends Command
{
    protected $signature = 'leave:carry-forward-audit
        {--employee=* : Employee codes to detail, e.g. --employee=CNS018 --employee=CNS021}
        {--all : Detail every active employee}';

    protected $description = 'Read-only diagnosis of why carry forward finds no eligible employees';

    public function handle(LeaveYearResolver $resolver, LeaveCarryOverService $engine): int
    {
        $current = $resolver->current();
        $previous = $resolver->previous($current);

        $this->info('Carry forward audit — read-only, nothing is written');
        $this->newLine();

        $this->line("  Current leave year:  <fg=cyan>{$current->label}</> ({$current->starts_on->toDateString()} to {$current->ends_on->toDateString()}, legacy year {$current->legacyYear()})");
        $this->line("  Previous leave year: <fg=cyan>{$previous->label}</> ({$previous->starts_on->toDateString()} to {$previous->ends_on->toDateString()}, legacy year {$previous->legacyYear()})");
        $this->newLine();

        $blockers = $this->preconditions($previous);

        $this->table(
            ['Precondition', 'Result', 'Effect if missing'],
            array_map(fn (array $c) => [
                $c['label'],
                $c['ok'] ? "<fg=green>{$c['value']}</>" : "<fg=red>{$c['value']}</>",
                $c['ok'] ? '—' : $c['effect'],
            ], $blockers),
        );

        $this->newLine();
        $this->detailEmployees($previous, $current);

        $rows = $engine->preview($previous, $current);

        $this->newLine();
        $this->line("  Carry forward preview returns: <fg=yellow>{$rows->count()}</> row(s)");

        $failed = array_values(array_filter($blockers, fn (array $c) => ! $c['ok']));

        if ($rows->isEmpty()) {
            $this->newLine();
            $this->error('  Carry forward is empty because:');

            foreach ($failed as $c) {
                $this->line("    - {$c['effect']}");
            }

            if ($failed === []) {
                $this->line('    - Every previous-year balance nets to zero or less after used and encashed days.');
            }

            $this->newLine();
            $this->warn('  Historical leave data not available.');
            $this->line('  Do not credit balances by hand to populate this screen. The 2025/26 position');
            $this->line('  has to come from the HR export, imported as history, or carry forward will be');
            $this->line('  carrying numbers nobody can trace.');
        }

        return self::SUCCESS;
    }

    /** @return array<int, array{label:string, ok:bool, value:string, effect:string}> */
    private function preconditions(LeaveYear $previous): array
    {
        $activeEmployees = Employee::where('status', 'active')->count();
        $carryableTypes = LeaveType::where('allow_carry_forward', true)->count();
        $previousBalances = $this->previousYearBalanceQuery($previous)->count();
        $previousRequests = LeaveRequest::whereDate('start_date', '>=', $previous->starts_on)
            ->whereDate('start_date', '<=', $previous->ends_on)
            ->count();

        return [
            [
                'label' => 'Active employees',
                'ok' => $activeEmployees > 0,
                'value' => (string) $activeEmployees,
                'effect' => 'Carry forward only considers employees with status "active".',
            ],
            [
                'label' => 'Leave types with allow_carry_forward',
                'ok' => $carryableTypes > 0,
                'value' => (string) $carryableTypes,
                'effect' => 'No leave type is marked carry-forwardable, so nothing is ever eligible.',
            ],
            [
                'label' => "Balance rows in {$previous->label}",
                'ok' => $previousBalances > 0,
                'value' => (string) $previousBalances,
                'effect' => "No balances exist for {$previous->label}, so there is no previous-year position to carry from.",
            ],
            [
                'label' => "Leave requests dated inside {$previous->label}",
                'ok' => $previousRequests > 0,
                'value' => (string) $previousRequests,
                'effect' => "No leave was recorded in {$previous->label} — consistent with the historical data never having been imported.",
            ],
        ];
    }

    private function detailEmployees(LeaveYear $previous, LeaveYear $current): void
    {
        $codes = $this->option('employee');

        $employees = Employee::with(['user', 'leavePolicy'])
            ->when($codes, fn ($q) => $q->whereIn('employee_id', $codes))
            ->when(! $codes && ! $this->option('all'), fn ($q) => $q->limit(10))
            ->when($this->option('all'), fn ($q) => $q->where('status', 'active'))
            ->get();

        if ($employees->isEmpty()) {
            $this->warn('  No employees matched.'.($codes ? ' Codes searched: '.implode(', ', $codes) : ''));

            return;
        }

        $types = LeaveType::where('allow_carry_forward', true)->get();
        $rows = [];

        foreach ($employees as $employee) {
            if ($types->isEmpty()) {
                $rows[] = [
                    $employee->employee_id ?? '—',
                    $employee->user?->name ?? '—',
                    $employee->leave_policy_id ? ('#'.$employee->leave_policy_id) : 'none',
                    $previous->label,
                    'no carry-forwardable type',
                    '—', '—', '—', '—', '—',
                    'Not eligible',
                ];

                continue;
            }

            foreach ($types as $type) {
                $balance = $this->previousYearBalanceQuery($previous)
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->first();

                $allocated = $balance ? (float) $balance->allocated_days : null;
                $used = $balance ? (float) $balance->used_days : null;
                $encashed = $balance ? (float) ($balance->encashed_days ?? 0) : null;
                $eligible = $balance ? max(0, $allocated - $used - $encashed) : null;

                $rows[] = [
                    $employee->employee_id ?? '—',
                    $employee->user?->name ?? '—',
                    $employee->leave_policy_id ? ('#'.$employee->leave_policy_id) : 'none',
                    $previous->label,
                    $type->name,
                    $allocated ?? '—',
                    $used ?? '—',
                    $encashed ?? '—',
                    $eligible ?? '—',
                    $type->carry_forward_limit > 0 ? (string) $type->carry_forward_limit : 'none',
                    $balance === null
                        ? 'No 2025/26 record'
                        : ($eligible > 0 ? 'Eligible' : 'Not eligible'),
                ];
            }
        }

        $this->line('  Per-employee position in '.$previous->label.':');
        $this->table(
            ['Code', 'Employee', 'Policy', 'Leave year', 'Leave type', 'Allocated', 'Used', 'Encashed', 'Eligible', 'CF limit', 'Status'],
            $rows,
        );
    }

    /**
     * Balances belonging to a leave year, by the explicit link or the legacy
     * integer — the same rule the carry-over engine uses, so this audit cannot
     * disagree with the screen it is explaining.
     */
    private function previousYearBalanceQuery(LeaveYear $previous)
    {
        return LeaveBalance::where(function ($query) use ($previous) {
            $query->where('leave_year_id', $previous->id)
                ->orWhere(function ($legacy) use ($previous) {
                    $legacy->whereNull('leave_year_id')->where('year', $previous->legacyYear());
                });
        });
    }
}
