<?php

namespace App\Console\Commands;

use App\Services\Leave\LeaveCarryOverService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Carry unused leave into the next leave year.
 *
 * This command used to apply immediately, with no preview and no confirmation,
 * against an implementation that replaced each employee's fresh entitlement
 * with the carried figure and reset used_days to zero. Running it by mistake
 * erased every booking in the target year for every active employee.
 *
 * It is now read-only. It previews through the same engine the Carry Forward
 * screen uses, so the console and the UI cannot produce different answers from
 * the same data — but applying happens in the UI, where the amount HR decided,
 * the person who decided it and the reason are all recorded. A console run is
 * attributable to a shell, and carrying everybody's leave with no decision
 * behind it is exactly what the agreed process forbids.
 */
class CarryForwardLeaves extends Command
{
    protected $signature = 'hrms:carry-forward-leaves
        {year? : Leave year to carry INTO, as its starting year (2026 means 2026/27). Defaults to the current leave year}
        {--apply : Refused. Carry forward is applied from the UI so the decision is attributable}';

    protected $description = 'Preview leave carry-forward between leave years (read-only; apply from the UI)';

    public function handle(LeaveYearResolver $resolver, LeaveCarryOverService $engine): int
    {
        if ($this->option('apply')) {
            // Refused up front, before any preview work: whether there happens
            // to be anything to carry has no bearing on whether the console may
            // carry it. Carry forward is an HR decision per employee, and a
            // console run is attributable to a shell rather than to a person.
            $this->error('Applying carry forward from the console is not supported.');
            $this->newLine();
            $this->line('Carry forward is decided per employee by HR and recorded against whoever');
            $this->line('approved it. Apply it from <fg=cyan>Leave > Carry Forward</>, where the amount,');
            $this->line('the actor and the reason are all captured.');
            $this->newLine();
            $this->line('Re-run without <fg=cyan>--apply</> for a read-only preview.');

            return self::FAILURE;
        }

        $to = $this->argument('year')
            ? $resolver->forDate(Carbon::create((int) $this->argument('year'), 7, 1))
            : $resolver->current();

        $from = $resolver->previous($to);

        $this->info('PREVIEW — nothing will be written');
        $this->line("  From: <fg=cyan>{$from->label}</> ({$from->starts_on->toDateString()} to {$from->ends_on->toDateString()})");
        $this->line("  Into: <fg=cyan>{$to->label}</> ({$to->starts_on->toDateString()} to {$to->ends_on->toDateString()})");
        $this->newLine();

        $rows = $engine->preview($from, $to);

        if ($rows->isEmpty()) {
            $this->warn("Nothing to carry forward from {$from->label}.");
            $this->line('If that is unexpected, run <fg=cyan>php artisan leave:carry-forward-audit</> to see which');
            $this->line('precondition is missing — most often there are no balances for the previous year at all.');

            return self::SUCCESS;
        }

        $this->table(
            ['Employee', 'Leave type', 'Allocated', 'Used', 'Encashed', 'Eligible', 'Carry'],
            $rows->map(fn (array $r) => [
                $r['employee'] ?? '—',
                $r['leave_type'],
                $r['allocated'],
                $r['used'],
                $r['encashed'],
                $r['eligible'],
                $r['carry'],
            ])->all(),
        );

        $this->newLine();
        $this->line('  Employees: '.$rows->pluck('employee_id')->unique()->count());
        $this->line('  Days to carry: '.round($rows->sum('carry'), 2));

        $this->newLine();
        $this->line('Preview only — nothing was written.');
        $this->line('Apply from <fg=cyan>Leave > Carry Forward</>, where each decision is recorded');
        $this->line('against the person who made it.');

        return self::SUCCESS;
    }
}
