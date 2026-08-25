@props(['steps' => []])

{{--
    Generic vertical dot-and-connector timeline. Visually modeled on
    x-employee.timeline (attendance's fixed 4-step Clock In/Break/Clock Out
    timeline) but data-driven for an arbitrary sequence of events instead of a
    hardcoded 4 steps — e.g. a payslip's Created → Approved → Downloaded →
    Emailed history.

    Each item in $steps: {
        label: string,              // e.g. "Approved"
        user: ?string,               // who did it, null if it hasn't happened
        timestamp: ?string,          // pre-formatted display string, null if pending
        icon: string,                 // flux icon name
        tone: 'orange'|'emerald'|'amber'|'rose'|'zinc' (default 'orange'),
        note: ?string,                // small secondary line (e.g. a reason)
    }
--}}

@php
    $toneClasses = [
        'orange' => 'bg-orange-500 text-white ring-orange-100 dark:ring-orange-900/30',
        'emerald' => 'bg-emerald-500 text-white ring-emerald-100 dark:ring-emerald-900/30',
        'amber' => 'bg-amber-500 text-white ring-amber-100 dark:ring-amber-900/30',
        'rose' => 'bg-rose-500 text-white ring-rose-100 dark:ring-rose-900/30',
        'zinc' => 'bg-zinc-500 text-white ring-zinc-100 dark:ring-zinc-800',
    ];
    $connectorClasses = [
        'orange' => 'bg-orange-200 dark:bg-orange-900/40',
        'emerald' => 'bg-emerald-200 dark:bg-emerald-900/40',
        'amber' => 'bg-amber-200 dark:bg-amber-900/40',
        'rose' => 'bg-rose-200 dark:bg-rose-900/40',
        'zinc' => 'bg-zinc-200 dark:bg-zinc-800',
    ];
@endphp

<div {{ $attributes->class(['space-y-1']) }}>
    @forelse($steps as $step)
        @php
            $happened = ! empty($step['timestamp']);
            $tone = $step['tone'] ?? 'orange';
        @endphp
        <div class="flex items-center gap-3">
            <div class="flex flex-col items-center">
                <span @class([
                    'flex size-8 items-center justify-center rounded-full ring-4 transition',
                    ($toneClasses[$tone] ?? $toneClasses['orange']) => $happened,
                    'bg-zinc-100 text-zinc-400 ring-transparent dark:bg-zinc-800' => ! $happened,
                ])>
                    <flux:icon :name="$step['icon']" class="size-4" />
                </span>
                @if(! $loop->last)
                    <span @class(['my-0.5 h-6 w-0.5 rounded', ($connectorClasses[$tone] ?? $connectorClasses['orange']) => $happened, 'bg-zinc-100 dark:bg-zinc-800' => ! $happened])></span>
                @endif
            </div>
            <div class="flex flex-1 items-center justify-between pb-2">
                <div>
                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $step['label'] }}</div>
                    <div class="text-[11px] text-zinc-400">
                        {{ $step['user'] ?? ($step['note'] ?? ($happened ? '' : 'Not yet')) }}
                    </div>
                </div>
                <div class="text-sm font-semibold tabular-nums {{ $happened ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-300 dark:text-zinc-600' }}">
                    {{ $step['timestamp'] ?? '—' }}
                </div>
            </div>
        </div>
    @empty
        <p class="text-xs text-zinc-400">No activity recorded yet.</p>
    @endforelse
</div>
