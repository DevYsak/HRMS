<flux:main class="bg-zinc-50 p-6 dark:bg-zinc-950 min-h-screen">

    <div class="pulse-page-header mb-6">
        <div>
            <h1 class="pulse-page-title">My KPIs</h1>
            <p class="pulse-page-subtitle">Track your performance goals and progress</p>
        </div>
        <flux:select wire:model.live="selectedCycleId" class="w-56">
            <flux:select.option value="">— Select Cycle —</flux:select.option>
            @foreach($cycles as $cycle)
                <flux:select.option value="{{ $cycle->id }}">{{ $cycle->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if(! $selectedCycleId)
        <div class="pulse-card flex flex-col items-center justify-center py-20 text-center">
            <flux:icon.clipboard-document-check class="size-12 text-zinc-300 dark:text-zinc-600 mb-3" />
            <p class="text-zinc-400">No active KPI cycle found for your profile.</p>
        </div>
    @else

    {{-- ── Summary Bar ─────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-zinc-900 dark:text-white">{{ $kpis->count() }}</div>
            <div class="text-xs text-zinc-400 mt-1">Total KPIs</div>
        </div>
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-green-600">{{ $kpis->where('status', 'achieved')->count() }}</div>
            <div class="text-xs text-zinc-400 mt-1">Achieved</div>
        </div>
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-amber-500">{{ $kpis->where('status', 'in_progress')->count() }}</div>
            <div class="text-xs text-zinc-400 mt-1">In Progress</div>
        </div>
        <div class="pulse-card text-center">
            <div class="text-3xl font-bold text-red-500">{{ $kpis->where('status', 'missed')->count() }}</div>
            <div class="text-xs text-zinc-400 mt-1">Missed</div>
        </div>
    </div>

    {{-- ── Overall Progress ─────────────────────────────────────────────────── --}}
    <div class="pulse-card mb-6">
        <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">Overall Progress</div>
            <div class="text-sm font-bold text-indigo-600">{{ $overallProgress }}%</div>
        </div>
        <div class="h-2.5 rounded-full bg-zinc-100 dark:bg-zinc-800">
            <div
                class="h-2.5 rounded-full bg-gradient-to-r from-indigo-400 to-indigo-600 transition-all"
                style="width: {{ min($overallProgress, 100) }}%"
            ></div>
        </div>

        @if($scorecard)
            <div class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800 flex items-center gap-6">
                @php
                    $gradeColors = [
                        'a_plus' => 'text-green-600 bg-green-100',
                        'a'      => 'text-emerald-600 bg-emerald-100',
                        'b'      => 'text-blue-600 bg-blue-100',
                        'c'      => 'text-amber-600 bg-amber-100',
                        'd'      => 'text-red-600 bg-red-100',
                    ];
                    $gradeLabels = ['a_plus' => 'A+', 'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
                    $gc = $gradeColors[$scorecard->grade] ?? 'text-zinc-600 bg-zinc-100';
                    $gl = $gradeLabels[$scorecard->grade] ?? strtoupper($scorecard->grade);
                @endphp
                <div>
                    <div class="text-xs text-zinc-400">Final Score</div>
                    <div class="text-xl font-bold text-zinc-900 dark:text-white">{{ number_format($scorecard->final_score, 1) }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-400">Grade</div>
                    <span class="px-3 py-1 text-sm font-bold rounded-lg {{ $gc }}">{{ $gl }}</span>
                </div>
                @if($scorecard->rank_in_department)
                <div>
                    <div class="text-xs text-zinc-400">Dept Rank</div>
                    <div class="text-xl font-bold text-zinc-900 dark:text-white">#{{ $scorecard->rank_in_department }}</div>
                </div>
                @endif
                @if($scorecard->rank_in_company)
                <div>
                    <div class="text-xs text-zinc-400">Company Rank</div>
                    <div class="text-xl font-bold text-zinc-900 dark:text-white">#{{ $scorecard->rank_in_company }}</div>
                </div>
                @endif
            </div>
        @endif
    </div>

    {{-- ── KPI List by Category ─────────────────────────────────────────────── --}}
    @php $grouped = $kpis->groupBy(fn ($k) => $k->component?->category?->name ?? 'Uncategorized'); @endphp

    @forelse($grouped as $categoryName => $categoryKpis)
        <div class="pulse-card mb-4">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <span class="size-2 rounded-full bg-indigo-400 inline-block"></span>
                {{ $categoryName }}
            </h3>

            <div class="space-y-4">
                @foreach($categoryKpis as $kpi)
                    @php
                        $statusColors = [
                            'not_started' => 'text-zinc-400 bg-zinc-100 dark:bg-zinc-800',
                            'in_progress' => 'text-amber-600 bg-amber-100 dark:bg-amber-900/30',
                            'achieved'    => 'text-green-600 bg-green-100 dark:bg-green-900/30',
                            'missed'      => 'text-red-600 bg-red-100 dark:bg-red-900/30',
                        ];
                        $statusLabels = ['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'achieved' => 'Achieved', 'missed' => 'Missed'];
                        $sc = $statusColors[$kpi->status] ?? 'text-zinc-400 bg-zinc-100';
                        $sl = $statusLabels[$kpi->status] ?? $kpi->status;
                        $pct = min((float) $kpi->progress_percent, 100);
                        $barColor = $pct >= 100 ? 'bg-green-400' : ($pct >= 50 ? 'bg-indigo-400' : ($pct > 0 ? 'bg-amber-400' : 'bg-zinc-200 dark:bg-zinc-700'));
                    @endphp

                    <div class="border border-zinc-100 dark:border-zinc-800 rounded-lg p-4">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div>
                                <div class="font-medium text-zinc-900 dark:text-white text-sm">{{ $kpi->component?->name ?? '—' }}</div>
                                @if($kpi->due_date)
                                    <div class="text-xs text-zinc-400 mt-0.5">Due {{ $kpi->due_date->format('d M Y') }}</div>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="px-2 py-0.5 text-xs font-semibold rounded {{ $sc }}">{{ $sl }}</span>
                                @if(in_array($kpi->status, ['not_started', 'in_progress']))
                                    <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openUpdate({{ $kpi->id }})">Update</flux:button>
                                @endif
                            </div>
                        </div>

                        {{-- Progress bar --}}
                        <div class="flex items-center gap-3">
                            <div class="flex-1 h-2 bg-zinc-100 dark:bg-zinc-800 rounded-full">
                                <div class="{{ $barColor }} h-2 rounded-full transition-all" style="width: {{ $pct }}%"></div>
                            </div>
                            <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-400 w-10 text-right tabular-nums">{{ $pct }}%</span>
                        </div>

                        @if($kpi->notes)
                            <p class="text-xs text-zinc-400 mt-2 italic">{{ $kpi->notes }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="pulse-card flex flex-col items-center justify-center py-12 text-center">
            <flux:icon.clipboard-document-list class="size-10 text-zinc-300 mb-3" />
            <p class="text-zinc-400">No KPIs assigned for this cycle.</p>
        </div>
    @endforelse

    @endif

    {{-- ── Update Progress Modal ─────────────────────────────────────────────── --}}
    <flux:modal wire:model="showUpdateModal" class="max-w-sm">
        <div class="space-y-4 p-6">
            <flux:heading size="lg">Update KPI Progress</flux:heading>
            <flux:field>
                <flux:label>Progress (%) <span class="text-red-500">*</span></flux:label>
                <flux:input type="number" wire:model="progressInput" min="0" max="100" step="1" />
                <flux:error name="progressInput" />
            </flux:field>
            <flux:field>
                <flux:label>Notes</flux:label>
                <flux:textarea wire:model="notesInput" rows="3" placeholder="Optional update notes…" />
            </flux:field>
            <div class="flex justify-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <flux:button wire:click="$set('showUpdateModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="saveProgress" variant="primary">Save</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
