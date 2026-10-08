{{--
    Attendance Needs Attention — rendered only when something needs action:
    alerts (missing check-in/out, late arrival, early exit, long break, device
    sync), the late-mark warning, pending regularisations, punch conflicts, or
    an unassigned shift. Nothing is rendered on a clean day.
--}}
@php
    $pendingRegs = $this->myRegularisations->where('status', 'pending');
    $conflicts = (int) ($pj['conflict_count'] ?? 0);
    $lateWarning = (bool) ($analytics['late_warning'] ?? false);
    $hasAttention = $todayMissing || $issues->isNotEmpty() || $lateWarning || $pendingRegs->isNotEmpty() || $conflicts > 0 || $this->shiftUnassigned;
    $row = 'flex flex-wrap items-center justify-between gap-2 px-5 py-3';
    // At most five items show; the rest open with "Show more" (and fold again).
    $attentionLimit = 5;
    $attentionTotal = (int) $todayMissing + (int) $this->shiftUnassigned + $issues->count() + (int) ($conflicts > 0) + (int) $lateWarning + $pendingRegs->count();
    $attentionIndex = 0;
    $overflowAttr = fn (int $i): string => $i > $attentionLimit ? 'x-show="more" x-cloak' : '';
@endphp

@if($hasAttention)
<section id="attention" class="rounded-2xl border border-amber-200 bg-white shadow-sm dark:border-amber-500/30 dark:bg-zinc-900" aria-labelledby="attention-title" data-attention x-data="{ more: false }">
    <div class="flex items-center gap-2 border-b border-amber-100 px-5 py-3 dark:border-amber-500/20">
        <flux:icon.exclamation-triangle class="size-5 text-amber-500" />
        <h2 id="attention-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Attendance Needs Attention</h2>
    </div>
    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
        @if($todayMissing)
            <div class="{{ $row }}" data-alert="missing_today" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="text-sm"><span class="font-semibold text-zinc-900 dark:text-white">Missing {{ $todayMissingDir === 'IN' ? 'Check-In' : 'Check-Out' }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> — today, {{ now()->format('d M') }}: a {{ $todayMissingDir ?? 'punch' }} is missing from your timeline</span></div>
                <button type="button" wire:click="openRegularisation('{{ $todayKeyAtt }}')"
                    class="shrink-0 rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-600">Request Regularisation</button>
            </div>
        @endif

        @if($this->shiftUnassigned)
            <div class="{{ $row }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="text-sm"><span class="font-semibold text-zinc-900 dark:text-white">Shift not assigned</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> — arrivals and hours can't be judged against a shift. Ask HR to assign one.</span></div>
            </div>
        @endif

        @foreach($issues as $alert)
            <div class="{{ $row }}" data-alert="{{ $alert['type'] ?? '' }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="text-sm"><span class="font-semibold text-zinc-900 dark:text-white">{{ $alert['label'] }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> — {{ $alert['detail'] }}</span></div>
                @if($alert['action'] ?? true)
                    <button type="button" wire:click="openRegularisation('{{ $alert['date'] }}')"
                        class="shrink-0 rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-600">Request Regularisation</button>
                @endif
            </div>
        @endforeach

        @if($conflicts > 0)
            <div class="{{ $row }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="text-sm"><span class="font-semibold text-zinc-900 dark:text-white">Punch conflict</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> — {{ $conflicts }} conflicting {{ \Illuminate\Support\Str::plural('punch', $conflicts) }} today. Open “View raw punches” to see which were used.</span></div>
            </div>
        @endif

        @if($lateWarning)
            <div class="{{ $row }}" {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="text-sm"><span class="font-semibold text-rose-700 dark:text-rose-400">Late-mark warning — {{ $analytics['late_month_count'] }} late arrivals this month</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> — {{ $analytics['late_threshold'] ?? 3 }}+ late marks lead to a formal warning letter. Arrive before your shift's grace cutoff.</span></div>
            </div>
        @endif

        @foreach($pendingRegs as $myReg)
            <div class="{{ $row }}" data-attention-reg {!! $overflowAttr(++$attentionIndex) !!}>
                <div class="text-sm"><span class="font-semibold text-zinc-900 dark:text-white">Regularisation pending</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> — {{ \Illuminate\Support\Carbon::parse($myReg->work_date)->format('d M') }},
                        @if($myReg->isLeave()) leave
                        @elseif($myReg->regularisation_type === 'half_day') half day ({{ $myReg->half_day_period }})
                        @else {{ $myReg->requested_check_in ? \Illuminate\Support\Carbon::parse($myReg->requested_check_in)->format('H:i') : '—' }} → {{ $myReg->requested_check_out ? \Illuminate\Support\Carbon::parse($myReg->requested_check_out)->format('H:i') : '—' }}
                        @endif · with HR</span></div>
                @include('livewire.attendance.partials.regularisation-actions', ['reg' => $myReg])
            </div>
        @endforeach
    </div>
    @if($attentionTotal > $attentionLimit)
        <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
            <button type="button" @click="more = ! more" class="text-xs font-semibold text-orange-600 hover:text-orange-700"
                x-text="more ? 'Show less' : 'Show {{ $attentionTotal - $attentionLimit }} more'">Show {{ $attentionTotal - $attentionLimit }} more</button>
        </div>
    @endif
</section>
@endif
