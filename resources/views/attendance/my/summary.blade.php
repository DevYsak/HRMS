{{--
    Today's Summary — worked, break, shift, late and approved OT for today,
    straight from todayCalc (AttendanceCalculator). Worked = last OUT − first
    IN; breaks are informational and never deducted.
--}}
@php
    $otMinutes = (int) ($todayCalc['approved_ot_minutes'] ?? 0);
    $breakOver = (bool) ($todayCalc['excess_break'] ?? false) || ($breakAllowance > 0 && $breakMin > $breakAllowance);
    $hasPunchDrawer = ($pj['nodes'] ?? []) !== [] || (int) ($pj['raw_count'] ?? 0) > 0 || $activity['events'] !== [] || $biometricDevice
        || $todaySummary?->device_serial || collect($syncHistory)->pluck('serial')->filter()->isNotEmpty();
    $line = 'flex items-center gap-3 py-2.5 text-sm';
    $icon = 'flex size-7 shrink-0 items-center justify-center rounded-lg';
@endphp

<section class="{{ $ui['card'] }} {{ $ui['lift'] }} flex flex-col p-5" aria-labelledby="today-summary-title" data-today-summary>
    <div class="flex items-start gap-3">
        <span class="{{ $ui['tile'] }} bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.clipboard-document-list class="size-5" /></span>
        <div class="min-w-0">
            <h2 id="today-summary-title" class="{{ $ui['title'] }}">Today's Summary</h2>
            <p class="{{ $ui['sub'] }} mt-0.5">{{ now()->format('l, d M') }}</p>
        </div>
    </div>

    <dl class="mt-3 divide-y divide-zinc-100 dark:divide-white/[0.06]">
        <div class="{{ $line }}"><span class="{{ $icon }} bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-300"><flux:icon.clock variant="mini" class="size-4" /></span><dt class="flex-1 text-zinc-600 dark:text-slate-400">Worked</dt><dd class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $hm($workedMin) }}</dd></div>
        <div class="{{ $line }}"><span class="{{ $icon }} bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300"><flux:icon.pause variant="mini" class="size-4" /></span><dt class="flex-1 text-zinc-600 dark:text-slate-400">Break</dt><dd @class(['font-bold tabular-nums', 'text-orange-600 dark:text-orange-400' => $breakOver, 'text-zinc-900 dark:text-white' => ! $breakOver])>{{ $hm($breakMin) }}</dd></div>
        <div class="{{ $line }}"><span class="{{ $icon }} bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-300"><flux:icon.calendar variant="mini" class="size-4" /></span><dt class="flex-1 text-zinc-600 dark:text-slate-400">Shift</dt><dd class="text-right font-semibold text-zinc-900 dark:text-white">{{ $shiftWindow ?? 'Shift not assigned' }}</dd></div>
        <div class="{{ $line }}"><span class="{{ $icon }} bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.exclamation-circle variant="mini" class="size-4" /></span><dt class="flex-1 text-zinc-600 dark:text-slate-400">Late</dt><dd @class(['font-bold', 'text-orange-600 dark:text-orange-400' => $isLate, 'text-emerald-600 dark:text-emerald-400' => $firstIn && ! $isLate, 'text-zinc-400' => ! $firstIn])>{{ $firstIn ? ($isLate ? 'Yes'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'No') : '—' }}</dd></div>
        <div class="{{ $line }}"><span class="{{ $icon }} bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><flux:icon.bolt variant="mini" class="size-4" /></span><dt class="flex-1 text-zinc-600 dark:text-slate-400">OT</dt><dd class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $otMinutes > 0 ? $hm($otMinutes).' approved' : '0h' }}</dd></div>
    </dl>

    <p class="mt-3 flex gap-2 rounded-xl bg-zinc-50 p-2.5 text-[11px] leading-snug text-zinc-500 dark:bg-white/[0.03] dark:text-slate-400">
        <flux:icon.information-circle class="size-4 shrink-0" />
        <span>Worked = last OUT − first IN. Breaks are shown for information and never deducted.</span>
    </p>

    <div class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 text-sm dark:border-white/[0.06]">
        @if($issueCount === 0)
            <span class="inline-flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400"><flux:icon.check-circle class="size-4" /> No attendance issues today</span>
        @else
            <a href="#attention" class="inline-flex items-center gap-1.5 font-semibold text-orange-600 dark:text-orange-400"><flux:icon.exclamation-triangle class="size-4" /> {{ $issueCount }} {{ \Illuminate\Support\Str::plural('item', $issueCount) }} {{ $issueCount === 1 ? 'needs' : 'need' }} attention</a>
        @endif
        @if($hasPunchDrawer)
            <button type="button" @click="$flux.modal('today-punches').show()" class="inline-flex items-center gap-0.5 font-semibold text-zinc-700 hover:text-orange-600 dark:text-slate-300 dark:hover:text-orange-300">View raw punches <flux:icon.chevron-right class="size-4" /></button>
        @endif
    </div>
</section>
