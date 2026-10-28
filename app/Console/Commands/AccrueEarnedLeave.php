<?php

namespace App\Console\Commands;

use App\Models\LeaveYear;
use App\Models\User;
use App\Services\Leave\EarnedLeaveAccrualService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Leave V2 Earned Leave accrual: 1 day per completed month of the leave
 * year, catching up any missed month, never twice (EarnedLeaveAccrualService).
 *
 * --preview (the default) writes nothing: it shows, per employee, what is
 * there, what the completed months entitle them to, and what would be
 * credited — READY / NO CHANGE / NEEDS REVIEW / BLOCKED. --apply credits the
 * READY rows only; everything else is reported and left alone.
 */
#[Signature('leave:accrue-earned
    {--preview : Show what would be credited and write nothing (default)}
    {--apply : Credit every READY employee}
    {--employee=* : Limit to these employee codes}
    {--as-of= : Accrue months completed before this date (default today)}
    {--year= : Leave year label, e.g. 2026/27 (default: the year the as-of date accrues for)}
    {--actor= : Email of the HR / admin user running it by hand (scheduled runs are the system)}
    {--all : Also list employees whose policy has no Earned Leave}
    {--csv= : Also write the rows to this CSV path}')]
#[Description('Earned Leave monthly accrual — 1 day per completed month, idempotent, catches up missed months (preview writes nothing)')]
class AccrueEarnedLeave extends Command
{
    /** Employees left out of the run because their policy has no Earned Leave. */
    private int $outsideScope = 0;

    public function handle(EarnedLeaveAccrualService $accrual): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('preview')) {
            $this->error('Choose --preview or --apply, not both.');

            return self::FAILURE;
        }

        $asOf = $this->option('as-of') ? Carbon::parse((string) $this->option('as-of'))->startOfDay() : Carbon::today();
        $actor = null;
        if ($this->option('actor')) {
            $actor = User::where('email', (string) $this->option('actor'))->first();
            if (! $actor) {
                $this->error('No user with that --actor email.');

                return self::FAILURE;
            }
        }

        if (! $apply) {
            DB::beginTransaction();
            try {
                $rows = $this->plan($accrual, $asOf);
                if ($rows === null) {
                    return self::FAILURE;
                }
                $this->report($rows, 'PREVIEW — nothing is written');
            } finally {
                DB::rollBack();
            }

            return self::SUCCESS;
        }

        $rows = $this->plan($accrual, $asOf);
        if ($rows === null) {
            return self::FAILURE;
        }

        $failed = 0;
        $trigger = $actor ? 'manual' : 'scheduled';
        $rows = $rows->map(function (array $row) use ($accrual, $actor, $trigger, &$failed) {
            if ($row['status'] !== EarnedLeaveAccrualService::READY) {
                return $row;
            }
            try {
                return $accrual->apply($row, $actor, $trigger);
            } catch (Throwable $e) {
                $failed++;

                return ['status' => 'FAILED', 'reason' => 'Rolled back: '.$e->getMessage()] + $row;
            }
        });

        $this->report($rows, 'APPLIED'.($actor ? ' by '.$actor->email : ' by the system'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Collection<int, array<string, mixed>>|null */
    private function plan(EarnedLeaveAccrualService $accrual, Carbon $asOf): ?Collection
    {
        $year = null;
        if ($this->option('year')) {
            $year = LeaveYear::where('label', $this->option('year'))->first();
            if (! $year) {
                $this->error('No leave year '.$this->option('year').'.');

                return null;
            }
        }

        $rows = $accrual->plan($asOf, $year, $this->option('employee'), true);
        if ($this->option('all') || $this->option('employee') !== []) {
            return $rows;
        }

        // Employees whose policy has no Earned Leave are outside this run: counted, never silently dropped.
        $this->outsideScope = $rows->whereNull('earned_type_id')->count();

        return $rows->whereNotNull('earned_type_id')->values();
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function report(Collection $rows, string $heading): void
    {
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $first = $rows->first();

        $this->line('<comment>Earned Leave accrual · '.($first['year'] ?? '—').' · as of '.($first['as_of'] ?? '—')." · {$heading}</comment>");
        $this->table(
            ['Employee', 'Code', 'Policy', 'Earned now', 'Opening', 'Accrued', 'Used', 'Pending', 'Joined', 'Accrual starts', 'Eligible months', 'Already credited', 'Proposed', 'Status', 'Reason'],
            $rows->map(fn (array $r) => [
                $r['employee'], $r['code'], $r['policy'] ?? '—', $fmt($r['available']), $fmt($r['opening']), $fmt($r['accrued']), $fmt($r['used']), $fmt($r['pending']),
                $r['joining_date'] ?? 'missing',
                $r['transition'] ?? ($r['suggested_start'] ? 'not set (suggest '.$r['suggested_start'].')' : 'not set'),
                $r['expected'] === null ? '—' : count($r['expected_months']), count($r['credited_months']), $fmt($r['proposed']), $r['status'], $r['reason'],
            ])->all(),
        );
        $this->line('  '.$rows->count().' employee(s): '.($rows->countBy('status')->map(fn ($n, $s) => "{$s} {$n}")->implode(' · ') ?: 'none'));
        if ($this->outsideScope > 0) {
            $this->line("  {$this->outsideScope} other employee(s) have no Earned Leave policy and are not listed (--all lists them).");
        }

        if ($path = $this->option('csv')) {
            $out = fopen($path, 'w');
            fputcsv($out, ['employee', 'code', 'policy', 'earned_available', 'opening', 'accrued', 'used', 'pending', 'joining_date', 'monthly_accrual_starts_from', 'suggested_start',
                'eligible_months', 'credited_months', 'proposed', 'due_months', 'status', 'reason']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['employee'], $r['code'], $r['policy'], $r['available'], $r['opening'], $r['accrued'], $r['used'], $r['pending'], $r['joining_date'], $r['transition'], $r['suggested_start'],
                    implode(' ', $r['expected_months']), implode(' ', $r['credited_months']), $r['proposed'], implode(' ', $r['due_months']), $r['status'], $r['reason']]);
            }
            fclose($out);
            $this->line("  Rows written to {$path}");
        }
    }
}
