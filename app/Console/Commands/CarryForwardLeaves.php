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
 * It now previews by default and writes only with --apply, through the same
 * engine the Carry Forward screen uses, so the console and the UI cannot
 * produce different answers from the same data.
 */
class CarryForwardLeaves extends Command
{
    protected $signature = 'hrms:carry-forward-leaves
        {year? : Leave year to carry INTO, as its starting year (2026 means 2026/27). Defaults to the current leave year}
        {--apply : Actually write the balances. Without this the command only previews}';

    protected $description = 'Preview or apply leave carry-forward between leave years (previews by default)';

    public function handle(LeaveYearResolver $resolver, LeaveCarryOverService $engine): int
    {
        $to = $this->argument('year')
            ? $resolver->forDate(Carbon::create((int) $this->argument('year'), 7, 1))
            : $resolver->current();

        $from = $resolver->previous($to);

        $this->info(($this->option('apply') ? 'Applying' : 'PREVIEW — nothing will be written').' carry-forward');
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

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('Nothing was written. Re-run with <fg=cyan>--apply</> to commit,');
            $this->line('or use the Carry Forward screen, which records who approved each row.');

            return self::SUCCESS;
        }

        $result = $engine->execute($from, $to);

        $this->newLine();
        $this->info("Carried {$result['days']} day(s) across {$result['rows']} row(s) for {$result['employees']} employee(s).");
        $this->line('Fresh entitlement was preserved and used_days left untouched.');

        return self::SUCCESS;
    }
}
