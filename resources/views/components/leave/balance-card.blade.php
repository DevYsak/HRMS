{{--
    One leave balance (or leave kind) as a card: name, what it is, the figure that
    matters most, then the same row of numbers every card shares — so Earned
    Leave, Combined Leave, Comp Off, Special and Unpaid read the same way.

    figures: [['label' => 'Used', 'value' => '2', 'key' => 'used', 'tone' => 'rose'|'emerald'|null], …]
--}}
@props([
    'title',
    'subtitle' => null,
    'tone' => 'zinc',
    'headline' => null,
    'headlineLabel' => 'Remaining',
    'headlineNegative' => false,
    'figures' => [],
    'progress' => null,
    'progressLabel' => null,
])

@php
    $accent = [
        'emerald' => 'bg-emerald-500', 'sky' => 'bg-sky-500', 'violet' => 'bg-violet-500',
        'amber' => 'bg-amber-500', 'rose' => 'bg-rose-500', 'zinc' => 'bg-zinc-400',
    ][$tone] ?? 'bg-zinc-400';
    $toneText = fn (?string $t) => [
        'emerald' => 'text-emerald-700 dark:text-emerald-400', 'rose' => 'text-rose-600 dark:text-rose-400',
        'amber' => 'text-amber-700 dark:text-amber-300', 'sky' => 'text-sky-700 dark:text-sky-300',
    ][$t ?? ''] ?? 'text-zinc-900 dark:text-white';
    $pct = $progress === null ? null : max(0, min(100, (int) round($progress * 100)));
@endphp

<div {{ $attributes->merge(['class' => 'ui-surface relative flex flex-col overflow-hidden p-4']) }}>
    <span class="absolute inset-y-0 left-0 w-1 {{ $accent }}" aria-hidden="true"></span>

    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</p>
            @if($subtitle)<p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</p>@endif
        </div>
        @isset($actions)
            <div class="flex flex-wrap gap-1">{{ $actions }}</div>
        @endisset
    </div>

    @if($headline !== null)
        <div class="mt-3 flex items-baseline gap-2">
            <span @class(['text-3xl font-bold tabular-nums', 'text-rose-600 dark:text-rose-400' => $headlineNegative, 'text-zinc-900 dark:text-white' => ! $headlineNegative]) data-card-headline>{{ $headline }}</span>
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $headlineLabel }}</span>
        </div>
    @endif

    @if($pct !== null)
        <div class="mt-2" data-card-progress>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                <div class="h-full rounded-full {{ $accent }}" style="width: {{ $pct }}%"></div>
            </div>
            @if($progressLabel)<p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $progressLabel }}</p>@endif
        </div>
    @endif

    @if($figures !== [])
        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 {{ [2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3'][count($figures)] ?? 'sm:grid-cols-4' }}" data-card-figures>
            @foreach($figures as $f)
                <div @if(! empty($f['key'])) data-figure="{{ $f['key'] }}" @endif>
                    <dt class="text-[11px] text-zinc-500 dark:text-zinc-400">{{ $f['label'] }}</dt>
                    <dd class="text-sm font-semibold tabular-nums {{ $toneText($f['tone'] ?? null) }}">{{ $f['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if(trim((string) $slot) !== '')
        <div class="mt-3 flex-1 space-y-2 border-t border-zinc-100 pt-3 text-xs text-zinc-600 dark:border-white/5 dark:text-zinc-300">{{ $slot }}</div>
    @endif
</div>
