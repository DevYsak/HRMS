{{--
    Attendance hero — greeting, today's date, the one month selector the whole
    page follows, and Clock In when there is no attendance yet today.
--}}
@php
    $hour = (int) now()->format('G');
    [$greeting, $greetIcon, $greetTone] = match (true) {
        $hour < 12 => ['Good Morning', 'sun', 'bg-amber-100 text-amber-500 dark:bg-amber-400/15 dark:text-amber-300'],
        $hour < 17 => ['Good Afternoon', 'sun', 'bg-orange-100 text-orange-500 dark:bg-orange-400/15 dark:text-orange-300'],
        default => ['Good Evening', 'moon', 'bg-indigo-100 text-indigo-500 dark:bg-indigo-400/15 dark:text-indigo-300'],
    };
    $firstName = \Illuminate\Support\Str::of(auth()->user()->name ?? '')->trim()->before(' ')->value();
@endphp

<section class="relative isolate overflow-hidden rounded-3xl border border-orange-100/80 bg-gradient-to-br from-[#FFF4EA] via-white to-[#EEF5FF] px-5 py-5 shadow-[0_12px_32px_-20px_rgba(249,115,22,0.35)] sm:px-7 sm:py-6 dark:border-white/[0.07] dark:from-[#1E1631] dark:via-[#121A30] dark:to-[#0C2036] dark:shadow-none" data-attendance-hero>
    {{-- Soft light blobs --}}
    <div class="pointer-events-none absolute -left-16 -top-24 -z-10 size-64 rounded-full bg-orange-200/40 blur-3xl dark:bg-orange-500/10" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-24 right-24 -z-10 size-72 rounded-full bg-sky-200/40 blur-3xl dark:bg-sky-500/10" aria-hidden="true"></div>

    <div class="flex flex-wrap items-center justify-between gap-5">
        <div class="flex min-w-0 items-center gap-4">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl shadow-sm {{ $greetTone }}" aria-hidden="true">
                <flux:icon :name="$greetIcon" variant="solid" class="size-7" />
            </span>
            <div class="min-w-0">
                <h1 class="text-[22px] font-bold leading-tight tracking-tight text-zinc-900 sm:text-[26px] dark:text-white">
                    {{ $greeting }}{{ $firstName !== '' ? ', '.$firstName : '' }}!
                </h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-slate-300">Here’s your attendance overview for today, {{ now()->format('D, d M Y') }}.</p>
            </div>
        </div>

        {{-- Illustration: calendar card + clock (decorative) --}}
        <svg class="pointer-events-none hidden h-[92px] w-[210px] shrink-0 xl:block" viewBox="0 0 210 92" fill="none" aria-hidden="true">
            <defs>
                <linearGradient id="hero-ring" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FB923C"/><stop offset="1" stop-color="#F59E0B"/></linearGradient>
                <linearGradient id="hero-card" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FFFFFF"/><stop offset="1" stop-color="#F4F6FB"/></linearGradient>
            </defs>
            <rect x="18" y="14" width="104" height="70" rx="12" fill="url(#hero-card)" stroke="#E7E9F1" class="dark:opacity-20"/>
            <rect x="18" y="14" width="104" height="18" rx="12" fill="#FDBA74" opacity=".55"/>
            @foreach([0, 1, 2, 3, 4] as $c)
                @foreach([0, 1, 2] as $r)
                    <rect x="{{ 28 + $c * 18 }}" y="{{ 40 + $r * 13 }}" width="10" height="7" rx="2" fill="{{ $c === 2 && $r === 1 ? '#F97316' : '#E5E7EB' }}" @if(! ($c === 2 && $r === 1)) class="dark:opacity-30" @endif/>
                @endforeach
            @endforeach
            <circle cx="150" cy="50" r="31" fill="#fff" class="dark:opacity-10"/>
            <circle cx="150" cy="50" r="27" stroke="#FED7AA" stroke-width="6" class="dark:opacity-30"/>
            <path d="M150 23a27 27 0 0 1 25.7 35.3" stroke="url(#hero-ring)" stroke-width="6" stroke-linecap="round"/>
            <path d="M150 36v14l9 6" stroke="#1F2937" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="dark:stroke-slate-200"/>
            <circle cx="186" cy="22" r="10" fill="#10B981"/>
            <path d="m181.5 22 3 3 6-6" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="8" cy="30" r="3" fill="#FDBA74"/><circle cx="200" cy="80" r="2.5" fill="#93C5FD"/><circle cx="118" cy="6" r="2" fill="#C4B5FD"/>
        </svg>

        <div class="flex flex-wrap items-center gap-2.5">
            @if($emp && ! $todayAttendance)
                <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'in' })" class="{{ $ui['btnPrimary'] }}" data-clock-in>
                    <flux:icon.arrow-right-end-on-rectangle class="size-4" /> Clock In
                </button>
            @endif
            <div class="flex items-center gap-1 rounded-xl border border-zinc-200/80 bg-white/90 p-1 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/[0.05]" data-month-selector>
                <button type="button" wire:click="historyPreviousMonth" class="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Previous month"><flux:icon.chevron-left class="size-4" /></button>
                <span class="inline-flex min-w-[9.5rem] items-center justify-center gap-2 text-sm font-semibold text-zinc-800 dark:text-slate-100" wire:loading.class="opacity-50" wire:target="historyPreviousMonth,historyNextMonth" aria-live="polite">
                    <flux:icon.calendar class="size-4 text-orange-500" /> {{ $mh['label'] ?? now()->format('F Y') }}
                </span>
                <button type="button" wire:click="historyNextMonth" @disabled($isCurrentMonth) class="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 disabled:opacity-30 disabled:hover:bg-transparent dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white" aria-label="Next month"><flux:icon.chevron-right class="size-4" /></button>
            </div>
        </div>
    </div>
</section>
