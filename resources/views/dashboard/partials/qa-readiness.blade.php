{{-- QA readiness at a glance (holders of view_qa_audit — Super Admin by default). Figures from App\Services\Qa\QaReadiness. --}}
@php
    $qa = app(\App\Services\Qa\QaReadiness::class)->summary();
    $levelTone = match ($qa['level']) {
        'production' => 'emerald', 'staging', 'uat' => 'sky', 'internal_qa' => 'amber', default => 'rose',
    };
    $toneClasses = [
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
    ];
    // The last full-suite run when there is one (else the latest run): passed · new regressions · known baseline.
    $run = $qa['verified']['full'] ?? $qa['latest_run'];
    $stats = [
        ['Passed', $run ? $run->passed : $qa['counts']['passed']],
        ['New regressions', $run ? $run->new_regressions : '—'],
        ['Known baseline', $run ? $run->known_baseline : $qa['counts']['known_baseline']],
        ['Findings open', $qa['counts']['open_findings']],
        ['Critical open', $qa['counts']['open_critical']],
        ['QA score', $qa['scores']['overall'].'%'],
    ];
@endphp

<x-pulse.card title="QA readiness" subtitle="System → QA & Audit" icon="clipboard-document-check" flush data-qa-widget>
    <x-slot:actions>
        <flux:button size="sm" variant="primary" icon-trailing="arrow-right" :href="route('qa-audit.index')" wire:navigate>Open QA &amp; Audit</flux:button>
    </x-slot:actions>

    <div class="flex flex-col gap-5 px-[var(--ui-card-padding,1.25rem)] pb-[var(--ui-card-padding,1.25rem)] sm:flex-row sm:items-center">
        <div class="flex items-center gap-4">
            <x-pulse.radial :value="$qa['scores']['production']" caption="readiness" :accent="$levelTone === 'sky' ? 'blue' : $levelTone" size="size-24" />
            <div class="min-w-0">
                <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide ring-1 {{ $toneClasses[$levelTone] }}">{{ $qa['level_label'] }}</span>
                @if($run)
                    <p class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $run->name }} · {{ $run->run_at->format('d M Y') }}</p>
                @endif
                @if($qa['blockers'] !== [])
                    <p class="mt-2 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $qa['blockers'][0] }}</p>
                @elseif($qa['next_steps'] !== [])
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Next: {{ $qa['next_steps'][0] }}</p>
                @endif
            </div>
        </div>
        <dl class="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach($stats as [$label, $value])
                <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5">
                    <dt class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="text-base font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</x-pulse.card>
