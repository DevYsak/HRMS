<div class="space-y-3" data-tab-panel="runs">
    @forelse($runs as $run)
        @php $pct = $run->total > 0 ? round($run->passed / $run->total * 100, 1) : 0; @endphp
        <div wire:key="run-{{ $run->id }}" class="ui-surface space-y-3 p-4" data-run="{{ $run->id }}">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="font-semibold text-zinc-900 dark:text-white">{{ $run->name }}</p>
                    <p class="text-xs text-zinc-500">
                        {{ \App\Models\QaTestRun::SUITES[$run->suite] ?? ucfirst($run->suite) }} ·
                        {{ \App\Enums\QaEnvironment::tryFrom($run->environment)?->label() ?? $run->environment }} ·
                        @if($run->commit)<span class="font-mono">{{ substr($run->commit, 0, 7) }}</span> · @endif
                        {{ $run->run_at->format('d M Y H:i') }}
                        @if($run->duration_seconds) · {{ gmdate($run->duration_seconds >= 3600 ? 'G\h i\m' : 'i\m s\s', $run->duration_seconds) }}@endif
                        @if($run->phase) · Phase {{ $run->phase->number }}@endif
                    </p>
                </div>
                <flux:badge :color="$run->resultColor()">{{ $run->resultLabel() }}</flux:badge>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                <div class="h-full bg-emerald-500" style="width: {{ $pct }}%"></div>
            </div>
            <dl class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
                @foreach([['Total', $run->total, ''], ['Passed', $run->passed, 'text-emerald-600'], ['Failed', $run->failed, $run->failed ? 'text-rose-600' : ''], ['Skipped', $run->skipped, ''], ['Known baseline', $run->known_baseline, $run->known_baseline ? 'text-violet-600' : ''], ['New regressions', $run->new_regressions, $run->new_regressions ? 'text-rose-600' : 'text-emerald-600']] as [$label, $value, $tone])
                    <div class="rounded-lg bg-zinc-50 py-2 dark:bg-white/5">
                        <dd class="text-lg font-semibold tabular-nums {{ $tone ?: 'text-zinc-900 dark:text-white' }}">{{ $value }}</dd>
                        <dt class="text-[11px] text-zinc-500">{{ $label }}</dt>
                    </div>
                @endforeach
            </dl>
            @if($run->notes)
                <details class="text-xs text-zinc-500">
                    <summary class="cursor-pointer select-none">Notes</summary>
                    <pre class="mt-1 whitespace-pre-wrap break-words font-sans">{{ $run->notes }}</pre>
                </details>
            @endif
        </div>
    @empty
        <div class="ui-surface p-5"><x-pulse.empty-state icon="play-circle" title="No test runs recorded" /></div>
    @endforelse
    <div>{{ $runs->links() }}</div>
</div>
