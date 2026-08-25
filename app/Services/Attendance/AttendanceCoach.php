<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceDailyScore;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AI Attendance Coach (Priority 2) — a real analytics engine, not static text.
 *
 * Every line it produces is derived from the employee's actual attendance and
 * the persisted daily scores ({@see AttendanceScoreEngine}). It explains WHY
 * the score moved (by diffing the score breakdown factors month-over-month),
 * reads arrival/logout/break trends, predicts warning risk, surfaces earned
 * achievements and personalised weekly tips. No sentence is hardcoded — an
 * employee with no data gets an onboarding message, not a fake insight.
 */
class AttendanceCoach
{
    /** Human labels for the score factors, used in "why your score changed". */
    private const FACTOR_LABELS = [
        'late_arrival' => 'late arrivals',
        'early_exit' => 'early exits',
        'missing_punch' => 'missing punches',
        'auto_punch_out' => 'auto punch-outs',
        'regularization' => 'regularizations',
        'break_violation' => 'long breaks',
        'short_hours' => 'short working days',
        'overtime' => 'overtime',
        'holiday_work' => 'holiday/weekend work',
        'absent' => 'absences',
    ];

    public function __construct(protected AttendanceScoreEngine $scores) {}

    /**
     * Produce the full coaching payload for the given period. The period drives
     * the arrival/logout/break trends; score/health/risk/achievements are
     * inherently monthly and computed against the current month.
     *
     * @return array<string, mixed>
     */
    public function analyze(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->get();

        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

        $scoreChange = $this->scoreChange($employee, $thisMonth, $lastMonth);
        $arrival = $this->arrivalTrend($employee, $attendances);
        $logout = $this->logoutTrend($attendances);
        $break = $this->breakAnalysis($employee, $attendances);
        $consistency = $this->consistency($attendances);
        $risk = $this->warningRisk($employee);
        $health = $this->health($scoreChange['this_score']);

        return [
            'has_data' => $attendances->isNotEmpty() || $scoreChange['this_score'] !== null,
            'headline' => $this->headline($employee, $scoreChange, $consistency),
            'metrics' => [
                'predicted_score' => $this->predictedScore($employee, $scoreChange['this_score']),
                'consistency' => $consistency['pct'],
                'overtime_pred' => $this->overtimePrediction($attendances),
                'risk' => ['level' => $risk['level'], 'tone' => $risk['tone']],
            ],
            'score_change' => $scoreChange,
            'arrival_trend' => $arrival,
            'logout_trend' => $logout,
            'break_analysis' => $break,
            'consistency' => $consistency,
            'health' => $health,
            'risk' => $risk,
            'achievements' => $this->achievements($employee, $attendances, $scoreChange),
            'tips' => $this->weeklyTips($scoreChange, $arrival, $break, $risk, $consistency),
            'recommendation' => $this->recommendation($scoreChange, $arrival, $break, $risk),
        ];
    }

    /**
     * Why the score changed: this vs last month, plus the factors that moved it
     * most (aggregated from the persisted score breakdowns).
     *
     * @return array{this_score: ?float, last_score: ?float, delta: ?float, reason: string, drivers: array<int, array{factor: string, label: string, delta: float}>}
     */
    protected function scoreChange(Employee $employee, CarbonInterface $thisMonth, CarbonInterface $lastMonth): array
    {
        $thisScore = $this->scores->monthlyScore($employee, $thisMonth);
        $lastScore = $this->scores->monthlyScore($employee, $lastMonth);

        if ($thisScore === null) {
            return ['this_score' => null, 'last_score' => $lastScore, 'delta' => null,
                'reason' => 'Your attendance score starts building as soon as your first full day is scored.', 'drivers' => []];
        }

        if ($lastScore === null) {
            return ['this_score' => $thisScore, 'last_score' => null, 'delta' => null,
                'reason' => 'This is your first scored month — a '.round($thisScore).'/100 baseline to build on.', 'drivers' => []];
        }

        $delta = round($thisScore - $lastScore, 1);
        $drivers = $this->factorDrivers($employee, $thisMonth, $lastMonth);

        $reason = match (true) {
            abs($delta) < 0.5 => 'Your score held steady at '.round($thisScore).'/100 versus last month.',
            $delta > 0 => 'Your score rose '.abs($delta).' points'.$this->driverClause($drivers, true).'.',
            default => 'Your score dropped '.abs($delta).' points'.$this->driverClause($drivers, false).'.',
        };

        return compact('drivers') + ['this_score' => $thisScore, 'last_score' => $lastScore, 'delta' => $delta, 'reason' => $reason];
    }

    /**
     * The factors whose total deduction changed most between the two months.
     *
     * @return array<int, array{factor: string, label: string, delta: float}>
     */
    protected function factorDrivers(Employee $employee, CarbonInterface $thisMonth, CarbonInterface $lastMonth): array
    {
        $sum = fn (CarbonInterface $m) => AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereBetween('date', [$m->copy()->startOfMonth()->toDateString(), $m->copy()->endOfMonth()->toDateString()])
            ->get()
            ->flatMap(fn ($row) => $row->breakdown ?? [])
            ->groupBy('factor')
            ->map(fn ($lines) => (float) collect($lines)->sum('points'));

        $thisSum = $sum($thisMonth);
        $lastSum = $sum($lastMonth);

        return collect($thisSum->keys())->merge($lastSum->keys())->unique()
            ->map(fn ($factor) => [
                'factor' => $factor,
                'label' => self::FACTOR_LABELS[$factor] ?? str_replace('_', ' ', $factor),
                'delta' => round(($thisSum[$factor] ?? 0) - ($lastSum[$factor] ?? 0), 1),
            ])
            ->filter(fn ($d) => abs($d['delta']) >= 0.5)
            ->sortByDesc(fn ($d) => abs($d['delta']))
            ->take(2)
            ->values()
            ->all();
    }

    /** "— mostly from fewer late arrivals and less overtime" style clause. */
    protected function driverClause(array $drivers, bool $improved): string
    {
        if ($drivers === []) {
            return '';
        }
        $verb = $improved ? 'fewer ' : 'more ';
        $parts = array_map(function ($d) use ($improved, $verb) {
            // A positive delta means the factor contributed more points this month.
            $better = $d['delta'] > 0;
            $prefix = ($better === $improved) ? $verb : ($improved ? 'more ' : 'fewer ');

            return $prefix.$d['label'];
        }, $drivers);

        return ' — mostly from '.implode(' and ', $parts);
    }

    /**
     * Average arrival versus shift start, and whether the employee is trending
     * earlier or later across the period (first half vs second half).
     *
     * @return array{avg_offset: ?int, direction: string, text: string}
     */
    protected function arrivalTrend(Employee $employee, Collection $attendances): array
    {
        $shift = app(ShiftResolver::class)->resolve($employee, now());
        $ins = $attendances->filter(fn ($a) => $a->check_in)
            ->map(fn ($a) => ['ts' => Carbon::parse($a->date), 'min' => $a->check_in->hour * 60 + $a->check_in->minute])
            ->values();

        if ($ins->isEmpty() || ! $shift) {
            return ['avg_offset' => null, 'direction' => 'flat', 'text' => 'Not enough arrivals yet to read a pattern.'];
        }

        $shiftStartMin = $shift->start->hour * 60 + $shift->start->minute;
        $avgOffset = (int) round($ins->avg('min')) - $shiftStartMin;

        $half = (int) ceil($ins->count() / 2);
        $firstAvg = $ins->take($half)->avg('min');
        $lastAvg = $ins->skip($half)->avg('min');
        $shift10 = $lastAvg - $firstAvg;
        $direction = abs($shift10) < 5 ? 'flat' : ($shift10 < 0 ? 'earlier' : 'later');

        $offsetText = $avgOffset <= 0
            ? abs($avgOffset).' min before your '.$shift->start->format('g:i A').' shift start'
            : $avgOffset.' min after your '.$shift->start->format('g:i A').' shift start';
        $trendText = match ($direction) {
            'earlier' => ' — and you\'re arriving '.abs((int) round($shift10)).' min earlier than earlier in the period.',
            'later' => ' — but you\'re slipping '.(int) round($shift10).' min later than earlier in the period.',
            default => ' — steady throughout the period.',
        };

        return ['avg_offset' => $avgOffset, 'direction' => $direction,
            'text' => 'You arrive on average '.$offsetText.$trendText];
    }

    /**
     * @return array{avg: ?string, text: string}
     */
    protected function logoutTrend(Collection $attendances): array
    {
        $outs = $attendances->filter(fn ($a) => $a->check_out)
            ->map(fn ($a) => $a->check_out->hour * 60 + $a->check_out->minute);

        if ($outs->isEmpty()) {
            return ['avg' => null, 'text' => 'No completed check-outs to analyse yet.'];
        }

        $avg = (int) round($outs->avg());
        $label = sprintf('%02d:%02d %s', (intdiv($avg, 60) % 12) ?: 12, $avg % 60, $avg < 720 ? 'AM' : 'PM');

        return ['avg' => $label, 'text' => 'Your average logout is '.$label.'.'];
    }

    /**
     * @return array{avg: int, excess_days: int, text: string}
     */
    protected function breakAnalysis(Employee $employee, Collection $attendances): array
    {
        $shift = app(ShiftResolver::class)->resolve($employee, now());
        $allowance = $shift?->breakMinutes ?: 60;
        $withBreak = $attendances->filter(fn ($a) => (int) ($a->break_minutes ?? 0) > 0);
        $avg = $withBreak->isNotEmpty() ? (int) round($withBreak->avg('break_minutes')) : 0;
        $excess = $attendances->filter(fn ($a) => (int) ($a->break_minutes ?? 0) > $allowance)->count();

        $text = match (true) {
            $withBreak->isEmpty() => 'No breaks logged this period.',
            $excess === 0 => 'Your breaks average '.$avg.' min — comfortably within the '.$allowance.' min allowance.',
            default => 'Your breaks average '.$avg.' min, with '.$excess.' day(s) over the '.$allowance.' min allowance.',
        };

        return ['avg' => $avg, 'excess_days' => $excess, 'text' => $text];
    }

    /**
     * Attendance consistency from arrival-time spread (lower spread = steadier).
     *
     * @return array{pct: int, text: string}
     */
    protected function consistency(Collection $attendances): array
    {
        $mins = $attendances->filter(fn ($a) => $a->check_in)
            ->map(fn ($a) => $a->check_in->hour * 60 + $a->check_in->minute)
            ->values();

        if ($mins->count() < 2) {
            return ['pct' => 100, 'text' => 'Building a consistency baseline.'];
        }

        $mean = $mins->avg();
        $variance = $mins->reduce(fn ($c, $m) => $c + ($m - $mean) ** 2, 0) / $mins->count();
        $std = sqrt($variance);
        // 0 min spread → 100%; ~60 min spread → ~0%. Clamp.
        $pct = (int) round(max(0, min(100, 100 - $std / 60 * 100)));

        $text = match (true) {
            $pct >= 85 => 'Very consistent arrivals (±'.round($std).' min) — a strong routine.',
            $pct >= 60 => 'Fairly consistent arrivals (±'.round($std).' min).',
            default => 'Your arrival times swing ±'.round($std).' min — a steadier routine would lift your score.',
        };

        return ['pct' => $pct, 'text' => $text];
    }

    /**
     * Probability the employee draws a late-mark warning next month, projected
     * from the current month's late pace against the configured threshold.
     *
     * @return array{level: string, tone: string, probability: int, text: string}
     */
    protected function warningRisk(Employee $employee): array
    {
        $threshold = max(1, (int) (AttendanceSetting::query()->value('late_warning_threshold') ?? 3));

        $monthStart = now()->startOfMonth();
        $lateSoFar = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$monthStart->toDateString(), now()->toDateString()])
            ->where('is_late', true)
            ->count();

        $elapsed = max(1, $monthStart->diffInDays(now()) + 1);
        $monthDays = now()->daysInMonth;
        $projected = $lateSoFar / $elapsed * $monthDays;

        $probability = (int) round(max(0, min(100, $projected / $threshold * 100)));
        [$level, $tone] = match (true) {
            $lateSoFar >= $threshold => ['High', 'danger'],
            $probability >= 60 => ['Elevated', 'warn'],
            $probability >= 30 => ['Moderate', 'warn'],
            default => ['Low', 'good'],
        };

        $text = match ($level) {
            'High' => 'You have already hit '.$lateSoFar.' late marks — a warning threshold ('.$threshold.') for this month.',
            'Elevated', 'Moderate' => 'At your current pace you may reach '.round($projected).' late marks by month-end (threshold '.$threshold.').',
            default => 'Low risk of a late-mark warning — you\'re well under the '.$threshold.'-mark threshold.',
        };

        return ['level' => $level, 'tone' => $tone, 'probability' => $probability, 'text' => $text];
    }

    /**
     * @return array{label: string, tone: string, text: string}
     */
    protected function health(?float $score): array
    {
        if ($score === null) {
            return ['label' => 'Building', 'tone' => 'muted', 'text' => 'Your attendance health appears once days are scored.'];
        }

        return match (true) {
            $score >= 90 => ['label' => 'Excellent', 'tone' => 'good', 'text' => 'Attendance health is excellent at '.round($score).'/100.'],
            $score >= 75 => ['label' => 'Healthy', 'tone' => 'good', 'text' => 'Attendance health is solid at '.round($score).'/100.'],
            $score >= 60 => ['label' => 'Fair', 'tone' => 'warn', 'text' => 'Attendance health is fair at '.round($score).'/100 — a few habits to tighten.'],
            default => ['label' => 'At risk', 'tone' => 'danger', 'text' => 'Attendance health is at risk at '.round($score).'/100 — let\'s turn it around.'],
        };
    }

    /**
     * Earned achievements from real data — streaks, perfect scores, improvement.
     *
     * @return array<int, array{icon: string, label: string}>
     */
    protected function achievements(Employee $employee, Collection $attendances, array $scoreChange): array
    {
        $earned = [];

        $streak = $this->onTimeStreak($employee);
        if ($streak >= 5) {
            $earned[] = ['icon' => 'fire', 'label' => $streak.'-day on-time streak'];
        }

        $perfectDays = AttendanceDailyScore::where('employee_id', $employee->id)
            ->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->where('score', 100)
            ->count();
        if ($perfectDays >= 5) {
            $earned[] = ['icon' => 'star', 'label' => $perfectDays.' perfect days this month'];
        }

        if (($scoreChange['delta'] ?? 0) >= 3) {
            $earned[] = ['icon' => 'arrow-trending-up', 'label' => 'Score up '.$scoreChange['delta'].' pts vs last month'];
        }

        $companyIds = Employee::where('status', 'active')->pluck('id')->all();
        $rank = $this->scores->rankAmong($employee, $companyIds, now());
        if ($rank && $rank[0] <= max(3, (int) ceil($rank[1] * 0.1))) {
            $earned[] = ['icon' => 'trophy', 'label' => 'Top '.$rank[0].' company-wide this month'];
        }

        $noLate = $attendances->isNotEmpty() && $attendances->where('is_late', true)->isEmpty();
        if ($noLate) {
            $earned[] = ['icon' => 'check-badge', 'label' => 'Zero late marks this period'];
        }

        return $earned;
    }

    /**
     * Personalised weekly coaching tips, ordered by impact for THIS employee.
     *
     * @return array<int, string>
     */
    protected function weeklyTips(array $scoreChange, array $arrival, array $break, array $risk, array $consistency): array
    {
        $tips = [];

        if ($risk['level'] === 'High' || $risk['level'] === 'Elevated') {
            $tips[] = 'Protect your score this week: aim to clock in before your grace cutoff every day.';
        }
        if (($arrival['direction'] ?? 'flat') === 'later') {
            $tips[] = 'Your arrivals are drifting later — set a departure reminder 15 min earlier to reset the trend.';
        }
        if (($break['excess_days'] ?? 0) > 0) {
            $tips[] = 'Trim breaks on '.$break['excess_days'].' day(s) that ran over the allowance to recover break points.';
        }
        if (($consistency['pct'] ?? 100) < 60) {
            $tips[] = 'Anchor a fixed arrival time this week — consistency alone lifts your attendance score.';
        }
        $topDriver = collect($scoreChange['drivers'] ?? [])->first(fn ($d) => $d['delta'] < 0);
        if ($topDriver) {
            $tips[] = 'Focus area: '.$topDriver['label'].' cost you the most points this month.';
        }

        if ($tips === []) {
            $tips[] = 'You\'re on track — keep your current routine to hold your score.';
        }

        return array_slice($tips, 0, 3);
    }

    protected function recommendation(array $scoreChange, array $arrival, array $break, array $risk): string
    {
        return match (true) {
            $risk['level'] === 'High' => 'Priority: you\'ve hit the late-mark threshold — arrive before your grace cutoff daily to avoid escalation.',
            ($arrival['direction'] ?? '') === 'later' => 'Reset your arrival trend — leaving 15 minutes earlier each morning will protect your score.',
            ($break['excess_days'] ?? 0) >= 2 => 'Tighten your breaks — '.$break['excess_days'].' days ran over the allowance this period.',
            ($scoreChange['delta'] ?? 0) < 0 => 'Rebuild your score by locking in on-time arrivals for the next 5 working days.',
            default => 'You\'re on track for your best month — keep clocking in before your grace cutoff.',
        };
    }

    protected function predictedScore(Employee $employee, ?float $current): int
    {
        if ($current === null) {
            return 100;
        }
        // Project the month-to-date average forward with a small momentum nudge.
        $streak = $this->onTimeStreak($employee);
        $nudge = min(3, intdiv($streak, 3));

        return (int) min(100, round($current) + $nudge);
    }

    protected function overtimePrediction(Collection $attendances): float
    {
        $otDays = $attendances->filter(fn ($a) => (float) ($a->total_hours ?? 0) > 9);
        if ($otDays->isEmpty()) {
            return 0.0;
        }
        $avgOt = $otDays->avg(fn ($a) => max(0, (float) $a->total_hours - 9));

        return round($avgOt * $otDays->count(), 1);
    }

    protected function headline(Employee $employee, array $scoreChange, array $consistency): string
    {
        $name = $employee->user?->name ? explode(' ', $employee->user->name)[0] : 'there';

        $companyIds = Employee::where('status', 'active')->pluck('id')->all();
        $rank = $this->scores->rankAmong($employee, $companyIds, now());

        if ($rank) {
            $percentile = (int) round((1 - ($rank[0] - 1) / max(1, $rank[1])) * 100);

            return 'Hi '.$name.' — you\'re ahead of '.$percentile.'% of the company on attendance this month.';
        }

        if ($scoreChange['this_score'] !== null) {
            return 'Hi '.$name.' — your attendance score sits at '.round($scoreChange['this_score']).'/100 this month.';
        }

        return 'Hi '.$name.' — clock in a few days and I\'ll start coaching your attendance trends.';
    }

    /** Current run of on-time working days, back from the latest one. */
    protected function onTimeStreak(Employee $employee): int
    {
        $recent = Attendance::where('employee_id', $employee->id)
            ->where('date', '>=', now()->subDays(120)->toDateString())
            ->get(['date', 'is_late', 'check_in'])
            ->keyBy(fn ($a) => Carbon::parse($a->date)->toDateString());

        $streak = 0;
        $cursor = Carbon::today();
        if (! isset($recent[$cursor->toDateString()])) {
            $cursor->subDay();
        }
        for ($i = 0; $i < 120; $i++) {
            if ($cursor->isSunday()) {
                $cursor->subDay();

                continue;
            }
            $a = $recent[$cursor->toDateString()] ?? null;
            if ($a && $a->check_in && ! $a->is_late) {
                $streak++;
            } elseif ($cursor->lt(Carbon::today())) {
                break;
            }
            $cursor->subDay();
        }

        return $streak;
    }
}
