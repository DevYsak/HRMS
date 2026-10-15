@php
    $c = $summary['counts'];
    $scoreRows = [
        ['overall', 'Overall QA', 'Test cases passing (known baseline excluded; not tested counts against).'],
        ['security', 'Security', 'Security / AI / export / notification tests, minus open security findings.'],
        ['rbac', 'RBAC', 'Access-control tests, minus open RBAC findings.'],
        ['ui', 'UI / UX', 'Responsive matrix cells passing, minus open UI findings.'],
        ['modules', 'Module readiness', '18 modules × 8 dimensions.'],
        ['demo', 'Demo readiness', '8 roles × 13 demo steps.'],
        ['production', 'Production readiness', 'Average of the above; capped at 25% while NOT READY and at 49% while a Critical or High security/RBAC finding is open.'],
    ];
    $bar = fn (int $v) => $v >= 90 ? 'bg-emerald-500' : ($v >= 70 ? 'bg-sky-500' : ($v >= 40 ? 'bg-amber-500' : 'bg-rose-500'));
    $cards = [
        ['Total', $c['total'], 'clipboard-document-list', 'zinc', ['cases', '']],
        ['Passed', $c['passed'], 'check-circle', 'emerald', ['cases', 'pass']],
        ['Failed', $c['failed'], 'x-circle', 'rose', ['cases', 'fail']],
        ['Partial', $c['partial'], 'adjustments-horizontal', 'amber', ['cases', 'partial']],
        ['Blocked', $c['blocked'], 'no-symbol', 'amber', ['cases', 'blocked']],
        ['Known baseline', $c['known_baseline'], 'archive-box', 'violet', ['cases', 'known_baseline']],
        ['Fixed', $c['fixed'], 'wrench-screwdriver', 'emerald', ['findings', '']],
        ['Pending fix', $c['pending_fix'], 'bug-ant', 'rose', ['findings', 'open']],
        ['Retest required', $c['retest_required'], 'arrow-path', 'sky', ['findings', 'fixed']],
    ];
@endphp

<div class="space-y-5" data-tab-panel="overview">
    {{-- Readiness hero --}}
    <div class="ui-surface grid grid-cols-1 gap-6 p-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <x-pulse.radial :value="$summary['scores']['production']" caption="ready" :accent="$levelTone === 'sky' ? 'blue' : $levelTone" />
            <div class="min-w-0 space-y-2">
                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold tracking-wide ring-1 {{ $soft[$levelTone] }}">{{ $summary['level_label'] }}</span>
                @if($summary['blockers'] !== [])
                    <ul class="space-y-1" data-blockers>
                        @foreach($summary['blockers'] as $blocker)
                            <li class="flex items-start gap-1.5 text-sm font-medium text-rose-600 dark:text-rose-400"><flux:icon.no-symbol class="mt-0.5 size-4 shrink-0" /> {{ $blocker }}</li>
                        @endforeach
                    </ul>
                @endif
                @if($summary['next_steps'] !== [])
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">To reach the next level</p>
                        <ul class="mt-1 space-y-1" data-next-steps>
                            @foreach($summary['next_steps'] as $step)
                                <li class="flex items-start gap-1.5 text-sm text-zinc-600 dark:text-zinc-300"><flux:icon.arrow-right class="mt-0.5 size-3.5 shrink-0 text-zinc-400" /> {{ $step }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if($summary['latest_run'])
                    <p class="text-xs text-zinc-500">Latest run: <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $summary['latest_run']->name }}</span> · {{ $summary['latest_run']->resultLabel() }}</p>
                @endif
            </div>
        </div>
        <div class="space-y-3" data-scores>
            @foreach($scoreRows as [$key, $label, $hint])
                @php $v = $summary['scores'][$key]; @endphp
                <div title="{{ $hint }}">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $label }}</span>
                        <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $v }}%</span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                        <div class="h-full rounded-full {{ $bar($v) }}" style="width: {{ $v }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Count cards (each drills into the filtered list) --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-9" data-count-cards>
        @foreach($cards as [$label, $value, $icon, $tone, [$toTab, $toStatus]])
            <button type="button" wire:click="$set('status', '{{ $toStatus }}'); $set('tab', '{{ $toTab }}')"
                class="ui-surface group flex flex-col items-start gap-2 p-3 text-left transition hover:ring-2 hover:ring-orange-500/30">
                <span class="flex size-8 items-center justify-center rounded-lg ring-1 {{ $soft[$tone] }}"><flux:icon :name="$icon" class="size-4" /></span>
                <span class="text-2xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</span>
                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        {{-- Phases --}}
        <x-pulse.card title="Audit phases" icon="flag" flush>
            <x-slot:actions>
                <flux:button size="xs" variant="ghost" wire:click="setTab('phases')">All phases</flux:button>
            </x-slot:actions>
            <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($phaseRows as $p)
                    <li class="flex items-center gap-3 px-5 py-3">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-bold text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ $p->number }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-zinc-800 sm:truncate dark:text-zinc-100">{{ $p->name }}</p>
                            <p class="text-xs text-zinc-500">{{ $p->passed_count }}/{{ $p->test_cases_count }} passed · {{ $p->open_findings_count }} open finding(s)</p>
                        </div>
                        <flux:badge size="sm" :color="$p->status->color()">{{ $p->status->label() }}</flux:badge>
                    </li>
                @endforeach
            </ul>
        </x-pulse.card>

        {{-- Open findings --}}
        <x-pulse.card title="Open findings" icon="bug-ant" flush>
            <x-slot:actions>
                <flux:button size="xs" variant="ghost" wire:click="setTab('findings')">All findings</flux:button>
            </x-slot:actions>
            @forelse($recentFindings as $f)
                <button type="button" wire:click="openFinding({{ $f->id }})" class="flex w-full items-center gap-3 border-b border-zinc-100 px-5 py-3 text-left last:border-0 hover:bg-zinc-50 dark:border-white/5 dark:hover:bg-white/5">
                    <flux:badge size="sm" :color="$f->severity->color()">{{ $f->severity->label() }}</flux:badge>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $f->title }}</p>
                        <p class="text-xs text-zinc-500"><span class="font-mono">{{ $f->code }}</span> · {{ $f->module }}</p>
                    </div>
                    <flux:badge size="sm" :color="$f->status->color()">{{ $f->status->label() }}</flux:badge>
                </button>
            @empty
                <x-pulse.empty-state icon="check-badge" title="No open findings" />
            @endforelse
        </x-pulse.card>
    </div>
</div>
