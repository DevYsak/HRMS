<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-tab-panel="phases">
    @foreach($phaseRows as $p)
        @php $commits = $p->findings->flatMap->fixes->map->shortHash()->unique()->values(); @endphp
        <div class="ui-surface flex flex-col gap-3 p-5" data-phase="{{ $p->number }}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Phase {{ $p->number }}</p>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $p->name }}</h3>
                </div>
                <flux:badge size="sm" :color="$p->status->color()">{{ $p->status->label() }}</flux:badge>
            </div>
            @if($p->notes)
                <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $p->notes }}</p>
            @endif
            <dl class="grid grid-cols-4 gap-2 text-center">
                @foreach([['Tests', $p->test_cases_count], ['Passed', $p->passed_count], ['Failed', $p->failed_count], ['Open', $p->open_findings_count]] as [$label, $value])
                    <div class="rounded-lg bg-zinc-50 py-2 dark:bg-white/5">
                        <dd class="text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</dd>
                        <dt class="text-[11px] text-zinc-500">{{ $label }}</dt>
                    </div>
                @endforeach
            </dl>
            <div class="space-y-1 text-xs text-zinc-500">
                <p>Owner: <span class="text-zinc-700 dark:text-zinc-200">{{ $p->owner ?? '—' }}</span></p>
                <p>Started: <span class="text-zinc-700 dark:text-zinc-200">{{ $p->started_at?->format('d M Y') ?? '—' }}</span> · Completed: <span class="text-zinc-700 dark:text-zinc-200">{{ $p->completed_at?->format('d M Y') ?? '—' }}</span></p>
                @if($commits->isNotEmpty())
                    <p class="flex flex-wrap gap-1">Commits:
                        @foreach($commits as $hash)
                            <button type="button" wire:click="$set('commitRef', '{{ $hash }}'); $set('tab', 'fixes')" class="rounded bg-zinc-100 px-1.5 font-mono text-[11px] text-zinc-700 hover:bg-orange-100 dark:bg-white/10 dark:text-zinc-200">{{ $hash }}</button>
                        @endforeach
                    </p>
                @endif
            </div>
            <div class="mt-auto flex gap-2">
                <flux:button size="xs" variant="ghost" wire:click="$set('phase', '{{ $p->id }}'); $set('tab', 'cases')">Test cases</flux:button>
                <flux:button size="xs" variant="ghost" wire:click="$set('phase', '{{ $p->id }}'); $set('tab', 'findings')">Findings</flux:button>
                @if($canManage)
                    <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editPhase({{ $p->id }})" class="ml-auto">Update</flux:button>
                @endif
            </div>
        </div>
    @endforeach
</div>
