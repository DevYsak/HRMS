@props(['profile', 'nextHoliday' => null])

{{-- EmployeeWelcomeCard — identity and context on the left; the `aside` slot
     (today's status) sits on the right on desktop and below on mobile. --}}
<section {{ $attributes->class('relative overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-[0_1px_2px_0_rgb(16_24_40/0.04)] dark:border-white/[0.06] dark:bg-ink-900') }}>
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-orange-50/90 to-transparent dark:from-orange-500/[0.07]"></div>

    <div class="relative grid gap-5 p-5 md:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,23rem)] lg:items-center lg:gap-8">
        <div class="flex min-w-0 items-start gap-4">
            @if($profile['photo_url'])
                <img src="{{ $profile['photo_url'] }}" alt="{{ $profile['name'] }}"
                     class="size-14 shrink-0 rounded-xl object-cover ring-1 ring-zinc-200 md:size-16 dark:ring-white/10">
            @else
                <div aria-hidden="true"
                     class="flex size-14 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-lg font-semibold text-orange-700 ring-1 ring-orange-200/70 md:size-16 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-500/20">
                    {{ $profile['initials'] }}
                </div>
            @endif

            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ now()->format('l, j F Y') }}</p>
                <h1 class="mt-0.5 text-xl font-semibold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">
                    {{ $profile['greeting'] }}, {{ $profile['first_name'] }}
                </h1>

                @if($profile['designation'] || $profile['department'])
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $profile['designation'] ?? 'Employee' }}@if($profile['department'])<span class="text-zinc-400"> · </span>{{ $profile['department'] }}@endif
                    </p>
                @endif
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $profile['email'] }}</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if($profile['shift_label'])
                        <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-zinc-200 bg-white/80 px-2.5 py-1 text-xs text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
                            <flux:icon.clock class="size-3.5 shrink-0 text-zinc-400" />
                            <span class="truncate">{{ $profile['shift_label'] }}</span>
                        </span>
                    @endif
                    @if($profile['work_mode'])
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white/80 px-2.5 py-1 text-xs text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
                            <flux:icon.building-office-2 class="size-3.5 shrink-0 text-zinc-400" />
                            {{ $profile['work_mode'] }}
                        </span>
                    @endif
                    @if($nextHoliday)
                        <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-zinc-200 bg-white/80 px-2.5 py-1 text-xs text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
                            <flux:icon.sun class="size-3.5 shrink-0 text-zinc-400" />
                            <span class="truncate">Next holiday: {{ $nextHoliday->name }} · {{ \Illuminate\Support\Carbon::parse($nextHoliday->date)->format('j M') }}</span>
                        </span>
                    @endif
                </div>

                @if($profile['message'])
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $profile['message'] }}</p>
                @endif
            </div>
        </div>

        @isset($aside)
            {{ $aside }}
        @endisset
    </div>
</section>
