<flux:main class="bg-zinc-50 p-6 dark:bg-zinc-950 min-h-screen">

    {{-- Header --}}
    <div class="pulse-page-header mb-6">
        <div>
            <h1 class="pulse-page-title">KPI Dashboard</h1>
            <p class="pulse-page-subtitle">Performance scorecard overview across review cycles</p>
        </div>
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="selectedCycleId" class="w-56">
                <flux:select.option value="">— Select Cycle —</flux:select.option>
                @foreach($cycles as $cycle)
                    <flux:select.option value="{{ $cycle->id }}">{{ $cycle->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <a href="{{ route('performance.kpi-templates') }}">
                <flux:button variant="ghost" icon="cog-6-tooth">Manage Templates</flux:button>
            </a>
        </div>
    </div>

    @if(! $selectedCycleId)
        <div class="pulse-card flex flex-col items-center justify-center py-20 text-center">
            <flux:icon.chart-bar class="size-12 text-zinc-300 dark:text-zinc-600 mb-3" />
            <p class="text-zinc-400">Select a performance cycle to view the dashboard.</p>
        </div>
    @else

    {{-- ── Stat Cards ────────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $totalEmployees }}</div>
            <div class="text-xs text-zinc-400 mt-1">Employees Reviewed</div>
        </div>
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-indigo-600">{{ $avgScore ?? '—' }}</div>
            <div class="text-xs text-zinc-400 mt-1">Avg Score</div>
        </div>
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-green-600">{{ number_format($topScore ?? 0, 1) }}</div>
            <div class="text-xs text-zinc-400 mt-1">Top Score</div>
        </div>
        <div class="pulse-card text-center">
            @php
                $aPlus = ($gradeDistribution['a_plus'] ?? 0);
                $aGrade = ($gradeDistribution['a'] ?? 0);
                $topPercent = $totalEmployees > 0 ? round(($aPlus + $aGrade) / $totalEmployees * 100) : 0;
            @endphp
            <div class="text-3xl font-bold text-amber-500">{{ $topPercent }}%</div>
            <div class="text-xs text-zinc-400 mt-1">Scored A / A+</div>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-6 mb-6">

        {{-- ── Grade Distribution Donut ─────────────────────────────────────── --}}
        <div class="col-span-12 sm:col-span-4 pulse-card">
            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Grade Distribution</h3>
            @if($totalEmployees > 0)
                <div class="space-y-3">
                    @php
                        $grades = [
                            'a_plus' => ['label' => 'A+ (≥90)', 'color' => 'bg-green-500'],
                            'a'      => ['label' => 'A  (≥80)', 'color' => 'bg-emerald-400'],
                            'b'      => ['label' => 'B  (≥70)', 'color' => 'bg-blue-400'],
                            'c'      => ['label' => 'C  (≥60)', 'color' => 'bg-amber-400'],
                            'd'      => ['label' => 'D  (<60)',  'color' => 'bg-red-400'],
                        ];
                    @endphp
                    @foreach($grades as $key => $meta)
                        @php $cnt = $gradeDistribution[$key] ?? 0; $pct = $totalEmployees > 0 ? round($cnt / $totalEmployees * 100) : 0; @endphp
                        <div>
                            <div class="flex justify-between text-xs mb-1">
                                <span class="text-zinc-500">{{ $meta['label'] }}</span>
                                <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $cnt }} <span class="text-zinc-400">({{ $pct }}%)</span></span>
                            </div>
                            <div class="h-1.5 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="{{ $meta['color'] }} h-1.5 rounded-full transition-all" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-zinc-400 text-center py-6">No scorecards generated yet.</p>
            @endif
        </div>

        {{-- ── Trend Chart ──────────────────────────────────────────────────── --}}
        <div class="col-span-12 sm:col-span-8 pulse-card">
            <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Average Score Trend</h3>
            @if($trendCycles->count() > 1)
                @php
                    $maxScore = $trendCycles->max('avg') ?: 100;
                    $minScore = max(0, $trendCycles->min('avg') - 10);
                    $range = $maxScore - $minScore ?: 1;
                    $chartH = 120;
                @endphp
                <div class="relative h-36 flex items-end gap-3">
                    @foreach($trendCycles as $point)
                        @php $barH = round(($point['avg'] - $minScore) / $range * $chartH); @endphp
                        <div class="flex-1 flex flex-col items-center gap-1">
                            <span class="text-xs font-bold text-indigo-600">{{ $point['avg'] }}</span>
                            <div class="w-full bg-indigo-200 dark:bg-indigo-900/50 rounded-t-lg transition-all" style="height: {{ max($barH, 4) }}px"></div>
                            <span class="text-[10px] text-zinc-400 text-center truncate w-full text-center">{{ Str::limit($point['name'], 10) }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex items-center justify-center h-32 text-sm text-zinc-400">
                    Not enough completed cycles for a trend.
                </div>
            @endif
        </div>
    </div>

    {{-- ── Strength & Weakness Bars ──────────────────────────────────────────── --}}
    @if(count($strengthWeakness) > 0)
    <div class="pulse-card mb-6">
        <h3 class="text-sm font-semibold text-zinc-700 dark:text-zinc-300 mb-4">Component Avg Scores (Strength → Weakness)</h3>
        <div class="space-y-2.5">
            @foreach($strengthWeakness as $idx => $comp)
                @php
                    $pct = $comp['max_score'] > 0 ? round(($comp['avg_score'] ?? 0) / $comp['max_score'] * 100) : 0;
                    $barColor = $pct >= 80 ? 'bg-green-400' : ($pct >= 60 ? 'bg-blue-400' : ($pct >= 40 ? 'bg-amber-400' : 'bg-red-400'));
                @endphp
                <div class="flex items-center gap-3">
                    <div class="w-44 text-xs text-zinc-500 truncate">{{ $comp['component_name'] }}</div>
                    <div class="flex-1 h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full">
                        <div class="{{ $barColor }} h-2 rounded-full" style="width: {{ $pct }}%"></div>
                    </div>
                    <div class="w-12 text-xs text-right font-semibold text-zinc-700 dark:text-zinc-300 tabular-nums">
                        {{ number_format($comp['avg_score'] ?? 0, 1) }}/{{ $comp['max_score'] }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ── Filters ───────────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search employee…" icon="magnifying-glass" class="w-48" />
        <flux:select wire:model.live="selectedDepartmentId" class="w-44">
            <flux:select.option value="">All Departments</flux:select.option>
            @foreach($departments as $dept)
                <flux:select.option value="{{ $dept->id }}">{{ $dept->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="gradeFilter" class="w-36">
            <flux:select.option value="">All Grades</flux:select.option>
            <flux:select.option value="a_plus">A+</flux:select.option>
            <flux:select.option value="a">A</flux:select.option>
            <flux:select.option value="b">B</flux:select.option>
            <flux:select.option value="c">C</flux:select.option>
            <flux:select.option value="d">D</flux:select.option>
        </flux:select>
    </div>

    {{-- ── Performer Segments ────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        @php
            $segments = [
                ['title' => 'Top Performers', 'rows' => $topPerformers, 'badge' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400', 'empty' => 'No scores yet.'],
                ['title' => 'At Risk', 'rows' => $atRisk, 'badge' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400', 'empty' => 'No scores yet.'],
                ['title' => 'Promotion Ready', 'rows' => $promotionReady, 'badge' => 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400', 'empty' => 'None scoring 85+ this cycle.'],
            ];
        @endphp
        @foreach($segments as $segment)
            <div class="pulse-card">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $segment['title'] }}</h3>
                    <span class="rounded-full px-2 py-0.5 text-xs font-black {{ $segment['badge'] }}">{{ $segment['rows']->count() }}</span>
                </div>
                <div class="space-y-2">
                    @forelse($segment['rows'] as $sc)
                        <div class="flex items-center justify-between gap-3 border-b border-zinc-50 pb-2 last:border-0 last:pb-0 dark:border-zinc-800/60">
                            <span class="truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $sc->employee?->user?->name ?? '—' }}</span>
                            <div class="flex items-center gap-2">
                                @if($sc->grade)<span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $sc->grade }}</span>@endif
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $sc->final_score }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-center text-xs text-zinc-400">{{ $segment['empty'] }}</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Goal progress · Department ranking · PIP risk ─────────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="pulse-card">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-3">Goal Progress</h3>
            <div class="flex items-end gap-3">
                <div class="text-4xl font-black text-emerald-600">{{ $goalStats['percent'] }}%</div>
                <div class="text-xs text-zinc-400 pb-1">{{ $goalStats['completed'] }}/{{ $goalStats['total'] }} goals completed</div>
            </div>
            <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $goalStats['percent'] }}%"></div>
            </div>
        </div>

        <div class="pulse-card">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-3">Department Ranking</h3>
            <div class="space-y-2">
                @forelse($deptRanking as $i => $dept)
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-50 pb-2 last:border-0 last:pb-0 dark:border-zinc-800/60">
                        <span class="truncate text-sm text-zinc-700 dark:text-zinc-200"><span class="text-zinc-400">{{ $i + 1 }}.</span> {{ $dept['name'] }}</span>
                        <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $dept['avg'] }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-xs text-zinc-400">No scores yet.</p>
                @endforelse
            </div>
        </div>

        <div class="pulse-card">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">PIP Risk</h3>
                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-black text-rose-700 dark:bg-rose-950/40 dark:text-rose-400">{{ $pipRisk->count() }}</span>
            </div>
            <p class="mb-2 text-[11px] text-zinc-400">Below 50 for two consecutive cycles</p>
            <div class="space-y-2">
                @forelse($pipRisk as $sc)
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-50 pb-2 last:border-0 last:pb-0 dark:border-zinc-800/60">
                        <span class="truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $sc->employee?->user?->name ?? '—' }}</span>
                        <span class="text-sm font-bold text-rose-600">{{ $sc->final_score }}</span>
                    </div>
                @empty
                    <p class="py-6 text-center text-xs text-zinc-400">No at-risk employees.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ── Department × Cycle Heatmap ─────────────────────────────────────────── --}}
    @if($heatmapCycles->isNotEmpty())
        <div class="pulse-card mb-6">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4">Department &times; Cycle Heatmap</h3>
            <div class="pulse-table-wrap">
                <table class="pulse-table">
                    <thead>
                        <tr>
                            <th class="pulse-th pl-6">Department</th>
                            @foreach($heatmapCycles as $cy)
                                <th class="pulse-th text-center!">{{ \Illuminate\Support\Str::limit($cy->name, 10) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($heatmap as $row)
                            <tr>
                                <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">{{ $row['department'] }}</td>
                                @foreach($row['cells'] as $cell)
                                    @php
                                        $tone = is_null($cell)
                                            ? 'bg-zinc-50 text-zinc-300 dark:bg-zinc-800/40 dark:text-zinc-600'
                                            : ($cell >= 75
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                : ($cell >= 50
                                                    ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
                                                    : 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-400'));
                                    @endphp
                                    <td class="pulse-td text-center!">
                                        <span class="inline-flex min-w-10 justify-center rounded-md px-2 py-1 text-xs font-bold {{ $tone }}">{{ $cell ?? '—' }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ $heatmapCycles->count() + 1 }}" class="pulse-table__empty">No data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ── Scorecard Table ───────────────────────────────────────────────────── --}}
    <div class="pulse-card overflow-hidden p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">#</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Employee</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Department</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Score</th>
                        <th class="text-center px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Grade</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Dept Rank</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Company Rank</th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-zinc-500 uppercase tracking-wide">Generated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @forelse($scorecards as $idx => $sc)
                        @php
                            $gradeColors = [
                                'a_plus' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
                                'a'      => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400',
                                'b'      => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                'c'      => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
                                'd'      => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
                            ];
                            $gradeLabels = ['a_plus' => 'A+', 'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
                            $colorCls = $gradeColors[$sc->grade] ?? 'bg-zinc-100 text-zinc-500';
                            $gradeLabel = $gradeLabels[$sc->grade] ?? strtoupper($sc->grade ?? '—');
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50 transition-colors">
                            <td class="px-4 py-3 text-zinc-400 tabular-nums">{{ $idx + 1 }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-zinc-900 dark:text-white">{{ $sc->employee->user?->name ?? '—' }}</div>
                                <div class="text-xs text-zinc-400">{{ $sc->employee->employee_code }}</div>
                            </td>
                            <td class="px-4 py-3 text-zinc-500">{{ $sc->employee->department?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <span class="font-bold text-zinc-900 dark:text-white tabular-nums">{{ number_format($sc->final_score, 1) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 text-xs font-bold rounded {{ $colorCls }}">{{ $gradeLabel }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-zinc-500">{{ $sc->rank_in_department ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-zinc-500">{{ $sc->rank_in_company ?? '—' }}</td>
                            <td class="px-4 py-3 text-zinc-400 text-xs">
                                {{ $sc->generated_at ? $sc->generated_at->format('d M Y') : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-zinc-400">
                                No scorecards found for this cycle. Scorecards are generated when reviews are locked.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @endif

</flux:main>
