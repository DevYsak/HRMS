{{--
    Attendance Needs Attention — rendered (by overview) only when something
    needs action: alerts (missing check-in/out, late arrival, early exit, long
    break, device sync), the late-mark warning, pending regularisations, punch
    conflicts, or an unassigned shift.
--}}
@php
    $item = 'flex flex-col gap-2 py-3 first:pt-0 last:pb-0';
    $cta = 'inline-flex w-fit items-center gap-1.5 rounded-xl bg-gradient-to-b from-orange-500 to-orange-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-[0_6px_14px_-6px_rgba(249,115,22,0.7)] transition hover:from-orange-500 hover:to-orange-500';
    // At most five items show; the rest open with "Show more" (and fold again).
    $attentionLimit = 5;
    $attentionTotal = (int) $todayMissing + (int) $this->shiftUnassigned + $issues->count() + (int) ($conflicts > 0) + (int) $lateWarning + $pendingRegs->count();
    $attentionIndex = 0;
    $overflowAttr = fn (int $i): string => $i > $attentionLimit ? 'x-show="more" x-cloak' : '';
@endphp

<section id="attention" class="relative overflow-hidden rounded-2xl border border-rose-200/80 bg-gradient-to-br from-rose-50 via-white to-orange-50/40 p-5 shadow-[0_12px_32px_-20px_rgba(225,29,72,0.35)] dark:border-rose-500/25 dark:from-rose-500/[0.10] dark:via-[#111A2E] dark:to-[#111A2E] dark:shadow-none" aria-labelledby="attention-title" data-attention x-data="{ more: false }">
    <div class="flex items-start gap-3">
        <span class="{{ $ui['tile'] }} bg-rose-100 text-rose-600 dark:bg-rose-500/15 dark:text-rose-300"><flux:icon.exclamation-triangle class="size-5" /></span>
        <div class="min-w-0">
            <h2 id="attention-title" class="{{ $ui['title'] }} !text-rose-900 dark:!text-rose-100">Attendance Needs Attention</h2>
            <p class="{{ $ui['sub'] }} mt-0.5">{{ $attentionTotal }} {{ \Illuminate\Support\Str::plural('item', $attentionTotal) }} {{ $attentionTotal === 1 ? 'needs' : 'need' }} attention</p>
        </div>
    </div>

    <div class="mt-4 divide-y divide-rose-100 dark:divide-rose-500/15">
        @if($todayMissing)
            <div class="{{ $item }}" data-alert="missing_today" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="border-l-2 border-rose-400 pl-3 text-sm"><div class="font-semibold text-zinc-900 dark:text-white">Missing {{ $todayMissingDir === 'IN' ? 'Check-In' : 'Check-Out' }}</div>
                    <div class="text-xs text-zinc-600 dark:text-slate-400">Today, {{ now()->format('D, d M') }} · a {{ $todayMissingDir ?? 'punch' }} is missing from your timeline</div></div>
                <button type="button" wire:click="openRegularisation('{{ $todayKeyAtt }}')" class="{{ $cta }} ml-3.5">Request Regularisation</button>
            </div>
        @endif

        @if($this->shiftUnassigned)
            <div class="{{ $item }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="border-l-2 border-amber-400 pl-3 text-sm"><div class="font-semibold text-zinc-900 dark:text-white">Shift not assigned</div>
                    <div class="text-xs text-zinc-600 dark:text-slate-400">Arrivals and hours can't be judged against a shift. Ask HR to assign one.</div></div>
            </div>
        @endif

        @foreach($issues as $alert)
            <div class="{{ $item }}" data-alert="{{ $alert['type'] ?? '' }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="border-l-2 border-rose-400 pl-3 text-sm"><div class="font-semibold text-zinc-900 dark:text-white">{{ $alert['label'] }}</div>
                    <div class="text-xs text-zinc-600 dark:text-slate-400">{{ $alert['detail'] }}</div></div>
                @if($alert['action'] ?? true)
                    <button type="button" wire:click="openRegularisation('{{ $alert['date'] }}')" class="{{ $cta }} ml-3.5">Request Regularisation</button>
                @endif
            </div>
        @endforeach

        @if($conflicts > 0)
            <div class="{{ $item }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="border-l-2 border-amber-400 pl-3 text-sm"><div class="font-semibold text-zinc-900 dark:text-white">Punch conflict</div>
                    <div class="text-xs text-zinc-600 dark:text-slate-400">{{ $conflicts }} conflicting {{ \Illuminate\Support\Str::plural('punch', $conflicts) }} today. Open “View raw punches” to see which were used.</div></div>
            </div>
        @endif

        @if($lateWarning)
            <div class="{{ $item }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="border-l-2 border-rose-500 pl-3 text-sm"><div class="font-semibold text-rose-700 dark:text-rose-300">Late-mark warning — {{ $analytics['late_month_count'] }} late arrivals this month</div>
                    <div class="text-xs text-zinc-600 dark:text-slate-400">{{ $analytics['late_threshold'] ?? 3 }}+ late marks lead to a formal warning letter. Arrive before your shift's grace cutoff.</div></div>
            </div>
        @endif

        @foreach($pendingRegs as $myReg)
            <div class="{{ $item }}" data-attention-reg {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="border-l-2 border-violet-400 pl-3 text-sm"><div class="font-semibold text-zinc-900 dark:text-white">Regularisation pending</div>
                    <div class="text-xs text-zinc-600 dark:text-slate-400">@userShortDate(\Illuminate\Support\Carbon::parse($myReg->work_date)),
                        @if($myReg->isLeave()) leave
                        @elseif($myReg->regularisation_type === 'half_day') half day ({{ $myReg->half_day_period }})
                        @else @userTime($myReg->requested_check_in) → @if($myReg->requested_check_out) @userTime($myReg->requested_check_out) @else open (no OUT yet) @endif
                        @endif · with HR</div></div>
                <div class="ml-3.5">@include('livewire.attendance.partials.regularisation-actions', ['reg' => $myReg])</div>
            </div>
        @endforeach
    </div>
    @if($attentionTotal > $attentionLimit)
        <div class="pt-3">
            <button type="button" @click="more = ! more" class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400"
                x-text="more ? 'Show less' : 'Show {{ $attentionTotal - $attentionLimit }} more'">Show {{ $attentionTotal - $attentionLimit }} more</button>
        </div>
    @endif
</section>
