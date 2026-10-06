@php
    use App\Services\Profile\ProfileFieldRegistry as Registry;

    $tierLabels = [
        Registry::TIER_EDITABLE => ['You can edit directly', 'bg-emerald-500'],
        Registry::TIER_APPROVAL => ['Request a change (HR approves)', 'bg-amber-500'],
        Registry::TIER_LOCKED => ['Managed by HR', 'bg-zinc-400'],
    ];
    $percent = $trackedCount > 0 ? (int) round($doneCount / $trackedCount * 100) : 100;
@endphp

<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <div class="mx-auto max-w-4xl space-y-6 p-4 md:p-6">

        @if(session('status'))
            <flux:callout icon="check-circle" color="green">
                <flux:callout.heading>{{ session('status') }}</flux:callout.heading>
                <flux:callout.text>Welcome aboard! This short tutorial shows you how to get set up.</flux:callout.text>
            </flux:callout>
        @endif

        {{-- Header --}}
        <div class="overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-orange-400 p-6 text-white shadow-lg shadow-orange-500/20">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-xl">
                    <p class="text-xs font-bold uppercase tracking-widest text-white/80">New employee tutorial</p>
                    <h1 class="mt-1 text-2xl font-black">Getting started with {{ config('app.name') }}</h1>
                    <p class="mt-2 text-sm text-white/90">How to sign in for the first time, keep your profile up to date and apply for leave — step by step.</p>
                </div>
                <div class="rounded-xl bg-white/15 px-4 py-3 text-center">
                    <div class="text-3xl font-black tabular-nums">{{ $doneCount }}/{{ $trackedCount }}</div>
                    <div class="text-[11px] font-bold uppercase tracking-wider text-white/80">steps done</div>
                </div>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-white/25" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-white" style="width: {{ $percent }}%"></div>
            </div>
        </div>

        {{-- Contents --}}
        <nav aria-label="Tutorial steps" class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <p class="mb-2 text-[11px] font-bold uppercase tracking-widest text-zinc-500">In this tutorial</p>
            <ol class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                @foreach($steps as $i => $step)
                    <li>
                        <a href="#{{ $step['id'] }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-zinc-700 hover:bg-orange-50 hover:text-orange-700 dark:text-zinc-300 dark:hover:bg-orange-500/10">
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold {{ $step['done'] ? 'bg-emerald-500 text-white' : 'bg-zinc-100 text-zinc-500 dark:bg-white/10' }}">
                                @if($step['done']) <flux:icon.check class="size-3" /> @else {{ $i + 1 }} @endif
                            </span>
                            {{ $step['title'] }}
                        </a>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- Steps --}}
        <ol class="space-y-4">
            @foreach($steps as $i => $step)
                <li id="{{ $step['id'] }}" class="scroll-mt-20 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="flex items-start gap-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $step['done'] ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10' : 'bg-orange-50 text-orange-500 dark:bg-orange-500/10' }}">
                            <flux:icon :name="$step['done'] ? 'check-circle' : $step['icon']" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1 space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-bold text-zinc-900 dark:text-white">
                                    <span class="text-zinc-400">Step {{ $i + 1 }} ·</span> {{ $step['title'] }}
                                </h2>
                                @if($step['status'])
                                    <span class="rounded-full px-2.5 py-0.5 text-[11px] font-bold {{ $step['done'] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' }}">
                                        {{ $step['status'] }}
                                    </span>
                                @endif
                            </div>

                            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $step['intro'] }}</p>

                            <ul class="space-y-1.5 text-sm text-zinc-700 dark:text-zinc-300">
                                @foreach($step['points'] as $point)
                                    <li class="flex gap-2">
                                        <flux:icon.chevron-right class="mt-0.5 size-4 shrink-0 text-orange-400" />
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            @if(! empty($step['tiers']))
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                    @foreach($tierLabels as $tier => [$heading, $dot])
                                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                                            <p class="flex items-center gap-2 text-xs font-bold text-zinc-900 dark:text-white"><span class="size-2 rounded-full {{ $dot }}"></span>{{ $heading }}</p>
                                            <ul class="mt-2 flex flex-wrap gap-1">
                                                @foreach($step['tiers'][$tier] ?? [] as $label)
                                                    <li class="rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-700 dark:bg-white/5 dark:text-zinc-300">{{ $label }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($step['tip'])
                                <p class="flex gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">
                                    <flux:icon.light-bulb class="size-4 shrink-0" /> {{ $step['tip'] }}
                                </p>
                            @endif

                            @if($step['links'])
                                <div class="flex flex-wrap gap-2">
                                    @foreach($step['links'] as $link)
                                        <flux:button :href="$link['url']" wire:navigate size="sm" icon:trailing="arrow-right">{{ $link['label'] }}</flux:button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</flux:main>
