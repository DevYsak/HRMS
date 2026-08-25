@props([
    'employee' => null,
    'attendance' => null,
    'shiftName' => null,
    'shift' => null,
    'workedMinutes' => 0,
    'quote' => null,
    'designation' => null,
    'department' => null,
])

@php
    $user = auth()->user();
    $hour = now()->hour;
    $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
    $firstName = \Illuminate\Support\Str::of($user->name ?? 'there')->explode(' ')->first();
    $initials = \Illuminate\Support\Str::of($user->name ?? 'U')->explode(' ')->map(fn ($n) => mb_substr($n, 0, 1))->take(2)->implode('');

    $isIn = $attendance && $attendance->check_in;
    $isOut = $attendance && $attendance->check_out;
    $working = $isIn && ! $isOut;

    $liveStart = $working ? $attendance->check_in->timestamp : null;
    $staticDur = intdiv($workedMinutes, 60).'h '.str_pad((string) ($workedMinutes % 60), 2, '0', STR_PAD_LEFT).'m';

    $shiftTime = $shift ? \Illuminate\Support\Carbon::parse($shift->start_time)->format('g:i A').' – '.\Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A') : null;
@endphp

<div class="relative overflow-hidden rounded-3xl border border-orange-100/70 bg-gradient-to-br from-[#FFF3E9] via-white to-[#FFF8F1] p-5 shadow-sm dark:border-white/5 dark:from-zinc-900 dark:via-zinc-900 dark:to-zinc-950 md:p-7">
    {{-- decorative blobs --}}
    <div class="pointer-events-none absolute -right-10 -top-16 size-56 rounded-full bg-orange-300/20 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-20 right-1/3 size-56 rounded-full bg-amber-200/20 blur-3xl"></div>

    <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">

        {{-- LEFT: identity + greeting --}}
        <div class="flex items-start gap-4">
            <div class="relative shrink-0">
                @if($employee?->photo)
                    <img src="{{ \Illuminate\Support\Facades\Storage::url($employee->photo) }}" alt="{{ $user->name }}"
                         class="size-16 rounded-2xl object-cover shadow-md ring-2 ring-white dark:ring-zinc-800 md:size-[72px]">
                @else
                    <div class="flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-xl font-black text-white shadow-md ring-2 ring-white dark:ring-zinc-800 md:size-[72px]">
                        {{ $initials }}
                    </div>
                @endif
                <span class="absolute -bottom-1 -right-1 size-4 rounded-full border-2 border-white bg-emerald-500 dark:border-zinc-900"></span>
            </div>

            <div class="min-w-0">
                <h1 class="flex items-center gap-2 text-2xl font-black tracking-tight text-zinc-900 dark:text-white md:text-[28px]">
                    {{ $greeting }}, {{ $firstName }}! <span class="animate-bounce">👋</span>
                </h1>
                <p class="mt-0.5 text-sm font-semibold text-zinc-600 dark:text-zinc-300">
                    {{ $designation ?? 'Employee' }}@if($department) <span class="text-zinc-400">• {{ $department }}</span>@endif
                </p>
                <p class="text-xs text-zinc-400">{{ $user->email }}</p>

                @if($quote)
                    <div class="mt-3 inline-flex max-w-md items-start gap-2 rounded-xl border border-orange-100 bg-white/70 px-3 py-1.5 text-xs italic text-zinc-600 backdrop-blur dark:border-white/5 dark:bg-white/5 dark:text-zinc-300">
                        <flux:icon.sparkles class="mt-0.5 size-3.5 shrink-0 text-orange-400" /> {{ $quote }}
                    </div>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] font-medium text-zinc-500 dark:text-zinc-400">
                    <span class="inline-flex items-center gap-1"><flux:icon.calendar class="size-3.5" /> {{ now()->format('l, d M Y') }}</span>
                    @if($shiftName)<span class="inline-flex items-center gap-1"><flux:icon.clock class="size-3.5" /> {{ $shiftName }}@if($shiftTime) · {{ $shiftTime }}@endif</span>@endif
                    <span class="inline-flex items-center gap-1"><flux:icon.sun class="size-3.5 text-amber-500" /> Have a productive day</span>
                </div>
            </div>
        </div>

        {{-- RIGHT: status + clock card --}}
        <div class="w-full shrink-0 rounded-2xl border border-zinc-100 bg-white/80 p-4 shadow-sm backdrop-blur dark:border-white/5 dark:bg-white/5 lg:w-[300px]">
            <div class="flex items-center justify-between">
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Today's Status</span>
                <span @class([
                    'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold',
                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $isIn,
                    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => ! $isIn,
                ])>
                    <span class="size-1.5 rounded-full {{ $isIn ? 'bg-emerald-500' : 'bg-zinc-400' }}"></span>
                    {{ $isOut ? 'Checked Out' : ($isIn ? 'Present' : 'Not Clocked In') }}
                </span>
            </div>

            <div class="mt-2"
                 x-data="{ start: {{ $liveStart ?? 'null' }}, text: '{{ $staticDur }}',
                          tick() { if (this.start === null) return; const s = Math.floor(Date.now()/1000) - this.start; const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60; this.text = h + 'h ' + String(m).padStart(2,'0') + 'm ' + String(sec).padStart(2,'0') + 's'; } }"
                 x-init="tick(); if (start !== null) setInterval(() => tick(), 1000)">
                <div class="text-[10px] font-semibold uppercase tracking-wide text-zinc-400">{{ $working ? 'Working since '.$attendance->check_in->format('h:i A') : 'Working Duration' }}</div>
                <div class="text-2xl font-black tabular-nums text-zinc-900 dark:text-white" x-text="text"></div>
            </div>

            {{-- Punches through the dashboard's clockIn()/clockOut(), which delegate to
                 AttendanceService — the same call the attendance page makes. The browser
                 location is offered here because it is cheap to ask for; when HR requires
                 a selfie, or the employee declines location, the component hands off to
                 the attendance page, which has the full capture UI. --}}
            <div class="mt-3 flex gap-2"
                 x-data="{
                    punching: false,
                    punch(action) {
                        if (this.punching) return;
                        this.punching = true;
                        const send = (lat, lng) => $wire.call(action, lat, lng).finally(() => this.punching = false);
                        if (! ('geolocation' in navigator)) { send(null, null); return; }
                        navigator.geolocation.getCurrentPosition(
                            p => send(+p.coords.latitude.toFixed(6), +p.coords.longitude.toFixed(6)),
                            () => send(null, null),
                            { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
                        );
                    }
                 }">
                @if(! $isIn)
                    <button type="button" x-on:click="punch('clockIn')" x-bind:disabled="punching"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-orange-500 px-3 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-orange-600 disabled:opacity-60">
                        <flux:icon.arrow-right-end-on-rectangle class="size-4" /> Clock In
                    </button>
                @elseif(! $isOut)
                    <button type="button" x-on:click="punch('clockOut')" x-bind:disabled="punching"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-orange-500 px-3 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-orange-600 disabled:opacity-60">
                        <flux:icon.arrow-left-start-on-rectangle class="size-4" /> Clock Out
                    </button>
                    <a href="{{ route('attendance.my') }}" wire:navigate class="flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-xs font-bold text-zinc-700 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200">
                        <flux:icon.pause-circle class="size-4" /> Break
                    </a>
                @else
                    <a href="{{ route('attendance.my') }}" wire:navigate class="flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-xs font-bold text-zinc-700 transition hover:bg-zinc-50 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200">
                        <flux:icon.clock class="size-4" /> View Timeline
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>
