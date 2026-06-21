<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">

    @php
        $gradeColors = [
            'a_plus' => 'text-emerald-700 bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400',
            'a' => 'text-green-700 bg-green-100 dark:bg-green-900/30 dark:text-green-400',
            'b' => 'text-blue-700 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400',
            'c' => 'text-amber-700 bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400',
            'd' => 'text-red-700 bg-red-100 dark:bg-red-900/30 dark:text-red-400',
        ];
        $gradeLabels = ['a_plus' => 'A+', 'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
        $grade = $scorecard?->grade;
        $gradeBadgeClass = $gradeColors[$grade] ?? 'text-zinc-600 bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-400';
        $gradeLabelText = $gradeLabels[$grade] ?? 'N/A';

        $achievementBadge = function ($pct) {
            return match (true) {
                $pct >= 100 => ['Excellent', 'text-emerald-700 bg-emerald-100 dark:bg-emerald-900/30 dark:text-emerald-400'],
                $pct >= 85 => ['Good', 'text-blue-700 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400'],
                $pct >= 70 => ['Average', 'text-amber-700 bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400'],
                $pct >= 50 => ['Needs Improvement', 'text-orange-700 bg-orange-100 dark:bg-orange-900/30 dark:text-orange-400'],
                default => ['Critical', 'text-red-700 bg-red-100 dark:bg-red-900/30 dark:text-red-400'],
            };
        };

        $trend = ($scorecard && $previousScorecard)
            ? round($scorecard->final_score - $previousScorecard->final_score, 1)
            : null;

        $indicators = [
            ['label' => 'Attendance Score', 'value' => $scorecard?->attendance_score],
            ['label' => 'Punctuality Score', 'value' => $scorecard?->punctuality_score],
            ['label' => 'KPI Achievement', 'value' => $kpiAchievement],
            ['label' => 'Task Completion', 'value' => $taskCompletion],
            ['label' => 'Leave Compliance', 'value' => $scorecard?->leave_impact ?? $review?->leave_impact_score],
            ['label' => 'Shift Compliance', 'value' => $shiftCompliance],
            ['label' => 'Manager Rating', 'value' => $managerRatingPercent, 'raw' => $scorecard?->manager_rating],
        ];

        $eventColorClasses = [
            'green' => 'text-emerald-600 bg-emerald-100 dark:bg-emerald-900/30',
            'red' => 'text-red-600 bg-red-100 dark:bg-red-900/30',
            'orange' => 'text-orange-600 bg-orange-100 dark:bg-orange-900/30',
            'blue' => 'text-blue-600 bg-blue-100 dark:bg-blue-900/30',
            'zinc' => 'text-zinc-500 bg-zinc-100 dark:bg-zinc-800',
        ];

        $warningTypeClasses = [
            'blue' => 'text-blue-700 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400',
            'amber' => 'text-amber-700 bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400',
            'orange' => 'text-orange-700 bg-orange-100 dark:bg-orange-900/30 dark:text-orange-400',
            'red' => 'text-red-700 bg-red-100 dark:bg-red-900/30 dark:text-red-400',
            'rose' => 'text-rose-700 bg-rose-100 dark:bg-rose-900/30 dark:text-rose-400',
            'zinc' => 'text-zinc-700 bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-400',
        ];

        $promoLabel = match (true) {
            $promotionStats['approved'] > 0 => 'Approved',
            $promotionStats['under_review'] > 0 => 'Under Review',
            $promotionStats['eligible'] > 0 => 'Eligible',
            default => 'Not Evaluated',
        };
    @endphp

    {{-- ===== HERO ===== --}}
    <div class="pulse-hero">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>

        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5 mb-3">
                    <span class="inline-flex items-center px-3 py-1 bg-white/10 border border-white/10 rounded-full text-[11px] font-semibold text-white/70">
                        My Performance
                    </span>
                    @if($grade)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wide {{ $gradeBadgeClass }}">
                            Grade {{ $gradeLabelText }}
                        </span>
                    @endif
                </div>
                <h1 class="text-3xl font-black text-white tracking-tight">{{ $employee->user->name }}</h1>
                <p class="text-white/55 text-sm mt-1.5">
                    {{ $employee->jobTitle?->name ?? '—' }} &middot; {{ $employee->department?->name ?? '—' }}
                </p>
            </div>

            <div class="flex items-center gap-2">
                <flux:select wire:model.live="selectedCycleId" class="w-56">
                    @foreach($cycles as $c)
                        <flux:select.option value="{{ $c->id }}">{{ $c->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-6">

        @if(! $cycle)
            <div class="pulse-card flex flex-col items-center justify-center py-20 text-center">
                <flux:icon.chart-bar class="size-12 text-zinc-300 dark:text-zinc-600 mb-3" />
                <p class="text-zinc-400">No performance cycle data found for your profile.</p>
            </div>
        @else

        {{-- ===== SCORE WIDGET ROW ===== --}}
        <div class="pulse-card">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                {{-- Score circle --}}
                <div class="flex flex-col items-center justify-center text-center border-b lg:border-b-0 lg:border-r border-zinc-100 dark:border-zinc-800 pb-6 lg:pb-0 lg:pr-6">
                    <x-performance.score-circle :score="$scorecard?->final_score ?? 0" :grade="$grade" label="Overall Score" />

                    <div class="mt-4 space-y-1">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $cycle->name }}</div>
                        <span class="badge-{{ $cycle->statusColor() }}">{{ strtoupper($cycle->statusLabel()) }}</span>

                        @if($trend !== null)
                            <div class="flex items-center justify-center gap-1 mt-2 text-xs font-bold
                                {{ $trend > 0 ? 'text-emerald-600' : ($trend < 0 ? 'text-red-500' : 'text-zinc-400') }}">
                                @if($trend > 0)
                                    <flux:icon.arrow-trending-up class="size-3.5" />
                                @elseif($trend < 0)
                                    <flux:icon.arrow-trending-down class="size-3.5" />
                                @else
                                    <flux:icon.minus class="size-3.5" />
                                @endif
                                {{ $trend > 0 ? '+' : '' }}{{ $trend }} pts vs last cycle
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Indicators --}}
                <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-4 content-center">
                    @foreach($indicators as $indicator)
                        @php
                            $val = $indicator['value'];
                            $pct = $val !== null ? max(0, min(100, (float) $val)) : 0;
                            $barColor = $pct >= 85 ? 'from-emerald-400 to-emerald-600' : ($pct >= 70 ? 'from-blue-400 to-blue-600' : ($pct >= 50 ? 'from-amber-400 to-amber-600' : 'from-red-400 to-red-600'));
                        @endphp
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">{{ $indicator['label'] }}</span>
                                <span class="text-xs font-bold text-zinc-900 dark:text-white tabular-nums">
                                    @if($val === null)
                                        —
                                    @elseif(isset($indicator['raw']) && $indicator['raw'] <= 5)
                                        {{ number_format($indicator['raw'], 1) }}/5
                                    @else
                                        {{ number_format($val, 0) }}%
                                    @endif
                                </span>
                            </div>
                            <div class="h-2.5 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-2.5 rounded-full bg-gradient-to-r {{ $barColor }} transition-all" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ===== TOP PERFORMANCE CARDS ===== --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-1">
                    <flux:icon.clipboard-document-check class="size-4 text-zinc-500" />
                </div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white tabular-nums">{{ $stats['kpis_total'] }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Total KPIs</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-900/20 mb-1">
                    <flux:icon.check-circle class="size-4 text-emerald-500" />
                </div>
                <div class="text-2xl font-black text-emerald-600 tabular-nums">{{ $stats['kpis_completed'] }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Completed</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-900/20 mb-1">
                    <flux:icon.clock class="size-4 text-amber-500" />
                </div>
                <div class="text-2xl font-black text-amber-500 tabular-nums">{{ $stats['kpis_pending'] }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Pending</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-900/20 mb-1">
                    <flux:icon.star class="size-4 text-indigo-500" />
                </div>
                <div class="text-2xl font-black text-indigo-600 tabular-nums">{{ $scorecard ? number_format($scorecard->final_score, 1) : '—' }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Overall Score</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/20 mb-1">
                    <flux:icon.calendar-days class="size-4 text-blue-500" />
                </div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white tabular-nums">{{ $scorecard?->attendance_score !== null ? number_format($scorecard->attendance_score, 0) . '%' : '—' }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Attendance</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full {{ $promoLabel === 'Approved' ? 'bg-emerald-50 dark:bg-emerald-900/20' : 'bg-zinc-100 dark:bg-zinc-800' }} mb-1">
                    <flux:icon.arrow-trending-up class="size-4 {{ $promoLabel === 'Approved' ? 'text-emerald-500' : ($promoLabel === 'Under Review' ? 'text-amber-500' : 'text-zinc-400') }}" />
                </div>
                <div class="text-sm font-black {{ $promoLabel === 'Approved' ? 'text-emerald-600' : ($promoLabel === 'Under Review' ? 'text-amber-500' : 'text-zinc-700 dark:text-zinc-300') }}">{{ $promoLabel }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Promotion</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full {{ $stats['open_warnings'] > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-zinc-100 dark:bg-zinc-800' }} mb-1">
                    <flux:icon.exclamation-triangle class="size-4 {{ $stats['open_warnings'] > 0 ? 'text-red-500' : 'text-zinc-400' }}" />
                </div>
                <div class="text-2xl font-black {{ $stats['open_warnings'] > 0 ? 'text-red-500' : 'text-zinc-900 dark:text-white' }} tabular-nums">{{ $stats['open_warnings'] }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Warnings</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5">
                <div class="flex size-8 items-center justify-center rounded-full {{ $stats['has_active_pip'] ? 'bg-red-50 dark:bg-red-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20' }} mb-1">
                    <flux:icon.chart-bar class="size-4 {{ $stats['has_active_pip'] ? 'text-red-500' : 'text-emerald-500' }}" />
                </div>
                <div class="text-sm font-black {{ $stats['has_active_pip'] ? 'text-red-500' : 'text-emerald-600' }}">{{ $stats['has_active_pip'] ? 'Active' : 'None' }}</div>
                <div class="text-[11px] font-medium text-zinc-400">PIP Status</div>
            </div>
            <div class="pulse-card flex flex-col items-center text-center gap-1.5 py-5 lg:col-span-2">
                <div class="flex size-8 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 mb-1">
                    <flux:icon.user-circle class="size-4 text-zinc-500" />
                </div>
                <div class="text-2xl font-black text-zinc-900 dark:text-white tabular-nums">{{ $stats['manager_reviews_pending'] }}</div>
                <div class="text-[11px] font-medium text-zinc-400">Manager Reviews Pending</div>
            </div>
        </div>

        {{-- ===== KPI HIGHLIGHTS ===== --}}
        @if($scorecardRows->isNotEmpty())
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <div class="pulse-card">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                        <flux:icon.arrow-trending-up class="size-4 text-emerald-500" />
                        Top Performing KPIs
                    </h3>
                    <div class="space-y-3">
                        @forelse($topPerforming as $row)
                            @php [$badgeLabel, $badgeClass] = $achievementBadge($row->achievement); @endphp
                            <div class="flex items-center justify-between gap-3 border border-zinc-100 dark:border-zinc-800 rounded-lg p-3">
                                <div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $row->name }}</div>
                                    <div class="text-xs text-zinc-400 mt-0.5">Weight {{ $row->weight !== null ? number_format($row->weight, 0) . '%' : '—' }} &middot; Target {{ $row->target ?? '—' }}</div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ number_format($row->achievement, 0) }}%</div>
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-400">No data available.</p>
                        @endforelse
                    </div>
                </div>

                <div class="pulse-card">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                        <flux:icon.arrow-trending-down class="size-4 text-red-500" />
                        Areas Needing Improvement
                    </h3>
                    <div class="space-y-3">
                        @forelse($needsImprovement as $row)
                            @php [$badgeLabel, $badgeClass] = $achievementBadge($row->achievement); @endphp
                            <div class="flex items-center justify-between gap-3 border border-zinc-100 dark:border-zinc-800 rounded-lg p-3">
                                <div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white">{{ $row->name }}</div>
                                    <div class="text-xs text-zinc-400 mt-0.5">Weight {{ $row->weight !== null ? number_format($row->weight, 0) . '%' : '—' }} &middot; Target {{ $row->target ?? '—' }}</div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ number_format($row->achievement, 0) }}%</div>
                                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-zinc-400">No data available.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        {{-- ===== KPI SCORECARD TABLE ===== --}}
        <div class="pulse-card overflow-hidden !p-0">
            <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center gap-2">
                <div class="flex size-6 items-center justify-center rounded-md bg-indigo-100 dark:bg-indigo-900/30">
                    <flux:icon.table-cells class="size-3.5 text-indigo-600 dark:text-indigo-400" />
                </div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">KPI Scorecard</h3>
                @if($scorecardRows->isNotEmpty())
                    <span class="ml-auto text-xs text-zinc-400">{{ $scorecardRows->count() }} {{ Str::plural('component', $scorecardRows->count()) }}</span>
                @endif
            </div>
            @if($scorecardRows->isEmpty())
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <flux:icon.clipboard-document-list class="size-10 text-zinc-300 mb-3" />
                    <p class="text-zinc-400 text-sm">No KPI scorecard data for this cycle.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-zinc-50/80 dark:bg-zinc-950/60 border-b border-zinc-100 dark:border-zinc-800">
                                <th class="text-left py-3 pl-6 pr-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap min-w-[180px]">KPI Component</th>
                                <th class="text-left py-3 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap min-w-[120px]">Category</th>
                                <th class="text-right py-3 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap">Weight</th>
                                <th class="text-right py-3 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap">Target</th>
                                <th class="text-right py-3 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap">Actual</th>
                                <th class="text-right py-3 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap">Achievement %</th>
                                <th class="text-left py-3 px-4 text-[10px] font-bold text-zinc-400 uppercase tracking-wider whitespace-nowrap">Status</th>
                                <th class="text-left py-3 pl-4 pr-6 text-[10px] font-bold text-zinc-400 uppercase tracking-wider min-w-[160px]">Manager Feedback</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @foreach($scorecardRows as $row)
                                @php [$badgeLabel, $badgeClass] = $achievementBadge($row->achievement); @endphp
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors">
                                    <td class="py-3.5 pl-6 pr-4 font-semibold text-zinc-900 dark:text-white">{{ $row->name }}</td>
                                    <td class="py-3.5 px-4 text-zinc-500 dark:text-zinc-400">{{ $row->category ?? '—' }}</td>
                                    <td class="py-3.5 px-4 text-right tabular-nums text-zinc-700 dark:text-zinc-300 font-medium">{{ $row->weight !== null ? number_format($row->weight, 0) . '%' : '—' }}</td>
                                    <td class="py-3.5 px-4 text-right tabular-nums text-zinc-500 dark:text-zinc-400">{{ $row->target ?? '—' }}</td>
                                    <td class="py-3.5 px-4 text-right tabular-nums font-bold text-zinc-900 dark:text-white">{{ $row->actual ?? '—' }}</td>
                                    <td class="py-3.5 px-4 text-right">
                                        @php $ach = (float) $row->achievement; @endphp
                                        <span class="tabular-nums font-bold {{ $ach >= 85 ? 'text-emerald-600' : ($ach >= 70 ? 'text-blue-600' : ($ach >= 50 ? 'text-amber-600' : 'text-red-500')) }}">
                                            {{ number_format($ach, 0) }}%
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <span class="inline-flex items-center px-2.5 py-1 text-[10px] font-bold rounded-lg {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    </td>
                                    <td class="py-3.5 pl-4 pr-6 text-zinc-500 dark:text-zinc-400 text-xs">
                                        {{ $row->feedback ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @endif {{-- end @if($cycle) --}}

        {{-- ===== PERFORMANCE TIMELINE ===== --}}
        <div class="pulse-card">
            <div class="flex items-center gap-2 mb-6">
                <div class="flex size-6 items-center justify-center rounded-md bg-indigo-100 dark:bg-indigo-900/30">
                    <flux:icon.clock class="size-3.5 text-indigo-600 dark:text-indigo-400" />
                </div>
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Performance Timeline</h3>
                @if($timelines->isNotEmpty())
                    <span class="ml-auto text-xs text-zinc-400">{{ $timelines->count() }} {{ Str::plural('event', $timelines->count()) }}</span>
                @endif
            </div>
            @if($timelines->isEmpty())
                <div class="flex flex-col items-center justify-center py-10 text-center">
                    <flux:icon.clock class="size-10 text-zinc-200 dark:text-zinc-700 mb-3" />
                    <p class="text-sm text-zinc-400">No timeline events recorded yet.</p>
                </div>
            @else
                <div class="relative">
                    {{-- Vertical connector line --}}
                    <div class="absolute left-[15px] top-4 bottom-4 w-px bg-zinc-100 dark:bg-zinc-800"></div>

                    <div class="space-y-1">
                        @foreach($timelines as $event)
                            @php $colorClass = $eventColorClasses[$event->eventColor()] ?? $eventColorClasses['zinc']; @endphp
                            <div class="relative flex items-start gap-4 py-3">
                                {{-- Icon dot (sits on the vertical line) --}}
                                <div class="relative z-10 flex size-[30px] shrink-0 items-center justify-center rounded-full {{ $colorClass }} ring-2 ring-white dark:ring-zinc-900">
                                    <flux:icon :name="$event->eventIcon()" class="size-3.5" />
                                </div>
                                {{-- Content --}}
                                <div class="flex-1 min-w-0 pt-0.5">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white leading-tight">{{ $event->title }}</span>
                                        <span class="text-[11px] text-zinc-400 tabular-nums">{{ $event->event_date?->format('d M Y') }}</span>
                                    </div>
                                    @if($event->description)
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 leading-relaxed">{{ $event->description }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- ===== WARNING LETTERS ===== --}}
        <div class="space-y-4">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold {{ $warningStats['active'] > 0 ? 'text-red-500' : 'text-zinc-900 dark:text-white' }}">{{ $warningStats['active'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Active Warnings</div>
                </div>
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-emerald-600">{{ $warningStats['acknowledged'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Acknowledged</div>
                </div>
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-amber-500">{{ $warningStats['pending_ack'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Pending Acknowledgement</div>
                </div>
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-orange-500">{{ $warningStats['escalated'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Escalated</div>
                </div>
            </div>

            <div class="pulse-card">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                    <flux:icon.exclamation-triangle class="size-4 text-amber-500" />
                    Warning History
                </h3>
                @if($warnings->isEmpty())
                    <div class="flex flex-col items-center justify-center py-8 text-center">
                        <flux:icon.shield-check class="size-10 text-emerald-300 mb-3" />
                        <p class="text-zinc-400">Clean record &mdash; no warning letters issued.</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($warnings as $warning)
                            @php $typeClass = $warningTypeClasses[$warning->warningTypeColor()] ?? $warningTypeClasses['zinc']; @endphp
                            <div class="flex items-center justify-between gap-3 border border-zinc-100 dark:border-zinc-800 rounded-lg p-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded {{ $typeClass }}">{{ strtoupper($warning->warningTypeLabel()) }}</span>
                                        <span class="badge-{{ $warning->statusColor() }}">{{ strtoupper($warning->statusLabel()) }}</span>
                                    </div>
                                    <div class="text-sm font-medium text-zinc-900 dark:text-white mt-1.5">{{ $warning->reason }}</div>
                                    <div class="text-xs text-zinc-400 mt-0.5">
                                        Issued {{ $warning->issue_date?->format('d M Y') }}
                                        @if($warning->next_review_date)
                                            &middot; Next Review {{ $warning->next_review_date->format('d M Y') }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ===== PERFORMANCE IMPROVEMENT PLAN ===== --}}
        <div class="pulse-card">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.chart-bar class="size-4 text-orange-500" />
                Performance Improvement Plan
            </h3>

            @if(! $activePip)
                <div class="flex flex-col items-center justify-center py-8 text-center">
                    <flux:icon.check-circle class="size-10 text-emerald-300 mb-3" />
                    <p class="text-zinc-400">No active improvement plan.</p>
                </div>
            @else
                @php
                    $progress = $activePip->overallProgress();
                    $goalsCompleted = $activePip->goals->whereIn('status', ['achieved'])->count();
                    $goalsPending = $activePip->goals->whereIn('status', ['not_started', 'in_progress'])->count();
                    $reviewerName = match ($activePip->current_reviewer_stage) {
                        'manager' => $activePip->manager?->name,
                        'hr' => $activePip->hrReviewer?->name,
                        'dept_head' => $activePip->departmentHead?->name,
                        'super_admin' => 'Super Admin',
                        default => '—',
                    };
                @endphp

                <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-5">
                    <div class="lg:col-span-2">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-300">PIP Progress</span>
                            <span class="text-xs font-bold text-zinc-900 dark:text-white">{{ number_format($progress, 0) }}%</span>
                        </div>
                        <div class="h-2.5 rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div class="h-2.5 rounded-full bg-gradient-to-r from-orange-400 to-orange-600 transition-all" style="width: {{ min($progress, 100) }}%"></div>
                        </div>
                        <span class="badge-{{ $activePip->statusColor() }} mt-2 inline-block">{{ strtoupper($activePip->statusLabel()) }}</span>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400">Goals Completed</div>
                        <div class="text-2xl font-bold text-emerald-600">{{ $goalsCompleted }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400">Goals Pending</div>
                        <div class="text-2xl font-bold text-amber-500">{{ $goalsPending }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400">Current Reviewer</div>
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $reviewerName ?? '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-zinc-400">Expected Completion</div>
                        <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $activePip->end_date?->format('d M Y') ?? '—' }}</div>
                    </div>
                </div>

                @if($activePip->goals->isNotEmpty())
                    <div class="space-y-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        @foreach($activePip->goals as $goal)
                            @php $gPct = min((float) ($goal->progress_percent ?? 0), 100); @endphp
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $goal->title }}</span>
                                    <span class="text-xs font-bold text-zinc-600 dark:text-zinc-300">{{ $gPct }}%</span>
                                </div>
                                <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="h-2 rounded-full bg-gradient-to-r from-indigo-400 to-indigo-600" style="width: {{ $gPct }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- ===== PROMOTIONS & REWARDS ===== --}}
        <div class="space-y-4">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-blue-500">{{ $promotionStats['eligible'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Eligible</div>
                </div>
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-amber-500">{{ $promotionStats['under_review'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Under Review</div>
                </div>
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-emerald-600">{{ $promotionStats['approved'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Approved</div>
                </div>
                <div class="pulse-card text-center">
                    <div class="text-3xl font-bold text-red-500">{{ $promotionStats['rejected'] }}</div>
                    <div class="text-xs text-zinc-400 mt-1">Rejected</div>
                </div>
            </div>

            <div class="pulse-card">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                    <flux:icon.star class="size-4 text-purple-500" />
                    Promotion &amp; Reward Recommendations
                </h3>
                @if($promotions->isEmpty())
                    <p class="text-sm text-zinc-400 text-center py-6">No recommendations on record.</p>
                @else
                    <div class="space-y-3">
                        @foreach($promotions as $rec)
                            <div class="border border-zinc-100 dark:border-zinc-800 rounded-lg p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $rec->recommendationTypeLabel() }}</div>
                                        @if($rec->incrementPercent() !== null)
                                            <div class="text-xs text-zinc-400 mt-0.5">Increment: {{ $rec->incrementPercent() }}%</div>
                                        @endif
                                        @if($rec->proposed_role)
                                            <div class="text-xs text-zinc-400 mt-0.5">Proposed Role: {{ $rec->proposed_role }}</div>
                                        @endif
                                    </div>
                                    <span class="badge-{{ $rec->statusColor() }} px-2 py-0.5 shrink-0">{{ strtoupper($rec->statusLabel()) }}</span>
                                </div>
                                @if($rec->justification)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2 italic">{{ $rec->justification }}</p>
                                @endif
                                @if($rec->hr_comments || $rec->dept_head_comments)
                                    <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-800 text-xs text-zinc-500 dark:text-zinc-400 space-y-1">
                                        @if($rec->hr_comments)
                                            <div><span class="font-semibold">HR:</span> {{ $rec->hr_comments }}</div>
                                        @endif
                                        @if($rec->dept_head_comments)
                                            <div><span class="font-semibold">Dept Head:</span> {{ $rec->dept_head_comments }}</div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

    </div>
</flux:main>
